"""
Check what the system showed at the very first ML run vs now.
python\venv\Scripts\python.exe python\check_history.py
"""
import os, sys
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

ACTIVE = "JOIN senior_citizens sc ON sc.id = r.senior_citizen_id AND sc.deleted_at IS NULL"

# 1. Earliest ever result per active senior (what the system showed at first run)
with conn.cursor() as cur:
    cur.execute(f"""
        SELECT r.overall_risk_level, r.cluster_named_id, COUNT(*) AS n
        FROM ml_results r
        JOIN (
            SELECT senior_citizen_id, MIN(id) AS first_id
            FROM ml_results GROUP BY senior_citizen_id
        ) first_r ON r.id = first_r.first_id
        {ACTIVE}
        GROUP BY r.overall_risk_level, r.cluster_named_id
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    rows = cur.fetchall()

clusters_first, risks_first = {}, {}
for row in rows:
    cid = row["cluster_named_id"]
    rl  = (row["overall_risk_level"] or "").upper()
    clusters_first[cid] = clusters_first.get(cid, 0) + row["n"]
    risks_first[rl]     = risks_first.get(rl, 0) + row["n"]

print("\n=== Earliest ml_results per senior (first time each was ever processed) ===")
print(f"  C1={clusters_first.get(1,0)}  C2={clusters_first.get(2,0)}  C3={clusters_first.get(3,0)}  "
      f"  HIGH={risks_first.get('HIGH',0)}  MOD={risks_first.get('MODERATE',0)}  LOW={risks_first.get('LOW',0)}")

# 2. Latest result per active senior (current)
with conn.cursor() as cur:
    cur.execute(f"""
        SELECT r.overall_risk_level, r.cluster_named_id, COUNT(*) AS n
        FROM ml_results r
        JOIN (
            SELECT senior_citizen_id, MAX(id) AS last_id
            FROM ml_results GROUP BY senior_citizen_id
        ) last_r ON r.id = last_r.last_id
        {ACTIVE}
        GROUP BY r.overall_risk_level, r.cluster_named_id
        ORDER BY r.cluster_named_id, r.overall_risk_level
    """)
    rows = cur.fetchall()

clusters_now, risks_now = {}, {}
for row in rows:
    cid = row["cluster_named_id"]
    rl  = (row["overall_risk_level"] or "").upper()
    clusters_now[cid] = clusters_now.get(cid, 0) + row["n"]
    risks_now[rl]     = risks_now.get(rl, 0) + row["n"]

print("\n=== Latest ml_results per senior (current state) ===")
print(f"  C1={clusters_now.get(1,0)}  C2={clusters_now.get(2,0)}  C3={clusters_now.get(3,0)}  "
      f"  HIGH={risks_now.get('HIGH',0)}  MOD={risks_now.get('MODERATE',0)}  LOW={risks_now.get('LOW',0)}")

# 3. How many ml_results rows exist total vs distinct seniors
with conn.cursor() as cur:
    cur.execute("SELECT COUNT(*) AS n FROM ml_results")
    total_rows = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(DISTINCT senior_citizen_id) AS n FROM ml_results")
    distinct_seniors = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM senior_citizens WHERE deleted_at IS NULL")
    active_seniors = cur.fetchone()["n"]
    cur.execute("SELECT COUNT(*) AS n FROM senior_citizens WHERE deleted_at IS NOT NULL")
    archived_seniors = cur.fetchone()["n"]

print(f"\n=== DB state ===")
print(f"  Active seniors:   {active_seniors}")
print(f"  Archived seniors: {archived_seniors}")
print(f"  ml_results rows (total):    {total_rows}")
print(f"  ml_results (distinct senior_citizen_id): {distinct_seniors}")

# 4. Check if any archived senior still has ml_results being counted
with conn.cursor() as cur:
    cur.execute("""
        SELECT COUNT(*) AS n FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
        WHERE sc.deleted_at IS NOT NULL
    """)
    archived_with_results = cur.fetchone()["n"]

print(f"  ml_results rows for ARCHIVED seniors: {archived_with_results}  (should be excluded from dashboard)")

# 5. Compare notebook reference
print("\n=== Comparison vs notebook reference ===")
print("  Notebook reference (in-sample, threshold >= 0.45):")
print("    C1=75  C2=132  C3=76   HIGH=54  MODERATE=191  LOW=38")
print("  System first run (actual live predictions):")
print(f"    C1={clusters_first.get(1,0)}  C2={clusters_first.get(2,0)}  C3={clusters_first.get(3,0)}"
      f"   HIGH={risks_first.get('HIGH',0)}  MODERATE={risks_first.get('MODERATE',0)}  LOW={risks_first.get('LOW',0)}")
print("  System current:")
print(f"    C1={clusters_now.get(1,0)}  C2={clusters_now.get(2,0)}  C3={clusters_now.get(3,0)}"
      f"   HIGH={risks_now.get('HIGH',0)}  MODERATE={risks_now.get('MODERATE',0)}  LOW={risks_now.get('LOW',0)}")

conn.close()
