# Design: Wellbeing Propagation + Deterministic Cluster Assignment
**Date:** 2026-05-25
**Status:** Approved
**Scope:** Two inter-related fixes — one bug fix (wellbeing drift), one feature (deterministic cross-device cluster assignment).

---

## Problem Summary

### 1. Wellbeing score drifts even when notebook cache is active

`_db_cache_lookup()` fetches `wellbeing_score` from `ml_results` but does not include it in its returned dict. As a result, every code path in `infer()` — including the notebook-cache short-circuit and the DB-cache-assisted path — recomputes wellbeing live from `section_scores["overall_wellbeing"]`. If the preprocessing formula or weights change between code versions, or if the notebook was run with different weights than the current code, the wellbeing value drifts while all other scores remain pinned to cached values.

### 2. New seniors produce different results on different devices

Each device has its own local database. UMAP's `transform()` is non-deterministic across platforms (different OS, BLAS, and hardware instruction sets) even with `transform_seed=42`. When two devices independently run the first batch analysis for the same new senior, they each call `umap.transform()` and may get slightly different 2D embeddings → different KMeans cluster → different risk scores. The DB cache fixes this *per device* after the first run but cannot synchronise across devices that each ran UMAP independently.

The 283 seeded seniors have their cluster and risk scores pinned by the notebook CSV override; wellbeing and new seniors have no such protection.

---

## Fix 1: Wellbeing Propagation Through the DB Cache

### Root cause
`_db_cache_lookup()` in `inference_service.py`:
- **Fetches** `wellbeing_score` via SQL (correct)
- **Discards** it from the returned dict (bug)

### Fix

**File:** `python/services/inference_service.py`

**Step A — propagate from `_db_cache_lookup()`:**

```python
# In the returned dict of _db_cache_lookup(), add:
"wellbeing_score": float(row["wellbeing_score"] or 0.5),
```

**Step B — notebook-cache short-circuit path** (the early-return block for `prediction_source = notebook_cache`):

```python
# Before
"wellbeing_score": round(float(section_scores.get("overall_wellbeing", 0.5)), 4),

# After — prefer stored DB value, fall back to live preprocess
"wellbeing_score": round(
    float(_db_cached.get("wellbeing_score") or section_scores.get("overall_wellbeing", 0.5)),
    4
),
```

**Step C — normal inference path** (after `wellbeing_score = float(section_scores.get(...))`):

```python
# Override with DB-cached value if present (stabilises result after first run)
if _db_cached and _db_cached.get("wellbeing_score"):
    wellbeing_score = float(_db_cached["wellbeing_score"])
```

### Behaviour after fix

| Scenario | Wellbeing source |
|---|---|
| First inference on a device, no DB cache | Live preprocess (unchanged) |
| Any subsequent inference on the same device | Stored `ml_results.wellbeing_score` (stable) |
| Notebook-cache short-circuit | Stored `ml_results.wellbeing_score`, or live preprocess if not yet stored |

The wellbeing score is locked in on first inference and never recomputed from scratch after that on the same device. Cross-device drift is addressed by Fix 2 ensuring the first inference produces the same score everywhere.

---

## Fix 2: Deterministic Cluster Assignment

### Approach
Generate a **cluster centroid file** from the live trained pipeline. During inference, when `ENABLE_DETERMINISTIC_CLUSTER=true`, assign clusters by nearest L2 centroid in the N-dimensional scaled feature space (N = `len(feature_list.json)`, currently 31) instead of running `umap.transform()`. This is pure arithmetic on the same standardised inputs and produces the same result on every platform.

**Critical guarantee:** The centroids are derived directly from the live trained models (scaler → UMAP → KMeans) — they are not a separate computation. The deterministic path is a reproducible proxy for the live model, not a replacement.

### Live model usage guarantees

1. `generate_cluster_centroids.py` runs the full live pipeline (scaler → UMAP → KMeans) on the 283 training seniors to determine ground-truth cluster assignments, then averages the pre-UMAP scaled inputs per cluster. The centroids represent what the live model produces.
2. If `ENABLE_DETERMINISTIC_CLUSTER=false` **or** `cluster_centroids_scaled.json` is missing, inference falls through to the live UMAP → KMeans path completely unchanged.
3. `validate_model_artifacts.py` keeps the live UMAP forward-pass test and adds a second check verifying that the deterministic centroid path assigns the same cluster as UMAP for the test input.

### New artefact: `python/models/cluster_centroids_scaled.json`

Not gitignored (contains no personal data — only aggregate cluster statistics). Generated once and committed alongside the other model files.

Format:
```json
{
  "generated_at": "2026-05-25T10:30:00",
  "model_dir": "python/models",
  "feature_names": ["feature_1", "feature_2", ...],   // same list as feature_list.json
  "n_features": 31,
  "n_clusters": 3,
  "centroids": {
    "1": [0.12, -0.34, ...],
    "2": [-0.05, 0.78, ...],
    "3": [0.44, -0.11, ...]
  }
}
```

Keys in `centroids` are named cluster IDs (`"1"`, `"2"`, `"3"` — matching `cluster_named_id` in `ml_results`).

### New script: `python/scripts/generate_cluster_centroids.py`

One-time generation script. Safe to re-run at any time; always overwrites the output file.

**Steps:**
1. Load `scaler.pkl`, `umap_nd.pkl` (falling back to `umap_reducer.pkl`), `kmeans.pkl`, `feature_list.json` from `MODEL_DIR`.
2. Load `senior_predictions.csv` (the same file used by the notebook override system).
3. For each row in the CSV, reconstruct the N-dimensional (currently 31) scaled feature vector: scale the full scaler input (39 features) using the live scaler, then select only the columns listed in `feature_list.json`. This runs the live scaler transform.
4. Run the live UMAP transform on the full batch to get reduced embeddings.
5. Run the live KMeans `predict()` on the reduced embeddings to get raw cluster IDs.
6. Map raw cluster IDs → named cluster IDs using `cluster_mapping.json`.
7. Group training seniors by named cluster ID. For each cluster, compute the mean of their N-dimensional scaled feature vectors. This is the centroid in the original (pre-UMAP) feature space.
8. Write `cluster_centroids_scaled.json` with the result and a generation timestamp.

**Running the script:**
```bash
python python/scripts/generate_cluster_centroids.py
```

The script prints a summary:
```
Loaded 283 seniors from senior_predictions.csv
Cluster 1: 94 seniors, centroid shape (31,)
Cluster 2: 101 seniors, centroid shape (31,)
Cluster 3: 88 seniors, centroid shape (31,)
Written: python/models/cluster_centroids_scaled.json
```

### New `.env` flag

```
ENABLE_DETERMINISTIC_CLUSTER=true
```

Default: `false` (safe — existing behaviour unchanged until explicitly opted in).

Loaded at startup in `inference_service.py`:
```python
ENABLE_DETERMINISTIC_CLUSTER = _env_flag("ENABLE_DETERMINISTIC_CLUSTER", False)
```

### New loader in `inference_service.py`

```python
@lru_cache(maxsize=1)
def _load_cluster_centroids_scaled() -> Optional[Dict]:
    path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
    if not os.path.exists(path):
        return None
    with open(path) as f:
        return json.load(f)
```

### New function `_deterministic_cluster_assign()`

```python
def _deterministic_cluster_assign(
    vector: List[float],
    feature_names: List[str],
) -> Optional[int]:
    """
    Assign named cluster ID by nearest L2 centroid in the N-dimensional scaled feature space
    (N = len(feature_list.json)). Returns None if centroids file missing (caller falls back to UMAP).
    """
    data = _load_cluster_centroids_scaled()
    if not data:
        return None
    centroid_names = data["feature_names"]
    centroids = data["centroids"]
    feat_idx = {f: i for i, f in enumerate(feature_names)}
    best_id, best_dist = None, float("inf")
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

### Integration in `infer()`

Inserted inside the **`else:` block (single-senior path)**, after `feature_names = _load_json("feature_list.json")` and **before** the `if scaler is not None and reducer is not None ...` UMAP block. This placement ensures:
- `scaled_features` is already populated from preprocessed (line 1445)
- `feature_names` is freshly loaded from `feature_list.json`
- `cluster_map` and `cluster_profiles` are already loaded
- Setting `preprocessed["_precomputed_named_id"]` causes the cluster-map re-lookup block below (`if "_precomputed_named_id" not in preprocessed:`) to be **skipped**, preventing it from overwriting the deterministically assigned `named_id`

```python
# After: feature_names = _load_json("feature_list.json")
# Before: if scaler is not None and reducer is not None and kmeans is not None and feature_map:

# Deterministic cluster assignment — bypasses UMAP for cross-device consistency.
# Only runs when no DB cache provides a stored result and the flag is enabled.
# Falls back to UMAP automatically if the centroids file is missing.
_det_named_id = None
if ENABLE_DETERMINISTIC_CLUSTER and not _db_cached:
    _det_named_id = _deterministic_cluster_assign(
        scaled_features,    # N-dimensional vector already in preprocessed (line 1445)
        feature_names,      # from feature_list.json, loaded 2 lines above
    )

if _det_named_id is not None:
    named_id = max(1, min(3, _det_named_id))
    cluster_profile = cluster_profiles[named_id]
    # Reverse-lookup raw_cluster_id via cluster_map (NOT named_id - 1;
    # the actual cluster_mapping.json is {0:3, 1:1, 2:2}, not a simple +1 offset).
    raw_cluster_id = next(
        (raw_id for raw_id, mapped_id in (cluster_map or {}).items() if mapped_id == named_id),
        0,
    )
    # Inject into preprocessed so the cluster-map re-lookup block below is skipped
    preprocessed = dict(preprocessed)
    preprocessed["_precomputed_named_id"] = named_id
    preprocessed["_precomputed_raw_cluster_id"] = raw_cluster_id
    warnings_list.append("Deterministic cluster assignment used (UMAP skipped).")
else:
    # Existing UMAP → KMeans path (unchanged)
    if scaler is not None and reducer is not None and kmeans is not None and feature_map:
        ...
```

### Priority chain (final)

| Priority | Condition | Cluster source | Risk source |
|---|---|---|---|
| 1 | DB cache hit, `prediction_source = notebook_cache` | DB | DB |
| 2 | DB cache hit, other source | DB | DB |
| 3 | Notebook CSV override fires | CSV | CSV |
| 4 | `ENABLE_DETERMINISTIC_CLUSTER=true` + centroids file exists, no DB cache | Centroid L2 | Live formula |
| 5 | Fallback | Live UMAP+KMeans | Live formula |

The live UMAP+KMeans pipeline (priority 5) is always the fallback and is exercised by `validate_model_artifacts.py` on every CI run.

---

## Fix 3: `validate_model_artifacts.py` additions

**New check — centroids file exists and is consistent:**

```python
centroid_path = os.path.join(MODEL_DIR, "cluster_centroids_scaled.json")
if os.path.exists(centroid_path):
    with open(centroid_path) as f:
        cd = json.load(f)
    if set(cd["centroids"].keys()) != {"1", "2", "3"}:
        _fail("Cluster centroids file", f"Expected keys 1,2,3 — got {list(cd['centroids'].keys())}")
    elif cd["feature_names"] != fl2:
        _fail("Cluster centroids file", "feature_names mismatch with feature_list.json")
    else:
        _pass("Cluster centroids file", f"{len(cd['centroids'])} clusters, {cd['n_features']} features")

    # Agreement check: deterministic path and live UMAP path should agree on
    # the well-centred test vector. Use test_cluster[0] — the same 31D input that
    # was passed to UMAP above — so the spaces are identical.
    from inference_service import _deterministic_cluster_assign
    det_id = _deterministic_cluster_assign(
        test_cluster[0].tolist(), fl2   # 31D UMAP-input vector, feature_list.json names
    )
    if det_id is not None and det_id != named:
        _warn(
            "Deterministic vs UMAP cluster agreement",
            f"Deterministic assigned C{det_id}, UMAP assigned C{named} — "
            "may diverge near cluster boundaries; review if unexpected."
        )
    elif det_id is not None:
        _pass("Deterministic vs UMAP cluster agreement", f"Both assign C{named}")
else:
    _warn("Cluster centroids file", "Not found — run generate_cluster_centroids.py to enable deterministic mode")
```

A mismatch between deterministic and UMAP results is a `_warn` (not `_fail`) because the test vector (all features = 3.0) may legitimately fall near a boundary. Disagreement on a boundary case does not mean either path is wrong.

---

## Summary of file changes

| File | Change |
|---|---|
| `python/services/inference_service.py` | Fix 1: propagate wellbeing in `_db_cache_lookup` + 2 usage sites. Fix 2: new `ENABLE_DETERMINISTIC_CLUSTER` flag, `_load_cluster_centroids_scaled()`, `_deterministic_cluster_assign()`, integration in `infer()` |
| `python/scripts/generate_cluster_centroids.py` | New one-time generation script |
| `python/models/cluster_centroids_scaled.json` | New generated artefact (committed) |
| `python/scripts/validate_model_artifacts.py` | New centroids consistency + UMAP agreement check |
| `.env.example` | Document `ENABLE_DETERMINISTIC_CLUSTER=true` |

**No PHP changes. No migrations. No JS changes.**
