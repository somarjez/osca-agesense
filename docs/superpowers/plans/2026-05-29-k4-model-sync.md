# K=4 Model Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all K=3 model artifacts in `python/models/` with K=4 artifacts from `osca_output/model/`, regenerate derived files, patch `inference_service.py`, and validate that live inference matches the notebook's 283-senior ground truth (≥90% cluster match, ≥95% risk-level match, composite Δ <0.01).

**Architecture:** Two new scripts handle the operation: `sync_models_k4.py` performs a pre-flighted, backed-up atomic copy of 23 source files and regenerates 3 derived artifacts; `validate_k4_sync.py` drives two-step HTTP inference for all 283 seniors and compares against a regression baseline derived from `senior_predictions.csv`. A single one-line patch to `inference_service.py` bumps the model version constant to `2.0.0`.

**Tech Stack:** Python 3.10+, scikit-learn (pickle), PyMySQL, Flask HTTP (ports 5001/5002), PowerShell for service restart.

---

## File Map

| Action | Path |
|---|---|
| Modify | `python/services/inference_service.py` — 2 targeted patches |
| Create | `python/scripts/sync_models_k4.py` — full model sync entry point |
| Create | `python/scripts/validate_k4_sync.py` — batch inference comparison |
| Overwrite (by sync script) | `python/models/` — all 23 K=4 artifacts from `../osca_output/model/` |
| Regenerate (by sync script) | `python/models/cluster_centroids_scaled.json` |
| Regenerate (by sync script) | `python/models/model_manifest.json` |
| Regenerate (by sync script) | `python/models/regression_baseline.json` |

---

## Context

**Repo root:** `C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system`

**Source model dir (K=4):** `..\osca_output\model\` (one level above repo root)
- Contains 15 pkl files + 8 json files = 23 total
- KMeans trained on 31-dim scaled features → `km.cluster_centers_` is 4×31
- `cluster_mapping.json` = `{"0":1,"1":2,"2":3,"3":4}`

**Target model dir (K=3, to be replaced):** `python\models\`
- Currently has K=3 `cluster_mapping.json` = `{"1":1,"2":2,"0":3}`
- Has extra file `cluster_eval_metrics.json` (not in source — preserve as-is)

**Notebook ground truth:** `..\osca_output\predictions\senior_predictions.csv`
- Columns: `first_name,last_name,barangay,cluster_id,risk_level,ic_risk,env_risk,func_risk,composite_risk,...`
- 283 rows, one per senior

**inference_service.py current state (from grep):**
- Line 146: `MODEL_VERSION = "1.1.1"` → needs `"2.0.0"`
- Line 2014: `if named_id < 1 or named_id > 3:` warning → needs `named_id > 4`
- Lines 377-418: `CLUSTER_PROFILES` already has all 4 clusters ✅
- Lines 1890, 1940, 1019: `max(1, min(4, int(...)))` already correct ✅

**venv path:** `python\venv\Scripts\python.exe`

---

## Task 1: Patch inference_service.py

**Files:**
- Modify: `python/services/inference_service.py:146` (MODEL_VERSION)
- Modify: `python/services/inference_service.py:2014-2017` (range warning)

- [ ] **Step 1: Apply MODEL_VERSION patch**

  Using the Edit tool on `python/services/inference_service.py`:

  ```
  old_string: MODEL_VERSION = "1.1.1"
  new_string: MODEL_VERSION = "2.0.0"
  ```

- [ ] **Step 2: Apply range warning patch**

  Using the Edit tool on `python/services/inference_service.py`:

  ```
  old_string:
            if named_id < 1 or named_id > 3:
                logger.warning(
                    "raw_cluster_id=%s produced out-of-range named_id=%s; clamping to [1,3].",
                    raw_cluster_id, named_id,
                )

  new_string:
            if named_id < 1 or named_id > 4:
                logger.warning(
                    "raw_cluster_id=%s produced out-of-range named_id=%s; clamping to [1,4].",
                    raw_cluster_id, named_id,
                )
  ```

- [ ] **Step 3: Verify grep shows "2.0.0" and no "1.1.1" remains**

  Run: `Select-String -Path "python\services\inference_service.py" -Pattern 'MODEL_VERSION|clamping to'`

  Expected:
  ```
  MODEL_VERSION = "2.0.0"
  clamping to [1,4]
  ```

---

## Task 2: Write sync_models_k4.py

**Files:**
- Create: `python/scripts/sync_models_k4.py`

- [ ] **Step 1: Create the sync script**

  Write `python/scripts/sync_models_k4.py` with full content:

  ```python
  """
  sync_models_k4.py
  =================
  Copies all K=4 model artifacts from osca_output/model/ into python/models/,
  regenerates cluster_centroids_scaled.json, model_manifest.json, and
  regression_baseline.json, then prints a summary.

  Run from repo root:
      python\\venv\\Scripts\\python.exe python\\scripts\\sync_models_k4.py

  Rollback:
      Remove-Item -Recurse -Force python/models/
      Rename-Item python/models_backup_YYYYMMDD_HHMM python/models
  """

  import os
  import sys
  import csv
  import json
  import pickle
  import shutil
  import hashlib
  import warnings
  from datetime import datetime

  warnings.filterwarnings("ignore")

  # ── Paths ─────────────────────────────────────────────────────────────────────
  SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
  REPO_ROOT   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/
  BASE_DIR    = os.path.dirname(REPO_ROOT)                      # one above repo root

  SOURCE_DIR  = os.path.join(BASE_DIR, "osca_output", "model")
  TARGET_DIR  = os.path.join(REPO_ROOT, "python", "models")
  PREDS_CSV   = os.path.join(BASE_DIR, "osca_output", "predictions", "senior_predictions.csv")

  # 23 source files to copy verbatim
  SOURCE_FILES = [
      "kmeans.pkl",           "kmeans_model.pkl",
      "scaler.pkl",
      "umap_reducer.pkl",     "umap_2d.pkl",          "umap_nd.pkl",
      "gbr_ic_risk.pkl",      "gbr_env_risk.pkl",     "gbr_func_risk.pkl",
      "rfr_ic_risk.pkl",      "rfr_env_risk.pkl",     "rfr_func_risk.pkl",
      "edu_encoder.pkl",      "income_encoder.pkl",   "hdbscan.pkl",
      "cluster_mapping.json", "cluster_metadata.json",
      "feature_list.json",    "final_feature_list.json",
      "ml_risk_features.json","best_hyperparameters.json",
      "asset_weights.json",   "vif_retained_features.json",
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


  # ── Step 1: Pre-flight ─────────────────────────────────────────────────────────
  print("=" * 60)
  print("K=4 Model Sync — Pre-flight check")
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
      _abort(f"senior_predictions.csv has only {len(rows_csv)} rows (expected ≥280).")

  print(f"  Source dir:   {SOURCE_DIR}")
  print(f"  Target dir:   {TARGET_DIR}")
  print(f"  Source files: {len(SOURCE_FILES)} files present ✅")
  print(f"  Predictions:  {len(rows_csv)} rows ✅")
  print()

  for fname in SOURCE_FILES:
      fpath = os.path.join(SOURCE_DIR, fname)
      print(f"  {fname:40s}  {os.path.getsize(fpath):>10,} bytes")


  # ── Step 2: Backup ─────────────────────────────────────────────────────────────
  ts = datetime.now().strftime("%Y%m%d_%H%M")
  backup_dir = os.path.join(REPO_ROOT, "python", f"models_backup_{ts}")

  print(f"\nBacking up python/models/ → {os.path.basename(backup_dir)} ...")
  if os.path.isdir(TARGET_DIR):
      shutil.copytree(TARGET_DIR, backup_dir)
      print(f"  Backup created: {backup_dir}")
  else:
      os.makedirs(TARGET_DIR, exist_ok=True)
      print(f"  Target dir did not exist — created fresh: {TARGET_DIR}")


  # ── Step 3: Copy model files ────────────────────────────────────────────────────
  print(f"\nCopying {len(SOURCE_FILES)} files → python/models/ ...")
  os.makedirs(TARGET_DIR, exist_ok=True)
  for fname in SOURCE_FILES:
      src = os.path.join(SOURCE_DIR, fname)
      dst = os.path.join(TARGET_DIR, fname)
      shutil.copy2(src, dst)
      print(f"  ✅ {fname:40s}  {os.path.getsize(dst):>10,} bytes")


  # ── Step 4: Generate cluster_centroids_scaled.json ─────────────────────────────
  print("\nGenerating cluster_centroids_scaled.json ...")

  km_path = os.path.join(TARGET_DIR, "kmeans.pkl")
  with open(km_path, "rb") as f:
      km = pickle.load(f)

  raw_centers = km.cluster_centers_.tolist()   # shape: [n_clusters, n_features]
  n_clusters  = len(raw_centers)
  n_dims      = len(raw_centers[0])
  assert n_clusters == 4, f"Expected 4 centroids, got {n_clusters}"
  assert n_dims == 31,    f"Expected 31 dims, got {n_dims}"

  # Load feature names (the 31 features KMeans was trained on)
  fl_path = os.path.join(TARGET_DIR, "feature_list.json")
  with open(fl_path, encoding="utf-8") as f:
      feature_names = json.load(f)
  assert len(feature_names) == n_dims, \
      f"feature_list.json has {len(feature_names)} features, expected {n_dims}"

  # Map raw cluster IDs → named cluster IDs using cluster_mapping.json
  cm_path = os.path.join(TARGET_DIR, "cluster_mapping.json")
  with open(cm_path, encoding="utf-8") as f:
      cluster_mapping = {int(k): int(v) for k, v in json.load(f).items()}

  # Build centroids dict keyed by named_id string (e.g. "1", "2", "3", "4")
  centroids: dict = {}
  for raw_id, center in enumerate(raw_centers):
      named_id = cluster_mapping.get(raw_id, raw_id + 1)
      centroids[str(named_id)] = center

  centroid_doc = {
      "generated_at": datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S"),
      "method":       "kmeans_cluster_centers",
      "model_version": MODEL_VERSION,
      "feature_names": feature_names,
      "n_features":   n_dims,
      "n_clusters":   n_clusters,
      "centroids":    centroids,
  }
  out_path = os.path.join(TARGET_DIR, "cluster_centroids_scaled.json")
  with open(out_path, "w", encoding="utf-8") as f:
      json.dump(centroid_doc, f, indent=2)
  print(f"  cluster_centroids_scaled.json: {n_clusters} centroids × {n_dims} dims ✅")


  # ── Step 5: Generate model_manifest.json ───────────────────────────────────────
  print("\nGenerating model_manifest.json ...")

  pkl_files = [f for f in os.listdir(TARGET_DIR) if f.endswith(".pkl")]
  checksums = {}
  for fname in sorted(pkl_files):
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
  print(f"  model_manifest.json: SHA256 for {len(checksums)} pkl files, version={MODEL_VERSION} ✅")


  # ── Step 6: Generate regression_baseline.json ─────────────────────────────────
  print("\nGenerating regression_baseline.json ...")

  import unicodedata, re

  def _norm_name(s: str) -> str:
      """Lowercase, NFC, strip accents, collapse non-alphanum."""
      s = unicodedata.normalize("NFC", str(s or ""))
      s = s.replace("ñ", "n").replace("Ñ", "n")
      s = unicodedata.normalize("NFKD", s)
      s = "".join(c for c in s if unicodedata.category(c) != "Mn")
      return re.sub(r"[^a-z0-9]+", "", s.lower())

  def _key(first: str, last: str, barangay: str) -> str:
      return f"{_norm_name(first)}|{_norm_name(last)}|{_norm_name(barangay)}"

  baseline = []
  for row in rows_csv:
      baseline.append({
          "key":              _key(row["first_name"], row["last_name"], row["barangay"]),
          "first_name":       row["first_name"].strip(),
          "last_name":        row["last_name"].strip(),
          "barangay":         row["barangay"].strip(),
          "cluster_named_id": int(float(row["cluster_id"])),
          "overall_risk_level": row["risk_level"].strip().upper(),
          "ic_risk":          float(row["ic_risk"]),
          "env_risk":         float(row["env_risk"]),
          "func_risk":        float(row["func_risk"]),
          "composite_risk":   float(row["composite_risk"]),
      })

  baseline_path = os.path.join(TARGET_DIR, "regression_baseline.json")
  with open(baseline_path, "w", encoding="utf-8") as f:
      json.dump(baseline, f, indent=2)
  print(f"  regression_baseline.json: {len(baseline)} rows ✅")
  if len(baseline) != 283:
      print(f"  ⚠️  Expected 283 rows, got {len(baseline)}")


  # ── Step 7: Summary ─────────────────────────────────────────────────────────────
  print()
  print("=" * 60)
  print("K=4 Model Sync Complete")
  print("=" * 60)
  print(f"  Files copied:    {len(SOURCE_FILES)}")
  print(f"  Centroids:       {n_clusters} × {n_dims}")
  print(f"  Manifest SHA256: computed for {len(checksums)} pkl files")
  print(f"  Baseline rows:   {len(baseline)}")
  print(f"  Backup at:       {backup_dir}")
  print()
  print("Next steps:")
  print("  1. Restart Flask services (preprocess :5001 and inference :5002)")
  print("  2. Run: python\\venv\\Scripts\\python.exe python\\scripts\\validate_k4_sync.py")
  ```

- [ ] **Step 2: Verify the file was created**

  Run: `Test-Path "python\scripts\sync_models_k4.py"`

  Expected: `True`

---

## Task 3: Write validate_k4_sync.py

**Files:**
- Create: `python/scripts/validate_k4_sync.py`

- [ ] **Step 1: Create the validation script**

  Write `python/scripts/validate_k4_sync.py` with full content:

  ```python
  """
  validate_k4_sync.py
  ====================
  Compares live inference against the K=4 notebook ground truth for all 283 seniors.

  Requires:
    - python/models/regression_baseline.json (generated by sync_models_k4.py)
    - Flask preprocess service running on :5001
    - Flask inference service running on :5002
    - DB accessible (uses same .env as other scripts)

  Run from repo root:
      python\\venv\\Scripts\\python.exe python\\scripts\\validate_k4_sync.py

  Exit code 0 = PASS, exit code 1 = FAIL.
  """

  import os
  import sys
  import json
  import csv
  import re
  import unicodedata
  import urllib.request
  import urllib.error
  from datetime import date, datetime
  from collections import defaultdict

  try:
      import pymysql
      import pymysql.cursors
  except ImportError:
      print("[ERROR] pymysql not installed. Run: pip install pymysql")
      sys.exit(1)

  # ── Paths ─────────────────────────────────────────────────────────────────────
  SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
  REPO_ROOT   = os.path.dirname(os.path.dirname(SCRIPT_DIR))
  MODEL_DIR   = os.path.join(REPO_ROOT, "python", "models")

  PREPROCESS_URL = "http://127.0.0.1:5001/preprocess"
  INFER_URL      = "http://127.0.0.1:5002/infer"

  CLUSTER_TARGET  = 0.90   # ≥90% cluster match
  RISK_TARGET     = 0.95   # ≥95% risk-level match
  COMPOSITE_MAX   = 0.01   # Δ max < 0.01


  # ── Helpers ────────────────────────────────────────────────────────────────────
  def _norm_name(s: str) -> str:
      s = unicodedata.normalize("NFC", str(s or ""))
      s = s.replace("ñ", "n").replace("Ñ", "n")
      s = unicodedata.normalize("NFKD", s)
      s = "".join(c for c in s if unicodedata.category(c) != "Mn")
      return re.sub(r"[^a-z0-9]+", "", s.lower())

  def _key(first: str, last: str, barangay: str) -> str:
      return f"{_norm_name(first)}|{_norm_name(last)}|{_norm_name(barangay)}"

  def _read_dotenv(name: str) -> str:
      for candidate in [os.path.join(REPO_ROOT, ".env"),
                        os.path.join(os.path.dirname(REPO_ROOT), ".env")]:
          if os.path.exists(candidate):
              for line in open(candidate, encoding="utf-8"):
                  line = line.strip()
                  if line and not line.startswith("#") and "=" in line:
                      k, _, v = line.partition("=")
                      if k.strip() == name:
                          return v.strip().strip('"').strip("'")
      return ""

  def _db_connect():
      return pymysql.connect(
          host     = os.environ.get("DB_HOST")     or _read_dotenv("DB_HOST")     or "127.0.0.1",
          port     = int(os.environ.get("DB_PORT") or _read_dotenv("DB_PORT")     or 3306),
          user     = os.environ.get("DB_USERNAME") or _read_dotenv("DB_USERNAME") or "root",
          password = os.environ.get("DB_PASSWORD") or _read_dotenv("DB_PASSWORD") or "",
          database = os.environ.get("DB_DATABASE") or _read_dotenv("DB_DATABASE") or "osca_db",
          cursorclass = pymysql.cursors.DictCursor,
          connect_timeout = 5,
      )

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

  def _http_post(url: str, payload: dict) -> dict:
      data = json.dumps(payload).encode("utf-8")
      req  = urllib.request.Request(
          url, data=data,
          headers={"Content-Type": "application/json"},
          method="POST",
      )
      try:
          with urllib.request.urlopen(req, timeout=30) as resp:
              return json.loads(resp.read().decode("utf-8"))
      except urllib.error.URLError as e:
          raise ConnectionError(f"Cannot reach {url}: {e}") from e


  # ── Step 1: Load ground truth ──────────────────────────────────────────────────
  baseline_path = os.path.join(MODEL_DIR, "regression_baseline.json")
  if not os.path.exists(baseline_path):
      print("[ERROR] regression_baseline.json not found. Run sync_models_k4.py first.")
      sys.exit(1)

  with open(baseline_path, encoding="utf-8") as f:
      baseline_list = json.load(f)

  # Keyed by normalised "first|last|barangay"
  baseline: dict = {row["key"]: row for row in baseline_list}
  print(f"Loaded {len(baseline)} ground-truth entries from regression_baseline.json")


  # ── Step 2: Check services are reachable ───────────────────────────────────────
  print("\nChecking service health ...")
  for url, label in [(PREPROCESS_URL.replace("/preprocess", "/health"), "preprocess :5001"),
                     (INFER_URL.replace("/infer", "/health"), "inference :5002")]:
      try:
          with urllib.request.urlopen(url, timeout=5) as r:
              body = json.loads(r.read())
          print(f"  {label}: ✅  {body.get('service','?')}  version={body.get('model_version','?')}")
      except Exception as e:
          print(f"  {label}: ❌  {e}")
          print("\n[ERROR] Services must be running before validation.")
          print("  Start preprocess_service.py on :5001 and inference_service.py on :5002.")
          sys.exit(1)


  # ── Step 3: Load seniors from DB ──────────────────────────────────────────────
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
          qs.h1_belief_comfort, qs.h2_belief_practice
      FROM senior_citizens sc
      JOIN qol_surveys qs ON qs.senior_citizen_id = sc.id
      WHERE sc.deleted_at IS NULL
        AND qs.id = (SELECT MAX(qs2.id) FROM qol_surveys qs2
                     WHERE qs2.senior_citizen_id = sc.id)
      ORDER BY sc.id
  """

  print("\nQuerying seniors from DB ...")
  try:
      conn = _db_connect()
      with conn.cursor() as cur:
          cur.execute(QUERY)
          db_rows = cur.fetchall()
      conn.close()
  except Exception as e:
      print(f"[ERROR] DB connection failed: {e}")
      sys.exit(1)
  print(f"  Loaded {len(db_rows)} seniors with QoL surveys from DB")


  # ── Step 4: Two-step inference for each senior ─────────────────────────────────
  print(f"\nRunning two-step inference for matched seniors ...")

  results = []
  not_in_baseline = 0
  inference_errors = 0

  for row in db_rows:
      k = _key(row["first_name"], row["last_name"], row["barangay"])
      nb = baseline.get(k)
      if nb is None:
          not_in_baseline += 1
          continue

      # Build raw payload — nested qol_responses format required by preprocess service
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
          preprocessed = _http_post(PREPROCESS_URL, raw)
          if preprocessed.get("status") != "success":
              raise ValueError(f"Preprocess failed: {preprocessed.get('error')}")

          infer_result = _http_post(INFER_URL, preprocessed)
          if infer_result.get("status") != "success":
              raise ValueError(f"Infer failed: {infer_result.get('error')}")

          live_cluster       = int(infer_result["cluster"]["named_id"])
          live_risk_level    = str(infer_result["risk_levels"]["overall"]).upper()
          live_composite     = float(infer_result["risk_scores"]["composite"])

          results.append({
              "senior_id":     row["id"],
              "name":          f"{row['first_name']} {row['last_name']}",
              "barangay":      row["barangay"],
              "nb_cluster":    nb["cluster_named_id"],
              "live_cluster":  live_cluster,
              "nb_risk":       nb["overall_risk_level"],
              "live_risk":     live_risk_level,
              "nb_composite":  nb["composite_risk"],
              "live_composite": live_composite,
              "cluster_match": live_cluster == nb["cluster_named_id"],
              "risk_match":    live_risk_level == nb["overall_risk_level"],
              "composite_delta": abs(live_composite - nb["composite_risk"]),
          })

      except Exception as e:
          inference_errors += 1
          if inference_errors <= 5:
              print(f"  ⚠️  Error for id={row['id']} {row['first_name']} {row['last_name']}: {e}")

  total = len(results)
  if total == 0:
      print("[ERROR] No results produced — check service health and baseline matching.")
      sys.exit(1)


  # ── Step 5: Compute metrics ────────────────────────────────────────────────────
  cluster_matches = sum(1 for r in results if r["cluster_match"])
  risk_matches    = sum(1 for r in results if r["risk_match"])
  deltas          = [r["composite_delta"] for r in results]
  delta_max       = max(deltas)
  delta_mean      = sum(deltas) / len(deltas)

  cluster_pct = cluster_matches / total
  risk_pct    = risk_matches / total

  mismatches = [r for r in results if not r["cluster_match"]]


  # ── Step 6: Print report ───────────────────────────────────────────────────────
  print()
  print("=" * 60)
  print(f"K=4 Sync Validation — {total} seniors")
  print("─" * 60)

  def _check(val, target, label, suffix=""):
      icon = "✅" if val >= target else "❌"
      print(f"  {label:<28} {val:>6.1f}%  {icon}  (target ≥{target*100:.0f}%){suffix}")

  def _check_lt(val, target, label):
      icon = "✅" if val < target else "❌"
      print(f"  {label:<28} {val:>9.4f}  {icon}  (target <{target})")

  _check(cluster_pct, CLUSTER_TARGET, "Cluster match:",
         f"  {cluster_matches}/{total}")
  _check(risk_pct, RISK_TARGET, "Risk level match:",
         f"  {risk_matches}/{total}")
  _check_lt(delta_max, COMPOSITE_MAX, "Composite risk Δ max:")
  print(f"  {'Composite risk Δ mean:':<28} {delta_mean:>9.4f}")

  if not_in_baseline > 0:
      print(f"\n  ℹ️  Seniors in DB but not in baseline: {not_in_baseline} (skipped)")
  if inference_errors > 0:
      print(f"  ⚠️  Inference errors: {inference_errors}")

  if mismatches:
      print(f"\nCluster mismatches (showing up to 20):")
      for r in mismatches[:20]:
          borderline = " [borderline]" if r["composite_delta"] < 0.05 else ""
          print(f"  Senior #{r['senior_id']:>4d}  {r['name'][:22]:<22}"
                f"  nb={r['nb_cluster']}  live={r['live_cluster']}"
                f"  Δ={r['composite_delta']:.4f}{borderline}")

  print()
  print("─" * 60)

  passed = (cluster_pct >= CLUSTER_TARGET
            and risk_pct  >= RISK_TARGET
            and delta_max  < COMPOSITE_MAX)

  if passed:
      print("RESULT: PASS")
      print()
      print("All targets met. System is consistent with notebook ground truth.")
      sys.exit(0)
  else:
      print("RESULT: FAIL")
      print()
      failures = []
      if cluster_pct < CLUSTER_TARGET:
          failures.append(f"Cluster match below target ({cluster_pct*100:.1f}% < {CLUSTER_TARGET*100:.0f}%).")
      if risk_pct < RISK_TARGET:
          failures.append(f"Risk level match below target ({risk_pct*100:.1f}% < {RISK_TARGET*100:.0f}%).")
      if delta_max >= COMPOSITE_MAX:
          failures.append(f"Composite risk Δ max too large ({delta_max:.4f} >= {COMPOSITE_MAX}).")
      for f in failures:
          print(f"  {f}")
      print()
      print("To restore previous models:")
      print("  Remove-Item -Recurse -Force python/models/")
      print("  Rename-Item python/models_backup_YYYYMMDD_HHMM python/models")
      sys.exit(1)
  ```

- [ ] **Step 2: Verify the file was created**

  Run: `Test-Path "python\scripts\validate_k4_sync.py"`

  Expected: `True`

---

## Task 4: Execute the sync script

**Files:** (none created — executes sync_models_k4.py)

- [ ] **Step 1: Run the sync script**

  Run from repo root:
  ```
  python\venv\Scripts\python.exe python\scripts\sync_models_k4.py
  ```

  Expected output includes:
  ```
  K=4 Model Sync — Pre-flight check
  Source files: 23 files present ✅
  Predictions:  283 rows ✅
  ...
  K=4 Model Sync Complete
    Files copied:    23
    Centroids:       4 × 31
    Manifest SHA256: computed for 15 pkl files
    Baseline rows:   283
    Backup at:       python/models_backup_...
  ```

  If you see `[ABORT]`, read the error and fix the cause before proceeding.

- [ ] **Step 2: Verify cluster_mapping.json is now K=4**

  Run: `Get-Content "python\models\cluster_mapping.json"`

  Expected:
  ```json
  {
    "0": 1,
    "1": 2,
    "2": 3,
    "3": 4
  }
  ```

- [ ] **Step 3: Verify model_manifest.json version**

  Run: `Get-Content "python\models\model_manifest.json" | Select-String 'model_version'`

  Expected: `"model_version": "2.0.0"`

- [ ] **Step 4: Verify centroids shape**

  Run inline Python:
  ```
  python\venv\Scripts\python.exe -c "import json; d=json.load(open('python/models/cluster_centroids_scaled.json')); print(f\"n_clusters={d['n_clusters']}, n_features={d['n_features']}\")"
  ```

  Expected: `n_clusters=4, n_features=31`

- [ ] **Step 5: Verify regression_baseline.json row count**

  Run inline Python:
  ```
  python\venv\Scripts\python.exe -c "import json; b=json.load(open('python/models/regression_baseline.json')); print(f'rows={len(b)}')"
  ```

  Expected: `rows=283`

---

## Task 5: Restart Flask services

**Files:** (none modified — service restart only)

- [ ] **Step 1: Stop running Flask services**

  Kill any processes on ports 5001 and 5002:
  ```powershell
  Stop-Process -Name python -ErrorAction SilentlyContinue
  ```

  Or if using specific process management, stop preprocess_service.py and inference_service.py.

- [ ] **Step 2: Start preprocess service**

  In a new terminal / background:
  ```
  cd python\services
  ..\venv\Scripts\python.exe preprocess_service.py
  ```

  Wait until you see: `Running on http://127.0.0.1:5001`

- [ ] **Step 3: Start inference service**

  In another terminal / background:
  ```
  cd python\services
  ..\venv\Scripts\python.exe inference_service.py
  ```

  Wait until you see: `Running on http://127.0.0.1:5002`

- [ ] **Step 4: Confirm health endpoints return model_version 2.0.0**

  Run:
  ```
  python\venv\Scripts\python.exe -c "import urllib.request,json; r=urllib.request.urlopen('http://127.0.0.1:5002/health'); print(json.loads(r.read()))"
  ```

  Expected output contains: `'model_version': '2.0.0'`

---

## Task 6: Run validation and confirm PASS

**Files:** (none created — executes validate_k4_sync.py)

- [ ] **Step 1: Run the validation script**

  Run from repo root:
  ```
  python\venv\Scripts\python.exe python\scripts\validate_k4_sync.py
  ```

  Expected output:
  ```
  K=4 Sync Validation — 283 seniors
  ────────────────────────────────────────────────────────────
  Cluster match:               ≥90.0%  ✅  (target ≥90%)
  Risk level match:            ≥95.0%  ✅  (target ≥95%)
  Composite risk Δ max:        <0.01   ✅  (target <0.01)
  ...
  ────────────────────────────────────────────────────────────
  RESULT: PASS
  ```

- [ ] **Step 2: On PASS — confirm success criteria checklist**

  Verify all are true:
  - [ ] `cluster_mapping.json` has 4 entries (K=4)
  - [ ] `cluster_centroids_scaled.json` shape is 4 × 31
  - [ ] `model_manifest.json` version = "2.0.0"
  - [ ] `regression_baseline.json` has 283 entries
  - [ ] Health check returns `model_version: "2.0.0"`
  - [ ] Validation exits 0 (PASS), cluster match ≥90%, risk match ≥95%, Δ <0.01

- [ ] **Step 3: On FAIL — restore backup and investigate**

  If the script exits with code 1:
  ```powershell
  Remove-Item -Recurse -Force python\models\
  $backup = Get-ChildItem python\ -Directory | Where-Object { $_.Name -like "models_backup_*" } | Select-Object -Last 1
  Rename-Item $backup.FullName python\models
  ```

  Then restart Flask services and investigate mismatches from the FAIL report.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Verify 23 source files present | Task 4 / sync pre-flight |
| Backup before any writes | Task 4 / sync Step 2 |
| Copy 23 files verbatim | Task 4 / sync Step 3 |
| Generate cluster_centroids_scaled.json (4×31) | Task 4 / sync Step 4 |
| Generate model_manifest.json (version 2.0.0) | Task 4 / sync Step 5 |
| Generate regression_baseline.json (283 rows) | Task 4 / sync Step 6 |
| Patch named_id clamp to min(4) | Already correct in code — verified in Task 1 |
| Patch CLUSTER_PROFILES 4th entry | Already correct in code — verified in Task 1 |
| Patch MODEL_VERSION to "2.0.0" | Task 1 |
| Restart Flask services | Task 5 |
| Validate ≥90% cluster match | Task 6 |
| Validate ≥95% risk level match | Task 6 |
| Validate composite Δ <0.01 | Task 6 |
| Rollback instructions on FAIL | Task 6 Step 3 |

**Placeholder scan:** All steps have complete code. No TBDs.

**Type consistency:** `cluster_named_id` used consistently in baseline JSON and comparison. Named_id integers throughout. `overall_risk_level` always `.upper()` normalized before comparison.
