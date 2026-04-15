# OSCA Senior Citizen Profiling & QoL Analytics System
### Pagsanjan, Laguna — Office of Senior Citizens Affairs

A full-stack Laravel 11 + Python ML system for collecting senior citizen profile and quality-of-life survey data, running KMeans clustering (validated K=3), predicting WHO domain risk scores, and generating actionable recommendations.

---

## Table of Contents

1. [System Architecture](#system-architecture)
2. [Tech Stack](#tech-stack)
3. [Project Structure](#project-structure)
4. [Quick Start](#quick-start)
5. [ML Pipeline](#ml-pipeline)
6. [Modules](#modules)
7. [Database Schema](#database-schema)
8. [API Reference (Python Services)](#api-reference)
9. [Configuration](#configuration)
10. [Deployment](#deployment)

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Laravel 11 + Livewire 3                  │
│                                                              │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌───────────┐  │
│  │ Profile  │  │   QoL    │  │Dashboard │  │  Reports  │  │
│  │  Survey  │  │  Survey  │  │ (Charts) │  │ Cluster / │  │
│  │  Form    │  │  Form    │  │          │  │   Risk    │  │
│  └────┬─────┘  └────┬─────┘  └──────────┘  └───────────┘  │
│       │              │                                       │
│  ┌────▼──────────────▼──────────────────────────────────┐  │
│  │              MlService (PHP)                          │  │
│  │     Orchestrates: preprocess → infer → persist       │  │
│  └────────────┬──────────────────────────┬──────────────┘  │
└───────────────┼──────────────────────────┼─────────────────┘
                │ HTTP (JSON)               │ HTTP (JSON)
                ▼                           ▼
   ┌────────────────────┐    ┌────────────────────────┐
   │  Preprocessing     │    │   Inference Service    │
   │  Service :5001     │    │   :5002                │
   │                    │    │                        │
   │  • Ordinal encode  │    │  • KMeans K=3 cluster  │
   │  • Asset scoring   │    │  • GBR + RFR ensemble  │
   │  • QoL reverse-    │    │  • WHO domain risks    │
   │    score           │    │  • Risk stratification │
   │  • Section scores  │    │  • Recommendations     │
   │  • UMAP reduction  │    │    engine              │
   └────────────────────┘    └────────────────────────┘
                │                           │
                └───────────┬───────────────┘
                            ▼
                  ┌──────────────────┐
                  │  MySQL Database  │
                  │                  │
                  │  senior_citizens │
                  │  qol_surveys     │
                  │  ml_results      │
                  │  recommendations │
                  │  cluster_snap..  │
                  └──────────────────┘
```

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend framework | Laravel 11 |
| Reactive UI | Livewire 3 + Alpine.js |
| Styling | Tailwind CSS 3 |
| Charts | Chart.js 4 |
| Database | MySQL 8 |
| ML pipeline | Python 3.11+ |
| Clustering | scikit-learn KMeans (K=3) |
| Risk prediction | GradientBoostingRegressor + RandomForestRegressor (60/40 ensemble) |
| Dimensionality reduction | UMAP |
| Python API | Flask |
| PDF export | barryvdh/laravel-dompdf |
| Excel export | maatwebsite/excel |

---

## Project Structure

```
osca-system/
├── app/
│   ├── Http/Controllers/
│   │   ├── DashboardController.php       # Dashboard invokable
│   │   ├── SeniorCitizenController.php   # CRUD + export
│   │   ├── SurveyController.php          # Profile + QoL survey pages
│   │   ├── MlController.php              # ML status + batch run
│   │   ├── ReportController.php          # Cluster + risk reports
│   │   └── RecommendationController.php  # Recommendations management
│   │
│   ├── Livewire/
│   │   ├── Dashboard/
│   │   │   └── MainDashboard.php         # Live KPI + chart data
│   │   ├── Surveys/
│   │   │   ├── ProfileSurvey.php         # 6-step profile form
│   │   │   └── QolSurveyForm.php         # 8-section QoL form
│   │   └── Reports/
│   │       ├── ClusterAnalysis.php       # Interactive cluster table
│   │       └── RiskReport.php            # Filterable risk table
│   │
│   ├── Models/
│   │   ├── SeniorCitizen.php             # Core profile model
│   │   ├── QolSurvey.php                 # QoL survey + domain scoring
│   │   │                                 # (also contains MlResult + Recommendation)
│   │   └── SeniorCitizen.php
│   │
│   └── Services/
│       └── MlService.php                 # PHP ↔ Python HTTP bridge
│
├── database/
│   ├── migrations/
│   │   └── 2024_01_01_000001_create_osca_tables.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── OscaSeeder.php                # 60 demo senior records
│
├── python/
│   ├── services/
│   │   ├── preprocess_service.py         # Flask API :5001
│   │   └── inference_service.py          # Flask API :5002
│   ├── models/                           # .pkl model files go here
│   │   ├── kmeans_k3.pkl
│   │   ├── scaler.pkl
│   │   ├── umap_reducer.pkl
│   │   ├── gbr_ic_risk.pkl
│   │   ├── rfr_ic_risk.pkl
│   │   ├── gbr_env_risk.pkl
│   │   ├── rfr_env_risk.pkl
│   │   ├── gbr_func_risk.pkl
│   │   └── rfr_func_risk.pkl
│   ├── requirements.txt
│   └── start_services.sh
│
├── resources/
│   ├── css/app.css                       # Tailwind + custom utilities
│   ├── js/
│   │   ├── app.js                        # Alpine + Chart.js setup
│   │   └── bootstrap.js
│   └── views/
│       ├── layouts/app.blade.php         # Main shell with sidebar
│       ├── dashboard.blade.php
│       ├── seniors/                      # index, show, create, edit
│       ├── surveys/qol/                  # index, create, results
│       ├── reports/                      # cluster, risk (static + Livewire)
│       ├── recommendations/              # index, show
│       ├── ml/                           # status, batch
│       └── livewire/                     # Livewire component views
│
└── routes/web.php
```

---

## Quick Start

### 1. Laravel Setup

```bash
git clone <repo>
cd osca-system

# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Edit .env — set DB_DATABASE, DB_USERNAME, DB_PASSWORD

# Database
php artisan migrate
php artisan db:seed --class=OscaSeeder

# Build assets
npm run build
# or for development:
npm run dev
```

### 2. Python ML Services

```bash
# Install and start both services (ports 5001 + 5002)
bash python/start_services.sh

# Or individually:
cd python
python3 -m venv venv && source venv/bin/activate
pip install -r requirements.txt

# Preprocessing service (port 5001)
PREPROCESS_PORT=5001 python services/preprocess_service.py &

# Inference service (port 5002)
INFERENCE_PORT=5002 python services/inference_service.py &
```

### 3. Load ML Models

Copy trained `.pkl` files from your notebook's `osca_output/model/` directory into `storage/app/ml_models/`:

```bash
cp osca_output/model/*.pkl storage/app/ml_models/
```

Expected files:
- `kmeans_k3.pkl` — KMeans model (K=3)
- `scaler.pkl` — StandardScaler
- `umap_reducer.pkl` — UMAP reducer
- `gbr_ic_risk.pkl`, `rfr_ic_risk.pkl` — IC risk ensemble
- `gbr_env_risk.pkl`, `rfr_env_risk.pkl` — Environment risk ensemble
- `gbr_func_risk.pkl`, `rfr_func_risk.pkl` — Functional risk ensemble
- `cluster_map.pkl` — `{raw_id: named_id}` mapping dict

> **Note:** If `.pkl` files are missing, both Python services fall back gracefully to heuristic scoring using WHO domain score inversion. All features remain functional.

### 4. Start Laravel

```bash
php artisan serve
```

Visit: `http://localhost:8000`

---

## ML Pipeline

The ML pipeline runs automatically when a QoL survey is submitted:

```
1. QolSurveyForm::submitSurvey()
        │
        ├── QolSurvey::computeScores()      ← PHP: domain scores (0-1)
        │
        └── MlService::runPipeline()
                │
                ├── POST :5001/preprocess    ← Python: encode, scale, UMAP
                │        Returns: scaled_features, reduced_features,
                │                 section_scores, who_domain_scores
                │
                ├── POST :5002/infer         ← Python: KMeans + ensemble
                │        Returns: cluster, risk_scores, risk_levels,
                │                 recommendations[]
                │
                └── Persist to MySQL:
                         ml_results table
                         recommendations table
```

### Risk Level Thresholds

| Level | Composite Score | Action |
|-------|----------------|--------|
| CRITICAL | > 0.75 | Immediate intervention |
| HIGH | 0.65 – 0.75 | Urgent intervention |
| MODERATE | 0.45 – 0.65 | Planned intervention |
| LOW | < 0.45 | Maintenance care |

### Cluster Profiles (K=3)

| Cluster | Name | Profile | Typical Risk |
|---------|------|---------|-------------|
| 1 | High Functioning | Independent, financially stable, socially engaged | LOW |
| 2 | Moderate / Mixed Needs | Mixed domain performance | MODERATE |
| 3 | Low Functioning / Multi-Domain Risk | Multi-domain vulnerabilities | HIGH |

---

## Modules

### 1. Senior Record Management (`/seniors`)
- Full CRUD with soft deletes
- OSCA ID auto-generation (e.g., `SAN-2024-0001`)
- Search by name, OSCA ID, barangay, risk level
- PDF profile export

### 2. Profile Survey Form (`/seniors/create`)
- 6-step Livewire wizard:
  1. Identifying Information
  2. Family Composition
  3. Education / HR Profile
  4. Dependency Profile
  5. Economic Profile
  6. Health Profile
- Real-time validation
- Draft save support

### 3. QoL Survey Form (`/surveys/qol/create/{senior}`)
- 8-section Livewire form (30 items total)
- Sections: QoL, Physical, Psychological, Independence, Social, Environment, Financial, Spirituality
- Reverse-scored items (B2, B3, C3, D4) handled automatically
- Domain scores computed server-side on submit
- Triggers ML pipeline on submission

### 4. Preprocessing Service (Python :5001)
- Ordinal encoding (education, income levels)
- Multi-select asset/income weighted scoring
- Household risk scoring
- QoL reverse-score normalization
- Section score computation (6 sections → wellbeing score)
- Feature scaling (StandardScaler)
- UMAP dimensionality reduction

### 5. ML Inference Service (Python :5002)
- KMeans cluster assignment (K=3, validated)
- GBR + RFR ensemble risk prediction (60/40)
- WHO domain risks: IC, Environment, Functional
- Composite risk scoring
- Risk level stratification
- Recommendations generation (cluster + domain + section triggers)

### 6. Risk Scoring Service
- Composite = 0.40×IC + 0.30×Env + 0.30×Func
- Section score triggers for age 80+, housing risk, low family support
- HC access recommendation always included

### 7. Recommendation Engine
- Priority-ranked action list
- Categories: health, financial, social, functional, hc_access, general
- Urgency: immediate → urgent → planned → maintenance
- Status tracking: pending → in_progress → completed → dismissed

### 8. Dashboard (`/dashboard`)
- Live KPI cards (total seniors, surveyed, critical/high risk, pending recs)
- Risk distribution doughnut chart
- Cluster distribution chart
- WHO domain score radar
- Age group bar chart
- Barangay breakdown table
- Urgent pending recommendations panel
- ML service health indicator
- Auto-refreshes every 60 seconds

### 9. Cluster Analysis (`/reports/cluster`)
- Cluster validation metrics (Silhouette, Davies-Bouldin, Calinski-Harabasz)
- Per-cluster summary cards with WHO domain bars
- Domain risk grouped bar chart
- Risk level stacked distribution per cluster
- Interactive sortable member table with CSV export

### 10. Risk Reports (`/reports/risk`)
- Filter by risk level, barangay, cluster
- Sortable composite risk table
- Risk level summary cards (click-to-filter)
- CSV export

### 11. Recommendations Management (`/recommendations`)
- Filter by status, urgency, category, barangay
- Status update (pending → in_progress → completed)
- Senior-specific recommendation page

---

## Database Schema

```
senior_citizens          qol_surveys              ml_results
─────────────────        ─────────────────        ─────────────────
id (PK)                  id (PK)                  id (PK)
osca_id (UNIQUE)         senior_citizen_id (FK)   senior_citizen_id (FK)
first/middle/last_name   survey_date              qol_survey_id (FK)
barangay                 a1..h2 (30 items)        cluster_id / named_id
date_of_birth            score_qol..overall       cluster_name
gender / marital_status  status                   ic_risk / env_risk
educational_attainment   created_at               func_risk
monthly_income_range     updated_at               composite_risk
income_source (JSON)                              overall_risk_level
real_assets (JSON)       recommendations          section_scores (JSON)
medical_concern (JSON)   ─────────────────        processed_at
... (all OSCA fields)    id (PK)
status                   ml_result_id (FK)        cluster_snapshots
created_at               senior_citizen_id (FK)   ─────────────────
deleted_at               priority                 id (PK)
                         type / domain            snapshot_date
                         category / action        cluster_id / name
                         urgency / risk_level     member_count
                         status / notes           avg_* scores
                         target_date              barangay_distribution
```

---

## API Reference

### Preprocessing Service `:5001`

**`POST /preprocess`**
```json
Request:
{
  "age": 72,
  "gender": "Female",
  "educational_attainment": "High School Graduate",
  "monthly_income_range": "5,000 - 10,000",
  "income_source": ["Own pension"],
  "real_assets": ["House and Lot"],
  "medical_concern": ["Hypertension", "Diabetes"],
  "qol_responses": {
    "a1_enjoy_life": 4, "b2_pain_discomfort": 3, ...
  }
}

Response:
{
  "status": "success",
  "encoded_features": { "age": 72, "education_enc": 4, ... },
  "scaled_features": [0.45, -0.23, ...],
  "reduced_features": [0.32, 0.45, ...],
  "section_scores": {
    "sec1_age_risk": 0.3, "sec5_eco_stability": 0.65,
    "overall_wellbeing": 0.58
  },
  "who_domain_scores": {
    "ic_score": 3.2, "env_score": 3.5, "func_score": 3.1, "qol_score": 3.4
  }
}
```

### Inference Service `:5002`

**`POST /infer`**
```json
Request: (output from /preprocess)

Response:
{
  "status": "success",
  "cluster": { "named_id": 2, "name": "Moderate / Mixed Needs", ... },
  "risk_scores": {
    "ic_risk": 0.42, "env_risk": 0.51, "func_risk": 0.38, "composite_risk": 0.44
  },
  "risk_levels": {
    "ic": "moderate", "env": "moderate", "func": "low", "overall": "MODERATE"
  },
  "recommendations": [
    { "priority": 1, "type": "cluster", "action": "...", "urgency": "planned" },
    ...
  ]
}
```

**`POST /batch_infer`** — accepts JSON array of preprocessed records.

---

## Configuration

### `.env` Key Settings

```env
# Database
DB_CONNECTION=mysql
DB_DATABASE=osca_db

# Python ML services
PYTHON_SERVICE_URL=http://127.0.0.1:5000
ML_MODELS_PATH=storage/app/ml_models

# Municipality
MUNICIPALITY_NAME="Pagsanjan"
PROVINCE_NAME="Laguna"
```

### `config/services.php` — Add:

```php
'python' => [
    'base_url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1'),
],
```

---

## Deployment

### Production with Supervisor (Python services)

```ini
; /etc/supervisor/conf.d/osca-ml.conf
[program:osca-preprocessor]
command=/var/www/osca-system/python/venv/bin/gunicorn
        --bind 127.0.0.1:5001 --workers 2 preprocess_service:app
directory=/var/www/osca-system/python/services
environment=ML_MODELS_PATH="/var/www/osca-system/storage/app/ml_models"
autostart=true
autorestart=true

[program:osca-inference]
command=/var/www/osca-system/python/venv/bin/gunicorn
        --bind 127.0.0.1:5002 --workers 2 inference_service:app
directory=/var/www/osca-system/python/services
environment=ML_MODELS_PATH="/var/www/osca-system/storage/app/ml_models"
autostart=true
autorestart=true
```

### Laravel Production Checklist

```bash
composer install --optimize-autoloader --no-dev
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

---

## Fallback Behavior

Both Python services fail gracefully — if they are unreachable, `MlService.php` uses heuristic scoring:
- Cluster assignment based on `overall_wellbeing` section score
- Risk scores computed by inverting normalized WHO domain averages
- A warning is stored in `ml_results.raw_output.warnings[]`
- Users see a warning banner; all data is persisted normally

---

## Credits

- **Survey instrument:** Adapted WHOQOL-BREF and WHO Integrated Care for Older People (ICOPE) framework
- **ML methodology:** Notebook `osca5.ipynb` — KMeans K=3 with UMAP reduction, validated via Silhouette (0.412), Davies-Bouldin (1.198), Calinski-Harabasz (84.3)
- **Risk scoring:** WHO Healthy Ageing domains: Intrinsic Capacity, Environment, Functional Capacity
- **System built for:** OSCA Pagsanjan, Laguna — Municipal Social Welfare and Development Office
