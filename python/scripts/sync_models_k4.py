"""
sync_models_k4.py
=================
Copies all K=4 model artifacts from osca_output/model/ into python/models/,
regenerates cluster_centroids_scaled.json (31-dim scaled centroids from CSV labels
+ DB features), model_manifest.json, and regression_baseline.json, then prints
a summary.

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\sync_models_k4.py

Rollback:
    Remove-Item -Recurse -Force python/models/
    Rename-Item python/models_backup_YYYYMMDD_HHMM python/models
"""

import csv
import hashlib
import json
import os
import pickle
import re
import shutil
import sys
import unicodedata
import warnings
from datetime import datetime

warnings.filterwarnings("ignore")

# ── Paths ──────────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT  = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/
BASE_DIR   = os.path.dirname(REPO_ROOT)                      # one level above repo root

SOURCE_DIR = os.path.join(BASE_DIR, "osca_output", "model")
TARGET_DIR = os.path.join(REPO_ROOT, "python", "models")
PREDS_CSV  = os.path.join(BASE_DIR, "osca_output", "predictions", "senior_predictions.csv")

# 23 source files to copy verbatim
SOURCE_FILES = [
    "kmeans.pkl",            "kmeans_model.pkl",
    "scaler.pkl",
    "umap_reducer.pkl",      "umap_2d.pkl",           "umap_nd.pkl",
    "gbr_ic_risk.pkl",       "gbr_env_risk.pkl",      "gbr_func_risk.pkl",
    "rfr_ic_risk.pkl",       "rfr_env_risk.pkl",      "rfr_func_risk.pkl",
    "edu_encoder.pkl",       "income_encoder.pkl",    "hdbscan.pkl",
    "cluster_mapping.json",  "cluster_metadata.json",
    "feature_list.json",     "final_feature_list.json",
    "ml_risk_features.json", "best_hyperparameters.json",
    "asset_weights.json",    "vif_retained_features.json",
]

MODEL_VERSION = "2.0.0"


# ── Helpers ────────────────────────────────────────────────────────────────────
def _sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def _abort(msg: str):
    print(f"\n[ABORT] {msg}")
    sys.exit(1)


def _norm_name(s: str) -> str:
    """Lowercase, NFC, strip accents, collapse non-alphanum."""
    s = unicodedata.normalize("NFC", str(s or ""))
    s = s.replace("\xf1", "n").replace("\xd1", "n")   # n-tilde
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return re.sub(r"[^a-z0-9]+", "", s.lower())


# Committed baseline files are public (public GitHub repo) and must never carry
# identifying senior data. Keys are salted hashes of normalised name+barangay,
# not the cleartext itself. The salt is not a security boundary — it only
# keeps the tracked JSON from reading as a plain name list — so it is a fixed
# literal, duplicated in every script that builds or looks up these keys
# (sync_models_k4.py, validate_k4_sync.py, generate_xai_means.py,
# regression_test.py). Change it in all four together if it ever changes.
_BASELINE_KEY_SALT = "agesense-baseline-deid-v1"


def _key(first: str, last: str, barangay: str) -> str:
    raw = f"{_norm_name(first)}|{_norm_name(last)}|{_norm_name(barangay)}"
    return hashlib.sha256((_BASELINE_KEY_SALT + raw).encode("utf-8")).hexdigest()[:24]


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
    from datetime import date as _date
    if dob is None:
        return 70
    if isinstance(dob, str):
        dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = _date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))


# ── Step 1: Pre-flight ─────────────────────────────────────────────────────────
print("=" * 60)
print("K=4 Model Sync -- Pre-flight check")
print("=" * 60)

if not os.path.isdir(SOURCE_DIR):
    _abort(f"Source dir not found: {SOURCE_DIR}")

missing = [f for f in SOURCE_FILES if not os.path.exists(os.path.join(SOURCE_DIR, f))]
if missing:
    _abort(f"Missing source files: {missing}")

if not os.path.exists(PREDS_CSV):
    _abort(f"senior_predictions.csv not found: {PREDS_CSV}")

with open(PREDS_CSV, encoding="utf-8-sig") as f:
    rows_csv = list(csv.DictReader(f))
if len(rows_csv) < 280:
    _abort(f"senior_predictions.csv has only {len(rows_csv)} rows (expected >=280).")

print(f"  Source dir:   {SOURCE_DIR}")
print(f"  Target dir:   {TARGET_DIR}")
print(f"  Source files: {len(SOURCE_FILES)} files present [OK]")
print(f"  Predictions:  {len(rows_csv)} rows [OK]")
print()

for fname in SOURCE_FILES:
    fpath = os.path.join(SOURCE_DIR, fname)
    print(f"  {fname:40s}  {os.path.getsize(fpath):>10,} bytes")


# ── Step 2: Backup ─────────────────────────────────────────────────────────────
ts = datetime.now().strftime("%Y%m%d_%H%M")
backup_dir = os.path.join(REPO_ROOT, "python", f"models_backup_{ts}")

print(f"\nBacking up python/models/ -> {os.path.basename(backup_dir)} ...")
if os.path.isdir(TARGET_DIR):
    shutil.copytree(TARGET_DIR, backup_dir)
    print(f"  Backup created: {backup_dir}")
else:
    os.makedirs(TARGET_DIR, exist_ok=True)
    print(f"  Target dir did not exist -- created fresh: {TARGET_DIR}")


# ── Step 3: Copy model files ───────────────────────────────────────────────────
print(f"\nCopying {len(SOURCE_FILES)} files -> python/models/ ...")
os.makedirs(TARGET_DIR, exist_ok=True)
for fname in SOURCE_FILES:
    src = os.path.join(SOURCE_DIR, fname)
    dst = os.path.join(TARGET_DIR, fname)
    shutil.copy2(src, dst)
    print(f"  [OK] {fname:40s}  {os.path.getsize(dst):>10,} bytes")


# ── Step 4: Generate cluster_centroids_scaled.json ─────────────────────────────
# NOTE: KMeans was trained on UMAP-reduced features (10-dim), not 31-dim scaled.
# cluster_centroids_scaled.json must be in 31-dim scaled space so that
# _deterministic_cluster_assign() in inference_service.py can do nearest-centroid.
# Method: use K=4 cluster labels from senior_predictions.csv, match to DB seniors,
# compute their 31-dim scaled feature vectors via preprocess(), average per cluster.
print("\nGenerating cluster_centroids_scaled.json ...")
print("  Method: 31-dim scaled centroids from CSV labels + DB features")

centroid_generated = False
n_clusters = 4
n_dims = 31

try:
    from collections import defaultdict

    import numpy as np
    import pandas as pd
    import pymysql
    import pymysql.cursors

    # Add services to path so we can import preprocess() directly (no Flask startup)
    SERVICES_DIR = os.path.join(REPO_ROOT, "python", "services")
    if SERVICES_DIR not in sys.path:
        sys.path.insert(0, SERVICES_DIR)
    from preprocess_service import preprocess as _preprocess

    # Load scaler to discover feature name order and scaling parameters
    with open(os.path.join(TARGET_DIR, "scaler.pkl"), "rb") as f:
        _scaler = pickle.load(f)

    fl_path = os.path.join(TARGET_DIR, "feature_list.json")
    with open(fl_path, encoding="utf-8") as f:
        feature_names = json.load(f)   # 31 features that UMAP/KMeans uses

    scaler_input_names = list(_scaler.feature_names_in_)
    scaler_feat_idx    = {fn: i for i, fn in enumerate(scaler_input_names)}

    # Build CSV label lookup: normalised key -> cluster_named_id (1-4)
    csv_labels: dict = {}
    for row in rows_csv:
        k = _key(row["first_name"], row["last_name"], row["barangay"])
        csv_labels[k] = int(float(row["cluster_id"]))

    # DB credentials from .env
    def _read_dotenv_local(name: str) -> str:
        for cand in [os.path.join(REPO_ROOT, ".env"),
                     os.path.join(os.path.dirname(REPO_ROOT), ".env")]:
            if os.path.exists(cand):
                for line in open(cand, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k2, _, v = line.partition("=")
                        if k2.strip() == name:
                            return v.strip().strip('"').strip("'")
        return ""

    conn = pymysql.connect(
        host     = os.environ.get("DB_HOST")     or _read_dotenv_local("DB_HOST")     or "127.0.0.1",
        port     = int(os.environ.get("DB_PORT") or _read_dotenv_local("DB_PORT")     or 3306),
        user     = os.environ.get("DB_USERNAME") or _read_dotenv_local("DB_USERNAME") or "root",
        password = os.environ.get("DB_PASSWORD") or _read_dotenv_local("DB_PASSWORD") or "",
        database = os.environ.get("DB_DATABASE") or _read_dotenv_local("DB_DATABASE") or "osca_db",
        cursorclass = pymysql.cursors.DictCursor,
    )
    CENTROID_QUERY = """
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
            qs.h1_belief_comfort, qs.h2_belief_practice
        FROM senior_citizens sc
        JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
        WHERE sc.deleted_at IS NULL
          AND qs.id = (SELECT MAX(qs2.id) FROM qol_surveys qs2
                       WHERE qs2.senior_citizen_id = sc.id)
        ORDER BY sc.id
    """
    with conn.cursor() as cur:
        cur.execute(CENTROID_QUERY)
        db_rows_c = cur.fetchall()
    conn.close()
    print(f"  Loaded {len(db_rows_c)} seniors from DB for centroid computation")

    cluster_vectors: dict = defaultdict(list)
    centroid_skipped = 0
    for row in db_rows_c:
        k = _key(row["first_name"], row["last_name"], row["barangay"])
        named_id_csv = csv_labels.get(k)
        if named_id_csv is None:
            centroid_skipped += 1
            continue
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
            "has_medical_checkup":      (
                bool(row["has_medical_checkup"])
                and row["checkup_schedule"] != "No Follow-up"
            ),
            "qol_responses": {
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
            },
        }
        try:
            result      = _preprocess(raw)
            feature_map = result.get("feature_map") or {}
            scaler_row  = [float(feature_map.get(fn, 0.0)) for fn in scaler_input_names]
            full_scaled = _scaler.transform(
                pd.DataFrame([scaler_row], columns=scaler_input_names)
            )[0]
            scaled_31 = [
                float(full_scaled[scaler_feat_idx[fn]]) if fn in scaler_feat_idx else 0.0
                for fn in feature_names
            ]
            cluster_vectors[named_id_csv].append(scaled_31)
        except Exception as exc:
            centroid_skipped += 1
            if centroid_skipped <= 3:
                print(f"    [WARN] skipped id={row['id']}: {exc}")

    total_centroid = sum(len(v) for v in cluster_vectors.values())
    print(f"  Computed 31-dim scaled vectors for {total_centroid} seniors "
          f"({centroid_skipped} skipped)")

    centroids: dict = {}
    n_dims = len(feature_names)
    for cid in sorted(cluster_vectors.keys()):
        arr      = np.array(cluster_vectors[cid], dtype=np.float64)
        centroid = arr.mean(axis=0).tolist()
        centroids[str(cid)] = centroid
        print(f"  Cluster {cid}: {len(cluster_vectors[cid])} seniors -> "
              f"{len(centroid)}-dim centroid")

    n_clusters = len(centroids)
    centroid_doc = {
        "generated_at":  datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
        "method":        "csv_labels_scaled_features",
        "model_version": MODEL_VERSION,
        "feature_names": feature_names,
        "n_features":    n_dims,
        "n_clusters":    n_clusters,
        "n_seniors_used": total_centroid,
        "centroids":     centroids,
    }
    out_path = os.path.join(TARGET_DIR, "cluster_centroids_scaled.json")
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(centroid_doc, f, indent=2)
    print(f"  cluster_centroids_scaled.json: {n_clusters} centroids x {n_dims} dims [OK]")
    centroid_generated = True

except Exception as centroid_exc:
    print(f"  [WARN] Could not generate cluster_centroids_scaled.json: {centroid_exc}")
    print("  Inference service will fall back to UMAP+KMeans for cluster assignment.")
    print("  Run generate_cluster_centroids.py after services are restarted.")


# ── Step 5: Generate model_manifest.json ──────────────────────────────────────
print("\nGenerating model_manifest.json ...")

pkl_files = sorted(fn for fn in os.listdir(TARGET_DIR) if fn.endswith(".pkl"))
checksums = {}
for fname in pkl_files:
    fpath = os.path.join(TARGET_DIR, fname)
    checksums[fname] = _sha256(fpath)

manifest = {
    "model_version": MODEL_VERSION,
    "generated_at":  datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
    "sha256":        checksums,
}
manifest_path = os.path.join(TARGET_DIR, "model_manifest.json")
with open(manifest_path, "w", encoding="utf-8") as f:
    json.dump(manifest, f, indent=2)
print(f"  model_manifest.json: SHA256 for {len(checksums)} pkl files, version={MODEL_VERSION} [OK]")


# ── Step 6: Generate regression_baseline_k4.json ───────────────────────────────
# NOTE: this file is committed to a public repo. It must carry only the hashed
# "key" (see _BASELINE_KEY_SALT above) plus model outputs — never cleartext
# first_name/last_name/barangay. Downstream readers (validate_k4_sync.py,
# generate_xai_means.py) only ever consult nb["cluster_named_id"] /
# nb["overall_risk_level"] / nb["composite_risk"], never nb["first_name"] etc.,
# so dropping the cleartext fields here does not break anything downstream.
print("\nGenerating regression_baseline_k4.json ...")

baseline = []
for row in rows_csv:
    baseline.append({
        "key":               _key(row["first_name"], row["last_name"], row["barangay"]),
        "cluster_named_id":  int(float(row["cluster_id"])),
        "overall_risk_level": row["risk_level"].strip().upper(),
        "ic_risk":           float(row["ic_risk"]),
        "env_risk":          float(row["env_risk"]),
        "func_risk":         float(row["func_risk"]),
        "composite_risk":    float(row["composite_risk"]),
    })

baseline_path = os.path.join(TARGET_DIR, "regression_baseline_k4.json")
with open(baseline_path, "w", encoding="utf-8") as f:
    json.dump(baseline, f, indent=2)
print(f"  regression_baseline_k4.json: {len(baseline)} rows [OK]")
if len(baseline) != 283:
    print(f"  [WARN] Expected 283 rows, got {len(baseline)}")


# ── Step 7: Summary ────────────────────────────────────────────────────────────
print()
print("=" * 60)
print("K=4 Model Sync Complete")
print("=" * 60)
print(f"  Files copied:      {len(SOURCE_FILES)}")
print(f"  Centroids:         {n_clusters} clusters x {n_dims} dims "
      f"({'generated' if centroid_generated else 'WARN: not generated'})")
print(f"  Manifest SHA256:   computed for {len(checksums)} pkl files")
print(f"  Baseline rows:     {len(baseline)}")
print(f"  Backup at:         {backup_dir}")
print()
print("Next steps:")
print("  1. Restart Flask services (preprocess :5001 and inference :5002)")
print("  2. Run: python\\venv\\Scripts\\python.exe python\\scripts\\validate_k4_sync.py")
