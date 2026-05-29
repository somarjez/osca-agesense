# DB / Notebook Alignment — Design Spec
**Date:** 2026-05-28  
**Status:** Approved  
**Author:** brainstorming session

---

## Problem Statement

After multiple normalization fixes to `OscaCsvSeeder.php` and `BulkUploadController.php`, the MySQL database (`osca_db`) holds clean, canonical values for all 283 seniors. However, `python/models/predictions/senior_predictions.csv` — used by `inference_service.py` as cluster/risk-level overrides when `ENABLE_NOTEBOOK_OVERRIDES=true` — was generated from the raw, pre-fix `osca.csv`. This means notebook overrides (when matched) may reflect stale predictions based on un-normalized data.

The live Python preprocessing (`preprocess_service.py`) already handles all canonical normalized values correctly via case-insensitive substring matching (verified: `other chronic disease`, `hearing impairment`, `physical disability`, `eye impairment`, `arthritis / gout`, `lack social support`, `lack leisure activities`, `living in a healthy environment` are all covered). No changes to `preprocess_service.py` are required.

**Goal:** Regenerate `senior_predictions.csv` from the clean normalized DB data so notebook overrides align with what is actually in MySQL.

---

## Approach: DB Export → Notebook Re-run (Approach A)

Chosen over:
- **Approach B** (patch raw CSV in Python) — duplicates normalization logic in a second language, creates drift risk.
- **Approach C** (Papermill headless execution) — overkill for a one-time refresh; harder to inspect.

---

## Architecture

Four sequential phases:

```
Phase 1 — Disable notebook overrides
  .env: set ENABLE_NOTEBOOK_OVERRIDES=false
  Effect: inference_service.py no longer defers to senior_predictions.csv;
          live GBR/RFR + KMeans pipeline runs end-to-end for every senior.

Phase 2 — Export normalized DB data
  MySQL (senior_citizens JOIN qol_surveys)
    → python/scripts/export_normalized_db.py
      → osca_normalized.csv  (repo root — used for notebook comparison only)

Phase 3 — Notebook re-run (comparison, not override)
  osca5.ipynb: change data source from "osca.csv" → "osca_normalized.csv"
  Jupyter: Kernel → Restart & Run All
    → python/models/predictions/senior_predictions.csv  (refreshed)
    → python/models/predictions/senior_recommendations_flat.csv  (refreshed)
    → python/models/*.pkl  (refreshed if notebook re-trains models)
  Purpose: generate fresh notebook predictions to COMPARE against live model —
           not to override it.

Phase 4 — Deploy & Validate
  Flask services restarted (picks up ENABLE_NOTEBOOK_OVERRIDES=false)
  compare_notebook_vs_live.py: confirm live model and notebook agree
  End-to-end UI check
```

---

## Components

### 0. `.env` change *(one line)*

Add or update:
```
ENABLE_NOTEBOOK_OVERRIDES=false
```
This makes the live inference pipeline the sole source of truth. The notebook predictions CSV is retained for comparison but never used as an override.

### 1. `python/scripts/export_normalized_db.py` *(new)*

Responsibilities:
- Read DB credentials from `.env` (same `_read_dotenv_value` pattern as existing scripts)
- JOIN `senior_citizens` with latest `qol_surveys` row per senior (`LEFT JOIN`, keyed on `senior_citizen_id`)
- Decode JSON-array multiselect fields → comma-delimited strings
- Map DB column names → notebook CSV column names (see table below)
- Write `osca_normalized.csv` to repo root
- Print summary: rows exported, seniors with no QoL row (filled with blanks), any field warnings

Safeguards:
- Exit with error if 0 rows exported (prevents overwriting with empty file)
- Log a warning per senior with missing QoL data
- On JSON parse failure for any multiselect field: log warning, write raw string value

### 2. `osca5.ipynb` *(one-line change)*

Find the cell containing `pd.read_csv("osca.csv")` or `DATA_CSV = "osca.csv"` and change the path to `"osca_normalized.csv"`. No other changes.

### 3. `python/models/predictions/senior_predictions.csv` *(refreshed output)*

Overwritten by the notebook re-run. Picked up by `inference_service.py` automatically after Flask restart (lru_cache is cleared on process restart).

### 4. Flask services *(restart only, no code changes)*

Both `preprocess_service.py` (port 5000) and `inference_service.py` (port 5001) must be restarted to clear their `lru_cache` so the new predictions CSV is loaded on the next request.

---

## Data Flow — Column Mapping

| DB column | CSV column | Conversion |
|---|---|---|
| `senior_citizens.first_name` | `first_name` | direct |
| `senior_citizens.last_name` | `last_name` | direct |
| `senior_citizens.middle_name` | `middle_name` | direct |
| `senior_citizens.date_of_birth` | `dob` | `DATE` → `m/d/Y` string (no time suffix) |
| `senior_citizens.survey_date` | `timestamp` | `DATE` → `m/d/Y H:i` string |
| `senior_citizens.barangay` | `barangay` | direct |
| `senior_citizens.sex` | `sex` | direct |
| `senior_citizens.civil_status` | `civil_status` | direct |
| `senior_citizens.educational_attainment` | `education` | direct |
| `senior_citizens.monthly_income_range` | `monthly_income_range` | direct |
| `senior_citizens.medical_concern` | `medical_concern` | JSON array → comma string |
| `senior_citizens.income_source` | `income_source` | JSON array → comma string |
| `senior_citizens.real_assets` | `real_assets` | JSON array → comma string |
| `senior_citizens.movable_assets` | `movable_assets` | JSON array → comma string |
| `senior_citizens.living_with` | `living_with` | JSON array → comma string |
| `senior_citizens.community_service` | `community_service` | JSON array → comma string |
| `senior_citizens.household_condition` | `household_condition` | JSON array → comma string |
| `senior_citizens.specialization` | `specialization` | JSON array → comma string |
| `senior_citizens.social_emotional_concern` | `social_emotional_concern` | JSON array → comma string |
| `senior_citizens.problems_needs` | `problems_needs` | JSON array → comma string |
| `senior_citizens.dental_concern` | `dental_concern` | direct |
| `senior_citizens.optical_concern` | `optical_concern` | direct |
| `senior_citizens.hearing_concern` | `hearing_concern` | direct |
| `senior_citizens.has_medical_checkup` | `has_medical_checkup` | bool/tinyint → `Yes` / `No` |
| `senior_citizens.checkup_schedule` | `checkup_schedule` | direct |
| `senior_citizens.healthcare_difficulty` | `healthcare_difficulty` | direct |
| `senior_citizens.housing_concern` | `housing_concern` | direct |
| `qol_surveys.qol_enjoy_life` | `qol_enjoy_life` | direct numeric (1–5); blank if no QoL row |
| `qol_surveys.qol_life_satisfaction` | `qol_life_satisfaction` | direct |
| `qol_surveys.qol_future_outlook` | `qol_future_outlook` | direct |
| `qol_surveys.qol_meaningfulness` | `qol_meaningfulness` | direct |
| `qol_surveys.phy_energy` | `phy_energy` | direct |
| `qol_surveys.phy_pain_r` | `phy_pain_r` | direct |
| `qol_surveys.phy_health_limit_r` | `phy_health_limit_r` | direct |
| `qol_surveys.phy_mobility_outside` | `phy_mobility_outside` | direct |
| `qol_surveys.phy_mobility_indoor` | `phy_mobility_indoor` | direct |
| `qol_surveys.psych_happiness` | `psych_happiness` | direct |
| `qol_surveys.psych_peace` | `psych_peace` | direct |
| `qol_surveys.psych_lonely_r` | `psych_lonely_r` | direct |
| `qol_surveys.psych_confidence` | `psych_confidence` | direct |
| `qol_surveys.func_independence` | `func_independence` | direct |
| `qol_surveys.func_autonomy` | `func_autonomy` | direct |
| `qol_surveys.func_control` | `func_control` | direct |
| `qol_surveys.env_income_limit_r` | `env_income_limit_r` | direct |
| `qol_surveys.soc_social_support` | `soc_social_support` | direct |
| `qol_surveys.soc_close_friend` | `soc_close_friend` | direct |
| `qol_surveys.soc_participation` | `soc_participation` | direct |
| `qol_surveys.soc_opportunity` | `soc_opportunity` | direct |
| `qol_surveys.soc_respect` | `soc_respect` | direct |
| `qol_surveys.env_safe_home` | `env_safe_home` | direct |
| `qol_surveys.env_safe_neighborhood` | `env_safe_neighborhood` | direct |
| `qol_surveys.env_service_access` | `env_service_access` | direct |
| `qol_surveys.env_home_comfort` | `env_home_comfort` | direct |
| `qol_surveys.env_fin_medical` | `env_fin_medical` | direct |
| `qol_surveys.env_fin_household` | `env_fin_household` | direct |
| `qol_surveys.env_fin_personal` | `env_fin_personal` | direct |
| `qol_surveys.spi_belief_comfort` | `spi_belief_comfort` | direct |
| `qol_surveys.spi_belief_practice` | `spi_belief_practice` | direct |

---

## Error Handling

| Scenario | Handling |
|---|---|
| Senior has no `qol_surveys` row | Write blanks for all 31 QoL columns; print warning |
| JSON parse failure on multiselect field | Write raw string; print warning per field per senior |
| DB connection failure | Print message showing which `.env` key is wrong; `sys.exit(1)` |
| Zero rows exported | Print error; `sys.exit(1)` — do NOT overwrite existing CSV |
| Notebook cell exception | Stop; fix manually before continuing |

---

## Verification Steps

| # | Step | Tool | Pass Criterion |
|---|---|---|---|
| 1 | Confirm export | export script stdout | 283 rows, 0 DB errors |
| 2 | Notebook ran clean | Jupyter cell output | All cells green, no exceptions |
| 3 | Predictions file updated | File modified timestamp | Newer than before re-run |
| 4 | Spot-check 5 seniors | Open `senior_predictions.csv` | Sensible cluster/risk values, no nulls |
| 5 | Compare distributions | `compare_notebook_vs_live.py` | LOW/MODERATE/HIGH counts close to current (38/191/54) |
| 6 | Flask health check | `curl http://127.0.0.1:5000/health` (and :5001) | `{"status":"ok"}` |
| 7 | End-to-end UI check | Laravel → view any senior's profile | Risk level shows, no 500 error |

---

## Out of Scope

- Retraining ML model weights (GBR/RFR/KMeans/UMAP `.pkl` files) — acceptable and desirable if the notebook retrains when all cells run (cleaner normalized features improve model quality), but not a blocking requirement; existing `.pkl` files remain valid if only prediction cells are re-executed
- Modifying `preprocess_service.py` — already covers all canonical values
- Re-enabling notebook overrides after validation — `ENABLE_NOTEBOOK_OVERRIDES` stays `false` to preserve live model independence
- Changes to `OscaCsvSeeder.php` or `BulkUploadController.php` — already fixed in prior sessions

---

## Files Changed

| File | Change type |
|---|---|
| `.env` | Add `ENABLE_NOTEBOOK_OVERRIDES=false` |
| `python/scripts/export_normalized_db.py` | New |
| `osca5.ipynb` | One-line edit (CSV path → `osca_normalized.csv`) |
| `osca_normalized.csv` | Generated (gitignored) |
| `python/models/predictions/senior_predictions.csv` | Overwritten by notebook (comparison only) |
| `python/models/predictions/senior_recommendations_flat.csv` | Overwritten by notebook (comparison only) |
