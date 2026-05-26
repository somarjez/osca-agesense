"""
generate_cluster_centroids.py
==============================
Computes cluster centroids in the N-dimensional scaled feature space
(N = len(feature_list.json)) from the 283 seeded seniors stored in the DB.
Run once after initial setup or after any model retrain.

Usage (from project root):
    python/venv/Scripts/python.exe python/scripts/generate_cluster_centroids.py

Requirements:
  - DB running with seeded seniors (prediction_source = 'notebook_cache' in ml_results)
  - python/models/ directory with scaler.pkl, umap_nd.pkl, kmeans.pkl,
    feature_list.json, cluster_mapping.json

Output: python/models/cluster_centroids_scaled.json
"""

import os
import sys

# Must be set before numba / umap are imported
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")

import json
import pickle
import warnings
from collections import defaultdict
from datetime import date, datetime

warnings.filterwarnings("ignore")

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/

sys.path.insert(0, os.path.join(BASE_DIR, "python", "services"))

import numpy as np
import pandas as pd
import pymysql
import pymysql.cursors

# ── Resolve MODEL_DIR (same logic as inference_service) ───────────────────────
def _read_dotenv(name: str):
    for candidate in [os.path.join(BASE_DIR, ".env"),
                      os.path.join(BASE_DIR, "..", ".env")]:
        if os.path.exists(candidate):
            try:
                for line in open(candidate, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        if k.strip() == name:
                            return v.strip().strip('"').strip("'")
            except Exception:
                pass
    return None

_env_model_dir = os.environ.get("ML_MODELS_PATH") or _read_dotenv("ML_MODELS_PATH")
if _env_model_dir:
    MODEL_DIR = _env_model_dir if os.path.isabs(_env_model_dir) \
                else os.path.join(BASE_DIR, _env_model_dir)
else:
    MODEL_DIR = os.path.join(BASE_DIR, "python", "models")

print(f"MODEL_DIR: {MODEL_DIR}")

# ── Load live models ───────────────────────────────────────────────────────────
def _load_pkl(name: str):
    path = os.path.join(MODEL_DIR, name)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)

scaler    = _load_pkl("scaler.pkl")
umap_model = _load_pkl("umap_nd.pkl") or _load_pkl("umap_reducer.pkl")
kmeans    = _load_pkl("kmeans.pkl") or _load_pkl("kmeans_k3.pkl")

fl_path   = os.path.join(MODEL_DIR, "feature_list.json")
cm_path   = os.path.join(MODEL_DIR, "cluster_mapping.json")

with open(fl_path, encoding="utf-8") as f:
    feature_names: list = json.load(f)      # 31 UMAP-input feature names

with open(cm_path, encoding="utf-8") as f:
    _cm_raw = json.load(f)
cluster_map = {int(k): int(v) for k, v in _cm_raw.items()}

assert scaler is not None,    "ERROR: scaler.pkl not found"
assert umap_model is not None, "ERROR: umap_nd.pkl / umap_reducer.pkl not found"
assert kmeans is not None,    "ERROR: kmeans.pkl not found"
print(f"Models loaded. Feature list: {len(feature_names)} features.")

# ── DB connection ──────────────────────────────────────────────────────────────
def _db_connect():
    env = {}
    for candidate in [os.path.join(BASE_DIR, ".env"),
                      os.path.join(BASE_DIR, "..", ".env")]:
        if os.path.exists(candidate):
            try:
                for line in open(candidate, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        env[k.strip()] = v.strip().strip('"').strip("'")
                break
            except Exception:
                pass
    return pymysql.connect(
        host     = env.get("DB_HOST", "127.0.0.1"),
        port     = int(env.get("DB_PORT", 3306)),
        user     = env.get("DB_USERNAME", "root"),
        password = env.get("DB_PASSWORD", ""),
        database = env.get("DB_DATABASE", "osca_db"),
        cursorclass = pymysql.cursors.DictCursor,
    )

# ── Import preprocess_service (direct function call, no HTTP) ─────────────────
from preprocess_service import preprocess

# ── Query seeded seniors from DB ───────────────────────────────────────────────
QUERY = """
    SELECT
        sc.id, sc.first_name, sc.last_name, sc.barangay, sc.date_of_birth,
        sc.gender, sc.marital_status, sc.educational_attainment,
        sc.monthly_income_range, sc.num_children, sc.num_working_children,
        sc.household_size, sc.child_financial_support, sc.spouse_working,
        sc.income_source, sc.real_assets, sc.movable_assets, sc.living_with,
        sc.household_condition, sc.community_service, sc.specialization,
        sc.medical_concern, sc.dental_concern, sc.optical_concern,
        sc.hearing_concern, sc.social_emotional_concern, sc.healthcare_difficulty,
        sc.has_medical_checkup, sc.checkup_schedule,
        qs.a1_enjoy_life, qs.a2_life_satisfaction, qs.a3_future_outlook, qs.a4_meaningfulness,
        qs.b1_physical_energy, qs.b2_pain_discomfort, qs.b3_health_self_care,
        qs.b4_health_outside, qs.b5_mobility,
        qs.c1_happiness, qs.c2_calm_peace, qs.c3_loneliness, qs.c4_confidence,
        qs.d1_independence, qs.d2_time_control, qs.d3_life_control, qs.d4_income_limits,
        qs.e1_social_support, qs.e2_close_person, qs.e3_community_opportunities,
        qs.e4_participation, qs.e5_respect,
        qs.f1_home_safety, qs.f2_neighborhood_safety, qs.f3_service_access, qs.f4_home_comfort,
        qs.g1_household_expenses, qs.g2_medical_afford, qs.g3_personal_wants,
        qs.h1_belief_comfort, qs.h2_belief_practice,
        mr.cluster_named_id
    FROM ml_results mr
    JOIN senior_citizens sc ON sc.id = mr.senior_citizen_id
    JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
    WHERE mr.prediction_source = 'notebook_cache'
      AND mr.id = (
          SELECT MAX(id2.id) FROM ml_results id2
          WHERE id2.senior_citizen_id = sc.id
            AND id2.prediction_source = 'notebook_cache'
      )
      AND sc.deleted_at IS NULL
    ORDER BY sc.id
"""

print("Connecting to DB...")
conn = _db_connect()
with conn.cursor() as cur:
    cur.execute(QUERY)
    rows = cur.fetchall()
conn.close()
print(f"Loaded {len(rows)} seeded seniors from DB (prediction_source = notebook_cache).")

# ── Build raw payloads and run preprocess ──────────────────────────────────────
def _parse_json_col(val):
    if val is None:
        return []
    if isinstance(val, (list, dict)):
        return val
    try:
        return json.loads(val)
    except Exception:
        return []

def _compute_age(dob) -> int:
    if dob is None:
        return 70  # fallback
    if isinstance(dob, str):
        dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

all_scaled: list = []       # will hold 31D vectors
all_db_named: list = []     # ground-truth cluster_named_id from ml_results
skipped = 0

scaler_input_names = list(scaler.feature_names_in_)
scaler_feat_idx    = {f: i for i, f in enumerate(scaler_input_names)}

for row in rows:
    qol_responses = {
        "qol_enjoy_life":        row["a1_enjoy_life"],
        "qol_life_satisfaction": row["a2_life_satisfaction"],
        "qol_future_outlook":    row["a3_future_outlook"],
        "qol_meaningfulness":    row["a4_meaningfulness"],
        "phy_energy":            row["b1_physical_energy"],
        "phy_pain_r":            row["b2_pain_discomfort"],
        "phy_health_limit_r":    row["b3_health_self_care"],
        "phy_mobility_outside":  row["b4_health_outside"],
        "phy_mobility_indoor":   row["b5_mobility"],
        "psych_happiness":       row["c1_happiness"],
        "psych_peace":           row["c2_calm_peace"],
        "psych_lonely_r":        row["c3_loneliness"],
        "psych_confidence":      row["c4_confidence"],
        "func_independence":     row["d1_independence"],
        "func_autonomy":         row["d2_time_control"],
        "func_control":          row["d3_life_control"],
        "env_income_limit_r":    row["d4_income_limits"],
        "soc_social_support":    row["e1_social_support"],
        "soc_close_friend":      row["e2_close_person"],
        "soc_participation":     row["e4_participation"],
        "soc_opportunity":       row["e3_community_opportunities"],
        "soc_respect":           row["e5_respect"],
        "env_safe_home":         row["f1_home_safety"],
        "env_safe_neighborhood": row["f2_neighborhood_safety"],
        "env_service_access":    row["f3_service_access"],
        "env_home_comfort":      row["f4_home_comfort"],
        "env_fin_medical":       row["g2_medical_afford"],
        "env_fin_household":     row["g1_household_expenses"],
        "env_fin_personal":      row["g3_personal_wants"],
        "spi_belief_comfort":    row["h1_belief_comfort"],
        "spi_belief_practice":   row["h2_belief_practice"],
    }
    raw = {
        "senior_id":                row["id"],
        "first_name":               row["first_name"],
        "last_name":                row["last_name"],
        "barangay":                 row["barangay"],
        "age":                      _compute_age(row["date_of_birth"]),
        "gender":                   row["gender"],
        "marital_status":           row["marital_status"],
        "educational_attainment":   row["educational_attainment"],
        "monthly_income_range":     row["monthly_income_range"],
        "num_children":             row["num_children"] or 0,
        "num_working_children":     row["num_working_children"] or 0,
        "household_size":           row["household_size"] or 1,
        "child_financial_support":  row["child_financial_support"],
        "spouse_working":           row["spouse_working"],
        "income_source":            _parse_json_col(row["income_source"]),
        "real_assets":              _parse_json_col(row["real_assets"]),
        "movable_assets":           _parse_json_col(row["movable_assets"]),
        "living_with":              _parse_json_col(row["living_with"]),
        "household_condition":      _parse_json_col(row["household_condition"]),
        "community_service":        _parse_json_col(row["community_service"]),
        "specialization":           _parse_json_col(row["specialization"]),
        "medical_concern":          _parse_json_col(row["medical_concern"]),
        "dental_concern":           _parse_json_col(row["dental_concern"]),
        "optical_concern":          _parse_json_col(row["optical_concern"]),
        "hearing_concern":          _parse_json_col(row["hearing_concern"]),
        "social_emotional_concern": _parse_json_col(row["social_emotional_concern"]),
        "healthcare_difficulty":    _parse_json_col(row["healthcare_difficulty"]),
        "has_medical_checkup":      bool(row["has_medical_checkup"])
                                    and row["checkup_schedule"] != "No Follow-up",
        "qol_responses":            qol_responses,
    }

    try:
        result = preprocess(raw)
        feature_map = result.get("feature_map") or {}

        # Build the 31D UMAP-input vector: scale the 39-feature scaler input
        # then select the feature_list.json subset.
        scaler_row  = [float(feature_map.get(f, 0.0)) for f in scaler_input_names]
        full_scaled = scaler.transform(
            pd.DataFrame([scaler_row], columns=scaler_input_names)
        )[0]
        scaled_31 = [
            float(full_scaled[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
            for f in feature_names
        ]
        all_scaled.append(scaled_31)
        all_db_named.append(int(row["cluster_named_id"] or 1))
    except Exception as exc:
        print(f"  WARN: skipped senior id={row['id']} ({row['first_name']} {row['last_name']}): {exc}")
        skipped += 1

print(f"Preprocessed {len(all_scaled)} seniors ({skipped} skipped).")

# ── Run live UMAP + KMeans on the full batch ──────────────────────────────────
print("Running live UMAP transform on full batch...")
batch_np = np.array(all_scaled, dtype=np.float64)   # shape (N, 31)
umap_model.transform_seed = 42
if not getattr(umap_model, "_rp_forest", None):
    umap_model.transform_queue_size = 0.0
reduced = umap_model.transform(batch_np)             # shape (N, 10)
raw_ids = kmeans.predict(reduced)                    # shape (N,)
live_named = [cluster_map.get(int(r), int(r) + 1) for r in raw_ids]

# Print agreement between live pipeline and DB ground truth
matches = sum(l == d for l, d in zip(live_named, all_db_named))
print(f"Live pipeline vs DB cluster agreement: {matches}/{len(live_named)}")

# ── Compute centroids (mean of 31D scaled vectors, grouped by live cluster) ──
cluster_vectors = defaultdict(list)
for vec, named_id in zip(all_scaled, live_named):
    cluster_vectors[named_id].append(vec)

centroids = {}
for named_id in sorted(cluster_vectors):
    arr     = np.array(cluster_vectors[named_id], dtype=np.float64)
    centroid = arr.mean(axis=0).tolist()
    centroids[str(named_id)] = centroid
    print(f"Cluster {named_id}: {len(cluster_vectors[named_id])} seniors, "
          f"centroid shape ({len(centroid)},)")

# ── Write JSON ─────────────────────────────────────────────────────────────────
from datetime import datetime as _dt
output = {
    "generated_at":  _dt.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "model_dir":     MODEL_DIR,
    "feature_names": feature_names,
    "n_features":    len(feature_names),
    "n_clusters":    len(centroids),
    "centroids":     centroids,
}

out_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(output, f, indent=2)

print(f"Written: {out_path}")
