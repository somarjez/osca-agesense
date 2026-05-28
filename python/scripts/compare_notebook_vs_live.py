"""
compare_notebook_vs_live.py
============================
Compares every senior's notebook_cache result against their live_model result.

This is the definitive post-migration check: after running
  generate_cluster_centroids.py + ml:batch-analyze --force
the live model results should match the notebook's cluster assignments
and risk levels.

Notebook target (from the study):
    C1 · High Functioning:            75 seniors  composite_risk ≈ 0.306
    C2 · Moderate / Mixed Needs:     132 seniors  composite_risk ≈ 0.399
    C3 · Low Functioning/Multi-Risk:  76 seniors  composite_risk ≈ 0.534

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\compare_notebook_vs_live.py

Saves full report to:
    python\\scripts\\audit_output\\notebook_vs_live_report.txt
"""

import os, sys, json
import pymysql
import pymysql.cursors

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "audit_output")
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

conn = pymysql.connect(
    host     = env.get("DB_HOST", "127.0.0.1"),
    port     = int(env.get("DB_PORT", 3306)),
    user     = env.get("DB_USERNAME", "root"),
    password = env.get("DB_PASSWORD", ""),
    database = env.get("DB_DATABASE", "osca_db"),
    cursorclass = pymysql.cursors.DictCursor,
)

# ── Notebook target distribution (from study) ──────────────────────────────────
NOTEBOOK_TARGET = {
    1: {"n": 75,  "name": "High Functioning",           "composite": 0.306, "ic": 4.437, "env": 3.127, "func": 3.204},
    2: {"n": 132, "name": "Moderate / Mixed Needs",     "composite": 0.399, "ic": 3.911, "env": 2.636, "func": 2.697},
    3: {"n": 76,  "name": "Low Functioning/Multi-Risk", "composite": 0.534, "ic": 2.997, "env": 2.180, "func": 2.219},
}

# ── Query notebook_cache (latest per senior) ───────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name, sc.barangay,
               r.senior_citizen_id,
               r.cluster_named_id   AS nb_cluster,
               r.cluster_name       AS nb_cluster_name,
               r.overall_risk_level AS nb_risk,
               r.composite_risk     AS nb_composite,
               r.wellbeing_score    AS nb_wellbeing,
               r.ic_score           AS nb_ic,
               r.env_score          AS nb_env,
               r.func_score         AS nb_func,
               r.qol_score          AS nb_qol
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id AND sc.deleted_at IS NULL
        WHERE r.prediction_source = 'notebook_cache'
          AND r.id = (
              SELECT MAX(id2.id) FROM ml_results id2
              WHERE id2.senior_citizen_id = r.senior_citizen_id
                AND id2.prediction_source = 'notebook_cache'
          )
        ORDER BY sc.last_name, sc.first_name
    """)
    nb_rows = {row["senior_citizen_id"]: row for row in cur.fetchall()}

# ── Query live_model (latest per senior) ──────────────────────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT r.senior_citizen_id,
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
        WHERE r.prediction_source = 'live_model'
          AND r.id = (
              SELECT MAX(id2.id) FROM ml_results id2
              WHERE id2.senior_citizen_id = r.senior_citizen_id
                AND id2.prediction_source = 'live_model'
          )
    """)
    lv_rows = {row["senior_citizen_id"]: row for row in cur.fetchall()}

conn.close()

# ── Match and compare ──────────────────────────────────────────────────────────
matched_ids = set(nb_rows.keys()) & set(lv_rows.keys())
nb_only     = set(nb_rows.keys()) - set(lv_rows.keys())
lv_only     = set(lv_rows.keys()) - set(nb_rows.keys())

print(f"\nNotebook cache seniors: {len(nb_rows)}")
print(f"Live model seniors:     {len(lv_rows)}")
print(f"Matched (both):         {len(matched_ids)}")
if nb_only:
    print(f"In notebook only:       {len(nb_only)}  (no live result yet — run ml:batch-analyze --force)")
if lv_only:
    print(f"In live only:           {len(lv_only)}  (new seniors added after study)")

# Per-senior diffs
cluster_match  = 0
risk_match     = 0
composite_diffs = []
cluster_mismatches = []
risk_mismatches    = []

live_cluster_counts = {1: 0, 2: 0, 3: 0}

for sid in sorted(matched_ids):
    nb = nb_rows[sid]
    lv = lv_rows[sid]
    name = f"{nb['first_name']} {nb['last_name']} ({nb['barangay']})"

    nb_c = int(nb["nb_cluster"] or 0)
    lv_c = int(lv["lv_cluster"] or 0)
    nb_r = str(nb["nb_risk"]  or "").lower()
    lv_r = str(lv["lv_risk"]  or "").lower()
    nb_comp = float(nb["nb_composite"] or 0)
    lv_comp = float(lv["lv_composite"] or 0)

    if lv_c in live_cluster_counts:
        live_cluster_counts[lv_c] += 1

    if nb_c == lv_c:
        cluster_match += 1
    else:
        cluster_mismatches.append({
            "name":      name,
            "nb_cluster": nb_c, "lv_cluster": lv_c,
            "nb_risk":   nb_r,  "lv_risk":    lv_r,
            "nb_comp":   nb_comp, "lv_comp":  lv_comp,
        })

    if nb_r == lv_r:
        risk_match += 1
    else:
        risk_mismatches.append({
            "name":     name,
            "nb_risk":  nb_r,  "lv_risk": lv_r,
            "nb_comp":  nb_comp, "lv_comp": lv_comp,
            "nb_cluster": nb_c, "lv_cluster": lv_c,
        })

    composite_diffs.append(abs(nb_comp - lv_comp))

n = len(matched_ids)
avg_comp_diff = sum(composite_diffs) / n if n else 0
max_comp_diff = max(composite_diffs) if composite_diffs else 0

# ── Print report ───────────────────────────────────────────────────────────────
DIVIDER = "=" * 70

print(f"\n{DIVIDER}")
print("NOTEBOOK vs LIVE MODEL COMPARISON")
print(f"{DIVIDER}")
print(f"  Seniors compared: {n}")

if n == 0:
    print()
    print("  [BLOCKED] No notebook_cache rows found in DB.")
    print("  Run the migration steps first:")
    print("    1. Set ENABLE_NOTEBOOK_OVERRIDES=true in .env")
    print("    2. Restart Flask services:  python\\start_services.ps1")
    print("    3. Seed notebook cache:     php artisan ml:repair-notebook-cache")
    print("    4. Rebuild centroids:       python\\venv\\Scripts\\python.exe python\\scripts\\generate_cluster_centroids.py")
    print("    5. Set ENABLE_NOTEBOOK_OVERRIDES=false in .env")
    print("    6. Restart Flask services:  python\\start_services.ps1")
    print("    7. Recompute all:           php artisan ml:batch-analyze --force")
    print("    8. Re-run this script.")
    print(f"\n{DIVIDER}\n")
    import sys; sys.exit(0)

print(f"  Cluster match:    {cluster_match} / {n}  ({cluster_match/n*100:.1f}%)")
print(f"  Risk level match: {risk_match}  / {n}  ({risk_match/n*100:.1f}%)")
print(f"  Composite delta:  avg={avg_comp_diff:.4f}  max={max_comp_diff:.4f}")

# ── Cluster distribution comparison ───────────────────────────────────────────
print(f"\n{DIVIDER}")
print("CLUSTER DISTRIBUTION")
print(f"{DIVIDER}")
print(f"  {'Cluster':<38}  {'Notebook':>8}  {'Live':>6}  {'Diff':>5}  {'Status'}")
print(f"  {'-'*65}")
all_match = True
for cid in [1, 2, 3]:
    target = NOTEBOOK_TARGET[cid]["n"]
    actual = live_cluster_counts.get(cid, 0)
    diff   = actual - target
    ok     = abs(diff) <= 2   # allow ±2 for Francisco Tañeda + borderline cases
    status = "OK" if ok else "MISMATCH"
    if not ok:
        all_match = False
    cname  = NOTEBOOK_TARGET[cid]["name"]
    print(f"  C{cid} · {cname:<33}  {target:>8}  {actual:>6}  {diff:>+5}  {status}")

print(f"\n  Cluster distribution: {'MATCHES NOTEBOOK (within tolerance)' if all_match else 'DOES NOT MATCH NOTEBOOK'}")

# ── Risk distribution ──────────────────────────────────────────────────────────
from collections import Counter
risk_counter = Counter()
for sid in matched_ids:
    lv = lv_rows[sid]
    risk_counter[str(lv["lv_risk"] or "").lower()] += 1

print(f"\n{DIVIDER}")
print("RISK DISTRIBUTION (live model)")
print(f"{DIVIDER}")
for rl in ["high", "moderate", "low"]:
    print(f"  {rl.upper():<10}  {risk_counter.get(rl, 0):>4}")

# ── Cluster mismatches detail ──────────────────────────────────────────────────
print(f"\n{DIVIDER}")
print(f"CLUSTER MISMATCHES ({len(cluster_mismatches)} seniors)")
print(f"{DIVIDER}")
if not cluster_mismatches:
    print("  None — perfect cluster match!")
else:
    print(f"  {'Senior':<40}  {'NB_C':>5}  {'LV_C':>5}  {'NB_Risk':>8}  {'LV_Risk':>8}  {'NB_Comp':>8}  {'LV_Comp':>8}")
    print(f"  {'-'*95}")
    for m in cluster_mismatches:
        print(f"  {m['name']:<40}  C{m['nb_cluster']:>4}  C{m['lv_cluster']:>4}  "
              f"{m['nb_risk']:>8}  {m['lv_risk']:>8}  "
              f"{m['nb_comp']:>8.3f}  {m['lv_comp']:>8.3f}")

# ── Risk mismatches detail ─────────────────────────────────────────────────────
print(f"\n{DIVIDER}")
print(f"RISK LEVEL MISMATCHES ({len(risk_mismatches)} seniors)")
print(f"{DIVIDER}")
if not risk_mismatches:
    print("  None — perfect risk level match!")
else:
    print(f"  {'Senior':<40}  {'NB_Risk':>8}  {'LV_Risk':>8}  {'NB_Comp':>8}  {'LV_Comp':>8}  {'C'}")
    print(f"  {'-'*90}")
    for m in risk_mismatches:
        print(f"  {m['name']:<40}  {m['nb_risk']:>8}  {m['lv_risk']:>8}  "
              f"{m['nb_comp']:>8.3f}  {m['lv_comp']:>8.3f}  "
              f"NB:C{m['nb_cluster']} LV:C{m['lv_cluster']}")

# ── Verdict ────────────────────────────────────────────────────────────────────
print(f"\n{DIVIDER}")
cluster_pct = cluster_match / n * 100 if n else 0
risk_pct    = risk_match / n * 100 if n else 0

if cluster_pct >= 98 and risk_pct >= 98 and max_comp_diff < 0.05:
    verdict = "[PASS] Live model matches notebook within acceptable tolerance."
elif cluster_pct >= 95 and risk_pct >= 95:
    verdict = "[WARN] Mostly aligned — minor differences in borderline seniors are acceptable."
else:
    verdict = "[FAIL] Significant divergence — run migration steps and recheck."

print(verdict)
print(f"{DIVIDER}\n")

# ── Save full report ───────────────────────────────────────────────────────────
out_path = os.path.join(OUTPUT_DIR, "notebook_vs_live_report.txt")
lines = []
lines.append("NOTEBOOK vs LIVE MODEL — Full Report")
lines.append(f"Seniors compared: {n}")
lines.append(f"Cluster match: {cluster_match}/{n} ({cluster_pct:.1f}%)")
lines.append(f"Risk match: {risk_match}/{n} ({risk_pct:.1f}%)")
lines.append(f"Max composite delta: {max_comp_diff:.4f}")
lines.append("")
lines.append("CLUSTER DISTRIBUTION")
for cid in [1,2,3]:
    t = NOTEBOOK_TARGET[cid]["n"]
    a = live_cluster_counts.get(cid, 0)
    lines.append(f"  C{cid}: target={t}  actual={a}  diff={a-t:+d}")
lines.append("")
lines.append(f"CLUSTER MISMATCHES ({len(cluster_mismatches)})")
for m in cluster_mismatches:
    lines.append(f"  {m['name']}: NB=C{m['nb_cluster']} ({m['nb_risk']}, {m['nb_comp']:.3f}) -> LV=C{m['lv_cluster']} ({m['lv_risk']}, {m['lv_comp']:.3f})")
lines.append("")
lines.append(f"RISK MISMATCHES ({len(risk_mismatches)})")
for m in risk_mismatches:
    lines.append(f"  {m['name']}: NB={m['nb_risk']} ({m['nb_comp']:.3f}) -> LV={m['lv_risk']} ({m['lv_comp']:.3f})")
lines.append("")
lines.append(verdict)

with open(out_path, "w", encoding="utf-8") as f:
    f.write("\n".join(lines))
print(f"Full report saved to: {out_path}")
