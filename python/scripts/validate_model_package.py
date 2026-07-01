"""
validate_model_package.py
==========================
Validates the locked K=4 + KNN model package against the MinMaxScaler-30-feature
specification. Checks that every artifact is correctly typed, sized, and internally
consistent with the cluster_assignment_knn_k5.pkl deployment model.

SAFE: Read-only. Does not modify any file or database record.

Usage (from osca-system/ project root):
    python/venv/Scripts/python.exe python/scripts/validate_model_package.py

Exit codes:
    0  — all checks PASS (warnings do not fail the run)
    1  — one or more checks FAIL
"""

import json
import os
import pickle
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))


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

# ── Expected package specification ────────────────────────────────────────────
EXPECTED_SCALER_CLASS     = "MinMaxScaler"
EXPECTED_N_FEATURES       = 30
EXPECTED_KMEANS_K         = 4
EXPECTED_UMAP_N_COMPONENTS = 10
EXPECTED_KNN_LABEL_SET    = {1, 2, 3, 4}
_reports_primary  = os.path.join(BASE_DIR, "osca_output", "reports")
_reports_fallback = os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "reports"))
# Prefer whichever directory actually contains the key report file
_swdoc = "scoring_weights_documentation.json"
REPORTS_DIR = (
    _reports_primary
    if os.path.isfile(os.path.join(_reports_primary, _swdoc))
    else _reports_fallback
)

# ── Helpers ───────────────────────────────────────────────────────────────────
PASS_COUNT = 0
FAIL_COUNT = 0
WARN_COUNT = 0


def _pass(label: str, detail: str = "") -> None:
    global PASS_COUNT
    PASS_COUNT += 1
    print(f"  [PASS] {label}" + (f"  ({detail})" if detail else ""))


def _fail(label: str, detail: str = "") -> None:
    global FAIL_COUNT
    FAIL_COUNT += 1
    print(f"  [FAIL] {label}" + (f"\n         {detail}" if detail else ""))


def _warn(label: str, detail: str = "") -> None:
    global WARN_COUNT
    WARN_COUNT += 1
    print(f"  [WARN] {label}" + (f"\n         {detail}" if detail else ""))


def _section(title: str) -> None:
    print(f"\n{title}")
    print("-" * 60)


def _p(filename: str) -> str:
    return os.path.join(MODEL_DIR, filename)


def _exists(filename: str) -> bool:
    return os.path.exists(_p(filename))


def _load_pkl(filename: str):
    with open(_p(filename), "rb") as f:
        return pickle.load(f)


def _load_json(filename: str, basedir: str = MODEL_DIR):
    raw = open(os.path.join(basedir, filename), "rb").read()
    for enc in ("utf-8-sig", "utf-8", "cp1252"):
        try:
            return json.loads(raw.decode(enc))
        except Exception:
            continue
    return None


# ── Main checks ───────────────────────────────────────────────────────────────
print("=" * 60)
print("OSCA MODEL PACKAGE VALIDATION (K=4 + KNN MinMaxScaler-30)")
print("=" * 60)
print(f"\n  MODEL_DIR : {MODEL_DIR}")

if not os.path.isdir(MODEL_DIR):
    print(f"\n[ERROR] MODEL_DIR does not exist: {MODEL_DIR}")
    sys.exit(1)


# ── 1. scaler.pkl ─────────────────────────────────────────────────────────────
_section("1. scaler.pkl — must be MinMaxScaler, 30 features, with feature names")
if not _exists("scaler.pkl"):
    _fail("scaler.pkl", "file not found in MODEL_DIR")
else:
    try:
        sc = _load_pkl("scaler.pkl")
        sc_class = type(sc).__name__
        if sc_class == EXPECTED_SCALER_CLASS:
            _pass("scaler class", sc_class)
        else:
            _fail("scaler class",
                  f"expected {EXPECTED_SCALER_CLASS}, got {sc_class}. "
                  "Re-run osca5.ipynb (cell 37 now assigns scaler=sc_final).")
        n = int(getattr(sc, "n_features_in_", -1))
        if n == EXPECTED_N_FEATURES:
            _pass("scaler n_features_in_", str(n))
        else:
            _fail("scaler n_features_in_",
                  f"expected {EXPECTED_N_FEATURES}, got {n}. "
                  "Ensure cell 37 fits on df_imputed[ablated_feats] (not .values).")
        if hasattr(sc, "feature_names_in_"):
            names = list(sc.feature_names_in_)
            _pass("scaler has feature_names_in_", f"{len(names)} names present")
        else:
            _fail("scaler feature_names_in_ missing",
                  "scaler was fitted on .values (no column names). "
                  "Cell 37 must fit on a DataFrame, not a numpy array.")
    except Exception as exc:
        _fail("scaler.pkl loadable", str(exc))


# ── 2. kmeans.pkl ─────────────────────────────────────────────────────────────
_section("2. kmeans.pkl — must be K=4")
if not _exists("kmeans.pkl"):
    _fail("kmeans.pkl", "file not found")
else:
    try:
        km = _load_pkl("kmeans.pkl")
        k = int(getattr(km, "n_clusters", -1))
        if k == EXPECTED_KMEANS_K:
            _pass("kmeans n_clusters", str(k))
        else:
            _fail("kmeans n_clusters", f"expected {EXPECTED_KMEANS_K}, got {k}")
        n_init = int(getattr(km, "n_init", -1))
        if n_init >= 100:
            _pass("kmeans n_init", str(n_init))
        else:
            _warn("kmeans n_init", f"got {n_init}; expected >=100 for stability")
    except Exception as exc:
        _fail("kmeans.pkl loadable", str(exc))


# ── 3. umap_nd.pkl ────────────────────────────────────────────────────────────
_section("3. umap_nd.pkl — must have n_components=10")
if not _exists("umap_nd.pkl"):
    _fail("umap_nd.pkl", "file not found")
else:
    try:
        u = _load_pkl("umap_nd.pkl")
        nc = int(getattr(u, "n_components", -1))
        nn = int(getattr(u, "n_neighbors", -1))
        if nc == EXPECTED_UMAP_N_COMPONENTS:
            _pass("umap n_components", str(nc))
        else:
            _fail("umap n_components", f"expected {EXPECTED_UMAP_N_COMPONENTS}, got {nc}")
        if nn == 10:
            _pass("umap n_neighbors", str(nn))
        else:
            _warn("umap n_neighbors", f"expected 10 (final config), got {nn}")
    except Exception as exc:
        _fail("umap_nd.pkl loadable", str(exc))


# ── 4. cluster_assignment_knn_k5.pkl ─────────────────────────────────────────
_section("4. cluster_assignment_knn_k5.pkl — KNN k=5, 30-feature, labels 1-4")
KNN_PKL = "cluster_assignment_knn_k5.pkl"
if not _exists(KNN_PKL):
    _fail(KNN_PKL, "file not found — run: python/scripts/generate_knn_classifier.py")
else:
    try:
        knn = _load_pkl(KNN_PKL)
        knn_class = type(knn).__name__
        if "KNeighbors" in knn_class:
            _pass("knn class", knn_class)
        else:
            _fail("knn class", f"expected KNeighborsClassifier, got {knn_class}")
        k_val = int(getattr(knn, "n_neighbors", -1))
        if k_val == 5:
            _pass("knn n_neighbors", str(k_val))
        else:
            _fail("knn n_neighbors", f"expected 5, got {k_val}")
        knn_classes = set(int(c) for c in getattr(knn, "classes_", []))
        if knn_classes == EXPECTED_KNN_LABEL_SET:
            _pass("knn classes_ (predicts named IDs)", str(sorted(knn_classes)))
        else:
            _fail("knn classes_",
                  f"got {sorted(knn_classes)}; expected {sorted(EXPECTED_KNN_LABEL_SET)} "
                  f"(KNN must predict named cluster IDs 1-4, not raw KMeans labels 0-3)")
        knn_feats = getattr(knn, "_osca_feature_names", None)
        if knn_feats is not None:
            if len(knn_feats) == EXPECTED_N_FEATURES:
                _pass("knn _osca_feature_names length", str(len(knn_feats)))
            else:
                _fail("knn _osca_feature_names length",
                      f"expected {EXPECTED_N_FEATURES}, got {len(knn_feats)}")
        else:
            _fail("knn _osca_feature_names", "attribute missing — alignment check disabled")
        import numpy as np
        dummy = np.zeros((1, EXPECTED_N_FEATURES))
        try:
            pred = knn.predict(dummy)
            label = int(pred[0])
            if label in EXPECTED_KNN_LABEL_SET:
                _pass("knn predict output label", f"{label} in {sorted(EXPECTED_KNN_LABEL_SET)}")
            else:
                _fail("knn predict label range",
                      f"predicted {label}; expected one of {sorted(EXPECTED_KNN_LABEL_SET)}")
        except Exception as pe:
            _fail("knn predict callable", str(pe))
    except Exception as exc:
        _fail(f"{KNN_PKL} loadable", str(exc))


# ── 5. feature_list.json ─────────────────────────────────────────────────────
_section("5. feature_list.json — must have exactly 30 features")
if not _exists("feature_list.json"):
    _fail("feature_list.json", "file not found")
else:
    fl = _load_json("feature_list.json")
    if isinstance(fl, list):
        if len(fl) == EXPECTED_N_FEATURES:
            _pass("feature_list.json length", str(len(fl)))
        else:
            _fail("feature_list.json length",
                  f"expected {EXPECTED_N_FEATURES}, got {len(fl)}")
        # Cross-check with KNN feature names
        if _exists(KNN_PKL):
            try:
                knn2 = _load_pkl(KNN_PKL)
                knn_feats2 = getattr(knn2, "_osca_feature_names", None)
                if knn_feats2 is not None and knn_feats2 == fl:
                    _pass("feature_list.json matches knn._osca_feature_names")
                elif knn_feats2 is not None:
                    _fail("feature_list.json / knn feature mismatch",
                          "Model and feature list are from different runs.")
            except Exception:
                pass
    else:
        _fail("feature_list.json format", "expected a JSON array")


# ── 6. final_feature_list.json ────────────────────────────────────────────────
_section("6. final_feature_list.json — count=30, no '31-feature' prose")
if not _exists("final_feature_list.json"):
    _fail("final_feature_list.json", "file not found")
else:
    ffl = _load_json("final_feature_list.json")
    if isinstance(ffl, dict):
        count = ffl.get("final_clustering_feature_count", -1)
        if count == EXPECTED_N_FEATURES:
            _pass("final_clustering_feature_count", str(count))
        else:
            _fail("final_clustering_feature_count",
                  f"expected {EXPECTED_N_FEATURES}, got {count}")
        note = str(ffl.get("note", ""))
        decision = str(ffl.get("decision", ""))
        if "31-feature" in note or "31-feature" in decision:
            _fail("no '31-feature' prose in final_feature_list.json",
                  "note or decision field still says '31-feature'. "
                  "Re-run osca5.ipynb cell 37.")
        else:
            _pass("no stale '31-feature' prose in note/decision")
        sig_count = ffl.get("significant_features_count")
        if isinstance(sig_count, int) and sig_count > 0:
            _pass("significant_features_count present", str(sig_count))
        else:
            _warn("significant_features_count", f"got {sig_count!r}; should be Kruskal sig count")
    else:
        _fail("final_feature_list.json format", "expected a JSON object")


# ── 7. scoring_weights_documentation.json ─────────────────────────────────────
_section("7. scoring_weights_documentation.json — MinMaxScaler, nn=10, n_init=100, 4-level thresholds")
swdoc_path = os.path.join(REPORTS_DIR, "scoring_weights_documentation.json")
if not os.path.exists(swdoc_path):
    _warn("scoring_weights_documentation.json", f"not found at {swdoc_path}")
else:
    try:
        raw = open(swdoc_path, "rb").read()
        swdoc = json.loads(raw.decode("utf-8-sig"))
        scaler_name = swdoc.get("scaler", "")
        if scaler_name == EXPECTED_SCALER_CLASS:
            _pass("scoring_weights scaler", scaler_name)
        else:
            _fail("scoring_weights scaler",
                  f"expected '{EXPECTED_SCALER_CLASS}', got '{scaler_name}'")
        umap_nd = (swdoc.get("umap_params") or {}).get("nd") or {}
        nn_val = umap_nd.get("n_neighbors")
        if nn_val == 10:
            _pass("scoring_weights umap_nd n_neighbors", str(nn_val))
        else:
            _fail("scoring_weights umap_nd n_neighbors",
                  f"expected 10, got {nn_val!r}")
        metric_val = umap_nd.get("metric")
        if metric_val == "euclidean":
            _pass("scoring_weights umap_nd metric", metric_val)
        else:
            _fail("scoring_weights umap_nd metric", f"expected 'euclidean', got {metric_val!r}")
        km_doc = swdoc.get("kmeans") or {}
        n_init_val = km_doc.get("n_init")
        if n_init_val == 100:
            _pass("scoring_weights kmeans n_init", str(n_init_val))
        else:
            _fail("scoring_weights kmeans n_init", f"expected 100, got {n_init_val!r}")
        thresholds = swdoc.get("risk_level_thresholds") or {}
        expected_bands = {"LOW", "MODERATE", "HIGH", "CRITICAL"}
        missing = expected_bands - set(thresholds.keys())
        if missing:
            _fail("risk_level_thresholds 4-level",
                  f"missing bands: {missing}; expected LOW/MODERATE/HIGH/CRITICAL (Option A)")
        else:
            _pass("risk_level_thresholds 4-level (LOW/MODERATE/HIGH/CRITICAL)")
        feat_count = swdoc.get("final_clustering_feature_count")
        if feat_count == EXPECTED_N_FEATURES:
            _pass("scoring_weights final_clustering_feature_count", str(feat_count))
        else:
            _fail("scoring_weights final_clustering_feature_count",
                  f"expected {EXPECTED_N_FEATURES}, got {feat_count!r}")
    except Exception as exc:
        _fail("scoring_weights_documentation.json loadable", str(exc))


# ── 8. cluster_assignment_metadata.json ───────────────────────────────────────
_section("8. cluster_assignment_metadata.json — k=5, scaler=MinMaxScaler, n_features=30")
if not _exists("cluster_assignment_metadata.json"):
    _warn("cluster_assignment_metadata.json",
          "not found — generated by osca5.ipynb (KNN cell) or generate_knn_classifier.py")
else:
    meta = _load_json("cluster_assignment_metadata.json")
    if isinstance(meta, dict):
        if meta.get("k") == 5:
            _pass("metadata k", str(meta["k"]))
        else:
            _fail("metadata k", f"expected 5, got {meta.get('k')!r}")
        if meta.get("scaler") == EXPECTED_SCALER_CLASS:
            _pass("metadata scaler", meta["scaler"])
        else:
            _fail("metadata scaler",
                  f"expected '{EXPECTED_SCALER_CLASS}', got {meta.get('scaler')!r}")
        if meta.get("n_features") == EXPECTED_N_FEATURES:
            _pass("metadata n_features", str(meta["n_features"]))
        else:
            _fail("metadata n_features",
                  f"expected {EXPECTED_N_FEATURES}, got {meta.get('n_features')!r}")
        cv_acc = meta.get("cv_accuracy")
        if isinstance(cv_acc, (int, float)) and cv_acc >= 0.90:
            _pass("metadata cv_accuracy", f"{cv_acc:.4f}")
        else:
            _warn("metadata cv_accuracy",
                  f"{cv_acc!r} — expected >= 0.90; retrain if accuracy is below threshold")
    else:
        _fail("cluster_assignment_metadata.json format", "expected a JSON object")


# ── Summary ───────────────────────────────────────────────────────────────────
print(f"\n{'=' * 60}")
print(f"RESULT: {PASS_COUNT} PASS  |  {WARN_COUNT} WARN  |  {FAIL_COUNT} FAIL")
if FAIL_COUNT == 0:
    print("STATUS: PACKAGE LOCKED  — all checks passed.")
    if WARN_COUNT:
        print("        (review warnings above before production deploy)")
else:
    print("STATUS: NOT LOCKED  — fix FAILs, re-run osca5.ipynb, redeploy artifacts.")
    print("        Runbook: https://github.com/somarjez/osca-agesense (see DEPLOY.md)")
print("=" * 60)

sys.exit(0 if FAIL_COUNT == 0 else 1)
