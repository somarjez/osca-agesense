"""Quick validation script — run from python/services/ with the venv active."""
import os, sys

os.environ["ML_MODELS_PATH"] = r"C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models"
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "true"
os.environ["NUMBA_THREADING_LAYER"] = "workqueue"
os.environ["NUMBA_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"

sys.path.insert(0, os.path.dirname(__file__))

from inference_service import (
    ENABLE_NOTEBOOK_OVERRIDES, MODEL_DIR,
    NOTEBOOK_PREDICTIONS_CANDIDATES, NOTEBOOK_RECOMMENDATIONS_CANDIDATES,
    _resolve_notebook_predictions_path, _resolve_notebook_recommendations_path,
    _load_notebook_cluster_index, _recommendation_urgency, _priority_flag,
)

print("=== Path validation ===")
print("ENABLE_NOTEBOOK_OVERRIDES:", ENABLE_NOTEBOOK_OVERRIDES)
print("MODEL_DIR:", MODEL_DIR)
print()
print("Predictions candidates:")
for p in NOTEBOOK_PREDICTIONS_CANDIDATES:
    exists = "EXISTS" if os.path.exists(p) else "MISSING"
    print(f"  [{exists}] {p}")
print()
pred_path = _resolve_notebook_predictions_path()
print("Resolved predictions path:", pred_path)
print("  -> exists:", os.path.exists(pred_path))
print()
rec_path = _resolve_notebook_recommendations_path()
print("Resolved recommendations path:", rec_path)
print("  -> exists:", os.path.exists(rec_path))

print()
print("=== Notebook index load ===")
idx = _load_notebook_cluster_index()
print("full index size:", len(idx["full"]))
print("name_barangay size:", len(idx["name_barangay"]))

print()
print("=== Urgency logic validation ===")
cases = [
    ("HIGH", "urgent",           "expected: urgent"),
    ("HIGH", "priority_action",  "expected: planned"),
    ("HIGH", "planned_monitoring","expected: planned"),
    ("MODERATE", "",             "expected: planned"),
    ("LOW", "",                  "expected: maintenance"),
]
all_ok = True
for level, pflag, note in cases:
    result = _recommendation_urgency(level, pflag)
    expected = note.split(": ")[1]
    ok = result == expected
    status = "OK" if ok else "FAIL"
    if not ok:
        all_ok = False
    print(f"  [{status}] _recommendation_urgency({level!r}, {pflag!r}) = {result!r}  ({note})")

print()
print("=== priority_flag thresholds ===")
for score, expected in [(0.80, "urgent"), (0.70, "urgent"), (0.69, "priority_action"),
                        (0.50, "priority_action"), (0.49, "planned_monitoring"),
                        (0.30, "planned_monitoring"), (0.29, "maintenance")]:
    result = _priority_flag(score)
    ok = result == expected
    status = "OK" if ok else "FAIL"
    if not ok:
        all_ok = False
    print(f"  [{status}] composite={score} -> {result!r}  (expected {expected!r})")

print()
if all_ok:
    print("ALL CHECKS PASSED")
else:
    print("SOME CHECKS FAILED")
    sys.exit(1)
