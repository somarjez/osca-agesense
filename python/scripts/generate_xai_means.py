"""
generate_xai_means.py
=====================
Computes per-cluster means for all 52 ml_risk_features using the notebook's
cluster labels (from regression_baseline.json) and the DB senior data.
Writes python/models/cluster_feature_means.json.

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\generate_xai_means.py
"""
import json
import os
import pickle
import sys
import warnings
from collections import defaultdict
from datetime import date, datetime

warnings.filterwarnings("ignore")

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT  = os.path.dirname(os.path.dirname(SCRIPT_DIR))
MODEL_DIR  = os.path.join(REPO_ROOT, "python", "models")
SERVICES   = os.path.join(REPO_ROOT, "python", "services")

sys.path.insert(0, SERVICES)
import numpy as np  # noqa: E402
import pymysql  # noqa: E402
import pymysql.cursors  # noqa: E402
from preprocess_service import preprocess as _preprocess  # noqa: E402


def _read_dotenv(name):
    for cand in [os.path.join(REPO_ROOT, ".env"),
                 os.path.join(os.path.dirname(REPO_ROOT), ".env")]:
        if os.path.exists(cand):
            for line in open(cand, encoding="utf-8"):
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, _, v = line.partition("=")
                    if k.strip() == name:
                        return v.strip().strip('"').strip("'")
    return ""

def _parse_json_col(val):
    if val is None:
        return []
    if isinstance(val, (list, dict)):
        return val
    try:
        return json.loads(val)
    except Exception:
        return []

def _compute_age(dob, ref=None):
    # Age anchored to the survey date (immutable) so means match the live path,
    # which computes age from date_of_birth relative to survey_date (not today).
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

# Load baseline (cluster labels for 283 seniors)
baseline_path = os.path.join(MODEL_DIR, "regression_baseline_k4.json")
baseline = {row["key"]: row for row in json.load(open(baseline_path, encoding="utf-8"))}

# Load 52 ml_risk_features order
risk_features = json.load(open(os.path.join(MODEL_DIR, "ml_risk_features.json"), encoding="utf-8"))
print(f"Risk features: {len(risk_features)}")

import re  # noqa: E402
import unicodedata  # noqa: E402


def _norm(s):
    s = unicodedata.normalize("NFC", str(s or ""))
    s = s.replace("\xf1", "n").replace("\xd1", "n")
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"[^a-z0-9]+", "", s.lower())
def _key(f, last, b): return f"{_norm(f)}|{_norm(last)}|{_norm(b)}"

conn = pymysql.connect(
    host=_read_dotenv("DB_HOST") or "127.0.0.1",
    port=int(_read_dotenv("DB_PORT") or 3306),
    user=_read_dotenv("DB_USERNAME") or "root",
    password=_read_dotenv("DB_PASSWORD") or "",
    database=_read_dotenv("DB_DATABASE") or "osca_db",
    cursorclass=pymysql.cursors.DictCursor,
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
print(f"DB seniors: {len(db_rows)}")

cluster_vectors = defaultdict(list)   # {named_id: [[52 values], ...]}
skipped = 0

for row in db_rows:
    k = _key(row["first_name"], row["last_name"], row["barangay"])
    nb = baseline.get(k)
    if nb is None:
        skipped += 1
        continue
    named_id = nb["cluster_named_id"]
    raw = {
        "senior_id": row["id"], "first_name": row["first_name"],
        "last_name": row["last_name"], "barangay": row["barangay"],
        "age": _compute_age(row["date_of_birth"], row.get("survey_date")),
        "gender": row["gender"], "marital_status": row["marital_status"],
        "educational_attainment": row["educational_attainment"],
        "monthly_income_range": row["monthly_income_range"],
        "num_children": row["num_children"] or 0,
        "num_working_children": row["num_working_children"] or 0,
        "household_size": row["household_size"] or 1,
        "child_financial_support": row["child_financial_support"],
        "spouse_working": row["spouse_working"],
        "income_source": _parse_json_col(row["income_source"]),
        "real_assets": _parse_json_col(row["real_assets"]),
        "movable_assets": _parse_json_col(row["movable_assets"]),
        "living_with": _parse_json_col(row["living_with"]),
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
            "qol_enjoy_life": row["a1_enjoy_life"],
            "qol_life_satisfaction": row["a2_life_satisfaction"],
            "qol_future_outlook": row["a3_future_outlook"],
            "qol_meaningfulness": row["a4_meaningfulness"],
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
    try:
        result = _preprocess(raw)
        fm = result.get("feature_map") or {}
        vec = [float(fm.get(f, 0.0)) for f in risk_features]
        cluster_vectors[named_id].append(vec)
    except Exception as exc:
        skipped += 1
        if skipped <= 3:
            print(f"  WARN: skipped id={row['id']}: {exc}")

total = sum(len(v) for v in cluster_vectors.values())
print(f"Processed {total} seniors ({skipped} skipped)")

# Compute means per cluster
means = {}
global_vecs = []
for cid in sorted(cluster_vectors.keys()):
    arr = np.array(cluster_vectors[cid], dtype=np.float64)
    cluster_mean = arr.mean(axis=0).tolist()
    means[str(cid)] = dict(zip(risk_features, cluster_mean))
    global_vecs.extend(cluster_vectors[cid])
    print(f"  Cluster {cid}: {len(cluster_vectors[cid])} seniors")

# Global mean (fallback when named_id not in means)
global_arr = np.array(global_vecs, dtype=np.float64)
global_mean = dict(zip(risk_features, global_arr.mean(axis=0).tolist()))

# ── Feature effect signs ──────────────────────────────────────────────────────
# feature_importances_ are unsigned, so importance x deviation only tells us
# "above/below cluster average", not "raises/lowers risk". To make the XAI
# direction mean "raises/lowers risk", we compute the SIGN of each feature's
# effect on the GBR's predicted risk: correlate each feature column against the
# model's predictions across all 283 seniors.
#   correlation > 0  → higher feature value associates with higher risk → +1
#   correlation < 0  → higher feature value associates with lower risk  → -1
# In _compute_xai:  risk_contrib = importance x (value - mean) x effect_sign
#
# CAVEAT: this correlation is observational and can be flipped by reverse
# causation / confounding — e.g. seniors who already have a chronic condition
# are more likely to have a regular check-up, so "has check-up" correlates
# positively with risk in the sample even though preventive care is
# protective. inference_service.py applies a _CLINICAL_EFFECT_SIGNS override
# at runtime for such features regardless of what's written here; we bake the
# same override into the artifact so it stays consistent on disk. Keep this
# list in sync with _CLINICAL_EFFECT_SIGNS in
# python/services/inference_service.py.
_CLINICAL_EFFECT_SIGNS = {
    "checkup_enc":              -1,  # regular preventive check-up — protective
    "has_pension":               -1,  # income security — protective
    "income_enc":                -1,  # higher income bracket — protective
    "community_service_count":   -1,  # social participation / active ageing — protective
}
print("\nComputing feature effect signs...")

def _load_gbr(name):
    path = os.path.join(MODEL_DIR, name)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)

_GBR_BY_DOMAIN = {
    "ic":   _load_gbr("gbr_ic_risk.pkl"),
    "env":  _load_gbr("gbr_env_risk.pkl"),
    "func": _load_gbr("gbr_func_risk.pkl"),
}

feature_effect_signs = {}
for domain, gbr in _GBR_BY_DOMAIN.items():
    signs = {f: 1 for f in risk_features}   # default neutral +1
    if gbr is not None and hasattr(gbr, "predict"):
        n_in = getattr(gbr, "n_features_in_", global_arr.shape[1])
        X = global_arr[:, :n_in]
        try:
            y_pred = gbr.predict(X)
            for i, feat in enumerate(risk_features):
                if i >= n_in:
                    continue
                col = global_arr[:, i]
                if np.std(col) < 1e-9 or np.std(y_pred) < 1e-9:
                    signs[feat] = 1   # constant feature — no directional signal
                    continue
                corr = float(np.corrcoef(col, y_pred)[0, 1])
                signs[feat] = 1 if corr >= 0 else -1
        except Exception as exc:
            print(f"  WARN: effect-sign for {domain} failed ({exc}); defaulting to +1")
    # Clinical override wins over the raw correlation (see caveat above).
    for feat, clinical_sign in _CLINICAL_EFFECT_SIGNS.items():
        if feat in signs:
            signs[feat] = clinical_sign
    feature_effect_signs[domain] = signs
    pos = sum(1 for v in signs.values() if v == 1)
    neg = sum(1 for v in signs.values() if v == -1)
    print(f"  {domain}: {pos} raise-risk (+1), {neg} lower-risk (-1)")

output = {
    "generated_at": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "n_seniors": total,
    "risk_features": risk_features,
    "cluster_means": means,
    "global_mean": global_mean,
    "feature_effect_signs": feature_effect_signs,
}
out_path = os.path.join(MODEL_DIR, "cluster_feature_means.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(output, f, indent=2)
print(f"\nWritten: {out_path}")
print("Done.")
