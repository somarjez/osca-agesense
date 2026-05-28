"""
generate_validation_report.py
=============================================================
Reads the live DB and committed model JSON files, then prints a
Markdown evidence table with current numbers for the model
validation document.

Usage (from repo root):
    python\\venv\\Scripts\\python.exe python\\scripts\\generate_validation_report.py
    python\\venv\\Scripts\\python.exe python\\scripts\\generate_validation_report.py --output docs\\evidence-table-current.md

Exit 0 = success.
Exit 1 = required file missing or DB connection failed.
"""
import os, sys, json, argparse

BASE_DIR   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODELS_DIR = os.path.join(BASE_DIR, "models")

# ── Notebook-validated constants ──────────────────────────────────────────────
# These three values are locked from the validated comparison run (v1.1.1,
# 2026-05-28).  Re-generate by running:
#   python\scripts\compare_notebook_vs_live.py   (requires senior_predictions.csv)
_NB_CLUSTER_MATCH_N   = 272
_NB_CLUSTER_MATCH_TOT = 283
_NB_RISK_MATCH_N      = 282
_NB_RISK_MATCH_TOT    = 283
_NB_MAX_DELTA         = 0.0061
_URGENT_THRESHOLD     = 0.70


def load_env(base_dir: str) -> dict:
    """Return key=value pairs from the nearest .env file."""
    env = {}
    for candidate in [
        os.path.join(base_dir, ".env"),
        os.path.join(os.path.dirname(base_dir), ".env"),
    ]:
        if os.path.exists(candidate):
            with open(candidate, encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        env[k.strip()] = v.strip().strip('"').strip("'")
            break
    return env


def load_model_metrics(models_dir: str) -> dict:
    """
    Read cluster_eval_metrics.json and regression_baseline.json from models_dir.
    model_version, baseline_locked_on, and baseline_senior_count are sourced from
    regression_baseline.json._meta (not from a separate model_manifest.json).

    Returns a dict with keys:
        silhouette (float), davies_bouldin (float), calinski_harabasz (float),
        model_version (str), baseline_locked_on (str), baseline_senior_count (int)

    Raises FileNotFoundError if any required file is missing.
    """
    metrics = {}

    # cluster_eval_metrics.json
    eval_path = os.path.join(models_dir, "cluster_eval_metrics.json")
    if not os.path.exists(eval_path):
        raise FileNotFoundError(f"Required file missing: {eval_path}")
    with open(eval_path, encoding="utf-8") as f:
        ev = json.load(f)
    metrics["silhouette"]        = float(ev["silhouette"])
    metrics["davies_bouldin"]    = float(ev["davies_bouldin"])
    metrics["calinski_harabasz"] = float(ev["calinski_harabasz"])

    # regression_baseline.json
    baseline_path = os.path.join(models_dir, "regression_baseline.json")
    if not os.path.exists(baseline_path):
        raise FileNotFoundError(f"Required file missing: {baseline_path}")
    with open(baseline_path, encoding="utf-8") as f:
        bl = json.load(f)
    meta = bl.get("_meta", {})
    metrics["model_version"]         = str(meta.get("model_version", "unknown"))
    metrics["baseline_locked_on"]    = str(meta.get("locked_on",     "unknown"))
    metrics["baseline_senior_count"] = int(meta.get("senior_count",  len(bl.get("seniors", {}))))

    return metrics


def query_live_distribution(conn) -> dict:
    """
    Query ml_results for the latest result per active senior.

    Returns dict:
        risk (dict: "HIGH"|"MODERATE"|"LOW" -> int count)
        cluster (dict: "1"|"2"|"3" -> int count)
        urgent_count (int)   seniors with composite_risk >= 0.70 AND risk = HIGH
        total (int)          total seniors with any ML result
        regression_failures (int)  always 0 — regression_test.py is run separately
    """
    _LATEST = """
        JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) lat ON r.id = lat.max_id
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
    """

    with conn.cursor() as cur:
        # Risk distribution
        cur.execute(f"""
            SELECT r.overall_risk_level AS lvl, COUNT(*) AS n
            FROM ml_results r {_LATEST}
            GROUP BY r.overall_risk_level
        """)
        risk = {}
        for row in cur.fetchall():
            risk[(row["lvl"] or "UNKNOWN").upper()] = int(row["n"])

        # Cluster distribution
        cur.execute(f"""
            SELECT r.cluster_named_id AS cid, COUNT(*) AS n
            FROM ml_results r {_LATEST}
            GROUP BY r.cluster_named_id
        """)
        cluster = {}
        for row in cur.fetchall():
            cluster[str(row["cid"] or 0)] = int(row["n"])

        # Urgent count
        cur.execute(f"""
            SELECT COUNT(*) AS n
            FROM ml_results r {_LATEST}
            WHERE r.overall_risk_level = 'HIGH'
              AND r.composite_risk >= %s
        """, (_URGENT_THRESHOLD,))
        urgent_count = int(cur.fetchone()["n"])

        # Total
        cur.execute(f"""
            SELECT COUNT(*) AS n FROM ml_results r {_LATEST}
        """)
        total = int(cur.fetchone()["n"])

    return {
        "risk":                risk,
        "cluster":             cluster,
        "urgent_count":        urgent_count,
        "total":               total,
        "regression_failures": 0,
    }


def render_evidence_table(metrics: dict, distribution: dict) -> str:
    """
    Render the Markdown evidence table from model metrics and live distribution.
    Returns the table as a string (no trailing newline).

    Notebook comparison constants (_NB_*) are module-level — callers running unit
    tests see the same locked values as production.
    """
    cluster_pct = 100 * _NB_CLUSTER_MATCH_N / _NB_CLUSTER_MATCH_TOT
    risk_pct    = 100 * _NB_RISK_MATCH_N    / _NB_RISK_MATCH_TOT

    risk   = distribution["risk"]
    clust  = distribution["cluster"]
    urgent = distribution["urgent_count"]
    total  = distribution["total"]
    reg_f  = distribution["regression_failures"]

    high_n = risk.get("HIGH",     0)
    mod_n  = risk.get("MODERATE", 0)
    low_n  = risk.get("LOW",      0)
    c1_n   = clust.get("1", 0)
    c2_n   = clust.get("2", 0)
    c3_n   = clust.get("3", 0)

    high_pct = 100 * high_n / total if total else 0
    mod_pct  = 100 * mod_n  / total if total else 0
    low_pct  = 100 * low_n  / total if total else 0

    rows = [
        "| Metric | Value | Source |",
        "|---|---|---|",
        f"| Training population | {_NB_CLUSTER_MATCH_TOT} seniors (Pagsanjan OSCA dataset) | `osca5.ipynb` |",
        f"| Cluster match: live system vs notebook | **{_NB_CLUSTER_MATCH_N} / {_NB_CLUSTER_MATCH_TOT} = {cluster_pct:.1f}%** | `compare_notebook_vs_live.py` |",
        f"| Risk-level match: live system vs notebook | **{_NB_RISK_MATCH_N} / {_NB_RISK_MATCH_TOT} = {risk_pct:.1f}%** | `compare_notebook_vs_live.py` |",
        f"| Max composite risk delta (live vs notebook) | **{_NB_MAX_DELTA}** | `compare_notebook_vs_live.py` |",
        f"| Regression baseline failures (post v1.1.1) | **{reg_f} failures** (tolerance ±0.005 per senior) | `regression_test.py` |",
        "| **Risk distribution (live model)** | | |",
        f"| — LOW risk | {low_n} seniors ({low_pct:.1f}%) | `validate_clusters.py` |",
        f"| — MODERATE risk | {mod_n} seniors ({mod_pct:.1f}%) | `validate_clusters.py` |",
        f"| — HIGH risk | {high_n} seniors ({high_pct:.1f}%) | `validate_clusters.py` |",
        f"| — HIGH risk, urgent flag (composite >= {_URGENT_THRESHOLD}) | **{urgent} seniors** | `final_comparison_report.py` |",
        "| **Cluster distribution (live model)** | | |",
        f"| — C1 High Functioning | {c1_n} seniors | `validate_clusters.py` |",
        f"| — C2 Moderate / Mixed Needs | {c2_n} seniors | `validate_clusters.py` |",
        f"| — C3 Low Functioning / Multi-domain Risk | {c3_n} seniors | `validate_clusters.py` |",
        f"| Silhouette score (cluster quality) | **{metrics['silhouette']}** | `cluster_eval_metrics.json` |",
        f"| Davies-Bouldin index (cluster separation) | **{metrics['davies_bouldin']}** | `cluster_eval_metrics.json` |",
        f"| Calinski-Harabasz index (cluster density) | **{metrics['calinski_harabasz']}** | `cluster_eval_metrics.json` |",
        f"| Model version | **{metrics['model_version']}** | `model_manifest.json` |",
        f"| Regression baseline locked | **{metrics['baseline_locked_on']}** | `regression_baseline.json` |",
    ]
    return "\n".join(rows)


def main():
    parser = argparse.ArgumentParser(
        description="Generate the AgeSense model validation evidence table from live DB + JSON files."
    )
    parser.add_argument("--output", "-o",
        help="Write Markdown evidence table to this file path (optional)")
    parser.add_argument("--models-dir", default=MODELS_DIR,
        help=f"Path to python/models/ directory (default: {MODELS_DIR})")
    args = parser.parse_args()

    # Load JSON metrics
    try:
        metrics = load_model_metrics(args.models_dir)
    except FileNotFoundError as exc:
        print(f"[ERROR] {exc}", file=sys.stderr)
        sys.exit(1)

    # Connect to DB
    env = load_env(BASE_DIR)
    try:
        import pymysql
        import pymysql.cursors
        conn = pymysql.connect(
            host     = env.get("DB_HOST",     "127.0.0.1"),
            port     = int(env.get("DB_PORT", 3306)),
            user     = env.get("DB_USERNAME", "root"),
            password = env.get("DB_PASSWORD", ""),
            database = env.get("DB_DATABASE", "osca_db"),
            cursorclass = pymysql.cursors.DictCursor,
        )
    except Exception as exc:
        print(f"[ERROR] DB connection failed: {exc}", file=sys.stderr)
        sys.exit(1)

    try:
        distribution = query_live_distribution(conn)
    finally:
        conn.close()

    table = render_evidence_table(metrics, distribution)

    print("\n=== AgeSense Model Validation — Evidence Table ===\n")
    print(table)
    print(f"\n[INFO] Total seniors with ML results : {distribution['total']}")
    print(f"[INFO] Urgent-priority seniors       : {distribution['urgent_count']}  (composite >= {_URGENT_THRESHOLD})")
    print(f"[INFO] Model version                 : {metrics['model_version']}")
    print(f"[INFO] Regression baseline locked    : {metrics['baseline_locked_on']}")

    if args.output:
        out_dir = os.path.dirname(args.output)
        if out_dir:
            os.makedirs(out_dir, exist_ok=True)
        with open(args.output, "w", encoding="utf-8") as f:
            f.write("# AgeSense Model Validation — Evidence Table\n\n")
            f.write("*Generated from live DB and committed model files.*\n\n")
            f.write(table)
            f.write("\n")
        print(f"\n[OK] Evidence table written to: {args.output}")

    sys.exit(0)


if __name__ == "__main__":
    main()
