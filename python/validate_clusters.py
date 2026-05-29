"""
Cluster validation script — run from project root:
    python\venv\Scripts\python.exe python\validate_clusters.py

DEPRECATED (K=3 era): this diagnostic assumes the original 3-cluster model and
is superseded by python/scripts/validate_system.py, which validates the current
K=4 system end-to-end (feature engineering, risk, clustering, XAI, determinism).
Kept for historical reference; numbers/labels here reflect the K=3 build.
"""
import os, sys

os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(BASE_DIR, "services"))

# Load .env
env = {}
env_path = os.path.join(BASE_DIR, ".env")
if not os.path.exists(env_path):
    env_path = os.path.join(os.path.dirname(BASE_DIR), ".env")
for line in open(env_path, encoding="utf-8"):
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        k, _, v = line.partition("=")
        env[k.strip()] = v.strip().strip('"')

import pymysql
conn = pymysql.connect(
    host=env.get("DB_HOST", "127.0.0.1"),
    port=int(env.get("DB_PORT", 3306)),
    user=env.get("DB_USERNAME", "root"),
    password=env.get("DB_PASSWORD", ""),
    database=env.get("DB_DATABASE", "osca_db"),
    cursorclass=pymysql.cursors.DictCursor
)

# ── 1. Per-cluster semantic averages ─────────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT
            r.cluster_named_id,
            r.cluster_name,
            COUNT(*)                                          AS n,
            ROUND(AVG(r.overall_risk_level = 'high'), 3)     AS pct_high,
            ROUND(AVG(r.overall_risk_level = 'low'),  3)     AS pct_low,
            ROUND(AVG(r.composite_risk),  3)                 AS avg_risk,
            ROUND(AVG(r.wellbeing_score), 3)                 AS avg_wb,
            ROUND(AVG(r.ic_score),   3)                      AS avg_ic,
            ROUND(AVG(r.env_score),  3)                      AS avg_env,
            ROUND(AVG(r.func_score), 3)                      AS avg_func,
            ROUND(AVG(r.qol_score),  3)                      AS avg_qol
        FROM ml_results r
        INNER JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) latest ON r.id = latest.max_id
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        GROUP BY r.cluster_named_id, r.cluster_name
        ORDER BY r.cluster_named_id
    """)
    rows = cur.fetchall()

print("\n=== Cluster Semantic Validation ===")
print(f"{'C':<3} {'Name':<34} {'N':>4}  {'%HIGH':>6}  {'%LOW':>5}  {'Risk':>5}  {'WB':>5}  {'IC':>5}  {'ENV':>5}  {'FUNC':>5}  {'QoL':>5}")
print("-" * 110)

PASS = True
for r in rows:
    cid = r["cluster_named_id"]
    name = (r["cluster_name"] or "")[:33]
    print(
        f"  C{cid}  {name:<33}  {r['n']:>4}  "
        f"{r['pct_high']:>5.1%}  {r['pct_low']:>5.1%}  "
        f"{r['avg_risk']:>5.3f}  {r['avg_wb']:>5.3f}  "
        f"{r['avg_ic']:>5.2f}  {r['avg_env']:>5.2f}  {r['avg_func']:>5.2f}  {r['avg_qol']:>5.2f}"
    )

# Semantic checks
clusters = {r["cluster_named_id"]: r for r in rows}
c1, c2, c3 = clusters.get(1), clusters.get(2), clusters.get(3)

print("\n=== Semantic Checks ===")

def check(label, condition, detail=""):
    global PASS
    status = "PASS" if condition else "FAIL"
    if not condition:
        PASS = False
    print(f"  [{status}] {label}" + (f"  ({detail})" if detail else ""))

if c1 and c2 and c3:
    check("C1 wellbeing > C2 wellbeing",
          c1["avg_wb"] > c2["avg_wb"],
          f"C1={c1['avg_wb']:.3f}  C2={c2['avg_wb']:.3f}")
    check("C2 wellbeing > C3 wellbeing",
          c2["avg_wb"] > c3["avg_wb"],
          f"C2={c2['avg_wb']:.3f}  C3={c3['avg_wb']:.3f}")
    check("C1 composite risk < C2 composite risk",
          c1["avg_risk"] < c2["avg_risk"],
          f"C1={c1['avg_risk']:.3f}  C2={c2['avg_risk']:.3f}")
    check("C2 composite risk < C3 composite risk",
          c2["avg_risk"] < c3["avg_risk"],
          f"C2={c2['avg_risk']:.3f}  C3={c3['avg_risk']:.3f}")
    check("C1 %HIGH risk < C3 %HIGH risk",
          c1["pct_high"] < c3["pct_high"],
          f"C1={c1['pct_high']:.1%}  C3={c3['pct_high']:.1%}")
    check("C2 is the largest cluster",
          c2["n"] > c1["n"] and c2["n"] > c3["n"],
          f"C1={c1['n']}  C2={c2['n']}  C3={c3['n']}")
    check("C1 and C3 are similar size (ratio < 1.15)",
          max(c1["n"], c3["n"]) / max(min(c1["n"], c3["n"]), 1) < 1.15,
          f"C1={c1['n']}  C3={c3['n']}")
else:
    print("  [FAIL] Could not find all three clusters in ml_results")
    PASS = False

# ── 2. Risk × Cluster cross-tab ──────────────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT r.cluster_named_id, r.overall_risk_level, COUNT(*) AS n
        FROM ml_results r
        INNER JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) latest ON r.id = latest.max_id
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        GROUP BY r.cluster_named_id, r.overall_risk_level
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    cross = cur.fetchall()

print("\n=== Risk x Cluster Cross-tab ===")
print(f"  {'Cluster':>8}  {'Risk':>10}  {'Count':>6}")
print("  " + "-" * 30)
for r in cross:
    cid = r["cluster_named_id"]
    rl  = (r["overall_risk_level"] or "").lower()
    flag = ""
    if cid == 1 and rl == "high":
        flag = "  *** UNEXPECTED (HIGH in C1)"
        PASS = False
    if cid == 3 and rl == "low":
        flag = "  *** UNEXPECTED (LOW in C3)"
        PASS = False
    print(f"  C{cid:>1}        {r['overall_risk_level']:>10}  {r['n']:>6}{flag}")

# ── 3. Top 5 HIGH-risk seniors — spot check ──────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               r.cluster_named_id, r.overall_risk_level, r.priority_flag,
               ROUND(r.composite_risk, 3) AS risk,
               ROUND(r.wellbeing_score, 3) AS wb,
               r.cluster_name
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        INNER JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) latest ON r.id = latest.max_id
        WHERE r.overall_risk_level = 'high'
        ORDER BY r.composite_risk DESC
        LIMIT 5
    """)
    top_high = cur.fetchall()

print("\n=== Top 5 HIGH-risk seniors (spot check — expect C2 or C3) ===")
for r in top_high:
    cid  = r["cluster_named_id"]
    flag = "  *** UNEXPECTED (HIGH in C1)" if cid == 1 else ""
    print(f"  C{cid} {r['overall_risk_level'].upper():>8}  risk={r['risk']:.3f}  wb={r['wb']:.3f}  "
          f"{r['first_name']} {r['last_name']} ({r['barangay']}){flag}")

# ── 4. Top 5 LOW-risk seniors ─────────────────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               r.cluster_named_id, r.overall_risk_level,
               ROUND(r.composite_risk, 3) AS risk,
               ROUND(r.wellbeing_score, 3) AS wb
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        INNER JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) latest ON r.id = latest.max_id
        WHERE r.overall_risk_level = 'low'
        ORDER BY r.composite_risk ASC
        LIMIT 5
    """)
    top_low = cur.fetchall()

print("\n=== Top 5 LOW-risk seniors (spot check — expect C1) ===")
for r in top_low:
    cid  = r["cluster_named_id"]
    flag = "  *** UNEXPECTED (LOW in C3)" if cid == 3 else ""
    print(f"  C{cid} {r['overall_risk_level'].upper():>8}  risk={r['risk']:.3f}  wb={r['wb']:.3f}  "
          f"{r['first_name']} {r['last_name']} ({r['barangay']}){flag}")

conn.close()

print("\n" + "=" * 50)
print("Overall result:", "ALL CHECKS PASSED" if PASS else "ONE OR MORE CHECKS FAILED")
print("=" * 50 + "\n")
