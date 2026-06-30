# AgeSense — System Functionality Documentation

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Deployment Site:** Office of Senior Citizens Affairs (OSCA), Pagsanjan, Laguna, Philippines
> **Framework Basis:** WHO Healthy Ageing Framework (Intrinsic Capacity · Environment · Functional Ability)
> **Document Purpose:** Comprehensive functional reference for developers, thesis panelists, and future maintainers.
> **Last Updated:** 2026-07-01 — Reflects the v2.0.0 / K=4 / 30-feature MinMaxScaler model retrain (360 seniors: 290 original + 70 Magdapio/Barangay II batch). Clustering upgraded from nearest-centroid fallback to KNN (k=5, CV 0.9333). Official metrics: Silhouette 0.5577, Davies-Bouldin 0.6492, Calinski-Harabász 6048.7. GIS module complete (Phase 3). Phase 1 and Phase 2 complete. 360 seniors seeded.

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Current System Capabilities](#2-current-system-capabilities)
3. [Functional Modules](#3-functional-modules)
4. [User Roles and Permissions](#4-user-roles-and-permissions)
5. [Data Handled by the System](#5-data-handled-by-the-system)
6. [Import and Export Features](#6-import-and-export-features)
7. [Reporting and Analytics Features](#7-reporting-and-analytics-features)
8. [Machine Learning and Clustering](#8-machine-learning-and-clustering)
9. [Risk Classification](#9-risk-classification)
10. [Prescriptive Recommendation System](#10-prescriptive-recommendation-system)
11. [Admin Features](#11-admin-features)
12. [User-Facing Features](#12-user-facing-features)
13. [Terminologies Used by the System](#13-terminologies-used-by-the-system)
14. [Current Advantages](#14-current-advantages)
15. [Current Limitations](#15-current-limitations)
16. [Security and Privacy Notes](#16-security-and-privacy-notes)
17. [Known Missing Features and TODOs](#17-known-missing-features-and-todos)
18. [GIS Module](#18-gis-module)
19. [Suggested Future Improvements](#19-suggested-future-improvements)
20. [Current System Status](#20-current-system-status)

---

## 1. System Overview

AgeSense is a web-based decision-support system designed to help OSCA Pagsanjan, Laguna monitor, profile, and analyze the health and well-being of registered senior citizens. The system integrates structured profiling surveys, a WHO-aligned Quality of Life (QoL) assessment instrument, and a machine learning pipeline to classify seniors into health-functioning clusters and generate domain-specific risk scores.

The system produces prescriptive recommendations for each senior based on their cluster membership, computed risk levels, and individual health profile. OSCA staff can monitor real-time analytics on the dashboard, view cluster and risk reports, manage recommendation statuses, and export data for external use.

The ML backend consists of two Python Flask microservices — a preprocessing service that transforms raw profile and survey data into a feature vector, and an inference service that performs K-Means clustering, computes risk scores, and generates recommendations. A three-tier fallback strategy (HTTP services → local Python subprocess → PHP heuristic) ensures the system remains functional even without the Python services running.

---

## 2. Current System Capabilities

The following capabilities are fully implemented and operational in the current codebase:

| Capability | Status |
|---|---|
| Senior citizen profile creation (6-step form) | Implemented |
| Senior citizen profile editing | Implemented |
| Soft delete (archive) and restore of profiles | Implemented |
| Permanent deletion of profiles and all related data | Implemented |
| PDF export of individual senior profile | Implemented |
| WHO-aligned Quality of Life survey (32 items, 8 domains) | Implemented |
| QoL domain score computation (normalized 0–1) | Implemented |
| QoL survey draft save and edit before submission | Implemented |
| ML feature engineering (35+ features, 6 section scores, 7 domain risks) | Implemented |
| K-Means clustering (K=4) with UMAP dimensionality reduction | Implemented |
| Domain-specific risk scoring (IC, Environment, Functional) | Implemented |
| Composite risk score and overall risk level classification | Implemented |
| Prescriptive recommendation generation (5 domain functions: health, financial, social, functional, hc_access) | Implemented |
| Disease-specific recommendation actions (22+ condition keyword mappings) | Implemented |
| Interactive dashboard with real-time KPIs and charts | Implemented |
| Dashboard barangay and risk level filter | Implemented |
| Cluster analysis report with evaluation metrics | Implemented |
| Risk report with paginated at-risk senior list | Implemented |
| Recommendation management (status tracking, staff assignment) | Implemented |
| CSV export for cluster and risk reports | Implemented |
| Batch ML inference for multiple seniors | Implemented |
| Single senior ML re-analysis trigger | Implemented |
| ML service health monitoring | Implemented |
| Auto-start Python services on `php artisan serve` | Implemented |
| Three-tier ML fallback strategy | Implemented |
| Senior citizen archive (soft-deleted records) management | Implemented |
| QoL survey soft-delete cascade on senior archive | Implemented |
| Archived QoL survey restore from archives page | Implemented |
| CSV data import and ML pipeline seeding | Implemented |
| Session-based authentication | Implemented |
| Role-based access control (admin / encoder / viewer) | Implemented |
| User account management UI (admin only) | Implemented |
| Collapsible sidebar with dark mode toggle | Implemented |
| GBR + RFR ensemble risk scoring (IC, ENV, FUNC, composite) | Implemented |
| Batch UMAP + KMeans one-shot clustering (batch optimisation) | Implemented |
| Runtime-configurable scoring weights via asset_weights.json | Implemented |
| Runtime-configurable cluster metadata via cluster_metadata.json | Implemented |
| UI terminology simplification (plain-language labels throughout) | Implemented |
| Cluster analysis archived-senior exclusion fix | Implemented |
| In-app Help Centre with FAQs and user guide | Implemented |
| Sidebar section reorganisation (Archives, Assessment Tools, Help) | Implemented |

---

## 3. Functional Modules

### 3.1 Senior Citizen Profile Management

**Location:** `app/Http/Controllers/SeniorCitizenController.php`, `app/Livewire/Surveys/ProfileSurvey.php`
**Views:** `resources/views/seniors/`, `resources/views/livewire/surveys/profile-survey.blade.php`

The profile module captures comprehensive information about each registered senior citizen through a 6-step multi-page Livewire form:

| Step | Data Collected |
|---|---|
| 1 — Personal Info | Full name, OSCA ID (auto-generated), date of birth, gender, marital status, contact number, blood type, PhilSys ID, religion, ethnic origin, place of birth |
| 2 — Family | Number of children, number of working children, child financial support, spouse working status, household size |
| 3 — Education & Skills | Educational attainment, specializations (multi-select, 24 options), community service involvement (multi-select, 11 options) |
| 4 — Dependency | Living arrangement (multi-select), household condition (multi-select) |
| 5 — Economic | Income sources (multi-select), real assets, movable assets, monthly income range, problems and needs (multi-select) |
| 6 — Health | Medical concerns (multi-select, 18 options), dental/optical/hearing concerns, social-emotional concerns, healthcare access difficulties, medical checkup status and schedule |

The OSCA ID is auto-generated in the format `PAG-YYYY-NNNN` where `PAG` is derived from the municipality name and `NNNN` is a zero-padded per-year sequence (`SeniorCitizen::generateOscaId()`).

The system supports 16 barangays in Pagsanjan, Laguna. Each profile can be soft-deleted (archived), restored from archive, or permanently deleted along with all linked surveys, ML results, and recommendations.

Each senior's profile page (`seniors.show`) displays:
- Latest QoL survey results and domain scores
- Latest ML result: cluster, risk scores, and risk level badges
- Historical ML results (last 3) and historical surveys (last 5)
- All current prescriptive recommendations with urgency and status

### 3.2 Quality of Life Survey

**Location:** `app/Livewire/Surveys/QolSurveyForm.php`, `app/Models/QolSurvey.php`
**Views:** `resources/views/surveys/qol/`, `resources/views/livewire/surveys/qol-survey-form.blade.php`

The QoL survey instrument is adapted from the WHOQOL-BREF (World Health Organization Quality of Life — Brief). It collects 32 items (questions) rated on a 5-point Likert scale across 8 thematic domains:

| Section | Domain | Items |
|---|---|---|
| A | Overall Quality of Life | a1 (enjoy life), a2 (life satisfaction), a3 (future outlook), a4 (meaningfulness) |
| B | Physical Health | b1 (energy), b2 (pain — reverse-scored), b3 (health limits self-care — reverse-scored), b4 (outside activity), b5 (mobility) |
| C | Psychological | c1 (happiness), c2 (calm/peace), c3 (loneliness — reverse-scored), c4 (confidence) |
| D | Independence & Autonomy | d1 (independence), d2 (time control), d3 (life control), d4 (income limits — reverse-scored) |
| E | Social Relationships | e1 (social support), e2 (close person), e3 (community opportunities), e4 (participation), e5 (respect) |
| F | Home & Neighborhood | f1 (home safety), f2 (neighborhood safety), f3 (service access), f4 (home comfort) |
| G | Financial | g1 (household expenses), g2 (medical affordability), g3 (personal wants) |
| H | Spirituality *(optional)* | h1 (belief comfort), h2 (belief practice) |

Four items are reverse-scored (b2, b3, c3, d4): the raw response is inverted (`6 − response`) before scoring. Domain scores are normalized to a 0–1 range. An overall QoL score is computed as a weighted combination of all domain scores.

The form supports draft saving (partial surveys), validation per step, and editing of previously submitted surveys. On submission, `QolSurvey::computeScores()` is called, followed immediately by the ML pipeline trigger via `MlService::runPipeline()`.

### 3.3 Machine Learning Pipeline

**Location:** `app/Services/MlService.php`, `python/services/preprocess_service.py`, `python/services/inference_service.py`, `python/services/local_ml_runner.py`

The ML pipeline is the core analytical engine of the system. It is triggered automatically on survey submission and can also be run manually per senior or in bulk via the batch processor.

#### Pipeline flow

```
Senior Profile + QoL Survey
        ↓
  [Preprocessing]           → Feature engineering, normalization, UMAP reduction
  preprocess_service.py
        ↓
  [Inference]               → K-Means clustering, risk scoring, recommendation generation
  inference_service.py
        ↓
  [Persist Results]         → MlResult + Recommendation records stored in database
  MlService::persistResults()
```

#### Three-tier execution strategy

The system attempts each strategy in sequence, falling back if unavailable:

1. **HTTP Services (preferred):** Calls `http://127.0.0.1:5001/preprocess` then `http://127.0.0.1:5002/infer`
2. **Local Python subprocess:** Executes `python/services/local_ml_runner.py` as a subprocess, passing data via stdin and reading results from stdout. Supports `combined` mode (preprocess + infer in one process) and `batch` mode.
3. **PHP heuristic fallback:** A simplified age-based and income-based estimator implemented entirely in PHP within `MlService`. Results are labeled as fallback in `raw_output`.

### 3.4 Dashboard

**Location:** `app/Livewire/Dashboard/MainDashboard.php`
**Views:** `resources/views/dashboard.blade.php`, `resources/views/livewire/dashboard/main-dashboard.blade.php`

The dashboard provides a real-time overview of the senior citizen population. It refreshes automatically every 60 seconds via `wire:poll.60s` and responds to filter changes without a page reload.

**KPI panels:**
- Total Seniors (all active records)
- QoL Surveyed (seniors with at least one survey)
- High Risk count (including urgent sub-count)
- Moderate Risk count
- Pending Recommendations count

**Charts (Chart.js 4):**
- Risk Distribution doughnut (HIGH / MODERATE / LOW)
- Cluster Distribution doughnut (Cluster 1 / 2 / 3 / 4)
- WHO Domain Scores radar (8 domains, population mean %)
- Age Group Distribution bar chart (60–64, 65–69, 70–74, 75–79, 80–84, 85+)

**Filters:** Barangay selector, Risk level selector — filter all KPIs and charts simultaneously.

**Additional panels:**
- Barangay Breakdown table (total seniors + HIGH risk count per barangay)
- Recent Senior Records list (10 latest with risk badge)
- Urgent Pending Actions list (8 most urgent immediate/urgent recommendations)
- ML Pipeline health status indicators (preprocessor and inference service)

### 3.5 Cluster Analysis Report

**Location:** `app/Http/Controllers/ReportController.php`, `app/Livewire/Reports/ClusterAnalysis.php`
**Views:** `resources/views/reports/cluster.blade.php`, `resources/views/livewire/reports/cluster-analysis.blade.php`

The cluster report presents the results of the K-Means clustering across the senior population.

**Static section (outer page, `reports/cluster.blade.php`):**
- Cluster summary cards (one per cluster): member count, average IC/ENV/FUNC risks, average composite risk, average wellbeing score
- WHO Domain Risk by Cluster grouped bar chart
- QoL Domain Scores by Cluster radar chart
- Clustering Evaluation Metrics table (Silhouette Score, Davies-Bouldin Index, Calinski-Harabász Index, Inertia/WCSS)
- Barangay × Cluster distribution table

**Interactive section (`livewire:reports.cluster-analysis`):**
- Cluster evaluation metric KPI cards with pass/fail indicators
- Barangay filter
- Per-cluster member cards with average risk scores
- WHO Domain Risk by Cluster chart (updates with filter)
- Risk Level Distribution by Cluster stacked bars
- Paginated cluster member table with sortable composite risk, IC/ENV/FUNC risk values, and link to senior profile

### 3.6 Risk Report

**Location:** `app/Http/Controllers/ReportController.php`, `app/Livewire/Reports/RiskReport.php`
**Views:** `resources/views/reports/risk.blade.php`, `resources/views/livewire/reports/risk-report.blade.php`

The risk report focuses on identifying and listing seniors at elevated health risk.

- Risk distribution summary (count and percentage per risk level)
- At-risk senior list (HIGH + urgent-priority), paginated 25 per page
- Filterable by barangay, risk level, and cluster
- Sortable by composite risk, IC risk, ENV risk, FUNC risk
- Domain risk averages (IC, ENV, FUNC) for the full population
- Barangay risk breakdown table
- Pending recommendations grouped by category

### 3.7 Recommendations Management

**Location:** `app/Http/Controllers/RecommendationController.php`
**Views:** `resources/views/recommendations/`

Recommendations are structured action items generated by the ML inference service for each senior. They are stored in the `recommendations` table and can be managed by OSCA staff.

**Recommendation properties:**

| Property | Values |
|---|---|
| Priority | Integer (1 = highest) |
| Type | cluster, domain, section, general |
| Category | health, financial, social, functional, hc_access, general |
| Urgency | immediate, urgent, planned, maintenance |
| Status | pending, in_progress, completed, dismissed |
| Risk Level | low, moderate, high |

**Management actions:**
- View all recommendations grouped by senior (`recommendations.index`)
- View all recommendations for a single senior sorted by priority (`recommendations.show`)
- Update recommendation status (pending → in_progress → completed / dismissed)
- Assign a recommendation to a specific user for follow-up

### 3.8 ML Service Management

**Location:** `app/Http/Controllers/MlController.php`
**Views:** `resources/views/ml/`

- **Status page** (`/ml/status`): Displays health check results for both Python services (online/offline), processing statistics (total processed, critical count, unprocessed count), and a button to start services.
- **Batch processing** (`/ml/batch`): Lists all seniors eligible for (re-)analysis and provides a button to run batch inference in chunks of 100 seniors. Returns a summary of successes, fallbacks, and errors.
- **Single inference** (`POST /ml/run/{senior}`): Re-runs the full ML pipeline for one senior and returns the updated risk level, cluster, and composite risk score as JSON.

---

## 4. User Roles and Permissions

The system uses Laravel session-based authentication with role-based access control powered by `spatie/laravel-permission`. All routes are protected by the `auth` middleware; individual routes are further restricted by the `role` middleware.

### Roles

| Role | Label in UI | Description |
|---|---|---|
| `admin` | Administrator | Full system access including user management, archives, exports, and audit log |
| `encoder` | Encoder | Can create and edit senior profiles and surveys, run ML inference, manage recommendations |
| `viewer` | Viewer | Read-only access to dashboard, senior profiles, reports, and recommendations |

### Permission matrix

| Capability | admin | encoder | viewer |
|---|---|---|---|
| Dashboard, all reports, recommendations (view) | ✅ | ✅ | ✅ |
| View senior profiles and survey results | ✅ | ✅ | ✅ |
| Create and edit senior profiles | ✅ | ✅ | ❌ |
| Create and edit QoL surveys | ✅ | ✅ | ❌ |
| Assign / update recommendation status | ✅ | ✅ | ❌ |
| Run ML batch inference and single re-analysis | ✅ | ✅ | ❌ |
| Archive (soft-delete) and restore seniors | ✅ | ❌ | ❌ |
| Permanently delete seniors | ✅ | ❌ | ❌ |
| Delete / restore QoL surveys | ✅ | ❌ | ❌ |
| Activity audit log | ✅ | ❌ | ❌ |
| Export registry, cluster, and risk CSVs | ✅ | ❌ | ❌ |
| Take cluster snapshots | ✅ | ❌ | ❌ |
| User account management | ✅ | ❌ | ❌ |

### Default accounts (created by `UserSeeder`)

Three accounts are seeded automatically during setup. Passwords must be changed after first login.

| Role | Email | Initial password |
|---|---|---|
| Administrator | `admin@osca.local` | `Admin@OSCA2026!` |
| Encoder | `encoder@osca.local` | `Encoder@OSCA2026!` |
| Viewer | `viewer@osca.local` | `Viewer@OSCA2026!` |

### User management (`/users`) — admin only

Administrators can manage all system accounts at `/users` (sidebar: **Administration → User Management**):

- **List accounts** — shows name, email, role badge, creation date
- **Create account** — name, email, role, password (minimum 8 characters)
- **Edit account** — change name, email, role, or reset password (leave blank to keep)
- **Delete account** — permanently removes the account (cannot delete your own)

**Implementation:** `app/Http/Controllers/UserManagementController.php` · `routes/users.php` · `resources/views/users/`

### Role middleware

The `role` middleware alias is defined in `bootstrap/app.php` and maps to `App\Http\Middleware\RoleMiddleware`. Routes use it as:

```php
Route::middleware('role:admin')->group(fn() => ...);
Route::middleware('role:admin,encoder')->group(fn() => ...);
```

Blade directives `@role`, `@hasanyrole`, and `@endrole` / `@endhasanyrole` are provided by Spatie and are used in the sidebar to conditionally render navigation items per role.

---

## 5. Data Handled by the System

### Senior Citizen Profile (`senior_citizens` table)

Core identifying and socioeconomic fields:

| Category | Fields |
|---|---|
| Identity | `osca_id`, `first_name`, `middle_name`, `last_name`, `name_extension`, `date_of_birth`, `gender`, `marital_status`, `contact_number`, `place_of_birth`, `religion`, `ethnic_origin`, `blood_type`, `philsys_id` |
| Location | `barangay` (one of 16 Pagsanjan barangays) |
| Family | `num_children`, `num_working_children`, `child_financial_support`, `spouse_working`, `household_size` |
| Education | `educational_attainment`, `specialization` (JSON array), `community_service` (JSON array) |
| Household | `living_with` (JSON array), `household_condition` (JSON array) |
| Economic | `income_source` (JSON array), `real_assets` (JSON array), `movable_assets` (JSON array), `monthly_income_range`, `problems_needs` (JSON array) |
| Health | `medical_concern` (JSON array), `dental_concern` (JSON array), `optical_concern` (JSON array), `hearing_concern` (JSON array), `social_emotional_concern` (JSON array), `healthcare_difficulty` (JSON array), `has_medical_checkup` (bool), `checkup_schedule` |
| Admin | `status` (active), `encoded_by`, `deleted_at` (soft delete), timestamps |

### QoL Survey (`qol_surveys` table)

- 32 survey item responses (`a1`–`h2`), stored as `tinyInteger` (1–5)
- 8 computed domain scores and 1 overall score (decimal 0–1)
- `survey_date`, `survey_version`, `status` (draft / submitted / processed)

### ML Results (`ml_results` table)

- Cluster: `cluster_id` (0-indexed), `cluster_named_id` (1–4), `cluster_name`
- Risk scores (decimal 0–1): `ic_risk`, `env_risk`, `func_risk`, `composite_risk`, `wellbeing_score`
- Risk levels: `ic_risk_level`, `env_risk_level`, `func_risk_level` (LOW/MODERATE/HIGH), `overall_risk_level` (LOW/MODERATE/HIGH), `priority_flag` (maintenance/planned_monitoring/priority_action/urgent)
- `section_scores` (JSON): 6 composite section indices from preprocessing
- `raw_output` (JSON): full Python service output, including status and mode tags
- `model_version`, `processed_at`

### Recommendations (`recommendations` table)

- Priority, type, domain, category, action text, urgency, status, risk level, notes, target date, assigned user

### Analytics Tables

- `cluster_snapshots` — populated by `osca:snapshot-clusters` (scheduled daily; on-demand button on cluster report)
- `activity_logs` — populated by `ActivityLogObserver` on Senior, Survey, and Recommendation models; viewable at `/activity-log`

---

## 6. Import and Export Features

### CSV Import — `OscaCsvSeeder`

**Location:** `database/seeders/OscaCsvSeeder.php`

Reads a CSV file (`osca.csv`, located one level above the project root at `../osca.csv`) with standardized column headers mapping to all senior profile and QoL survey fields. This file is gitignored and never committed — it is placed locally only on the machine that performs seeding. For each row:
1. Creates a `SeniorCitizen` record
2. Creates a `QolSurvey` record with all responses
3. Calls `QolSurvey::computeScores()` to calculate domain scores
4. Runs `MlService::runPipeline()` to generate cluster, risk scores, and recommendations

The seeder gracefully handles null/NaN values and normalizes data types (dates, booleans, integer scores, multi-select arrays).

**Run with:** `php artisan db:seed` (requires `osca.csv` at `../osca.csv` and prediction CSVs in `python/models/predictions/` — both gitignored, never committed)

### CSV Export — Cluster Report

**Location:** `ReportController::clusterExport()` → `GET /reports/cluster/export`

Streams a downloadable CSV with the following columns:

```
OSCA ID, Name, Barangay, Age, Gender, Cluster ID, Cluster Name,
Overall Risk Level, IC Risk, ENV Risk, Func Risk, Composite Risk,
Wellbeing Score, Processed At
```

### CSV Export — Risk Report

**Location:** `ReportController::riskExport()` → `GET /reports/risk/export`

Streams a downloadable CSV with:

```
OSCA ID, Name, Barangay, Age, Overall Risk Level, Composite Risk,
IC Risk Level, ENV Risk Level, Func Risk Level, Processed At
```

### PDF Export — Individual Senior Profile

**Location:** `SeniorCitizenController::export()` → `GET /seniors/{senior}/export`

Generates a PDF document using `barryvdh/laravel-dompdf` from the template `resources/views/seniors/pdf.blade.php`. Includes full senior profile data and the latest ML results.

---

## 7. Reporting and Analytics Features

### Dashboard Analytics

All dashboard data is computed in `MainDashboard.php` and filtered in real time by barangay and risk level:

- **Risk distribution** by level (HIGH, MODERATE, LOW) with urgent sub-count for HIGH seniors
- **Cluster distribution** (Cluster 1, 2, 3, 4) via `ClusterAnalyticsService`
- **WHO domain scores** — population mean for 8 QoL domains
- **Age group distribution** — six age brackets from 60–64 to 85+
- **Barangay breakdown** — per-barangay total and HIGH risk counts
- **Pending recommendations** — sorted by urgency

### Cluster Analysis

- Per-cluster summary: member count, average IC/ENV/FUNC risks, average composite risk, average wellbeing
- WHO domain risk comparison across clusters (grouped bar chart)
- QoL domain scores comparison across clusters (radar chart)
- **Cluster evaluation metrics** (read from `python/models/cluster_eval_metrics.json` — updates automatically when model is retrained):
  - Silhouette Score
  - Davies-Bouldin Index
  - Calinski-Harabász Index
  - K chosen
- Barangay × Cluster distribution

### Risk Analysis

- Population-level risk level counts and percentages
- Domain-level risk averages (IC, Environment, Functional)
- Barangay × risk level breakdown
- At-risk senior list with sortable risk scores
- Pending recommendation counts by category (health, financial, social, functional, hc_access)

---

## 8. Machine Learning and Clustering

### Preprocessing Pipeline — `python/services/preprocess_service.py`

The preprocessing service transforms raw senior profile and QoL survey data into a structured feature vector suitable for the K-Means clustering model.

#### Feature engineering stages

1. **Demographic encoding:** Age-based risk (continuous), ordinal encoding of education and income range, binary/nominal encoding of gender, marital status

2. **Household and family features:** Household size, children count, working children, support indicators

3. **Multi-select weighted scoring:**
   - Income sources scored by financial stability weight (pension: 1.0 → dependent on others: 0.30)
   - Real assets scored by economic value (house: 1.0 → lot: 0.60)
   - Movable assets (automobile: 1.0 → bicycle: 0.25)
   - Community service engagement score
   - Specialization / skills score
   - Living arrangement risk (living alone indicator, household member count)
   - Household condition risk (informal settler: 1.0 → government housing: 0.20)

4. **QoL feature normalization:** All 31 QoL items normalized; reverse-scored items transformed (b2, b3, c3, d4)

5. **Six composite section scores:**

| Score | Description |
|---|---|
| `sec1_age_risk` | Age-based risk index (linear thresholds: <70 → 0.20, <80 → 0.50, 80+ → 0.85) |
| `sec2_family_support` | Family and household support buffer |
| `sec3_hr_score` | Human resource capability (education + skills + community) |
| `sec4_dependency_risk` | Dependency and living condition risk |
| `sec5_eco_stability` | Economic stability from income and assets |
| `sec6_health_score` | Health functioning from physical/psychological QoL + checkup status |

6. **Seven rule-based domain risk scores (0–1):**

| Risk Score | Components |
|---|---|
| `risk_medical` | Weighted severity score across all listed medical conditions |
| `risk_financial` | Income instability + asset scarcity − pension bonus |
| `risk_social` | Living alone indicator + social support gaps |
| `risk_functional` | Mobility + independence QoL items |
| `risk_housing` | Household condition + home/neighborhood safety |
| `risk_hc_access` | Healthcare cost barriers + transport + checkup frequency + service access |
| `risk_sensory` | Vision + hearing impairment combined |

7. **WHO domain scores (4 composite scores):**

| Score | WHO Domain | Components |
|---|---|---|
| `ic_score` | Intrinsic Capacity | Physical health + psychological well-being + functional ability |
| `env_score` | Environment | Financial resources + housing + community + social relationships |
| `func_score` | Functional Ability | Activities of daily living + mobility + autonomy |
| `qol_score` | Quality of Life | Overall life enjoyment + meaningfulness + spirituality |

8. **Feature scaling and dimensionality reduction:** Features are scaled using a fitted `StandardScaler`. UMAP reduces the feature space to 10 dimensions for K-Means input (skipped in batch mode via `OSCA_BATCH_MODE=1` to avoid per-item cold-start overhead).

### Clustering — `python/services/inference_service.py`

- **Algorithm:** K-Means (K=4, n_init=100), trained in `osca5.ipynb` on the full OSCA Pagsanjan senior citizen dataset (360 seniors). **Live inference does not call UMAP+KMeans per senior** — it uses a **KNN classifier (k=5, euclidean, MinMaxScaler·30-feature, CV accuracy 0.9333)** (`cluster_assignment_knn_k5.pkl`) for cross-device reproducibility. `cluster_centroids_scaled.json` (30D scaled-space centroids) is the fallback. See [ML_PIPELINE.md](ML_PIPELINE.md).
- **Input (training):** UMAP-reduced 10-dimensional feature vector (nn=10, euclidean)
- **Output:** Named cluster ID 1–4 directly from KNN (no post-hoc remapping). Raw KMeans ID → named ID mapping in `cluster_mapping.json` = `{"0":2,"1":4,"2":3,"3":1}`:

| Cluster | Named ID | Profile |
|---|---|---|
| 0 (raw) | 1 | High Functioning / Well-Supported — low overall risk, independent, good QoL |
| 1 (raw) | 2 | Stable Ageing / Moderate Support Needs — moderate risk across one or more domains |
| 2 (raw) | 3 | Environmentally & Financially Vulnerable — functionally capable but financial/housing stress |
| 3 (raw) | 4 | Low Functioning / Multi-Domain Priority — high risk across multiple domains |

- **Evaluation metrics** (loaded from `python/models/cluster_eval_metrics.json` / `cluster_assignment_metadata.json` — update automatically when the model is retrained):
  - Silhouette Score: **0.5577** (K=4, 30-feature MinMaxScaler ablated set)
  - Davies-Bouldin Index: **0.6492**
  - Calinski-Harabász Index: **6048.7**
  - KNN CV accuracy: **0.9333** (5-fold stratified, predicts named IDs 1–4 directly)

---

## 9. Risk Classification

### Risk Levels

The system classifies risk across three official levels. Urgency within HIGH is expressed via `priority_flag` (applied in `inference_service.py`):

| Risk Level | Score Range | Priority Flag | Meaning |
|---|---|---|---|
| **HIGH** (Urgent) | ≥ 0.70 | `urgent` | Requires immediate intervention |
| **HIGH** | 0.50 – 0.69 | `priority_action` | Requires targeted intervention |
| **MODERATE** | 0.30 – 0.49 | `planned_monitoring` | Requires monitoring and preventive action |
| **LOW** | < 0.30 | `maintenance` | Generally functioning well; maintain current state |

The **live app displays 3 levels** (LOW/MODERATE/HIGH). Seniors with composite ≥ 0.70 are flagged `priority_flag='urgent'` — surfaced as "High Risk + Urgent" in the dashboard (orange ring + warning icon). The notebook/reports use a 4-level analytical scheme (CRITICAL ≥0.70 = urgent-review flag, *not a clinical diagnosis*); that CRITICAL band is folded to HIGH at the live-app ingest boundary.

### Risk Scores Computed

| Score | Description |
|---|---|
| `ic_risk` | Intrinsic Capacity risk — physical, psychological, and sensory health deficits |
| `env_risk` | Environment risk — financial, housing, social, and healthcare access deficits |
| `func_risk` | Functional Ability risk — mobility, independence, and daily living deficits |
| `composite_risk` | Weighted combination of IC, ENV, and FUNC risks |
| `wellbeing_score` | Inverse of composite risk; represents overall well-being (higher = better) |

Each score produces an associated risk level label. The `overall_risk_level` is derived from `composite_risk`.

---

## 10. Prescriptive Recommendation System

**Location:** `python/services/inference_service.py`

The recommendation engine generates a prioritized list of actionable interventions for each senior. Recommendations are produced by five domain helper functions that each receive the model output (risk scores, risk levels, cluster assignment) and the senior's profile data, then return a list of structured recommendation dictionaries. The results from all five functions are merged, deduplicated, and sorted by priority before being persisted to the `recommendations` table.

### Recommendation Generation Functions

| Function | Category | Driven By |
|---|---|---|
| `generate_health_recs(result, profile)` | `health` | `ic_risk_level`, medical concerns list (200+ disease entries), checkup status |
| `financial_actions(result, profile)` | `financial` | `env_risk_level`, income sources, asset scores, household condition |
| `social_actions(result, profile)` | `social` | `env_risk_level`, living arrangement, social support QoL items, community engagement |
| `functional_actions(result, profile)` | `functional` | `func_risk_level`, mobility/independence QoL items, dependency section score |
| `hc_access_actions(result, profile)` | `hc_access` | `env_risk_level`, healthcare access difficulty flags, transport barriers |

### Disease-Specific Recommendations

`generate_health_recs` matches the `medical_concern` text against the `DISEASE_RULE_MAP` keyword table (22+ condition keywords, matched as case-insensitive substrings; each maps to a primary recommendation rule code plus an optional secondary code for high-cost diseases), including:

- **Coronary Heart Disease:** Cardiology referral, BP/HR monitoring, cardiac diet counseling, PhilHealth Z-Benefit enrollment
- **Diabetes Mellitus:** Endocrinology referral, blood glucose monitoring, diet counseling
- **Stroke:** Neurological assessment, rehabilitation referral, caregiver coordination
- **Dementia/Alzheimer's:** Cognitive screening, psychiatry referral, caregiver support network
- **Hypertension:** BP monitoring schedule, cardiologist referral if uncontrolled
- **Cancer:** Oncology referral, palliative care if stage III+, PhilHealth Z-Benefit

### Urgency Mapping

Urgency is assigned per recommendation based on the risk level and priority flag:

| Risk Level / Flag | Urgency |
|---|---|
| HIGH + `urgent` (≥ 0.70) | `urgent` |
| HIGH + `priority_action` | `priority_action` |
| MODERATE | `planned_monitoring` |
| LOW | `maintenance` |

### Recommendation Structure

Each recommendation record contains:

| Field | Description |
|---|---|
| `priority` | Integer rank (1 = most urgent) |
| `type` | Source type: `domain` or `general` |
| `domain` | Relevant WHO domain: `ic`, `env`, `func`, or `general` |
| `category` | Action category: `health`, `financial`, `social`, `functional`, `hc_access`, `general` |
| `action` | Plain-language description of the recommended action |
| `urgency` | Execution timeline: `immediate`, `urgent`, `planned`, `maintenance` |
| `risk_level` | Risk threshold that triggered this recommendation |

---

## 11. Admin Features

The features below are restricted to `admin` role users via the `role:admin` middleware (see §4 for the full permission matrix):

| Feature | Route | Description |
|---|---|---|
| Force delete senior | `DELETE /seniors/{id}/force-delete` | Permanently deletes the senior and all related surveys, ML results, and recommendations |
| Restore archived senior | `POST /seniors/{id}/restore` | Restores a soft-deleted senior to active status |
| Batch ML inference | `POST /ml/batch/run` | Runs the full ML pipeline on all eligible seniors in chunks of 100 |
| Start Python services | `POST /ml/start` | Executes `python/start_services.ps1` to launch the preprocessor and inference services |
| Assign recommendation | `PATCH /recommendations/{rec}/assign` | Assigns a recommendation item to a specific user for follow-up |
| Update recommendation status | `PATCH /recommendations/{rec}/status` | Moves a recommendation through its lifecycle (pending → in_progress → completed/dismissed) |

---

## 12. User-Facing Features

| Feature | Description |
|---|---|
| Dashboard | Real-time KPIs, charts, barangay table, recent seniors, urgent recommendations; filterable by barangay and risk level |
| Senior profile list | Searchable (by name or OSCA ID), filterable by barangay, risk level, and cluster; paginated (20 per page) |
| Senior profile detail | Full profile view, latest survey scores, latest ML result, 5 most recent surveys, 3 most recent ML results, recommendation list |
| Senior profile edit | 6-step form (same as create) pre-populated with existing data |
| Archives | List of soft-deleted seniors with search and barangay filter; restore or permanently delete |
| QoL survey creation | 8-step WHO-aligned survey for a specific senior; draft save supported |
| QoL survey results | Survey domain scores, ML risk assessment card, domain breakdown table, recommendations list |
| QoL survey list | All surveys across all seniors; filterable by status (draft/submitted/processed) and barangay |
| Cluster analysis report | Full cluster visualization page + interactive Livewire explorer |
| Risk report | At-risk senior table with sort, filter, and CSV export |
| Recommendations index | All seniors with recommendation counts (pending, immediate); filterable by barangay, risk level, urgency |
| Recommendations detail | Per-senior recommendation list with status management |
| ML service status | Health check display for preprocessor and inference services |
| PDF export | Individual senior profile as a printable PDF document |
| Dark mode | Toggle in sidebar footer; preference persisted in `localStorage` |

---

## 13. Terminologies Used by the System

The following table defines terms as they are used throughout the codebase, database schema, user interface, and documentation.

| Term | Meaning | Where / How It Is Used |
|---|---|---|
| **AgeSense** | The name of the system | Application title, sidebar branding |
| **OSCA** | Office of Senior Citizens Affairs — the government body responsible for managing senior citizen welfare at the local level | System name, seeder labels, PDF headers, OSCA ID generation |
| **Senior Citizen** | A Filipino citizen aged 60 years or older | Primary subject of all data operations in the system; stored in `senior_citizens` table |
| **OSCA ID** | Auto-generated unique identifier per senior in the format `PAG-YYYY-NNNN` | `senior_citizens.osca_id`; generated by `SeniorCitizen::generateOscaId()` |
| **WHO Healthy Ageing Framework** | A framework by the World Health Organization that defines healthy ageing through three interacting capacities: Intrinsic Capacity, Environment, and Functional Ability | Drives the domain structure of the preprocessing pipeline and reporting |
| **Intrinsic Capacity (IC)** | The composite of all physical and mental capacities an individual can draw upon at any given moment, as defined by the WHO | `ic_risk`, `ic_risk_level`, `ic_score` in ML results; domain label in charts and reports |
| **Environment (ENV)** | The external factors (home, community, financial resources, healthcare access, social support) that interact with an individual's intrinsic capacity | `env_risk`, `env_risk_level`, `env_score` in ML results |
| **Functional Ability (FA / FUNC)** | The health-related attributes that allow a person to do what they value; determined by IC and Environment combined | `func_risk`, `func_risk_level`, `func_score` in ML results |
| **QoL Survey** | Quality of Life survey — a 32-item instrument adapted from the WHOQOL-BREF administered to collect subjective well-being data from seniors | `qol_surveys` table; `QolSurveyForm` Livewire component |
| **WHOQOL-BREF** | World Health Organization Quality of Life — Brief version; a validated instrument for measuring quality of life across multiple domains | Basis for the QoL survey instrument used in the system |
| **Domain Score** | A normalized (0–1) aggregate score for a single QoL domain, computed from the relevant survey items | `score_physical`, `score_psychological`, etc. in `qol_surveys`; computed by `QolSurvey::computeScores()` |
| **Reverse-Scored Item** | A survey question where higher raw responses indicate worse outcomes; the item is inverted (`6 − response`) before inclusion in domain calculations | b2, b3, c3, d4 in the QoL survey; defined in `QolSurvey::REVERSE_SCORED` |
| **Feature Vector** | A numerical representation of a senior's profile and QoL responses used as input to the ML model | Produced by `preprocess_service.py`; includes 35+ features, section scores, domain risks |
| **K-Means Clustering** | An unsupervised machine learning algorithm that assigns each data point to one of K clusters based on feature similarity | Used to group seniors into 4 health-functioning clusters; trained in the notebook, applied live via deterministic nearest-centroid in `inference_service.py` |
| **K=4** | The number of clusters chosen for the K-Means model, validated through silhouette analysis | Cluster names: High Functioning / Well-Supported, Stable Ageing / Moderate Support, Environmentally & Financially Vulnerable, Low Functioning / Multi-Domain Priority |
| **Cluster** | One of four groups (Cluster 1, 2, 3, 4) that a senior is assigned to based on their feature profile | `ml_results.cluster_named_id`; displayed in badges, charts, and reports |
| **UMAP** | Uniform Manifold Approximation and Projection — a dimensionality reduction algorithm used to project the feature vector to 10 dimensions before clustering | Applied in `preprocess_service.py`; loaded from `umap_reducer.pkl` |
| **MinMaxScaler** | A scikit-learn preprocessing tool that scales each feature to [0, 1] range | Applied to the 30-feature ablated set for clustering; loaded from `scaler.pkl` |
| **VIF (Variance Inflation Factor)** | A measure used during feature selection to remove multicollinear features | Used to produce the final feature list retained in `feature_list.json` |
| **Section Score** | One of six composite indices derived from senior profile data during preprocessing, summarizing risk or strength in a particular aspect of ageing | `sec1_age_risk` through `sec6_health_score`; stored in `ml_results.section_scores` (JSON) |
| **Risk Score** | A continuous value between 0 and 1 representing the estimated risk level for a specific domain (IC, ENV, FUNC, or composite) | `ic_risk`, `env_risk`, `func_risk`, `composite_risk` in `ml_results` |
| **Composite Risk** | A weighted combination of IC, ENV, and FUNC risk scores representing overall health risk | `ml_results.composite_risk`; drives `overall_risk_level` |
| **Risk Level** | A categorical classification (LOW / MODERATE / HIGH) derived from a risk score using fixed thresholds. Urgency within HIGH is expressed via `priority_flag` | `overall_risk_level` (UPPERCASE), `ic_risk_level`, `env_risk_level`, `func_risk_level`, `priority_flag` in `ml_results` |
| **Wellbeing Score** | The inverse of the composite risk score; represents overall well-being (higher = better) | `ml_results.wellbeing_score`; displayed in cluster summary cards |
| **Recommendation** | A specific, actionable health or social intervention generated by the ML pipeline for a senior | Stored in `recommendations` table; generated by `inference_service.py` |
| **Urgency** | The execution timeline for a recommendation: immediate (within days), urgent (within weeks), planned (within months), maintenance (ongoing) | `recommendations.urgency`; drives sorting and dashboard priority list |
| **Prescriptive Recommendation** | A recommendation that not only identifies a problem but prescribes a specific action or referral to address it | All recommendations generated by the system are prescriptive in nature |
| **ML Pipeline** | The end-to-end process: data preprocessing → clustering → risk scoring → recommendation generation | Orchestrated by `MlService::runPipeline()` |
| **Preprocessing Service** | The Python Flask microservice (port 5001) responsible for feature engineering | `python/services/preprocess_service.py`; endpoint `POST /preprocess` |
| **Inference Service** | The Python Flask microservice (port 5002) responsible for clustering, risk scoring, and recommendation generation | `python/services/inference_service.py`; endpoint `POST /infer` |
| **Local ML Runner** | A Python script that runs preprocessing and/or inference as a subprocess when HTTP services are unavailable | `python/services/local_ml_runner.py`; invoked by `MlService` as subprocess |
| **PHP Heuristic Fallback** | A simplified age- and income-based risk estimator implemented in PHP; activated when Python is entirely unavailable | `MlService::fallbackPreprocess()` and `fallbackInfer()`; results tagged `status: fallback_php` |
| **Batch Inference** | Running the ML pipeline on multiple seniors simultaneously | `MlController::batchRun()`; processes 100 seniors per chunk |
| **MlResult** | A database record containing the full output of one ML pipeline execution for one senior | `ml_results` table; related to one `QolSurvey` and one `SeniorCitizen` |
| **Barangay** | A Philippine administrative subdivision equivalent to a village or neighborhood | Used throughout for geographic filtering and reporting; 16 barangays for Pagsanjan |
| **Silhouette Score** | A metric (−1 to 1) evaluating cluster quality; higher values indicate better-defined clusters | Read from `cluster_eval_metrics.json` (K=4 30-feature MinMaxScaler: **0.5577**); displayed on the Cluster Analysis report |
| **Davies-Bouldin Index** | A cluster evaluation metric; lower values indicate better separation | From `cluster_eval_metrics.json` (K=4: **0.6492**) |
| **Calinski-Harabász Index** | A cluster evaluation metric; higher values indicate denser, better-separated clusters | From `cluster_eval_metrics.json` (K=4: **6048.7**) |
| **Inertia (WCSS)** | Within-Cluster Sum of Squares — measures compactness of clusters | Displayed on cluster evaluation metrics panel |
| **Soft Delete** | A deletion strategy that marks a record as deleted without removing it from the database | Applied to `senior_citizens` via Laravel's `SoftDeletes` trait; viewable in Archives |
| **Survey Version** | A label identifying the version of the QoL instrument used | `qol_surveys.survey_version`; default `v1` |
| **Draft Survey** | A partially completed QoL survey saved for later completion | `qol_surveys.status = 'draft'`; supported by `QolSurveyForm::saveDraft()` |
| **PhilSys ID** | Philippine Identification System ID — a national ID for Filipino citizens | Optional field in senior profile; `senior_citizens.philsys_id` |
| **PhilHealth Z-Benefit** | A Philippine Health Insurance Corporation benefit package for catastrophic illnesses | Referenced in disease-specific recommendation actions (e.g., for CHD, cancer) |

---

## 14. Current Advantages

1. **End-to-end integration:** The system covers the complete workflow from data collection to ML analysis to recommendation generation within a single web application, without requiring OSCA staff to interact with external tools.

2. **WHO-grounded framework:** The profiling instrument and feature engineering pipeline are explicitly designed around the WHO Healthy Ageing three-domain model, giving the outputs direct interpretive relevance for health professionals.

3. **Three-tier ML fallback:** The system remains functional in low-resource environments where Python services cannot run, using a local subprocess or PHP heuristic to ensure every senior submission still produces a result.

4. **Actionable, prioritized outputs:** Recommendations are not generic; they are domain-specific, disease-specific, and urgency-ranked, making them directly usable by OSCA caseworkers for care planning.

5. **Real-time reactive UI:** Livewire 3 provides interactive filtering, pagination, and multi-step forms without full page reloads, improving usability on low-bandwidth connections.

6. **CSV import pipeline:** The `OscaCsvSeeder` allows bulk import of existing OSCA registry data from spreadsheets, including automatic ML pipeline execution per imported record.

7. **Comprehensive audit trail:** Activity logging is implemented via `ActivityLogObserver` on all core models, and cluster snapshots are taken daily. This provides a foundation for future longitudinal tracking.

8. **Cluster evaluation transparency:** Quantitative cluster quality metrics (Silhouette, Davies-Bouldin, Calinski-Harabász) are displayed to users on the cluster analysis page, supporting academic and administrative accountability.

---

## 15. Current Limitations

1. **No longitudinal tracking:** The `cluster_snapshots` table is populated daily via `osca:snapshot-clusters` (scheduled at 23:55) and on-demand. However, there is no dashboard panel yet to visualise how a senior's risk scores change over time — that is a Phase 4 deliverable.

2. **Single-office architecture:** The system is designed for a single OSCA office with one shared user table. Multi-tenancy (multi-office support) is a Phase 4 item.

3. **`SeniorCitizenController::store()` and `update()` are stubs:** Profile creation and editing are handled by the `ProfileSurvey` Livewire component. The corresponding controller methods simply redirect without any validation or persistence logic, which could cause confusion during maintenance.

4. **Auto-start platform limitation:** `php artisan serve` auto-starts ML services via `python/start_services.ps1` (PowerShell). A shell equivalent `python/start_services.sh` is committed for Linux/macOS, but the `ServeCommand` auto-launcher only calls the PS1 script. Linux/macOS users start services manually via `start_services.sh`.

5. **No email or notification system:** Recommendations, HIGH risk flags, and system events do not trigger any notifications. Mail is configured to log only (`MAIL_MAILER=log`).

6. **No automated ML model retraining:** The system consumes pre-trained models but provides no mechanism to retrain or update models from new data collected in the application.

---

## 16. Security and Privacy Notes

- **Authentication required:** All application routes are protected by the `auth` middleware. Unauthenticated users are redirected to `/login`.

- **CSRF protection:** All POST, PUT, PATCH, and DELETE requests use Laravel's built-in CSRF token verification.

- **Session security:** Sessions are stored in the database (`SESSION_DRIVER=database`) with a 120-minute lifetime. Session ID is regenerated on login.

- **Password hashing:** All passwords are hashed using bcrypt with 12 rounds (`BCRYPT_ROUNDS=12`).

- **Soft deletes:** Senior citizen records are soft-deleted by default, preventing accidental permanent data loss.

- **Sensitive personal data:** The `senior_citizens` table stores personally identifiable information (PII) including name, date of birth, contact number, PhilSys ID, blood type, and religion. This data is considered sensitive under the Philippine Data Privacy Act of 2012 (RA 10173). The following controls are implemented: (1) field-level encryption for `contact_number`, `place_of_birth`, and `philsys_id` (AES-256-CBC via Laravel `encrypted` cast); (2) `consent_given_at` and `consent_method` fields to record collection consent per senior; (3) `osca:purge-expired` Artisan command for data retention enforcement.

- **Role-based access control:** Implemented via `spatie/laravel-permission`. Roles `admin`, `encoder`, and `viewer` are enforced at route and UI level. Only `admin` can permanently delete records, manage users, export data, or view the audit log.

- **No token API / no public API surface:** `routes/api.php` defines no routes. The GIS data endpoints (`/api/gis/*`) are registered in `routes/web.php` inside the authenticated session group (`auth` + `role:admin,encoder,viewer`), so they are reachable only by a logged-in browser session — there is no token-based or unauthenticated API access.

- **ML service communication:** Requests between the Laravel application and the Python microservices are made over localhost HTTP without authentication tokens or TLS. This is acceptable for single-machine deployment but would require securing for network-distributed deployment.

- **Default credentials:** The seeded accounts (`admin@osca.local` / `Admin@OSCA2026!`, `encoder@osca.local` / `Encoder@OSCA2026!`, `viewer@osca.local` / `Viewer@OSCA2026!`) must be changed before production deployment.

---

## 17. Known Missing Features and TODOs

The following features are either partially implemented or explicitly absent from the current codebase:

| Feature | Status | Location / Notes |
|---|---|---|
| Role-based access control | ✅ Implemented | `spatie/laravel-permission`; roles `admin`, `encoder`, `viewer`; middleware + Blade directives |
| Cluster snapshot generation | ✅ Implemented | `osca:snapshot-clusters` command; scheduled daily at 23:55; on-demand button on cluster report |
| Activity audit logging | ✅ Implemented | `ActivityLogObserver` on Senior, Survey, Recommendation; viewable at `/activity-log` |
| User management interface | ✅ Implemented | `/users` — admin-only; create, edit, delete accounts |
| `SeniorCitizenController::store()` | Stub | Redirects without saving; profile creation uses Livewire `ProfileSurvey` |
| `SeniorCitizenController::update()` | Stub | Redirects without saving; editing uses Livewire `ProfileSurvey` |
| Email/notification system | Not implemented | `MAIL_MAILER=log`; no Notification classes or mail templates |
| Linux/macOS ML service startup | ✅ Implemented | `start_services.sh` committed alongside `start_services.ps1`; `ServeCommand` auto-launcher still calls PS1 only |
| Automated ML model retraining | Not implemented | Models are static artefacts; no retraining pipeline in the web app |
| Survey instrument versioning UI | Partially implemented | `survey_version` field exists; no UI to manage multiple versions |
| Senior citizen photo upload | Not implemented | No photo field or upload feature in the profile form |
| Export full database to Excel | ✅ Implemented | `/reports/registry/export` — xlsx with all active seniors + latest ML result; sidebar under Administration |
| GIS / interactive senior location map | ✅ Implemented | Live at `/reports/gis` with 4 visualization modes — see §18 |
| Accessibility proximity scoring | ✅ Implemented | `gis:score-proximity` writes `senior_accessibility_metrics` |
| GIS CSV export | ✅ Implemented | `/reports/gis/export` |
| `gis_proximity_score` as an ML feature | Pending | Accessibility scores are computed but not yet wired into the GBR/RFR pipeline — requires model retrain |

**Implemented in Phase 2 (May 2026):**

| Feature | Notes |
|---|---|
| Activity audit logging | `ActivityLogObserver` wired to Senior, Survey, Recommendation; viewable at `/activity-log` |
| Queued batch ML inference | `ProcessMlBatch` dispatched via `Bus::batch()`; queue worker starts automatically with `start.bat` |
| Dynamic cluster evaluation metrics | Read from `python/models/cluster_eval_metrics.json` — updates when model is retrained |
| Data Privacy Act compliance | Field encryption (contact_number, place_of_birth, philsys_id), consent fields, `osca:purge-expired` command |
| Barangay-specific report page | Full drill-down at `/reports/barangay/{brgy}` with KPIs, domain bars, cluster distribution, senior roster |

---

## 18. GIS Module

The GIS (Geographic Information System) module provides geographic visualisation of senior citizen locations and proximity analysis to essential services within Pagsanjan, Laguna. It is complete; see [GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md](GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md) for the full reference.

### 18.1 Implementation Status

| Component | Status | Notes |
|---|---|---|
| GIS fields on `senior_citizens` | ✅ Done | `latitude`, `longitude`, `location_source`, `location_accuracy`, `location_verified_at`; spatial index |
| `facilities` table | ✅ Done | Stores health centres, hospitals, pharmacies, markets, barangay halls (plus `osm_id` for OSM-imported facilities) |
| `senior_accessibility_metrics` table | ✅ Done | Links seniors to nearest facility per category (health centre, hospital, pharmacy, market, barangay hall); stores distances |
| Route-distance cache tables | ✅ Done | `senior_facility_route_distances` + `senior_facility_route_failures` |
| Pagsanjan facility seeder | ✅ Done | `PagsanjanFacilitySeeder` synchronizes the committed 155-record GeoJSON dataset into the database |
| GIS API — `/api/gis/seniors` | ✅ Done | Returns senior locations as GeoJSON FeatureCollection |
| GIS API — `/api/gis/facilities` | ✅ Done | Returns active facilities as GeoJSON FeatureCollection |
| GIS API — `/api/gis/boundary/*` | ✅ Done | Returns municipal and barangay boundary GeoJSON (from local storage files) |
| GIS API — `/api/gis/route-distance` | ✅ Done | Road-network distance/duration (OpenRouteService); throttled 60/min/user |
| GIS map view — `/reports/gis` | ✅ Done | Leaflet map with 4 visualization modes, facility overlay, risk/barangay/health-group filters, KPI + geocode-status panels |
| Privacy-safe coordinate generalisation | ✅ Done | Deterministic barangay-level points — no exact home addresses exposed |
| Bulk geocode command | ✅ Done | `php artisan gis:geocode` — privacy-safe barangay-level coords for seniors missing GPS data |
| Map coordinate picker in profile form | ❌ Removed | The Leaflet pin/boundary-validation picker was removed; `gis:geocode` is now the sole coordinate source |
| Accessibility proximity scoring | ✅ Done | `php artisan gis:score-proximity` — nearest-facility distances + 0–1 accessibility score |
| GIS CSV export | ✅ Done | Admin-only `/reports/gis/export` — lat/lng + nearest facility distances + accessibility score |
| Road-network route caching | ✅ Done | `php artisan gis:cache-route-distances` (OpenRouteService) |
| OpenStreetMap facility import | ✅ Done | `php artisan facilities:import-osm` — replaces approximate facilities with real coordinates |
| Committed facility sync | ✅ Done | `php artisan facilities:sync-geojson` — local, idempotent database sync with no external API request |
| Field GPS / geocoding documentation | ✅ Done | [gis-geocoding.md](gis-geocoding.md); manual-pin workflow covered in [GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md](GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md) |
| `gis_proximity_score` as an ML feature | ⏳ Pending | Accessibility scores computed but not yet wired into the GBR/RFR pipeline — requires model retrain |

### 18.2 Technical Stack

| Component | Technology |
|---|---|
| Map rendering | Leaflet.js (loaded via CDN in `gis.blade.php`) |
| Base tiles | OpenStreetMap (free, no API key required) |
| GIS data format | GeoJSON FeatureCollection — served by `GisApiController` |
| Senior coordinates | Stored in `senior_citizens.latitude / .longitude`; barangay centroid fallback via hash-based generalisation |
| Facility data | `facilities` table, synchronized from the committed 155-record GeoJSON via `PagsanjanFacilitySeeder` or `facilities:sync-geojson` |
| Proximity storage | `senior_accessibility_metrics` table (health centre, barangay hall, market distances) |
| Boundary files | GeoJSON files at `storage/app/gis/boundaries/` (optional; graceful fallback if missing) |

### 18.3 GIS API

**Controller:** `app/Http/Controllers/GisApiController.php`
**Routes:** registered in `routes/web.php` (authenticated session group, `role:admin,encoder,viewer`) — **not** `routes/api.php`, so browser `fetch` calls are session-authenticated.

| Endpoint | Response | Notes |
|---|---|---|
| `GET /api/gis/seniors` | GeoJSON FeatureCollection | Returns all active seniors with risk level, cluster, composite risk; uses stored coords or barangay centroid fallback |
| `GET /api/gis/facilities` | GeoJSON FeatureCollection | Returns all active facilities with name, type, barangay |
| `GET /api/gis/boundary/pagsanjan` | GeoJSON | Municipal boundary from `storage/app/gis/boundaries/pagsanjan_boundary.geojson` |
| `GET /api/gis/boundary/barangays` | GeoJSON | Barangay polygons from `storage/app/gis/boundaries/pagsanjan_barangays.geojson` (cached 24h) |
| `GET /api/gis/route-distance` | JSON | Road-network distance/duration (OpenRouteService); throttled 60/min/user; caches to `senior_facility_route_distances` |

### 18.4 Accessibility Proximity Scoring

`php artisan gis:score-proximity` computes, for each geocoded senior, the distance to the nearest facility in each category (health centre, hospital, pharmacy, market, barangay hall) and a composite **accessibility score** (0–1, higher = better access), stored in `senior_accessibility_metrics`. The score uses cached road-network distance (falling back to straight-line), with a detour guard that rejects implausible barangay-centroid routes and a 1.4× cap recalibration so rural barangays aren't scored to ~0%. The GIS page surfaces this as a percentage-style proximity indicator and drives the Accessibility Heatmap.

**Still pending — `gis_proximity_score` as an ML feature:** the accessibility score is computed and stored but is **not yet wired into the GBR/RFR preprocessing pipeline**. Integrating it as an optional model feature requires a model retrain; seniors without coordinates would continue to use the existing pipeline unchanged.

---

## 19. Suggested Future Improvements

1. **Wire `gis_proximity_score` into the ML pipeline.** The GIS module is complete (mapping, geocoding, accessibility scoring, route distances, OSM import). The one remaining GIS enhancement is feeding the computed accessibility score into the GBR/RFR preprocessing pipeline as an optional feature — this requires a model retrain. See Section 18.

2. **A future verified-coordinate workflow.** Bulk geocoding assigns privacy-safe barangay-level coordinates today. The manual pin picker was removed; a future address-line-based capture workflow could reintroduce verified per-senior coordinates, after which `gis:score-proximity` would be re-run.

3. **Model versioning and retraining pipeline.** Add a database field or config entry for the active model version, and create a retraining workflow (even if offline) that updates the artefact files and records version history in `ml_results.model_version`.

4. **Notification system.** Implement Laravel Notifications for critical risk alerts (email or SMS via Twilio), recommendation assignment notifications, and weekly analytics summaries for OSCA staff.

5. **Longitudinal risk tracking dashboard.** Build a dashboard panel showing risk score trends over time per senior and per barangay, using the `cluster_snapshots` table already being populated.

6. **Mobile-responsive field entry.** Optimise the QoL survey form and senior profile form for tablet/phone use by field workers doing in-home visits.

7. **Full Linux/macOS auto-start.** `start_services.sh` is implemented. The remaining step is to update `ServeCommand` to detect the OS and call the appropriate script automatically.

8. **Senior photo upload.** Add a photo field to the senior profile form, stored in `storage/app/public/seniors/`.

9. **Survey versioning UI.** Manage multiple QoL instrument versions; display which version was used for each survey submission.

10. **Multi-office support.** Extend the system to serve multiple OSCA offices (multi-tenancy) with separate data per municipality — a major architectural change.

---

## 20. Current System Status

AgeSense is a **pilot-ready system** with Phase 1 (Core), Phase 2 (Production Hardening), and Phase 3 (GIS Module) fully complete. All primary workflows — senior profiling, QoL survey administration, the v2.0.0 / K=4 ML pipeline, recommendation management, role-based access control, and the GIS spatial-analytics module — are implemented and operational.

The dataset comprises **360 senior citizens** (290 original + 70 Magdapio/Barangay II batch). With the current trained model, expected dashboard distribution (3-level live display): HIGH=77 (of which 2 carry `priority_flag='urgent'`), MODERATE=233, LOW=50. Notebook analytical distribution: HIGH=75, CRITICAL=2, MODERATE=233, LOW=50.

### Completed phases

| Phase | Status |
|---|---|
| Phase 1 — Core System | ✅ Complete (April 2026) |
| Phase 2 — Production Hardening | ✅ Complete (May 2026) |
| Phase 3 — GIS Module | ✅ Complete (June 2026) |
| Phase 4 — Advanced Features | 📋 Planned (June–July 2026) |

### Remaining gaps

| Priority | Gap |
|---|---|
| **Medium** | `gis_proximity_score` not yet wired into the ML pipeline as a feature — accessibility scores are computed and stored, but feeding them to the GBR/RFR models requires a model retrain |
| **Low** | No notification system — critical risk events are not automatically communicated to staff |
| **Low** | No automated/in-app model retraining pipeline — models are static committed artefacts |

**Technology maturity:** The Laravel/Livewire stack and Python ML microservices are production-grade in design. The three-tier fallback strategy for ML execution is robust and well-tested. Role-based access control (`spatie/laravel-permission`) is fully implemented with `admin`, `encoder`, and `viewer` roles enforced at route and UI level. The `setup.bat`/`start.bat` launcher workflow and committed model artefacts (`python/models/`) with notebook-validated prediction CSVs ensure reproducible results across all machines.

**Academic readiness:** The system's use of WHO Healthy Ageing framework terminology, WHOQOL-BREF-derived instrument, K-Means clustering with UMAP, interpretable domain-level risk scores, prescriptive recommendation generation, and role-differentiated access control makes it suitable as a thesis research system prototype. The documented cluster evaluation metrics and feature engineering pipeline provide sufficient methodological grounding for academic presentation.
