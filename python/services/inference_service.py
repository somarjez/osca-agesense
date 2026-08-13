"""
OSCA ML Inference Service
Runs KMeans clustering and ensemble GBR+RFR models to indicate possible risk levels
for decision support based on multidimensional senior citizen indicators.
Generates structured recommendations per WHO Healthy Ageing domains.

Usage:
    python inference_service.py
"""

import os

# Force single-threaded numba/UMAP so transform() is deterministic across devices
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

import csv
import json
import logging
import pickle
import re
import threading
import unicodedata
import warnings
from functools import lru_cache
from typing import Any, Dict, List, Optional, Tuple

try:
    from recommendation_rules import build_rec_from_rule as _build_rec_from_rule
    _RULES_AVAILABLE = True
except ImportError:
    _RULES_AVAILABLE = False
    def _build_rec_from_rule(  # type: ignore[misc]
        code: str,
        reason_override=None,
        priority: int = 1,
        urgency: str = "planned",
        risk_level: str = "moderate",
    ) -> dict:
        return {}

import numpy as np
import pandas as pd
from flask import Flask, jsonify, request

warnings.filterwarnings("ignore")
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 5 * 1024 * 1024  # 5MB — generous for a single/batch senior payload
BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))

# Shared-secret check for every request except /health, which Laravel's
# MlService::startServices()/stopServices()/healthCheck() poll before/without
# knowing whether a token is even configured. Empty ML_SERVICE_TOKEN disables
# enforcement (matches MlService::authHeaders() sending no header in that case).
# Note: /model_insights is NOT exempted — it's an authenticated GET like the
# POST routes, unlike /health which must stay reachable unconditionally.
EXPECTED_TOKEN = os.environ.get("ML_SERVICE_TOKEN", "")


@app.before_request
def _check_internal_token():
    if request.path == "/health":
        return  # health checks stay open — polled before/without auth context by Laravel's service-management commands
    if EXPECTED_TOKEN and request.headers.get("X-Internal-Api-Key") != EXPECTED_TOKEN:
        return jsonify({"status": "error", "message": "Unauthorized"}), 401


def _resolve_model_dir() -> str:
    # Canonical sentinel files that must all exist for a directory to qualify.
    # This prevents silently loading a stale or incomplete artifact bundle.
    _SENTINEL_FILES = ("feature_list.json", "scaler.pkl", "cluster_mapping.json")

    env_model_dir = os.environ.get("ML_MODELS_PATH") or _read_dotenv_value("ML_MODELS_PATH")
    if env_model_dir:
        resolved = env_model_dir if os.path.isabs(env_model_dir) else os.path.join(BASE_DIR, env_model_dir)
        if os.path.isdir(resolved):
            missing = [f for f in _SENTINEL_FILES if not os.path.exists(os.path.join(resolved, f))]
            if missing:
                logger.warning(
                    "ML_MODELS_PATH='%s' is missing sentinel files: %s. "
                    "Run python/scripts/validate_model_artifacts.py to diagnose.",
                    resolved, missing,
                )
            return resolved
        logger.warning(
            "ML_MODELS_PATH='%s' does not exist (resolved: '%s'). "
            "Falling back to auto-detection — set ML_MODELS_PATH in .env to pin a canonical path.",
            env_model_dir, resolved,
        )

    candidates = [
        os.path.join(BASE_DIR, "python", "models"),
        os.path.join(BASE_DIR, "storage", "app", "ml_models"),
        os.path.join(os.path.expanduser("~"), "AppData", "Local", "OSCA-System", "ml_models"),
        os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "model")),
    ]
    for candidate in candidates:
        if os.path.isdir(candidate) and all(
            os.path.exists(os.path.join(candidate, f)) for f in _SENTINEL_FILES
        ):
            logger.warning(
                "ML_MODELS_PATH not set — auto-selected model dir: '%s'. "
                "Add ML_MODELS_PATH=python/models to .env for deterministic deployment.",
                candidate,
            )
            return candidate
    # Last resort: return first that exists as a directory, or canonical default
    for candidate in candidates:
        if os.path.isdir(candidate):
            return candidate
    logger.error(
        "No valid model directory found. Create python/models/ and set ML_MODELS_PATH in .env."
    )
    return candidates[0]


def _read_dotenv_value(name: str) -> Optional[str]:
    """Read a single key from the Laravel .env file (fallback for values not in os.environ)."""
    for candidate in [
        os.path.join(BASE_DIR, ".env"),
        os.path.join(os.path.dirname(BASE_DIR), ".env"),
    ]:
        if os.path.exists(candidate):
            try:
                for line in open(candidate, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        if k.strip() == name:
                            return v.strip().strip('"').strip("'")
            except Exception:
                pass
    return None


def _env_flag(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name) or _read_dotenv_value(name)
    if raw is None:
        return default
    return str(raw).strip().lower() in {"1", "true", "yes", "on"}


MODEL_DIR = _resolve_model_dir()
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", True)
# KNN classifier (k=5) trained on batch labels — primary inference path.
# Achieves ~92.8% consistency with notebook UMAP cluster labels vs ~87.5% for nearest-centroid.
# Set ENABLE_KNN_CLUSTER=false in .env to skip KNN and fall through to nearest-centroid.
ENABLE_KNN_CLUSTER = _env_flag("ENABLE_KNN_CLUSTER", True)
# Nearest-centroid in scaled space — secondary path when KNN model is unavailable.
# Set ENABLE_DETERMINISTIC_CLUSTER=false in .env only for debugging UMAP behaviour.
ENABLE_DETERMINISTIC_CLUSTER = _env_flag("ENABLE_DETERMINISTIC_CLUSTER", True)

if not ENABLE_NOTEBOOK_OVERRIDES:
    logger.warning(
        "ENABLE_NOTEBOOK_OVERRIDES=false — live GBR/RFR model active. "
        "Results may deviate from the validated notebook baseline. "
        "Set ENABLE_NOTEBOOK_OVERRIDES=true (or remove the env var) to restore validated predictions."
    )

# Semantic version written to every ml_results row so reports can filter by
# model generation. Bump the patch digit when thresholds change; bump minor
# when model .pkl files are retrained; bump major for schema-breaking changes.
MODEL_VERSION = "2.0.0"

# Expected artifact dimensions (must match the notebook training run).
# These constants let startup validation catch mismatched bundles early.
_EXPECTED_SCALER_N_FEATURES  = 30   # scaler.feature_names_in_ length (MinMaxScaler on 30-feature set)
_EXPECTED_UMAP_N_COMPONENTS  = 10   # umap_nd.pkl n_components
_EXPECTED_UMAP_INPUT_N_FEATS = 30   # feature_list.json length (UMAP raw input)
_EXPECTED_KMEANS_N_CLUSTERS  = 4    # kmeans.pkl n_clusters
_EXPECTED_KMEANS_N_FEATURES  = 10   # kmeans.pkl n_features_in_ (== UMAP output)
_EXPECTED_CLUSTER_RAW_IDS    = {0, 1, 2, 3}  # all raw KMeans IDs must be in cluster_mapping.json


def _validate_artifacts_at_startup() -> None:
    """
    Cross-check the loaded artifact bundle for shape/key consistency.
    Logs PASS or WARN for each check. Does NOT raise — the service remains
    available so callers get a structured error rather than a crash.
    Mismatches indicate a mixed-bundle deployment and should be fixed with
    python/scripts/validate_model_artifacts.py.
    """
    issues: List[str] = []

    def _warn(msg: str) -> None:
        issues.append(msg)
        logger.warning("[ARTIFACT MISMATCH] %s", msg)

    # ── feature_list.json ────────────────────────────────────────────────────
    fl_path = os.path.join(MODEL_DIR, "feature_list.json")
    if not os.path.exists(fl_path):
        _warn("feature_list.json missing — UMAP input feature list unavailable.")
    else:
        try:
            import json as _json
            with open(fl_path, encoding="utf-8") as _f:
                fl = _json.load(_f)
            if not isinstance(fl, list):
                _warn("feature_list.json is not a JSON array.")
            elif len(fl) != _EXPECTED_UMAP_INPUT_N_FEATS:
                _warn(
                    f"feature_list.json has {len(fl)} features; expected {_EXPECTED_UMAP_INPUT_N_FEATS}. "
                    "This file must list the UMAP training features (post-VIF subset), "
                    "NOT the full scaler input. Re-export from osca5.ipynb."
                )
        except Exception as exc:
            _warn(f"feature_list.json unreadable: {exc}")

    # ── scaler.pkl ───────────────────────────────────────────────────────────
    sc_path = os.path.join(MODEL_DIR, "scaler.pkl")
    if not os.path.exists(sc_path):
        _warn("scaler.pkl missing.")
    else:
        try:
            import pickle as _pkl
            with open(sc_path, "rb") as _f:
                sc = _pkl.load(_f)
            n = int(getattr(sc, "n_features_in_", -1))
            if n != _EXPECTED_SCALER_N_FEATURES:
                _warn(
                    f"scaler.pkl was trained on {n} features; expected {_EXPECTED_SCALER_N_FEATURES}. "
                    "Re-export scaler from osca5.ipynb with the correct training set."
                )
            if not hasattr(sc, "feature_names_in_"):
                _warn(
                    "scaler.pkl has no feature_names_in_. "
                    "The scaler must be fitted on a DataFrame so feature names are preserved."
                )
        except Exception as exc:
            _warn(f"scaler.pkl unloadable: {exc}")

    # ── umap_nd.pkl ──────────────────────────────────────────────────────────
    umap_path = os.path.join(MODEL_DIR, "umap_nd.pkl")
    if not os.path.exists(umap_path):
        umap_path = os.path.join(MODEL_DIR, "umap_reducer.pkl")
    if not os.path.exists(umap_path):
        _warn("umap_nd.pkl (and umap_reducer.pkl) missing.")
    else:
        try:
            with open(umap_path, "rb") as _f:
                u = _pkl.load(_f)
            nc = getattr(u, "n_components", -1)
            if nc != _EXPECTED_UMAP_N_COMPONENTS:
                _warn(
                    f"UMAP n_components={nc}; expected {_EXPECTED_UMAP_N_COMPONENTS}. "
                    "Re-export umap_nd.pkl from osca5.ipynb."
                )
            if hasattr(u, "_raw_data"):
                raw_ncols = u._raw_data.shape[1] if u._raw_data is not None else -1
                if raw_ncols != _EXPECTED_UMAP_INPUT_N_FEATS:
                    _warn(
                        f"UMAP was trained on {raw_ncols} input features; "
                        f"feature_list.json has {_EXPECTED_UMAP_INPUT_N_FEATS}. "
                        "feature_list.json does not match the UMAP training data."
                    )
            if not hasattr(u, "embedding_"):
                _warn("UMAP artifact has no embedding_ — it may not be fitted.")
        except Exception as exc:
            _warn(f"UMAP artifact unloadable: {exc}")

    # ── kmeans.pkl ───────────────────────────────────────────────────────────
    km_path = os.path.join(MODEL_DIR, "kmeans.pkl")
    if not os.path.exists(km_path):
        km_path = os.path.join(MODEL_DIR, "kmeans_model.pkl")
    if not os.path.exists(km_path):
        _warn("kmeans.pkl missing.")
    else:
        try:
            with open(km_path, "rb") as _f:
                km = _pkl.load(_f)
            nc = getattr(km, "n_clusters", -1)
            nf = getattr(km, "n_features_in_", -1)
            if nc != _EXPECTED_KMEANS_N_CLUSTERS:
                _warn(f"KMeans n_clusters={nc}; expected {_EXPECTED_KMEANS_N_CLUSTERS}.")
            if nf != _EXPECTED_KMEANS_N_FEATURES:
                _warn(
                    f"KMeans n_features_in_={nf}; expected {_EXPECTED_KMEANS_N_FEATURES} "
                    f"(== UMAP n_components). "
                    "KMeans and UMAP were not trained together in this artifact bundle."
                )
        except Exception as exc:
            _warn(f"KMeans artifact unloadable: {exc}")

    # ── cluster_mapping.json ─────────────────────────────────────────────────
    cm_path = os.path.join(MODEL_DIR, "cluster_mapping.json")
    if not os.path.exists(cm_path):
        _warn("cluster_mapping.json missing.")
    else:
        try:
            import json as _json2
            with open(cm_path, encoding="utf-8") as _f:
                cm = _json2.load(_f)
            raw_ids_in_map = {int(k) for k in cm.keys()}
            missing_ids = _EXPECTED_CLUSTER_RAW_IDS - raw_ids_in_map
            if missing_ids:
                _warn(
                    f"cluster_mapping.json is missing raw cluster IDs: {sorted(missing_ids)}. "
                    "All KMeans raw IDs {0,1,2} must be mapped. "
                    "Re-export cluster_mapping.json from osca5.ipynb."
                )
        except Exception as exc:
            _warn(f"cluster_mapping.json unreadable: {exc}")

    # ── GBR/RFR risk indicator models ────────────────────────────────────────
    for fn in ["gbr_ic_risk.pkl", "rfr_ic_risk.pkl",
               "gbr_env_risk.pkl", "rfr_env_risk.pkl",
               "gbr_func_risk.pkl", "rfr_func_risk.pkl"]:
        if not os.path.exists(os.path.join(MODEL_DIR, fn)):
            _warn(f"{fn} missing — risk indicator ensemble will use fallback scores.")

    # ── cluster_centroids_scaled.json (fallback path after KNN) ─────────────
    cc_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if not os.path.exists(cc_path):
        _warn(
            "cluster_centroids_scaled.json missing — nearest-centroid fallback "
            "will not be available (service will use UMAP if KNN also misses). "
            "Run: python/venv/Scripts/python.exe python/scripts/generate_cluster_centroids.py"
        )

    # ── cluster_assignment_knn_k5.pkl (primary cluster assignment path) ────────────────────
    knn_path = os.path.join(MODEL_DIR, "cluster_assignment_knn_k5.pkl")
    if not os.path.exists(knn_path):
        logger.info(
            "cluster_assignment_knn_k5.pkl not found in MODEL_DIR='%s' — cluster assignment will "
            "use nearest-centroid fallback (UMAP as last resort). "
            "Run: python/venv/Scripts/python.exe python/scripts/generate_knn_classifier.py",
            MODEL_DIR,
        )

    # ── model_manifest.json SHA-256 cross-check ──────────────────────────────
    # If present, verify each artifact's hash matches the manifest. This catches
    # mixed bundles (e.g., scaler from one training run, models from another).
    import hashlib as _hashlib
    manifest_path = os.path.join(MODEL_DIR, "model_manifest.json")
    if os.path.exists(manifest_path):
        try:
            import json as _jm
            with open(manifest_path, encoding="utf-8") as _f:
                _manifest = _jm.load(_f)
            _hashes = _manifest.get("sha256", {})
            for _fname, _expected_hash in _hashes.items():
                _fpath = os.path.join(MODEL_DIR, _fname)
                if not os.path.exists(_fpath):
                    continue  # missing file already caught above
                _h = _hashlib.sha256()
                with open(_fpath, "rb") as _f:
                    for _chunk in iter(lambda: _f.read(65536), b""):
                        _h.update(_chunk)
                _actual = _h.hexdigest()
                if _actual != _expected_hash:
                    _warn(
                        f"{_fname} SHA-256 mismatch: expected {_expected_hash[:12]}… "
                        f"got {_actual[:12]}… — models on this device differ from the "
                        "training device. Copy python/models/ from the training machine "
                        "or re-run generate_cluster_centroids.py."
                    )
        except Exception as exc:
            logger.warning("Could not read model_manifest.json: %s", exc)

    if issues:
        logger.warning(
            "Artifact validation found %d issue(s) in MODEL_DIR='%s'. "
            "Run: python/venv/Scripts/python.exe python/scripts/validate_model_artifacts.py",
            len(issues), MODEL_DIR,
        )
    else:
        logger.info(
            "Artifact validation PASSED (%d checks) — MODEL_DIR='%s'",
            9, MODEL_DIR,
        )


# ── DB-backed ML result cache ─────────────────────────────────────────────────
# Reads Laravel .env so the same DB credentials are used without duplication.
def _read_laravel_env() -> Dict[str, str]:
    env: Dict[str, str] = {}
    candidates = [
        os.path.join(BASE_DIR, ".env"),
        os.path.join(BASE_DIR, "..", ".env"),
    ]
    for path in candidates:
        if os.path.exists(path):
            try:
                with open(path, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        k, _, v = line.partition("=")
                        env[k.strip()] = v.strip().strip('"').strip("'")
                return env
            except Exception:
                pass
    return env


def _load_risk_thresholds() -> Dict[str, float]:
    """Load risk-band boundaries from MODEL_DIR/risk_thresholds.json — the
    single source of truth shared with PHP's App\\Support\\RiskThresholds
    (see app/Services/MlService.php), which reads the same file so the
    fallback-scoring path and the live model can never silently classify the
    same composite_risk into different bands again (TC-ML-06). Deliberately
    NOT using _load_json()/MODEL_DIR-dependent helpers defined later in this
    module — this runs at plain module-load time, before those exist in the
    module namespace, so it does its own minimal read. Falls back to these
    same tuned values (kept identical to the JSON's own defaults on purpose)
    if the file is missing/unreadable, so a deploy without the file still
    classifies correctly rather than reverting to stale numbers.
    """
    defaults = {
        "critical": 0.70,  # urgent review flag — not a clinical diagnosis
        "high": 0.54,  # tuned: raised 0.50->0.54 (F1 55%->62%, false_esc 17%->6%)
        "moderate": 0.39,  # tuned: raised 0.30->0.39 (calibration offset +0.09, F1 62%->82%)
    }
    path = os.path.join(MODEL_DIR, "risk_thresholds.json")
    if os.path.exists(path):
        try:
            raw = open(path, "rb").read()
            for enc in ("utf-8-sig", "utf-8"):
                try:
                    data = json.loads(raw.decode(enc))
                    if isinstance(data, dict):
                        return {**defaults, **{k: float(v) for k, v in data.items() if k in defaults}}
                except (UnicodeDecodeError, json.JSONDecodeError, ValueError, TypeError):
                    continue
        except OSError:
            pass
    return defaults


RISK_THRESHOLDS = _load_risk_thresholds()
URGENT_PRIORITY_THRESHOLD = RISK_THRESHOLDS["critical"]

CLUSTER_PROFILES = {
    1: {
        "name": "High Functioning / Well-Supported Seniors",
        "ic": "High", "env": "High", "func": "High",
        "risk_level": "LOW",
        "description": (
            "Strong WHO Healthy Ageing alignment across all domains. "
            "Independent, financially stable, socially engaged seniors. "
            "Preventive monitoring and social participation focus."
        ),
    },
    2: {
        "name": "Stable Ageing / Moderate Support Needs",
        "ic": "Moderate", "env": "Moderate", "func": "Moderate",
        "risk_level": "MODERATE",
        "description": (
            "Moderate alignment across IC, Environment, and Functional domains. "
            "Targeted support recommended for identified needs. "
            "Mixed domain performance with manageable risk. "
            "Note: cluster membership is determined by WHO domain profile similarity, "
            "not by composite risk score. A subset of seniors in this cluster may carry "
            "HIGH composite risk due to compounding stressors (e.g., chronic illness "
            "combined with financial or access barriers) even while their overall "
            "IC/env/func profile is moderate — reflecting cluster-level heterogeneity "
            "that is addressed through individual risk-based recommendations."
        ),
    },
    3: {
        "name": "Environmentally and Financially Vulnerable Seniors",
        "ic": "Moderate", "env": "Low", "func": "Moderate",
        "risk_level": "MODERATE",
        "description": (
            "Intrinsic Capacity and functional ability relatively preserved, but "
            "environmental and financial stressors require targeted intervention. "
            "Financial, housing, and service-access support focus. "
            "This cluster is proportionally large because environmental and financial "
            "vulnerability is the dominant pattern in the survey population: many seniors "
            "maintain reasonable physical and cognitive function but face systemic barriers "
            "such as low income, housing insecurity, and limited service access — "
            "consistent with the socioeconomic context of the barangay catchment area. "
            "The cluster size reflects prevalence of need, not model imbalance."
        ),
    },
    4: {
        "name": "Low Functioning / Multi-Domain Priority Seniors",
        "ic": "Low", "env": "Low", "func": "Low",
        "risk_level": "HIGH",
        "description": (
            "Lowest WHO Healthy Ageing alignment across all domains. "
            "Multi-domain vulnerabilities requiring immediate and coordinated intervention. "
            "Comprehensive care coordination and priority escalation."
        ),
    },
}


# Section grouping for XAI section-level summary
_XAI_SECTION_GROUPS: Dict[str, List[str]] = {
    # checkup_enc is a component of sec6_health_score (Section VI / Health), so it
    # belongs with Physical Health — NOT Human Capital (sec3 = education/skills).
    "Physical Health":    ["sec6_phy_score", "sec6_health_score", "checkup_enc", "phy_energy", "phy_pain_r",
                           "phy_health_limit_r", "phy_mobility_outside", "phy_mobility_indoor"],
    "Psychological":      ["sec6_psy_score", "psych_happiness", "psych_peace",
                           "psych_lonely_r", "psych_confidence"],
    "Functional Health":  ["sec6_func_score", "func_independence", "func_autonomy", "func_control"],
    "Economic Stability": ["sec5_eco_stability", "sec5_income_norm", "sec5_real_asset_score",
                           "sec5_movable_asset_score", "sec5_income_source_score",
                           "env_fin_medical", "env_fin_household", "env_fin_personal",
                           "env_income_limit_r", "income_enc", "has_pension"],
    "Social Connection":  ["soc_social_support", "soc_close_friend", "soc_participation",
                           "soc_opportunity", "soc_respect"],
    "Environment":        ["env_safe_home", "env_safe_neighborhood", "env_home_comfort",
                           "env_service_access"],
    "Family & Household": ["sec2_family_support", "sec2_family_size_norm", "sec4_lives_alone",
                           "sec4_household_risk", "sec4_dependency_risk", "living_with_count"],
    "Human Capital":      ["sec3_education_norm", "sec3_skill_score", "sec3_community_score",
                           "sec3_hr_score", "education_enc",
                           "community_service_count"],
    "Age & Demographics": ["sec1_age_risk", "age"],
}

_FEATURE_TO_SECTION: Dict[str, str] = {
    feat: section
    for section, feats in _XAI_SECTION_GROUPS.items()
    for feat in feats
}

# Clinical direction overrides for XAI effect signs.
# feature_effect_signs in cluster_feature_means.json is derived purely from
# correlating each feature against the model's predicted risk (see
# generate_xai_means.py). That is an OBSERVATIONAL signal and can be flipped
# by reverse causation / confounding — e.g. seniors who already have a chronic
# condition are more likely to have a regular check-up, so "has check-up"
# correlates positively with risk in the sample even though preventive care is
# protective. These features are protective by clinical/WHO evidence
# regardless of what the sample correlation says, so their sign is pinned
# here instead of trusting the raw correlation. Evidence: regular medical
# check-ups reduce disability risk (~1.85x higher risk without them); pension/
# income security and community participation are established WHO Healthy
# Ageing protective factors.
_CLINICAL_EFFECT_SIGNS: Dict[str, int] = {
    "checkup_enc":              -1,  # regular preventive check-up — protective
    "has_pension":               -1,  # income security — protective
    "income_enc":                -1,  # higher income bracket — protective
    "community_service_count":   -1,  # social participation / active ageing — protective
}


@lru_cache(maxsize=1)
def _load_cluster_feature_means() -> Optional[Dict[str, Any]]:
    """Load cluster_feature_means.json (per-cluster feature averages for XAI deviation)."""
    path = os.path.join(MODEL_DIR, "cluster_feature_means.json")
    if not os.path.exists(path):
        logger.warning("cluster_feature_means.json not found — XAI will use 0.5 fallback.")
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _compute_xai(
    feature_map: Dict[str, Any],
    named_id: int,
    ml_risk_feature_names: List[str],
) -> Dict[str, Any]:
    """Compute per-domain XAI using feature importance x deviation from cluster mean."""
    import datetime as _dt
    means_data = _load_cluster_feature_means()
    if means_data:
        cluster_means: Dict[str, float] = means_data.get("cluster_means", {}).get(
            str(named_id),
            means_data.get("global_mean", {})
        )
        effect_signs_all: Dict[str, Dict[str, int]] = means_data.get("feature_effect_signs", {})
    else:
        cluster_means = {f: 0.5 for f in ml_risk_feature_names}
        effect_signs_all = {}

    def _domain_xai(model_key: str, domain: str) -> Dict[str, Any]:
        gbr = _load_model(model_key)
        if gbr is None or not hasattr(gbr, "feature_importances_"):
            return {"section_drivers": [], "feature_drivers": []}
        importances = gbr.feature_importances_
        # Per-feature sign of effect on risk (+1 raises, -1 lowers). Default +1.
        # _CLINICAL_EFFECT_SIGNS takes precedence over the correlation-derived
        # sign — see its docstring for why (confounding/reverse causation).
        signs = effect_signs_all.get(domain, {})
        contribs: List[Dict[str, Any]] = []
        for i, feat in enumerate(ml_risk_feature_names):
            if i >= len(importances):
                continue
            imp     = float(importances[i])
            val     = float(feature_map.get(feat, 0.0))
            mean    = float(cluster_means.get(feat, 0.5))
            sign    = _CLINICAL_EFFECT_SIGNS.get(feat, int(signs.get(feat, 1)))
            # Signed contribution to RISK: positive => raises risk, negative => lowers risk
            contrib = imp * (val - mean) * sign
            contribs.append({
                "feature":     feat,
                "importance":  round(imp, 5),
                "value":       round(val, 4),
                "mean":        round(mean, 4),
                "raw_contrib": contrib,
            })
        total_abs = sum(abs(c["raw_contrib"]) for c in contribs) or 1.0
        for c in contribs:
            c["contribution_pct"] = round(abs(c["raw_contrib"]) / total_abs * 100, 2)
            c["direction"] = "up" if c["raw_contrib"] >= 0 else "down"
        top_feats = sorted(contribs, key=lambda x: x["contribution_pct"], reverse=True)[:5]
        feature_drivers = [
            {"feature": c["feature"], "contribution_pct": c["contribution_pct"],
             "direction": c["direction"], "value": c["value"],
             "mean": c["mean"], "importance": c["importance"]}
            for c in top_feats
        ]
        section_totals: Dict[str, float] = {}
        section_raw: Dict[str, float] = {}
        for c in contribs:
            sec = _FEATURE_TO_SECTION.get(c["feature"], "Other")
            section_totals[sec] = section_totals.get(sec, 0.0) + c["contribution_pct"]
            section_raw[sec]    = section_raw.get(sec, 0.0)    + c["raw_contrib"]
        top_sections = sorted(section_totals.items(), key=lambda x: x[1], reverse=True)[:3]
        section_drivers = [
            {"section": name, "contribution_pct": round(pct, 2),
             "direction": "up" if section_raw.get(name, 0) >= 0 else "down"}
            for name, pct in top_sections
        ]
        return {"section_drivers": section_drivers, "feature_drivers": feature_drivers}

    return {
        "ic":               _domain_xai("gbr_ic_risk.pkl", "ic"),
        "env":              _domain_xai("gbr_env_risk.pkl", "env"),
        "func":             _domain_xai("gbr_func_risk.pkl", "func"),
        "cluster_named_id": named_id,
        "computed_at":      _dt.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    }


@lru_cache(maxsize=1)
def _build_model_insights() -> Dict[str, Any]:
    """Global GBR feature importance, cached. Returns top-15 per domain."""
    import datetime as _dt
    means_data = _load_cluster_feature_means()
    risk_features = means_data["risk_features"] if means_data else []

    def _domain_importance(model_key: str) -> List[Dict[str, Any]]:
        gbr = _load_model(model_key)
        if gbr is None or not hasattr(gbr, "feature_importances_") or not risk_features:
            return []
        pairs = [
            {"feature": feat, "importance": round(float(imp), 5)}
            for feat, imp in zip(risk_features, gbr.feature_importances_)
        ]
        pairs.sort(key=lambda x: x["importance"], reverse=True)
        return pairs[:15]

    return {
        "ic":           _domain_importance("gbr_ic_risk.pkl"),
        "env":          _domain_importance("gbr_env_risk.pkl"),
        "func":         _domain_importance("gbr_func_risk.pkl"),
        "n_seniors":    int(means_data.get("n_seniors", 0)) if means_data else 0,
        "generated_at": _dt.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    }


@lru_cache(maxsize=1)
def _load_cluster_profiles() -> Dict[int, Dict[str, str]]:
    """
    Merge cluster_metadata.json from MODEL_DIR over CLUSTER_PROFILES defaults.
    Accepts shape A  {"1": {...}, "2": {...}, "3": {...}}
    or shape B  {"clusters": {"1": {...}, ...}}.
    Maps notebook field names to internal API names:
        ic_level / env_level / func_level → ic / env / func
        interpretation → description
    """
    data = _load_json("cluster_metadata.json")
    merged = {k: dict(v) for k, v in CLUSTER_PROFILES.items()}

    if not data or not isinstance(data, dict):
        logger.info("cluster_metadata.json missing or empty; using hardcoded defaults.")
        return merged

    raw_clusters = data.get("clusters", data)
    if not isinstance(raw_clusters, dict):
        logger.warning("cluster_metadata.json has unexpected shape; using hardcoded defaults.")
        return merged

    field_map = {
        "ic_level":    "ic",
        "env_level":   "env",
        "func_level":  "func",
        "interpretation": "description",
    }
    for str_id, meta in raw_clusters.items():
        try:
            cid = int(str_id)
        except (ValueError, TypeError):
            continue
        if cid not in merged or not isinstance(meta, dict):
            continue
        for src, dst in field_map.items():
            if src in meta and meta[src]:
                merged[cid][dst] = str(meta[src])
        for direct in ("name", "ic", "env", "func", "risk_level", "description"):
            if direct in meta and meta[direct]:
                merged[cid][direct] = str(meta[direct])

    if not all(i in merged for i in (1, 2, 3, 4)):
        logger.warning("cluster_metadata.json missing required cluster IDs; using hardcoded defaults.")
        return {k: dict(v) for k, v in CLUSTER_PROFILES.items()}

    logger.info("cluster_metadata.json loaded successfully.")
    return merged



# ── Disease keyword → rule code mapping ──────────────────────────────────────
# Replaces the clinical DISEASE_ACTIONS string lists with service-backed
# recommendation rule codes. Each keyword maps to a primary rule code;
# a secondary code (value[1]) is optional and fires only for high-cost diseases.
# Keys are matched as substrings (case-insensitive) against medical_concern text.
DISEASE_RULE_MAP: Dict[str, Tuple[str, Optional[str]]] = {
    # Hypertensive
    "hypertension":             ("HEALTH_HYPERTENSION_PHILPEN",  None),
    "high blood pressure":      ("HEALTH_HYPERTENSION_PHILPEN",  None),
    # Endocrine
    "diabetes":                 ("HEALTH_DIABETES_PHILPEN",      None),
    "blood sugar":              ("HEALTH_DIABETES_PHILPEN",      None),
    # Cardiovascular / high-cost → also Malasakit
    "coronary heart disease":   ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_MALASAKIT_CENTER"),
    "heart disease":            ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_MALASAKIT_CENTER"),
    # Neurological
    "stroke":                   ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_MALASAKIT_CENTER"),
    "dementia":                 ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ADL_SUPPORT"),
    "alzheimer":                ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ADL_SUPPORT"),
    "parkinson":                ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    # Oncology / high-cost
    "cancer":                   ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_MALASAKIT_CENTER"),
    # Respiratory
    "asthma":                   ("HEALTH_YAKAP_CHECKUP",        None),
    "copd":                     ("HEALTH_YAKAP_CHECKUP",        None),
    "tuberculosis":             ("HEALTH_YAKAP_CHECKUP",        None),
    # Musculoskeletal
    "arthritis":                ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    "osteoporosis":             ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    # Renal / high-cost
    "kidney":                   ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_PCSO_MAP"),
    "chronic kidney disease":   ("HEALTH_YAKAP_CHECKUP",        "MEDICAL_PCSO_MAP"),
    # Sensory
    "glaucoma":                 ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    "cataract":                 ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    "hearing impairment":       ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    "eye impairment":           ("HEALTH_YAKAP_CHECKUP",        "FUNCTIONAL_ASSISTIVE_DEVICE"),
    # Haematological
    "anemia":                   ("HEALTH_YAKAP_CHECKUP",        None),
    # Disability
    "physical disability":      ("FUNCTIONAL_ASSISTIVE_DEVICE", "HEALTH_YAKAP_CHECKUP"),
    # Mental health reported in medical_concern (unusual but possible)
    "depression":               ("MENTAL_HEALTH_REFERRAL",      None),
    # Catch-all
    "other chronic disease":    ("HEALTH_YAKAP_CHECKUP",        None),
}

NOTEBOOK_PREDICTIONS_CANDIDATES = [
    # Primary: inside the repo under python/models/predictions/ (gitignored, placed by setup.bat)
    os.path.join(MODEL_DIR, "predictions", "senior_predictions.csv"),
    # Fallback: osca_output/ is one level above the project root (BASE_DIR/../)
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "predictions", "senior_predictions.csv")),
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "reports", "predictions", "senior_predictions.csv")),
]

NOTEBOOK_RECOMMENDATIONS_CANDIDATES = [
    # Primary: inside the repo under python/models/predictions/ (gitignored, placed by setup.bat)
    os.path.join(MODEL_DIR, "predictions", "senior_recommendations_flat.csv"),
    # Fallback: osca_output/ is one level above the project root (BASE_DIR/../)
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "predictions", "senior_recommendations_flat.csv")),
    os.path.abspath(os.path.join(
        BASE_DIR, "..", "osca_output", "reports", "predictions", "senior_recommendations_flat.csv"
    )),
]


def _resolve_notebook_predictions_path() -> str:
    for candidate in NOTEBOOK_PREDICTIONS_CANDIDATES:
        if os.path.exists(candidate):
            return candidate
    return NOTEBOOK_PREDICTIONS_CANDIDATES[0]


def _resolve_notebook_recommendations_path() -> str:
    for candidate in NOTEBOOK_RECOMMENDATIONS_CANDIDATES:
        if os.path.exists(candidate):
            return candidate
    return NOTEBOOK_RECOMMENDATIONS_CANDIDATES[0]


# ── Loaders ───────────────────────────────────────────────────────────────────
@lru_cache(maxsize=None)
def _load_model(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)


def _load_first_model(candidates: List[str]) -> Optional[Any]:
    for filename in candidates:
        model = _load_model(filename)
        if model is not None:
            return model
    return None


@lru_cache(maxsize=None)
def _load_json(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    raw = open(path, "rb").read()
    for enc in ("utf-8-sig", "utf-8", "cp1252", "latin-1"):
        try:
            return json.loads(raw.decode(enc))
        except (UnicodeDecodeError, json.JSONDecodeError):
            continue
    return None


@lru_cache(maxsize=1)
def _load_cluster_centroids_scaled() -> Optional[Dict[str, Any]]:
    """Load cluster_centroids_scaled.json from MODEL_DIR (cached after first load)."""
    path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _deterministic_cluster_assign(
    vector: List[float],
    feature_names: List[str],
) -> Optional[int]:
    """
    Assign named cluster ID (1, 2, or 3) by nearest L2 centroid in the
    N-dimensional scaled feature space (N = len(feature_list.json)).
    Returns None if the centroids file is missing — caller falls back to UMAP.
    """
    data = _load_cluster_centroids_scaled()
    if not data:
        return None
    centroid_names: List[str] = data["feature_names"]
    centroids: Dict[str, List[float]] = data["centroids"]
    feat_idx = {f: i for i, f in enumerate(feature_names)}
    best_id: Optional[int] = None
    best_dist = float("inf")
    for named_id_str, centroid in centroids.items():
        dist = sum(
            (float(centroid[j]) - (vector[feat_idx[f]] if f in feat_idx else 0.0)) ** 2
            for j, f in enumerate(centroid_names)
        )
        if dist < best_dist:
            best_dist = dist
            best_id = int(named_id_str)
    return best_id


@lru_cache(maxsize=1)
def _load_knn_classifier() -> Optional[Any]:
    """Load cluster_assignment_knn_k5.pkl from MODEL_DIR (cached after first load)."""
    path = os.path.join(MODEL_DIR, "cluster_assignment_knn_k5.pkl")
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)


def _knn_cluster_assign(
    vector: List[float],
    feature_names: List[str],
) -> Optional[int]:
    """
    Assign named cluster ID (1-4) using the KNN k=5 classifier in scaled space.
    Returns None if cluster_assignment_knn_k5.pkl is missing or if feature alignment fails.
    """
    knn = _load_knn_classifier()
    if knn is None:
        return None
    try:
        knn_features: Optional[List[str]] = getattr(knn, "_osca_feature_names", None)
        if knn_features is not None and knn_features != feature_names:
            logger.warning(
                "cluster_assignment_knn_k5.pkl feature list does not match feature_list.json — "
                "skipping KNN assignment (model and artifacts are from different runs)."
            )
            return None
        arr = np.asarray([vector], dtype=np.float64)
        return int(knn.predict(arr)[0])
    except Exception as exc:
        logger.warning("KNN cluster assignment failed: %s", exc)
        return None


def _normalize_identity_part(value: Any) -> str:
    """
    Robust Unicode normalization for Filipino names and barangay strings.

    Steps:
    1. NFC-compose first so pre-composed chars (e.g. UTF-8 ñ U+00F1) are unified.
    2. Explicitly replace ñ/Ñ with 'n' before decomposition — this covers both
       proper UTF-8 ñ (U+00F1) and cp1252 ñ that survived mis-encoding.
    3. NFKD-decompose to split remaining accented letters into base + combining.
    4. Strip all combining (accent) characters, keeping base ASCII letters.
    5. Lowercase, trim, collapse anything non-alphanumeric.

    This ensures the CSV (which may be saved in cp1252) and the DB (UTF-8)
    both resolve the same normalised key for names containing ñ, é, etc.
    """
    text = str(value or "")
    # Step 1: NFC compose (unify any decomposed sequences first)
    text = unicodedata.normalize("NFC", text)
    # Step 2: handle ñ/Ñ explicitly so it maps to 'n' not empty
    text = text.replace("ñ", "n").replace("Ñ", "n")   # ñ → n, Ñ → N→n
    # Step 3: NFKD decompose remaining accented chars
    text = unicodedata.normalize("NFKD", text)
    # Step 4: strip combining diacritical marks
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    # Step 5: lowercase, trim, keep only [a-z0-9]
    text = text.lower().strip()
    return re.sub(r"[^a-z0-9]+", "", text)


def _identity_key(first_name: Any, last_name: Any, barangay: Any, age: Any) -> Tuple[str, str, str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
        _normalize_identity_part(barangay),
        str(age).strip(),
    )


def _name_barangay_key(first_name: Any, last_name: Any, barangay: Any) -> Tuple[str, str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
        _normalize_identity_part(barangay),
    )


def _name_key(first_name: Any, last_name: Any) -> Tuple[str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
    )


def _safe_float(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


@lru_cache(maxsize=1)
def _load_notebook_cluster_index() -> Dict[str, Dict[Any, Any]]:
    predictions_path = _resolve_notebook_predictions_path()
    if not os.path.exists(predictions_path):
        return {"full": {}, "name_age": {}, "name_barangay": {}, "name": {}, "name_barangay_multi": {}}

    full_index: Dict[Tuple[str, str, str, str], Dict[str, Any]] = {}
    fallback_bucket: Dict[Tuple[str, str, str], List[Dict[str, Any]]] = {}
    name_barangay_bucket: Dict[Tuple[str, str, str], List[Dict[str, Any]]] = {}
    name_bucket: Dict[Tuple[str, str], List[Dict[str, Any]]] = {}
    # Try UTF-8-sig first; fall back to cp1252 (Windows Excel default).
    # Many Philippine government CSV exports use cp1252 which encodes ñ as 0xF1.
    # Reading cp1252 bytes as UTF-8 corrupts ñ into U+FFFD ('?'), breaking
    # the normalisation match for names like Opeña → must read as cp1252 first.
    _csv_encodings = ["utf-8-sig", "utf-8", "cp1252", "latin-1"]
    _opened_file = None
    for _enc in _csv_encodings:
        try:
            _f = open(predictions_path, "r", encoding=_enc, errors="strict", newline="")
            # Consume a few lines to detect encoding errors early
            _preview = [_f.readline() for _ in range(5)]
            _f.seek(0)
            _opened_file = _f
            logger.info("senior_predictions.csv opened with encoding=%s", _enc)
            break
        except (UnicodeDecodeError, LookupError):
            try:
                _f.close()
            except Exception:
                pass
    if _opened_file is None:
        # Last resort: replace undecodable bytes silently
        _opened_file = open(predictions_path, "r", encoding="cp1252", errors="replace", newline="")
        logger.warning("senior_predictions.csv: all encodings failed strict mode; using cp1252 with replacement.")
    with _opened_file as csvfile:
        for row in csv.DictReader(csvfile):
            key = _identity_key(
                row.get("first_name"),
                row.get("last_name"),
                row.get("barangay"),
                row.get("age"),
            )
            try:
                cluster_id = int(float(row.get("cluster_id", 0)))
            except Exception:
                continue

            payload = {
                "cluster_id": max(1, min(4, cluster_id)),
                "cluster_name": row.get("cluster_name") or _load_cluster_profiles().get(cluster_id, {}).get("name"),
                "age": _safe_float(row.get("age")),
                "risk_level": (row.get("risk_level") or "").strip().upper(),
                "composite_risk": _safe_float(row.get("composite_risk")),
                "ml_ic_risk": _safe_float(row.get("ml_ic_risk")),
                "ml_env_risk": _safe_float(row.get("ml_env_risk")),
                "ml_func_risk": _safe_float(row.get("ml_func_risk")),
                "overall_wellbeing": _safe_float(row.get("overall_wellbeing")),
                "sec5_eco_stability": _safe_float(row.get("sec5_eco_stability")),
                "sec5_real_asset_score": _safe_float(row.get("sec5_real_asset_score")),
                "sec5_movable_asset_score": _safe_float(row.get("sec5_movable_asset_score")),
                "ic_score": _safe_float(row.get("ic_score")),
                "env_score": _safe_float(row.get("env_score")),
                "func_score": _safe_float(row.get("func_score")),
                "qol_score": _safe_float(row.get("qol_score")),
            }
            full_index[key] = payload
            fallback_key = (key[0], key[1], key[3])
            fallback_bucket.setdefault(fallback_key, []).append(payload)
            nb_key = _name_barangay_key(
                row.get("first_name"),
                row.get("last_name"),
                row.get("barangay"),
            )
            name_barangay_bucket.setdefault(nb_key, []).append(payload)
            name_bucket.setdefault(_name_key(row.get("first_name"), row.get("last_name")), []).append(payload)

    name_age_index = {
        key: rows[0]
        for key, rows in fallback_bucket.items()
        if len(rows) == 1
    }

    name_barangay_index = {
        key: rows[0]
        for key, rows in name_barangay_bucket.items()
        if len(rows) == 1
    }

    name_index = {
        key: rows[0]
        for key, rows in name_bucket.items()
        if len(rows) == 1
    }

    name_barangay_multi = {
        key: rows
        for key, rows in name_barangay_bucket.items()
        if len(rows) > 1
    }

    return {
        "full": full_index,
        "name_age": name_age_index,
        "name_barangay": name_barangay_index,
        "name": name_index,
        "name_barangay_multi": name_barangay_multi,
    }


@lru_cache(maxsize=1)
def _load_notebook_recommendation_index() -> Dict[str, Dict[Any, Any]]:
    recommendations_path = _resolve_notebook_recommendations_path()
    if not os.path.exists(recommendations_path):
        return {"full_name_barangay": {}, "name_barangay": {}}

    full_name_barangay: Dict[Tuple[str, str], List[Dict[str, Any]]] = {}
    # Same encoding-detection logic as predictions CSV (cp1252 / utf-8 auto-detect)
    _rec_encodings = ["utf-8-sig", "utf-8", "cp1252", "latin-1"]
    _rec_file = None
    for _enc in _rec_encodings:
        try:
            _f = open(recommendations_path, "r", encoding=_enc, errors="strict", newline="")
            [_f.readline() for _ in range(5)]
            _f.seek(0)
            _rec_file = _f
            break
        except (UnicodeDecodeError, LookupError):
            try:
                _f.close()
            except Exception:
                pass
    if _rec_file is None:
        _rec_file = open(recommendations_path, "r", encoding="cp1252", errors="replace", newline="")
    with _rec_file as csvfile:
        for row in csv.DictReader(csvfile):
            name = str(row.get("name", "")).strip()
            if not name:
                continue
            actions = full_name_barangay.setdefault(
                (_normalize_identity_part(name), _normalize_identity_part(row.get("barangay"))),
                [],
            )
            # CSV columns: category (not domain), recommendation (not action), priority as string
            category = str(row.get("category", "")).strip().lower() or "general"
            risk_level = (row.get("risk_level") or "").strip().upper() or "MODERATE"
            csv_priority = str(row.get("priority", "")).strip().lower()
            urgency_map = {
                "immediate": "immediate", "urgent": "urgent",
                "planned": "planned", "maintenance": "maintenance",
            }
            urgency = urgency_map.get(csv_priority, _recommendation_urgency(risk_level))
            # v2 enriched fields (present only after notebook rerun with new cell 60)
            rec_code = str(row.get("recommendation_code", "") or "").strip() or None
            svc_provider = str(row.get("service_provider", "") or "").strip() or None
            evidence = str(row.get("evidence_source", "") or "").strip() or None
            apa_ref = str(row.get("apa_reference", "") or "").strip() or None
            src_type = str(row.get("source_type", "") or "").strip() or None
            trig_summary = str(row.get("trigger_summary", "") or "").strip() or None
            # domain falls back to category for CSVs that pre-date the v2 export
            domain = str(row.get("domain", "") or "").strip() or category
            rhv_raw = str(row.get("requires_human_validation", "1")).strip()
            requires_hv = rhv_raw not in ("0", "false", "False", "")
            docs_raw = str(row.get("documents_needed", "") or "").strip()
            try:
                docs_needed = json.loads(docs_raw) if docs_raw else None
            except (json.JSONDecodeError, ValueError):
                docs_needed = None
            actions.append({
                "priority": len(actions) + 1,
                "type": "domain",
                "domain": domain,
                "category": category,
                "action": str(row.get("recommendation", "") or row.get("action", "")),
                "reason": str(row.get("reason", "")),
                "urgency": urgency,
                "risk_level": risk_level.lower(),
                # v2 fields — None if CSV pre-dates notebook v2 update
                "recommendation_code":       rec_code,
                "service_provider":          svc_provider,
                "evidence_source":           evidence,
                "apa_reference":             apa_ref,
                "source_type":               src_type,
                "trigger_summary":           trig_summary,
                "requires_human_validation": requires_hv,
                "documents_needed":          docs_needed,
            })

    return {"full_name_barangay": full_name_barangay}


def _resolve_notebook_cluster_override(
    identity: Dict[str, Any],
    section_scores: Optional[Dict[str, Any]] = None,
    who_scores: Optional[Dict[str, Any]] = None,
) -> Optional[Dict[str, Any]]:
    if not identity:
        return None

    section_scores = section_scores or {}
    who_scores = who_scores or {}
    full_key = _identity_key(
        identity.get("first_name"),
        identity.get("last_name"),
        identity.get("barangay"),
        identity.get("age"),
    )
    indexes = _load_notebook_cluster_index()
    if full_key in indexes["full"]:
        return indexes["full"][full_key]

    fallback_key = (full_key[0], full_key[1], full_key[3])
    if fallback_key in indexes["name_age"]:
        return indexes["name_age"][fallback_key]

    name_barangay_key = _name_barangay_key(
        identity.get("first_name"),
        identity.get("last_name"),
        identity.get("barangay"),
    )
    if name_barangay_key in indexes["name_barangay"]:
        return indexes["name_barangay"][name_barangay_key]

    duplicate_candidates = indexes["name_barangay_multi"].get(name_barangay_key)
    if duplicate_candidates:
        current_age = _safe_float(identity.get("age"))

        def distance(candidate: Dict[str, Any]) -> float:
            age_distance = abs(candidate.get("age", current_age) - current_age)
            score_distance = (
                abs(candidate.get("overall_wellbeing", 0.0) - _safe_float(section_scores.get("overall_wellbeing"))) +
                abs(candidate.get("composite_risk", 0.0) - _safe_float(section_scores.get("rule_composite"))) +
                abs(candidate.get("sec5_eco_stability", 0.0) - _safe_float(section_scores.get("sec5_eco_stability"))) +
                abs(candidate.get("sec5_real_asset_score", 0.0) -
                    _safe_float(section_scores.get("sec5_real_asset_score"))) +
                abs(candidate.get("sec5_movable_asset_score", 0.0) -
                    _safe_float(section_scores.get("sec5_movable_asset_score"))) +
                abs(candidate.get("ic_score", 0.0) - _safe_float(who_scores.get("ic_score"))) +
                abs(candidate.get("env_score", 0.0) - _safe_float(who_scores.get("env_score"))) +
                abs(candidate.get("func_score", 0.0) - _safe_float(who_scores.get("func_score"))) +
                abs(candidate.get("qol_score", 0.0) - _safe_float(who_scores.get("qol_score")))
            )
            return age_distance * 0.25 + score_distance

        return min(duplicate_candidates, key=distance)

    return indexes["name"].get(_name_key(identity.get("first_name"), identity.get("last_name")))


def _resolve_notebook_recommendations(identity: Dict[str, Any]) -> Optional[List[Dict[str, Any]]]:
    if not identity:
        return None

    full_name = " ".join(
        part for part in [
            str(identity.get("first_name") or "").strip(),
            str(identity.get("last_name") or "").strip(),
        ]
        if part
    )
    key = (_normalize_identity_part(full_name), _normalize_identity_part(identity.get("barangay")))
    rows = _load_notebook_recommendation_index()["full_name_barangay"].get(key)
    if not rows:
        return None
    return [dict(row) for row in rows]


@lru_cache(maxsize=1)
def _load_cluster_mapping() -> Optional[Dict[int, int]]:
    mapping = _load_model("cluster_map.pkl")
    if mapping is None:
        mapping = _load_json("cluster_mapping.json")
    if not mapping:
        return None

    normalized: Dict[int, int] = {}
    for k, v in mapping.items():
        try:
            normalized[int(k)] = int(v)
        except Exception:
            continue
    return normalized or None


# ── Helpers ───────────────────────────────────────────────────────────────────
def _get_risk_level(score: float) -> str:
    # 3-level per-domain classifier (ic / env / func).
    # Composite >= 0.70 stays HIGH in the live app; urgency for those cases is
    # surfaced via priority_flag='urgent' (see _priority_flag), not a 4th level.
    if score >= RISK_THRESHOLDS["high"]:
        return "high"
    if score >= RISK_THRESHOLDS["moderate"]:
        return "moderate"
    return "low"


def _vector_from_feature_map(feature_map: Dict[str, Any], feature_names: List[str]) -> List[float]:
    return [float(feature_map.get(name, 0.0)) for name in feature_names]


def _safe_kmeans_predict(kmeans: Any, vector_2d: List[List[float]]) -> int:
    for dtype in (np.float64, np.float32):
        try:
            arr = np.asarray(vector_2d, dtype=dtype)
            return int(kmeans.predict(arr)[0])
        except Exception:
            continue
    return int(kmeans.predict(vector_2d)[0])


def _predict_model(model: Any, features: List[float]) -> Optional[float]:
    if model is None:
        return None
    try:
        required = getattr(model, "n_features_in_", len(features))
        arr = np.asarray([features[:required]], dtype=np.float64)
        return float(np.clip(model.predict(arr)[0], 0.0, 1.0))
    except Exception:
        # A loaded model (not None — that case returns above) throwing during
        # .predict() is a genuine anomaly, not the expected "model file
        # missing" case — silently swallowing it once hid a real bug (a
        # mismatched auth token upstream) behind an untraceable fallback to
        # 0.5 for every domain. Log it so a future failure is never silent
        # again; the caller still degrades to the fallback score regardless.
        logger.exception(
            "_predict_model failed — model=%s n_features_in_=%s len(features)=%s",
            type(model).__name__,
            getattr(model, "n_features_in_", "n/a"),
            len(features),
        )
        return None


def _dual_predict(gbr: Any, rfr: Any, features: List[float]) -> Tuple[Optional[float], Optional[float]]:
    return _predict_model(gbr, features), _predict_model(rfr, features)


def _clip01(value: float) -> float:
    return float(np.clip(value, 0.0, 1.0))


def _notebook_ml_score(gbr_pred: Optional[float], rfr_pred: Optional[float], fallback: float) -> float:
    # Notebook ensemble: rule-based=0.45, GBR=0.35, RFR=0.20
    # Weights are renormalised proportionally when a model is unavailable.
    if gbr_pred is None and rfr_pred is None:
        return _clip01(fallback)
    w_rule, w_gbr, w_rfr = 0.45, 0.35, 0.20
    total  = w_rule
    score  = fallback * w_rule
    if gbr_pred is not None:
        total += w_gbr
        score += gbr_pred * w_gbr
    if rfr_pred is not None:
        total += w_rfr
        score += rfr_pred * w_rfr
    return _clip01(score / total)


def _fallback_cluster_from_wellbeing(wb: float) -> int:
    # Bug 11 fix: returns 0-indexed raw KMeans-style IDs (0, 1, 2) so that the
    # caller's `raw_cluster_id + 1` mapping produces the correct named cluster ID
    # (1, 2, or 3).  Do NOT return 1/2/3 here — that would shift every cluster up
    # by one and bypass the +1 step.
    if wb >= 0.65:
        return 0
    if wb >= 0.40:
        return 1
    return 2


def _recommendation_urgency(overall_level: str, priority_flag: str = "") -> str:
    # Seniors with composite >= 0.70 carry priority_flag='urgent' (see _priority_flag).
    # overall_level is always LOW/MODERATE/HIGH in the live app; CRITICAL is folded to
    # HIGH at ingest. The overall_level=="CRITICAL" branch is a safety net only.
    if overall_level == "CRITICAL" or priority_flag == "urgent":
        return "urgent"
    return {
        "HIGH": "planned",
        "MODERATE": "planned",
        "LOW": "maintenance",
    }.get(overall_level, "planned")


def _priority_flag(composite: float) -> str:
    """Internal priority flag — aligns with CRITICAL risk level (composite >= 0.70)."""
    if composite >= URGENT_PRIORITY_THRESHOLD:
        return "urgent"
    if composite >= RISK_THRESHOLDS["high"]:
        return "priority_action"
    if composite >= RISK_THRESHOLDS["moderate"]:
        return "planned_monitoring"
    return "maintenance"


def _as_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    return str(value or "").strip().lower() in {"1", "true", "yes", "y"}


# ── Recommendation helpers (v2 — structured output with rule library) ─────────

def _build_rec_action(
    action: str,
    priority: int,
    urgency: str,
    risk_level: str,
    domain: str = "health",
    service_provider: str = "",
    evidence_source: str = "",
) -> Dict[str, Any]:
    """Wrap a plain action string as a full structured recommendation dict."""
    return {
        "priority": priority,
        "type": "domain",
        "domain": domain,
        "category": domain,
        "action": action,
        "urgency": urgency,
        "risk_level": risk_level,
        "reason": "",
        "service_provider": service_provider,
        "evidence_source": evidence_source,
        "recommendation_code": None,
        "requires_human_validation": True,
        "documents_needed": None,
    }


def _health_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """
    Return structured health recommendation dicts.
    Disease matching uses DISEASE_RULE_MAP (keyword → rule code); specialty
    concern fields (dental, optical, hearing, emotional) use dedicated rule codes.
    """
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    age_val = _safe_float(row.get("age"), 70)
    has_checkup = _safe_float(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0)
    env_fin_medical = _safe_float(row.get("env_fin_medical"), 3.0)
    mob_outside = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor = _safe_float(row.get("phy_mobility_indoor"), 3.0)

    concern_fields = [
        ("medical",   row.get("medical_concern", "")),
        ("dental",    row.get("dental_concern", "")),
        ("optical",   row.get("optical_concern", "")),
        ("hearing",   row.get("hearing_concern", "")),
        ("emotional", row.get("social_emotional_concern", "")),
    ]
    skip_tokens = {"none", "physically healthy", "healthy eyes", "healthy hearing",
                   "healthy teeth", "nan", "", "n/a"}
    matched_any = False
    seen_disease: set = set()

    for field_name, concern_text in concern_fields:
        text_value = str(concern_text or "").strip()
        if text_value.lower() in skip_tokens:
            continue
        text_lower = text_value.lower()

        # Specialty concern fields → dedicated rule codes
        if field_name == "dental":
            _add_rule("HLT-005")
            matched_any = True
            continue
        if field_name == "optical":
            _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
                "Senior reports optical/vision concern — assess need for eyeglasses "
                "or eye care referral through the senior citizen assistive device benefit."
            ))
            matched_any = True
            continue
        if field_name == "hearing":
            _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
                "Senior reports hearing concern — assess need for hearing aid "
                "through the senior citizen assistive device benefit."
            ))
            matched_any = True
            continue
        if field_name == "emotional":
            _add_rule("MENTAL_HEALTH_REFERRAL", reason=(
                f"Senior reported emotional concern: '{text_value[:80]}'. "
                "Psychosocial support or mental health referral recommended "
                "(decision-support flag, not a clinical diagnosis)."
            ))
            matched_any = True
            continue

        # Medical concern text: match DISEASE_RULE_MAP keywords
        matched_any_disease = False
        for keyword, (primary_code, secondary_code) in DISEASE_RULE_MAP.items():
            if keyword in text_lower and keyword not in seen_disease:
                seen_disease.add(keyword)
                _add_rule(primary_code, reason=f"Senior has reported: {keyword}.")
                if secondary_code:
                    _add_rule(secondary_code)
                matched_any_disease = True

        if matched_any_disease:
            matched_any = True
        elif text_value:
            # Non-empty medical concern not matched by any keyword → generic checkup referral
            _add_rule("HEALTH_YAKAP_CHECKUP", reason=(
                "Senior has reported a health concern — primary care consultation "
                "via PhilHealth YAKAP/Konsulta recommended."
            ))
            matched_any = True

    # Mobility difficulty → fall risk / assistive device check
    if mob_outside <= 2 or mob_indoor <= 2:
        _add_rule("HLT-008")
        matched_any = True

    # No recent check-up or aged 70+ → YAKAP primary care referral
    if not has_checkup or age_val >= 70:
        _add_rule("HEALTH_YAKAP_CHECKUP", reason=(
            "Senior has no recorded regular check-up or is aged 70+ — "
            "PhilHealth YAKAP/Konsulta primary-care enrollment recommended."
            if not has_checkup else
            "Senior is aged 70+ — annual preventive check-up via YAKAP/Konsulta recommended."
        ))
        matched_any = True

    # Medicine cost difficulty → Malasakit/PCSO MAP
    if env_fin_medical <= 2:
        _add_rule("MEDICAL_PCSO_MAP", reason=(
            "Senior reports difficulty affording medicines — "
            "PCSO MAP / Malasakit Center referral recommended."
        ))

    if not matched_any:
        _add_rule("HEALTH_YAKAP_CHECKUP", reason=(
            "Senior reports no significant health concerns. "
            "Continue preventive monitoring via PhilHealth YAKAP/Konsulta annual check-up."
        ))

    return recs


def _financial_recs(
    row: Dict[str, Any],
    income_enc_val: float,
    eco_stability: float,
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured financial recommendation dicts using the rule library (FIN/LVL codes)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    income_band = int(min(max(income_enc_val, 1), 9))
    env_fin_medical = _safe_float(row.get("env_fin_medical"), 3.0)
    env_fin_household = _safe_float(row.get("env_fin_household"), 3.0)
    age_val = _safe_float(row.get("age"), 70)
    func_indep = _safe_float(row.get("func_independence"), 3.0)

    if income_band <= 2 or eco_stability < 0.25:
        _add_rule("FINANCIAL_AICS")            # DSWD AICS crisis assistance
        _add_rule("FINANCIAL_SOCIAL_PENSION")  # Social Pension for Indigent Senior Citizens
        _add_rule("MEDICAL_PCSO_MAP")          # PCSO MAP / Malasakit Center
        _add_rule("FIN-004")                   # PhilHealth membership verification
    elif income_band <= 4 or eco_stability < 0.45:
        _add_rule("FINANCIAL_AICS")
        _add_rule("FIN-004")
    else:
        _add_rule("FIN-004")

    # No pension → social pension referral regardless of income tier
    if _safe_float(row.get("has_pension")) == 0:
        _add_rule("FINANCIAL_SOCIAL_PENSION")

    # Medicine or household cost difficulty
    if env_fin_medical <= 2:
        _add_rule("MEDICAL_PCSO_MAP", reason=(
            "Senior reports difficulty affording medicines — "
            "PCSO MAP / Malasakit Center referral recommended."
        ))
    if env_fin_household <= 2:
        _add_rule("FINANCIAL_AICS", reason=(
            "Senior reports difficulty meeting household expenses — "
            "DSWD AICS financial assistance referral recommended."
        ))

    # Physical disability → PWD benefits
    if _safe_float(row.get("sec4_has_disability", row.get("has_disability", 0))) == 1:
        _add_rule("FIN-006")

    # Centenarian and milestone ages (Expanded Centenarians Act, RA 11982)
    if int(age_val) in [80, 85, 90, 95, 100]:
        _add_rule("BENEFIT_CENTENARIAN")

    # Livelihood support — only for mobile, non-frail seniors with income need
    if income_band <= 4 and func_indep >= 3:
        _add_rule("LIVELIHOOD_SAFE_REFERRAL")

    return recs


def _social_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured social recommendation dicts using the rule library (SOC codes)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    lives_alone = _as_bool(row.get("sec4_lives_alone", row.get("lives_alone", 0)))
    soc_support = _safe_float(row.get("soc_social_support"), 3.0)
    soc_close_friend = _safe_float(row.get("soc_close_friend"), 3.0)
    soc_participation = _safe_float(row.get("soc_participation"), 3.0)
    soc_opportunity = _safe_float(row.get("soc_opportunity"), 3.0)
    soc_respect = _safe_float(row.get("soc_respect"), 3.0)
    psych_lonely_r = _safe_float(row.get("psych_lonely_r"), 3.0)
    family_support = _safe_float(row.get("sec2_family_support"), 0.5)
    is_member = _as_bool(row.get("is_association_member", 0))
    emotional_text = str(row.get("social_emotional_concern", "") or "").strip().lower()
    marital_status = str(row.get("marital_status", "") or "").strip()
    skip_tokens = {"none", "nan", "", "n/a"}

    # Lives alone → welfare check-in (SOCIAL_WELFARE_CHECK covers routine follow-up)
    if lives_alone:
        _add_rule("SOCIAL_WELFARE_CHECK")

    # Low social support or no close friend → social engagement / participation
    if soc_support <= 2 or soc_close_friend <= 2:
        _add_rule("SOCIAL_PARTICIPATION", reason=(
            "Senior has low social support or lacks a close person — "
            "participation in senior group activities and community programs recommended."
        ))

    # Low community participation → participation strengthening
    if soc_participation <= 2:
        _add_rule("SOC-006", reason=(
            "Senior shows low community participation indicators — "
            "active engagement in senior group activities recommended."
        ))
    elif soc_participation <= 3 and (soc_opportunity <= 2 or soc_respect <= 2):
        # Moderate participation but poor community opportunities or respect
        _add_rule("SOC-006", reason=(
            "Senior has limited community opportunities or low sense of social respect — "
            "facilitated participation recommended."
        ))

    # Loneliness indicator (psych_lonely_r low = more lonely; reversed scale)
    if psych_lonely_r <= 2:
        _add_rule("MENTAL_HEALTH_REFERRAL", reason=(
            "Senior shows loneliness indicators (QoL psychosocial score). "
            "Possible psychosocial concern — mental health and emotional support referral "
            "recommended (decision-support flag, not a clinical diagnosis)."
        ))

    # Low family support → caregiver capacity assessment
    if family_support < 0.3:
        _add_rule("SOC-005")

    # Widowed or separated → peer support / grief adjustment
    if marital_status in ("Widowed", "Separated"):
        _add_rule("SOC-007", reason=(
            f"Senior is {marital_status.lower()} — elevated social isolation and "
            "psychosocial adjustment need. Peer support referral recommended."
        ))

    # Not registered with Senior Citizen Association
    if not is_member:
        _add_rule("SOC-002")

    # Explicit emotional concern text with mental health keywords
    if emotional_text and emotional_text not in skip_tokens:
        if any(k in emotional_text for k in [
            "depression", "anxiety", "hopeless", "sad", "lonely", "grief",
            "stress", "trauma", "isolation", "withdrawn",
        ]):
            _add_rule("MENTAL_HEALTH_REFERRAL", reason=(
                f"Senior reported emotional concern: '{emotional_text[:80]}'. "
                "Possible psychosocial concern — mental health referral recommended "
                "(decision-support flag, not a clinical diagnosis)."
            ))

    # Lives alone + low social support → reinforce welfare check and social connection
    if lives_alone and soc_support <= 3:
        _add_rule("SOCIAL_PARTICIPATION", reason=(
            "Senior lives alone with low social support — "
            "social engagement and community involvement strongly recommended."
        ))

    return recs


def _functional_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured functional recommendation dicts using the rule library (FNC/HLT codes)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    age_val        = _safe_float(row.get("age"), 70)
    mob_outside    = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor     = _safe_float(row.get("phy_mobility_indoor"), 3.0)
    func_indep     = _safe_float(row.get("func_independence"), 3.0)
    func_autonomy  = _safe_float(row.get("func_autonomy"), 3.0)
    func_control   = _safe_float(row.get("func_control"), 3.0)
    phy_energy     = _safe_float(row.get("phy_energy"), 3.0)
    lives_alone    = _as_bool(row.get("sec4_lives_alone", row.get("lives_alone", 0)))

    # ── section_score composite triggers (preprocess-computed aggregates) ──────
    func_score      = _safe_float(row.get("sec6_func_score"), 0.5)
    risk_functional = _safe_float(row.get("risk_functional"), 0.0)
    dep_risk        = _safe_float(row.get("sec4_dependency_risk"), 0.0)
    phy_score       = _safe_float(row.get("sec6_phy_score"), 0.5)

    # Low composite functional score → ADL support
    if func_score < 0.45:
        _add_rule("FUNCTIONAL_ADL_SUPPORT", reason=(
            f"Senior's composite functional score ({func_score:.2f}) is below the "
            "threshold associated with functional independence risk — "
            "ADL support assessment recommended."
        ))

    # High functional risk score → ADL support
    if risk_functional > 0.55:
        _add_rule("FUNCTIONAL_ADL_SUPPORT", reason=(
            f"Senior's functional risk indicator ({risk_functional:.2f}) is elevated — "
            "home-care support or ADL assistance referral recommended."
        ))

    # High dependency risk → welfare check + ADL support
    if dep_risk > 0.60:
        _add_rule("SOCIAL_WELFARE_CHECK", reason=(
            f"Senior's dependency risk indicator ({dep_risk:.2f}) is high — "
            "welfare check and caregiver capacity assessment recommended."
        ))
        _add_rule("FUNCTIONAL_ADL_SUPPORT", reason=(
            f"Senior's dependency risk ({dep_risk:.2f}) indicates possible need for "
            "home-care or ADL assistance — assessment recommended."
        ))

    # Low physical score → fall/frailty risk (HLT-008)
    if phy_score < 0.40:
        _add_rule("HLT-008", reason=(
            f"Senior's composite physical score ({phy_score:.2f}) is below threshold — "
            "fall-risk and home-safety assessment recommended."
        ))

    # ── Item-level triggers ────────────────────────────────────────────────────

    # Severe mobility impairment → fall risk / assistive device check (HLT-008)
    if mob_outside <= 2 or mob_indoor <= 2:
        _add_rule("HLT-008")

    # Moderate mobility + age >= 75 → dedicated fall-risk home assessment (FNC-006)
    if (mob_outside <= 3 or mob_indoor <= 3) and age_val >= 75:
        _add_rule("FNC-006", reason=(
            f"Senior aged {int(age_val)} with moderate mobility limitation — "
            "fall-risk home assessment recommended."
        ))

    # Severe functional independence loss → ADL support (WHO Healthy Ageing)
    if func_indep <= 2:
        _add_rule("FUNCTIONAL_ADL_SUPPORT")

    # Moderate independence loss for 75+ seniors (softer threshold)
    if func_indep <= 3 and age_val >= 75:
        _add_rule("FUNCTIONAL_ADL_SUPPORT", reason=(
            f"Senior aged {int(age_val)} shows functional independence indicators "
            "warranting home-care support review."
        ))

    # Loss of autonomy or life-control → ADL + assistive support
    if func_autonomy <= 2 or func_control <= 2:
        _add_rule("FUNCTIONAL_ADL_SUPPORT", reason=(
            "Senior reports low sense of autonomy or daily life-control — "
            "functional support assessment recommended."
        ))

    # Lives alone with functional risk → reinforce welfare check
    if lives_alone and (func_score < 0.50 or risk_functional > 0.40):
        _add_rule("SOCIAL_WELFARE_CHECK", reason=(
            "Senior lives alone and shows functional risk indicators — "
            "welfare check and periodic follow-up recommended."
        ))

    # Low energy combined with age >= 70 → fall risk flag
    if phy_energy <= 2 and age_val >= 70:
        _add_rule("FNC-006", reason=(
            "Low physical energy combined with age increases fall and frailty risk."
        ))

    # Assistive device eligibility check for moderate functional or sensory impairment
    if (mob_outside <= 3 or mob_indoor <= 3 or func_indep <= 3) and age_val >= 70:
        _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE")

    # Comprehensive geriatric assessment for 80+ (polypharmacy, falls)
    if age_val >= 80:
        _add_rule("FNC-002")

    return recs


def _hc_access_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured healthcare-access recommendation dicts (FNC/FIN/HCA codes)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    _hc_raw = row.get("healthcare_difficulty", "")
    if isinstance(_hc_raw, (list, tuple)):
        hc_diff = " ".join(str(v) for v in _hc_raw).lower()
    else:
        hc_diff = str(_hc_raw or "").lower()
    service_acc = _safe_float(row.get("env_service_access"), 3.0)
    age_val = _safe_float(row.get("age"), 70)
    has_checkup = _safe_float(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0)
    medical_text = str(row.get("medical_concern", "") or "").lower()

    if "cost" in hc_diff or "expensive" in hc_diff:
        _add_rule("MEDICAL_PCSO_MAP")          # PCSO MAP referral
        _add_rule("MEDICAL_MALASAKIT_CENTER")  # Malasakit Center one-stop shop
        _add_rule("FIN-004")                   # PhilHealth membership check

    if "transport" in hc_diff or "distance" in hc_diff:
        _add_rule("ACCESS_TRANSPORT")          # transport / distance barrier

    # Low service accessibility (strict threshold)
    if service_acc <= 2:
        _add_rule("FNC-005")   # BHW home-based health monitoring

    # Moderate service accessibility for older seniors — still needs BHW support
    if service_acc <= 3 and age_val >= 75:
        _add_rule("FNC-005", reason=(
            f"Senior aged {int(age_val)} has limited health service access — "
            "BHW home-based monitoring recommended."
        ))

    # Chronic disease + no regular check-up = priority access gap
    chronic_keywords = ["hypertension", "diabetes", "high blood", "heart", "stroke", "copd",
                        "arthritis", "kidney", "cancer", "asthma"]
    has_chronic = any(kw in medical_text for kw in chronic_keywords)
    if has_chronic and not has_checkup:
        _add_rule("HCA-001", reason=(
            "Senior has chronic condition(s) but no recorded regular check-up — "
            "healthcare access gap identified. Priority scheduling recommended."
        ))

    # Transport/distance + no check-up → barangay transport / mobile clinic coordination
    if ("transport" in hc_diff or "distance" in hc_diff) and not has_checkup:
        _add_rule("ACCESS_TRANSPORT", reason=(
            "Senior faces transport/distance barriers to healthcare and has no recorded check-up — "
            "barangay transport assistance or mobile clinic coordination recommended."
        ))

    housing_concern = str(row.get("housing_concern", "") or "").strip().lower()
    if housing_concern and housing_concern not in {"none", "nan", ""}:
        _add_rule("FNC-004")   # home safety / housing concern

    return recs


def _household_safety_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured household safety recommendation dicts (HSF codes)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    household_condition = str(row.get("household_condition", "") or "").lower()
    housing_concern = str(row.get("housing_concern", "") or "").strip().lower()
    env_safe_home = _safe_float(row.get("env_safe_home"), 3.0)
    mob_outside = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor = _safe_float(row.get("phy_mobility_indoor"), 3.0)
    age_val = _safe_float(row.get("age"), 70)

    # Unsafe home safety score
    if env_safe_home <= 2:
        _add_rule("HSF-001", reason=(
            "Senior reports low home safety score — home hazard assessment and "
            "safety improvement recommended."
        ))

    # Poor household condition keywords
    unsafe_keywords = ["poor", "damaged", "unsafe", "dilapidated", "makeshift", "needs repair"]
    if any(kw in household_condition for kw in unsafe_keywords):
        _add_rule("HSF-001", reason=(
            "Senior's household condition indicates structural concerns — "
            "safety assessment and referral recommended."
        ))
        _add_rule("HSF-002", reason=(
            "Household condition suggests possible need for home-improvement assistance."
        ))

    # Housing concern present
    if housing_concern and housing_concern not in {"none", "nan", ""}:
        _add_rule("HSF-001", reason=(
            f"Senior reports housing concern: '{housing_concern[:60]}'. "
            "Home safety assessment recommended."
        ))

    # Mobility impairment + any home safety concern → higher fall hazard risk
    if (mob_outside <= 3 or mob_indoor <= 3) and env_safe_home <= 3 and age_val >= 70:
        _add_rule("HSF-001", reason=(
            "Senior has mobility limitation and lower home safety score — "
            "combined fall-hazard risk warrants priority home assessment."
        ))

    return recs


def _assistive_device_recs(
    row: Dict[str, Any],
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> List[Dict[str, Any]]:
    """Return structured assistive device recommendation dicts (FUNCTIONAL_ASSISTIVE_DEVICE / RA 9994)."""
    recs: List[Dict[str, Any]] = []
    _p = [1]
    seen_codes: set = set()

    def _np() -> int:
        v = _p[0]
        _p[0] += 1
        return v

    def _add_rule(code: str, reason: str = None) -> None:
        if code in seen_codes:
            return
        seen_codes.add(code)
        rec = _build_rec_from_rule(
            code, reason_override=reason,
            priority=_np(), urgency=urgency, risk_level=risk_level,
        )
        if rec:
            recs.append(rec)

    mob_outside = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor = _safe_float(row.get("phy_mobility_indoor"), 3.0)
    hearing_concern = str(row.get("hearing_concern", "") or "").strip().lower()
    optical_concern = str(row.get("optical_concern", "") or "").strip().lower()
    func_indep = _safe_float(row.get("func_independence"), 3.0)
    age_val = _safe_float(row.get("age"), 70)
    skip_tokens = {"none", "nan", "", "n/a", "no concern", "no concerns"}

    # Mobility aids (cane, walker, wheelchair) — moderate to severe impairment
    if mob_outside <= 3 or mob_indoor <= 3:
        _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
            "Senior shows mobility impairment — assess need for cane, walker, "
            "or wheelchair through the senior citizen assistive device benefit (RA 9994)."
        ))

    # Hearing aid — hearing concern reported
    if hearing_concern and hearing_concern not in skip_tokens:
        _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
            "Senior reports hearing concern — assess need for hearing aid "
            "through the senior citizen assistive device benefit (RA 9994)."
        ))

    # Eyeglasses / optical aid — optical concern reported
    if optical_concern and optical_concern not in skip_tokens:
        _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
            "Senior reports optical/vision concern — assess need for eyeglasses "
            "through the senior citizen assistive device benefit (RA 9994)."
        ))

    # Broad assistive device eligibility for 75+ with any functional limitation
    if age_val >= 75 and (func_indep <= 3 or mob_outside <= 3 or mob_indoor <= 3):
        _add_rule("FUNCTIONAL_ASSISTIVE_DEVICE", reason=(
            f"Senior aged {int(age_val)} with functional limitation — "
            "assistive device eligibility assessment recommended (RA 9994)."
        ))

    return recs


# ── Backward-compatible aliases (return plain action strings) ─────────────────

def generate_health_recs(row: Dict[str, Any]) -> List[str]:
    """Legacy alias — returns action strings only. New code should use _health_recs()."""
    return [r["action"] for r in _health_recs(row)]


def financial_actions(row: Dict[str, Any], income_enc_val: float, eco_stability: float) -> List[str]:
    """Legacy alias — returns action strings only. New code should use _financial_recs()."""
    return [r["action"] for r in _financial_recs(row, income_enc_val, eco_stability)]


def social_actions(row: Dict[str, Any]) -> List[str]:
    """Legacy alias — returns action strings only. New code should use _social_recs()."""
    return [r["action"] for r in _social_recs(row)]


def functional_actions(row: Dict[str, Any]) -> List[str]:
    """Legacy alias — returns action strings only. New code should use _functional_recs()."""
    return [r["action"] for r in _functional_recs(row)]


def hc_access_actions(row: Dict[str, Any]) -> List[str]:
    """Legacy alias — returns action strings only. New code should use _hc_access_recs()."""
    return [r["action"] for r in _hc_access_recs(row)]


def _build_recommendations(
    named_id: int,
    overall_level: str,
    feature_map: Dict[str, Any],
    section_scores: Dict[str, float],
    raw_context: Dict[str, Any],
    priority_flag: str = "",
) -> List[Dict[str, Any]]:
    merged = dict(feature_map)
    merged.update(section_scores)
    merged.update(raw_context)

    urgency = _recommendation_urgency(overall_level, priority_flag)
    risk_level_str = overall_level.lower()

    import catalog_recommender as _cr
    return _cr.build_recommendations(
        merged,
        urgency=urgency,
        risk_level=risk_level_str,
        cluster_id=named_id,
        overall_level=overall_level,
        priority_flag=priority_flag or "",
    )


# ── Main inference ────────────────────────────────────────────────────────────
def infer(preprocessed: Dict[str, Any]) -> Dict[str, Any]:
    warnings_list: List[str] = []

    _senior_id: Optional[int] = None
    _raw_senior_id = preprocessed.get("senior_id") or preprocessed.get("identity", {}).get("senior_id")
    if _raw_senior_id is not None:
        try:
            _senior_id = int(_raw_senior_id)
        except (TypeError, ValueError):
            pass

    scaled_features = preprocessed.get("scaled_features", []) or []
    reduced_features = preprocessed.get("reduced_features", []) or []
    section_scores = preprocessed.get("section_scores", {}) or {}
    rule_scores = preprocessed.get("rule_scores", {}) or {}
    who_scores = preprocessed.get("who_domain_scores", {}) or {}
    feature_map = preprocessed.get("feature_map", {}) or {}
    raw_context = preprocessed.get("raw_context", {}) or {}

    # 1. Cluster assignment
    cluster_map      = _load_cluster_mapping()
    cluster_profiles = _load_cluster_profiles()

    raw_cluster_id: Optional[int] = None

    if "_precomputed_named_id" in preprocessed:
        # Direct named cluster injection — bypasses raw->named mapping lookup entirely.
        # Used by fix_cluster_distribution.py after auto-calibrating the mapping.
        raw_cluster_id   = int(preprocessed.get("_precomputed_raw_cluster_id", 0))
        named_id         = max(1, min(4, int(preprocessed["_precomputed_named_id"])))
        reduced_features = list(preprocessed.get("reduced_features") or [])
        scaled_features  = list(preprocessed.get("scaled_features")  or [])
        cluster_profile  = cluster_profiles[named_id]
        # Skip to recommendations — all cluster assignment logic below is bypassed
        warnings_list.append(f"Cluster named_id={named_id} injected directly (auto-calibrated mapping).")
    elif "_precomputed_raw_cluster_id" in preprocessed:
        # Fast path: batch_cluster_assign already ran UMAP+KMeans for the whole batch.
        raw_cluster_id   = int(preprocessed["_precomputed_raw_cluster_id"])
        reduced_features = list(preprocessed.get("reduced_features") or [])
        scaled_features  = list(preprocessed.get("scaled_features")  or [])
    else:
        # Single-senior path: scaler → [nearest-centroid OR UMAP] → cluster.
        #
        # Primary path (default): nearest-centroid in 31D scaled space.
        #   - Pure matrix arithmetic — deterministic on every device.
        #   - UMAP is NOT called (no numba, no random-projection variability).
        #
        # Fallback path: UMAP+KMeans (only when centroid file is missing or
        #   ENABLE_DETERMINISTIC_CLUSTER=false is set for debug purposes).
        batch_mode = bool(os.environ.get("OSCA_BATCH_MODE"))
        scaler  = _load_model("scaler.pkl")
        reducer = None if batch_mode else _load_first_model(["umap_nd.pkl", "umap_reducer.pkl"])
        kmeans  = _load_first_model(["kmeans.pkl", "kmeans_k3.pkl", "kmeans_model.pkl"])

        feature_names = _load_json("feature_list.json")

        if scaler is not None and feature_map:
            try:
                if hasattr(scaler, "feature_names_in_") and isinstance(feature_names, list) and feature_names:
                    # Step 1 — scale (always needed regardless of cluster path)
                    scaler_input_names = list(scaler.feature_names_in_)
                    scaler_row  = _vector_from_feature_map(feature_map, scaler_input_names)
                    full_scaled = scaler.transform(
                        pd.DataFrame([scaler_row], columns=scaler_input_names)
                    )[0]
                    scaler_feat_idx = {f: i for i, f in enumerate(scaler_input_names)}
                    row_scaled_30 = [
                        float(full_scaled[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
                        for f in feature_names
                    ]
                    scaled_features = row_scaled_30

                    # Step 2a — KNN classifier (primary path, ~92.8% label consistency)
                    _knn_named_id: Optional[int] = None
                    if ENABLE_KNN_CLUSTER:
                        _knn_named_id = _knn_cluster_assign(row_scaled_30, feature_names)

                    if _knn_named_id is not None:
                        # ✅ KNN path — best consistency with batch UMAP labels
                        named_id = max(1, min(4, _knn_named_id))
                        cluster_profile = cluster_profiles[named_id]
                        raw_cluster_id  = next(
                            (raw_id for raw_id, mapped_id in (cluster_map or {}).items()
                             if mapped_id == named_id),
                            0,
                        )
                        preprocessed = dict(preprocessed)
                        preprocessed["_precomputed_named_id"]       = named_id
                        preprocessed["_precomputed_raw_cluster_id"] = raw_cluster_id
                        warnings_list.append("KNN classifier cluster assignment used (UMAP skipped).")

                    else:
                        # Step 2b — nearest-centroid (fallback when cluster_assignment_knn_k5.pkl missing)
                        _det_named_id: Optional[int] = None
                        if ENABLE_DETERMINISTIC_CLUSTER:
                            _det_named_id = _deterministic_cluster_assign(row_scaled_30, feature_names)

                        if _det_named_id is not None:
                            # ✅ Centroid path — no UMAP invoked
                            named_id = max(1, min(4, _det_named_id))
                            cluster_profile = cluster_profiles[named_id]
                            raw_cluster_id  = next(
                                (raw_id for raw_id, mapped_id in (cluster_map or {}).items()
                                 if mapped_id == named_id),
                                0,
                            )
                            preprocessed = dict(preprocessed)
                            preprocessed["_precomputed_named_id"]       = named_id
                            preprocessed["_precomputed_raw_cluster_id"] = raw_cluster_id
                            warnings_list.append(
                                "Nearest-centroid cluster assignment used "
                                "(cluster_assignment_knn_k5.pkl missing)."
                            )

                    if "_precomputed_named_id" not in preprocessed and reducer is not None and kmeans is not None:
                        # ⚠️ UMAP fallback — only when both KNN and centroid are unavailable
                        warnings_list.append(
                            "Falling back to UMAP cluster assignment "
                            "(cluster_assignment_knn_k5.pkl and cluster_centroids_scaled.json both missing "
                            "or both flags off). Results may differ across devices."
                        )
                        reducer.transform_seed = 42
                        if not getattr(reducer, "_rp_forest", None):
                            reducer.transform_queue_size = 0.0
                        row_reduced      = reducer.transform([row_scaled_30])
                        raw_cluster_id   = _safe_kmeans_predict(kmeans, row_reduced)
                        reduced_features = row_reduced[0].tolist()

                else:
                    # Fallback: scaler has no feature_names_in_ (older artifact)
                    expected: int = int(getattr(scaler, "n_features_in_", 0) or 0)
                    cluster_input_names: Optional[List[str]] = feature_names
                    if not cluster_input_names or (expected and len(cluster_input_names) != expected):
                        warnings_list.append(
                            "No cluster feature list matched scaler input size; cluster fallback used."
                        )
                        cluster_input_names = None
                    if cluster_input_names and reducer is not None and kmeans is not None:
                        cluster_row  = _vector_from_feature_map(feature_map, cluster_input_names)
                        row_scaled   = scaler.transform(
                            pd.DataFrame([cluster_row], columns=cluster_input_names)
                        )[0].tolist()
                        scaled_features = row_scaled  # noqa: F841
                        reducer.transform_seed = 42
                        if getattr(reducer, "_rp_forest", None) is None:
                            reducer.transform_queue_size = 0.0
                        row_reduced      = reducer.transform([row_scaled])
                        raw_cluster_id   = _safe_kmeans_predict(kmeans, row_reduced)
                        reduced_features = row_reduced[0].tolist()
            except Exception as exc:
                warnings_list.append(f"Cluster assignment failed: {exc}")

        if raw_cluster_id is None and kmeans is not None:
            try:
                required = getattr(kmeans, "n_features_in_",
                                   len(reduced_features) if reduced_features else 0)
                if len(reduced_features) >= required:
                    raw_cluster_id = _safe_kmeans_predict(kmeans, [reduced_features[:required]])
                else:
                    warnings_list.append(
                        "Reduced features shorter than KMeans expected input; fallback cluster used."
                    )
            except Exception as exc:
                warnings_list.append(f"KMeans prediction failed: {exc}")

        if raw_cluster_id is None:
            wb = float(section_scores.get("overall_wellbeing", 0.5))
            raw_cluster_id = _fallback_cluster_from_wellbeing(wb)
            warnings_list.append("KMeans unavailable/incompatible; heuristic cluster assignment used.")

    if "_precomputed_named_id" not in preprocessed:
        # Normal path: resolve named_id from raw_cluster_id via cluster_map
        if cluster_map and raw_cluster_id in cluster_map:
            named_id = cluster_map[raw_cluster_id]
        else:
            named_id = raw_cluster_id + 1
            if named_id < 1 or named_id > 4:
                logger.warning(
                    "raw_cluster_id=%s produced out-of-range named_id=%s; clamping to [1,4].",
                    raw_cluster_id, named_id,
                )
        named_id = max(1, min(4, int(named_id)))
        cluster_profile = cluster_profiles[named_id]

    notebook_override = None
    notebook_recommendations = None
    if ENABLE_NOTEBOOK_OVERRIDES:
        notebook_override = _resolve_notebook_cluster_override(
            preprocessed.get("identity", {}) or {},
            section_scores=section_scores,
            who_scores=who_scores,
        )
        notebook_recommendations = _resolve_notebook_recommendations(
            preprocessed.get("identity", {}) or {}
        )
    if notebook_override:
        named_id = max(1, min(4, int(notebook_override.get("cluster_id", named_id))))
        cluster_profile = cluster_profiles[named_id]
        raw_cluster_id = next(
            (raw_id for raw_id, mapped_id in (cluster_map or {}).items() if mapped_id == named_id),
            named_id - 1,
        )
        warnings_list.append("Cluster matched to notebook export for known senior record.")

    # 2. Risk prediction
    gbr_ic = _load_model("gbr_ic_risk.pkl")
    rfr_ic = _load_model("rfr_ic_risk.pkl")
    gbr_env = _load_model("gbr_env_risk.pkl")
    rfr_env = _load_model("rfr_env_risk.pkl")
    gbr_func = _load_model("gbr_func_risk.pkl")
    rfr_func = _load_model("rfr_func_risk.pkl")
    ml_feature_names = _load_json("ml_risk_features.json")
    if isinstance(ml_feature_names, list) and feature_map:
        ml_features = _vector_from_feature_map(feature_map, ml_feature_names)
    else:
        # Bug 8 fix: scaled_features are on the VIF-scaler's standardised scale, not the
        # raw feature scale the ML risk models were trained on.  Never substitute them.
        # Fall back to rule-based composite scores only (via the None-pred path below).
        ml_features = []
        if not ml_feature_names:
            warnings_list.append("ml_risk_features.json not found; ML risk models will use rule-based fallback.")
        else:
            warnings_list.append("feature_map unavailable; ML risk models will use rule-based fallback.")

    gbr_ic_pred, rfr_ic_pred = _dual_predict(gbr_ic, rfr_ic, ml_features)
    gbr_env_pred, rfr_env_pred = _dual_predict(gbr_env, rfr_env, ml_features)
    gbr_func_pred, rfr_func_pred = _dual_predict(gbr_func, rfr_func, ml_features)

    ic_fallback = _clip01(_safe_float(
        section_scores.get("ic_risk"), 1.0 - (_safe_float(who_scores.get("ic_score"), 3.0) - 1.0) / 4.0
    ))
    env_fallback = _clip01(_safe_float(
        section_scores.get("env_risk"), 1.0 - (_safe_float(who_scores.get("env_score"), 3.0) - 1.0) / 4.0
    ))
    func_fallback = _clip01(_safe_float(
        section_scores.get("func_risk"), 1.0 - (_safe_float(who_scores.get("func_score"), 3.0) - 1.0) / 4.0
    ))

    if gbr_ic_pred is None and rfr_ic_pred is None:
        warnings_list.append("IC ML models unavailable/incompatible; fallback score used.")
    if gbr_env_pred is None and rfr_env_pred is None:
        warnings_list.append("ENV ML models unavailable/incompatible; fallback score used.")
    if gbr_func_pred is None and rfr_func_pred is None:
        warnings_list.append("FUNC ML models unavailable/incompatible; fallback score used.")

    ic_risk_raw = _notebook_ml_score(gbr_ic_pred, rfr_ic_pred, ic_fallback)
    env_risk_raw = _notebook_ml_score(gbr_env_pred, rfr_env_pred, env_fallback)
    func_risk_raw = _notebook_ml_score(gbr_func_pred, rfr_func_pred, func_fallback)

    # Composite matches the notebook formula (cell 45):
    #   ml_composite  = IC×0.35 + ENV×0.35 + FUNC×0.30
    #   composite     = rule_composite×0.45 + ml_composite×0.55
    ml_composite = ic_risk_raw * 0.35 + env_risk_raw * 0.35 + func_risk_raw * 0.30
    rule_composite_val = _clip01(_safe_float(rule_scores.get("rule_composite", ml_composite)))
    composite_risk = _clip01(rule_composite_val * 0.45 + ml_composite * 0.55)

    wellbeing_score = float(section_scores.get("overall_wellbeing", 0.5))

    # 3. Risk levels
    ic_level = _get_risk_level(ic_risk_raw)
    env_level = _get_risk_level(env_risk_raw)
    func_level = _get_risk_level(func_risk_raw)

    # 3-level classification: LOW / MODERATE / HIGH
    # Scores >= 0.70 remain HIGH; urgency is surfaced via priority_flag, not a 4th level.
    if composite_risk >= RISK_THRESHOLDS["high"]:
        overall_level = "HIGH"
    elif composite_risk >= RISK_THRESHOLDS["moderate"]:
        overall_level = "MODERATE"
    else:
        overall_level = "LOW"

    if notebook_override:
        # Bug 10 fix: a zero value in the CSV means "not available", not a true zero
        # risk score.  Only override when the CSV value is strictly positive so that
        # a correctly-computed ML score is never silently zeroed out.
        _ov_ic = _safe_float(notebook_override.get("ml_ic_risk"), 0.0)
        _ov_env = _safe_float(notebook_override.get("ml_env_risk"), 0.0)
        _ov_func = _safe_float(notebook_override.get("ml_func_risk"), 0.0)
        _ov_comp = _safe_float(notebook_override.get("composite_risk"), 0.0)
        if _ov_ic > 0:
            ic_risk_raw = _clip01(_ov_ic)
        if _ov_env > 0:
            env_risk_raw = _clip01(_ov_env)
        if _ov_func > 0:
            func_risk_raw = _clip01(_ov_func)
        if _ov_comp > 0:
            composite_risk = _clip01(_ov_comp)
        _nb_level = (notebook_override.get("risk_level") or overall_level or "").upper()
        # Fold CRITICAL→HIGH: the notebook emits a 4-level analytical scheme but the live
        # app is 3-level display.  Urgency for composite >= 0.70 is surfaced via priority_flag.
        if _nb_level == "CRITICAL":
            _nb_level = "HIGH"
        overall_level = _nb_level if _nb_level in ("LOW", "MODERATE", "HIGH") else overall_level
        ic_level = _get_risk_level(ic_risk_raw)
        env_level = _get_risk_level(env_risk_raw)
        func_level = _get_risk_level(func_risk_raw)
    # 4. Recommendations — compute priority_flag first so urgency assignment is correct
    computed_priority_flag = _priority_flag(composite_risk)
    recs = _build_recommendations(
        named_id=named_id,
        overall_level=overall_level,
        feature_map=feature_map,
        section_scores=section_scores,
        raw_context=raw_context,
        priority_flag=computed_priority_flag,
    )
    if notebook_recommendations:
        recs = notebook_recommendations
        warnings_list.append("Recommendations matched to notebook export for known senior record.")

    # XAI — compute after cluster + risk scores are final
    ml_risk_feats = _load_json("ml_risk_features.json") or []
    xai_data: Optional[Dict[str, Any]] = None
    try:
        if ml_risk_feats:
            xai_data = _compute_xai(feature_map, named_id, ml_risk_feats)
    except Exception as _xai_err:
        logger.warning("XAI computation failed (non-fatal): %s", _xai_err)

    result = {
        "status": "success",
        "cluster": {
            "raw_id": raw_cluster_id,
            "named_id": named_id,
            "name": cluster_profile["name"],
            "ic": cluster_profile["ic"],
            "env": cluster_profile["env"],
            "func": cluster_profile["func"],
            "description": cluster_profile["description"],
        },
        "risk_scores": {
            "ic_risk": round(ic_risk_raw, 4),
            "env_risk": round(env_risk_raw, 4),
            "func_risk": round(func_risk_raw, 4),
            "composite_risk": round(composite_risk, 4),
            "wellbeing_score": round(wellbeing_score, 4),
        },
        "risk_levels": {
            "ic": ic_level,
            "env": env_level,
            "func": func_level,
            "overall": overall_level,
        },
        "priority_flag": computed_priority_flag,
        "domain_risks": {
            "risk_medical":    round(float(rule_scores.get("risk_medical",    0.0)), 4),
            "risk_financial":  round(float(rule_scores.get("risk_financial",  0.0)), 4),
            "risk_social":     round(float(rule_scores.get("risk_social",     0.0)), 4),
            "risk_functional": round(float(rule_scores.get("risk_functional", 0.0)), 4),
            "risk_housing":    round(float(rule_scores.get("risk_housing",    0.0)), 4),
            "risk_hc_access":  round(float(rule_scores.get("risk_hc_access",  0.0)), 4),
            "risk_sensory":    round(float(rule_scores.get("risk_sensory",    0.0)), 4),
            "rule_composite":  round(float(rule_scores.get("rule_composite",  rule_composite_val)), 4),
        },
        "who_scores": {
            "ic_score":   round(float(who_scores.get("ic_score",   3.0)), 4),
            "env_score":  round(float(who_scores.get("env_score",  3.0)), 4),
            "func_score": round(float(who_scores.get("func_score", 3.0)), 4),
            "qol_score":  round(float(who_scores.get("qol_score",  3.0)), 4),
        },
        "recommendations": recs,
        "xai": xai_data,
        "section_scores": section_scores,
        "model_metadata": {
            "model_version": MODEL_VERSION,
            "model_dir": MODEL_DIR,
            "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
            "notebook_override_applied": bool(notebook_override),
            # prediction_source is the canonical label persisted to ml_results.prediction_source
            "prediction_source": "notebook_cache" if notebook_override else "live_model",
            "is_cached_prediction": bool(notebook_override),
        },
        "warnings": warnings_list,
    }

    return result


# ── Batch cluster assignment (used by local_ml_runner) ───────────────────────
def batch_cluster_assign(preprocessed_list: List[Dict[str, Any]]) -> List[str]:
    """
    One-shot batch UMAP + KMeans for every valid preprocessed payload.
    Injects '_precomputed_raw_cluster_id' and 'reduced_features' into each dict
    so that subsequent infer() calls skip per-senior UMAP/KMeans.
    Returns a list of warning/info strings.
    """
    warn: List[str] = []

    scaler  = _load_model("scaler.pkl")
    reducer = _load_first_model(["umap_nd.pkl", "umap_reducer.pkl"])
    kmeans  = _load_first_model(["kmeans.pkl", "kmeans_k3.pkl", "kmeans_model.pkl"])

    if reducer is None or kmeans is None:
        warn.append("batch KMeans path: UMAP or KMeans unavailable; heuristic fallback will be used.")
        return warn
    if scaler is None:
        warn.append("batch KMeans path: scaler unavailable; heuristic fallback will be used.")
        return warn

    feature_names = _load_json("feature_list.json")  # 30 final clustering columns

    # Mirror the notebook flow used in infer() single-senior path:
    #   1. Build scaler input from scaler.feature_names_in_ (full VIF superset)
    #   2. Scale the full vector
    #   3. Select the 30 final clustering columns (feature_list.json) from the scaled output
    #   4. UMAP-reduce the 30-column slice → KMeans
    # Using feature_list.json directly as the scaler input was wrong when the scaler
    # was trained on more features — it would produce a different scaled space than the
    # single-senior path, causing inconsistent cluster assignments across batch vs. single.
    use_notebook_flow = (
        hasattr(scaler, "feature_names_in_")
        and isinstance(feature_names, list)
        and len(feature_names) > 0
    )

    if use_notebook_flow:
        scaler_input_names = list(scaler.feature_names_in_)
    else:
        vif_features = _load_json("vif_retained_features.json")
        scaler_input_names = feature_names or vif_features or []

    valid_indices: List[int] = []
    cluster_rows:  List[List[float]] = []
    for idx, p in enumerate(preprocessed_list):
        if not isinstance(p, dict):
            continue
        feature_map = p.get("feature_map") or {}
        if not feature_map:
            continue
        cluster_rows.append([float(feature_map.get(k, 0.0)) for k in scaler_input_names])
        valid_indices.append(idx)

    if not cluster_rows:
        warn.append("batch KMeans path: no valid feature maps found; heuristic fallback will be used.")
        return warn

    try:
        X        = np.asarray(cluster_rows, dtype=np.float64)
        X_scaled = scaler.transform(X)

        if use_notebook_flow:
            # Select only the 30 final clustering columns from the scaled output
            scaler_feat_idx = {f: i for i, f in enumerate(scaler.feature_names_in_)}
            col_indices = [scaler_feat_idx[f] for f in feature_names if f in scaler_feat_idx]
            X_cluster = X_scaled[:, col_indices] if col_indices else X_scaled
        else:
            X_cluster = X_scaled

        reducer.transform_seed = 42
        if getattr(reducer, "_rp_forest", None) is None:
            reducer.transform_queue_size = 0.0
        np.random.seed(42)
        X_reduced  = reducer.transform(X_cluster)
        raw_ids    = kmeans.predict(X_reduced).tolist()

        for i, idx in enumerate(valid_indices):
            preprocessed_list[idx]["_precomputed_raw_cluster_id"] = int(raw_ids[i])
            preprocessed_list[idx]["reduced_features"]            = X_reduced[i].tolist()

        warn.append(
            f"batch KMeans path used successfully for {len(valid_indices)} seniors."
        )
    except Exception as exc:
        import traceback as _tb
        logger.warning("batch_cluster_assign failed:\n%s", _tb.format_exc())
        warn.append(
            f"batch KMeans path failed ({exc}); heuristic fallback will be used."
        )

    return warn


# ── Flask API ─────────────────────────────────────────────────────────────────
@app.route("/model_insights", methods=["GET"])
def model_insights():
    try:
        return jsonify(_build_model_insights())
    except Exception as e:
        logger.error("model_insights error: %s", e)
        return jsonify({"error": str(e)}), 500


@app.route("/health", methods=["GET"])
def health():
    # models_ready reflects whether _warm_up_models() has actually finished —
    # the port binding (and this endpoint answering "ok") happens the instant
    # the process starts, well before the ~30s artifact load completes. A
    # caller that treats "status":"ok" alone as "safe to submit real work
    # now" pays the cold-load cost itself on the first real request instead
    # (reported: "first analysis took over a minute, second run 2-3s" — the
    # wake modal had already declared the service "Ready" before it actually
    # was).
    return jsonify({
        "status": "ok",
        "service": "osca-inference",
        "model_dir": MODEL_DIR,
        "model_version": MODEL_VERSION,
        "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
        "models_ready": _MODELS_READY.is_set(),
    })


@app.route("/infer", methods=["POST"])
def infer_endpoint():
    try:
        payload = request.get_json(force=True)
        if not payload or not isinstance(payload, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

        logger.info("Infer request: senior_id=%s", payload.get("senior_id"))
        result = infer(payload)
        return jsonify(result)
    except Exception as exc:
        logger.exception("Inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/batch_infer", methods=["POST"])
def batch_infer_endpoint():
    try:
        batch = request.get_json(force=True)
        if not isinstance(batch, list):
            return jsonify({"status": "error", "message": "Expected JSON array"}), 400

        logger.info("Batch infer request: %d items", len(batch))
        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({
                    "status": "error",
                    "message": f"Item at index {idx} is not an object",
                })
                continue
            # Per-item guard (matches /batch_preprocess) — one malformed senior
            # used to raise here and take the whole endpoint down with a 500,
            # which the PHP side then treated as a total chunk failure and
            # degraded every senior in it to the local/heuristic fallback.
            # persistResults() on the PHP side already throws (and is already
            # caught per-item in MlService::runBatchPipeline()) when a result
            # is missing required keys, so an error dict here correctly marks
            # only this one senior as failed instead of the whole chunk.
            try:
                results.append(infer(item))
            except Exception as exc:
                logger.exception("Batch infer error at index %d", idx)
                results.append({"status": "error", "message": str(exc)})

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


_MODELS_READY = threading.Event()


def _warm_up_models() -> None:
    """Pre-load model artifacts into their @lru_cache stores in a background
    thread so the FIRST real /infer request doesn't pay the ~30s cold-load
    cost (models otherwise load lazily on first use). Runs off the request
    path — /health responds immediately regardless of warm-up progress, and a
    request arriving mid-warm-up just loads on demand as it does today.
    Best-effort only: any failure here is logged and swallowed, never fatal.
    Sets _MODELS_READY when done (success or failure) — see /health, which
    reports this so callers can tell "port bound" apart from "actually warm".
    """
    try:
        _load_model("scaler.pkl")
        _load_first_model(["umap_nd.pkl", "umap_reducer.pkl"])
        _load_first_model(["kmeans.pkl", "kmeans_k3.pkl", "kmeans_model.pkl"])
        _load_knn_classifier()
        _load_cluster_centroids_scaled()
        _load_cluster_feature_means()
        _load_cluster_profiles()
        for domain in ("ic", "env", "func"):
            _load_model(f"gbr_{domain}_risk.pkl")
            _load_model(f"rfr_{domain}_risk.pkl")
        if ENABLE_NOTEBOOK_OVERRIDES:
            _load_notebook_cluster_index()
            _load_notebook_recommendation_index()
        logger.info("Model warm-up complete — caches primed for first request")
    except Exception:
        logger.exception("Model warm-up failed (non-fatal); models will load lazily on first request")
    finally:
        # Set even on failure — a request arriving now will load lazily on
        # demand exactly as it would have before this flag existed; the
        # flag's only job is to stop /health claiming warm-up is DONE when
        # it hasn't even finished trying.
        _MODELS_READY.set()


if __name__ == "__main__":
    # Render (and similar PaaS hosts) assign a dynamic $PORT and only route
    # traffic to the port the process actually binds — it must win over the
    # service-specific vars below. Locally, $PORT is unset, so
    # INFERENCE_PORT/PYTHON_INFERENCE_PORT (set by start_services.sh) still apply.
    port = int(
        os.environ.get("PORT")
        or os.environ.get("INFERENCE_PORT")
        or os.environ.get("PYTHON_INFERENCE_PORT")
        or 5002
    )
    logger.info("Starting OSCA Inference Service on port %s — MODEL_DIR=%s", port, MODEL_DIR)
    _validate_artifacts_at_startup()
    threading.Thread(target=_warm_up_models, daemon=True, name="model-warmup").start()
    try:
        from waitress import serve
        logger.info("Serving via waitress (production WSGI) on port %s", port)
        serve(app, host="0.0.0.0", port=port, threads=8)
    except ImportError:
        logger.warning("waitress not installed; falling back to Flask dev server (not for production use)")
        app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False)
