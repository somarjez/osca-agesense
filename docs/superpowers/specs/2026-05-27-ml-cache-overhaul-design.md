# ML Cache Overhaul — Design Spec

**Date:** 2026-05-27
**Approach:** A — Python as Pure Compute Service
**Status:** Approved

---

## 1. Problem Statement

The ML result pipeline has three overlapping, conflicting caches that all read/write `ml_results`:

| Layer | File | Query criteria | Problem |
|---|---|---|---|
| PHP `findReusableResult()` | MlService.php | `senior_id + qol_survey_id` | ✅ Correct |
| PHP `persistResults()` | MlService.php | `senior_id + qol_survey_id` | ✅ Correct |
| Python `_db_cache_lookup()` | inference_service.py | `senior_id` only | ❌ No `qol_survey_id` — reads wrong row when senior has multiple surveys |
| Python `_db_cache_write()` | inference_service.py | `senior_id` only | ❌ No `qol_survey_id` — writes wrong row, second writer conflict |

### Observed symptoms

1. **Profile page shows different timestamp than batch analysis** — Profile shows `scored_at` (set only on fresh compute); batch shows `processed_at` (bumped on every run). When Python's `_db_cache_write` updates the wrong row, `processed_at` changes on a row that isn't the one the profile page reads.

2. **Re-running analysis does not change results** — Python's `_db_cache_lookup` reads the latest `ml_results` row by `senior_id` only. It then applies those stored scores over the freshly computed values (`elif _db_cached:` block in `infer()`), effectively discarding the fresh computation and returning the old scores.

3. **`AND is_stale = 0` fix broke analysis entirely** — A prior fix added `AND is_stale = 0` to `_db_cache_lookup`. When `is_stale = true`, `_db_cache_lookup` returns `None`, causing Python to fall through to full UMAP computation. This is correct behaviour but exposed that `_db_cache_write` then writes to the wrong row, creating further inconsistency.

---

## 2. Cross-Device Consistency

Each device has its own local MySQL instance (`DB_HOST=127.0.0.1`). Cross-device consistency is guaranteed by the **deterministic ML model**, not by Python's DB cache:

- `ENABLE_DETERMINISTIC_CLUSTER=true` — cluster assignment uses nearest-centroid in scaled space (no UMAP random initialisation)
- Fixed `.pkl` model files with `MODEL_VERSION=1.1.0` — same model on every device
- Same input data → identical output scores on every device

The Python DB cache was attempting to solve a consistency problem that the deterministic model already solves. It is safe to remove.

---

## 3. Solution: Approach A

**Python becomes a pure compute service.** No DB reads, no DB writes. Data in → ML pipeline runs → scores out.

**PHP remains the single source of truth** for all ML result persistence and cache decisions via the existing `ml_results` table, `is_stale` flag, and `MlResultStalenessObserver`.

**Individual Re-run always forces fresh computation.** `ProcessMlSingle` passes `force: true` to `runPipeline()`, skipping `findReusableResult()` and writing fresh `scored_at` on every explicit Re-run click.

**Batch runs retain efficiency.** `runBatchPipeline()` keeps its `findReusableResult()` loop — unchanged seniors are skipped, stale seniors are recomputed.

---

## 4. Changes

### 4.1 `python/services/inference_service.py`

**Remove entirely:**
- `_db_connect()` function
- `_db_cache_lookup()` function
- `_db_cache_write()` function
- `_PYMYSQL_AVAILABLE` flag and `pymysql` conditional import
- DB env var reads at startup (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_CHARSET`)

**Remove from `infer()` function:**
- `_db_cached = None` declaration
- `if senior_id and "_precomputed_named_id" not in preprocessed: _db_cached = _db_cache_lookup(senior_id)` block
- The `notebook_cache` early-return block gated on `ENABLE_NOTEBOOK_OVERRIDES and _db_cached` (lines ~1737–1820)
- The `elif _db_cached:` score-override block (~lines 2090–2111)
- The write-back call: `if senior_id and not _db_cached and not notebook_override: _db_cache_write(senior_id, result)`
- All references to `_db_cached` variable

**The `notebook_override` path (CSV-based, `ENABLE_NOTEBOOK_OVERRIDES=true`) is untouched** — it does not use the DB cache.

### 4.2 `app/Jobs/ProcessMlSingle.php`

Change one line:

```php
// Before
$ml->runPipeline($senior, $survey);

// After
$ml->runPipeline($senior, $survey, force: true);
```

`force: true` causes `runPipeline()` to:
1. Skip `findReusableResult()` — always calls Python
2. Pass `force: true` to `persistResults()` — bypasses notebook_cache guard, writes fresh scores unconditionally
3. Sets `scored_at = now()` — profile page timestamp updates on every Re-run

### 4.3 No other PHP changes

- `MlService::runPipeline()` — unchanged
- `MlService::runBatchPipeline()` — unchanged (uses `findReusableResult()` for efficiency)
- `MlService::persistResults()` — unchanged
- `MlService::findReusableResult()` — unchanged (batch still uses it)
- `MlResultStalenessObserver` — unchanged (still marks results stale for batch efficiency)
- All Blade views — unchanged (timestamp discrepancy resolves automatically once `scored_at` is always fresh)

---

## 5. Result After Fix

| Scenario | Before | After |
|---|---|---|
| Re-run after editing profile/QoL | Old scores returned (Python read stale DB row) | Fresh Python scores always |
| Re-run with no data change | Old scores returned (Python read DB cache) | Fresh Python scores always |
| Batch run on unchanged senior | Correctly reused cached result | Still reuses cached result (efficient) |
| Batch run on stale senior | Old scores (Python read stale row) | Fresh Python scores |
| Profile page timestamp | Could show hours-old `scored_at` after Re-run | Always shows current `scored_at` after Re-run |
| Batch vs profile discrepancy | Different rows written by PHP and Python | Single writer (PHP only), always consistent |
| Cross-device consistency | Attempted via Python DB write (wrong row) | Guaranteed by deterministic model + shared PHP result |

---

## 6. Success Criteria

- [ ] Clicking "Re-run Assessment" always produces a fresh Python score
- [ ] Profile page `scored_at` updates after every Re-run
- [ ] Batch analysis and profile page show consistent timestamps for same senior
- [ ] Editing a senior profile/QoL and re-running produces updated scores
- [ ] Batch run skips unchanged seniors (no regression in batch efficiency)
- [ ] No `pymysql` / DB connection code remains in `inference_service.py`
- [ ] Inference service runs cleanly after restart
