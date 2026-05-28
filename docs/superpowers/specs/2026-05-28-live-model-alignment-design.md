# Live Model Alignment Design

## Problem

The live GBR/RFR model pipeline produces different risk classifications from the
notebook-validated baseline (`senior_predictions.csv`). The system currently works
around this by keeping `ENABLE_NOTEBOOK_OVERRIDES=true`, which forces every inference
call to look up the senior in a static CSV cache. This approach:

- Breaks for any senior not in the CSV (new registrations score incorrectly)
- Is not portable — deploying to a new device requires shipping the CSV
- Produces inconsistent results between individual re-run (live model, may miss CSV
  lookup) and batch analysis (CSV cache hit)
- Is not a real ML deployment — it bypasses the trained models entirely

**Confirmed symptoms (live model with `ENABLE_NOTEBOOK_OVERRIDES=false`):**
- Risk scores drift — 42 seniors incorrectly classified LOW instead of MODERATE/HIGH
- Cluster assignments drift — seniors assigned to wrong WHO Healthy Ageing profiles
- Recommendation content and counts change — domain risk scores drive different rules,
  wrong urgency levels are set, and the total recommendation count diverges from the
  validated 4,666

All three symptoms share the same root cause: multiselect fields arrive as Python
lists but the preprocessing pipeline treats them as comma strings, causing every
asset/income/community score to silently evaluate to `0.0`. This corrupts the 51-feature
vector fed to GBR/RFR models *and* the scaled feature vector fed to UMAP/KMeans
clustering, producing cascading drift across risk, cluster, and recommendations.

**Goal:** Make the live preprocessing pipeline reproduce the notebook's feature
values exactly, so the GBR/RFR models produce scores consistent with the notebook
baseline for any senior — existing or new — on any device. `ENABLE_NOTEBOOK_OVERRIDES`
is permanently disabled and the system is deployment-ready.

---

## Root Cause Analysis

Four categories of discrepancy between `preprocess_service.py` and the notebook:

### 1. `env_score` / `func_score` use narrow feature lists

The 45% rule-based fallback component of the domain risk blend is:

```
ic_risk = rule_ic_dim × 0.45 + GBR_ic × 0.35 + RFR_ic × 0.20
  where rule_ic_dim = 1 − (ic_score − 1) / 4
```

`ic_score`, `env_score`, `func_score` are used to compute this fallback. The notebook
defines them as:

```python
# ENVIRONMENT_RAW — 22 items including non-Likert features
env_score = mean(ENVIRONMENT_RAW)   # includes income_enc, sec5_eco_stability, etc.

# FUNCTIONAL_RAW — 12 items including non-Likert features  
func_score = mean(FUNCTIONAL_RAW)   # includes education_enc, checkup_enc, etc.
```

The current `who_domain_scores` in `preprocess_service.py` uses only 13 and 7 Likert
items respectively, producing systematically lower domain averages → higher fallback
risk → inflated or deflated blended scores.

### 2. Multi-select fields arrive as Python lists, not comma strings

`score_multiselect(text, ...)` calls `str(text).split(",")`. When `text` is a Python
list `['own pension', 'house & lot']`, `str()` produces `"['own pension', 'house &
lot']"` — the comma inside the brackets causes the split to return garbage tokens that
match nothing. Every `real_assets`, `movable_assets`, `income_source`,
`community_service`, and `specialization` score silently evaluates to `0.0`.

### 3. Derived flags use string-search on list input

`has_pension`, `is_association_member`, and `lives_alone` use `.str.contains()` /
`.eq("alone")` style logic on the raw `income_source` / `community_service` /
`living_with` fields. If these arrive as lists, the string-search finds nothing.

### 4. `child_support_enc` / `spouse_working_enc` edge values

The notebook maps `"Occasional" → 0.5` and `"Deceased" → 0`. Missing or case-variant
values fall back to `0`, which is correct — but needs explicit verification.

---

## Design

### Component: Audit Script (`python/scripts/audit_feature_alignment.py`)

A standalone Python script that quantifies the gap between the notebook and live
pipelines for all 283 training seniors, and serves as a regression test after fixes.

**Inputs:**
- `C:/Users/jramo/OneDrive/Desktop/02. AgeSense/osca-system/osca.csv`
  (original training data; column names already renamed to internal keys)
- `python/models/` (model artifacts, for `preprocess_service` to load)

**Reference pipeline:** Inline Python replicating the exact notebook preprocessing
(cells 6, 9/12, 14, 16) — reverse scoring, ordinal encoding, `compute_section_*`
functions, `compute_rule_based_risk`, and `ENVIRONMENT_RAW`/`FUNCTIONAL_RAW` domain
averages. Produces a reference DataFrame for all 283 seniors.

**Live pipeline:** Converts each CSV row to the JSON format Laravel sends
(`multiselect → list`, `"Yes"/"No" → True/False`, etc.), then calls
`preprocess_service.preprocess()` directly (imported, not via HTTP). Extracts 51
ML features + `rule_composite` + `env_score` + `func_score` from the returned dict.

**Output (`python/scripts/audit_output/feature_alignment_report.csv`):**

| feature | max_delta | mean_delta | n_mismatch | status |
|---------|-----------|------------|------------|--------|
| sec5_eco_stability | 0.0000 | 0.0000 | 0 | PASS |
| env_score | 0.2341 | 0.1823 | 247 | FAIL |
| ... | | | | |

Console summary:
```
Features PASS (delta < 0.001): 38 / 51
Features FAIL                : 13 / 51
Composite risk PASS          : 241 / 283 (85.2%)
```

**Pass criteria (must all be true before cutover):**
- All 51 ML features: `max_delta < 0.001`
- Composite risk: ≥ 280/283 seniors match (≥ 99%)
- Risk level: ≥ 280/283 match notebook CSV risk_level

### Component: Preprocess Service Fixes (`python/services/preprocess_service.py`)

**Fix 1 — `_normalise_multiselect(value) → str`**

Add a single helper at module level:

```python
def _normalise_multiselect(value) -> str:
    """Normalise any multiselect input to a comma-separated string.
    
    Laravel sends JSON arrays; the notebook used CSV comma strings.
    Both forms must produce identical scores from score_multiselect().
    """
    if value is None:
        return ""
    if isinstance(value, list):
        return ", ".join(str(v).strip() for v in value if str(v).strip())
    return str(value)
```

Apply at the top of `preprocess()`, replacing every raw multiselect field with its
normalised string form before any downstream logic runs:

```python
for _f in [
    "income_source", "real_assets", "movable_assets", "community_service",
    "living_with", "specialization", "medical_concern", "dental_concern",
    "optical_concern", "hearing_concern", "social_emotional_concern",
    "healthcare_difficulty", "household_condition",
]:
    raw[_f] = _normalise_multiselect(raw.get(_f, ""))
```

After this, **all** downstream code operates on plain strings exactly as in the notebook:
- `score_multiselect` → correct asset / income / community scores
- Derived flags (`has_pension`, `is_association_member`, `lives_alone`) → correct
- Count features (`living_with_count`, `community_service_count`) → correct
- `raw_context` dict (built from `raw` after normalisation) → `disease_severity_score`
  and `social_emotional_score` receive correct strings → correct domain risk scores →
  correct recommendation rules → correct recommendation counts

**Fix 2 — `who_domain_scores.env_score` and `func_score`**

Replace the narrow lists with the notebook's exact `ENVIRONMENT_RAW` and
`FUNCTIONAL_RAW` feature sets:

```python
"env_score": _domain_avg(enc, [
    "env_income_limit_r", "env_fin_household", "env_fin_medical", "env_fin_personal",
    "env_safe_home", "env_safe_neighborhood", "env_home_comfort", "env_service_access",
    "income_enc",
    "soc_social_support", "soc_close_friend", "soc_participation",
    "soc_opportunity", "soc_respect",
    "living_with_count", "community_service_count",
    "sec5_real_asset_score", "sec5_movable_asset_score", "sec5_income_source_score",
    "sec5_eco_stability", "sec4_household_risk",
    "sec3_community_score",
]),
"func_score": _domain_avg(enc, [
    "func_independence", "func_autonomy", "func_control",
    "phy_mobility_outside", "phy_mobility_indoor",
    "soc_participation", "soc_opportunity",
    "education_enc", "checkup_enc",
    "sec3_education_norm", "sec3_skill_score",
    "sec2_family_support",
]),
```

**Fix 3 — `child_support_enc` / `spouse_working_enc` completeness**

Verify (and if needed add) the `"Occasional": 0.5` and `"Deceased": 0` mappings with
case-insensitive matching to match the notebook exactly.

### Component: Validation Run

After all fixes:

1. Run `audit_feature_alignment.py` → confirm all 51 features PASS
2. `php artisan ml:diagnose-senior {id}` on 5 HIGH-risk seniors → composite Δ < 0.01
3. Set `.env` `ENABLE_NOTEBOOK_OVERRIDES=false`, restart Flask inference service
4. `php artisan ml:batch-analyze --force`
5. Confirm distribution: HIGH ≈ 54, MODERATE ≈ 191, LOW ≈ 38 (within ±3)
6. `php artisan test` — all 29 tests pass

### Component: Deployment Hardening

To ensure consistent results across all devices:

- `ENABLE_NOTEBOOK_OVERRIDES` defaults to `False` in `inference_service.py` (already
  changed in the previous fix — `64a2143`). The startup warning logs any override.
- `ENABLE_DETERMINISTIC_CLUSTER=true` (default) ensures cluster assignment uses
  nearest-centroid in scaled space — no UMAP randomness.
- `NUMBA_THREADING_LAYER=workqueue`, `NUMBA_NUM_THREADS=1`, `OMP_NUM_THREADS=1` are
  set at inference service startup — eliminates threading non-determinism.
- `senior_predictions.csv` is kept as a reference artifact but removed from the
  inference critical path. No code path falls back to it when overrides are off.
- The audit script (`audit_feature_alignment.py`) is the canonical pre-deployment
  check: run it on any new device after installing the model artifacts to confirm
  feature alignment before going live.

---

## Files Touched

| File | Change |
|------|--------|
| `python/scripts/audit_feature_alignment.py` | New — reference vs. live feature comparison |
| `python/scripts/audit_output/.gitkeep` | New — output directory |
| `python/services/preprocess_service.py` | Fix 1 (multiselect normalisation), Fix 2 (domain score lists), Fix 3 (encoding edge cases) |
| `.env` | `ENABLE_NOTEBOOK_OVERRIDES=false` after validation |

---

## Out of Scope

- Retraining the GBR/RFR models
- Changing risk thresholds or composite formula weights
- Modifying recommendation logic
- Changing the cluster profiles or centroid definitions

---

## Success Criteria

| Check | Target |
|-------|--------|
| Audit script: all 51 features | `max_delta < 0.001` |
| Composite risk match (283 seniors) | ≥ 280 / 283 |
| Risk level match (283 seniors) | ≥ 280 / 283 |
| Cluster assignment match (283 seniors) | ≥ 280 / 283 |
| Distribution after batch re-run | HIGH ≈ 54 ± 3, MOD ≈ 191 ± 3, LOW ≈ 38 ± 3 |
| Cluster distribution after batch re-run | High Functioning ≈ 75, Moderate/Mixed ≈ 132, Low Functioning ≈ 76 |
| Recommendation count after batch re-run | 4,666 (exact) |
| Individual re-run matches batch for same senior | Yes (no divergence) |
| All PHP tests | 29 / 29 pass |
