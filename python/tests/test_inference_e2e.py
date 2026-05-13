"""End-to-end infer() test on two real seniors from the DB.
Checks: notebook override applied, urgency correct, composite matches CSV.
"""
import os, sys, sqlite3, json

os.environ["ML_MODELS_PATH"] = r"C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models"
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "true"
os.environ["OSCA_BATCH_MODE"] = "1"
os.environ["NUMBA_THREADING_LAYER"] = "workqueue"
os.environ["NUMBA_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"

sys.path.insert(0, os.path.dirname(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'app'))

DB_PATH = r"C:\Users\jramo\AppData\Local\OSCA-System\database.sqlite"

conn = sqlite3.connect(DB_PATH)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

# Load known composites from CSV for comparison
import csv
csv_composites = {}
csv_path = r"C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models\predictions\senior_predictions.csv"
with open(csv_path, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        key = (row["first_name"].strip(), row["last_name"].strip())
        csv_composites[key] = {
            "composite_risk": float(row["composite_risk"]),
            "risk_level": row["risk_level"].strip().upper(),
            "cluster_id": int(float(row["cluster_id"])),
        }

# Test seniors: Norlito Basa (urgent) + one MODERATE
test_seniors = [
    ("Norlito", "Basa"),    # urgent, composite ~0.744
    ("Rosa", "Amante"),     # MODERATE, first in CSV
]

from preprocess_service import preprocess
from inference_service import infer

all_ok = True
for first, last in test_seniors:
    cur.execute("""
        SELECT sc.*, qs.id as qol_id
        FROM senior_citizens sc
        JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
        WHERE sc.first_name=? AND sc.last_name=? AND qs.status='processed'
        ORDER BY qs.id DESC LIMIT 1
    """, (first, last))
    row = cur.fetchone()
    if not row:
        print(f"[SKIP] {first} {last} not found in DB")
        continue

    # Build a minimal raw payload (fields the preprocess service needs)
    cur.execute("SELECT * FROM qol_surveys WHERE id=?", (row["qol_id"],))
    qol = cur.fetchone()

    raw = {
        "first_name": row["first_name"],
        "last_name": row["last_name"],
        "barangay": row["barangay"],
        "age": row["age"],
        "gender": row["gender"],
        "marital_status": row["marital_status"],
        "educational_attainment": row["educational_attainment"],
        "monthly_income_range": row["monthly_income_range"],
        "num_children": row["num_children"] or 0,
        "num_working_children": row["num_working_children"] or 0,
        "household_size": row["household_size"] or 1,
        "child_financial_support": row["child_financial_support"],
        "spouse_working": row["spouse_working"],
        "income_source": json.loads(row["income_source"] or "[]"),
        "real_assets": json.loads(row["real_assets"] or "[]"),
        "movable_assets": json.loads(row["movable_assets"] or "[]"),
        "living_with": json.loads(row["living_with"] or "[]"),
        "household_condition": json.loads(row["household_condition"] or "[]"),
        "community_service": json.loads(row["community_service"] or "[]"),
        "specialization": json.loads(row["specialization"] or "[]"),
        "medical_concern": json.loads(row["medical_concern"] or "[]"),
        "dental_concern": json.loads(row["dental_concern"] or "[]"),
        "optical_concern": json.loads(row["optical_concern"] or "[]"),
        "hearing_concern": json.loads(row["hearing_concern"] or "[]"),
        "social_emotional_concern": json.loads(row["social_emotional_concern"] or "[]"),
        "healthcare_difficulty": json.loads(row["healthcare_difficulty"] or "[]"),
        "has_medical_checkup": bool(row["has_medical_checkup"]),
        "qol_responses": {},  # preprocess will handle missing gracefully
    }

    try:
        preprocessed = preprocess(raw)
        result = infer(preprocessed)
    except Exception as e:
        print(f"[FAIL] {first} {last}: exception: {e}")
        all_ok = False
        continue

    csv_ref = csv_composites.get((first, last), {})
    got_composite = result["risk_scores"]["composite_risk"]
    got_level = result["risk_levels"]["overall"]
    got_pflag = result["priority_flag"]
    got_urgency = set(r["urgency"] for r in result["recommendations"])
    override_applied = result["model_metadata"]["notebook_override_applied"]

    exp_composite = csv_ref.get("composite_risk", None)
    exp_level = csv_ref.get("risk_level", "").replace("CRITICAL", "HIGH")

    checks = [
        ("override_applied", override_applied, True),
        ("risk_level", got_level, exp_level),
        ("composite_close", abs(got_composite - exp_composite) < 0.001 if exp_composite else True, True),
    ]
    # Urgency: urgent senior should have urgent/immediate recs; others should NOT have urgent
    if got_pflag == "urgent":
        checks.append(("recs_are_urgent", bool(got_urgency & {"urgent", "immediate"}), True))
        checks.append(("no_planned_on_urgent", "planned" not in got_urgency, True))
    else:
        checks.append(("recs_not_urgent", "urgent" not in got_urgency, True))
        checks.append(("recs_not_immediate", "immediate" not in got_urgency, True))

    senior_ok = True
    for name, actual, expected in checks:
        ok = actual == expected
        if not ok:
            senior_ok = False
            all_ok = False
        print(f"  [{'OK' if ok else 'FAIL'}] {name}: {actual!r} (expected {expected!r})")

    print(f"\n{'OK' if senior_ok else 'FAIL'} {first} {last}: composite={got_composite:.4f}, level={got_level}, pflag={got_pflag}, urgency_set={got_urgency}")
    print()

conn.close()
print("ALL CHECKS PASSED" if all_ok else "SOME CHECKS FAILED")
sys.exit(0 if all_ok else 1)
