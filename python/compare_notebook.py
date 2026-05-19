"""
Compare system risk distribution for the 283 notebook seniors only.
Run: python\venv\Scripts\python.exe python\compare_notebook.py
"""
import os, sys
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(BASE_DIR, "services"))

env = {}
for candidate in [
    os.path.join(BASE_DIR, ".env"),
    os.path.join(os.path.dirname(BASE_DIR), ".env"),
]:
    if os.path.exists(candidate):
        for line in open(candidate, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip().strip('"')
        break

import pymysql
conn = pymysql.connect(
    host=env.get("DB_HOST", "127.0.0.1"),
    port=int(env.get("DB_PORT", 3306)),
    user=env.get("DB_USERNAME", "root"),
    password=env.get("DB_PASSWORD", ""),
    database=env.get("DB_DATABASE", "osca_db"),
    cursorclass=pymysql.cursors.DictCursor,
)

LATEST = """
    INNER JOIN (
        SELECT senior_citizen_id, MAX(id) AS max_id
        FROM ml_results GROUP BY senior_citizen_id
    ) lat ON r.id = lat.max_id
"""

# Notebook reference (from osca5.ipynb on 283 seniors)
NOTEBOOK = {
    "C1": 75, "C2": 132, "C3": 76,
    "HIGH": 54, "MODERATE": 191, "LOW": 38,
}

with conn.cursor() as cur:
    # Total seniors in DB
    cur.execute("SELECT COUNT(*) AS n FROM senior_citizens WHERE deleted_at IS NULL")
    total_active = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM senior_citizens")
    total_all = cur.fetchone()["n"]
    print(f"\nActive seniors in DB: {total_active}  (including soft-deleted: {total_all})")

    # Full distribution (all active seniors)
    cur.execute(f"""
        SELECT r.overall_risk_level, r.cluster_named_id, COUNT(*) AS n
        FROM ml_results r {LATEST}
        GROUP BY r.overall_risk_level, r.cluster_named_id
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    rows = cur.fetchall()

    cluster_counts = {}
    risk_counts = {}
    for row in rows:
        cid = row["cluster_named_id"]
        rl  = (row["overall_risk_level"] or "").upper()
        cluster_counts[cid] = cluster_counts.get(cid, 0) + row["n"]
        risk_counts[rl]     = risk_counts.get(rl, 0) + row["n"]

    total_ml = sum(cluster_counts.values())
    print(f"\n=== System vs Notebook comparison ({total_ml} seniors in ml_results) ===")
    print(f"\n  {'Metric':<12}  {'System':>8}  {'Notebook':>8}  {'Diff':>6}")
    print("  " + "-" * 40)
    for label, nb_val in [("C1", 75), ("C2", 132), ("C3", 76)]:
        sys_val = cluster_counts.get(int(label[1]), 0)
        diff = sys_val - nb_val
        flag = "  <-- off" if abs(diff) > 2 else ""
        print(f"  {label:<12}  {sys_val:>8}  {nb_val:>8}  {diff:>+6}{flag}")
    print()
    for label, nb_val in [("HIGH", 54), ("MODERATE", 191), ("LOW", 38)]:
        sys_val = risk_counts.get(label, 0)
        diff = sys_val - nb_val
        flag = "  <-- off" if abs(diff) > 3 else ""
        print(f"  {label:<12}  {sys_val:>8}  {nb_val:>8}  {diff:>+6}{flag}")

    # Oldest 283 seniors by id (the seeded ones, closest to notebook training set)
    cur.execute(f"""
        SELECT r.overall_risk_level, r.cluster_named_id, COUNT(*) AS n
        FROM ml_results r {LATEST}
        WHERE r.senior_citizen_id IN (
            SELECT id FROM senior_citizens ORDER BY id ASC LIMIT 283
        )
        GROUP BY r.overall_risk_level, r.cluster_named_id
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    rows283 = cur.fetchall()
    cl283, rk283 = {}, {}
    for row in rows283:
        cid = row["cluster_named_id"]
        rl  = (row["overall_risk_level"] or "").upper()
        cl283[cid] = cl283.get(cid, 0) + row["n"]
        rk283[rl]  = rk283.get(rl, 0) + row["n"]

    total283 = sum(cl283.values())
    print(f"\n=== First 283 seniors only (by id) vs Notebook ({total283} seniors) ===")
    print(f"\n  {'Metric':<12}  {'System':>8}  {'Notebook':>8}  {'Diff':>6}")
    print("  " + "-" * 40)
    for label, nb_val in [("C1", 75), ("C2", 132), ("C3", 76)]:
        sys_val = cl283.get(int(label[1]), 0)
        diff = sys_val - nb_val
        flag = "  <-- off" if abs(diff) > 2 else ""
        print(f"  {label:<12}  {sys_val:>8}  {nb_val:>8}  {diff:>+6}{flag}")
    print()
    for label, nb_val in [("HIGH", 54), ("MODERATE", 191), ("LOW", 38)]:
        sys_val = rk283.get(label, 0)
        diff = sys_val - nb_val
        flag = "  <-- off" if abs(diff) > 3 else ""
        print(f"  {label:<12}  {sys_val:>8}  {nb_val:>8}  {diff:>+6}{flag}")

    # Risk threshold check: how many seniors are near the 0.50 HIGH boundary?
    cur.execute(f"""
        SELECT COUNT(*) AS n FROM ml_results r {LATEST}
        WHERE r.composite_risk BETWEEN 0.45 AND 0.55
    """)
    near_high = cur.fetchone()["n"]
    print(f"\n  Seniors with risk 0.45-0.55 (near HIGH threshold): {near_high}")
    print("  (Small changes in risk scoring shift HIGH count significantly if many seniors cluster here)")

conn.close()
