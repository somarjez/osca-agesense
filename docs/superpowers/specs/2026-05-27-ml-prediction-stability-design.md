# ML Prediction Stability Design

## Problem

The live GBR/RFR model ensemble systematically under-predicts risk compared to the
notebook-validated baseline (`senior_predictions.csv`).  After switching from
`ENABLE_NOTEBOOK_OVERRIDES=true` to `false`, 42 seniors moved from MODERATE/HIGH to
LOW — a clinically significant drift that affects which seniors receive priority
interventions.

Validated notebook distribution (283 seniors):
- HIGH + CRITICAL: 54   (18.73% + 0.35%)
- MODERATE:        191  (67.49%)
- LOW:             38   (13.43%)

Live model distribution (post `ml:batch-analyze --force`):
- HIGH:     34
- MODERATE: 169
- LOW:      80   ← 42 incorrectly demoted

---

## Goals

1. **Immediate safety**: Restore validated predictions on all devices without manual `.env`
   changes — no senior loses their risk classification.
2. **Root-cause fix**: Identify why the live GBR/RFR models under-predict and fix the
   feature pipeline so the live model can eventually replace the notebook cache.
3. **No regressions**: Cluster assignment, recommendation logic, and the recommendation
   count (4,666 on Device A) must not change.

---

## Design

### Part 1 — Fail-safe default (immediate)

**File:** `python/services/inference_service.py` line ~131

Change the default of `ENABLE_NOTEBOOK_OVERRIDES` from `False` to `True`:

```python
# Before
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", False)

# After
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", True)
```

This means every device uses the validated CSV **unless** the operator explicitly sets
`ENABLE_NOTEBOOK_OVERRIDES=false` in `.env`.  No `.env` changes required on any machine.

Add a startup `logger.warning()` when `ENABLE_NOTEBOOK_OVERRIDES` is `False` so it is
never silently active in production:

```python
if not ENABLE_NOTEBOOK_OVERRIDES:
    logger.warning(
        "ENABLE_NOTEBOOK_OVERRIDES=false — live GBR/RFR model is active. "
        "Results may deviate from the validated notebook baseline."
    )
```

After this change, restart the Flask inference service and re-run
`php artisan ml:batch-analyze --force` to regenerate all results from the notebook cache.

---

### Part 2 — Diagnostic command (root-cause investigation)

**New artisan command:** `php artisan ml:diagnose-senior {senior_id}`

This command runs one senior through the full live inference pipeline
(`ENABLE_NOTEBOOK_OVERRIDES=false` forced locally) and prints a comparison table:

| Step | Live model value | Notebook CSV value | Δ |
|------|-----------------|--------------------|---|
| feature: `sec6_phy_score`  | 0.00 | 0.52 | −0.52 |
| feature: `psych_happiness` | 0.00 | 0.75 | −0.75 |
| GBR IC pred                | 0.18 | n/a  |       |
| RFR IC pred                | 0.21 | n/a  |       |
| ic_risk_raw                | 0.29 | 0.51 | −0.22 |
| composite_risk             | 0.28 | 0.53 | −0.25 |
| overall_level              | LOW  | HIGH |       |

The primary hypothesis is **missing features**: `_vector_from_feature_map` silently
substitutes `0.0` for any feature absent from `feature_map`.  A feature like
`sec6_phy_score` defaulting to `0.0` (perfect physical health) instead of its true
value systematically lowers predicted risk.

The diagnostic output will reveal:
- Which features are `0.0` in the live pipeline but non-zero in the notebook CSV
- Whether the gap is in a specific domain (IC / ENV / FUNC) or spread across all three
- Whether GBR and RFR predictions are reasonable once correct features are supplied

---

### Part 3 — Fix the feature pipeline

Once the diagnostic identifies missing/mis-named features, the fix is in the
preprocessing layer that builds `feature_map`.

**Likely locations:**
- `python/services/inference_service.py` — `_preprocess_senior()` or equivalent function
  that assembles `feature_map` from survey data
- The 51 keys in `ml_risk_features.json` must all be present in `feature_map` after
  preprocessing

The fix: ensure every feature in `ml_risk_features.json` is computed and populated in
`feature_map` before the GBR/RFR step.  Any feature that cannot be computed from the
available survey data should use its correct fallback (domain risk score), not `0.0`.

After the fix:
1. Run the diagnostic command on several HIGH-risk seniors from the notebook CSV and
   confirm live model composite scores are within ±0.10 of notebook values.
2. Run `ml:batch-analyze --force` and compare the distribution to the notebook baseline.
3. If distribution is acceptably close (e.g., HIGH within ±5 of 54), export the live
   model predictions as the new `senior_predictions.csv` baseline.

---

## Out of Scope

- Retraining the GBR/RFR models
- Changing risk thresholds (0.50 HIGH, 0.30 MODERATE)
- Changing the composite formula weights
- Modifying recommendation logic

---

## Files Touched

| File | Change |
|------|--------|
| `python/services/inference_service.py` | Change `ENABLE_NOTEBOOK_OVERRIDES` default; add startup warning |
| `app/Console/Commands/MlDiagnoseSenior.php` | New artisan command |
| `app/Providers/AppServiceProvider.php` or `routes/console.php` | Register command (if needed by Laravel version) |

---

## Validation

1. After Part 1: `ml:batch-analyze --force` → distribution matches notebook baseline
   (HIGH ~54, MOD ~191, LOW ~38)
2. After Part 3: `ml:diagnose-senior {id}` → live composite within ±0.10 of CSV value
   for 5 sampled HIGH-risk seniors
3. No change to pending recommendation count (4,666)
