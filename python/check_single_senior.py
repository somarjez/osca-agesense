"""
Run full inference on one senior and print all intermediate scores.
Useful to compare against notebook output for the same senior.

Run: python\venv\Scripts\python.exe python\check_single_senior.py
"""
import os, sys, json, logging
logging.disable(logging.CRITICAL)

os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(BASE_DIR, "services"))

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

from inference_service import _load_model, _dual_predict, _load_json, _notebook_ml_score, _clip01

# Check if GBR/RFR models are loading
print("\n=== Model load check ===")
for name in ["gbr_ic_risk.pkl", "rfr_ic_risk.pkl", "gbr_env_risk.pkl",
             "rfr_env_risk.pkl", "gbr_func_risk.pkl", "rfr_func_risk.pkl"]:
    m = _load_model(name)
    status = "LOADED" if m is not None else "MISSING"
    print(f"  {name:<30} {status}")

ml_risk_features = _load_json("ml_risk_features.json")
print(f"\n  ml_risk_features.json: {len(ml_risk_features) if ml_risk_features else 'MISSING'} features")

# Pull 3 of the highest-composite-risk seniors from DB and re-run a quick score check
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.id, sc.first_name, sc.last_name,
               r.composite_risk, r.ic_risk, r.env_risk, r.func_risk,
               r.overall_risk_level, r.cluster_named_id,
               r.wellbeing_score, r.ic_score, r.env_score, r.func_score
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
        INNER JOIN (SELECT senior_citizen_id, MAX(id) AS max_id FROM ml_results GROUP BY senior_citizen_id) lat ON r.id=lat.max_id
        WHERE sc.deleted_at IS NULL
        ORDER BY r.composite_risk DESC
        LIMIT 5
    """)
    top_risk = cur.fetchall()

    cur.execute("""
        SELECT sc.id, sc.first_name, sc.last_name,
               r.composite_risk, r.ic_risk, r.env_risk, r.func_risk,
               r.overall_risk_level, r.cluster_named_id,
               r.wellbeing_score, r.ic_score, r.env_score, r.func_score
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
        INNER JOIN (SELECT senior_citizen_id, MAX(id) AS max_id FROM ml_results GROUP BY senior_citizen_id) lat ON r.id=lat.max_id
        WHERE sc.deleted_at IS NULL
        ORDER BY r.composite_risk ASC
        LIMIT 5
    """)
    low_risk = cur.fetchall()

print("\n=== Top 5 highest composite risk seniors ===")
print(f"  {'Name':<30} {'C':>2}  {'Risk':>6}  {'IC':>5}  {'ENV':>5}  {'FUNC':>5}  {'Level':>8}  {'WB':>5}  {'ICs':>4}  {'Es':>4}  {'Fs':>4}")
print("  " + "-" * 100)
for r in top_risk:
    # Recompute composite from stored domain scores to verify formula
    ic_s  = float(r["ic_score"]  or 3.0)
    env_s = float(r["env_score"] or 3.0)
    fs    = float(r["func_score"]or 3.0)
    rule_ic   = _clip01(1 - (ic_s  - 1) / 4)
    rule_env  = _clip01(1 - (env_s - 1) / 4)
    rule_func = _clip01(1 - (fs    - 1) / 4)
    ml_comp   = rule_ic * 0.35 + rule_env * 0.35 + rule_func * 0.30
    ic_r   = float(r["ic_risk"]  or 0)
    env_r  = float(r["env_risk"] or 0)
    func_r = float(r["func_risk"]or 0)
    ml_comp_stored = ic_r * 0.35 + env_r * 0.35 + func_r * 0.30
    comp_stored = float(r["composite_risk"] or 0)
    name = f"{r['first_name']} {r['last_name']}"
    print(f"  {name:<30} C{r['cluster_named_id']:>1}  {comp_stored:>6.3f}  {ic_r:>5.3f}  {env_r:>5.3f}  {func_r:>5.3f}  "
          f"{r['overall_risk_level']:>8}  {float(r['wellbeing_score'] or 0):>5.3f}  "
          f"{ic_s:>4.1f}  {env_s:>4.1f}  {fs:>4.1f}")

print("\n=== Top 5 lowest composite risk seniors ===")
print(f"  {'Name':<30} {'C':>2}  {'Risk':>6}  {'IC':>5}  {'ENV':>5}  {'FUNC':>5}  {'Level':>8}  {'WB':>5}")
print("  " + "-" * 85)
for r in low_risk:
    name = f"{r['first_name']} {r['last_name']}"
    ic_r   = float(r["ic_risk"]  or 0)
    env_r  = float(r["env_risk"] or 0)
    func_r = float(r["func_risk"]or 0)
    print(f"  {name:<30} C{r['cluster_named_id']:>1}  {float(r['composite_risk'] or 0):>6.3f}  "
          f"{ic_r:>5.3f}  {env_r:>5.3f}  {func_r:>5.3f}  "
          f"{r['overall_risk_level']:>8}  {float(r['wellbeing_score'] or 0):>5.3f}")

# Distribution check: how many near each threshold
with conn.cursor() as cur:
    cur.execute("""
        SELECT
            SUM(composite_risk >= 0.50) as high_50,
            SUM(composite_risk >= 0.45) as high_45,
            SUM(composite_risk >= 0.40) as high_40,
            SUM(composite_risk < 0.30)  as low_30,
            SUM(composite_risk < 0.25)  as low_25,
            COUNT(*) as total
        FROM ml_results r
        INNER JOIN (SELECT senior_citizen_id, MAX(id) AS max_id FROM ml_results GROUP BY senior_citizen_id) lat ON r.id=lat.max_id
        INNER JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
        WHERE sc.deleted_at IS NULL
    """)
    dist = cur.fetchone()

print(f"\n=== Threshold sensitivity (active {dist['total']} seniors) ===")
print(f"  HIGH if >= 0.40: {dist['high_40']}  (very conservative)")
print(f"  HIGH if >= 0.45: {dist['high_45']}  (current system threshold — matches notebook)")
print(f"  HIGH if >= 0.50: {dist['high_50']}  (stricter threshold)")
print(f"  LOW  if <  0.25: {dist['low_25']}  (conservative)")
print(f"  LOW  if <  0.30: {dist['low_30']}  (current system)")
print(f"\n  Notebook reference: HIGH=54, MODERATE=191, LOW=38 (in-sample, threshold >= 0.45)")
print(f"  System current:    HIGH={dist['high_45']} at >= 0.45 (on {dist['total']} active seniors)")

conn.close()
