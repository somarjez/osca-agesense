"""
compare_notebook_vs_live.py
============================
Compares every senior's live_model result against the notebook study ground
truth stored in senior_predictions.csv.

The comparison is done by matching name + barangay between the CSV and the DB.
This approach works regardless of whether notebook_cache rows exist in the DB.

Notebook target (from the study):
    C1 · High Functioning:            75 seniors  composite_risk ~0.306
    C2 · Moderate / Mixed Needs:     132 seniors  composite_risk ~0.399
    C3 · Low Functioning/Multi-Risk:  76 seniors  composite_risk ~0.534

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\compare_notebook_vs_live.py

Saves full report to:
    python\\scripts\\audit_output\\notebook_vs_live_report.txt
"""

import os, sys, csv
from collections import Counter
import pymysql
import pymysql.cursors

BASE_DIR   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "audit_output")
PREDS_CSV  = os.path.join(BASE_DIR, "models", "predictions", "senior_predictions.csv")
os.makedirs(OUTPUT_DIR, exist_ok=True)

# ── Load .env ──────────────────────────────────────────────────────────────────
env = {}
for cand in [os.path.join(BASE_DIR, ".env"),
             os.path.join(os.path.dirname(BASE_DIR), ".env")]:
    if os.path.exists(cand):
        for line in open(cand, encoding="utf-8"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip().strip('"').strip("'")
        break

# ── Notebook target (from study summary) ──────────────────────────────────────
NOTEBOOK_TARGET = {
    "1": {"n": 75,  "name": "High Functioning",           "composite": 0.306},
    "2": {"n": 132, "name": "Moderate / Mixed Needs",     "composite": 0.399},
    "3": {"n": 76,  "name": "Low Functioning/Multi-Risk", "composite": 0.534},
}

# ── Load notebook ground truth from senior_predictions.csv ────────────────────
if not os.path.exists(PREDS_CSV):
    print(f"[ERROR] senior_predictions.csv not found at:\n  {PREDS_CSV}")
    print("This file is gitignored — copy it from the original study device.")
    sys.exit(1)

nb_preds = {}   # key: "firstname|lastname|barangay" → csv row
with open(PREDS_CSV, encoding="utf-8-sig") as f:
    for row in csv.DictReader(f):
        key = (f"{row['first_name'].strip().lower()}|"
               f"{row['last_name'].strip().lower()}|"
               f"{row['barangay'].strip().lower()}")
        nb_preds[key] = row

print(f"Notebook CSV: {len(nb_preds)} seniors")

# ── Load live_model results from DB ───────────────────────────────────────────
conn = pymysql.connect(
    host     = env.get("DB_HOST", "127.0.0.1"),
    port     = int(env.get("DB_PORT", 3306)),
    user     = env.get("DB_USERNAME", "root"),
    password = env.get("DB_PASSWORD", ""),
    database = env.get("DB_DATABASE", "osca_db"),
    cursorclass = pymysql.cursors.DictCursor,
)
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               r.senior_citizen_id,
               r.cluster_named_id   AS lv_cluster,
               r.cluster_name       AS lv_cluster_name,
               r.overall_risk_level AS lv_risk,
               r.composite_risk     AS lv_composite,
               r.wellbeing_score    AS lv_wellbeing,
               r.ic_score           AS lv_ic,
               r.env_score          AS lv_env,
               r.func_score         AS lv_func,
               r.qol_score          AS lv_qol,
               r.model_version      AS lv_version
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id AND sc.deleted_at IS NULL
        WHERE r.id = (
            SELECT MAX(id2.id) FROM ml_results id2
            WHERE id2.senior_citizen_id = r.senior_citizen_id
        )
        ORDER BY sc.last_name, sc.first_name
    """)
    db_rows = {}
    for row in cur.fetchall():
        key = (f"{row['first_name'].strip().lower()}|"
               f"{row['last_name'].strip().lower()}|"
               f"{row['barangay'].strip().lower()}")
        db_rows[key] = row
conn.close()

print(f"DB live results: {len(db_rows)} seniors")

# ── Match and compare ──────────────────────────────────────────────────────────
matched_keys = set(nb_preds.keys()) & set(db_rows.keys())
nb_only      = set(nb_preds.keys()) - set(db_rows.keys())
db_only      = set(db_rows.keys())  - set(nb_preds.keys())

n = len(matched_keys)
cluster_match = 0
risk_match    = 0
composite_diffs = []
cluster_mismatches = []
risk_mismatches    = []
live_cluster_counts = Counter()

for key in sorted(matched_keys):
    nb  = nb_preds[key]
    lv  = db_rows[key]
    fn  = nb["first_name"]
    ln  = nb["last_name"]
    bar = nb["barangay"]
    name = f"{fn} {ln} ({bar})"

    nb_c = str(nb.get("cluster_id", "?")).strip()
    lv_c = str(int(lv["lv_cluster"] or 0))
    nb_r = str(nb.get("risk_level", "?")).strip().lower()
    lv_r = str(lv["lv_risk"] or "").lower()
    nb_comp = float(nb.get("composite_risk") or 0)
    lv_comp = float(lv["lv_composite"] or 0)

    live_cluster_counts[lv_c] += 1

    if nb_c == lv_c:
        cluster_match += 1
    else:
        cluster_mismatches.append({
            "name":       name,
            "nb_cluster": nb_c, "lv_cluster": lv_c,
            "nb_risk":    nb_r,  "lv_risk":    lv_r,
            "nb_comp":    nb_comp, "lv_comp":  lv_comp,
        })

    if nb_r == lv_r:
        risk_match += 1
    else:
        risk_mismatches.append({
            "name":       name,
            "nb_risk":    nb_r,  "lv_risk":   lv_r,
            "nb_comp":    nb_comp, "lv_comp": lv_comp,
            "nb_cluster": nb_c,  "lv_cluster": lv_c,
        })

    composite_diffs.append(abs(nb_comp - lv_comp))

avg_comp_diff = sum(composite_diffs) / n if n else 0
max_comp_diff = max(composite_diffs) if composite_diffs else 0

# ── Print report ───────────────────────────────────────────────────────────────
DIVIDER = "=" * 70

print(f"\n{DIVIDER}")
print("NOTEBOOK CSV vs LIVE MODEL COMPARISON")
print(f"{DIVIDER}")
print(f"  Seniors matched:  {n} / {len(nb_preds)} from CSV")
if nb_only:
    print(f"  In CSV only:      {len(nb_only)}  (not in DB — check name/barangay)")
if db_only:
    print(f"  In DB only:       {len(db_only)}  (new seniors added after study)")

if n == 0:
    print("\n  [BLOCKED] No matched seniors — run ml:batch-analyze first.")
    sys.exit(1)

cluster_pct = cluster_match / n * 100
risk_pct    = risk_match    / n * 100

print(f"\n  Cluster match:    {cluster_match} / {n}  ({cluster_pct:.1f}%)")
print(f"  Risk level match: {risk_match}  / {n}  ({risk_pct:.1f}%)")
print(f"  Composite delta:  avg={avg_comp_diff:.4f}  max={max_comp_diff:.4f}")

# ── Cluster distribution comparison ───────────────────────────────────────────
print(f"\n{DIVIDER}")
print("CLUSTER DISTRIBUTION")
print(f"{DIVIDER}")
print(f"  {'Cluster':<38}  {'Notebook':>8}  {'Live':>6}  {'Diff':>5}  Status")
print(f"  {'-'*65}")
dist_ok = True
for cid in ["1", "2", "3"]:
    target = NOTEBOOK_TARGET[cid]["n"]
    actual = live_cluster_counts.get(cid, 0)
    diff   = actual - target
    # Allow ±5 for borderline seniors whose features shifted with preprocessing fix
    ok     = abs(diff) <= 5
    if not ok:
        dist_ok = False
    print(f"  C{cid} · {NOTEBOOK_TARGET[cid]['name']:<33}  {target:>8}  {actual:>6}  {diff:>+5}  {'OK' if ok else 'MISMATCH'}")

# ── Risk distribution ──────────────────────────────────────────────────────────
risk_counter = Counter(str(db_rows[k]["lv_risk"] or "").lower() for k in matched_keys)
print(f"\n{DIVIDER}")
print("RISK DISTRIBUTION (live model, training seniors only)")
print(f"{DIVIDER}")
for rl in ["high", "moderate", "low"]:
    print(f"  {rl.upper():<10}  {risk_counter.get(rl, 0):>4}")

# ── Cluster mismatches ─────────────────────────────────────────────────────────
print(f"\n{DIVIDER}")
print(f"CLUSTER MISMATCHES ({len(cluster_mismatches)} seniors)")
print(f"{DIVIDER}")
if not cluster_mismatches:
    print("  None — perfect cluster match with notebook!")
else:
    print(f"  {'Senior':<42}  {'NB':>3}  {'LV':>3}  {'NB_Risk':>8}  {'LV_Risk':>8}  {'NB_Comp':>8}  {'LV_Comp':>8}")
    print(f"  {'-'*95}")
    for m in cluster_mismatches:
        print(f"  {m['name']:<42}  C{m['nb_cluster']:>1}  C{m['lv_cluster']:>1}  "
              f"{m['nb_risk']:>8}  {m['lv_risk']:>8}  "
              f"{m['nb_comp']:>8.3f}  {m['lv_comp']:>8.3f}")

# ── Risk mismatches ────────────────────────────────────────────────────────────
print(f"\n{DIVIDER}")
print(f"RISK LEVEL MISMATCHES ({len(risk_mismatches)} seniors)")
print(f"{DIVIDER}")
if not risk_mismatches:
    print("  None — perfect risk level match with notebook!")
else:
    print(f"  {'Senior':<42}  {'NB_Risk':>8}  {'LV_Risk':>8}  {'NB_Comp':>8}  {'LV_Comp':>8}")
    print(f"  {'-'*80}")
    for m in risk_mismatches:
        print(f"  {m['name']:<42}  {m['nb_risk']:>8}  {m['lv_risk']:>8}  "
              f"{m['nb_comp']:>8.3f}  {m['lv_comp']:>8.3f}")

# ── Verdict ────────────────────────────────────────────────────────────────────
print(f"\n{DIVIDER}")
if cluster_pct >= 98 and risk_pct >= 98 and max_comp_diff < 0.10:
    verdict = "[PASS] Live model closely matches notebook — alignment verified."
elif cluster_pct >= 95 and risk_pct >= 95:
    verdict = "[WARN] Minor differences for borderline seniors — acceptable."
else:
    verdict = "[FAIL] Significant divergence from notebook — investigate."
print(verdict)
print(f"{DIVIDER}\n")

# ── Save report ────────────────────────────────────────────────────────────────
out_path = os.path.join(OUTPUT_DIR, "notebook_vs_live_report.txt")
lines = [
    "NOTEBOOK CSV vs LIVE MODEL — Full Report",
    f"Matched: {n}/{len(nb_preds)}",
    f"Cluster match: {cluster_match}/{n} ({cluster_pct:.1f}%)",
    f"Risk match: {risk_match}/{n} ({risk_pct:.1f}%)",
    f"Max composite delta: {max_comp_diff:.4f}",
    "",
    "CLUSTER DISTRIBUTION",
]
for cid in ["1","2","3"]:
    t = NOTEBOOK_TARGET[cid]["n"]
    a = live_cluster_counts.get(cid, 0)
    lines.append(f"  C{cid}: target={t}  actual={a}  diff={a-t:+d}")
lines += ["", f"CLUSTER MISMATCHES ({len(cluster_mismatches)})"]
for m in cluster_mismatches:
    lines.append(f"  {m['name']}: NB=C{m['nb_cluster']} ({m['nb_risk']}, {m['nb_comp']:.3f}) -> LV=C{m['lv_cluster']} ({m['lv_risk']}, {m['lv_comp']:.3f})")
lines += ["", f"RISK MISMATCHES ({len(risk_mismatches)})"]
for m in risk_mismatches:
    lines.append(f"  {m['name']}: NB={m['nb_risk']} ({m['nb_comp']:.3f}) -> LV={m['lv_risk']} ({m['lv_comp']:.3f})")
lines += ["", verdict]

with open(out_path, "w", encoding="utf-8") as f:
    f.write("\n".join(lines))
print(f"Full report saved to: {out_path}")
