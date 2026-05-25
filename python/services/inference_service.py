"""
OSCA ML Inference Service
Runs KMeans clustering and ensemble GBR+RFR models to indicate possible risk levels
for decision support based on multidimensional senior citizen indicators.
Generates structured recommendations per WHO Healthy Ageing domains.

Usage:
    python inference_service.py
"""

import os
import sys

# Force single-threaded numba/UMAP so transform() is deterministic across devices
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

import json
import pickle
import warnings
import logging
import csv
import re
import unicodedata
from functools import lru_cache
from typing import Any, Dict, List, Optional, Tuple

try:
    import pymysql
    _PYMYSQL_AVAILABLE = True
except ImportError:
    _PYMYSQL_AVAILABLE = False

import numpy as np
import pandas as pd
from flask import Flask, request, jsonify

warnings.filterwarnings("ignore")
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


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
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", False)

# Semantic version written to every ml_results row so reports can filter by
# model generation. Bump the patch digit when thresholds change; bump minor
# when model .pkl files are retrained; bump major for schema-breaking changes.
MODEL_VERSION = "1.1.0"

# Expected artifact dimensions (must match the notebook training run).
# These constants let startup validation catch mismatched bundles early.
_EXPECTED_SCALER_N_FEATURES  = 39   # scaler.feature_names_in_ length
_EXPECTED_UMAP_N_COMPONENTS  = 10   # umap_nd.pkl n_components
_EXPECTED_UMAP_INPUT_N_FEATS = 31   # feature_list.json length (UMAP raw input)
_EXPECTED_KMEANS_N_CLUSTERS  = 3    # kmeans.pkl n_clusters
_EXPECTED_KMEANS_N_FEATURES  = 10   # kmeans.pkl n_features_in_ (== UMAP output)
_EXPECTED_CLUSTER_RAW_IDS    = {0, 1, 2}  # all raw KMeans IDs must be in cluster_mapping.json


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


def _db_connect() -> Optional[Any]:
    if not _PYMYSQL_AVAILABLE:
        return None
    try:
        env = _read_laravel_env()
        conn = pymysql.connect(
            host=env.get("DB_HOST", "127.0.0.1"),
            port=int(env.get("DB_PORT", 3306)),
            user=env.get("DB_USERNAME", "root"),
            password=env.get("DB_PASSWORD", ""),
            database=env.get("DB_DATABASE", "osca_db"),
            connect_timeout=3,
            read_timeout=5,
            write_timeout=5,
            autocommit=True,
        )
        return conn
    except Exception as exc:
        logger.debug("DB cache connect failed (non-fatal): %s", exc)
        return None


def _db_cache_lookup(senior_id: int) -> Optional[Dict[str, Any]]:
    """
    Query the latest ml_results row for this senior. Returns a dict shaped like
    a notebook_override payload so the same injection path is reused.
    Returns None if not found or DB is unreachable.

    Also returns the raw prediction_source so the infer() caller can decide
    whether to skip re-scoring notebook_cache rows.
    """
    if not senior_id:
        return None
    conn = _db_connect()
    if conn is None:
        return None
    try:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute(
                """
                SELECT cluster_id, cluster_named_id, cluster_name,
                       composite_risk, ic_risk, env_risk, func_risk,
                       overall_risk_level, wellbeing_score,
                       ic_score, env_score, func_score, qol_score,
                       prediction_source, model_version
                FROM ml_results
                WHERE senior_citizen_id = %s
                ORDER BY id DESC
                LIMIT 1
                """,
                (senior_id,),
            )
            row = cur.fetchone()
        if not row:
            return None
        # Map to the same shape as notebook_override payloads
        return {
            "cluster_id":         int(row["cluster_named_id"] or 1),
            "cluster_name":       row.get("cluster_name"),
            "risk_level":         (row.get("overall_risk_level") or "").upper(),
            "composite_risk":     float(row["composite_risk"] or 0.0),
            "ml_ic_risk":         float(row["ic_risk"] or 0.0),
            "ml_env_risk":        float(row["env_risk"] or 0.0),
            "ml_func_risk":       float(row["func_risk"] or 0.0),
            "ic_score":           float(row["ic_score"] or 3.0),
            "env_score":          float(row["env_score"] or 3.0),
            "func_score":         float(row["func_score"] or 3.0),
            "qol_score":          float(row["qol_score"] or 3.0),
            # raw_cluster_id is cluster_named_id - 1 (KMeans 0-indexed)
            "_raw_cluster_id":    max(0, int(row["cluster_named_id"] or 1) - 1),
            # Propagate source/version so infer() can detect notebook_cache rows
            "_prediction_source": row.get("prediction_source") or "",
            "_model_version":     row.get("model_version") or "",
        }
    except Exception as exc:
        logger.debug("DB cache lookup failed (non-fatal): %s", exc)
        return None
    finally:
        try:
            conn.close()
        except Exception:
            pass


def _db_cache_write(senior_id: int, result: Dict[str, Any]) -> None:
    """
    After a fresh UMAP run, persist the key ML outputs back to ml_results so
    any other device can read them on the next request for this senior.
    This is a best-effort write — failures are logged but never raise.
    """
    if not senior_id or not _PYMYSQL_AVAILABLE:
        return
    conn = _db_connect()
    if conn is None:
        return
    try:
        cluster  = result.get("cluster", {})
        scores   = result.get("risk_scores", {})
        levels   = result.get("risk_levels", {})
        who      = result.get("who_scores", {})
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE ml_results
                SET cluster_id       = %s,
                    cluster_named_id = %s,
                    cluster_name     = %s,
                    composite_risk   = %s,
                    ic_risk          = %s,
                    env_risk         = %s,
                    func_risk        = %s,
                    overall_risk_level = %s,
                    wellbeing_score  = %s,
                    ic_score         = %s,
                    env_score        = %s,
                    func_score       = %s,
                    qol_score        = %s,
                    processed_at     = NOW()
                WHERE senior_citizen_id = %s
                ORDER BY id DESC
                LIMIT 1
                """,
                (
                    cluster.get("raw_id"),
                    cluster.get("named_id"),
                    cluster.get("name"),
                    scores.get("composite_risk"),
                    scores.get("ic_risk"),
                    scores.get("env_risk"),
                    scores.get("func_risk"),
                    levels.get("overall"),
                    scores.get("wellbeing_score"),
                    who.get("ic_score"),
                    who.get("env_score"),
                    who.get("func_score"),
                    who.get("qol_score"),
                    senior_id,
                ),
            )
    except Exception as exc:
        logger.debug("DB cache write failed (non-fatal): %s", exc)
    finally:
        try:
            conn.close()
        except Exception:
            pass


RISK_THRESHOLDS = {
    "high": 0.50,
    "moderate": 0.30,
}
# Scores >= 0.70 are still HIGH but flagged as urgent-priority internally.
URGENT_PRIORITY_THRESHOLD = 0.70

CLUSTER_PROFILES = {
    1: {
        "name": "High Functioning",
        "ic": "High", "env": "High", "func": "High",
        "risk_level": "LOW",
        "description": "Independent, financially stable, socially engaged seniors.",
    },
    2: {
        "name": "Moderate / Mixed Needs",
        "ic": "Moderate", "env": "Moderate", "func": "Moderate",
        "risk_level": "MODERATE",
        "description": "Mixed domain performance; some areas need targeted support.",
    },
    3: {
        "name": "Low Functioning / Multi-domain Risk",
        "ic": "Low", "env": "Low", "func": "Low",
        "risk_level": "HIGH",
        "description": "Multi-domain vulnerabilities requiring immediate intervention.",
    },
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

    if not all(i in merged for i in (1, 2, 3)):
        logger.warning("cluster_metadata.json missing required cluster IDs; using hardcoded defaults.")
        return {k: dict(v) for k, v in CLUSTER_PROFILES.items()}

    logger.info("cluster_metadata.json loaded successfully.")
    return merged



DISEASE_ACTIONS = {
    "coronary heart disease": [
        "Refer to cardiologist for CHD evaluation and medication review.",
        "Monitor blood pressure and heart rate weekly.",
        "Advise low-sodium, low-fat cardiac diet.",
        "Verify PhilHealth Z-Benefit (heart disease) coverage.",
    ],
    "heart disease": [
        "Refer to cardiologist for cardiac management.",
        "Monitor blood pressure and heart rate weekly.",
        "Advise low-sodium diet and light aerobic activity.",
        "Verify PhilHealth Z-Benefit (heart disease) coverage.",
    ],
    "stroke": [
        "Coordinate with neurologist for stroke follow-up care.",
        "Enroll in physical/speech therapy rehabilitation program.",
        "Conduct falls-risk assessment and home hazard evaluation.",
        "Verify PhilHealth Z-Benefit (stroke) coverage.",
    ],
    "cancer": [
        "Coordinate with oncologist for ongoing treatment / surveillance.",
        "Apply for PCSO Individual Medical Assistance Program (IMAP).",
        "Refer to Malasakit Center for hospital bill reduction.",
        "Assess caregiver support needs for treatment schedule.",
    ],
    "dementia": [
        "Refer to geriatric psychiatrist for dementia assessment (MMSE).",
        "Engage family / caregiver in dementia care education.",
        "Assess home safety for wandering and fall prevention.",
        "Link to OSCA memory-care support group.",
    ],
    "alzheimer": [
        "Refer to neurologist / geriatrician for Alzheimer management.",
        "Provide caregiver education on behavioral management.",
        "Assess legal capacity (advance directive, guardianship).",
    ],
    "parkinson": [
        "Refer to neurologist for Parkinson disease management.",
        "Enroll in physical therapy for balance and gait training.",
        "Evaluate need for mobility aids (walker, cane).",
    ],
    "diabetes": [
        "Monitor fasting blood glucose monthly.",
        "Advise diabetic diet (low-GI, portion control).",
        "Inspect feet regularly for diabetic foot complications.",
        "Ensure HbA1c checked every 3 months.",
    ],
    "hypertension": [
        "Monitor blood pressure at least twice weekly.",
        "Advise DASH diet (low sodium, high potassium).",
        "Verify anti-hypertensive medication adherence.",
        "Alert for signs of hypertensive crisis (BP >180/120).",
    ],
    "high blood pressure": [
        "Monitor blood pressure at least twice weekly.",
        "Advise low-sodium diet and stress reduction.",
        "Verify anti-hypertensive medication adherence.",
    ],
    "depression": [
        "Refer to mental health professional for depression screening.",
        "Encourage social engagement and regular physical activity.",
        "Connect with OSCA mental health support group.",
    ],
    "asthma": [
        "Ensure maintenance inhaler prescription is active.",
        "Advise avoidance of asthma triggers (dust, smoke, allergens).",
        "Provide written asthma action plan for emergencies.",
    ],
    "copd": [
        "Refer to pulmonologist for COPD staging and spirometry.",
        "Advise smoking cessation and avoidance of pollutants.",
        "Enroll in pulmonary rehabilitation program.",
    ],
    "tuberculosis": [
        "Ensure enrollment in DOTS program.",
        "Verify completion of anti-TB medication regimen.",
        "Notify contacts for TB screening.",
    ],
    "arthritis": [
        "Refer to rheumatologist or orthopedist for joint assessment.",
        "Recommend low-impact exercise (swimming, walking) for joint mobility.",
        "Evaluate need for assistive devices to reduce joint stress.",
    ],
    "osteoporosis": [
        "Order bone mineral density (BMD) test.",
        "Advise calcium and vitamin D supplementation.",
        "Conduct fall prevention home assessment.",
    ],
    "glaucoma": [
        "Refer to ophthalmologist for IOP monitoring and glaucoma management.",
        "Ensure adherence to prescribed eye drops.",
        "Assess home environment for visual safety hazards.",
    ],
    "cataract": [
        "Refer to ophthalmologist for cataract evaluation.",
        "Discuss PhilHealth surgical benefit for cataract surgery.",
        "Advise UV-protective eyewear outdoors.",
    ],
    "hearing impairment": [
        "Refer to ENT / audiologist for hearing evaluation.",
        "Assess eligibility for hearing aid through OSCA or DSWD.",
        "Advise family on communication strategies for hearing-impaired seniors.",
    ],
    "kidney": [
        "Refer to nephrologist for kidney function evaluation.",
        "Advise low-protein, low-sodium diet.",
        "Verify PhilHealth Z-Benefit (hemodialysis) if applicable.",
    ],
    "chronic kidney disease": [
        "Refer to nephrologist for CKD staging and management.",
        "Monitor blood pressure and fluid intake carefully.",
        "Verify PhilHealth Z-Benefit (dialysis) coverage.",
    ],
    "anemia": [
        "Check CBC and iron studies; refer to physician for anemia management.",
        "Advise iron-rich diet (red meat, leafy greens, legumes).",
        "Assess for underlying cause (GI bleeding, malnutrition).",
    ],
    "physical disability": [
        "Refer to physical/occupational therapist for functional assessment.",
        "Assess eligibility for OSCA Persons with Disability (PWD) benefits.",
        "Conduct home modification assessment (ramps, grab bars, wide doorways).",
    ],
    "other chronic disease": [
        "Schedule comprehensive evaluation with a physician to identify and document the specific chronic condition.",
        "Ensure enrollment in PhilHealth and verify applicable benefit packages for chronic illness management.",
        "Advise regular follow-up at the barangay health center for monitoring and medication adherence.",
        "Refer to appropriate specialist based on confirmed diagnosis.",
    ],
    "__generic__": [
        "Schedule comprehensive health assessment at barangay health center.",
        "Ensure annual physical examination and laboratory workup.",
        "Review current medications for interactions or adverse effects.",
    ],
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
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "reports", "predictions", "senior_recommendations_flat.csv")),
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
                "cluster_id": max(1, min(3, cluster_id)),
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
            urgency_map = {"immediate": "immediate", "urgent": "urgent", "planned": "planned", "maintenance": "maintenance"}
            urgency = urgency_map.get(csv_priority, _recommendation_urgency(risk_level))
            actions.append({
                "priority": len(actions) + 1,
                "type": "domain",
                "domain": category,
                "category": category,
                "action": str(row.get("recommendation", "") or row.get("action", "")),
                "reason": str(row.get("reason", "")),
                "urgency": urgency,
                "risk_level": risk_level.lower(),
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
                abs(candidate.get("sec5_real_asset_score", 0.0) - _safe_float(section_scores.get("sec5_real_asset_score"))) +
                abs(candidate.get("sec5_movable_asset_score", 0.0) - _safe_float(section_scores.get("sec5_movable_asset_score"))) +
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
    # Only truly urgent seniors (composite >= 0.70, priority_flag="urgent") get
    # urgency="urgent".  All other HIGH seniors are "planned" — they need action
    # but should not flood the urgent-priority queue.
    if priority_flag == "urgent":
        return "urgent"
    return {
        "HIGH": "planned",
        "MODERATE": "planned",
        "LOW": "maintenance",
    }.get(overall_level, "planned")


def _priority_flag(composite: float) -> str:
    """Internal priority flag — not an official risk level.
    HIGH risk seniors with composite >= 0.70 are flagged as urgent-priority.
    """
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


def financial_actions(row: Dict[str, Any], income_enc_val: float, eco_stability: float) -> List[str]:
    actions: List[str] = []
    income_band = int(min(max(income_enc_val, 1), 9))
    real_asset_s = _safe_float(row.get("sec5_real_asset_score"), 0.3)
    if income_band <= 2 or eco_stability < 0.25:
        actions += [
            "Apply for DSWD Sustainable Livelihood Program (SLP) and Pantawid Pamilyang Pilipino Program (4Ps).",
            "Request OSCA indigent assessment for free medicine allocation.",
            "Apply for PCSO Individual Medical Assistance Program (IMAP).",
            "Verify Malasakit Center enrollment for hospital bill reduction.",
        ]
    elif income_band <= 4 or eco_stability < 0.45:
        actions += [
            "Verify enrollment in PhilHealth (subsidized/indigent member category).",
            "Apply for PCSO IMAP for medical assistance.",
            "Request OSCA financial assistance program assessment.",
        ]
    else:
        actions += [
            "Ensure active PhilHealth membership and check benefit utilization.",
            "Review PhilHealth senior citizen outpatient package.",
        ]
    if _safe_float(row.get("has_pension")) == 0:
        actions.append("Check eligibility for Social Pension for Indigent Senior Citizens (DSWD).")
    if real_asset_s < 0.2:
        actions.append("Assess eligibility for DSWD housing assistance programs.")
    if _safe_float(row.get("env_fin_medical"), 3.0) <= 2:
        actions.append("Refer to Botika ng Barangay for subsidized medicine access.")
    if _safe_float(row.get("env_fin_household"), 3.0) <= 2:
        actions.append("Link to local OSCA emergency financial assistance for utility bills.")
    return actions


def social_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    if _as_bool(row.get("sec4_lives_alone", row.get("lives_alone", 0))):
        actions.append("Enroll in OSCA regular home visit / buddy check program.")
        actions.append("Coordinate with barangay for periodic welfare check visits.")
    if _safe_float(row.get("soc_social_support"), 3.0) <= 2:
        actions.append("Refer to DSWD Supplementary Feeding Program and group activities.")
    if _safe_float(row.get("soc_close_friend"), 3.0) <= 2:
        actions.append("Encourage attendance at OSCA senior friendship / social club.")
    if _safe_float(row.get("sec2_family_support"), 0.5) < 0.3:
        actions.append("Conduct family assessment for support capacity and caregiver stress.")
    if not _as_bool(row.get("is_association_member", 0)):
        actions.append("Encourage registration with the local Senior Citizen Association (SCA).")
    return actions


def functional_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    age_val = _safe_float(row.get("age"), 70)
    mob_outside = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor = _safe_float(row.get("phy_mobility_indoor"), 3.0)
    func_indep = _safe_float(row.get("func_independence"), 3.0)
    has_checkup = _safe_float(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0)
    movable_s = _safe_float(row.get("sec5_movable_asset_score"), 0.3)
    if mob_outside <= 2 or mob_indoor <= 2:
        actions.append("Request occupational therapy home visit for mobility assessment.")
        actions.append("Assess need for assistive devices: cane, walker, wheelchair.")
        actions.append("Conduct home hazard inspection - remove floor clutter, add grab bars.")
    if movable_s < 0.3:
        actions.append("Assess eligibility for DSWD assistive device program.")
    if func_indep <= 2:
        actions.append("Assess ADL limitations for home care support.")
        actions.append("Link to DSWD / LGU home care services for assistance with daily tasks.")
    if age_val >= 80:
        actions.append("Schedule comprehensive geriatric assessment with physician.")
        actions.append("Review polypharmacy - check for 5+ concurrent medications.")
    if not has_checkup:
        actions.append("Schedule immediate health screening at barangay health center (BHC).")
    return actions


def hc_access_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    # healthcare_difficulty arrives as a list from DB (JSON field); join for substring matching
    _hc_raw = row.get("healthcare_difficulty", "")
    if isinstance(_hc_raw, (list, tuple)):
        hc_diff = " ".join(str(v) for v in _hc_raw).lower()
    else:
        hc_diff = str(_hc_raw or "").lower()
    service_acc = _safe_float(row.get("env_service_access"), 3.0)
    movable_s = _safe_float(row.get("sec5_movable_asset_score"), 0.3)
    if "cost" in hc_diff or "expensive" in hc_diff:
        actions.append("Apply for Malasakit Center for reduced hospital costs.")
        actions.append("Verify PhilHealth active status for outpatient/inpatient coverage.")
    if "transport" in hc_diff or "distance" in hc_diff:
        actions.append("Coordinate with barangay for transportation assistance to health facilities.")
        actions.append("Request OSCA mobile health clinic schedule for community visit.")
    if movable_s < 0.3:
        actions.append("Assess availability of community transport or ride-sharing for clinic visits.")
    if service_acc <= 2:
        actions.append("Coordinate barangay health worker (BHW) for home-based health monitoring.")
    return actions


def generate_health_recs(row: Dict[str, Any]) -> List[str]:
    recs: List[str] = []
    seen = set()
    concern_fields = [
        row.get("medical_concern", ""),
        row.get("dental_concern", ""),
        row.get("optical_concern", ""),
        row.get("hearing_concern", ""),
        row.get("social_emotional_concern", ""),
    ]
    matched_any = False
    skip_tokens = {"none", "physically healthy", "healthy eyes", "healthy hearing", "healthy teeth", "nan", "", "n/a"}
    for concern_text in concern_fields:
        text_value = str(concern_text or "").strip()
        if text_value.lower() in skip_tokens:
            continue
        text_lower = text_value.lower()
        matched = [kw for kw in DISEASE_ACTIONS if kw != "__generic__" and kw in text_lower]
        if matched:
            for disease in matched:
                if disease not in seen:
                    seen.add(disease)
                    recs.extend(DISEASE_ACTIONS[disease])
                    matched_any = True
        else:
            generic_key = text_value[:40]
            if generic_key not in seen:
                seen.add(generic_key)
                recs.extend(DISEASE_ACTIONS["__generic__"])
                matched_any = True
    if not matched_any:
        recs.append("Senior reports no significant health concerns. Continue preventive monitoring.")
    return list(dict.fromkeys(recs))


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

    grouped = {
        "health": generate_health_recs(merged),
        "financial": financial_actions(
            merged,
            _safe_float(merged.get("income_enc"), 5.0),
            _safe_float(merged.get("sec5_eco_stability"), 0.4),
        ),
        "social": social_actions(merged),
        "functional": functional_actions(merged),
        "hc_access": hc_access_actions(merged),
    }

    recs: List[Dict[str, Any]] = []
    priority = 1
    urgency = _recommendation_urgency(overall_level, priority_flag)
    for domain, actions in grouped.items():
        for action in actions:
            recs.append({
                "priority": priority,
                "type": "domain",
                "domain": domain,
                "category": domain,
                "action": action,
                "urgency": urgency,
                "risk_level": overall_level.lower(),
            })
            priority += 1
    return recs


# ── Main inference ────────────────────────────────────────────────────────────
def infer(preprocessed: Dict[str, Any]) -> Dict[str, Any]:
    warnings_list: List[str] = []

    senior_id: Optional[int] = None
    _raw_senior_id = preprocessed.get("senior_id") or preprocessed.get("identity", {}).get("senior_id")
    if _raw_senior_id is not None:
        try:
            senior_id = int(_raw_senior_id)
        except (TypeError, ValueError):
            pass

    # DB cache hit: reuse stored ML result for this senior, skipping UMAP entirely.
    # This ensures identical results across all devices for seniors already processed.
    # Exception: if the caller already injected _precomputed_named_id (e.g. fix_cluster_distribution.py
    # after auto-calibration), trust that value — the DB still holds the pre-calibration named_id
    # and would overwrite the corrected one if we let it through.
    _db_cached = None
    if senior_id and "_precomputed_named_id" not in preprocessed:
        _db_cached = _db_cache_lookup(senior_id)

    # Notebook-cache protection: if the DB already has a notebook_cache result
    # (prediction_source = 'notebook_cache', model_version = 1.1.0), do NOT
    # re-score — return it directly.  This prevents a re-analysis run from
    # overwriting validated notebook results with live model scores.
    # ml:repair-notebook-cache bypasses this by not going through infer() for
    # seniors that need repair; it forces a fresh CSV match instead.
    if (
        ENABLE_NOTEBOOK_OVERRIDES
        and _db_cached
        and _db_cached.get("_prediction_source") == "notebook_cache"
        and "_precomputed_named_id" not in preprocessed
    ):
        # Build a minimal but complete result from the DB cache so the caller
        # gets a properly-shaped payload with prediction_source = notebook_cache.
        named_id      = max(1, min(3, int(_db_cached["cluster_id"])))
        cluster_profs = _load_cluster_profiles()
        cluster_prof  = cluster_profs[named_id]
        raw_cluster   = _db_cached["_raw_cluster_id"]
        ic_r   = _db_cached["ml_ic_risk"]
        env_r  = _db_cached["ml_env_risk"]
        func_r = _db_cached["ml_func_risk"]
        comp   = _db_cached["composite_risk"]
        lvl    = (_db_cached["risk_level"] or "MODERATE").upper()
        if lvl == "CRITICAL":
            lvl = "HIGH"
        section_scores = preprocessed.get("section_scores", {}) or {}
        who_scores     = preprocessed.get("who_domain_scores", {}) or {}
        rule_scores    = preprocessed.get("rule_scores", {}) or {}
        feature_map    = preprocessed.get("feature_map", {}) or {}
        raw_context    = preprocessed.get("raw_context", {}) or {}
        pf             = _priority_flag(comp)
        recs = _build_recommendations(named_id, lvl, feature_map, section_scores, raw_context, pf)
        # Try to attach notebook recommendations if available
        nb_recs = _resolve_notebook_recommendations(preprocessed.get("identity", {}) or {})
        if nb_recs:
            recs = nb_recs
        return {
            "status": "success",
            "cluster": {
                "raw_id": raw_cluster, "named_id": named_id,
                "name": cluster_prof["name"],
                "ic": cluster_prof["ic"], "env": cluster_prof["env"], "func": cluster_prof["func"],
                "description": cluster_prof["description"],
            },
            "risk_scores": {
                "ic_risk": round(ic_r, 4), "env_risk": round(env_r, 4),
                "func_risk": round(func_r, 4), "composite_risk": round(comp, 4),
                "wellbeing_score": round(float(section_scores.get("overall_wellbeing", 0.5)), 4),
            },
            "risk_levels": {
                "ic": _get_risk_level(ic_r), "env": _get_risk_level(env_r),
                "func": _get_risk_level(func_r), "overall": lvl,
            },
            "priority_flag": pf,
            "domain_risks": {
                "risk_medical":    round(float(rule_scores.get("risk_medical",    0.0)), 4),
                "risk_financial":  round(float(rule_scores.get("risk_financial",  0.0)), 4),
                "risk_social":     round(float(rule_scores.get("risk_social",     0.0)), 4),
                "risk_functional": round(float(rule_scores.get("risk_functional", 0.0)), 4),
                "risk_housing":    round(float(rule_scores.get("risk_housing",    0.0)), 4),
                "risk_hc_access":  round(float(rule_scores.get("risk_hc_access",  0.0)), 4),
                "risk_sensory":    round(float(rule_scores.get("risk_sensory",    0.0)), 4),
                "rule_composite":  round(float(rule_scores.get("rule_composite",  comp)), 4),
            },
            "who_scores": {
                "ic_score":   round(float(_db_cached.get("ic_score",   3.0)), 4),
                "env_score":  round(float(_db_cached.get("env_score",  3.0)), 4),
                "func_score": round(float(_db_cached.get("func_score", 3.0)), 4),
                "qol_score":  round(float(_db_cached.get("qol_score",  3.0)), 4),
            },
            "recommendations": recs,
            "section_scores": section_scores,
            "model_metadata": {
                "model_version": MODEL_VERSION,
                "model_dir": MODEL_DIR,
                "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
                "notebook_override_applied": True,
                "db_cache_hit": True,
                "prediction_source": "notebook_cache",
                "is_cached_prediction": True,
            },
            "warnings": ["notebook_cache result returned from DB (re-scoring skipped)."],
        }

    if _db_cached:
        preprocessed = dict(preprocessed)
        preprocessed["_precomputed_raw_cluster_id"] = _db_cached["_raw_cluster_id"]
        # Inject named_id directly from DB so we bypass _load_cluster_mapping() lru_cache.
        # The lru_cache may hold a stale mapping from before fix_cluster_distribution.py ran,
        # which would silently flip the cluster label for any senior viewed after the fix.
        preprocessed["_precomputed_named_id"] = _db_cached["cluster_id"]
        warnings_list.append("Cluster and risk scores loaded from shared DB cache (UMAP skipped).")

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
        named_id         = max(1, min(3, int(preprocessed["_precomputed_named_id"])))
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
        # Single-senior path: scaler → UMAP → KMeans.
        # In legacy OSCA_BATCH_MODE=1 (without batch_cluster_assign), reducer is
        # skipped and the heuristic fallback below catches it.
        batch_mode = bool(os.environ.get("OSCA_BATCH_MODE"))
        scaler  = _load_model("scaler.pkl")
        reducer = None if batch_mode else _load_first_model(["umap_nd.pkl", "umap_reducer.pkl"])
        kmeans  = _load_first_model(["kmeans.pkl", "kmeans_k3.pkl", "kmeans_model.pkl"])

        feature_names = _load_json("feature_list.json")

        if scaler is not None and reducer is not None and kmeans is not None and feature_map:
            try:
                # Notebook flow: scale all scaler features (VIF superset), then
                # select only the final clustering columns (feature_list.json) from
                # the scaled output before passing to UMAP.
                if hasattr(scaler, "feature_names_in_") and isinstance(feature_names, list) and feature_names:
                    scaler_input_names = list(scaler.feature_names_in_)
                    scaler_row = _vector_from_feature_map(feature_map, scaler_input_names)
                    full_scaled = scaler.transform(
                        pd.DataFrame([scaler_row], columns=scaler_input_names)
                    )[0]
                    scaler_feat_idx = {f: i for i, f in enumerate(scaler_input_names)}
                    row_scaled_30 = [float(full_scaled[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
                                     for f in feature_names]
                    reducer.transform_seed = 42
                    if not getattr(reducer, "_rp_forest", None):
                        reducer.transform_queue_size = 0.0
                    row_reduced  = reducer.transform([row_scaled_30])
                    raw_cluster_id  = _safe_kmeans_predict(kmeans, row_reduced)
                    reduced_features = row_reduced[0].tolist()
                    scaled_features  = row_scaled_30
                else:
                    # Fallback: try feature_names directly against scaler
                    expected: int = int(getattr(scaler, "n_features_in_", 0) or 0)
                    cluster_input_names: Optional[List[str]] = feature_names
                    if not cluster_input_names or (expected and len(cluster_input_names) != expected):
                        warnings_list.append(
                            "No cluster feature list matched scaler input size; cluster fallback used."
                        )
                        cluster_input_names = None
                    if cluster_input_names:
                        cluster_row  = _vector_from_feature_map(feature_map, cluster_input_names)
                        row_scaled   = scaler.transform(
                            pd.DataFrame([cluster_row], columns=cluster_input_names)
                        )[0].tolist()
                        reducer.transform_seed = 42
                        if getattr(reducer, "_rp_forest", None) is None:
                            reducer.transform_queue_size = 0.0
                        row_reduced  = reducer.transform([row_scaled])
                        raw_cluster_id  = _safe_kmeans_predict(kmeans, row_reduced)
                        reduced_features = row_reduced[0].tolist()
                        scaled_features  = row_scaled
            except Exception as exc:
                warnings_list.append(f"Notebook-style cluster path failed: {exc}")

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
            if named_id < 1 or named_id > 3:
                logger.warning(
                    "raw_cluster_id=%s produced out-of-range named_id=%s; clamping to [1,3].",
                    raw_cluster_id, named_id,
                )
        named_id = max(1, min(3, int(named_id)))
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
        named_id = max(1, min(3, int(notebook_override.get("cluster_id", named_id))))
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

    ic_fallback = _clip01(_safe_float(section_scores.get("ic_risk"), 1.0 - (_safe_float(who_scores.get("ic_score"), 3.0) - 1.0) / 4.0))
    env_fallback = _clip01(_safe_float(section_scores.get("env_risk"), 1.0 - (_safe_float(who_scores.get("env_score"), 3.0) - 1.0) / 4.0))
    func_fallback = _clip01(_safe_float(section_scores.get("func_risk"), 1.0 - (_safe_float(who_scores.get("func_score"), 3.0) - 1.0) / 4.0))

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
        # Remap legacy CRITICAL → HIGH (3-level system)
        if _nb_level == "CRITICAL":
            _nb_level = "HIGH"
        overall_level = _nb_level or overall_level
        ic_level = _get_risk_level(ic_risk_raw)
        env_level = _get_risk_level(env_risk_raw)
        func_level = _get_risk_level(func_risk_raw)
    elif _db_cached:
        # DB cache: apply stored risk scores so all devices agree on this senior's values.
        # Same "only override if > 0" guard as notebook_override above.
        _ov_ic   = _safe_float(_db_cached.get("ml_ic_risk"),   0.0)
        _ov_env  = _safe_float(_db_cached.get("ml_env_risk"),  0.0)
        _ov_func = _safe_float(_db_cached.get("ml_func_risk"), 0.0)
        _ov_comp = _safe_float(_db_cached.get("composite_risk"), 0.0)
        if _ov_ic > 0:
            ic_risk_raw = _clip01(_ov_ic)
        if _ov_env > 0:
            env_risk_raw = _clip01(_ov_env)
        if _ov_func > 0:
            func_risk_raw = _clip01(_ov_func)
        if _ov_comp > 0:
            composite_risk = _clip01(_ov_comp)
        _db_level = (_db_cached.get("risk_level") or overall_level or "").upper()
        if _db_level == "CRITICAL":
            _db_level = "HIGH"
        overall_level = _db_level or overall_level
        ic_level   = _get_risk_level(ic_risk_raw)
        env_level  = _get_risk_level(env_risk_raw)
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
        "section_scores": section_scores,
        "model_metadata": {
            "model_version": MODEL_VERSION,
            "model_dir": MODEL_DIR,
            "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
            "notebook_override_applied": bool(notebook_override),
            "db_cache_hit": bool(_db_cached),
            # prediction_source is the canonical label persisted to ml_results.prediction_source
            "prediction_source": (
                "notebook_cache" if notebook_override
                else ("live_model" if not _db_cached else "live_model")
            ),
            "is_cached_prediction": bool(notebook_override),
        },
        "warnings": warnings_list,
    }

    # Write-back: if this was a fresh UMAP run (no DB cache hit, no notebook override),
    # persist the result so every other device gets a consistent answer next time.
    if senior_id and not _db_cached and not notebook_override:
        _db_cache_write(senior_id, result)

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
@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "service": "osca-inference",
        "model_dir": MODEL_DIR,
        "model_version": MODEL_VERSION,
        "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
    })


@app.route("/infer", methods=["POST"])
def infer_endpoint():
    try:
        payload = request.get_json(force=True)
        if not payload or not isinstance(payload, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

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

        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({
                    "status": "error",
                    "message": f"Item at index {idx} is not an object",
                })
                continue
            results.append(infer(item))

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


if __name__ == "__main__":
    port = int(os.environ.get("INFERENCE_PORT", os.environ.get("PYTHON_INFERENCE_PORT", 5002)))
    logger.info("Starting OSCA Inference Service on port %s — MODEL_DIR=%s", port, MODEL_DIR)
    _validate_artifacts_at_startup()
    app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False)
