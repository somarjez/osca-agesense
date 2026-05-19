# ML Pipeline — Technical Reference

This document covers the full machine learning pipeline in AgeSense: data flow, feature engineering, model architecture, ensemble design, recommendation generation, fallback strategy, and runtime configuration.

---

## Table of Contents

1. [Overview](#overview)
2. [Model Artefacts](#model-artefacts)
3. [Preprocessing Pipeline](#preprocessing-pipeline)
4. [Feature Engineering Detail](#feature-engineering-detail)
5. [Clustering](#clustering)
6. [Risk Scoring Ensemble](#risk-scoring-ensemble)
7. [Risk Level Classification](#risk-level-classification)
8. [Recommendation Generation](#recommendation-generation)
9. [Batch Pipeline Optimisation](#batch-pipeline-optimisation)
10. [Three-Tier Fallback Strategy](#three-tier-fallback-strategy)
11. [Runtime Configuration](#runtime-configuration)
12. [Testing the Pipeline](#testing-the-pipeline)

---

## Overview

The ML pipeline takes a raw senior citizen profile and QoL survey as input and produces:

- A **cluster assignment** (1 = High Functioning, 2 = Moderate / Mixed Needs, 3 = Low Functioning / Multi-domain Risk)
- **Risk scores** for Intrinsic Capacity (IC), Environment (ENV), Functional Ability (FUNC), and a composite overall score — each in [0, 1]
- **Risk levels** (HIGH / MODERATE / LOW) for each domain and overall, plus `priority_flag` for urgency within HIGH
- **Rule-based domain risks** for 7 sub-domains (medical, financial, social, functional, housing, healthcare access, sensory)
- **WHO domain scores** (IC, ENV, FUNC, QoL) on a 1–5 scale
- **Prescriptive recommendations** — prioritised action items per domain, driven by model output and profile data

All models were trained in a Jupyter notebook (`osca5.ipynb`) on the Pagsanjan OSCA dataset and exported as `.pkl` / `.json` artefacts consumed by the two Flask services at runtime.

---

## Model Artefacts

All artefacts live in `python/models/` (overridable via `ML_MODELS_PATH` in `.env`). The models are committed to the repository — no manual placement is needed after cloning.

### Trained models

| File | Type | Input | Output |
|---|---|---|---|
| `scaler.pkl` | StandardScaler | Raw feature vector | Standardised feature vector |
| `umap_nd.pkl` | UMAP reducer | Scaled features | 10-dimensional embedding |
| `kmeans.pkl` | KMeans (K=3) | 10-D UMAP embedding | Raw cluster ID (0, 1, 2) |
| `edu_encoder.pkl` | OrdinalEncoder | Education level string | Integer (1–9) |
| `income_encoder.pkl` | OrdinalEncoder | Income range string | Integer (1–9) |
| `gbr_ic_risk.pkl` | GradientBoostingRegressor | Risk feature vector | IC risk score [0,1] |
| `gbr_env_risk.pkl` | GradientBoostingRegressor | Risk feature vector | ENV risk score [0,1] |
| `gbr_func_risk.pkl` | GradientBoostingRegressor | Risk feature vector | FUNC risk score [0,1] |
| `rfr_ic_risk.pkl` | RandomForestRegressor | Risk feature vector | IC risk score [0,1] |
| `rfr_env_risk.pkl` | RandomForestRegressor | Risk feature vector | ENV risk score [0,1] |
| `rfr_func_risk.pkl` | RandomForestRegressor | Risk feature vector | FUNC risk score [0,1] |

### Configuration files

| File | Purpose |
|---|---|
| `feature_list.json` | Ordered list of feature names expected by `scaler.pkl` |
| `final_feature_list.json` | Final post-VIF feature list used in training (alias kept for compatibility) |
| `vif_retained_features.json` | VIF-filtered subset — used as fallback feature list for clustering |
| `ml_risk_features.json` | Feature names expected by the GBR/RFR risk models |
| `cluster_mapping.json` | Maps raw KMeans IDs `{0,1,2}` → named IDs `{1,2,3}` |
| `asset_weights.json` | Runtime-overridable scoring weights (see [Runtime Configuration](#runtime-configuration)) |
| `cluster_metadata.json` | Optional — overrides cluster names/descriptions without code changes |
| ~~`predictions/senior_predictions.csv`~~ | Removed — superseded by DB-backed ML result cache (see [Cross-device Consistency](#cross-device-consistency)) |

---

## Preprocessing Pipeline

**Service:** `python/services/preprocess_service.py` — Flask on port 5001

**Endpoint:** `POST /preprocess`

**Input:** Raw JSON payload from `MlService::buildRawPayload()` in Laravel

**Output:**
```json
{
  "status": "success",
  "identity": { "first_name", "last_name", "barangay", "age" },
  "feature_map": { "<feature_name>": <float>, ... },
  "feature_names": ["age", "education_enc", ...],
  "scaled_features": [<float>, ...],
  "reduced_features": [<float x10>],
  "cluster_feature_names": ["...", ...],
  "section_scores": { "sec1_age_risk": ..., "overall_wellbeing": ... },
  "who_domain_scores": { "ic_score": ..., "env_score": ..., "func_score": ..., "qol_score": ... },
  "rule_scores": { "risk_medical": ..., "rule_composite": ..., "risk_level_rule": "HIGH" }
}
```

### Processing steps

1. **Demographic encoding** — age, gender (0/1/2), marital status (0–5)
2. **Ordinal encoding** — education and income using saved encoders (`edu_encoder.pkl`, `income_encoder.pkl`); falls back to hardcoded ordinal lists if encoders are missing
3. **Multi-select scoring** — weighted scores for assets, income sources, community participation, skills, household risk conditions, and diseases using `_weighted_score()` with decaying weights (`0.5^idx` per additional item)
4. **Boolean flags** — `has_pension`, `lives_alone`, `is_association_member`, `has_medical_checkup`
5. **QoL feature extraction** — 31 items mapped from survey column names to feature names; 4 reverse-scored items (`phy_pain_r`, `phy_health_limit_r`, `psych_lonely_r`, `env_income_limit_r`) are transformed as `6 − raw_value`
6. **Section scores** — 6 composite scores from weighted combinations of encoded features (see [Feature Engineering Detail](#feature-engineering-detail))
7. **WHO domain scores** — IC, ENV, FUNC, QoL on a 1–5 scale via domain-grouped averages
8. **Rule-based risk engine** — 7 domain risks and a weighted composite (used as the rule-based component of the ensemble)
9. **Feature scaling** — StandardScaler applied to the cluster feature vector
10. **UMAP reduction** — 10-D embedding produced by `umap_nd.pkl`; skipped per-senior in batch mode

---

## Feature Engineering Detail

### Section scores

| Score | Formula summary | Weight in overall wellbeing |
|---|---|---|
| `sec1_age_risk` | `0.20` if age < 70, `0.50` if 70–79, `0.85` if 80+ | 5% |
| `sec2_family_support` | Working children × 0.35 + child support × 0.35 + spouse working × 0.20 + household size × 0.10 | 8% |
| `sec3_hr_score` | Education norm × 0.45 + skill score × 0.30 + community score × 0.25 | 7% |
| `sec4_dependency_risk` | Lives alone × 0.40 + household risk × 0.45 + (1 − living-with norm) × 0.15 | 12% |
| `sec5_eco_stability` | Income norm × 0.30 + real assets × 0.25 + income source score × 0.20 + movable assets × 0.10 + pension × 0.10 + child support × 0.05 | 25% |
| `sec6_health_score` | Physical avg × 0.35 + psychological avg × 0.30 + functional avg × 0.25 + checkup × 0.10 | 43% |

Overall wellbeing = weighted combination of (1 − risk sections) and wellbeing sections.

### Asset and income scoring

`_weighted_score()` takes a multi-select list and a weight table, matches items by substring, and applies geometric decay so additional items add progressively less:

```
score = w[0] × 0.5^0 + w[1] × 0.5^1 + w[2] × 0.5^2 + ...
```

Items are sorted descending by weight before applying decay. The result is clamped to [0, 1].

**Note on "No known assets":** Selecting "No known assets" scores `0.0` — this is correct and intentional. It means no asset wealth, which is the same baseline as leaving the field blank. The resulting `sec5_eco_stability = 0.0` correctly elevates financial and environmental risk scores.

### Disease severity scoring

`_disease_severity_score()` matches the medical concern text against a keyword weight table and applies decay for multiple conditions. Text matching is lowercase substring, so partial matches work (e.g., "chronic kidney disease" matches both "chronic kidney disease" and "kidney").

### WHO domain scores

| Domain | Features averaged |
|---|---|
| IC (Intrinsic Capacity) | 12 physical + psychological + functional items |
| ENV (Environment) | 22 features: financial, safety, social, community, asset scores |
| FUNC (Functional Ability) | 12 features: functional, mobility, participation, education, skill |
| QoL | 6 items: enjoyment, satisfaction, future outlook, meaningfulness, spirituality |

---

## Clustering

**Model:** KMeans with K=3, trained on the Pagsanjan dataset

**Input:** 10-dimensional UMAP embedding from `umap_nd.pkl`

**Pipeline:** raw features → `scaler.pkl` → `umap_nd.pkl` → `kmeans.pkl` → raw cluster ID (0, 1, 2) → `cluster_mapping.json` → named ID (1, 2, or 3)

### Cluster profiles (defaults)

| Named ID | Name | IC | ENV | FUNC | Typical risk |
|---|---|---|---|---|---|
| 1 | High Functioning | High | High | High | LOW |
| 2 | Moderate / Mixed Needs | Moderate | Moderate | Moderate | MODERATE |
| 3 | Low Functioning / Multi-domain Risk | Low | Low | Low | HIGH |

Cluster names and descriptions can be overridden at runtime by placing a `cluster_metadata.json` file in the model directory — no code change required.

### Fallback cluster assignment

If KMeans or UMAP is unavailable, the system assigns clusters from the overall wellbeing score:

| Wellbeing score | Raw cluster ID | Named ID |
|---|---|---|
| ≥ 0.65 | 0 | 1 (High Functioning) |
| 0.40–0.64 | 1 | 2 (Moderate) |
| < 0.40 | 2 | 3 (Low Functioning) |

A warning is added to the result's `warnings` list when the heuristic is used.

---

## Risk Scoring Ensemble

The inference service blends three sources for each of the four risk domains:

| Source | Weight | When used |
|---|---|---|
| Rule-based section score (fallback) | 45% | Always |
| Gradient Boosting regressor | 35% | When `gbr_*_risk.pkl` loads successfully |
| Random Forest regressor | 20% | When `rfr_*_risk.pkl` loads successfully |

Weights renormalise proportionally when a model is unavailable. If both GBR and RFR are missing, only the rule-based score is used (100%).

### Rule-based fallback scores

The rule-based risk engine (`_compute_rule_based_risk()` in `preprocess_service.py`) computes 7 domain risks from section scores and encoded features using explicit formulas. These serve both as the 45% ensemble component and as the sole input when ML models are unavailable.

Domain risks: medical, financial, social, functional, housing, healthcare access, sensory

Composite = weighted sum across domains:

| Domain | Weight |
|---|---|
| Medical | 28% |
| Financial | 18% |
| Social | 14% |
| Healthcare access | 12% |
| Housing | 10% |
| Functional | 10% |
| Sensory | 8% |

---

## Risk Level Classification

Applied after ensemble scoring:

| Level | Composite risk score | Priority flag |
|---|---|---|
| HIGH (Urgent) | ≥ 0.70 | `urgent` |
| HIGH | 0.50 – 0.69 | `priority_action` |
| MODERATE | 0.30 – 0.49 | `planned_monitoring` |
| LOW | < 0.30 | `maintenance` |

There is no CRITICAL level. Seniors with composite ≥ 0.70 are tagged `priority_flag = urgent` and displayed as **High Risk + Urgent** in the UI. The same thresholds apply to IC, ENV, and FUNC domain risk scores individually.

---

## Recommendation Generation

Recommendations are generated by five domain functions in `inference_service.py`. Each function reads directly from the senior's `feature_map`, `section_scores`, and `raw_context` — they do not use lookup tables indexed by cluster or risk level.

| Function | Key inputs | Example action triggers |
|---|---|---|
| `generate_health_recs()` | `medical_concern`, `dental_concern`, `optical_concern`, `hearing_concern`, `social_emotional_concern` | Disease keyword matching → disease-specific action sets from `DISEASE_ACTIONS` dict |
| `financial_actions()` | `income_enc`, `sec5_eco_stability`, `sec5_real_asset_score`, `has_pension`, `env_fin_medical` | Low income band → DSWD/SLP referral; no pension → social pension eligibility check |
| `social_actions()` | `sec4_lives_alone`, `soc_social_support`, `soc_close_friend`, `sec2_family_support`, `is_association_member` | Lives alone → home visit program; low social support → OSCA friendship club |
| `functional_actions()` | `phy_mobility_outside`, `phy_mobility_indoor`, `func_independence`, `age`, `checkup_enc` | Low mobility → assistive device assessment; age ≥ 80 → geriatric assessment |
| `hc_access_actions()` | `healthcare_difficulty`, `env_service_access`, `sec5_movable_asset_score` | "cost" in difficulty → Malasakit Center; "transport" → barangay transport coordination |

### Urgency mapping

| Overall risk level | Recommendation urgency |
|---|---|
| HIGH (urgent flag) | urgent |
| HIGH | priority_action |
| MODERATE | planned_monitoring |
| LOW | maintenance |

### Disease action coverage

The `DISEASE_ACTIONS` dictionary covers 22+ specific conditions including:
coronary heart disease, heart disease, stroke, cancer, dementia, Alzheimer's, Parkinson's, diabetes, hypertension, depression, asthma, COPD, tuberculosis, arthritis, osteoporosis, glaucoma, cataract, hearing impairment, chronic kidney disease, anemia, physical disability, and a `__generic__` fallback for unrecognised health concerns.

---

## Batch Pipeline Optimisation

For batch analysis (`local_ml_runner.py batch` or `MlController::batchRun()`):

```
Step 1: Preprocess all N seniors
        (OSCA_BATCH_MODE=1 — UMAP skipped per-senior in preprocess_service.py)

Step 2: batch_cluster_assign()
        Single pass: scaler → UMAP → KMeans on the full N × features matrix
        Injects _precomputed_raw_cluster_id into each preprocessed dict

Step 3: infer() for each senior
        Fast path: reads _precomputed_raw_cluster_id, skips scaler/UMAP/KMeans
        Only runs risk ensemble + recommendation generation per senior

Step 4: RecalculateClusters job (automatic — dispatched via Bus::batch()->then())
        Runs fix_cluster_distribution.py as a subprocess after all batch jobs finish
        Re-runs all seniors through a single batch UMAP transform (~30 seconds)
        Aligns every senior's cluster assignment to match the full-population UMAP
        Updates ml_results and recommendations rows in the database
```

**Performance impact:** For 275 seniors, UMAP runs once (not 275 times). This is the primary cost driver — UMAP transform with numba JIT on Windows takes ~5–10 seconds the first call and ~1–2 seconds subsequently. In HTTP mode (Flask services running), full batch analysis for 275 seniors completes in under 60 seconds. Step 4 (recluster) adds ~30 seconds.

**Why Step 4 is necessary:** Each Laravel batch job (Step 3) dispatches seniors in chunks of 100. If a senior's cluster was previously cached from a single-point UMAP transform (e.g., from `runSingle()`), it may drift from the full-population assignment. The automatic recluster corrects this, ensuring all seniors' clusters are aligned with the same UMAP embedding.

**UI indication:** While Step 4 runs, the batch progress bar pulses grey and shows "Finalising health group assignments…". The page auto-reloads when recluster_done is set.

**Fallback within batch:** If `batch_cluster_assign()` fails (model unavailable), each senior's `infer()` call independently falls back to the wellbeing heuristic.

**Chunk size:** Laravel's `chunk(100)` splits large batches into 100-senior subsets, each spawning one Python subprocess. For 275 seniors: 3 subprocess calls.

---

## Three-Tier Fallback Strategy

The `MlService.php` orchestrates three fallback tiers transparently:

### Tier 1 — HTTP Flask services

Both services are running and reachable on ports 5001/5002.

- Single senior: `POST /preprocess` → `POST /infer`
- Batch: `POST /batch_preprocess` → `POST /batch_infer`

Health checked once per request lifecycle (result cached in `$preprocessAvailable` / `$inferenceAvailable`).

### Tier 2 — Local Python subprocess

Flask services are unreachable but Python is available at `python/venv/Scripts/python.exe`.

- Single senior: `local_ml_runner.py combined` (one process, full ML)
- Batch: `local_ml_runner.py batch` (one process, all seniors)

The subprocess receives the raw payload via stdin and returns the full inference result via stdout as JSON.

**Environment setup in subprocess:** `MlService::pythonEnvironment()` passes the full parent process environment (preserving PATH, TEMP, Winsock) plus NUMBA threading overrides to prevent WinError 10106.

### Tier 3 — PHP heuristic

Python is entirely unavailable.

`fallbackPreprocess()` → `fallbackInfer()` in `MlService.php`:
- Section scores computed from age only (everything else defaults to 0.5)
- Cluster assigned from composite risk thresholds (no KMeans)
- Risk scores = `1 − wellbeing_score` (same for all domains)
- One generic recommendation: "ML service unavailable. Please re-run when service is restored."
- Result tagged `status: success_fallback`

---

## Runtime Configuration

### `asset_weights.json`

Place in the model directory to override scoring weights without code changes. Loaded once at startup via `lru_cache`. Supported keys:

```json
{
  "real_assets":              { "house & lot": 1.0, "lot": 0.6, ... },
  "movable_assets":           { "automobile": 1.0, "mobile phone": 0.7, ... },
  "income_sources":           { "own pension": 1.0, "dependent on children": 0.35, ... },
  "community":                { "community leader": 1.0, ... },
  "skills":                   { "medical": 0.9, "cooking": 0.45, ... },
  "household_risk":           { "informal settler": 1.0, ... },
  "disease_weights":          { "coronary heart disease": 1.0, "diabetes": 0.85, ... },
  "domain_weights":           { "medical": 0.28, "financial": 0.18, ... },
  "income_risk":              { "1": 1.0, "2": 0.85, ..., "9": 0.05 },
  "section_weights":          { "sec1": 0.05, "sec6": 0.43, ... },
  "social_emotional_weights": { "depressed": 0.8, "loneliness": 0.6, ... }
}
```

Missing keys fall back to hardcoded defaults. Malformed values are skipped.

### `cluster_metadata.json`

Override cluster names and descriptions at runtime:

```json
{
  "1": { "name": "Custom Cluster Name", "ic_level": "High", "env_level": "High", "func_level": "High", "interpretation": "Custom description." },
  "2": { ... },
  "3": { ... }
}
```

All three cluster IDs must be present or the file is ignored and hardcoded defaults are used.

### `ENABLE_NOTEBOOK_OVERRIDES` (.env)

**Default: `false`** (CSV-based override system has been retired).

Previously, this flag made the inference service match seniors against `senior_predictions.csv`. That file has been removed — the DB-backed ML result cache (described below) now provides cross-device consistency without requiring any per-machine CSV files.

Leave this set to `false`. Setting it to `true` has no effect unless the CSV files are manually restored.

---

## Cross-device Consistency

### The problem

UMAP's `.transform()` on a single data point is non-deterministic across different CPU families (Intel vs AMD) and different library patch versions. Running the same senior on two machines can produce different cluster assignments.

### The solution — DB-backed ML result cache

When `inference_service.py` processes a senior:

1. **DB cache lookup first** — queries `ml_results` for the latest row for that `senior_id`.  
   - **Hit:** stored `cluster_named_id`, `composite_risk`, `risk_level`, and risk scores are returned directly. UMAP is not run. All devices get identical results.  
   - **Miss (new senior):** UMAP runs once on this machine, result is stored in `ml_results`, and immediately written back to the DB cache. Every subsequent request (on any device) hits the cache.

2. The `senior_id` is passed from Laravel through the preprocessor and into the inference service via the `buildRawPayload()` call in `MlService.php`.

3. `preprocess_service.py` forwards `senior_id` in its output so it reaches `inference_service.py`.

### Re-clustering after bulk adds

Running "Run Full Batch" from the ML dashboard (`/ml/batch`) **automatically re-clusters** all seniors after every batch analysis via the `RecalculateClusters` job (Step 4 above). No manual intervention is needed for normal day-to-day operation.

The only case requiring a manual recluster is after a fresh database seed (`migrate:fresh` + `db:seed`) on a new machine — in that case, run:

```powershell
cd osca-system
python\venv\Scripts\python.exe python\fix_cluster_distribution.py
```

This re-runs all seniors through a **single batch UMAP transform** (the same way the notebook does), updates every `ml_results` row in the database, and prints the final cluster and risk distribution.

---

## Testing the Pipeline

Three test suites exist in `python/tests/`. Run all with the virtual environment activated:

```bash
cd python
venv\Scripts\activate      # Windows
# source venv/bin/activate  # macOS/Linux
python tests/test_ml_pipeline.py
python tests/test_inference_paths.py
python tests/test_inference_e2e.py
```

### Test coverage

**`test_ml_pipeline.py`** — Integration tests for the preprocessing and inference pipeline:

| Test | What it verifies |
|---|---|
| 1. Single combined inference | Full preprocess + infer path produces valid cluster, risk scores, and recommendations |
| 2. Batch real KMeans | Batch mode uses actual KMeans (not wellbeing heuristic) when model files exist |
| 3. Missing asset_weights.json | Preprocessing succeeds and uses hardcoded defaults when the file is absent |
| 4. Missing cluster_metadata.json | Inference succeeds and uses hardcoded cluster profiles when the file is absent |
| 5. Modified cluster_metadata.json | Cluster names change at runtime without code changes |
| 6. Modified asset_weights.json | Asset scores change at runtime when weights file is modified |

**`test_inference_paths.py`** — Validates model files present, `ENABLE_NOTEBOOK_OVERRIDES` disabled, urgency logic (5 cases), `_priority_flag` thresholds (8 cases), and that all 14 required model/JSON files load correctly.

**`test_inference_e2e.py`** — End-to-end inference on two synthetic seniors (high-risk / low-risk). Checks: status, cluster range, risk scores in [0,1], risk level accuracy, priority flag validity, urgency consistency, all XAI field presence (section scores, domain risks, WHO scores), and hc_access recommendation coverage when `healthcare_difficulty` is set.

> **Note on cluster assertions in e2e tests:** UMAP `.transform()` on only 2 seniors is non-deterministic — the expected cluster is logged as informational only. The authoritative cluster test is `test_ml_pipeline.py` Test 2, which uses a real batch of 3 seniors against the trained model.

All tests must pass before deploying new model artefacts. A failed test 2 ("no heuristic fallback") means a model file is missing or incompatible with the current feature list.

### Manual single-senior test

```bash
cd python
venv\Scripts\activate
cd services
echo '{"age":72,"gender":"Female","educational_attainment":"High School Graduate","monthly_income_range":"1,000 - 5,000","income_source":["own pension"],"real_assets":["house & lot"],"movable_assets":["mobile phone"],"living_with":["children"],"household_condition":[],"community_service":["senior citizen association member"],"specialization":["cooking"],"has_medical_checkup":true,"medical_concern":"hypertension","dental_concern":"tooth loss","optical_concern":"cataract","hearing_concern":"healthy hearing","social_emotional_concern":"loneliness","healthcare_difficulty":"cost","qol_responses":{"qol_enjoy_life":3,"qol_life_satisfaction":3,"qol_future_outlook":3,"qol_meaningfulness":3,"phy_energy":3,"phy_pain_r":3,"phy_health_limit_r":3,"phy_mobility_outside":3,"phy_mobility_indoor":4,"psych_happiness":3,"psych_peace":3,"psych_lonely_r":2,"psych_confidence":3,"func_independence":4,"func_autonomy":3,"func_control":3,"env_income_limit_r":2,"soc_social_support":3,"soc_close_friend":3,"soc_participation":3,"soc_opportunity":3,"soc_respect":4,"env_safe_home":4,"env_safe_neighborhood":4,"env_service_access":3,"env_home_comfort":3,"env_fin_medical":2,"env_fin_household":2,"env_fin_personal":2,"spi_belief_comfort":4,"spi_belief_practice":4}}' | python local_ml_runner.py combined
```

A successful run shows `"status": "success"` and `"warnings": []`.
