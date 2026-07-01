"""
generate_knn_classifier.py
===========================
Trains a KNN k=5 classifier (euclidean distance, scaled feature space) on the
cluster labels stored in ml_results.cluster_named_id and saves it as
python/models/knn_cluster.pkl for use by inference_service.py.

WHY KNN vs NEAREST-CENTROID:
  5-fold CV on the MinMaxScaler config shows KNN k=5 achieves ~92.8% label
  consistency with notebook UMAP assignments vs ~87.5% for nearest-centroid.
  KNN captures non-spherical cluster boundaries that nearest-centroid misses.

WHY NOT RE-RUN UMAP:
  Same reason as generate_cluster_centroids.py — single-point UMAP transform()
  is non-deterministic across devices. DB cluster_named_id is the ground truth.

Run once after notebook re-run + artifact redeploy:
    python/venv/Scripts/python.exe python/scripts/generate_knn_classifier.py

Requirements:
  - DB running with seeded seniors (prediction_source = 'notebook_cache' in ml_results)
  - python/models/: scaler.pkl, feature_list.json

Output: python/models/knn_cluster.pkl
"""

import json
import os
import pickle
import sys
import warnings
from datetime import date, datetime

warnings.filterwarnings("ignore")

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))

sys.path.insert(0, os.path.join(BASE_DIR, "python", "services"))

import numpy as np  # noqa: E402
import pandas as pd  # noqa: E402
import pymysql  # noqa: E402
import pymysql.cursors  # noqa: E402
from sklearn.model_selection import StratifiedKFold, cross_val_score  # noqa: E402
from sklearn.neighbors import KNeighborsClassifier  # noqa: E402

CV_TARGET = 0.90   # minimum acceptable 5-fold CV accuracy
KNN_K     = 5
KNN_METRIC = "euclidean"


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


def _load_pkl(name: str):
    path = os.path.join(MODEL_DIR, name)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)


scaler = _load_pkl("scaler.pkl")
assert scaler is not None, "ERROR: scaler.pkl not found in MODEL_DIR"

fl_path = os.path.join(MODEL_DIR, "feature_list.json")
assert os.path.exists(fl_path), "ERROR: feature_list.json not found in MODEL_DIR"
with open(fl_path, encoding="utf-8") as f:
    feature_names: list = json.load(f)

print(f"Scaler loaded ({len(list(scaler.feature_names_in_))} features). "
      f"Feature list: {len(feature_names)} clustering features.")


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
        host        = env.get("DB_HOST", "127.0.0.1"),
        port        = int(env.get("DB_PORT", 3306)),
        user        = env.get("DB_USERNAME", "root"),
        password    = env.get("DB_PASSWORD", ""),
        database    = env.get("DB_DATABASE", "osca_db"),
        cursorclass = pymysql.cursors.DictCursor,
    )


from preprocess_service import preprocess  # noqa: E402

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
print(f"Loaded {len(rows)} seniors (prediction_source = notebook_cache).")


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

X_rows: list = []
y_labels: list = []
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

        scaler_row  = [float(feature_map.get(f, 0.0)) for f in scaler_input_names]
        full_scaled = scaler.transform(
            pd.DataFrame([scaler_row], columns=scaler_input_names)
        )[0]
        scaled_n = [
            float(full_scaled[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
            for f in feature_names
        ]

        X_rows.append(scaled_n)
        y_labels.append(db_named_id)

    except Exception as exc:
        print(f"  WARN: skipped id={row['id']} ({row['first_name']} {row['last_name']}): {exc}")
        skipped += 1

X = np.array(X_rows, dtype=np.float64)
y = np.array(y_labels, dtype=np.int32)
print(f"Built feature matrix: X={X.shape}, y={y.shape} ({skipped} skipped).")
print(f"Class distribution: {dict(zip(*np.unique(y, return_counts=True)))}")

knn = KNeighborsClassifier(n_neighbors=KNN_K, metric=KNN_METRIC)

cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)
cv_scores = cross_val_score(knn, X, y, cv=cv, scoring="accuracy")
mean_acc = cv_scores.mean()
std_acc  = cv_scores.std()
print(f"\n5-fold CV accuracy: {mean_acc:.4f} +/- {std_acc:.4f}")
print(f"Fold scores: {[f'{s:.4f}' for s in cv_scores]}")

if mean_acc < CV_TARGET:
    print(f"\nWARNING: CV accuracy {mean_acc:.4f} is below target {CV_TARGET:.2f}. "
          f"Consider retraining the notebook before deploying cluster_assignment_knn_k5.pkl.")
else:
    print(f"CV target {CV_TARGET:.2f} met.")

knn.fit(X, y)

# Attach feature names so inference_service.py can verify alignment at runtime
knn._osca_feature_names = feature_names

out_path = os.path.join(MODEL_DIR, "cluster_assignment_knn_k5.pkl")
with open(out_path, "wb") as f:
    pickle.dump(knn, f, protocol=pickle.HIGHEST_PROTOCOL)
print(f"\nWritten: {out_path}")
print(f"  k={KNN_K}, metric={KNN_METRIC}, n_features={len(feature_names)}, "
      f"n_training={len(X)}, cv_accuracy={mean_acc:.4f}")

# ── Write cluster_assignment_metadata.json ────────────────────────────────────
# Load cluster_mapping.json (raw KMeans id -> named id) for metadata
cluster_map_path = os.path.join(MODEL_DIR, "cluster_mapping.json")
cluster_mapping = {}
if os.path.exists(cluster_map_path):
    with open(cluster_map_path, encoding="utf-8") as f:
        cluster_mapping = {int(k): int(v) for k, v in json.load(f).items()}

metadata = {
    "generated_at":      datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "artifact":          "cluster_assignment_knn_k5.pkl",
    "k":                 KNN_K,
    "metric":            KNN_METRIC,
    "scaler":            "MinMaxScaler",
    "n_features":        len(feature_names),
    "feature_names":     feature_names,
    "label_space":       "cluster_named_id (1-4)",
    "cluster_mapping":   cluster_mapping,
    "cv_accuracy":       round(float(mean_acc), 4),
    "cv_std":            round(float(std_acc), 4),
    "n_training":        int(len(X)),
    "note": (
        "KNN trained on DB cluster_named_id ground truth from notebook batch run. "
        "Primary inference path in inference_service.py (nearest-centroid is the fallback)."
    ),
}
meta_path = os.path.join(MODEL_DIR, "cluster_assignment_metadata.json")
with open(meta_path, "w", encoding="utf-8") as f:
    json.dump(metadata, f, indent=2)
print(f"Written: {meta_path}")

# ── Update model_manifest.json ────────────────────────────────────────────────
import hashlib  # noqa: E402


def _sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


manifest_path = os.path.join(MODEL_DIR, "model_manifest.json")
if os.path.exists(manifest_path):
    with open(manifest_path, encoding="utf-8") as f:
        manifest = json.load(f)
    manifest.setdefault("sha256", {})["cluster_assignment_knn_k5.pkl"] = _sha256(out_path)
    manifest["knn_updated_at"] = datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S")
    with open(manifest_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
    print(f"Updated: {manifest_path} (cluster_assignment_knn_k5.pkl hash added)")

print("\nDone. Restart the Flask inference service to pick up the new model.")
print("Next step: php artisan ml:repair-notebook-cache --all")
