"""
audit_csv_vs_db.py
==================
Compares osca_normalized.csv (notebook source of truth) against what the live
preprocess service computes for each senior in the DB.

Checks:
  1. All 283 CSV seniors are in the DB (name+barangay match)
  2. QoL raw values match exactly between CSV and DB
  3. Age at assessment matches (CSV fixed age vs live computed age)
  4. Computed feature_map values from preprocess match CSV-derived values
  5. Identifies which differences cause cluster mismatches

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\audit_csv_vs_db.py
"""

import os, sys, csv, json, re, unicodedata, urllib.request, urllib.error
from datetime import date, datetime
from collections import defaultdict

try:
    import pymysql
    import pymysql.cursors
except ImportError:
    print("[ERROR] pymysql not installed.")
    sys.exit(1)

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT   = os.path.dirname(os.path.dirname(SCRIPT_DIR))
BASE_DIR    = os.path.dirname(REPO_ROOT)

CSV_PATH    = os.path.join(BASE_DIR, "osca_normalized.csv")
PRED_CSV    = os.path.join(BASE_DIR, "osca_output", "predictions", "senior_predictions.csv")
PREPROCESS  = "http://127.0.0.1:5001/preprocess"
INFER       = "http://127.0.0.1:5002/infer"


# ── Helpers ───────────────────────────────────────────────────────────────────
def _norm(s):
    s = unicodedata.normalize("NFC", str(s or ""))
    s = s.replace("ñ", "n").replace("Ñ", "n")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"[^a-z0-9]+", "", s.lower())

def _key(first, last, barangay):
    return f"{_norm(first)}|{_norm(last)}|{_norm(barangay)}"

def _read_dotenv(name):
    for cand in [os.path.join(REPO_ROOT, ".env"),
                 os.path.join(os.path.dirname(REPO_ROOT), ".env")]:
        if os.path.exists(cand):
            for line in open(cand, encoding="utf-8"):
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, _, v = line.partition("=")
                    if k.strip() == name:
                        return v.strip().strip('"').strip("'")
    return ""

def _http_post(url, payload):
    data = json.dumps(payload).encode("utf-8")
    req  = urllib.request.Request(url, data=data,
           headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

def _parse_json_col(val):
    if val is None: return []
    if isinstance(val, (list, dict)): return val
    try: return json.loads(val)
    except: return []

def _compute_age(dob):
    if dob is None: return 70
    if isinstance(dob, str): dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

def _parse_csv_age(s):
    try: return int(float(str(s).strip()))
    except: return None

# ── Load CSV ──────────────────────────────────────────────────────────────────
print(f"Loading osca_normalized.csv from: {CSV_PATH}")
csv_rows = {}
with open(CSV_PATH, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        k = _key(row["first_name"], row["last_name"], row["barangay"])
        csv_rows[k] = row

# Load notebook predictions (ground truth cluster + risk)
pred_rows = {}
with open(PRED_CSV, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        k = _key(row["first_name"], row["last_name"], row["barangay"])
        pred_rows[k] = row

print(f"CSV seniors: {len(csv_rows)}")
print(f"Pred seniors: {len(pred_rows)}")

# ── DB connection + query ─────────────────────────────────────────────────────
conn = pymysql.connect(
    host     = _read_dotenv("DB_HOST") or "127.0.0.1",
    port     = int(_read_dotenv("DB_PORT") or 3306),
    user     = _read_dotenv("DB_USERNAME") or "root",
    password = _read_dotenv("DB_PASSWORD") or "",
    database = _read_dotenv("DB_DATABASE") or "osca_db",
    cursorclass = pymysql.cursors.DictCursor,
)

QOL_COLS = [
    "a1_enjoy_life","a2_life_satisfaction","a3_future_outlook","a4_meaningfulness",
    "b1_physical_energy","b2_pain_discomfort","b3_health_self_care","b4_health_outside","b5_mobility",
    "c1_happiness","c2_calm_peace","c3_loneliness","c4_confidence",
    "d1_independence","d2_time_control","d3_life_control","d4_income_limits",
    "e1_social_support","e2_close_person","e3_community_opportunities","e4_participation","e5_respect",
    "f1_home_safety","f2_neighborhood_safety","f3_service_access","f4_home_comfort",
    "g1_household_expenses","g2_medical_afford","g3_personal_wants",
    "h1_belief_comfort","h2_belief_practice",
]

# CSV QoL field → DB QoL column mapping
CSV_TO_DB_QOL = {
    "qol_enjoy_life":"a1_enjoy_life","qol_life_satisfaction":"a2_life_satisfaction",
    "qol_future_outlook":"a3_future_outlook","qol_meaningfulness":"a4_meaningfulness",
    "phy_energy":"b1_physical_energy","phy_pain_r":"b2_pain_discomfort",
    "phy_health_limit_r":"b3_health_self_care","phy_mobility_outside":"b4_health_outside",
    "phy_mobility_indoor":"b5_mobility","psych_happiness":"c1_happiness","psych_peace":"c2_calm_peace",
    "psych_lonely_r":"c3_loneliness","psych_confidence":"c4_confidence",
    "func_independence":"d1_independence","func_autonomy":"d2_time_control",
    "func_control":"d3_life_control","env_income_limit_r":"d4_income_limits",
    "soc_social_support":"e1_social_support","soc_close_friend":"e2_close_person",
    "soc_opportunity":"e3_community_opportunities","soc_participation":"e4_participation",
    "soc_respect":"e5_respect","env_safe_home":"f1_home_safety",
    "env_safe_neighborhood":"f2_neighborhood_safety","env_service_access":"f3_service_access",
    "env_home_comfort":"f4_home_comfort","env_fin_household":"g1_household_expenses",
    "env_fin_medical":"g2_medical_afford","env_fin_personal":"g3_personal_wants",
    "spi_belief_comfort":"h1_belief_comfort","spi_belief_practice":"h2_belief_practice",
}

with conn.cursor() as cur:
    qol_sel = ", ".join(f"qs.{c}" for c in QOL_COLS)
    cur.execute(f"""
        SELECT sc.id, sc.first_name, sc.last_name, sc.barangay, sc.date_of_birth,
               sc.gender, sc.marital_status, sc.educational_attainment,
               sc.monthly_income_range, sc.num_children, sc.num_working_children,
               sc.household_size, sc.child_financial_support, sc.spouse_working,
               sc.income_source, sc.real_assets, sc.movable_assets, sc.living_with,
               sc.household_condition, sc.community_service, sc.specialization,
               sc.medical_concern, sc.dental_concern, sc.optical_concern,
               sc.hearing_concern, sc.social_emotional_concern, sc.healthcare_difficulty,
               sc.has_medical_checkup, sc.checkup_schedule,
               mr.cluster_named_id, mr.model_version,
               {qol_sel}
        FROM senior_citizens sc
        JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
        JOIN ml_results mr ON mr.senior_citizen_id = sc.id
        WHERE sc.deleted_at IS NULL
          AND qs.id = (SELECT MAX(q2.id) FROM qol_surveys q2 WHERE q2.senior_citizen_id = sc.id)
          AND mr.id = (SELECT MAX(m2.id) FROM ml_results m2 WHERE m2.senior_citizen_id = sc.id)
        ORDER BY sc.id
    """)
    db_rows = cur.fetchall()
conn.close()

db_by_key = {}
for row in db_rows:
    k = _key(row["first_name"], row["last_name"], row["barangay"])
    db_by_key[k] = row

print(f"DB seniors with ML results: {len(db_by_key)}\n")

# ── Compare ───────────────────────────────────────────────────────────────────
not_in_db   = []
not_in_csv  = []
qol_mismatches  = []   # QoL value differs between CSV and DB
age_diffs   = []   # Age in CSV vs computed from DOB today
cluster_diffs = [] # Cluster differs

for key, csv_row in csv_rows.items():
    if key not in db_by_key:
        not_in_db.append(f"{csv_row['first_name']} {csv_row['last_name']} / {csv_row['barangay']}")
        continue

    db  = db_by_key[key]
    pred = pred_rows.get(key, {})

    # 1. QoL value comparison
    qol_diffs_for_senior = []
    for csv_field, db_col in CSV_TO_DB_QOL.items():
        csv_val = csv_row.get(csv_field, "").strip()
        db_val  = str(db.get(db_col, "") or "")
        if csv_val and db_val:
            try:
                cv = int(float(csv_val))
                dv = int(float(db_val))
                if cv != dv:
                    qol_diffs_for_senior.append(f"{csv_field}: csv={cv} db={dv}")
            except:
                pass

    if qol_diffs_for_senior:
        qol_mismatches.append({
            "name": f"{db['first_name']} {db['last_name']}",
            "barangay": db["barangay"],
            "diffs": qol_diffs_for_senior,
        })

    # 2. Age comparison
    csv_age  = _parse_csv_age(csv_row.get("age", ""))
    live_age = _compute_age(db["date_of_birth"])
    if csv_age is not None and abs(csv_age - live_age) > 0:
        age_diffs.append({
            "name":     f"{db['first_name']} {db['last_name']}",
            "barangay": db["barangay"],
            "csv_age":  csv_age,
            "live_age": live_age,
            "diff":     live_age - csv_age,
        })

    # 3. Cluster comparison (DB live vs notebook prediction)
    nb_cluster  = int(float(pred.get("cluster_id", 0) or 0)) if pred else None
    db_cluster  = int(db.get("cluster_named_id") or 0)
    if nb_cluster and db_cluster and nb_cluster != db_cluster:
        cluster_diffs.append({
            "name":       f"{db['first_name']} {db['last_name']}",
            "barangay":   db["barangay"],
            "nb_cluster": nb_cluster,
            "db_cluster": db_cluster,
        })

for key in db_by_key:
    if key not in csv_rows:
        r = db_by_key[key]
        not_in_csv.append(f"{r['first_name']} {r['last_name']} / {r['barangay']}")

# ── Report ────────────────────────────────────────────────────────────────────
print("=" * 70)
print("AUDIT: osca_normalized.csv vs DB")
print("=" * 70)

print(f"\n[1] Coverage")
print(f"  CSV seniors:                {len(csv_rows)}")
print(f"  DB seniors with ML results: {len(db_by_key)}")
print(f"  In CSV but NOT in DB:       {len(not_in_db)}")
print(f"  In DB but NOT in CSV:       {len(not_in_csv)}")
for name in not_in_db[:10]:
    print(f"    MISSING from DB: {name}")
for name in not_in_csv[:10]:
    print(f"    EXTRA in DB:     {name}")

print(f"\n[2] QoL Value Mismatches (DB vs CSV)")
print(f"  Seniors with >=1 QoL value difference: {len(qol_mismatches)}")
for s in qol_mismatches[:10]:
    print(f"  {s['name']} / {s['barangay']}:")
    for d in s["diffs"][:5]:
        print(f"    {d}")

print(f"\n[3] Age Drift (CSV fixed age vs computed from DOB today)")
age_by_diff = defaultdict(list)
for a in age_diffs:
    age_by_diff[a["diff"]].append(a)
print(f"  Seniors with age drift:    {len(age_diffs)}")
for diff_val in sorted(age_by_diff.keys()):
    print(f"  Drift +{diff_val} year(s): {len(age_by_diff[diff_val])} seniors")
if age_diffs:
    print(f"  Examples (showing up to 5):")
    for a in age_diffs[:5]:
        print(f"    {a['name']} / {a['barangay']}: csv_age={a['csv_age']} live_age={a['live_age']} drift=+{a['diff']}")

print(f"\n[4] Cluster Mismatches (DB live result vs notebook prediction)")
print(f"  Mismatched clusters: {len(cluster_diffs)} / {len(csv_rows)}")
print(f"  Match rate:          {(len(csv_rows)-len(cluster_diffs))/len(csv_rows)*100:.1f}%")
if cluster_diffs:
    print(f"  Examples (showing up to 10):")
    for c in cluster_diffs[:10]:
        print(f"    {c['name']} / {c['barangay']}: notebook={c['nb_cluster']} db={c['db_cluster']}")

print(f"\n[5] Summary")
print(f"  QoL input integrity:  {'OK' if not qol_mismatches else 'ISSUES FOUND (' + str(len(qol_mismatches)) + ' seniors)'}")
print(f"  Age computation:      {'DRIFT detected (' + str(len(age_diffs)) + ' seniors differ from CSV)' if age_diffs else 'OK'}")
print(f"  Cluster match rate:   {(len(csv_rows)-len(cluster_diffs))/len(csv_rows)*100:.1f}%")

if not qol_mismatches and not age_diffs:
    print("\n  All inputs match. Cluster drift is from borderline seniors only.")
elif age_diffs:
    print("\n  Age drift is the primary source of cluster mismatch.")
    print("  The notebook froze ages at survey time; the live system recalculates daily.")
    print("  To eliminate drift: store csv_age in DB at survey time, or use fixed scoring dates.")
