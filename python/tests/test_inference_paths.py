"""
Validates inference_service internals: urgency logic, priority_flag thresholds,
model/JSON file resolution, and notebook-override flag state.
"""
import os
import sys

os.environ["ML_MODELS_PATH"] = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "models")
)
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "false"
os.environ["ENABLE_DETERMINISTIC_CLUSTER"] = "false"
os.environ["NUMBA_THREADING_LAYER"] = "workqueue"
os.environ["NUMBA_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

from inference_service import (
    ENABLE_NOTEBOOK_OVERRIDES, MODEL_DIR,
    _recommendation_urgency, _priority_flag,
    _load_model, _load_json,
)

all_ok = True


def check(label, actual, expected):
    global all_ok
    ok = actual == expected
    if not ok:
        all_ok = False
    print(f"  [{'OK' if ok else 'FAIL'}] {label}: {actual!r}  (expected {expected!r})")
    return ok


print("=== Configuration ===")
print(f"  ENABLE_NOTEBOOK_OVERRIDES: {ENABLE_NOTEBOOK_OVERRIDES}  (expected False)")
check("overrides disabled", ENABLE_NOTEBOOK_OVERRIDES, False)
print(f"  MODEL_DIR: {MODEL_DIR}")
print()

print("=== Model files present ===")
for fname in [
    "scaler.pkl", "umap_nd.pkl", "kmeans.pkl",
    "gbr_ic_risk.pkl", "gbr_env_risk.pkl", "gbr_func_risk.pkl",
    "rfr_ic_risk.pkl", "rfr_env_risk.pkl", "rfr_func_risk.pkl",
    "edu_encoder.pkl", "income_encoder.pkl",
    "feature_list.json", "cluster_mapping.json", "ml_risk_features.json",
]:
    path = os.path.join(MODEL_DIR, fname)
    exists = os.path.exists(path)
    if not exists:
        all_ok = False
    print(f"  [{'OK' if exists else 'MISSING'}] {fname}")
print()

print("=== _recommendation_urgency ===")
cases = [
    ("HIGH",     "urgent",            "urgent"),
    ("HIGH",     "priority_action",   "planned"),
    ("HIGH",     "planned_monitoring","planned"),
    ("MODERATE", "",                  "planned"),
    ("LOW",      "",                  "maintenance"),
]
for level, pflag, expected in cases:
    result = _recommendation_urgency(level, pflag)
    check(f"urgency({level!r}, {pflag!r})", result, expected)
print()

print("=== _priority_flag thresholds ===")
thresholds = [
    (0.80, "urgent"),
    (0.70, "urgent"),
    (0.699, "priority_action"),
    (0.50,  "priority_action"),
    (0.499, "planned_monitoring"),
    (0.30,  "planned_monitoring"),
    (0.299, "maintenance"),
    (0.00,  "maintenance"),
]
for score, expected in thresholds:
    check(f"priority_flag({score})", _priority_flag(score), expected)
print()

print("=== Models load correctly ===")
scaler = _load_model("scaler.pkl")
check("scaler loads", scaler is not None, True)
kmeans = _load_model("kmeans.pkl")
check("kmeans loads", kmeans is not None, True)
feature_list = _load_json("feature_list.json")
check("feature_list.json is list", isinstance(feature_list, list), True)
if isinstance(feature_list, list):
    check("feature_list has entries", len(feature_list) > 0, True)
cluster_map = _load_json("cluster_mapping.json")
check("cluster_mapping.json loads", isinstance(cluster_map, dict), True)
ml_features = _load_json("ml_risk_features.json")
check("ml_risk_features.json is list", isinstance(ml_features, list), True)

print()

print("=== Python DB cache removed ===")
import inspect
import inference_service as _inf_svc   # module ref (already in sys.modules)

# Confirm that _db_cache_lookup, _db_cache_write, _db_connect are gone
check("_db_cache_lookup removed", hasattr(_inf_svc, "_db_cache_lookup"), False)
check("_db_cache_write removed",  hasattr(_inf_svc, "_db_cache_write"),  False)
check("_db_connect removed",      hasattr(_inf_svc, "_db_connect"),      False)

# Confirm no _db_cached references remain in infer()
infer_src = inspect.getsource(_inf_svc.infer)
check("_db_cached absent from infer()", "_db_cached" in infer_src, False)
check("prediction_source is live_model or notebook_cache only",
      "db_cache_hit" not in infer_src, True)
print()

print("=== ENABLE_DETERMINISTIC_CLUSTER flag ===")
from inference_service import ENABLE_DETERMINISTIC_CLUSTER, _deterministic_cluster_assign, _load_cluster_centroids_scaled
check("flag defaults to False", ENABLE_DETERMINISTIC_CLUSTER, False)
print()

print("=== _load_cluster_centroids_scaled (file exists) ===")
# cluster_centroids_scaled.json exists — function should return the data dict
result = _load_cluster_centroids_scaled()
check("returns dict when centroids file exists", result is not None, True)
if result:
    check("dict has 'centroids' key", "centroids" in result, True)
    check("dict has 'feature_names' key", "feature_names" in result, True)
print()

print("=== _deterministic_cluster_assign (file exists) ===")
dummy_vector = [3.0] * 31
dummy_names  = [f"feat_{i}" for i in range(31)]
result = _deterministic_cluster_assign(dummy_vector, dummy_names)
check("returns valid cluster ID when centroids file exists",
      result in [1, 2, 3], True)
print()

print("=" * 50)
print("ALL CHECKS PASSED" if all_ok else "SOME CHECKS FAILED")
sys.exit(0 if all_ok else 1)
