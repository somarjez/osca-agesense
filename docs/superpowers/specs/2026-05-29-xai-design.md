# XAI (Explainable AI) — System Design

> **Sub-project C of 4.**
> Adds per-senior risk driver explanations and global model feature importance
> to the OSCA system without using SHAP or any external explainability library.

**Date:** 2026-05-29
**Status:** Approved for implementation
**Scope:** Sub-project C — XAI only (no SHAP, no LIME)

---

## Goal

Every ML result in the system gets a human-readable explanation: *which factors drove this senior's risk score up or down, and by how much?* Explanations appear on the senior profile page (per-senior) and on the Cluster Report page (global model view). Data is computed during inference and persisted alongside the ML result — no extra HTTP calls at view time.

---

## Technique: Feature Importance × Deviation

For each GBR domain model (`gbr_ic_risk`, `gbr_env_risk`, `gbr_func_risk`):

```
contribution[i] = feature_importances_[i] × (senior_value[i] − cluster_mean[i]) × effect_sign[i]
```

`effect_sign[i]` (+1 or −1) is precomputed per domain in `cluster_feature_means.json` by
correlating each feature against the GBR's predictions across all 283 seniors. Because
`feature_importances_` are unsigned, this sign is required so that direction means
"raises/lowers risk" rather than merely "above/below cluster average". For protective
features (health, QoL, social scores where higher = better) the sign is −1.

- **Positive contribution** → feature pushes risk **up** (bad, shown in red/amber)
- **Negative contribution** → feature pushes risk **down** (good, shown in green)
- Contributions are normalized: `contribution[i] / Σ|contributions| × 100` → percentage
- Sorted by `abs(contribution)` → top 5 features = key drivers for that domain

The 52 features are also grouped into **sections** (`sec1_age_risk`, `sec2_*`, `sec5_eco_stability`, `sec6_*`, raw QoL items, demographic) — section contributions are summed → section-level ranking (top 3 shown by default).

**Cluster means** are pre-computed at service startup from the 283 seniors' feature_maps (grouped by notebook cluster assignment from `regression_baseline.json`). Stored in memory — no DB call per inference.

**Global model insights** use `feature_importances_` directly from each GBR — model-level, not per-senior. Pre-computed once at startup. Served via `GET /model_insights`.

---

## Architecture

```
inference_service.py (/infer)
  └── after GBR predictions:
      _compute_xai(feature_map, named_id, cluster_means)
        ├── for ic:  GBR ic importance × deviation → top 5 features + top 3 sections
        ├── for env: GBR env importance × deviation → top 5 features + top 3 sections
        └── for func: GBR func importance × deviation → top 5 features + top 3 sections
      → adds "xai": {...} key to infer response

MlService.php (persistResults)
  └── reads $inferResult['xai']
  └── json_encodes into ml_results.xai_data column

Senior Profile (seniors/show.blade.php)
  └── reads $ml->xai_data (already loaded with the record)
  └── renders "Risk Drivers" section (3 domain panels)

Cluster Report (reports/cluster.blade.php)
  └── Alpine.js fetch → GET /xai/model-insights
  └── renders "Model Insights" Chart.js bar chart (3 tabs)

XaiController (new, thin)
  └── GET /xai/model-insights → returns pre-built global importance JSON
```

---

## What Changes

### New Files
| File | Purpose |
|---|---|
| `app/Support/XaiFeatureLabels.php` | Static map: feature name → human-readable label |
| `app/Http/Controllers/XaiController.php` | Serves `GET /xai/model-insights` |

### Modified Files
| File | Change |
|---|---|
| `python/services/inference_service.py` | Add `_compute_xai()`, `_load_cluster_means()`, `GET /model_insights` endpoint; call `_compute_xai` in `_infer()` |
| `app/Services/MlService.php` | Add `'xai_data' => ...` to `updateOrCreate` payload in `persistResults()` |
| `app/Models/MlResult.php` | Add `xai_data` to `$casts` as `array` |
| `resources/views/seniors/show.blade.php` | Add "Risk Drivers" section |
| `resources/views/reports/cluster.blade.php` | Add "Model Insights" Chart.js panel |
| `routes/web.php` | Add `GET /xai/model-insights` route |

### New Migration
`add_xai_data_to_ml_results_table` — adds `xai_data JSON NULL` to `ml_results`.

---

## XAI Response Shape

Added to every `/infer` response under key `"xai"`:

```json
{
  "xai": {
    "ic": {
      "section_drivers": [
        { "section": "Physical Health", "key": "sec6_health_score", "contribution_pct": 34.2, "direction": "up", "value": 0.28, "mean": 0.54 },
        { "section": "Age Factor",      "key": "sec1_age_risk",      "contribution_pct": 18.1, "direction": "up", "value": 0.72, "mean": 0.48 },
        { "section": "Functional Health","key": "sec6_func_score",    "contribution_pct": 12.4, "direction": "up", "value": 0.31, "mean": 0.52 }
      ],
      "feature_drivers": [
        { "feature": "sec6_health_score",  "label": "Overall Health Score",    "contribution_pct": 34.2, "direction": "up",   "value": 0.28, "mean": 0.54, "importance": 0.18 },
        { "feature": "sec1_age_risk",      "label": "Age Risk Factor",         "contribution_pct": 18.1, "direction": "up",   "value": 0.72, "mean": 0.48, "importance": 0.11 },
        { "feature": "phy_energy",         "label": "Physical Energy",         "contribution_pct": 11.3, "direction": "up",   "value": 2.0,  "mean": 3.4,  "importance": 0.09 },
        { "feature": "func_independence",  "label": "Functional Independence", "contribution_pct":  8.7, "direction": "up",   "value": 2.0,  "mean": 3.1,  "importance": 0.07 },
        { "feature": "psych_confidence",   "label": "Confidence & Self-Worth", "contribution_pct":  5.2, "direction": "down", "value": 3.0,  "mean": 2.8,  "importance": 0.05 }
      ]
    },
    "env": { "section_drivers": [...], "feature_drivers": [...] },
    "func": { "section_drivers": [...], "feature_drivers": [...] },
    "cluster_named_id": 4,
    "computed_at": "2026-05-29T08:45:00"
  }
}
```

---

## Feature Label Map (`XaiFeatureLabels.php`)

Maps the 52 `ml_risk_features` names to Filipino-context OSCA labels:

```php
public static array $labels = [
    'sec1_age_risk'               => 'Age Risk Factor',
    'sec2_family_support'         => 'Family Support Score',
    'sec2_family_size_norm'       => 'Household Size',
    'sec3_education_norm'         => 'Education Level',
    'sec3_skill_score'            => 'Skills & Training',
    'sec3_community_score'        => 'Community Engagement',
    'sec3_hr_score'               => 'Human Resources Score',
    'sec4_lives_alone'            => 'Lives Alone',
    'sec4_household_risk'         => 'Household Risk',
    'sec4_dependency_risk'        => 'Dependency Risk',
    'sec5_income_norm'            => 'Income Level',
    'sec5_real_asset_score'       => 'Real Asset Score',
    'sec5_movable_asset_score'    => 'Movable Asset Score',
    'sec5_income_source_score'    => 'Income Source Diversity',
    'sec5_eco_stability'          => 'Economic Stability',
    'sec6_phy_score'              => 'Physical Health Score',
    'sec6_psy_score'              => 'Psychological Health Score',
    'sec6_func_score'             => 'Functional Health Score',
    'sec6_health_score'           => 'Overall Health Score',
    'phy_energy'                  => 'Physical Energy',
    'phy_pain_r'                  => 'Freedom from Pain',
    'phy_health_limit_r'          => 'Health Self-Care Ability',
    'phy_mobility_outside'        => 'Mobility Outside Home',
    'phy_mobility_indoor'         => 'Mobility Indoors',
    'psych_happiness'             => 'Happiness & Positive Affect',
    'psych_peace'                 => 'Inner Peace & Calm',
    'psych_lonely_r'              => 'Freedom from Loneliness',
    'psych_confidence'            => 'Confidence & Self-Worth',
    'func_independence'           => 'Functional Independence',
    'func_autonomy'               => 'Time & Activity Autonomy',
    'func_control'                => 'Life Control & Agency',
    'env_fin_medical'             => 'Medical Affordability',
    'env_fin_household'           => 'Household Expense Coverage',
    'env_fin_personal'            => 'Personal Expense Coverage',
    'env_income_limit_r'          => 'Freedom from Income Constraints',
    'env_safe_home'               => 'Home Safety',
    'env_safe_neighborhood'       => 'Neighborhood Safety',
    'env_home_comfort'            => 'Home Comfort',
    'env_service_access'          => 'Healthcare Service Access',
    'soc_social_support'          => 'Social Support Network',
    'soc_close_friend'            => 'Close Friendship',
    'soc_participation'           => 'Community Participation',
    'soc_opportunity'             => 'Social Opportunity',
    'soc_respect'                 => 'Sense of Respect & Dignity',
    'age'                         => 'Age',
    'education_enc'               => 'Education Level',
    'income_enc'                  => 'Monthly Income Range',
    'has_pension'                 => 'Has Pension',
    'checkup_enc'                 => 'Medical Check-up Frequency',
    'living_with_count'           => 'Number of Household Members',
    'community_service_count'     => 'Community Services Availed',
];
```

---

## Section Grouping (for section_drivers)

```python
SECTION_GROUPS = {
    "Physical Health":    ["sec6_phy_score", "sec6_health_score", "phy_energy", "phy_pain_r",
                           "phy_health_limit_r", "phy_mobility_outside", "phy_mobility_indoor"],
    "Psychological":      ["sec6_psy_score", "psych_happiness", "psych_peace",
                           "psych_lonely_r", "psych_confidence"],
    "Functional Health":  ["sec6_func_score", "func_independence", "func_autonomy", "func_control"],
    "Economic Stability": ["sec5_eco_stability", "sec5_income_norm", "sec5_real_asset_score",
                           "sec5_movable_asset_score", "sec5_income_source_score",
                           "env_fin_medical", "env_fin_household", "env_fin_personal",
                           "env_income_limit_r", "income_enc", "has_pension"],
    "Social Connection":  ["soc_social_support", "soc_close_friend", "soc_participation",
                           "soc_opportunity", "soc_respect"],
    "Environment":        ["env_safe_home", "env_safe_neighborhood", "env_home_comfort",
                           "env_service_access"],
    "Family & Household": ["sec2_family_support", "sec2_family_size_norm", "sec4_lives_alone",
                           "sec4_household_risk", "sec4_dependency_risk", "living_with_count"],
    "Human Capital":      ["sec3_education_norm", "sec3_skill_score", "sec3_community_score",
                           "sec3_hr_score", "education_enc", "checkup_enc",
                           "community_service_count"],
    "Age & Demographics": ["sec1_age_risk", "age"],
}
```

---

## UI: Senior Profile — "Risk Drivers" Section

**Location:** In `seniors/show.blade.php`, below the existing Risk Summary card, above Recommendations.

**Visibility rule:** Only rendered when `$ml->xai_data` is not null (records scored before this feature show nothing — no empty state needed since batch will backfill all).

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│  Risk Drivers  (sub: "Key factors behind this assessment")  │
├──────────────────┬──────────────────┬───────────────────────┤
│  Physical        │  Environment     │  Daily Functioning    │
│  Capacity        │                  │                       │
│  ─────────────   │  ─────────────   │  ─────────────        │
│  ↑ Physical      │  ↑ Medical       │  ↑ Functional         │
│    Health 34%    │    Affordability │    Health 28%         │
│    ████████░░    │    41% ████████  │    ██████░░░░         │
│  ↑ Age Factor    │  ↑ Economic      │  ↑ Independence       │
│    18% ████░░░   │    Stability 22% │    21% ████░░░        │
│  ↑ Functional    │  ↑ Service       │  ↑ Age Factor         │
│    Health 12%    │    Access 15%    │    14% ███░░░░        │
│                  │                  │                       │
│  [Show feature detail ▼]            [Show feature detail ▼] │
│  ↑ = raises risk · ↓ = lowers risk  · % = relative weight  │
└──────────────────┴──────────────────┴───────────────────────┘
```

Each panel:
- Header: domain name + risk level badge (HIGH/MODERATE/LOW)
- 3 section drivers, each: direction arrow (↑ red / ↓ green) + section label + bar + percentage
- Expandable "Show feature detail" (Alpine.js `x-show`) reveals 5 individual features
- Footer note: "↑ raises risk · ↓ lowers risk · % = relative weight"
- Responsive: stacks to 1 column on mobile, 3 columns on md+

**Colors:**
- Direction up (raises risk): `text-red-600` / `bg-red-500` bar
- Direction down (lowers risk): `text-emerald-600` / `bg-emerald-500` bar
- Neutral zero: `text-slate-400`

---

## UI: Cluster Report — "Model Insights" Panel

**Location:** New section in `reports/cluster.blade.php` after the existing cluster cards, before the barangay breakdown table.

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│  Model Insights  (sub: "Feature importance across 283 seniors")│
│  [IC Risk] [Env Risk] [Func Risk]  ← tab switcher          │
│                                                             │
│  Economic Stability    ████████████████████░░░░░░  18.2%   │
│  Physical Health Score ████████████████░░░░░░░░░░  14.6%   │
│  Functional Health     ████████████░░░░░░░░░░░░░░  12.1%   │
│  Age Risk Factor       ██████████░░░░░░░░░░░░░░░░  10.3%   │
│  Income Level          ████████░░░░░░░░░░░░░░░░░░   8.7%   │
│  ...                                                        │
│  (top 15 features shown, grouped by section color banding)  │
└─────────────────────────────────────────────────────────────┘
```

Loaded via `Alpine.js fetch('/xai/model-insights')` on page mount. Tab switches swap the chart dataset (no reload). Horizontal bar chart using Chart.js.

---

## `GET /model_insights` Endpoint (Python)

Pre-built at startup, cached in memory:

```json
{
  "ic": [
    { "feature": "sec5_eco_stability", "label": "Economic Stability",    "importance": 0.182 },
    { "feature": "sec6_health_score",  "label": "Overall Health Score",  "importance": 0.146 },
    ...
  ],
  "env": [...],
  "func": [...],
  "n_seniors": 283,
  "generated_at": "2026-05-29T..."
}
```

---

## PHP `/xai/model-insights` Route

`XaiController@modelInsights` proxies to the Python service:
- Fetches `http://127.0.0.1:5002/model_insights`
- Returns JSON directly to the browser
- 60-second cache (Laravel `cache()`) to avoid repeated proxy calls

---

## Batch Backfill

After the feature ships, all 283 existing `ml_results` need `xai_data` populated. Run:
```
php artisan ml:batch-analyze --force
```
This re-runs inference for all seniors → inference service now computes XAI → `persistResults()` stores `xai_data`. No separate backfill script needed.

---

## Success Criteria

- [ ] `ml_results.xai_data` column exists and is populated after batch
- [ ] Senior profile shows "Risk Drivers" section for all seniors with ml_results
- [ ] Each domain panel shows ≥3 section drivers + expandable feature detail
- [ ] Direction arrows (↑/↓) are correct (up = raises risk, positive contribution)
- [ ] Cluster report shows "Model Insights" panel with 3 domain tabs
- [ ] `/xai/model-insights` endpoint returns valid JSON with all 3 domains
- [ ] Layout is responsive: 3-col desktop, 1-col mobile
- [ ] Old records without xai_data show no broken UI (graceful null handling)

---

## Out of Scope

- SHAP or LIME integration
- Counterfactual explanations ("if X changed, risk would be Y")
- XAI for recommendations (separate feature)
- Per-senior XAI comparison across time
