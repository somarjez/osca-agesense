# Wellbeing Propagation + Deterministic Cluster Assignment — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix wellbeing score drift through the DB cache and add cross-device deterministic cluster assignment via nearest-centroid in the scaled feature space.

**Architecture:** Three changes to `inference_service.py` (wellbeing propagation in `_db_cache_lookup` + two usage sites; new flag + two functions + one integration block), one new standalone generation script (`generate_cluster_centroids.py`) that queries the DB to build centroid data from the live pipeline, one generated JSON artifact committed alongside the models, an extension to `validate_model_artifacts.py`, and a `.env.example` documentation update.

**Tech Stack:** Python 3, pymysql, numpy, pandas, scikit-learn (scaler), UMAP, scikit-learn KMeans; Laravel .env credentials reused for DB connection; existing pytest-style standalone test scripts in `python/tests/`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `python/services/inference_service.py` | Modify | Fix 1 (wellbeing × 3 edits) + Fix 2 (flag + 2 new functions + 1 integration block) |
| `python/scripts/generate_cluster_centroids.py` | Create | One-time script: DB → preprocess → UMAP+KMeans → centroids JSON |
| `python/models/cluster_centroids_scaled.json` | Create (run script) | Generated artifact — committed, no PII |
| `python/scripts/validate_model_artifacts.py` | Modify | Fix 3: centroids consistency check + UMAP agreement check |
| `.env.example` | Modify | Document `ENABLE_DETERMINISTIC_CLUSTER` |
| `python/tests/test_inference_paths.py` | Modify | Add tests for wellbeing propagation and deterministic cluster functions |

---

## Task 1: Fix wellbeing propagation in `_db_cache_lookup()` and `infer()`

**Files:**
- Modify: `python/services/inference_service.py` (lines 369–386, ~1399, ~1628)
- Modify: `python/tests/test_inference_paths.py`

**Context:** `_db_cache_lookup()` SELECTs `wellbeing_score` from `ml_results` but never adds it to the returned dict, so `infer()` always recomputes it from `section_scores["overall_wellbeing"]`. Three edits pin it after the first run.

- [ ] **Step 1: Add `wellbeing_score` to `_db_cache_lookup()` return dict**

In `inference_service.py`, the return dict of `_db_cache_lookup()` ends at approximately line 386:

```python
            "_model_version":     row.get("model_version") or "",
        }
```

Replace that closing line with:

```python
            "_model_version":     row.get("model_version") or "",
            "wellbeing_score":    float(row["wellbeing_score"] or 0.5),
        }
```

- [ ] **Step 2: Verify the edit looks correct**

Run:
```powershell
python/venv/Scripts/python.exe -c "
import os, sys
os.environ['ML_MODELS_PATH'] = 'python/models'
os.environ['ENABLE_NOTEBOOK_OVERRIDES'] = 'false'
sys.path.insert(0, 'python/services')
import inspect, inference_service
src = inspect.getsource(inference_service._db_cache_lookup)
assert 'wellbeing_score' in src, 'MISSING wellbeing_score in _db_cache_lookup'
print('OK: wellbeing_score present in _db_cache_lookup')
"
```
Expected: `OK: wellbeing_score present in _db_cache_lookup`

- [ ] **Step 3: Fix the notebook-cache early-return block (Step B in spec)**

Search `inference_service.py` for:
```python
                "wellbeing_score": round(float(section_scores.get("overall_wellbeing", 0.5)), 4),
```
This line is inside the early-return block that fires when `prediction_source = notebook_cache` (around line 1399). Replace it with:

```python
                "wellbeing_score": round(
                    float(_db_cached.get("wellbeing_score") or section_scores.get("overall_wellbeing", 0.5)),
                    4
                ),
```

- [ ] **Step 4: Fix the normal inference path (Step C in spec)**

Search `inference_service.py` for:
```python
    wellbeing_score = float(section_scores.get("overall_wellbeing", 0.5))
```
(Around line 1628.) Insert these two lines **immediately after** it:

```python
    # DB-cache override: pin wellbeing to the first-run value so it never drifts.
    if _db_cached and _db_cached.get("wellbeing_score"):
        wellbeing_score = float(_db_cached["wellbeing_score"])
```

- [ ] **Step 5: Add wellbeing propagation tests to `test_inference_paths.py`**

Append these checks to the bottom of `python/tests/test_inference_paths.py` (before the final `sys.exit` if one exists, otherwise at the end):

```python
print("=== Wellbeing propagation in _db_cache_lookup ===")
import inspect
import inference_service as _inf_svc   # module ref (already in sys.modules)
src = inspect.getsource(_inf_svc._db_cache_lookup)
check("wellbeing_score in _db_cache_lookup return dict",
      "wellbeing_score" in src, True)

# Confirm Step B: notebook-cache early-return uses _db_cached.get("wellbeing_score")
infer_src = inspect.getsource(_inf_svc.infer)
check("notebook-cache path uses _db_cached wellbeing",
      '_db_cached.get("wellbeing_score") or section_scores' in infer_src, True)

# Confirm Step C: normal path overrides from DB cache
check("normal path overrides wellbeing from DB cache",
      'if _db_cached and _db_cached.get("wellbeing_score")' in infer_src, True)
print()
```

- [ ] **Step 6: Run the tests**

```powershell
python/venv/Scripts/python.exe python/tests/test_inference_paths.py
```
Expected: All lines print `[OK]`, no `[FAIL]`.

- [ ] **Step 7: Commit**

```bash
git add python/services/inference_service.py python/tests/test_inference_paths.py
git commit -m "fix: propagate wellbeing_score through DB cache in inference_service

_db_cache_lookup() now returns wellbeing_score. infer() uses the stored
value in both the notebook-cache early-return path and the normal path,
preventing drift when preprocessing weights change between runs."
```

---

## Task 2: Add `ENABLE_DETERMINISTIC_CLUSTER` flag and helper functions

**Files:**
- Modify: `python/services/inference_service.py` (after line 123; after loaders section ~line 736)
- Modify: `python/tests/test_inference_paths.py`

**Context:** Adds the feature flag, a cached JSON loader, and the pure-arithmetic nearest-centroid function. No integration in `infer()` yet — that's Task 3.

- [ ] **Step 1: Add the feature flag after `ENABLE_NOTEBOOK_OVERRIDES`**

Search `inference_service.py` for:
```python
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", False)
```
Insert one line immediately after it:

```python
ENABLE_DETERMINISTIC_CLUSTER = _env_flag("ENABLE_DETERMINISTIC_CLUSTER", False)
```

- [ ] **Step 2: Add `_load_cluster_centroids_scaled()` after the loaders block**

Search `inference_service.py` for:
```python
def _normalize_identity_part(value: Any) -> str:
```
Insert the following block **immediately before** that function definition:

```python
@lru_cache(maxsize=1)
def _load_cluster_centroids_scaled() -> Optional[Dict[str, Any]]:
    """Load cluster_centroids_scaled.json from MODEL_DIR (cached after first load)."""
    path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


```

- [ ] **Step 3: Add `_deterministic_cluster_assign()` immediately after the loader**

Insert this block immediately after `_load_cluster_centroids_scaled()`:

```python
def _deterministic_cluster_assign(
    vector: List[float],
    feature_names: List[str],
) -> Optional[int]:
    """
    Assign named cluster ID (1, 2, or 3) by nearest L2 centroid in the
    N-dimensional scaled feature space (N = len(feature_list.json)).
    Returns None if the centroids file is missing — caller falls back to UMAP.
    """
    data = _load_cluster_centroids_scaled()
    if not data:
        return None
    centroid_names: List[str] = data["feature_names"]
    centroids: Dict[str, List[float]] = data["centroids"]
    feat_idx = {f: i for i, f in enumerate(feature_names)}
    best_id: Optional[int] = None
    best_dist = float("inf")
    for named_id_str, centroid in centroids.items():
        dist = sum(
            (float(centroid[j]) - (vector[feat_idx[f]] if f in feat_idx else 0.0)) ** 2
            for j, f in enumerate(centroid_names)
        )
        if dist < best_dist:
            best_dist = dist
            best_id = int(named_id_str)
    return best_id


```

- [ ] **Step 4: Add env-var guard + tests for the new functions**

First, add `os.environ["ENABLE_DETERMINISTIC_CLUSTER"] = "false"` to the env-var
setup block at the top of `python/tests/test_inference_paths.py`. Find:
```python
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "false"
```
Insert immediately after it:
```python
os.environ["ENABLE_DETERMINISTIC_CLUSTER"] = "false"
```
This prevents the test from breaking after Task 6 sets the flag to `true` in `.env`.

Then append to the bottom of `python/tests/test_inference_paths.py`:

```python
print("=== ENABLE_DETERMINISTIC_CLUSTER flag ===")
from inference_service import ENABLE_DETERMINISTIC_CLUSTER, _deterministic_cluster_assign, _load_cluster_centroids_scaled
check("flag defaults to False", ENABLE_DETERMINISTIC_CLUSTER, False)
print()

print("=== _load_cluster_centroids_scaled (no file → None) ===")
# cluster_centroids_scaled.json does not exist yet — function should return None
result = _load_cluster_centroids_scaled()
check("returns None when centroids file absent", result, None)
print()

print("=== _deterministic_cluster_assign (no file → None) ===")
dummy_vector = [3.0] * 31
dummy_names  = [f"feat_{i}" for i in range(31)]
result = _deterministic_cluster_assign(dummy_vector, dummy_names)
check("returns None when centroids file absent", result, None)
print()
```

- [ ] **Step 5: Run the tests**

```powershell
python/venv/Scripts/python.exe python/tests/test_inference_paths.py
```
Expected: All `[OK]`, including the three new checks.

- [ ] **Step 6: Commit**

```bash
git add python/services/inference_service.py python/tests/test_inference_paths.py
git commit -m "feat: add ENABLE_DETERMINISTIC_CLUSTER flag and centroid-assign helpers

New flag (default false). _load_cluster_centroids_scaled() loads the
centroid JSON with lru_cache. _deterministic_cluster_assign() computes
nearest named cluster by L2 distance in the scaled feature space.
Both fall back safely when cluster_centroids_scaled.json is absent."
```

---

## Task 3: Integrate the deterministic path inside `infer()`

**Files:**
- Modify: `python/services/inference_service.py` (single insertion in the `else:` block ~line 1483)

**Context:** The deterministic block goes inside the `else:` single-senior path, after `feature_names = _load_json("feature_list.json")` and before the UMAP `if` block. Setting `preprocessed["_precomputed_named_id"]` prevents the cluster-map re-lookup block (which runs after the if/elif/else) from overwriting the deterministically assigned `named_id`.

- [ ] **Step 1: Locate the exact insertion point**

In `inference_service.py`, search for:
```python
        feature_names = _load_json("feature_list.json")

        if scaler is not None and reducer is not None and kmeans is not None and feature_map:
```
The insertion goes between these two blocks.

- [ ] **Step 2: Insert the deterministic block**

Replace:
```python
        feature_names = _load_json("feature_list.json")

        if scaler is not None and reducer is not None and kmeans is not None and feature_map:
```

With:
```python
        feature_names = _load_json("feature_list.json")

        # ── Deterministic cluster assignment ─────────────────────────────────
        # Bypasses UMAP for cross-device reproducibility. Only runs when:
        #   • ENABLE_DETERMINISTIC_CLUSTER=true in .env
        #   • No DB cache result exists for this senior yet
        #   • cluster_centroids_scaled.json is present in MODEL_DIR
        # Falls back to the live UMAP+KMeans path automatically if the file
        # is missing or the flag is off.
        _det_named_id: Optional[int] = None
        if ENABLE_DETERMINISTIC_CLUSTER and not _db_cached:
            _det_named_id = _deterministic_cluster_assign(
                scaled_features,   # N-dim vector already in preprocessed (line ~1445)
                feature_names,     # from feature_list.json, loaded 2 lines above
            )

        if _det_named_id is not None:
            named_id = max(1, min(3, _det_named_id))
            cluster_profile = cluster_profiles[named_id]
            # Reverse-lookup raw_cluster_id: cluster_map is {0:3, 1:1, 2:2},
            # NOT a simple +1 offset — must look up by value, not index.
            raw_cluster_id = next(
                (raw_id for raw_id, mapped_id in (cluster_map or {}).items()
                 if mapped_id == named_id),
                0,
            )
            # Inject into preprocessed so the cluster-map re-lookup block
            # below ("if '_precomputed_named_id' not in preprocessed:") is
            # skipped and does not overwrite our named_id.
            preprocessed = dict(preprocessed)
            preprocessed["_precomputed_named_id"]      = named_id
            preprocessed["_precomputed_raw_cluster_id"] = raw_cluster_id
            warnings_list.append("Deterministic cluster assignment used (UMAP skipped).")

        if scaler is not None and reducer is not None and kmeans is not None and feature_map:
```

- [ ] **Step 3: Verify the edit compiles cleanly**

```powershell
python/venv/Scripts/python.exe -c "
import os, sys
os.environ['ML_MODELS_PATH'] = 'python/models'
os.environ['ENABLE_NOTEBOOK_OVERRIDES'] = 'false'
sys.path.insert(0, 'python/services')
import inference_service
print('OK: inference_service imported without errors')
print('ENABLE_DETERMINISTIC_CLUSTER =', inference_service.ENABLE_DETERMINISTIC_CLUSTER)
"
```
Expected: `OK: inference_service imported without errors`

- [ ] **Step 4: Verify integration test (no centroids file → UMAP still runs)**

```powershell
python/venv/Scripts/python.exe python/tests/test_inference_paths.py
```
Expected: All `[OK]`.

- [ ] **Step 5: Commit**

```bash
git add python/services/inference_service.py
git commit -m "feat: integrate deterministic cluster path in infer()

When ENABLE_DETERMINISTIC_CLUSTER=true and no DB cache exists,
_deterministic_cluster_assign() assigns the cluster by nearest L2
centroid in the scaled feature space, bypassing UMAP. Falls through
to live UMAP+KMeans when the centroids file is absent (safe default).
_precomputed_named_id injection prevents the cluster-map re-lookup
block from overwriting the deterministic result."
```

---

## Task 4: Write `generate_cluster_centroids.py` and generate the artifact

**Files:**
- Create: `python/scripts/generate_cluster_centroids.py`
- Create: `python/models/cluster_centroids_scaled.json` (by running the script)

**Context:** The script connects to the DB using the same `.env` credentials as `inference_service.py`, queries the 283 seeded seniors (those with `prediction_source = 'notebook_cache'` in `ml_results`), runs `preprocess_service.preprocess()` on each to get their `scaled_features`, then batch-runs the live UMAP + KMeans pipeline to get cluster assignments, groups by cluster, and computes centroid means. **Important:** `senior_predictions.csv` does NOT contain raw feature values — the DB is the only source of that data.

- [ ] **Step 1: Create `python/scripts/generate_cluster_centroids.py`**

```python
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
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")

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
```

- [ ] **Step 2: Run the script (DB must be running)**

```powershell
python/venv/Scripts/python.exe python/scripts/generate_cluster_centroids.py
```

Expected output (counts will vary by training data):
```
MODEL_DIR: python/models
Models loaded. Feature list: 31 features.
Connecting to DB...
Loaded 283 seeded seniors from DB (prediction_source = notebook_cache).
Preprocessed 283 seniors (0 skipped).
Running live UMAP transform on full batch...
Live pipeline vs DB cluster agreement: 280/283
Cluster 1: 94 seniors, centroid shape (31,)
Cluster 2: 101 seniors, centroid shape (31,)
Cluster 3: 88 seniors, centroid shape (31,)
Written: python/models/cluster_centroids_scaled.json
```

Accept disagreement up to ~5% — UMAP can shift a few boundary cases.

- [ ] **Step 3: Verify the generated file**

```powershell
python/venv/Scripts/python.exe -c "
import json
with open('python/models/cluster_centroids_scaled.json') as f:
    d = json.load(f)
assert set(d['centroids'].keys()) == {'1','2','3'}, 'bad cluster keys'
assert d['n_features'] == len(d['feature_names']), 'n_features mismatch'
assert d['n_features'] == 31, f'expected 31, got {d[\"n_features\"]}'
for k, c in d['centroids'].items():
    assert len(c) == d['n_features'], f'cluster {k} has wrong centroid length'
print('cluster_centroids_scaled.json is valid')
print(f'  clusters: {list(d[\"centroids\"].keys())}')
print(f'  features: {d[\"n_features\"]}')
print(f'  generated_at: {d[\"generated_at\"]}')
"
```
Expected: `cluster_centroids_scaled.json is valid`

- [ ] **Step 4: Commit both the script and the generated artifact**

```bash
git add python/scripts/generate_cluster_centroids.py python/models/cluster_centroids_scaled.json
git commit -m "feat: add generate_cluster_centroids.py + generated centroid artifact

generate_cluster_centroids.py queries seeded seniors (prediction_source=
notebook_cache) from the DB, runs preprocess_service.preprocess() to get
their scaled feature vectors, batch-runs the live UMAP+KMeans pipeline to
get cluster assignments, and writes cluster_centroids_scaled.json.

cluster_centroids_scaled.json contains no PII — only aggregate cluster
statistics (mean scaled feature vectors per cluster)."
```

---

## Task 5: Update `validate_model_artifacts.py` with centroids checks

**Files:**
- Modify: `python/scripts/validate_model_artifacts.py` (insert after end of the pipeline forward-pass block, before section [9])

**Context:** The existing section [8] ends with `_pass("End-to-end forward pass ...")` inside a try/except block. The new checks use `test_cluster` (the 31D UMAP-input vector already computed in that block) and `fl2` (feature_list.json names, also already in scope).

- [ ] **Step 1: Locate the insertion point**

In `validate_model_artifacts.py`, find:
```python
    except Exception as exc:
        _fail("End-to-end forward pass", str(exc))
else:
    _warn("Cross-file consistency check", "Skipped — one or more required files missing.")
```
The new centroids block is inserted **between** the `_fail(...)` line and the `else:` line — i.e., after the `except` block ends but still inside the outer `if _all_present:` block.

- [ ] **Step 2: Insert the centroids consistency + agreement checks**

Replace:
```python
    except Exception as exc:
        _fail("End-to-end forward pass", str(exc))
else:
    _warn("Cross-file consistency check", "Skipped — one or more required files missing.")
```

With:
```python
    except Exception as exc:
        _fail("End-to-end forward pass", str(exc))

    # ── Centroids file (optional) ─────────────────────────────────────────────
    centroid_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if os.path.exists(centroid_path):
        try:
            with open(centroid_path, encoding="utf-8") as _f:
                cd = json.load(_f)
            if set(cd["centroids"].keys()) != {"1", "2", "3"}:
                _fail("Cluster centroids file",
                      f"Expected keys 1,2,3 — got {list(cd['centroids'].keys())}")
            elif cd["feature_names"] != fl2:
                _fail("Cluster centroids file",
                      "feature_names mismatch with feature_list.json")
            else:
                _pass("Cluster centroids file",
                      f"{len(cd['centroids'])} clusters, {cd['n_features']} features, "
                      f"generated {cd.get('generated_at','unknown')}")

                # Agreement check: deterministic and UMAP paths should agree on the
                # well-centred test vector. Use test_cluster[0] (the 31D vector already
                # sent to UMAP above) so the feature spaces are identical.
                import sys as _sys
                _svc_path = os.path.join(BASE_DIR, "python", "services")
                if _svc_path not in _sys.path:
                    _sys.path.insert(0, _svc_path)
                from inference_service import _deterministic_cluster_assign
                det_id = _deterministic_cluster_assign(
                    test_cluster[0].tolist(), fl2
                )
                if det_id is not None and det_id != named:
                    _warn(
                        "Deterministic vs UMAP cluster agreement",
                        f"Deterministic assigned C{det_id}, UMAP assigned C{named} — "
                        "may diverge near cluster boundaries; review if unexpected."
                    )
                elif det_id is not None:
                    _pass("Deterministic vs UMAP cluster agreement",
                          f"Both assign C{named}")
        except Exception as _exc:
            _fail("Cluster centroids file", str(_exc))
    else:
        _warn("Cluster centroids file",
              "Not found — run generate_cluster_centroids.py to enable deterministic mode")

else:
    _warn("Cross-file consistency check", "Skipped — one or more required files missing.")
```

- [ ] **Step 3: Run the validator**

```powershell
python/venv/Scripts/python.exe python/scripts/validate_model_artifacts.py
```

Expected output includes new checks:
```
[PASS] Cluster centroids file: 3 clusters, 31 features, generated 2026-05-25T...
[PASS] Deterministic vs UMAP cluster agreement: Both assign C<N>
```
No `[FAIL]` lines. (A `[WARN]` on the agreement check is acceptable for a boundary-case test vector.)

- [ ] **Step 4: Commit**

```bash
git add python/scripts/validate_model_artifacts.py
git commit -m "test: add centroids consistency + UMAP agreement checks to validate_model_artifacts

New checks (both optional/warn-only when file absent):
- Centroids file key structure and feature_names match feature_list.json
- Deterministic path agrees with live UMAP path on the well-centred test vector
  (WARN not FAIL — test vector may be near a cluster boundary)"
```

---

## Task 6: Document `ENABLE_DETERMINISTIC_CLUSTER` in `.env.example`

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Insert the documentation block**

In `.env.example`, find:
```
ENABLE_NOTEBOOK_OVERRIDES=true
```

Insert the following block immediately after it:

```
# ── Cross-device deterministic cluster assignment ──────────────────────────────
#
# ENABLE_DETERMINISTIC_CLUSTER controls how new seniors (not yet in the DB cache)
# get their cluster assigned when analyzed on a fresh device.
#
#   false (DEFAULT — safe):
#     Live UMAP transform assigns the cluster. Result may differ slightly from
#     another device due to OS/BLAS/hardware non-determinism in UMAP, until the
#     DB cache synchronises the result across devices.
#
#   true (RECOMMENDED for multi-device teams):
#     Cluster is assigned by nearest L2 distance to pre-computed centroids in
#     the scaled feature space — pure arithmetic, identical on every platform.
#     Requires python/models/cluster_centroids_scaled.json to exist (run
#     generate_cluster_centroids.py once to produce it).
#     Falls back to live UMAP automatically if the JSON file is absent.
#
ENABLE_DETERMINISTIC_CLUSTER=false
```

- [ ] **Step 2: Also enable the flag in the actual `.env` (if the file exists)**

Check if `.env` exists and already has the flag:

```powershell
if (Test-Path ".env") {
    $content = Get-Content ".env" -Raw
    if ($content -notmatch "ENABLE_DETERMINISTIC_CLUSTER") {
        Add-Content ".env" "`nENABLE_DETERMINISTIC_CLUSTER=true"
        Write-Host "Added ENABLE_DETERMINISTIC_CLUSTER=true to .env"
    } else {
        Write-Host ".env already contains ENABLE_DETERMINISTIC_CLUSTER"
    }
} else {
    Write-Host ".env does not exist — skipping"
}
```

- [ ] **Step 3: Run the full test suite to confirm nothing is broken**

```powershell
python/venv/Scripts/python.exe python/tests/test_inference_paths.py
python/venv/Scripts/python.exe python/scripts/validate_model_artifacts.py
```

Expected: All `[OK]` in inference_paths; no `[FAIL]` in validate_model_artifacts.

- [ ] **Step 4: Commit**

```bash
git add .env.example
git commit -m "docs: document ENABLE_DETERMINISTIC_CLUSTER in .env.example

Describes the flag, its default (false = safe), recommended value for
multi-device teams (true), and the prerequisite (generate_cluster_centroids.py
must be run once to produce cluster_centroids_scaled.json)."
```

---

## Final: Push and open PR

- [ ] **Run the PHP test suite**

```powershell
php artisan test --stop-on-failure
```
Expected: All tests pass (no PHP changes in this feature).

- [ ] **Push and open PR**

```bash
git push origin feature/export-batch-activitylog-fixes
gh pr create \
  --title "fix: wellbeing propagation + deterministic cluster assignment" \
  --body "## Summary
- **Fix 1 (wellbeing drift):** \`_db_cache_lookup()\` now returns \`wellbeing_score\`. \`infer()\` uses the stored value in both the \`notebook_cache\` early-return path and the normal path, preventing drift when preprocessing weights change.
- **Fix 2 (cross-device cluster):** \`ENABLE_DETERMINISTIC_CLUSTER=true\` + \`cluster_centroids_scaled.json\` bypass UMAP with nearest-centroid L2 assignment in the 31D scaled feature space — pure arithmetic, identical on every platform.
- **Fix 3 (validation):** \`validate_model_artifacts.py\` checks centroids file consistency and agreement with the live UMAP path.

## New files
- \`python/scripts/generate_cluster_centroids.py\` — run once to generate centroids
- \`python/models/cluster_centroids_scaled.json\` — committed artifact (no PII)

## Test plan
- [ ] \`python/venv/Scripts/python.exe python/tests/test_inference_paths.py\` — all OK
- [ ] \`python/venv/Scripts/python.exe python/scripts/validate_model_artifacts.py\` — no FAIL
- [ ] \`php artisan test\` — all pass

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```
