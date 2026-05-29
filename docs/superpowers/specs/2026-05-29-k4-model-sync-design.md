# K=4 Model Sync — System Alignment Design

> **For agentic workers:** This spec drives Sub-project A of the OSCA K=4 upgrade.
> The terminal state is a live inference service running K=4 models with ≥90% cluster
> match and ≥95% risk-level match against the notebook's 283-senior ground truth.

**Date:** 2026-05-29
**Status:** Approved for implementation
**Scope:** Sub-project A of 4 (Model Sync only — UI/XAI/Comparison in later sub-projects)

---

## Goal

The notebook was re-executed today (2026-05-29 02:08) with K=4 KMeans clustering on
`osca_normalized.csv` (283 seniors from the live DB). The resulting model artifacts in
`osca_output/model/` are the authoritative source of truth. The live inference service
currently loads from `python/models/`, which still contains the old K=3 models.

**This sub-project syncs the live system to K=4 and verifies it is consistent with the
notebook ground truth.**

---

## What Changed in the Notebook (K=3 → K=4)

| Artifact | Old (K=3) | New (K=4) |
|---|---|---|
| `cluster_mapping.json` | `{"0":3,"1":1,"2":2}` | `{"0":1,"1":2,"2":3,"3":4}` |
| `cluster_metadata.json` | 3 profiles | 4 profiles |
| `kmeans.pkl` | `KMeans(n_clusters=3)` | `KMeans(n_clusters=4)` |
| Cluster 1 | High Functioning (75) | High Functioning / Well-Supported (63) |
| Cluster 2 | Moderate / Mixed Needs (132) | Stable Ageing / Moderate Support (79) |
| Cluster 3 | Low Functioning (76) | Environmentally & Financially Vulnerable (72) |
| Cluster 4 | — | Low Functioning / Multi-Domain Priority (69) |
| GBR/RFR models | Trained on previous dataset | Retrained on osca_normalized.csv (283 seniors) |
| Model version | 1.1.1 | 2.0.0 |

---

## Architecture

### File Categories

**1. Copy verbatim** `osca_output/model/` → `python/models/` (overwrite):
```
kmeans.pkl              kmeans_model.pkl
scaler.pkl
umap_reducer.pkl        umap_2d.pkl         umap_nd.pkl
gbr_ic_risk.pkl         gbr_env_risk.pkl    gbr_func_risk.pkl
rfr_ic_risk.pkl         rfr_env_risk.pkl    rfr_func_risk.pkl
edu_encoder.pkl         income_encoder.pkl  hdbscan.pkl
cluster_mapping.json    cluster_metadata.json
feature_list.json       final_feature_list.json
ml_risk_features.json   best_hyperparameters.json
asset_weights.json      vif_retained_features.json
```

**2. Regenerate** (derived artifacts not produced by the notebook — built by the sync script):
- `cluster_centroids_scaled.json` — extract 4 centroids × 31 dims from new `kmeans.pkl`;
  used by the deterministic nearest-centroid cluster assignment
- `model_manifest.json` — SHA256 checksums of all `.pkl` files; version set to `2.0.0`
- `regression_baseline.json` — rebuilt from `osca_output/predictions/senior_predictions.csv`
  (283-row notebook ground truth); used by the validate script

**3. Backup before any writes:**
```
python/models/ → python/models_backup_{YYYYMMDD_HHMM}/
```
Rollback = rename backup directory back to `python/models/`.

---

## Scripts

### `python/scripts/sync_models_k4.py`

Single entry-point for the full sync. Performs these steps in order, aborting on any error:

**Step 1 — Pre-flight check**
- Verify `osca_output/model/` exists
- Verify all 23 expected source files are present (list above)
- Verify `osca_output/predictions/senior_predictions.csv` exists and has ≥280 rows
- Print a summary of source file sizes

**Step 2 — Backup**
- Copy `python/models/` → `python/models_backup_{timestamp}/`
- Print backup path

**Step 3 — Copy model files**
- Copy all 22 files from osca_output/model/ → python/models/ (overwrite)
- Print each file name + size

**Step 4 — Generate `cluster_centroids_scaled.json`**
```python
import pickle, json, numpy as np
km = pickle.load(open("python/models/kmeans.pkl", "rb"))
centroids = km.cluster_centers_.tolist()
assert len(centroids) == 4, f"Expected 4 centroids, got {len(centroids)}"
assert len(centroids[0]) == 31, f"Expected 31 dims, got {len(centroids[0])}"
json.dump(centroids, open("python/models/cluster_centroids_scaled.json", "w"), indent=2)
print(f"cluster_centroids_scaled.json: {len(centroids)} centroids × {len(centroids[0])} dims")
```

**Step 5 — Generate `model_manifest.json`**
- Compute SHA256 of every `.pkl` file in `python/models/`
- Write manifest with `model_version: "2.0.0"`, `generated_at: <ISO timestamp>`, checksums dict

**Step 6 — Generate `regression_baseline.json`**
- Read `senior_predictions.csv`
- For each row extract: `id` (or `senior_id`), `cluster_named_id`, `overall_risk_level`,
  `ic_risk`, `env_risk`, `func_risk`, `composite_risk`
- Write as JSON array keyed by senior id
- Print row count (must be 283)

**Step 7 — Print completion summary**
```
K=4 Model Sync Complete
  Files copied:    23
  Centroids:       4 × 31
  Manifest SHA256: computed for 15 pkl files
  Baseline rows:   283
  Backup at:       python/models_backup_20260529_0930/

Next steps:
  1. Restart Flask services (preprocess :5001 and inference :5002)
  2. Run: python/venv/Scripts/python.exe python/scripts/validate_k4_sync.py
```

---

### `python/scripts/validate_k4_sync.py`

Runs after services are restarted. Compares live inference against notebook ground truth.

**Step 1 — Load ground truth**
- Read `python/models/regression_baseline.json` (283 entries)
- Build dict: `{senior_id: {cluster, risk_level, ic_risk, env_risk, func_risk, composite_risk}}`

**Step 2 — Load seniors from DB**
- Use same DB credentials as `export_normalized_db.py`
- Query all non-deleted seniors with their latest QoL survey
- Build a flat dict per senior in the format the preprocess service expects

**Step 3 — Two-step inference for each senior**
```
POST http://127.0.0.1:5001/preprocess  → preprocessed
POST http://127.0.0.1:5002/infer       → result
```
- Collect: `cluster.named_id`, `risk_levels.overall`, `risk_scores.ic`, `risk_scores.env`,
  `risk_scores.func`, `risk_scores.composite`

**Step 4 — Compare and report**

Metrics computed:
- **Cluster match**: `live.named_id == notebook.cluster_named_id`
- **Risk level match**: `live.overall == notebook.overall_risk_level`
- **Composite risk Δ**: `abs(live.composite - notebook.composite_risk)`

Output format:
```
K=4 Sync Validation — 283 seniors
──────────────────────────────────────────────────────
Cluster match:           274 / 283  (96.8%)  ✅  (target ≥90%)
Risk level match:        281 / 283  (99.3%)  ✅  (target ≥95%)
Composite risk Δ max:    0.0042              ✅  (target <0.01)
Composite risk Δ mean:   0.0011

Cluster mismatches (showing up to 20):
  Senior #47    notebook=2  live=3  composite_Δ=0.008  [borderline]
  Senior #112   notebook=1  live=2  composite_Δ=0.003  [borderline]
  ...

──────────────────────────────────────────────────────
RESULT: PASS

All targets met. System is consistent with notebook ground truth.
```

**Exit code 0** on PASS, **exit code 1** on FAIL. On FAIL, prints:
```
RESULT: FAIL
Cluster match below target (XX.X% < 90%).
To restore previous models:
  Remove-Item -Recurse -Force python/models/
  Rename-Item python/models_backup_YYYYMMDD_HHMM python/models
```

---

## Code Changes in `inference_service.py`

Three targeted patches — no other files need changes.

**Patch 1 — Named-ID clamp** (prevents cluster 4 from being silently dropped):
```python
# Before:
named_id = max(1, min(3, int(...)))
# After:
named_id = max(1, min(4, int(...)))
```

**Patch 2 — `CLUSTER_PROFILES` fallback constant** (used if cluster_metadata.json is missing):
```python
# Add 4th entry:
4: {
    "name": "Low Functioning / Multi-Domain Priority Seniors",
    "ic": "Low", "env": "Low", "func": "Low",
    "color": "#e74c3c",
    "interpretation": (
        "Lowest WHO Healthy Ageing alignment across all domains. "
        "Multi-domain vulnerability requiring immediate priority case management."
    ),
}
```

**Patch 3 — Model version constant**:
```python
# Before:
MODEL_VERSION = "1.1.1"
# After:
MODEL_VERSION = "2.0.0"
```

---

## Execution Sequence

```
1. python/venv/Scripts/python.exe python/scripts/sync_models_k4.py
   → copies files, generates centroids/manifest/baseline, prints summary

2. [Stop Flask services on :5001 and :5002]

3. [Start preprocess_service.py on :5001]
   [Start inference_service.py on :5002]

4. python/venv/Scripts/python.exe python/scripts/validate_k4_sync.py
   → batch inference on 283 seniors, prints PASS/FAIL report

5. [On PASS] Proceed to Sub-project B (UI/UX K=4 updates)
   [On FAIL] Restore backup, investigate mismatches
```

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| `osca_output/model/` missing | Sync script aborts at pre-flight, prints exact path that is missing |
| `senior_predictions.csv` has < 280 rows | Abort — notebook may not have run fully |
| Flask service not running at validation time | Validate script prints clear connection error per service |
| Senior in DB but not in notebook CSV | Skip silently, note count in summary |
| Centroid shape not `4 × 31` | Abort — wrong kmeans.pkl loaded |

---

## Success Criteria

- [ ] `python/models/` contains K=4 artifacts (cluster_mapping has 4 entries)
- [ ] `cluster_centroids_scaled.json` has shape `4 × 31`
- [ ] `model_manifest.json` version = `2.0.0`
- [ ] `regression_baseline.json` has 283 entries
- [ ] Inference service health check returns `model_version: "2.0.0"`
- [ ] Validation script exits 0 (PASS) with cluster match ≥90%, risk match ≥95%, Δ <0.01

---

## Out of Scope (Later Sub-projects)

- UI cluster cards (Sub-project B — show 4 clusters instead of 3)
- Cluster report page updates (Sub-project B)
- XAI / SHAP feature importance (Sub-project C)
- Live DB vs notebook comparison dashboard (Sub-project D)
