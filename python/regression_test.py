"""
Regression test: assert that the 283 seed seniors still match their locked
baseline scores within tolerance.

Run after any code change or re-seeding:
    python\venv\Scripts\python.exe python\regression_test.py

Exit 0 = all checks passed.
Exit 1 = one or more checks failed (scores drifted beyond tolerance).

BASELINE was locked on 2026-05-20 with model v1.1.0, threshold HIGH >= 0.45.
To update the baseline after an intentional model retrain, run with --update:
    python\venv\Scripts\python.exe python\regression_test.py --update
"""
import os, sys, json, csv

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# ── Load .env ─────────────────────────────────────────────────────────────────
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

BASELINE_PATH = os.path.join(BASE_DIR, "models", "regression_baseline.json")
UPDATE_MODE   = "--update" in sys.argv

# ── Fetch current latest results for all active seniors ───────────────────────
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.first_name, sc.last_name,
               r.overall_risk_level,
               r.cluster_named_id,
               ROUND(r.composite_risk, 4)  AS composite_risk,
               ROUND(r.wellbeing_score, 4) AS wellbeing_score
        FROM ml_results r
        JOIN senior_citizens sc ON sc.id = r.senior_citizen_id
            AND sc.deleted_at IS NULL
        JOIN (
            SELECT senior_citizen_id, MAX(id) AS max_id
            FROM ml_results GROUP BY senior_citizen_id
        ) lat ON r.id = lat.max_id
        ORDER BY sc.last_name, sc.first_name
    """)
    rows = cur.fetchall()
conn.close()

current = {
    f"{r['first_name'].strip().lower()}|{r['last_name'].strip().lower()}": {
        "risk_level":     (r["overall_risk_level"] or "").upper(),
        "cluster":        r["cluster_named_id"],
        "composite_risk": float(r["composite_risk"] or 0),
        "wellbeing":      float(r["wellbeing_score"] or 0),
    }
    for r in rows
}

# ── Update mode: write current state as the new baseline ──────────────────────
if UPDATE_MODE:
    os.makedirs(os.path.dirname(BASELINE_PATH), exist_ok=True)
    with open(BASELINE_PATH, "w", encoding="utf-8") as f:
        import datetime
        json.dump({"seniors": current, "_meta": {
            "locked_on": datetime.date.today().isoformat(),
            "model_version": "1.1.1",
            "senior_count": len(current),
            "note": "v1.1.1 pre-migration snapshot. Re-lock after ml:batch-analyze --force",
        }}, f, indent=2)
    print(f"[OK] Baseline updated: {len(current)} seniors -> {BASELINE_PATH}")
    sys.exit(0)

# ── Load existing baseline ────────────────────────────────────────────────────
if not os.path.exists(BASELINE_PATH):
    print(f"[ERROR] No baseline found at {BASELINE_PATH}")
    print(f"        Run with --update to create it from current DB state.")
    sys.exit(1)

with open(BASELINE_PATH, encoding="utf-8") as f:
    baseline_data = json.load(f)

baseline = baseline_data["seniors"]
meta     = baseline_data.get("_meta", {})

print(f"\n=== Regression Test  (baseline: {meta.get('locked_on','?')}  model: {meta.get('model_version','?')}) ===")
print(f"  Baseline seniors: {len(baseline)}   Current seniors: {len(current)}")

# ── Tolerances ────────────────────────────────────────────────────────────────
# composite_risk may vary by at most ±0.005 (floating-point / ordering noise).
# Risk level and cluster must match exactly.
COMPOSITE_TOL = 0.005

failures = []
missing  = []
new_seniors = []

for key, base in baseline.items():
    cur_row = current.get(key)
    if cur_row is None:
        missing.append(key)
        continue
    errs = []
    if cur_row["risk_level"] != base["risk_level"]:
        errs.append(f"risk_level {base['risk_level']} -> {cur_row['risk_level']}")
    if cur_row["cluster"] != base["cluster"]:
        errs.append(f"cluster C{base['cluster']} -> C{cur_row['cluster']}")
    drift = abs(cur_row["composite_risk"] - base["composite_risk"])
    if drift > COMPOSITE_TOL:
        errs.append(f"composite_risk {base['composite_risk']:.4f} -> {cur_row['composite_risk']:.4f}  (drift={drift:.4f})")
    if errs:
        name = " ".join(p.capitalize() for p in key.replace("|", " ").split())
        failures.append((name, errs))

for key in current:
    if key not in baseline:
        new_seniors.append(key)

# ── Distribution check ────────────────────────────────────────────────────────
base_dist = {}
curr_dist = {}
for v in baseline.values():
    base_dist[v["risk_level"]] = base_dist.get(v["risk_level"], 0) + 1
for v in current.values():
    curr_dist[v["risk_level"]] = curr_dist.get(v["risk_level"], 0) + 1

print(f"\n  Risk distribution:")
print(f"  {'Level':<12}  {'Baseline':>10}  {'Current':>10}  {'Diff':>6}")
print("  " + "-" * 44)
for lv in ["HIGH", "MODERATE", "LOW"]:
    b = base_dist.get(lv, 0)
    c = curr_dist.get(lv, 0)
    flag = "  *** CHANGED" if b != c else ""
    print(f"  {lv:<12}  {b:>10}  {c:>10}  {c-b:>+6}{flag}")

if new_seniors:
    print(f"\n  New seniors not in baseline: {len(new_seniors)}  (expected — new enrollments are scored fresh)")

if missing:
    print(f"\n  [WARN] {len(missing)} baseline seniors missing from DB (archived?):")
    for k in missing[:10]:
        print(f"    - {k}")

# ── Result ────────────────────────────────────────────────────────────────────
print(f"\n  Score-level failures: {len(failures)}")
if failures:
    print(f"\n  {'Senior':<32}  Changes")
    print("  " + "-" * 70)
    for name, errs in sorted(failures):
        print(f"  {name:<32}  {';  '.join(errs)}")

print("\n" + "=" * 60)
if failures:
    print("RESULT: FAILED — existing senior scores have drifted.")
    print("  If this was intentional (model retrain / threshold change),")
    print("  run with --update to lock the new baseline.")
    print("=" * 60 + "\n")
    sys.exit(1)
else:
    print("RESULT: PASSED — all existing senior scores are stable.")
    print("=" * 60 + "\n")
    sys.exit(0)
