"""
validate_system.py
==================
Comprehensive, MODEL-CENTRIC validation of the live OSCA pipeline.

Separates the cluster-mismatch question into its independent causes and
validates every output category (feature engineering, risk, clustering, XAI,
recommendations) plus determinism — WITHOUT treating the notebook clustering
as the sole source of truth.

Evidence gathered (per 283 baseline seniors):
  1. FEATURE-ENGINEERING FIDELITY  live preprocess WHO/section scores
        vs notebook senior_predictions.csv (ic_score, env_score, func_score,
        qol_score, sec5_eco_stability, sec5_real_asset_score,
        sec5_movable_asset_score, overall_wellbeing)
  2. RISK FIDELITY                 live ic/env/func/composite risk vs notebook
  3. RISK-LEVEL DISTRIBUTION       live LOW/MODERATE/HIGH counts + match rate
  4. CLUSTERING                    live named_id vs notebook cluster_id, AND
        for mismatches: nearest vs 2nd-nearest centroid margin (boundary test)
  5. CLUSTER COHERENCE             per-cluster mean composite risk monotonicity
  6. XAI                           presence + structure on every senior
  7. RECOMMENDATIONS               coverage (every senior gets >=1)
  8. DETERMINISM                   same payload inferred 3x -> identical

Run from repo root (services must be up on 5001/5002):
    python\\venv\\Scripts\\python.exe python\\scripts\\validate_system.py
"""

import csv
import json
import os
import pickle
import re
import sys
import unicodedata
import urllib.error
import urllib.request
from collections import defaultdict
from datetime import date, datetime

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

try:
    import numpy as np
    import pandas as pd
    import pymysql
    import pymysql.cursors
except ImportError as e:
    print(f"[ERROR] missing dependency: {e}")
    sys.exit(1)

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT  = os.path.dirname(os.path.dirname(SCRIPT_DIR))
BASE_DIR   = os.path.dirname(REPO_ROOT)
MODEL_DIR  = os.path.join(REPO_ROOT, "python", "models")
PRED_CSV   = os.path.join(BASE_DIR, "osca_output", "predictions", "senior_predictions.csv")
PREPROCESS = "http://127.0.0.1:5001/preprocess"
INFER      = "http://127.0.0.1:5002/infer"

# Tolerances
TOL_SCORE = 0.05   # WHO 1-5 domain scores
TOL_UNIT  = 0.02   # 0-1 section/risk scores


# ── helpers ───────────────────────────────────────────────────────────────────
def _norm(s):
    s = unicodedata.normalize("NFC", str(s or ""))
    s = s.replace(chr(0xc3) + chr(0xb1), "n").replace(chr(0xc3) + chr(0x91), "n")
    s = s.replace(chr(0xf1), "n").replace(chr(0xd1), "n")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"[^a-z0-9]+", "", s.lower())

def _key(f, last, b): return f"{_norm(f)}|{_norm(last)}|{_norm(b)}"

def _read_dotenv(name):
    for cand in [os.path.join(REPO_ROOT, ".env"), os.path.join(BASE_DIR, ".env")]:
        if os.path.exists(cand):
            for line in open(cand, encoding="utf-8"):
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, _, v = line.partition("=")
                    if k.strip() == name:
                        return v.strip().strip('"').strip("'")
    return ""

def _post(url, payload):
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=40) as r:
        return json.loads(r.read())

def _f(v, d=0.0):
    try:
        return float(v)
    except Exception:
        return d

def _parse_json_col(v):
    if v is None:
        return []
    if isinstance(v, (list, dict)):
        return v
    try:
        return json.loads(v)
    except Exception:
        return []

def _age(dob, ref=None):
    # Mirror the live path: age anchored to the immutable survey_date, not today.
    if dob is None:
        return 70
    if isinstance(dob, str):
        dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    if ref is None:
        ref = date.today()
    elif isinstance(ref, str):
        ref = datetime.strptime(ref[:10], "%Y-%m-%d").date()
    elif isinstance(ref, datetime):
        ref = ref.date()
    return ref.year - dob.year - ((ref.month, ref.day) < (dob.month, dob.day))


# ── load notebook ground truth ─────────────────────────────────────────────────
nb = {}
with open(PRED_CSV, encoding="utf-8-sig") as fh:
    for row in csv.DictReader(fh):
        nb[_key(row["first_name"], row["last_name"], row["barangay"])] = row
print(f"Notebook predictions: {len(nb)} seniors")

# ── DB query ────────────────────────────────────────────────────────────────────
conn = pymysql.connect(
    host=_read_dotenv("DB_HOST") or "127.0.0.1", port=int(_read_dotenv("DB_PORT") or 3306),
    user=_read_dotenv("DB_USERNAME") or "root", password=_read_dotenv("DB_PASSWORD") or "",
    database=_read_dotenv("DB_DATABASE") or "osca_db", cursorclass=pymysql.cursors.DictCursor,
)
with conn.cursor() as cur:
    cur.execute("""
        SELECT sc.id, sc.first_name, sc.last_name, sc.barangay, sc.date_of_birth,
               sc.gender, sc.marital_status, sc.educational_attainment,
               sc.monthly_income_range, sc.num_children, sc.num_working_children,
               sc.household_size, sc.child_financial_support, sc.spouse_working,
               sc.income_source, sc.real_assets, sc.movable_assets, sc.living_with,
               sc.household_condition, sc.community_service, sc.specialization,
               sc.medical_concern, sc.dental_concern, sc.optical_concern,
               sc.hearing_concern, sc.social_emotional_concern, sc.healthcare_difficulty,
               sc.has_medical_checkup, sc.checkup_schedule, qs.survey_date,
               qs.a1_enjoy_life, qs.a2_life_satisfaction, qs.a3_future_outlook, qs.a4_meaningfulness,
               qs.b1_physical_energy, qs.b2_pain_discomfort, qs.b3_health_self_care,
               qs.b4_health_outside, qs.b5_mobility,
               qs.c1_happiness, qs.c2_calm_peace, qs.c3_loneliness, qs.c4_confidence,
               qs.d1_independence, qs.d2_time_control, qs.d3_life_control, qs.d4_income_limits,
               qs.e1_social_support, qs.e2_close_person, qs.e3_community_opportunities,
               qs.e4_participation, qs.e5_respect,
               qs.f1_home_safety, qs.f2_neighborhood_safety, qs.f3_service_access, qs.f4_home_comfort,
               qs.g1_household_expenses, qs.g2_medical_afford, qs.g3_personal_wants,
               qs.h1_belief_comfort, qs.h2_belief_practice
        FROM senior_citizens sc
        JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
        WHERE sc.deleted_at IS NULL
          AND qs.id = (SELECT MAX(q2.id) FROM qol_surveys q2 WHERE q2.senior_citizen_id = sc.id)
        ORDER BY sc.id
    """)
    db_rows = cur.fetchall()
conn.close()
print(f"DB seniors with QoL: {len(db_rows)}")


def build_raw(row):
    return {
        "senior_id": row["id"], "first_name": row["first_name"], "last_name": row["last_name"],
        "barangay": row["barangay"], "age": _age(row["date_of_birth"], row.get("survey_date")), "gender": row["gender"],
        "marital_status": row["marital_status"], "educational_attainment": row["educational_attainment"],
        "monthly_income_range": row["monthly_income_range"], "num_children": row["num_children"] or 0,
        "num_working_children": row["num_working_children"] or 0, "household_size": row["household_size"] or 1,
        "child_financial_support": row["child_financial_support"], "spouse_working": row["spouse_working"],
        "income_source": _parse_json_col(row["income_source"]), "real_assets": _parse_json_col(row["real_assets"]),
        "movable_assets": _parse_json_col(row["movable_assets"]), "living_with": _parse_json_col(row["living_with"]),
        "household_condition": _parse_json_col(row["household_condition"]),
        "community_service": _parse_json_col(row["community_service"]),
        "specialization": _parse_json_col(row["specialization"]),
        "medical_concern": _parse_json_col(row["medical_concern"]),
        "dental_concern": _parse_json_col(row["dental_concern"]),
        "optical_concern": _parse_json_col(row["optical_concern"]),
        "hearing_concern": _parse_json_col(row["hearing_concern"]),
        "social_emotional_concern": _parse_json_col(row["social_emotional_concern"]),
        "healthcare_difficulty": _parse_json_col(row["healthcare_difficulty"]),
        "has_medical_checkup": bool(row["has_medical_checkup"]) and row["checkup_schedule"] != "No Follow-up",
        "qol_responses": {
            "qol_enjoy_life": row["a1_enjoy_life"], "qol_life_satisfaction": row["a2_life_satisfaction"],
            "qol_future_outlook": row["a3_future_outlook"], "qol_meaningfulness": row["a4_meaningfulness"],
            "phy_energy": row["b1_physical_energy"], "phy_pain_r": row["b2_pain_discomfort"],
            "phy_health_limit_r": row["b3_health_self_care"], "phy_mobility_outside": row["b4_health_outside"],
            "phy_mobility_indoor": row["b5_mobility"], "psych_happiness": row["c1_happiness"],
            "psych_peace": row["c2_calm_peace"], "psych_lonely_r": row["c3_loneliness"],
            "psych_confidence": row["c4_confidence"], "func_independence": row["d1_independence"],
            "func_autonomy": row["d2_time_control"], "func_control": row["d3_life_control"],
            "env_income_limit_r": row["d4_income_limits"], "soc_social_support": row["e1_social_support"],
            "soc_close_friend": row["e2_close_person"], "soc_participation": row["e4_participation"],
            "soc_opportunity": row["e3_community_opportunities"], "soc_respect": row["e5_respect"],
            "env_safe_home": row["f1_home_safety"], "env_safe_neighborhood": row["f2_neighborhood_safety"],
            "env_service_access": row["f3_service_access"], "env_home_comfort": row["f4_home_comfort"],
            "env_fin_medical": row["g2_medical_afford"], "env_fin_household": row["g1_household_expenses"],
            "env_fin_personal": row["g3_personal_wants"], "spi_belief_comfort": row["h1_belief_comfort"],
            "spi_belief_practice": row["h2_belief_practice"],
        },
    }


# ── load centroids + scaler for boundary-margin analysis ──────────────────────
with open(os.path.join(MODEL_DIR, "scaler.pkl"), "rb") as f:
    scaler = pickle.load(f)
with open(os.path.join(MODEL_DIR, "feature_list.json"), encoding="utf-8") as f:
    feat31 = json.load(f)
with open(os.path.join(MODEL_DIR, "cluster_centroids_scaled.json"), encoding="utf-8") as f:
    cdoc = json.load(f)
centroids = {int(k): np.array(v, dtype=np.float64) for k, v in cdoc["centroids"].items()}
cent_feats = cdoc["feature_names"]
scaler_names = list(scaler.feature_names_in_)
scaler_idx = {fn: i for i, fn in enumerate(scaler_names)}

with open(os.path.join(MODEL_DIR, "cluster_metadata.json"), encoding="utf-8-sig") as f:
    cluster_meta = json.load(f)

def cluster_name(cid):
    return cluster_meta.get(str(cid), {}).get("name", f"Cluster {cid}")

CSV_EXPORT_DIR = os.path.join(REPO_ROOT, "storage", "app", "ml_validation")
CSV_EXPORT_PATH = os.path.join(CSV_EXPORT_DIR, "cache_vs_live_model_comparison.csv")

def margin(feature_map):
    """Return (nearest_id, 2nd_id, margin) using same scaled-31 nearest-centroid logic."""
    srow = [_f(feature_map.get(fn, 0.0)) for fn in scaler_names]
    full = scaler.transform(pd.DataFrame([srow], columns=scaler_names))[0]
    vec = np.array([full[scaler_idx[fn]] if fn in scaler_idx else 0.0 for fn in cent_feats], dtype=np.float64)
    dists = sorted(((float(np.sum((vec - c) ** 2)), cid) for cid, c in centroids.items()))
    nearest, second = dists[0], dists[1]
    rel = (second[0] - nearest[0]) / (second[0] + 1e-9)
    return nearest[1], second[1], rel


# ── main loop ───────────────────────────────────────────────────────────────────
print("\nRunning live pipeline for matched seniors...\n")
res = []
errors = 0
for row in db_rows:
    k = _key(row["first_name"], row["last_name"], row["barangay"])
    g = nb.get(k)
    if g is None:
        continue
    try:
        pp = _post(PREPROCESS, build_raw(row))
        if pp.get("status") != "success":
            raise ValueError(pp.get("error", "preprocess fail"))
        inf = _post(INFER, pp)
        if inf.get("status") != "success":
            raise ValueError(inf.get("error", "infer fail"))
        fm = pp.get("feature_map", {}) or inf.get("feature_map", {})
        sec = inf.get("section_scores", {}) or pp.get("section_scores", {})
        who = inf.get("who_scores", {})
        rs  = inf.get("risk_scores", {})
        near, second, mrg = (None, None, None)
        if fm:
            try:
                near, second, mrg = margin(fm)
            except Exception:
                pass
        res.append({
            "id": row["id"], "key": k,
            "full_name": f"{row['first_name']} {row['last_name']}".strip(),
            "barangay": row["barangay"],
            "age": _age(row["date_of_birth"], row.get("survey_date")),
            "live_cluster": int(inf["cluster"]["named_id"]),
            "nb_cluster": int(_f(g["cluster_id"])),
            "live_risklvl": str(inf["risk_levels"]["overall"]).upper(),
            "nb_risklvl": str(g["risk_level"]).strip().upper(),
            # feature-engineering fidelity (WHO + section)
            "d_ic_score":   abs(_f(who.get("ic_score")) - _f(g["ic_score"])),
            "d_env_score":  abs(_f(who.get("env_score")) - _f(g["env_score"])),
            "d_func_score": abs(_f(who.get("func_score")) - _f(g["func_score"])),
            "d_qol_score":  abs(_f(who.get("qol_score")) - _f(g["qol_score"])),
            "d_eco":     abs(_f(sec.get("sec5_eco_stability")) - _f(g["sec5_eco_stability"])),
            "d_realast": abs(_f(sec.get("sec5_real_asset_score")) - _f(g["sec5_real_asset_score"])),
            "d_movast":  abs(_f(sec.get("sec5_movable_asset_score")) - _f(g["sec5_movable_asset_score"])),
            "d_wellb":   abs(_f(rs.get("wellbeing_score")) - _f(g["overall_wellbeing"])),
            # risk fidelity
            "d_ic_risk":   abs(_f(rs.get("ic_risk")) - _f(g["ic_risk"])),
            "d_env_risk":  abs(_f(rs.get("env_risk")) - _f(g["env_risk"])),
            "d_func_risk": abs(_f(rs.get("func_risk")) - _f(g["func_risk"])),
            "d_comp":      abs(_f(rs.get("composite_risk")) - _f(g["composite_risk"])),
            "live_comp": _f(rs.get("composite_risk")),
            # xai + recs
            "has_xai": bool(inf.get("xai")),
            "n_recs": len(inf.get("recommendations", []) or []),
            # boundary margin
            "near": near, "second": second, "margin": mrg,
        })
        if len(res) % 50 == 0:
            print(f"  ... {len(res)} processed")
    except Exception as e:
        errors += 1
        if errors <= 5:
            print(f"  ERROR id={row['id']}: {e}")

n = len(res)
print(f"\nProcessed {n} seniors ({errors} errors)\n")
if n == 0:
    sys.exit(1)


def pct_within(field, tol):
    ok = sum(1 for r in res if r[field] <= tol)
    return ok, ok / n * 100, max(r[field] for r in res), sum(r[field] for r in res) / n

def report_block(title, fields, tol):
    print(title)
    for f, label in fields:
        ok, pc, mx, mn = pct_within(f, tol)
        icon = "OK " if pc >= 99 else ("~  " if pc >= 90 else "XX ")
        print(f"  [{icon}] {label:34s} within {tol}: {ok}/{n} ({pc:.1f}%)  max_delta={mx:.4f} mean={mn:.4f}")

print("=" * 72)
print(f"OSCA SYSTEM VALIDATION — {n} seniors")
print("=" * 72)

print("\n[1] FEATURE-ENGINEERING FIDELITY (live preprocess vs notebook computed)")
report_block("  WHO domain scores (1-5 scale):", [
    ("d_ic_score", "IC score"), ("d_env_score", "Environment score"),
    ("d_func_score", "Functional score"), ("d_qol_score", "QoL score"),
], TOL_SCORE)
report_block("  Section scores (0-1 scale):", [
    ("d_eco", "sec5_eco_stability"), ("d_realast", "sec5_real_asset_score"),
    ("d_movast", "sec5_movable_asset_score"), ("d_wellb", "overall_wellbeing"),
], TOL_UNIT)

print("\n[2] RISK FIDELITY (live GBR/RFR vs notebook, 0-1 scale)")
report_block("  Risk scores:", [
    ("d_ic_risk", "IC risk"), ("d_env_risk", "Environment risk"),
    ("d_func_risk", "Functional risk"), ("d_comp", "Composite risk"),
], TOL_UNIT)

print("\n[3] RISK-LEVEL DISTRIBUTION")
live_dist = defaultdict(int)
nb_dist = defaultdict(int)
rl_match = 0
for r in res:
    live_dist[r["live_risklvl"]] += 1
    nb_dist[r["nb_risklvl"]] += 1
    if r["live_risklvl"] == r["nb_risklvl"]:
        rl_match += 1
for lvl in ["LOW", "MODERATE", "HIGH"]:
    print(f"  {lvl:9s} live={live_dist[lvl]:4d}   notebook={nb_dist[lvl]:4d}")
print(f"  Risk-level match: {rl_match}/{n} ({rl_match/n*100:.1f}%)")

print("\n[4] CLUSTERING (live nearest-centroid vs notebook UMAP+KMeans)")
cl_match = sum(1 for r in res if r["live_cluster"] == r["nb_cluster"])
mism = [r for r in res if r["live_cluster"] != r["nb_cluster"]]
print(f"  Cluster match: {cl_match}/{n} ({cl_match/n*100:.1f}%)")
print("  NOTE: 100% is impossible deterministically — notebook used UMAP+KMeans")
print("        (non-reproducible per-record). Mismatches should be boundary cases.")
if mism:
    margins = [r["margin"] for r in mism if r["margin"] is not None]
    matched_margins = [r["margin"] for r in res if r["live_cluster"] == r["nb_cluster"] and r["margin"] is not None]
    if margins:
        print(f"  Mismatch centroid margin (rel gap nearest vs 2nd): "
              f"mean={sum(margins)/len(margins):.4f} max={max(margins):.4f}")
    if matched_margins:
        print(f"  Match    centroid margin: mean={sum(matched_margins)/len(matched_margins):.4f}")
    print("  -> If mismatch margins << match margins, mismatches are boundary cases (expected).")

print("\n[5] CLUSTER COHERENCE (model-internal — risk should rise with cluster id)")
by_cluster = defaultdict(list)
for r in res:
    by_cluster[r["live_cluster"]].append(r["live_comp"])
prev = -1
monotonic = True
for cid in sorted(by_cluster):
    m = sum(by_cluster[cid]) / len(by_cluster[cid])
    flag = ""
    if m < prev:
        monotonic = False
        flag = "  <-- NOT monotonic"
    print(f"  cluster {cid}: n={len(by_cluster[cid]):4d}  mean composite risk={m:.4f}{flag}")
    prev = m
print(f"  Monotonic risk by cluster: {'YES (coherent)' if monotonic else 'NO (investigate)'}")

print("\n[6] XAI COVERAGE")
xai_ok = sum(1 for r in res if r["has_xai"])
print(f"  Seniors with XAI data: {xai_ok}/{n} ({xai_ok/n*100:.1f}%)")

print("\n[7] RECOMMENDATIONS COVERAGE")
rec_ok = sum(1 for r in res if r["n_recs"] > 0)
print(f"  Seniors with >=1 recommendation: {rec_ok}/{n} ({rec_ok/n*100:.1f}%)")
print(f"  Mean recommendations per senior: {sum(r['n_recs'] for r in res)/n:.1f}")

# ── [8] determinism ─────────────────────────────────────────────────────────────
print("\n[8] DETERMINISM (same payload inferred 3x -> identical)")
sample = db_rows[:5]
det_ok = True
for row in sample:
    pp = _post(PREPROCESS, build_raw(row))
    sigs = set()
    for _ in range(3):
        inf = _post(INFER, pp)
        sig = (inf["cluster"]["named_id"], round(_f(inf["risk_scores"]["composite_risk"]), 6),
               json.dumps(inf.get("xai", {}).get("ic", {}), sort_keys=True))
        sigs.add(sig)
    status = "stable" if len(sigs) == 1 else "VARIES"
    if len(sigs) != 1:
        det_ok = False
    print(f"  senior {row['id']}: {status} ({len(sigs)} distinct result(s) over 3 runs)")
print(f"  Determinism: {'PASS — identical across repeated runs' if det_ok else 'FAIL — non-deterministic!'}")

print("\n" + "=" * 72)
print("SUMMARY")
print("=" * 72)
fe_ok = all(pct_within(f, TOL_SCORE)[1] >= 95 for f in ["d_ic_score","d_env_score","d_func_score","d_qol_score"]) and \
        all(pct_within(f, TOL_UNIT)[1] >= 95 for f in ["d_eco","d_realast","d_movast","d_wellb"])
risk_ok = all(pct_within(f, TOL_UNIT)[1] >= 95 for f in ["d_ic_risk","d_env_risk","d_func_risk","d_comp"])
fe_label = "PASS" if fe_ok else "CHECK"
print(f"  Feature engineering fidelity : {fe_label}  (live preprocess reproduces notebook features)")
print(f"  Risk model fidelity          : {'PASS' if risk_ok else 'CHECK'}  (live GBR/RFR reproduces notebook risk)")
print(f"  Risk-level match             : {rl_match/n*100:.1f}%")
print(f"  Cluster match (vs UMAP)      : {cl_match/n*100:.1f}%  (deterministic ceiling)")
print(f"  Cluster coherence            : {'PASS' if monotonic else 'CHECK'}")
print(f"  XAI coverage                 : {xai_ok/n*100:.1f}%")
print(f"  Recommendation coverage      : {rec_ok/n*100:.1f}%")
print(f"  Determinism                  : {'PASS' if det_ok else 'FAIL'}")
print("=" * 72)

# ── Export cache_vs_live_model_comparison.csv (System Validation Pillar 5) ────
os.makedirs(CSV_EXPORT_DIR, exist_ok=True)
MARGIN_BOUNDARY = 0.10  # relative gap nearest-vs-2nd-nearest centroid below this = "boundary case"
with open(CSV_EXPORT_PATH, "w", newline="", encoding="utf-8") as fh:
    writer = csv.writer(fh)
    writer.writerow([
        "full_name", "barangay", "age",
        "notebook_cluster_id", "notebook_cluster_name",
        "live_cluster_id", "live_cluster_name", "cluster_match",
        "notebook_risk_level", "live_risk_level", "risk_match",
        "risk_difference", "likely_reason",
    ])
    for r in res:
        c_match = r["live_cluster"] == r["nb_cluster"]
        r_match = r["live_risklvl"] == r["nb_risklvl"]
        if c_match and r_match:
            reason = "match"
        elif not c_match and r["margin"] is not None and r["margin"] < MARGIN_BOUNDARY:
            reason = "boundary case (tight centroid margin)"
        elif not c_match:
            reason = "cluster mismatch"
        else:
            reason = "risk variance (out-of-sample)"
        writer.writerow([
            r["full_name"], r["barangay"], r["age"],
            r["nb_cluster"], cluster_name(r["nb_cluster"]),
            r["live_cluster"], cluster_name(r["live_cluster"]), "YES" if c_match else "NO",
            r["nb_risklvl"], r["live_risklvl"], "YES" if r_match else "NO",
            f"{r['d_comp']:.6f}", reason,
        ])
print(f"\nWrote {n} rows -> {CSV_EXPORT_PATH}")
