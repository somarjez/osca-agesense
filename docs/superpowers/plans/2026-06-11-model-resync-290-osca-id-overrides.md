# 2026-06-11 — Model v2.0.0 re-sync to 290 seniors, osca_id fixes, override-mode decision

Operations record for the model + data re-sync performed on 2026-06-11. Supersedes the
"283 seniors" figures in older docs for current-state purposes (historical validation
records dated 2026-05-29 are left intact).

## 1. Summary

The notebook (`osca5.ipynb`) was retrained on **290 seniors** (the previous 283 + 7 new
records). The new v2.0.0 artifacts were deployed to the live system, the 7 new seniors
were added, two code bugs surfaced during the work were fixed, and the live prediction
mode was deliberately set to the live model (`ENABLE_NOTEBOOK_OVERRIDES=false`).

## 2. Data changes

- **Population: 283 → 290 seniors.** `osca_normalized.csv` (notebook input, 64 cols) =
  `osca.csv` (seeder input, 71 cols) **+ 7 new seniors**: Rina Untivero, Paulino Almontero,
  Merle Caballes, Josie Capistrano, Josie Pingol, June Davac, Necita Sacluti.
- The 7 were added to the live DB via the **Bulk Upload** UI (osca_ids `BR2-2026-0001`,
  `SAM-2026-0002`, `MAG-2026-0001`, `LAY-2026-0001`, `ANI-2026-0001`/`0002`, `BUB-2026-0038`).
- One data fix in `osca_normalized.csv`: June Davac's education `"College Level, Vocational"`
  (a double-checked single-select) → `"Vocational"`, which had been failing the notebook's
  `education_enc` assert.
- Note the two CSVs can drift: the seeder reads `osca.csv`; to persist the 7 across a future
  re-seed, append their completed rows there too.

## 3. Model deployment (non-destructive)

Deployed v2.0.0 artifacts from `osca_output/` **without** a destructive re-seed:

1. Copied the 23 model artifacts `osca_output/model/` → `python/models/` and mirrored to
   `storage/app/ml_models/` (timestamped `*_backup_*` of both dirs kept for rollback).
2. Regenerated `model_manifest.json` (SHA-256 of pkls) and `regression_baseline_k4.json`
   (290 rows) in both dirs.
3. Copied `senior_predictions.csv` + `senior_recommendations_flat.csv` → both `predictions/`
   dirs; `osca_output/reports/*` → `storage/app/ml_validation/reports/`.
4. Rebuilt `cluster_centroids_scaled.json` via `generate_cluster_centroids.py` from the DB's
   notebook_cache labels (4 clusters: 64/78/76/72).

Rollback = restore `python/models` / `storage/app/ml_models` from the `*_backup_*` dirs.

## 4. Code fixes (uncommitted on branch `fix/osca-id-generation` at time of writing)

- **`app/Models/SeniorCitizen.php` — `generateOscaId()`**
  - Was `count()+1`, which excludes soft-deleted rows while the unique index still enforces
    them → duplicate-key on import after any deletion (hit `BAR-2026-0037`). Now
    `MAX(sequence)+1` over `withTrashed()`.
  - The two Poblacion barangays both reduced to the `BAR` prefix and shared one sequence;
    they now get distinct prefixes **`BR1`** (Barangay I) / **`BR2`** (Barangay II), new
    records only. Existing `BAR-*` IDs are left unchanged.
- **`app/Console/Commands/RepairNotebookCache.php`** — the final verify-state query used
  `MAX(id)` across a join where both tables have `id` (SQLSTATE 1052 ambiguous column).
  Fixed to `MAX(ml_results.id)`.

## 5. Prediction mode decision — `ENABLE_NOTEBOOK_OVERRIDES=false` (live model, canonical)

The system runs the **live model** for every senior. With overrides `true`, the 290 seed
seniors are served from `notebook_cache` (exact notebook reproduction, 100% cluster match);
with `false`, all seniors are scored live. The chosen canonical mode for this deployment is
**`false`** — consistent with `model-validation-defensible-statements.md`, which defends the
live-model results. Trade-off captured in section 6.

> Operational note: changing this flag requires a Flask service restart (env is read once at
> startup). Verify via `GET http://127.0.0.1:5002/health` → `notebook_overrides_enabled`.
> When `false`, `ml:repair-notebook-cache` cannot produce `notebook_cache` rows and will
> report a misleading "CSV match failed (name/barangay/age mismatch)" — the cause is the flag.

## 6. Why live clustering differs ~9% from the notebook

Not randomness — a deterministic **structural approximation**:

- **Notebook (training):** KMeans in **UMAP-reduced** space → **non-linear** cluster boundaries.
- **Live (production, `ENABLE_DETERMINISTIC_CLUSTER=true`, default):** nearest-centroid (L2) in
  the **original 31D scaled** space using `cluster_centroids_scaled.json` → **linear / Voronoi**
  boundaries between the 4 centroids. (UMAP `transform()` is only a fallback when the centroid
  file is missing.)

Both agree for seniors comfortably inside a cluster. For the ~9% near a boundary, the linear
Voronoi split and the non-linear UMAP+KMeans split disagree → a neighbouring cluster. The live
result is **fully deterministic across devices** (pure distance math) — that determinism is
exactly why nearest-centroid is used instead of non-reproducible single-point UMAP. Rebuilding
the centroids from the 290 labels nudged agreement 90% → 91%.

**Risk is unaffected:** the GBR/RFR regressors are deterministic functions of the feature
vector; composite-risk delta between live and notebook was ~0.0002 mean / 0.0107 max.

## 7. Validation (2026-06-11, 290 seniors, vs `senior_predictions.csv`)

| Metric | Overrides `true` (notebook_cache) | Overrides `false` (live, canonical) |
|---|---|---|
| prediction_source | 290 `notebook_cache` | 290 `live_model` |
| Cluster agreement | 100% (290/290) | **91.0% (264/290)** |
| Risk-level agreement | 99.7% | 99.7% (289/290) |
| Composite-risk Δ | 0.0000 | max 0.0107 / mean 0.0002 |
| Cluster distribution | `{1:64, 2:78, 3:76, 4:72}` (= notebook) | `{1:61, 2:85, 3:77, 4:67}` |
| Risk distribution | `HIGH 55 / MOD 196 / LOW 38 / CRIT 1` | `HIGH 56 / MOD 196 / LOW 38` |

`validate_k4_sync.py` PASS (cluster 100% target ≥90%, risk 99.6% target ≥95%, composite Δ max
0.0000 target <0.01). The single risk difference (Norlito Basa) is the notebook's `CRITICAL`
mapped to the live 3-level `HIGH` — intended, not an error. Note `senior_predictions.csv` still
emits one `CRITICAL`; the live system is 3-level (LOW/MODERATE/HIGH).

## 8. Hand-off commands (reference)

```powershell
cd <inner repo>
# deploy already done via file copy; to re-align after a future retrain:
powershell -NoProfile -File .\python\start_services.ps1          # restart, load new models + env
php artisan ml:repair-notebook-cache --all                        # only works if ENABLE_NOTEBOOK_OVERRIDES=true
.\python\venv\Scripts\python.exe .\python\scripts\generate_cluster_centroids.py
.\python\venv\Scripts\python.exe .\python\scripts\validate_k4_sync.py
# live-model mode (canonical here):
#   set ENABLE_NOTEBOOK_OVERRIDES=false, restart, then: php artisan ml:batch-analyze --force
```
