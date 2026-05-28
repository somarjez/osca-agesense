"""
validate_csv_db_parity.py
=========================
Directly compares raw CSV field values against DB field values for all
283 training seniors — without any preprocessing.

This answers: "Is the DB data different from the CSV that trained the model?"

Fields compared: the multiselect fields most likely to differ between
CSV comma-strings and DB JSON arrays.

Run from repo root:
    python\venv\Scripts\python.exe python\scripts\validate_csv_db_parity.py
"""

import os, sys, json, csv
import pymysql
from typing import Any

BASE_DIR   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO_ROOT  = os.path.dirname(BASE_DIR)
OSCA_CSV   = os.path.join(REPO_ROOT, "osca.csv")
# Also try sibling directory
if not os.path.exists(OSCA_CSV):
    OSCA_CSV = os.path.join(os.path.dirname(REPO_ROOT), "osca.csv")

# ── Load .env ─────────────────────────────────────────────────────────────
env = {}
for cand in [os.path.join(BASE_DIR, ".env"), os.path.join(REPO_ROOT, ".env")]:
    if os.path.exists(cand):
        for line in open(cand, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip().strip('"').strip("'")
        break

conn = pymysql.connect(
    host=env.get("DB_HOST","127.0.0.1"), port=int(env.get("DB_PORT",3306)),
    user=env.get("DB_USERNAME","root"), password=env.get("DB_PASSWORD",""),
    database=env.get("DB_DATABASE","osca_db"), cursorclass=pymysql.cursors.DictCursor,
)

# ── Load CSV ───────────────────────────────────────────────────────────────
if not os.path.exists(OSCA_CSV):
    print(f"[ERROR] osca.csv not found at {OSCA_CSV}")
    sys.exit(1)

with open(OSCA_CSV, encoding="utf-8-sig") as f:
    csv_rows = {
        f"{r['first_name'].strip().lower()}|{r['last_name'].strip().lower()}": r
        for r in csv.DictReader(f)
    }
print(f"CSV seniors: {len(csv_rows)}")

# ── Load DB ────────────────────────────────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name,
               sc.household_condition, sc.specialization,
               sc.social_emotional_concern, sc.medical_concern,
               sc.income_source, sc.real_assets, sc.movable_assets,
               sc.living_with, sc.community_service,
               sc.educational_attainment, sc.monthly_income_range,
               sc.has_medical_checkup, sc.checkup_schedule
        FROM senior_citizens sc
        WHERE sc.deleted_at IS NULL
        ORDER BY sc.last_name, sc.first_name
    """)
    db_rows = {
        f"{r['first_name'].strip().lower()}|{r['last_name'].strip().lower()}": r
        for r in cur.fetchall()
    }
conn.close()
print(f"DB seniors: {len(db_rows)}")

# ── Helpers ────────────────────────────────────────────────────────────────
def normalise_set(val: Any) -> set:
    """Convert either a JSON array or a comma-string to a normalised set."""
    if val is None:
        return set()
    if isinstance(val, (list, tuple)):
        return {str(v).strip().lower() for v in val if str(v).strip()}
    s = str(val).strip()
    # Try JSON first
    if s.startswith("["):
        try:
            parsed = json.loads(s)
            return {str(v).strip().lower() for v in parsed if str(v).strip()}
        except Exception:
            pass
    # Comma-delimited fallback
    return {p.strip().lower() for p in s.split(",") if p.strip()}

MULTISELECT_FIELDS = [
    ("household_condition",      "household_condition"),
    ("specialization",           "specialization"),
    ("social_emotional_concern", "social_emotional_concern"),
    ("medical_concern",          "medical_concern"),
    ("income_source",            "income_source"),
    ("real_assets",              "real_assets"),
    ("movable_assets",           "movable_assets"),
    ("living_with",              "living_with"),
    ("community_service",        "community_service"),
]

TEXT_FIELDS = [
    ("educational_attainment", "educational_attainment",  "education"),
    ("monthly_income_range",   "monthly_income_range",    "monthly_income_range"),
]

def normalise_bool(val: Any) -> bool:
    """yes/1/true -> True, no/0/''/false/None -> False"""
    if val is None:
        return False
    s = str(val).strip().lower()
    return s in ("yes", "1", "true")

BOOL_FIELDS = [
    ("has_medical_checkup", "has_medical_checkup", "has_medical_checkup"),
]

# ── Compare ────────────────────────────────────────────────────────────────
matched_keys = set(csv_rows.keys()) & set(db_rows.keys())
print(f"Matched seniors (CSV + DB): {len(matched_keys)}\n")

field_mismatch_counts = {}
senior_mismatches = []

for key in sorted(matched_keys):
    csv_r = csv_rows[key]
    db_r  = db_rows[key]
    diffs = []

    # Multiselect: compare as sets
    for db_field, csv_field in MULTISELECT_FIELDS:
        csv_val = csv_r.get(csv_field, "")
        db_val  = db_r.get(db_field, "")
        csv_set = normalise_set(csv_val)
        db_set  = normalise_set(db_val)
        if csv_set != db_set:
            only_csv = csv_set - db_set
            only_db  = db_set - csv_set
            diffs.append(f"  {db_field}: CSV={sorted(only_csv)} | DB_extra={sorted(only_db)}")
            field_mismatch_counts[db_field] = field_mismatch_counts.get(db_field, 0) + 1

    # Text fields: simple string compare
    for db_field, db_col, csv_col in TEXT_FIELDS:
        csv_val = str(csv_r.get(csv_col, "") or "").strip().lower()
        db_val  = str(db_r.get(db_col,  "") or "").strip().lower()
        if csv_val != db_val:
            diffs.append(f"  {db_field}: CSV={csv_val!r} | DB={db_val!r}")
            field_mismatch_counts[db_field] = field_mismatch_counts.get(db_field, 0) + 1

    # Boolean fields: normalise yes/1/true
    for db_field, db_col, csv_col in BOOL_FIELDS:
        csv_val = normalise_bool(csv_r.get(csv_col))
        db_val  = normalise_bool(db_r.get(db_col))
        if csv_val != db_val:
            diffs.append(f"  {db_field}: CSV={csv_val} | DB={db_val}")
            field_mismatch_counts[db_field] = field_mismatch_counts.get(db_field, 0) + 1

    if diffs:
        name = " ".join(p.capitalize() for p in key.replace("|"," ").split())
        senior_mismatches.append((name, diffs))

# ── Report ─────────────────────────────────────────────────────────────────
print("=" * 70)
print(f"FIELD-LEVEL MISMATCH COUNTS  ({len(matched_keys)} seniors compared)")
print("=" * 70)
for field, count in sorted(field_mismatch_counts.items(), key=lambda x: -x[1]):
    pct = count / len(matched_keys) * 100
    print(f"  {field:<35} {count:>4} seniors  ({pct:.1f}%)")

print(f"\n  Total seniors with ANY mismatch: {len(senior_mismatches)}")
print(f"  Total seniors fully matching:    {len(matched_keys) - len(senior_mismatches)}")

print("\n" + "=" * 70)
print("SENIOR-LEVEL DETAILS  (first 20 shown)")
print("=" * 70)
for name, diffs in senior_mismatches[:20]:
    print(f"\n  {name}")
    for d in diffs:
        print(d)

if len(senior_mismatches) > 20:
    print(f"\n  ... and {len(senior_mismatches) - 20} more seniors with mismatches.")

# Save full report
out_path = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        "audit_output", "csv_db_parity_report.txt")
with open(out_path, "w", encoding="utf-8") as f:
    f.write(f"CSV seniors: {len(csv_rows)}\n")
    f.write(f"DB seniors: {len(db_rows)}\n")
    f.write(f"Matched: {len(matched_keys)}\n\n")
    f.write("FIELD MISMATCH COUNTS\n")
    for field, count in sorted(field_mismatch_counts.items(), key=lambda x: -x[1]):
        f.write(f"  {field:<35} {count}\n")
    f.write(f"\nSeniors with ANY mismatch: {len(senior_mismatches)}\n\n")
    for name, diffs in senior_mismatches:
        f.write(f"\n{name}\n")
        for d in diffs:
            f.write(d + "\n")
print(f"\nFull report saved to: {out_path}")
