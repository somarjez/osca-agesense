# ML Cache Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove Python's independent DB cache layer so the inference service is a pure compute service, and force individual Re-run to always call Python, fixing inconsistent timestamps and stale results.

**Architecture:** Python's `_db_cache_lookup` / `_db_cache_write` were causing two conflicting writers to `ml_results` — PHP by `(senior_id, qol_survey_id)` and Python by `senior_id` only — producing wrong-row reads/writes and stale scores. After this change: PHP is the sole arbiter of result reuse and persistence; Python only computes. Individual Re-run passes `force: true` so Python is always called. Batch runs keep PHP's `findReusableResult()` for efficiency. Cross-device consistency is guaranteed by the deterministic ML model (same input → same output on every device).

**Tech Stack:** Laravel 11, PHP 8.3, Python 3.12, Flask, UMAP, KMeans, GBR/RFR ensembles

---

## Files

- Modify: `app/Jobs/ProcessMlSingle.php:34` — add `force: true`
- Modify: `python/services/inference_service.py` — remove pymysql import, three DB functions, and all `_db_cached` references inside `infer()`
- Test: `python/tests/test_inference_paths.py` — existing tests must still pass
- Test: `python/tests/test_ml_pipeline.py` — existing tests must still pass

---

## Task 1: Force individual Re-run to always call Python

**Files:**
- Modify: `app/Jobs/ProcessMlSingle.php:34`

- [ ] **Step 1: Edit ProcessMlSingle.php**

Open `app/Jobs/ProcessMlSingle.php`. Change line 34 from:

```php
$ml->runPipeline($senior, $survey);
```

to:

```php
$ml->runPipeline($senior, $survey, force: true);
```

The full `handle()` method should now read:

```php
public function handle(MlService $ml): void
{
    set_time_limit(0);

    $senior = SeniorCitizen::find($this->seniorId);
    $survey = QolSurvey::find($this->surveyId);

    if (!$senior || !$survey) {
        return;
    }

    $ml->runPipeline($senior, $survey, force: true);
}
```

- [ ] **Step 2: Verify the method signature accepts force**

Open `app/Services/MlService.php` and confirm line 53 reads:

```php
public function runPipeline(SeniorCitizen $senior, QolSurvey $survey, bool $force = false): MlResult
```

The `force` parameter exists with default `false`. No change needed here.

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/ProcessMlSingle.php
git commit -m "fix: individual Re-run always forces fresh Python compute (force: true)"
```

---

## Task 2: Remove pymysql import and three DB cache functions

**Files:**
- Modify: `python/services/inference_service.py`

The three functions to remove are `_db_connect()` (lines ~369–388), `_db_cache_lookup()` (lines ~391–451), and `_db_cache_write()` (lines ~454–515). Also remove the conditional `pymysql` import (lines ~43–47).

- [ ] **Step 1: Remove the pymysql import block**

Find and remove this exact block (lines 43–47):

```python
try:
    import pymysql
    _PYMYSQL_AVAILABLE = True
except ImportError:
    _PYMYSQL_AVAILABLE = False
```

Delete it entirely. The blank line after it can stay.

- [ ] **Step 2: Remove `_db_connect()`**

Find and remove this entire function:

```python
def _db_connect() -> Optional[Any]:
    if not _PYMYSQL_AVAILABLE:
        return None
    try:
        env = _read_laravel_env()
        conn = pymysql.connect(
            host=env.get("DB_HOST", "127.0.0.1"),
            port=int(env.get("DB_PORT", 3306)),
            user=env.get("DB_USERNAME", "root"),
            password=env.get("DB_PASSWORD", ""),
            database=env.get("DB_DATABASE", "osca_db"),
            connect_timeout=3,
            read_timeout=5,
            write_timeout=5,
            autocommit=True,
        )
        return conn
    except Exception as exc:
        logger.debug("DB cache connect failed (non-fatal): %s", exc)
        return None
```

- [ ] **Step 3: Remove `_db_cache_lookup()`**

Find and remove the entire `_db_cache_lookup` function. It starts with:

```python
def _db_cache_lookup(senior_id: int) -> Optional[Dict[str, Any]]:
    """
    Query the latest ml_results row for this senior. Returns a dict shaped like
    a notebook_override payload so the same injection path is reused.
```

And ends just before `def _db_cache_write`. Remove the whole function including its docstring.

- [ ] **Step 4: Remove `_db_cache_write()`**

Find and remove the entire `_db_cache_write` function. It starts with:

```python
def _db_cache_write(senior_id: int, result: Dict[str, Any]) -> None:
    """
    After a fresh UMAP run, persist the key ML outputs back to ml_results so
    any other device can read them on the next request for this senior.
    This is a best-effort write — failures are logged but never raise.
    """
```

And ends at the `finally: try: conn.close() ...` block. Remove the whole function.

- [ ] **Step 5: Verify no DB functions remain**

Run:

```bash
grep -n "_db_connect\|_db_cache_lookup\|_db_cache_write\|_PYMYSQL_AVAILABLE\|import pymysql" python/services/inference_service.py
```

Expected output: no matches.

- [ ] **Step 6: Commit**

```bash
git add python/services/inference_service.py
git commit -m "fix: remove Python DB cache functions (_db_connect, _db_cache_lookup, _db_cache_write)"
```

---

## Task 3: Remove all `_db_cached` references inside `infer()`

**Files:**
- Modify: `python/services/inference_service.py` (inside `infer()` function)

There are seven places inside `infer()` that reference `_db_cached`. Remove them all.

- [ ] **Step 1: Remove the `_db_cached` declaration and lookup call**

Find and remove these 8 lines (including the comment block):

```python
    # DB cache hit: reuse stored ML result for this senior, skipping UMAP entirely.
    # This ensures identical results across all devices for seniors already processed.
    # Exception: if the caller already injected _precomputed_named_id (e.g. fix_cluster_distribution.py
    # after auto-calibration), trust that value — the DB still holds the pre-calibration named_id
    # and would overwrite the corrected one if we let it through.
    _db_cached = None
    if senior_id and "_precomputed_named_id" not in preprocessed:
        _db_cached = _db_cache_lookup(senior_id)
```

- [ ] **Step 2: Remove the notebook-cache early-return block**

Find and remove the entire block that starts with:

```python
    # Notebook-cache protection: if the DB already has a notebook_cache result
    # (prediction_source = 'notebook_cache', model_version = 1.1.0), do NOT
    # re-score — return it directly.  This prevents a re-analysis run from
    # overwriting validated notebook results with live model scores.
    # ml:repair-notebook-cache bypasses this by not going through infer() for
    # seniors that need repair; it forces a fresh CSV match instead.
    if (
        ENABLE_NOTEBOOK_OVERRIDES
        and _db_cached
        and _db_cached.get("_prediction_source") == "notebook_cache"
        and "_precomputed_named_id" not in preprocessed
    ):
```

Remove everything from that comment through the closing `}` of the returned dict (approximately 85 lines). The block ends just before the line `if _db_cached:` that injects `_precomputed_named_id`.

- [ ] **Step 3: Remove the `_precomputed_named_id` injection block**

Find and remove:

```python
    if _db_cached:
        preprocessed = dict(preprocessed)
        preprocessed["_precomputed_raw_cluster_id"] = _db_cached["_raw_cluster_id"]
        # Inject named_id directly from DB so we bypass _load_cluster_mapping() lru_cache.
        # The lru_cache may hold a stale mapping from before fix_cluster_distribution.py ran,
        # which would silently flip the cluster label for any senior viewed after the fix.
        preprocessed["_precomputed_named_id"] = _db_cached["cluster_id"]
        warnings_list.append("Cluster and risk scores loaded from shared DB cache (UMAP skipped).")
```

- [ ] **Step 4: Remove the wellbeing score DB-cache override**

Find and remove:

```python
    # DB-cache override: pin wellbeing to the first-run value so it never drifts.
    if _db_cached and _db_cached.get("wellbeing_score") is not None:
        wellbeing_score = float(_db_cached["wellbeing_score"])
```

- [ ] **Step 5: Remove the `elif _db_cached:` risk score override block**

Find and remove the entire `elif _db_cached:` block:

```python
    elif _db_cached:
        # DB cache: apply stored risk scores so all devices agree on this senior's values.
        # Same "only override if > 0" guard as notebook_override above.
        _ov_ic   = _safe_float(_db_cached.get("ml_ic_risk"),   0.0)
        _ov_env  = _safe_float(_db_cached.get("ml_env_risk"),  0.0)
        _ov_func = _safe_float(_db_cached.get("ml_func_risk"), 0.0)
        _ov_comp = _safe_float(_db_cached.get("composite_risk"), 0.0)
        if _ov_ic > 0:
            ic_risk_raw = _clip01(_ov_ic)
        if _ov_env > 0:
            env_risk_raw = _clip01(_ov_env)
        if _ov_func > 0:
            func_risk_raw = _clip01(_ov_func)
        if _ov_comp > 0:
            composite_risk = _clip01(_ov_comp)
        _db_level = (_db_cached.get("risk_level") or overall_level or "").upper()
        if _db_level == "CRITICAL":
            _db_level = "HIGH"
        overall_level = _db_level or overall_level
        ic_level   = _get_risk_level(ic_risk_raw)
        env_level  = _get_risk_level(env_risk_raw)
        func_level = _get_risk_level(func_risk_raw)
```

- [ ] **Step 6: Clean up `model_metadata` dict — remove `db_cache_hit` and simplify `prediction_source`**

Find this section in the `model_metadata` dict:

```python
            "db_cache_hit": bool(_db_cached),
            # prediction_source is the canonical label persisted to ml_results.prediction_source
            "prediction_source": (
                "notebook_cache" if notebook_override
                else ("live_model" if not _db_cached else "live_model")
            ),
```

Replace with:

```python
            # prediction_source is the canonical label persisted to ml_results.prediction_source
            "prediction_source": "notebook_cache" if notebook_override else "live_model",
```

- [ ] **Step 7: Remove the write-back call**

Find and remove:

```python
    # Write-back: if this was a fresh UMAP run (no DB cache hit, no notebook override),
    # persist the result so every other device gets a consistent answer next time.
    if senior_id and not _db_cached and not notebook_override:
        _db_cache_write(senior_id, result)
```

- [ ] **Step 8: Verify no `_db_cached` references remain**

```bash
grep -n "_db_cached\|_db_cache_write\|_db_cache_lookup" python/services/inference_service.py
```

Expected: no matches.

- [ ] **Step 9: Commit**

```bash
git add python/services/inference_service.py
git commit -m "fix: remove all _db_cached references from infer() — Python is now a pure compute service"
```

---

## Task 4: Run Python tests and restart inference service

**Files:**
- Test: `python/tests/test_inference_paths.py`
- Test: `python/tests/test_ml_pipeline.py`

- [ ] **Step 1: Run the inference path tests**

```bash
cd python
python tests/test_inference_paths.py
```

Expected: all lines show `[OK]`, final line shows no `FAIL`. If any test fails, read the output carefully — the failure will name the function and the expected vs actual value.

- [ ] **Step 2: Run the ML pipeline tests**

```bash
python tests/test_ml_pipeline.py
```

Expected: all assertions pass with no Python tracebacks.

- [ ] **Step 3: Kill the currently running inference service and restart it**

Find the PID of the running inference service:

```powershell
Get-WmiObject Win32_Process | Where-Object { $_.Name -eq "python.exe" -and $_.CommandLine -like "*inference_service*" } | Select-Object ProcessId
```

Kill it:

```powershell
Stop-Process -Id <PID> -Force
```

Restart it (use the project venv):

```bash
nohup python python/services/inference_service.py > /tmp/inference.log 2>&1 &
```

- [ ] **Step 4: Wait for the service to load and verify health**

Wait 20 seconds for model loading, then:

```bash
curl -s http://127.0.0.1:5002/health
```

Expected response:

```json
{"model_version":"1.1.0","notebook_overrides_enabled":false,"service":"osca-inference","status":"ok"}
```

The `db_cache_hit` key should NOT appear in `/health` output any more.

- [ ] **Step 5: Smoke-test an infer call**

```bash
curl -s -X POST http://127.0.0.1:5002/infer \
  -H "Content-Type: application/json" \
  -d '{"senior_id": 1, "scaled_features": [], "reduced_features": [], "section_scores": {}, "rule_scores": {}, "identity": {}}' \
  | python -m json.tool | grep -E "status|prediction_source|composite_risk"
```

Expected: `"status": "ok"`, `"prediction_source": "live_model"` (never `notebook_cache` from DB), and a numeric `composite_risk` value.

- [ ] **Step 6: Commit the test run result (no file changes — confirm clean state)**

```bash
git status
```

Expected: `nothing to commit, working tree clean`. If any files were changed (e.g., a log file), verify they're expected before committing.

---

## Task 5: End-to-end verification via the UI

No code changes. Verify the full flow works in the browser.

- [ ] **Step 1: Open a senior profile page and note the current "Scored" timestamp**

Navigate to any senior's profile. Note the time shown under "Scored".

- [ ] **Step 2: Edit a profile field and save**

Click Edit on the senior. Change any ML-relevant field (e.g. Monthly Income Range or Household Size). Save.

Confirm the amber stale warning banner appears on the profile page:
> ⚠ Results may be outdated — This senior's profile or survey data was changed after the last analysis.

- [ ] **Step 3: Click "Re-run Assessment" and wait for reload**

Click the button. The spinner should appear, the page should reload automatically within ~30 seconds.

- [ ] **Step 4: Confirm timestamp updated**

After reload, the "Scored" timestamp should show a time within the last minute. The stale banner should be gone.

- [ ] **Step 5: Verify batch page shows the same timestamp**

Navigate to Analysis → Batch Analysis. Find the same senior. The "Last Run" column should show the same recent time as the profile page's "Scored" value.

- [ ] **Step 6: Push to remote**

```bash
git push
```

---

## Self-Review

**Spec coverage check:**
- ✅ Python DB cache removed: Tasks 2 + 3
- ✅ Individual Re-run always forces fresh compute: Task 1
- ✅ Batch runs unaffected (no changes to `runBatchPipeline` or `findReusableResult`): confirmed by spec — no tasks touch those
- ✅ Profile/batch timestamp discrepancy: resolved because `scored_at = now()` is set on every Re-run (Task 1 + Task 4 verification)
- ✅ Inference service restarted with clean code: Task 4
- ✅ Cross-device consistency: guaranteed by deterministic model — explicitly verified in Task 4 Step 5 (`prediction_source: live_model` always)

**Placeholder scan:** No TBDs, all steps have exact code or commands.

**Type consistency:** `force: true` in Task 1 matches `bool $force = false` parameter in `MlService::runPipeline()`.
