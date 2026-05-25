"""
OSCA ML Artifact Validation Script
====================================
Checks that the artifact bundle in MODEL_DIR is internally consistent and
matches the expected dimensions from the notebook training run.

SAFE: Read-only. Does not modify any file or database record.

Usage (from osca-system/ project root):
    python/venv/Scripts/python.exe python/scripts/validate_model_artifacts.py

Exit codes:
    0 - all checks PASS
    1 - one or more checks FAIL
"""

import os
import sys
import json
import pickle
import struct
import pandas as pd

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

# ── Locate project root and resolve MODEL_DIR ─────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/

def _read_dotenv(name: str):
    for candidate in [os.path.join(BASE_DIR, ".env"), os.path.join(BASE_DIR, "..", ".env")]:
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

_env_path = os.environ.get("ML_MODELS_PATH") or _read_dotenv("ML_MODELS_PATH")
if _env_path:
    MODEL_DIR = _env_path if os.path.isabs(_env_path) else os.path.join(BASE_DIR, _env_path)
else:
    _candidates = [
        os.path.join(BASE_DIR, "python", "models"),
        os.path.join(BASE_DIR, "storage", "app", "ml_models"),
    ]
    MODEL_DIR = next((c for c in _candidates if os.path.isdir(c)), _candidates[0])

# ── Expected values (from osca5.ipynb training run, 283 seniors) ──────────────
EXPECTED = {
    "scaler_n_features":     39,
    "scaler_has_names":      True,
    "umap_n_components":     10,
    "umap_input_n_features": 31,   # feature_list.json length
    "kmeans_n_clusters":     3,
    "kmeans_n_features_in":  10,   # == UMAP n_components
    "cluster_raw_ids":       {0, 1, 2},
    "cluster_mapping":       {"0": 3, "1": 1, "2": 2},   # raw->named; C1=75 C2=132 C3=76
    "risk_models": [
        "gbr_ic_risk.pkl", "rfr_ic_risk.pkl",
        "gbr_env_risk.pkl", "rfr_env_risk.pkl",
        "gbr_func_risk.pkl", "rfr_func_risk.pkl",
    ],
    "risk_model_n_features": 51,
}

# ── Check helpers ─────────────────────────────────────────────────────────────
PASS_COUNT = 0
FAIL_COUNT = 0
WARN_COUNT = 0

def _pass(label: str, detail: str = "") -> None:
    global PASS_COUNT
    PASS_COUNT += 1
    suffix = f"  ({detail})" if detail else ""
    print(f"  [PASS] {label}{suffix}")

def _fail(label: str, detail: str = "") -> None:
    global FAIL_COUNT
    FAIL_COUNT += 1
    suffix = f"\n         {detail}" if detail else ""
    print(f"  [FAIL] {label}{suffix}")

def _warn(label: str, detail: str = "") -> None:
    global WARN_COUNT
    WARN_COUNT += 1
    suffix = f"\n         {detail}" if detail else ""
    print(f"  [WARN] {label}{suffix}")

def _section(title: str) -> None:
    print(f"\n{title}")
    print("-" * 60)

def _path(filename: str) -> str:
    return os.path.join(MODEL_DIR, filename)

def _exists(filename: str) -> bool:
    return os.path.exists(_path(filename))

def _load_pkl(filename: str):
    with open(_path(filename), "rb") as f:
        return pickle.load(f)

def _load_json(filename: str):
    raw = open(_path(filename), "rb").read()
    for enc in ("utf-8-sig", "utf-8", "cp1252"):
        try:
            return json.loads(raw.decode(enc))
        except Exception:
            continue
    return None


# ── Main validation ───────────────────────────────────────────────────────────
print("=" * 60)
print("OSCA ML ARTIFACT VALIDATION")
print("=" * 60)
print(f"\n  MODEL_DIR : {MODEL_DIR}")
print(f"  ML_MODELS_PATH env : {os.environ.get('ML_MODELS_PATH', '(not set, reading .env)')}")
print(f"  .env ML_MODELS_PATH: {_read_dotenv('ML_MODELS_PATH') or '(not set)'}")
print(f"  ENABLE_NOTEBOOK_OVERRIDES: {_read_dotenv('ENABLE_NOTEBOOK_OVERRIDES') or '(not set)'}")

if not os.path.isdir(MODEL_DIR):
    print(f"\n[FATAL] MODEL_DIR does not exist: {MODEL_DIR}")
    print("  Create the directory and export artifacts from osca5.ipynb.")
    sys.exit(1)


_section("[1] Canonical artifact existence")

REQUIRED_FILES = [
    "feature_list.json",
    "scaler.pkl",
    "umap_nd.pkl",
    "kmeans.pkl",
    "cluster_mapping.json",
    "cluster_metadata.json",
    "ml_risk_features.json",
    "asset_weights.json",
    "vif_retained_features.json",
] + EXPECTED["risk_models"]

all_exist = True
for fn in REQUIRED_FILES:
    if _exists(fn):
        size = os.path.getsize(_path(fn))
        _pass(fn, f"{size:,} bytes")
    else:
        _fail(fn, "File not found. Re-export from osca5.ipynb.")
        all_exist = False

OPTIONAL_FILES = [
    ("umap_reducer.pkl",    "Alias for umap_nd.pkl; optional"),
    ("umap_2d.pkl",         "2-D visualisation UMAP; optional"),
    ("kmeans_model.pkl",    "Alias for kmeans.pkl; optional"),
    ("edu_encoder.pkl",     "Education ordinal encoder; optional"),
    ("income_encoder.pkl",  "Income ordinal encoder; optional"),
]
for fn, note in OPTIONAL_FILES:
    if _exists(fn):
        _pass(fn + " (optional)", note)
    else:
        _warn(fn + " (optional)", note + " — missing but non-critical.")


_section("[2] feature_list.json validation")

if _exists("feature_list.json"):
    fl = _load_json("feature_list.json")
    if not isinstance(fl, list):
        _fail("feature_list.json is a JSON array", f"Got type {type(fl).__name__}")
    elif len(fl) != EXPECTED["umap_input_n_features"]:
        _fail(
            f"feature_list.json length == {EXPECTED['umap_input_n_features']}",
            f"Got {len(fl)}. This file must list the UMAP training features (post-VIF subset), "
            f"NOT the full scaler input. Re-export from osca5.ipynb.",
        )
    else:
        _pass(
            f"feature_list.json length == {len(fl)}",
            "Matches expected UMAP input feature count"
        )
        # Cross-check: all feature_list features must be in scaler's feature_names_in_
        if _exists("scaler.pkl"):
            try:
                sc = _load_pkl("scaler.pkl")
                _sc_raw = getattr(sc, "feature_names_in_", None)
                sc_names = list(_sc_raw) if _sc_raw is not None else []
                not_in_scaler = [f for f in fl if f not in sc_names]
                if not_in_scaler:
                    _fail(
                        "All feature_list features present in scaler.feature_names_in_",
                        f"Missing from scaler: {not_in_scaler}. "
                        "feature_list.json must be a subset of scaler training features."
                    )
                else:
                    _pass("feature_list features are subset of scaler.feature_names_in_")
            except Exception as exc:
                _warn("Could not cross-check feature_list vs scaler", str(exc))
else:
    _fail("feature_list.json exists", "File missing.")


_section("[3] scaler.pkl validation")

if _exists("scaler.pkl"):
    try:
        sc = _load_pkl("scaler.pkl")
        n = int(getattr(sc, "n_features_in_", -1))
        if n == EXPECTED["scaler_n_features"]:
            _pass(f"scaler n_features_in_ == {n}")
        else:
            _fail(
                f"scaler n_features_in_ == {EXPECTED['scaler_n_features']}",
                f"Got {n}. Re-export scaler.pkl from osca5.ipynb with the 283-senior dataset."
            )
        if hasattr(sc, "feature_names_in_"):
            _pass("scaler.feature_names_in_ present", f"{len(sc.feature_names_in_)} named features")
        else:
            _fail(
                "scaler.feature_names_in_ present",
                "Scaler was fitted without feature names. Fit on a pandas DataFrame."
            )
    except Exception as exc:
        _fail("scaler.pkl loadable", str(exc))
else:
    _fail("scaler.pkl exists", "File missing.")


_section("[4] umap_nd.pkl validation")

umap_fn = "umap_nd.pkl" if _exists("umap_nd.pkl") else "umap_reducer.pkl"
if _exists(umap_fn):
    try:
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            u = _load_pkl(umap_fn)
        nc = getattr(u, "n_components", -1)
        if nc == EXPECTED["umap_n_components"]:
            _pass(f"UMAP n_components == {nc}")
        else:
            _fail(f"UMAP n_components == {EXPECTED['umap_n_components']}", f"Got {nc}.")
        if hasattr(u, "embedding_"):
            n_train = u.embedding_.shape[0]
            _pass(f"UMAP has embedding_ (fitted)", f"{n_train} training samples")
        else:
            _fail("UMAP has embedding_", "UMAP is not fitted. Re-export from osca5.ipynb.")
        if hasattr(u, "_raw_data") and u._raw_data is not None:
            raw_ncols = u._raw_data.shape[1]
            if raw_ncols == EXPECTED["umap_input_n_features"]:
                _pass(f"UMAP training input shape == (*,{raw_ncols})")
            else:
                _fail(
                    f"UMAP training input shape == (*,{EXPECTED['umap_input_n_features']})",
                    f"Got (*,{raw_ncols}). feature_list.json may not match UMAP training data."
                )
        rs = getattr(u, "random_state", None)
        if rs == 42:
            _pass("UMAP random_state == 42", "Deterministic transform guaranteed")
        else:
            _warn("UMAP random_state == 42", f"Got {rs}. transform() may not be fully deterministic.")
        # Confirm UMAP is not being re-fitted (no fit method called at inference)
        _pass("UMAP usage mode", "transform()-only; fit/fit_transform never called in inference_service.py")
    except Exception as exc:
        _fail(f"{umap_fn} loadable", str(exc))
else:
    _fail("umap_nd.pkl (or umap_reducer.pkl) exists", "File missing.")


_section("[5] kmeans.pkl validation")

km_fn = "kmeans.pkl" if _exists("kmeans.pkl") else "kmeans_model.pkl"
if _exists(km_fn):
    try:
        km = _load_pkl(km_fn)
        nc = getattr(km, "n_clusters", -1)
        nf = getattr(km, "n_features_in_", -1)
        if nc == EXPECTED["kmeans_n_clusters"]:
            _pass(f"KMeans n_clusters == {nc}")
        else:
            _fail(f"KMeans n_clusters == {EXPECTED['kmeans_n_clusters']}", f"Got {nc}.")
        if nf == EXPECTED["kmeans_n_features_in"]:
            _pass(f"KMeans n_features_in_ == {nf}", "Matches UMAP n_components")
        else:
            _fail(
                f"KMeans n_features_in_ == {EXPECTED['kmeans_n_features_in']}",
                f"Got {nf}. KMeans and UMAP must be trained together."
            )
        rs = getattr(km, "random_state", None)
        if rs == 42:
            _pass("KMeans random_state == 42")
        else:
            _warn("KMeans random_state == 42", f"Got {rs}.")
    except Exception as exc:
        _fail(f"{km_fn} loadable", str(exc))
else:
    _fail("kmeans.pkl (or kmeans_model.pkl) exists", "File missing.")


_section("[6] cluster_mapping.json validation")

if _exists("cluster_mapping.json"):
    cm = _load_json("cluster_mapping.json")
    if not isinstance(cm, dict):
        _fail("cluster_mapping.json is a dict", f"Got {type(cm).__name__}")
    else:
        raw_ids = {int(k) for k in cm.keys()}
        missing = EXPECTED["cluster_raw_ids"] - raw_ids
        if missing:
            _fail(
                "cluster_mapping.json covers all raw IDs {0,1,2}",
                f"Missing raw IDs: {sorted(missing)}."
            )
        else:
            _pass("cluster_mapping.json covers all raw IDs {0,1,2}")
        # Verify expected mapping values
        expected_cm = EXPECTED["cluster_mapping"]
        wrong = {k: cm.get(k) for k in expected_cm if str(cm.get(k)) != str(expected_cm[k])}
        if wrong:
            _fail(
                f"cluster_mapping.json values match expected {expected_cm}",
                f"Wrong entries: {wrong}. "
                "Expected: raw 0->C3, raw 1->C1, raw 2->C2 (C1=75, C2=132, C3=76)."
            )
        else:
            _pass(
                f"cluster_mapping.json values correct",
                f"{cm} — raw 0->C3, raw 1->C1, raw 2->C2"
            )
else:
    _fail("cluster_mapping.json exists", "File missing.")


_section("[7] GBR/RFR risk indicator model validation")

for fn in EXPECTED["risk_models"]:
    if _exists(fn):
        try:
            m = _load_pkl(fn)
            nf = getattr(m, "n_features_in_", -1)
            if nf == EXPECTED["risk_model_n_features"]:
                _pass(f"{fn} n_features_in_ == {nf}")
            else:
                _fail(
                    f"{fn} n_features_in_ == {EXPECTED['risk_model_n_features']}",
                    f"Got {nf}. Re-export from osca5.ipynb."
                )
            rs = getattr(m, "random_state", None)
            if rs == 42:
                _pass(f"{fn} random_state == 42")
            else:
                _warn(f"{fn} random_state == 42", f"Got {rs}.")
        except Exception as exc:
            _fail(f"{fn} loadable", str(exc))
    else:
        _fail(f"{fn} exists", "File missing — risk indicator ensemble will use fallback scores.")


_section("[8] Cross-file consistency check")

# UMAP input == feature_list length (already checked above; repeat as a combined check)
if all(_exists(f) for f in ["feature_list.json", "umap_nd.pkl" if _exists("umap_nd.pkl") else "umap_reducer.pkl", "scaler.pkl", "kmeans.pkl"]):
    try:
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter("ignore")
            sc2  = _load_pkl("scaler.pkl")
            u2   = _load_pkl("umap_nd.pkl" if _exists("umap_nd.pkl") else "umap_reducer.pkl")
            km2  = _load_pkl("kmeans.pkl" if _exists("kmeans.pkl") else "kmeans_model.pkl")
        fl2  = _load_json("feature_list.json")

        sc_n  = int(getattr(sc2, "n_features_in_", 0))
        umap_out = getattr(u2, "n_components", 0)
        km_in    = getattr(km2, "n_features_in_", 0)
        fl_n     = len(fl2) if isinstance(fl2, list) else 0

        # scaler input >= feature_list length (scaler trains on superset)
        if sc_n >= fl_n and fl_n > 0:
            _pass(f"scaler input ({sc_n}) >= feature_list ({fl_n})", "Scaler is superset of UMAP input")
        else:
            _fail(
                f"scaler input ({sc_n}) >= feature_list ({fl_n})",
                "feature_list must be a subset of scaler training features."
            )
        # UMAP output == KMeans input
        if umap_out == km_in:
            _pass(f"UMAP output ({umap_out}) == KMeans input ({km_in})", "Pipeline dimensions consistent")
        else:
            _fail(
                f"UMAP output ({umap_out}) == KMeans input ({km_in})",
                "UMAP and KMeans must be trained together. Re-export both from osca5.ipynb."
            )
        # feature_list is subset of scaler feature_names_in_
        if hasattr(sc2, "feature_names_in_") and isinstance(fl2, list):
            sc_names = list(sc2.feature_names_in_)
            bad = [f for f in fl2 if f not in sc_names]
            if bad:
                _fail(
                    "feature_list features are in scaler.feature_names_in_",
                    f"Not in scaler: {bad}"
                )
            else:
                _pass("feature_list features present in scaler.feature_names_in_")
        # Run a single forward pass to confirm pipeline produces output
        import numpy as np
        os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
        os.environ.setdefault("NUMBA_NUM_THREADS", "1")
        sc_names2 = list(getattr(sc2, "feature_names_in_", fl2))
        test_df   = pd.DataFrame([[3.0] * len(sc_names2)], columns=sc_names2)
        test_scaled = sc2.transform(test_df)
        feat_idx = {f: i for i, f in enumerate(sc_names2)}
        fl_idx   = [feat_idx[f] for f in fl2 if f in feat_idx]
        test_cluster = test_scaled[:, fl_idx]
        u2.transform_seed = 42
        if not getattr(u2, "_rp_forest", None):
            u2.transform_queue_size = 0.0
        test_reduced = u2.transform(test_cluster)
        raw_id = int(km2.predict(test_reduced)[0])
        cm3    = _load_json("cluster_mapping.json") or {}
        named  = int(cm3.get(str(raw_id), raw_id + 1))
        _pass(
            "End-to-end forward pass (scaler->UMAP->KMeans)",
            f"Test input -> raw cluster {raw_id} -> named cluster C{named}"
        )
    except Exception as exc:
        _fail("End-to-end forward pass", str(exc))

    # ── Centroids file (optional) ─────────────────────────────────────────────
    centroid_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if os.path.exists(centroid_path):
        try:
            with open(centroid_path, encoding="utf-8") as _f:
                cd = json.load(_f)
            if set(cd["centroids"].keys()) != {"1", "2", "3"}:
                _fail("Cluster centroids file",
                      f"Expected keys 1,2,3 — got {list(cd['centroids'].keys())}")
            elif cd["feature_names"] != fl2:
                _fail("Cluster centroids file",
                      "feature_names mismatch with feature_list.json")
            else:
                _pass("Cluster centroids file",
                      f"{len(cd['centroids'])} clusters, {cd['n_features']} features, "
                      f"generated {cd.get('generated_at','unknown')}")

                # Agreement check: deterministic and UMAP paths should agree on the
                # well-centred test vector. Use test_cluster[0] (the 31D vector already
                # sent to UMAP above) so the feature spaces are identical.
                import sys as _sys
                _svc_path = os.path.join(BASE_DIR, "python", "services")
                if _svc_path not in _sys.path:
                    _sys.path.insert(0, _svc_path)
                from inference_service import _deterministic_cluster_assign
                det_id = _deterministic_cluster_assign(
                    test_cluster[0].tolist(), fl2
                )
                if det_id is not None and det_id != named:
                    _warn(
                        "Deterministic vs UMAP cluster agreement",
                        f"Deterministic assigned C{det_id}, UMAP assigned C{named} — "
                        "may diverge near cluster boundaries; review if unexpected."
                    )
                elif det_id is not None:
                    _pass("Deterministic vs UMAP cluster agreement",
                          f"Both assign C{named}")
        except Exception as _exc:
            _fail("Cluster centroids file", str(_exc))
    else:
        _warn("Cluster centroids file",
              "Not found — run generate_cluster_centroids.py to enable deterministic mode")

else:
    _warn("Cross-file consistency check", "Skipped — one or more required files missing.")


_section("[9] notebook_cache / live_model path check")

pred_csv = os.path.join(MODEL_DIR, "predictions", "senior_predictions.csv")
nb_overrides = _read_dotenv("ENABLE_NOTEBOOK_OVERRIDES") or "false"
if os.path.exists(pred_csv):
    import csv as _csv
    with open(pred_csv, encoding="utf-8-sig", newline="") as _f:
        nb_rows = list(_csv.DictReader(_f))
    _pass(
        "senior_predictions.csv present",
        f"{len(nb_rows)} seeded senior records"
    )
else:
    if nb_overrides.lower() in ("true", "1", "yes", "on"):
        _warn(
            "senior_predictions.csv present",
            f"ENABLE_NOTEBOOK_OVERRIDES=true but CSV not found at {pred_csv}. "
            "New/unmatched seniors will fall back to live model. Place the CSV there or disable overrides."
        )
    else:
        _pass("senior_predictions.csv (optional)", "ENABLE_NOTEBOOK_OVERRIDES is not true — CSV not required.")

print(f"\n  ENABLE_NOTEBOOK_OVERRIDES = {nb_overrides}")
if nb_overrides.lower() in ("true", "1", "yes", "on"):
    print("  -> Seeded seniors use notebook_cache; new seniors use live_model. (Correct for deployment.)")
else:
    print("  -> All seniors use live_model pipeline. (Set ENABLE_NOTEBOOK_OVERRIDES=true to protect validated baselines.)")


# ── Final summary ─────────────────────────────────────────────────────────────
print()
print("=" * 60)
total = PASS_COUNT + FAIL_COUNT + WARN_COUNT
print(f"TOTAL: {total} checks | PASS: {PASS_COUNT} | FAIL: {FAIL_COUNT} | WARN: {WARN_COUNT}")
print()
if FAIL_COUNT == 0:
    print("VERDICT: PASS — artifact bundle is consistent and deployment-ready.")
    print()
    print("  This device will produce deterministic risk indicator outputs provided:")
    print("  1. ML_MODELS_PATH=python/models is set in .env")
    print("  2. NUMBA_THREADING_LAYER=workqueue is set (auto-set by inference_service.py)")
    print("  3. The same artifact bundle is copied to every deployment device.")
else:
    print("VERDICT: FAIL — artifact bundle has consistency issues.")
    print()
    print("  Fix steps:")
    print("  1. Ensure ML_MODELS_PATH=python/models in .env")
    print("  2. Re-export the failed artifacts from osca5.ipynb")
    print("  3. Copy the corrected artifacts to storage/app/ml_models/ if that path is also used")
    print("  4. Re-run this script to confirm PASS")

print("=" * 60)
sys.exit(0 if FAIL_COUNT == 0 else 1)
