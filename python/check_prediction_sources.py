"""
OSCA ML Prediction Source Validation Script
============================================
Connects to the Laravel MySQL database and prints a full breakdown of
prediction sources, risk distribution, cluster distribution, and
critical flag counts.

Validation logic:
  - SEED RECORDS: seniors whose identity matches a row in senior_predictions.csv
    (matched by normalized name + barangay + age).  These should ALL have
    prediction_source = notebook_cache after a successful repair.
  - NEW RECORDS: seniors not in the CSV (added after the notebook run).
    These legitimately use live_model.
  - SEED VALIDATION: FAIL only if any seed senior has prediction_source != notebook_cache.

Expected seed result (283 notebook-validated seniors):
  Risk:    LOW=38  MODERATE=191  HIGH=54
  Cluster: C1=75   C2=132        C3=76

Usage:
    python python/check_prediction_sources.py
"""

import csv
import os
import re
import sys
import unicodedata

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

PREDICTIONS_CANDIDATES = [
    os.path.join(os.path.dirname(__file__), "models", "predictions", "senior_predictions.csv"),
    os.path.abspath(os.path.join(BASE_DIR, "osca_output", "predictions", "senior_predictions.csv")),
    os.path.abspath(os.path.join(BASE_DIR, "osca_output", "reports", "predictions", "senior_predictions.csv")),
]


def _read_env():
    env = {}
    for candidate in [
        os.path.join(BASE_DIR, "osca-system", ".env"),
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


def _normalize(value):
    """
    Robust normalization matching inference_service.py _normalize_identity_part():
    NFC → explicit ñ→n → NFKD → strip combining → lowercase → keep [a-z0-9].
    """
    text = str(value or "")
    text = unicodedata.normalize("NFC", text)
    text = text.replace("ñ", "n").replace("Ñ", "n")
    text = unicodedata.normalize("NFKD", text)
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower().strip()
    return re.sub(r"[^a-z0-9]+", "", text)


def _load_csv_identity_set():
    """
    Load the set of (norm_first, norm_last, norm_barangay, age_str) tuples
    from senior_predictions.csv.  Returns (set_of_keys, csv_row_count, path_used).
    Also tries cp1252 encoding since the CSV may be a Windows Excel export.
    """
    path = None
    for candidate in PREDICTIONS_CANDIDATES:
        if os.path.exists(candidate):
            path = candidate
            break

    if path is None:
        return set(), 0, None

    keys = set()
    row_count = 0
    encodings_to_try = ["utf-8-sig", "utf-8", "cp1252", "latin-1"]
    opened_file = None
    for enc in encodings_to_try:
        try:
            f = open(path, "r", encoding=enc, errors="strict", newline="")
            [f.readline() for _ in range(5)]
            f.seek(0)
            opened_file = (f, enc)
            break
        except (UnicodeDecodeError, LookupError):
            try:
                f.close()
            except Exception:
                pass

    if opened_file is None:
        opened_file = (open(path, "r", encoding="cp1252", errors="replace", newline=""), "cp1252-replace")

    fileobj, enc_used = opened_file
    with fileobj as f:
        for row in csv.DictReader(f):
            row_count += 1
            fn  = _normalize(row.get("first_name", ""))
            ln  = _normalize(row.get("last_name", ""))
            br  = _normalize(row.get("barangay", ""))
            age = str(row.get("age", "")).strip()
            keys.add((fn, ln, br, age))

    print(f"  CSV path    : {path}")
    print(f"  CSV encoding: {enc_used}")
    return keys, row_count, path


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

    # ── Load CSV identity set ──────────────────────────────────────────────
    csv_keys, csv_row_count, csv_path = _load_csv_identity_set()
    if csv_row_count == 0:
        print("  [WARN] senior_predictions.csv not found or empty — seed vs. new split unavailable.")
    else:
        print(f"  CSV seed records: {csv_row_count}")
    print()

    # ── Latest ml_result per active senior ────────────────────────────────
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

    # ── Total active seniors ───────────────────────────────────────────────
    cur.execute("SELECT COUNT(*) FROM senior_citizens WHERE status='active' AND deleted_at IS NULL")
    total_seniors = cur.fetchone()[0]

    # ── Fetch each senior's identity + latest prediction_source ───────────
    cur.execute(f"""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               TIMESTAMPDIFF(YEAR, sc.date_of_birth, CURDATE()) AS age,
               ml.prediction_source
        FROM ml_results ml
        JOIN senior_citizens sc ON sc.id = ml.senior_citizen_id
        WHERE ml.id IN ({ids_placeholder})
    """, latest_ids)
    senior_rows = cur.fetchall()

    # Classify each senior as seed or new
    seed_sources  = {}   # source -> count
    new_sources   = {}   # source -> count
    seed_mismatches = [] # (first_name, last_name, barangay, age, source)

    for fn, ln, br, age, src in senior_rows:
        norm_key = (_normalize(fn), _normalize(ln), _normalize(br), str(age))
        is_seed  = norm_key in csv_keys
        src_str  = src or "unknown"
        if is_seed:
            seed_sources[src_str] = seed_sources.get(src_str, 0) + 1
            if src_str != "notebook_cache":
                seed_mismatches.append((fn, ln, br, age, src_str))
        else:
            new_sources[src_str] = new_sources.get(src_str, 0) + 1

    # ── Overall source breakdown ───────────────────────────────────────────
    cur.execute(f"""
        SELECT COALESCE(prediction_source, 'unknown'), COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY prediction_source
        ORDER BY cnt DESC
    """, latest_ids)
    source_rows = cur.fetchall()
    source_map = {r[0]: r[1] for r in source_rows}
    nb   = source_map.get("notebook_cache", 0)
    live = source_map.get("live_model",     0)
    fb   = source_map.get("fallback",        0)
    unk  = source_map.get("unknown",         0)

    # ── Risk distribution ──────────────────────────────────────────────────
    cur.execute(f"""
        SELECT overall_risk_level, COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY overall_risk_level
        ORDER BY FIELD(overall_risk_level, 'HIGH', 'MODERATE', 'LOW')
    """, latest_ids)
    risk_rows = cur.fetchall()
    risk_map = {r[0]: r[1] for r in risk_rows}
    high = risk_map.get("HIGH",     0)
    mod  = risk_map.get("MODERATE", 0)
    low  = risk_map.get("LOW",      0)

    # ── Critical flag ──────────────────────────────────────────────────────
    cur.execute(f"""
        SELECT COALESCE(critical_flag, 0), COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY critical_flag
    """, latest_ids)
    critical_rows = cur.fetchall()
    crit_map = {r[0]: r[1] for r in critical_rows}
    crit_yes = crit_map.get(1, 0) + crit_map.get(True, 0)

    # ── Cluster distribution ───────────────────────────────────────────────
    cur.execute(f"""
        SELECT cluster_named_id, cluster_name, COUNT(*) as cnt
        FROM ml_results
        WHERE id IN ({ids_placeholder})
        GROUP BY cluster_named_id, cluster_name
        ORDER BY cluster_named_id
    """, latest_ids)
    cluster_rows = cur.fetchall()
    cluster_map_display = {r[0]: (r[1], r[2]) for r in cluster_rows}
    c1 = cluster_map_display.get(1, ("High Functioning", 0))[1]
    c2 = cluster_map_display.get(2, ("Moderate / Mixed Needs", 0))[1]
    c3 = cluster_map_display.get(3, ("Low Functioning / Multi-domain Risk", 0))[1]

    # ── Model version ──────────────────────────────────────────────────────
    cur.execute(f"SELECT DISTINCT model_version FROM ml_results WHERE id IN ({ids_placeholder})", latest_ids)
    versions = [r[0] for r in cur.fetchall()]

    conn.close()

    # ─────────────────────────────────────────────────────────────────────
    # Print results
    # ─────────────────────────────────────────────────────────────────────

    print(f"  Total active seniors      : {total_seniors}")
    print(f"  Seniors with ML results   : {len(latest_ids)}")
    print()

    # Seed vs. New breakdown (only if CSV was found)
    if csv_row_count > 0:
        seed_nb   = seed_sources.get("notebook_cache", 0)
        seed_live = seed_sources.get("live_model",     0)
        seed_fb   = seed_sources.get("fallback",        0)
        new_live  = new_sources.get("live_model",       0)
        new_fb    = new_sources.get("fallback",          0)

        print("  SEED RECORDS (matched to senior_predictions.csv)")
        print("  " + "-" * 44)
        print(f"    notebook_cache : {seed_nb}  (expected {csv_row_count})")
        print(f"    live_model     : {seed_live}  {'<-- mismatch, should be 0' if seed_live > 0 else ''}")
        print(f"    fallback       : {seed_fb}")
        print()

        if seed_mismatches:
            print("  SEED MISMATCHES — seniors in CSV but not matched as notebook_cache:")
            print("  " + "-" * 44)
            for fn, ln, br, age, src in seed_mismatches:
                print(f"    {fn} {ln} | {br} | age {age} | source={src}")
            print()

        print("  NEW RECORDS (not in senior_predictions.csv)")
        print("  " + "-" * 44)
        print(f"    live_model : {new_live}")
        print(f"    fallback   : {new_fb}")
        print()

        seed_ok = seed_live == 0 and seed_fb == 0
        print(f"  SEED VALIDATION: {'PASS' if seed_ok else 'FAIL'}")
        print()

    print("  OVERALL DATABASE SUMMARY")
    print("  " + "-" * 44)
    print(f"    Total active seniors      : {total_seniors}")
    print(f"    Notebook-Validated Cache  : {nb}")
    print(f"    Live ML Model             : {live}")
    print(f"    Heuristic Fallback        : {fb}")
    if unk:
        print(f"    Unknown (pre-migration)   : {unk}")
    print()

    print("  RISK INDICATOR DISTRIBUTION")
    print("  " + "-" * 44)
    status_risk = "PASS" if (high == 54 and mod == 191 and low == 38) else "FAIL"
    print(f"    HIGH                     : {high}  (expected 54)")
    print(f"    MODERATE                 : {mod}  (expected 191)")
    print(f"    LOW                      : {low}  (expected 38)")
    print(f"    Total                    : {high + mod + low}  [{status_risk}]")
    print()

    print("  CRITICAL FLAG (HIGH + composite >= 0.70)")
    print("  " + "-" * 44)
    print(f"    Critical flag = true     : {crit_yes}")
    print()

    print("  CLUSTER DISTRIBUTION")
    print("  " + "-" * 44)
    for cid, (cname, cnt) in sorted(cluster_map_display.items()):
        exp = {1: 75, 2: 132, 3: 76}.get(cid, "?")
        print(f"    C{cid} {(cname or '').ljust(38)}: {cnt}  (expected {exp})")
    status_cluster = "PASS" if (c1 == 75 and c2 == 132 and c3 == 76) else "FAIL"
    print(f"    Cluster check            : [{status_cluster}]")
    print()

    print("  MODEL VERSION")
    print("  " + "-" * 44)
    for v in versions:
        print(f"    {v}")
    print()

    print("=" * 60)
    if csv_row_count > 0:
        seed_ok = seed_sources.get("live_model", 0) == 0 and seed_sources.get("fallback", 0) == 0
        print(f"  SEED VALIDATION : {'PASS' if seed_ok else 'FAIL'}")
    print(f"  RISK CHECK      : {status_risk}")
    print(f"  CLUSTER CHECK   : {status_cluster}")
    print("=" * 60)


if __name__ == "__main__":
    main()
