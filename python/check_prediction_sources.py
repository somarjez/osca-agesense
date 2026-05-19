"""
OSCA ML Prediction Source Validation Script
============================================
Connects to the Laravel MySQL database and prints a full breakdown of
prediction sources, risk distribution, cluster distribution, and
critical flag counts.

Expected seed result (283 notebook-validated seniors):
  Risk:    LOW=38  MODERATE=191  HIGH=54  (critical_flag=1 for those >=0.70)
  Cluster: C1=75   C2=132        C3=76

Usage:
    python python/check_prediction_sources.py
"""

import os
import sys

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))


def _read_env():
    env = {}
    for candidate in [
        os.path.join(BASE_DIR, ".env"),
        os.path.join(os.path.dirname(BASE_DIR), ".env"),
    ]:
        if os.path.exists(candidate):
            with open(candidate, encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        env[k.strip()] = v.strip().strip('"').strip("'")
            break
    return env


def main():
    try:
        import pymysql
    except ImportError:
        print("[ERROR] pymysql not installed. Run: pip install pymysql")
        sys.exit(1)

    env = _read_env()
    db_cfg = dict(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME", "root"),
        password=env.get("DB_PASSWORD", ""),
        database=env.get("DB_DATABASE", "osca_db"),
        charset="utf8mb4",
    )

    conn = pymysql.connect(**db_cfg)
    cur  = conn.cursor()

    print("=" * 60)
    print("  OSCA ML Prediction Source Validation")
    print("=" * 60)
    print(f"  DB: {db_cfg['host']}:{db_cfg['port']}/{db_cfg['database']}")
    print()

    # 1. Latest ml_result per active senior
    cur.execute("""
        SELECT MAX(ml.id) as id
        FROM ml_results ml
        JOIN senior_citizens sc ON sc.id = ml.senior_citizen_id
        WHERE sc.status = 'active' AND sc.deleted_at IS NULL
        GROUP BY ml.senior_citizen_id
    """)
    latest_ids = [r[0] for r in cur.fetchall()]

    if not latest_ids:
        print("  No ML results found.")
        conn.close()
        return

    ids_placeholder = ",".join(["%s"] * len(latest_ids))

    # 2. Total active seniors
    cur.execute("SELECT COUNT(*) FROM senior_citizens WHERE status='active' AND deleted_at IS NULL")
    total_seniors = cur.fetchone()[0]

    # 3. Prediction source counts
    cur.execute(f"""
        SELECT COALESCE(prediction_source, 'unknown'), COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY prediction_source
        ORDER BY cnt DESC
    """, latest_ids)
    source_rows = cur.fetchall()

    # 4. Risk distribution
    cur.execute(f"""
        SELECT overall_risk_level, COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY overall_risk_level
        ORDER BY FIELD(overall_risk_level, 'HIGH', 'MODERATE', 'LOW')
    """, latest_ids)
    risk_rows = cur.fetchall()

    # 5. Critical flag
    cur.execute(f"""
        SELECT COALESCE(critical_flag, 0), COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY critical_flag
    """, latest_ids)
    critical_rows = cur.fetchall()

    # 6. Cluster distribution
    cur.execute(f"""
        SELECT cluster_named_id, cluster_name, COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY cluster_named_id, cluster_name
        ORDER BY cluster_named_id
    """, latest_ids)
    cluster_rows = cur.fetchall()

    # 7. Model version
    cur.execute(f"SELECT DISTINCT model_version FROM ml_results WHERE id IN ({ids_placeholder})", latest_ids)
    versions = [r[0] for r in cur.fetchall()]

    conn.close()

    # -- Print results --

    print(f"  Total active seniors      : {total_seniors}")
    print(f"  Seniors with ML results   : {len(latest_ids)}")
    print()

    print("  PREDICTION SOURCE BREAKDOWN")
    print("  " + "-" * 40)
    source_map = {r[0]: r[1] for r in source_rows}
    nb   = source_map.get("notebook_cache", 0)
    live = source_map.get("live_model",     0)
    fb   = source_map.get("fallback",        0)
    unk  = source_map.get("unknown",         0) + source_map.get(None, 0)
    print(f"    Notebook-Validated Cache : {nb}")
    print(f"    Live ML Model            : {live}")
    print(f"    Heuristic Fallback       : {fb}")
    if unk:
        print(f"    Unknown (pre-migration)  : {unk}")
    print()

    print("  RISK INDICATOR DISTRIBUTION")
    print("  " + "-" * 40)
    risk_map = {r[0]: r[1] for r in risk_rows}
    high = risk_map.get("HIGH", 0)
    mod  = risk_map.get("MODERATE", 0)
    low  = risk_map.get("LOW", 0)
    print(f"    HIGH                     : {high}  (expected 54)")
    print(f"    MODERATE                 : {mod}  (expected 191)")
    print(f"    LOW                      : {low}  (expected 38)")
    total_risk = high + mod + low
    status_risk = "PASS" if (high == 54 and mod == 191 and low == 38) else "FAIL"
    print(f"    Total                    : {total_risk}  [{status_risk}]")
    print()

    print("  CRITICAL FLAG (HIGH + composite >= 0.70)")
    print("  " + "-" * 40)
    crit_map = {r[0]: r[1] for r in critical_rows}
    crit_yes = crit_map.get(1, 0) + crit_map.get(True, 0)
    print(f"    Critical flag = true     : {crit_yes}")
    print()

    print("  CLUSTER DISTRIBUTION")
    print("  " + "-" * 40)
    cluster_map_display = {r[0]: (r[1], r[2]) for r in cluster_rows}
    c1 = cluster_map_display.get(1, ("High Functioning", 0))[1]
    c2 = cluster_map_display.get(2, ("Moderate / Mixed Needs", 0))[1]
    c3 = cluster_map_display.get(3, ("Low Functioning / Multi-domain Risk", 0))[1]
    for cid, (cname, cnt) in sorted(cluster_map_display.items()):
        exp = {1: 75, 2: 132, 3: 76}.get(cid, "?")
        print(f"    C{cid} {(cname or '').ljust(38)}: {cnt}  (expected {exp})")
    status_cluster = "PASS" if (c1 == 75 and c2 == 132 and c3 == 76) else "FAIL"
    print(f"    Cluster check            : [{status_cluster}]")
    print()

    print("  MODEL VERSION")
    print("  " + "-" * 40)
    for v in versions:
        print(f"    {v}")
    print()

    # Overall verdict
    overall = "PASS" if status_risk == "PASS" and status_cluster == "PASS" else "FAIL"
    print("=" * 60)
    print(f"  OVERALL VALIDATION: {overall}")
    print("=" * 60)


if __name__ == "__main__":
    main()
