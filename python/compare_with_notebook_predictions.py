"""
Compare live system risk output against notebook senior_predictions.csv.
Run from project root:
    python\venv\Scripts\python.exe python\compare_with_notebook_predictions.py
"""
import os, sys, csv
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

env = {}
for candidate in [os.path.join(BASE_DIR, ".env"), os.path.join(os.path.dirname(BASE_DIR), ".env")]:
    if os.path.exists(candidate):
        for line in open(candidate, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip().strip('"')
        break

import pymysql
conn = pymysql.connect(
    host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", 3306)),
    user=env.get("DB_USERNAME", "root"), password=env.get("DB_PASSWORD", ""),
    database=env.get("DB_DATABASE", "osca_db"), cursorclass=pymysql.cursors.DictCursor,
)

# ── Load notebook predictions CSV ────────────────────────────────────────────
# osca_output sits one level above the osca-system project directory
project_root = os.path.dirname(BASE_DIR)
nb_path = os.path.join(project_root, "osca_output", "predictions", "senior_predictions.csv")
if not os.path.exists(nb_path):
    # try two levels up (if running from inside osca-system/osca-system)
    nb_path = os.path.join(os.path.dirname(project_root), "osca_output", "predictions", "senior_predictions.csv")
if not os.path.exists(nb_path):
    print(f"[ERROR] Not found: {nb_path}")
    sys.exit(1)

nb_by_name = {}
with open(nb_path, encoding="utf-8-sig") as f:
    reader = csv.DictReader(f)
    print(f"Notebook CSV columns: {reader.fieldnames}")
    for row in reader:
        # normalise name key
        first = (row.get("first_name") or row.get("First Name") or "").strip().lower()
        last  = (row.get("last_name")  or row.get("Last Name")  or "").strip().lower()
        key   = f"{first}|{last}"
        nb_by_name[key] = row

print(f"\nNotebook CSV: {len(nb_by_name)} seniors loaded")

# ── Load system results ───────────────────────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               r.overall_risk_level, r.cluster_named_id,
               ROUND(r.composite_risk, 4) AS sys_risk,
               ROUND(r.wellbeing_score, 4) AS sys_wb
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) lat ON r.id = lat.max_id
        ORDER BY sc.last_name, sc.first_name
    """)
    sys_rows = cur.fetchall()

print(f"System DB: {len(sys_rows)} active seniors")

# ── Match and compare ─────────────────────────────────────────────────────────
matched = 0
agree   = 0
disagree_rows = []
nb_high_sys_lower = 0   # notebook=HIGH, system=MODERATE or LOW
nb_risk_col = None

# detect which column has risk level
sample = next(iter(nb_by_name.values())) if nb_by_name else {}
for candidate in ["risk_level", "Risk Level", "overall_risk_level", "predicted_risk_level"]:
    if candidate in sample:
        nb_risk_col = candidate
        break

nb_cluster_col = None
for candidate in ["cluster_id", "cluster_named_id", "Cluster", "cluster"]:
    if candidate in sample:
        nb_cluster_col = candidate
        break

nb_composite_col = None
for candidate in ["composite_risk", "predicted_overall_healthy_ageing_risk"]:
    if candidate in sample:
        nb_composite_col = candidate
        break

print(f"\nNotebook columns used: risk={nb_risk_col}  cluster={nb_cluster_col}  composite={nb_composite_col}")

for sys_row in sys_rows:
    first = sys_row["first_name"].strip().lower()
    last  = sys_row["last_name"].strip().lower()
    key   = f"{first}|{last}"
    nb    = nb_by_name.get(key)
    if nb is None:
        continue
    matched += 1
    sys_level = (sys_row["overall_risk_level"] or "").upper()
    nb_level  = (nb.get(nb_risk_col) or "").upper()
    # Collapse CRITICAL → HIGH for comparison
    if nb_level == "CRITICAL":
        nb_level = "HIGH"

    if sys_level == nb_level:
        agree += 1
    else:
        disagree_rows.append({
            "name":      f"{sys_row['first_name']} {sys_row['last_name']}",
            "sys_level": sys_level,
            "nb_level":  nb_level,
            "sys_risk":  sys_row["sys_risk"],
            "nb_risk":   nb.get(nb_composite_col, "?"),
            "sys_c":     sys_row["cluster_named_id"],
            "nb_c":      nb.get(nb_cluster_col, "?"),
        })
        if nb_level == "HIGH" and sys_level != "HIGH":
            nb_high_sys_lower += 1

print(f"\n=== Match results ({matched} seniors matched by name) ===")
print(f"  Agreement (same risk level):  {agree}/{matched}  ({100*agree/max(matched,1):.1f}%)")
print(f"  Disagreements:                {len(disagree_rows)}")
print(f"  Notebook=HIGH but system not: {nb_high_sys_lower}  (seniors notebook flagged as HIGH that system rates lower)")

if disagree_rows:
    print(f"\n=== Disagreements (notebook vs system) ===")
    print(f"  {'Name':<30}  {'NB':>8}  {'SYS':>8}  {'NB_risk':>8}  {'SYS_risk':>8}  {'NB_C':>5}  {'SYS_C':>5}")
    print("  " + "-" * 80)
    for d in sorted(disagree_rows, key=lambda x: x["nb_level"], reverse=True):
        print(f"  {d['name']:<30}  {d['nb_level']:>8}  {d['sys_level']:>8}  "
              f"{str(d['nb_risk']):>8}  {d['sys_risk']:>8}  {str(d['nb_c']):>5}  {d['sys_c']:>5}")

# ── Distribution comparison ───────────────────────────────────────────────────
if nb_risk_col:
    nb_dist = {}
    for row in nb_by_name.values():
        lv = (row.get(nb_risk_col) or "").upper()
        if lv == "CRITICAL": lv = "HIGH"
        nb_dist[lv] = nb_dist.get(lv, 0) + 1

    with conn.cursor() as cur:
        cur.execute("""
            SELECT r.overall_risk_level, COUNT(*) AS n
            FROM ml_results r
            JOIN senior_citizens sc ON sc.id = r.senior_citizen_id AND sc.deleted_at IS NULL
            JOIN (SELECT senior_citizen_id, MAX(id) AS max_id FROM ml_results GROUP BY senior_citizen_id) lat ON r.id=lat.max_id
            GROUP BY r.overall_risk_level
        """)
        sys_dist = {(row["overall_risk_level"] or "").upper(): row["n"] for row in cur.fetchall()}

    print(f"\n=== Distribution comparison ===")
    print(f"  {'Level':<12}  {'Notebook':>10}  {'System':>10}  {'Diff':>6}")
    print("  " + "-" * 44)
    for lv in ["HIGH", "MODERATE", "LOW"]:
        nb_n  = nb_dist.get(lv, 0)
        sys_n = sys_dist.get(lv, 0)
        print(f"  {lv:<12}  {nb_n:>10}  {sys_n:>10}  {sys_n-nb_n:>+6}")

conn.close()
