"""
generate_cluster_centroids.py
==============================
Computes cluster centroids in the 31-dimensional scaled feature space
(N = len(feature_list.json)) using the notebook's ORIGINAL cluster
assignments stored in ml_results.cluster_named_id as ground truth.

WHY NOT RE-RUN UMAP:
  Single-point UMAP transform() is non-deterministic across devices and
  library versions. Re-deriving cluster assignments via UMAP introduces
  drift from what the notebook computed during training fit. Using the DB
  ground truth keeps centroids stable and reproducible.

Run once after initial setup or after any model retrain:
    python/venv/Scripts/python.exe python/scripts/generate_cluster_centroids.py

Requirements:
  - DB running with seeded seniors (prediction_source = 'notebook_cache' in ml_results)
  - python/models/: scaler.pkl, feature_list.json, cluster_mapping.json

Output: python/models/cluster_centroids_scaled.json
"""

import json
import os
import pickle
import sys
import warnings
from collections import defaultdict
from datetime import date, datetime

warnings.filterwarnings("ignore")

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/

sys.path.insert(0, os.path.join(BASE_DIR, "python", "services"))

import numpy as np  # noqa: E402
import pandas as pd  # noqa: E402
import pymysql  # noqa: E402
import pymysql.cursors  # noqa: E402


# ── Resolve MODEL_DIR ─────────────────────────────────────────────────────────
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

# ── Load scaler only (no UMAP needed) ─────────────────────────────────────────
def _load_pkl(name: str):
    path = os.path.join(MODEL_DIR, name)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)

scaler = _load_pkl("scaler.pkl")
assert scaler is not None, "ERROR: scaler.pkl not found"

fl_path = os.path.join(MODEL_DIR, "feature_list.json")
with open(fl_path, encoding="utf-8") as f:
    feature_names: list = json.load(f)      # 31 UMAP-input feature names

print(f"Scaler loaded ({len(list(scaler.feature_names_in_))} features). "
      f"Feature list: {len(feature_names)} features.")

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

from preprocess_service import preprocess  # noqa: E402

# ── Query seeded seniors — use DB cluster_named_id as ground truth ─────────────
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
print(f"Loaded {len(rows)} seeded seniors (prediction_source = notebook_cache).")

# ── Build scaled feature vectors ───────────────────────────────────────────────
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
        return 70
    if isinstance(dob, str):
        dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

scaler_input_names = list(scaler.feature_names_in_)
scaler_feat_idx    = {f: i for i, f in enumerate(scaler_input_names)}

# cluster_vectors[named_id] = list of 31D scaled vectors
# Uses DB cluster_named_id as ground truth — NOT re-derived from live UMAP
cluster_vectors: dict = defaultdict(list)
skipped = 0

for row in rows:
    db_named_id = int(row["cluster_named_id"] or 1)

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

        # Scale the 39-feature scaler input, then select 31-feature subset
        scaler_row  = [float(feature_map.get(f, 0.0)) for f in scaler_input_names]
        full_scaled = scaler.transform(
            pd.DataFrame([scaler_row], columns=scaler_input_names)
        )[0]
        scaled_31 = [
            float(full_scaled[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
            for f in feature_names
        ]

        # Use DB cluster as ground truth — no UMAP involved
        cluster_vectors[db_named_id].append(scaled_31)

    except Exception as exc:
        print(f"  WARN: skipped id={row['id']} ({row['first_name']} {row['last_name']}): {exc}")
        skipped += 1

total_processed = sum(len(v) for v in cluster_vectors.values())
print(f"Preprocessed {total_processed} seniors ({skipped} skipped).")

# ── Compute centroids (mean per cluster) ──────────────────────────────────────
centroids = {}
for named_id in sorted(cluster_vectors.keys()):
    arr      = np.array(cluster_vectors[named_id], dtype=np.float64)
    centroid = arr.mean(axis=0).tolist()
    centroids[str(named_id)] = centroid
    print(f"Cluster {named_id}: {len(cluster_vectors[named_id])} seniors, "
          f"centroid dims={len(centroid)}")

# ── Also compute and print model artifact hashes for the manifest ─────────────
import hashlib  # noqa: E402


def _sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()

artifact_hashes = {}
for fname in ["scaler.pkl", "gbr_model.pkl", "rfr_model.pkl", "kmeans.pkl",
              "umap_nd.pkl", "feature_list.json", "cluster_mapping.json"]:
    fpath = os.path.join(MODEL_DIR, fname)
    if os.path.exists(fpath):
        artifact_hashes[fname] = _sha256(fpath)

# ── Write cluster_centroids_scaled.json ───────────────────────────────────────
output = {
    "generated_at":       datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "method":             "db_ground_truth",   # NOT live UMAP — uses notebook cluster IDs
    "model_dir":          MODEL_DIR,
    "feature_names":      feature_names,
    "n_features":         len(feature_names),
    "n_clusters":         len(centroids),
    "n_seniors_used":     total_processed,
    "centroids":          centroids,
}
out_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
with open(out_path, "w", encoding="utf-8") as f:
    json.dump(output, f, indent=2)
print(f"\nWritten: {out_path}")

# ── Write model_manifest.json ──────────────────────────────────────────────────
manifest = {
    "generated_at": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "model_dir":    MODEL_DIR,
    "sha256":       artifact_hashes,
}
manifest_path = os.path.join(MODEL_DIR, "model_manifest.json")
with open(manifest_path, "w", encoding="utf-8") as f:
    json.dump(manifest, f, indent=2)
print(f"Written: {manifest_path}")
print("\nModel artifact SHA-256 hashes:")
for fname, h in artifact_hashes.items():
    print(f"  {fname}: {h[:16]}...")
print("\nDone. Copy python/models/ to any other device to get identical results.")
