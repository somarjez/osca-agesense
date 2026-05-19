"""
Diagnose risk distribution shift.
Run: python\venv\Scripts\python.exe python\check_risk_dist.py
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

with conn.cursor() as cur:
    cur.execute(f"""
        SELECT
            COUNT(*) AS total,
            ROUND(AVG(r.composite_risk), 4)  AS avg_risk,
            ROUND(MIN(r.composite_risk), 4)  AS min_risk,
            ROUND(MAX(r.composite_risk), 4)  AS max_risk,
            SUM(r.composite_risk >= 0.50)    AS count_high,
            SUM(r.composite_risk >= 0.30 AND r.composite_risk < 0.50) AS count_moderate,
            SUM(r.composite_risk < 0.30)     AS count_low
        FROM ml_results r {LATEST}
    """)
    s = cur.fetchone()
    print("\n=== Risk score summary (this device) ===")
    print(f"  Total seniors: {s['total']}")
    print(f"  Avg composite risk: {s['avg_risk']}")
    print(f"  Min / Max: {s['min_risk']} / {s['max_risk']}")
    print(f"  HIGH  (>=0.50): {s['count_high']}")
    print(f"  MOD   (0.30-0.49): {s['count_moderate']}")
    print(f"  LOW   (<0.30): {s['count_low']}")

    # Histogram
    cur.execute(f"""
        SELECT FLOOR(r.composite_risk / 0.05) * 0.05 AS bucket, COUNT(*) AS n
        FROM ml_results r {LATEST}
        GROUP BY bucket ORDER BY bucket
    """)
    print("\n=== Composite risk histogram ===")
    print("  bucket       n  bar")
    for row in cur.fetchall():
        b = float(row["bucket"])
        label = "HIGH" if b >= 0.50 else ("MOD" if b >= 0.30 else "LOW")
        bar = "#" * int(row["n"])
        print(f"  {b:.2f}-{b+0.05:.2f}  {row['n']:3d}  {bar}  [{label}]")

    # Per-cluster risk level counts (from stored overall_risk_level field)
    cur.execute(f"""
        SELECT r.cluster_named_id, r.overall_risk_level,
               COUNT(*) AS n,
               ROUND(AVG(r.composite_risk), 4) AS avg_risk
        FROM ml_results r {LATEST}
        GROUP BY r.cluster_named_id, r.overall_risk_level
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    print("\n=== Stored risk_level vs cluster (from ml_results.overall_risk_level) ===")
    for row in cur.fetchall():
        print(f"  C{row['cluster_named_id']}  {row['overall_risk_level']:>10}  n={row['n']:4d}  avg_risk={row['avg_risk']:.4f}")

    # Borderline seniors near 0.30 threshold (LOW/MOD boundary)
    cur.execute(f"""
        SELECT sc.first_name, sc.last_name,
               ROUND(r.composite_risk, 4) AS risk,
               r.overall_risk_level, r.cluster_named_id,
               ROUND(r.wellbeing_score, 4) AS wb
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
        {LATEST}
        WHERE r.composite_risk BETWEEN 0.25 AND 0.35
        ORDER BY r.composite_risk
        LIMIT 20
    """)
    rows = cur.fetchall()
    print(f"\n=== Borderline seniors near LOW/MOD threshold (0.25-0.35), n={len(rows)} ===")
    for row in rows:
        print(f"  C{row['cluster_named_id']}  {row['overall_risk_level']:>8}  risk={row['risk']}  wb={row['wb']}  "
              f"{row['first_name']} {row['last_name']}")

conn.close()
