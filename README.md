# AgeSense — OSCA Senior Citizen Profiling and Analytics System

> An explainable machine learning framework for profiling senior citizens and generating prescriptive recommendations for healthy ageing, aligned with the WHO Healthy Ageing Framework. Deployed for the Office of Senior Citizens Affairs (OSCA), Pagsanjan, Laguna.

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Technology Stack](#technology-stack)
3. [Prerequisites](#prerequisites)
4. [Installation & Setup](#installation--setup)
5. [Environment Configuration](#environment-configuration)
6. [Database Setup](#database-setup)
7. [Running the Application](#running-the-application)
8. [Running the Frontend Build](#running-the-frontend-build)
9. [Python ML Services](#python-ml-services)
10. [ML Model Artefacts](#ml-model-artefacts)
11. [Login & Default Account](#login--default-account)
12. [Project Structure](#project-structure)
13. [Key Directories Explained](#key-directories-explained)
14. [Main Modules & Features](#main-modules--features)
15. [ML Pipeline Architecture](#ml-pipeline-architecture)
16. [Common Developer Commands](#common-developer-commands)
17. [Git Workflow for Collaborators](#git-workflow-for-collaborators)
18. [Troubleshooting](#troubleshooting)
19. [Notes for Future Developers](#notes-for-future-developers)
20. [Additional Documentation](#additional-documentation)

---

## Project Overview

AgeSense is a web-based analytics system developed for OSCA Pagsanjan, Laguna. It profiles senior citizens using demographic, socioeconomic, and health survey data, then runs a machine learning pipeline to:

- Assign each senior to a health-functioning cluster (K-Means, K=3) via UMAP dimensionality reduction
- Compute domain-specific risk scores across Intrinsic Capacity (IC), Environment (ENV), and Functional Ability (FUNC) using an ensemble of Gradient Boosting and Random Forest regressors
- Generate prescriptive, prioritized recommendations driven by model output and senior profile data
- Provide analytical dashboards and exportable reports for OSCA staff

The system is built on Laravel 11 with Livewire 3 for real-time UI, and Python microservices (Flask) for ML preprocessing and inference. A three-tier fallback strategy (HTTP services → local Python subprocess → PHP heuristic) ensures the system remains operational even when Python services are unavailable.

---

## Technology Stack

| Layer | Technology |
|---|---|
| **Backend Framework** | Laravel 11 (PHP 8.2+) |
| **Reactive UI** | Livewire 3 |
| **Frontend JS** | Alpine.js 3, Chart.js 4 |
| **CSS** | Tailwind CSS 3 |
| **Build Tool** | Vite 5 |
| **Database** | MySQL 8+ (SQLite supported for development) |
| **ML Services** | Python 3.10+, Flask 3, scikit-learn, UMAP-learn, NumPy, pandas |
| **PDF Export** | barryvdh/laravel-dompdf |
| **CSV Export** | league/csv, maatwebsite/excel |
| **Icons** | blade-ui-kit/blade-heroicons |
| **Roles Package** | spatie/laravel-permission *(installed, not yet configured)* |
| **HTTP Client** | Guzzle 7 |

> **Note:** Laravel Telescope is listed as a dev dependency in `composer.json` but is excluded from auto-discovery (`dont-discover` in `composer.json`, removed from `bootstrap/providers.php`). It is not loaded on any request. Do not re-add it to `bootstrap/providers.php` unless Telescope migrations have been run first.

---

## Prerequisites

Ensure the following are installed on your machine before proceeding.

**PHP Environment:**
- PHP 8.2 or higher
- Composer 2.x
- MySQL 8.0+ (or SQLite for local development)

**Node.js Environment:**
- Node.js 18+ and npm 9+

**Python Environment (for ML pipeline):**
- Python 3.10 or higher
- `pip` (Python package manager)
- PowerShell (Windows) — required for the auto-start script `python/start_services.ps1`

---

## Installation & Setup

### 1. Clone the repository

```bash
git clone <repository-url>
cd osca-system
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Install Node.js dependencies

```bash
npm install
```

### 4. Install Python dependencies

```bash
cd python
python -m venv venv

# Activate the virtual environment:
# Windows:
venv\Scripts\activate
# macOS/Linux:
source venv/bin/activate

pip install -r requirements.txt
cd ..
```

### 5. Copy environment file and generate app key

```bash
cp .env.example .env
php artisan key:generate
```

### 6. Configure `.env`

See [Environment Configuration](#environment-configuration) below.

### 7. Run database migrations and seed

```bash
php artisan migrate
php artisan db:seed
```

> The default seeder runs `OscaCsvSeeder`, which reads from a file named `osca.csv` located **one directory above** the project root (`../osca.csv`). Place your real or demo dataset there before seeding. If the file is not found, the seeder exits with an error message and no data is imported.
>

### 8. Build the frontend assets

```bash
npm run build
```

---

## Environment Configuration

Open `.env` and configure the following sections.

### Application

```env
APP_NAME="OSCA Senior Citizen System"
APP_ENV=local           # Change to "production" when deploying
APP_DEBUG=true          # Set to false in production
APP_TIMEZONE=Asia/Manila
APP_URL=http://localhost
```

### Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=root
DB_PASSWORD=
```

> For SQLite (local development only):
> ```env
> DB_CONNECTION=sqlite
> DB_DATABASE=/absolute/path/to/database.sqlite
> ```
> Then create the file: `touch database/database.sqlite`

### Python ML Services

```env
PYTHON_SERVICE_URL=http://127.0.0.1      # Base URL (ports 5001/5002 appended automatically)
PYTHON_EXECUTABLE=                        # Leave blank to use python/venv auto-detection
ML_MODELS_PATH=storage/app/ml_models     # Path to trained model artefact files
ENABLE_NOTEBOOK_OVERRIDES=false          # Set true only when validating against notebook CSV exports
```

> `ML_MODELS_PATH` can be an absolute path. The Python services resolve it at startup and fall back through three candidate directories if not set. See [ML Model Artefacts](#ml-model-artefacts) for the full list of required files.

### Municipality (used in PDF headers and OSCA ID generation)

```env
MUNICIPALITY_NAME="Pagsanjan"
PROVINCE_NAME="Laguna"
```

### Session, Cache, and Queue

```env
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

---

## Database Setup

### Create the database (MySQL)

```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Run all migrations

```bash
php artisan migrate
```

This creates the following tables:

| Table | Description |
|---|---|
| `users` | Application accounts |
| `senior_citizens` | Senior citizen profiles (soft deletes enabled) |
| `qol_surveys` | QoL survey responses and computed domain scores |
| `ml_results` | ML pipeline output: cluster, risk scores, risk levels, WHO domain scores |
| `recommendations` | Generated prescriptive recommendations per senior |
| `cluster_snapshots` | Historical cluster analytics snapshots *(schema only; not yet populated)* |
| `activity_logs` | Audit trail *(schema only; logging not yet implemented)* |
| `sessions`, `cache`, `jobs` | Laravel infrastructure tables |

> **Note:** The `telescope_entries` migration exists in the project but Telescope is disabled. You do not need to run it and it will not cause errors if skipped.

### Seed the database

**Option A — Import from real CSV data:**

Place your `osca.csv` file one directory above the project root, then run:

```bash
php artisan db:seed
```

The CSV seeder (`database/seeders/OscaCsvSeeder.php`) maps columns including `first_name`, `last_name`, `barangay`, `dob`, `gender`, `education`, `medical_concern`, `qol_enjoy_life`, `phy_energy`, etc. After importing each senior and their QoL survey, it automatically runs the ML pipeline to generate initial results.

### Reset and re-seed

```bash
php artisan migrate:fresh --seed
```

---

## Running the Application

### Development

The system includes a custom `ServeCommand` that automatically starts the Python ML services before launching the Laravel development server:

```bash
php artisan serve
```

This will:
1. Execute `python/start_services.ps1` to launch the preprocessor on port 5001 and the inference service on port 5002 as background processes
2. Start the Laravel development server at `http://127.0.0.1:8000`

If the Python services do not respond within the configured timeout, the system automatically falls back to a local Python subprocess (one process for both preprocess and infer), and finally to a PHP heuristic if Python is entirely unavailable.

### Production

Use a production web server (Nginx or Apache with PHP-FPM). After deployment, run:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

A minimal Nginx configuration:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/osca-system/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Running the Frontend Build

### Development (hot module reload)

```bash
npm run dev
```

Keep this running alongside `php artisan serve` during local development.

### Production build

```bash
npm run build
```

Compiled assets are output to `public/build/`. The `@vite()` directive in the layout automatically references the correct built files.

---

## Python ML Services

### How the pipeline works

Each ML run passes through two Python services in sequence:

1. **Preprocessor** (`preprocess_service.py`, port 5001) — Encodes categorical features, extracts 31 WHO QoL features, computes 6 section scores, computes 4 WHO domain scores (IC, ENV, FUNC, QoL), runs the rule-based risk engine, scales features with `StandardScaler`, and reduces dimensions with UMAP (10-D output).

2. **Inference** (`inference_service.py`, port 5002) — Runs KMeans clustering on the UMAP-reduced features, runs the GBR+RFR risk ensemble for IC / ENV / FUNC / composite risk scores, applies WHO-aligned risk thresholds to produce risk levels, and generates prescriptive recommendations from the model output and senior profile data.

For batches, a single Python subprocess handles all seniors in one process: UMAP and KMeans are run once across the entire batch (not per-senior), which significantly reduces cold-start overhead.

### Starting the services

**Automatic (recommended):** Just run `php artisan serve` — the custom `ServeCommand` starts both services automatically via PowerShell.

**Manual startup:**

```bash
# From the python/ directory with venv activated:
python services/preprocess_service.py   # Starts on port 5001
python services/inference_service.py    # Starts on port 5002
```

**Using the PowerShell script directly:**

```powershell
powershell -File python/start_services.ps1
```

This kills any existing processes on ports 5001/5002, starts both services as hidden background processes, and writes logs to `storage/logs/preprocess.log` and `storage/logs/inference.log`.

### Service health check

Navigate to `/ml/status` in the browser to view the health of both services and see processing statistics. The dashboard topbar also shows live status indicators.

### Fallback modes

| Mode | When it activates | What runs |
|---|---|---|
| **HTTP (Flask)** | Both services are reachable | Full ML pipeline via HTTP |
| **Local subprocess** | Flask services unreachable | `local_ml_runner.py combined` — one subprocess, full ML |
| **PHP heuristic** | Python unavailable entirely | Section score averages, no cluster model |

The active mode is shown on the `/ml/status` page. Results from the PHP heuristic are tagged `status: success_fallback` and show a warning in the output.

---

## ML Model Artefacts

Place the following files in `storage/app/ml_models/` (or the directory set in `ML_MODELS_PATH`).

### Required for full ML pipeline

| File | Purpose |
|---|---|
| `kmeans.pkl` | Trained K-Means model (K=3), expects 10-D UMAP input |
| `scaler.pkl` | StandardScaler fitted on VIF-retained training features |
| `umap_nd.pkl` | Trained UMAP reducer — outputs 10 dimensions fed to KMeans |
| `edu_encoder.pkl` | Ordinal encoder for educational attainment |
| `income_encoder.pkl` | Ordinal encoder for monthly income range |
| `gbr_ic_risk.pkl` | Gradient Boosting regressor — Intrinsic Capacity risk |
| `gbr_env_risk.pkl` | Gradient Boosting regressor — Environment risk |
| `gbr_func_risk.pkl` | Gradient Boosting regressor — Functional Ability risk |
| `gbr_composite_risk.pkl` | Gradient Boosting regressor — composite overall risk |
| `rfr_ic_risk.pkl` | Random Forest regressor — Intrinsic Capacity risk |
| `rfr_env_risk.pkl` | Random Forest regressor — Environment risk |
| `rfr_func_risk.pkl` | Random Forest regressor — Functional Ability risk |
| `rfr_composite_risk.pkl` | Random Forest regressor — composite overall risk |

### Required configuration files

| File | Purpose |
|---|---|
| `feature_list.json` | Feature names expected by the scaler (must match training) |
| `vif_retained_features.json` | VIF-filtered subset of features for clustering |
| `ml_risk_features.json` | Feature names expected by the GBR/RFR risk models |
| `asset_weights.json` | Runtime-overridable scoring weights for assets, income, skills, diseases |
| `cluster_mapping.json` | Maps raw KMeans cluster IDs (0, 1, 2) to named IDs (1, 2, 3) |

### Optional configuration files

| File | Purpose |
|---|---|
| `cluster_metadata.json` | Overrides cluster names and descriptions without code changes |

### Risk ensemble weights

The inference service blends three sources using fixed weights (from notebook training):

| Source | Weight |
|---|---|
| Rule-based section scores | 45% |
| Gradient Boosting regressor | 35% |
| Random Forest regressor | 20% |

Weights renormalise proportionally if a model file is unavailable.

### Risk level thresholds

| Level | Composite risk score | Priority flag |
|---|---|---|
| HIGH (Urgent) | ≥ 0.70 | `urgent` |
| HIGH | 0.50 – 0.69 | `priority_action` |
| MODERATE | 0.30 – 0.49 | `planned_monitoring` |
| LOW | < 0.30 | `maintenance` |

There is no CRITICAL level. Seniors with composite ≥ 0.70 are displayed as **High Risk + Urgent** in the UI via `priority_flag = urgent`.

---

## Login & Default Account

The system uses Laravel session-based authentication. All application routes require login.

A default admin account is created when seeding the database. **Change the password immediately** before use in any shared or non-development environment:

```bash
php artisan tinker
> App\Models\User::where('email','admin@osca.local')->update(['password' => bcrypt('your-new-password')]);
```

Currently all authenticated users have full access to all features. A role/permission system (`spatie/laravel-permission`) is installed but not yet configured.

---

## Project Structure

```
osca-system/
├── app/
│   ├── Console/Commands/
│   │   └── ServeCommand.php              # Overrides artisan serve to auto-start ML services
│   ├── Http/Controllers/
│   │   ├── DashboardController.php
│   │   ├── MlController.php              # ML health, batch run, single inference trigger
│   │   ├── RecommendationController.php  # Recommendation listing, status, assignment
│   │   ├── ReportController.php          # Cluster + risk reports, CSV export
│   │   ├── SeniorCitizenController.php   # Senior CRUD, archive, restore, PDF export
│   │   └── SurveyController.php          # QoL survey management
│   ├── Livewire/
│   │   ├── Dashboard/MainDashboard.php   # Real-time dashboard: KPIs, charts, filters
│   │   ├── Reports/
│   │   │   ├── ClusterAnalysis.php       # Cluster detail explorer with sort/filter
│   │   │   └── RiskReport.php            # Paginated risk table with filters
│   │   └── Surveys/
│   │       ├── ProfileSurvey.php         # 6-step senior profile creation/edit form
│   │       └── QolSurveyForm.php         # 8-step QoL survey with ML pipeline trigger
│   ├── Models/
│   │   ├── MlResult.php                  # Cluster, risk scores, risk levels, WHO scores
│   │   ├── QolSurvey.php                 # Survey responses, domain scoring, feature export
│   │   ├── Recommendation.php            # Prescriptive action items per senior
│   │   ├── SeniorCitizen.php             # Core profile: demographics, health, economic data
│   │   └── User.php
│   ├── Providers/
│   │   └── AppServiceProvider.php        # Registers custom ServeCommand singleton
│   └── Services/
│       ├── ClusterAnalyticsService.php   # Reusable cluster query builders for reports
│       └── MlService.php                 # Full ML pipeline orchestration (3-tier strategy)
│
├── bootstrap/
│   ├── app.php                           # Laravel 11 application bootstrap
│   └── providers.php                     # Registered service providers (Telescope excluded)
│
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_osca_tables.php
│   │   ├── 2026_04_25_000001_convert_health_concerns_to_json.php
│   │   └── 2026_04_27_000001_add_domain_risks_to_ml_results.php
│   └── seeders/
│       ├── DatabaseSeeder.php            # Entry point → calls OscaCsvSeeder
│       └── OscaCsvSeeder.php             # Seeder: imports from osca.csv + runs ML pipeline
│
├── docs/
│   ├── GIT_WORKFLOW.md                  # Step-by-step guide: clone, branch, commit, PR
│   ├── ML_PIPELINE.md                   # ML architecture, data flow, model details
│   └── SYSTEM_FUNCTIONALITY.md          # Detailed system / thesis documentation
│
├── python/
│   ├── services/
│   │   ├── inference_service.py          # Flask: clustering + risk scoring + recommendations
│   │   ├── local_ml_runner.py            # Subprocess runner (preprocess/infer/combined/batch)
│   │   └── preprocess_service.py         # Flask: feature engineering (port 5001)
│   ├── tests/
│   │   └── test_ml_pipeline.py           # Integration tests for the full ML pipeline
│   ├── venv/                             # Python virtual environment (do not commit)
│   ├── requirements.txt                  # Python package dependencies
│   └── start_services.ps1               # PowerShell: background-launch both services
│
├── resources/
│   ├── css/app.css                       # Tailwind CSS entry point
│   ├── js/
│   │   ├── app.js                        # Alpine.js init, Chart.js defaults, OSCA utilities
│   │   └── bootstrap.js                  # Axios setup
│   └── views/
│       ├── auth/login.blade.php
│       ├── components/                    # Reusable Blade UI components
│       ├── layouts/app.blade.php         # Main layout: collapsible sidebar, topbar, dark mode
│       ├── livewire/                      # Blade templates for Livewire components
│       ├── ml/                            # batch.blade.php, status.blade.php
│       ├── recommendations/               # index.blade.php, show.blade.php
│       ├── reports/                       # cluster.blade.php, risk.blade.php
│       ├── seniors/                       # CRUD views + PDF template
│       └── surveys/qol/                   # QoL survey create/view/results
│
├── routes/
│   ├── web.php                           # Root route loader
│   ├── auth.php                          # Login / logout
│   ├── seniors.php                       # Senior CRUD routes
│   ├── surveys.php                       # QoL survey routes
│   ├── ml.php                            # ML status, batch, single run
│   ├── reports.php                       # Cluster and risk report routes
│   └── recommendations.php               # Recommendation routes
│
├── storage/app/ml_models/               # Trained model artefacts (.pkl, .json)
│
├── .env.example
├── bootstrap/providers.php              # Service provider registry
├── composer.json
├── package.json
└── vite.config.js
```

---

## Key Directories Explained

| Directory | Purpose |
|---|---|
| `app/Http/Controllers/` | HTTP request handlers: data retrieval, view rendering, CSV/PDF streaming |
| `app/Livewire/` | Reactive components for real-time dashboard, multi-step forms, and filterable reports |
| `app/Models/` | Eloquent models with relationships, casts, scopes, accessors, and scoring methods |
| `app/Services/MlService.php` | ML orchestration: HTTP services → local Python subprocess → PHP heuristic fallback |
| `app/Services/ClusterAnalyticsService.php` | Reusable query helpers for cluster distribution and reporting |
| `bootstrap/providers.php` | Lists registered service providers. Telescope is intentionally excluded here. |
| `database/migrations/` | Schema definitions for all application tables |
| `database/seeders/` | CSV-based production seeder and randomised demo seeder |
| `python/services/` | Flask microservices for feature engineering, clustering, risk scoring, and recommendations |
| `python/services/local_ml_runner.py` | Subprocess entry point — activated when HTTP services are unavailable |
| `python/tests/test_ml_pipeline.py` | Integration tests — run before deploying new model artefacts |
| `resources/views/livewire/` | Blade templates rendered and controlled by Livewire components |
| `resources/views/components/` | Reusable UI primitives: KPI cards, risk badges, cluster badges, progress bars |
| `storage/app/ml_models/` | Trained scikit-learn and UMAP model artefacts consumed by the Python services |

---

## Main Modules & Features

| Module | Description |
|---|---|
| **Senior Citizen Profiles** | 6-step Livewire form capturing demographics, family, education, household, economic, and health data. Supports create, edit, soft delete, archive, restore, and PDF export per senior. |
| **QoL Survey** | 8-step WHO-aligned Quality of Life questionnaire (31 items across 8 domains). Domain scores are computed and normalised (0–1) on submission. |
| **ML Pipeline** | Feature engineering → UMAP reduction → K-Means clustering → GBR+RFR risk ensemble → recommendation generation. Three-tier fallback: HTTP services → local Python → PHP heuristic. |
| **Dashboard** | Real-time KPIs (total seniors, surveyed count, high-risk + urgent count, pending recommendations), interactive Chart.js charts, barangay breakdown table, and recent activity panels. Filterable by barangay and risk level. |
| **Cluster Analysis Report** | Full cluster summaries, WHO domain risk comparison chart, cluster evaluation metrics (Silhouette, Davies-Bouldin, Calinski-Harabász), barangay × cluster breakdown, and interactive member table. |
| **Risk Report** | Paginated at-risk senior list (HIGH + urgent), domain-level risk averages, barangay risk breakdown, CSV export. Filterable and sortable. |
| **Recommendations** | Per-senior prioritised action list driven by ML risk scores and senior profile data. Urgency levels: urgent / priority_action / planned_monitoring / maintenance. Supports status tracking and staff assignment. |
| **ML Service Management** | Health status monitoring, batch inference runner (100-senior chunks, one Python process per chunk), single-record re-analysis trigger. |
| **Archives** | Soft-deleted senior records with restore and permanent-delete capability. |
| **Exports** | Cluster analysis CSV, risk report CSV, individual senior PDF profile. |

---

## ML Pipeline Architecture

### Data flow (single senior)

```
Senior Profile + QoL Survey (Laravel)
        │
        ▼
  buildRawPayload()   ← MlService.php
        │
        ▼
  preprocess_service.py (port 5001)
  ├─ Encode: education, income, gender, marital status
  ├─ Multi-select scoring: assets, income sources, community, skills, diseases
  ├─ Extract 31 WHO QoL features
  ├─ Compute 6 section scores (age, family, HR, dependency, economic, health)
  ├─ Compute 4 WHO domain scores (IC, ENV, FUNC, QoL)
  ├─ Rule-based risk engine (7 domain risks + composite)
  ├─ StandardScaler (scaler.pkl)
  └─ UMAP 10-D reduction (umap_nd.pkl)
        │
        ▼
  inference_service.py (port 5002)
  ├─ KMeans clustering (kmeans.pkl) → cluster 1 / 2 / 3
  ├─ GBR + RFR ensemble risk scoring
  │   ├─ IC risk  (gbr_ic_risk.pkl + rfr_ic_risk.pkl)
  │   ├─ ENV risk (gbr_env_risk.pkl + rfr_env_risk.pkl)
  │   ├─ FUNC risk (gbr_func_risk.pkl + rfr_func_risk.pkl)
  │   └─ Composite risk (gbr_composite_risk.pkl + rfr_composite_risk.pkl)
  ├─ Risk level classification (HIGH / MODERATE / LOW + priority_flag for urgency)
  └─ Prescriptive recommendation generation
        │
        ▼
  persistResults()   ← MlService.php
  ├─ MlResult (cluster, scores, levels, WHO scores, domain risks)
  └─ Recommendation rows (priority, domain, action, urgency)
```

### Batch flow optimisation

When running batch analysis, the pipeline runs differently to avoid per-senior UMAP cold-start overhead:

1. All seniors are preprocessed in a single subprocess with `OSCA_BATCH_MODE=1` (UMAP skipped per senior)
2. One-shot `scaler → UMAP → KMeans` pass across the entire batch in `batch_cluster_assign()`
3. Each senior's pre-computed cluster ID is injected before individual `infer()` calls

This means for 178 seniors, UMAP runs once (not 178 times).

### Recommendation generation

Recommendations are generated entirely from model output and senior profile data — not from static lookup tables. Five domain functions produce actions:

| Function | Driven by |
|---|---|
| `generate_health_recs()` | Medical, dental, optical, hearing, social-emotional concern fields |
| `financial_actions()` | `income_enc` from encoder, `sec5_eco_stability` from section scoring |
| `social_actions()` | `lives_alone`, `soc_social_support`, `sec2_family_support` features |
| `functional_actions()` | `phy_mobility_outside/indoor`, `func_independence`, `age`, `checkup_enc` |
| `hc_access_actions()` | `healthcare_difficulty` text, `env_service_access`, `sec5_movable_asset_score` |

Urgency is set from the overall risk level: urgent (≥ 0.70) → urgent, HIGH → priority_action, MODERATE → planned_monitoring, LOW → maintenance.

---

## Common Developer Commands

```bash
# Start development server (auto-starts Python ML services on Windows)
php artisan serve

# Start Vite dev server with hot module reload
npm run dev

# Build frontend assets for production
npm run build

# Run all pending migrations
php artisan migrate

# Reset database and re-run all migrations
php artisan migrate:fresh

# Reset, migrate, and seed
php artisan migrate:fresh --seed

# Seed from osca.csv (requires file at ../osca.csv)
php artisan db:seed

# Clear all compiled caches
php artisan optimize:clear

# Cache config, routes, and views for production
php artisan optimize

# List all registered routes
php artisan route:list

# ── Python ──────────────────────────────────────────────
# Install Python dependencies (run from python/ with venv active)
pip install -r requirements.txt

# Start preprocessing service manually (port 5001)
python python/services/preprocess_service.py

# Start inference service manually (port 5002)
python python/services/inference_service.py

# Run ML pipeline integration tests
cd python && venv\Scripts\activate && python tests/test_ml_pipeline.py

# Run a single batch inference test from the command line
echo '[{"age":72,"gender":"Female",...}]' | python python/services/local_ml_runner.py batch
```

---

## Git Workflow for Collaborators

> For the full step-by-step guide — including cloning, branch naming, commit format, PR creation, and common mistake fixes — see **[docs/GIT_WORKFLOW.md](docs/GIT_WORKFLOW.md)**.

### Core rules (read these even if you skip the guide)

- **Never push directly to `main`.** The branch is protected — direct pushes are blocked.
- **Never force-push to a shared branch.**
- All changes go through a Pull Request with at least one approval before merging.
- Squash merge only — one commit per PR lands on `main`.
- Delete your feature branch after it is merged.

### Branch naming

```
feat/short-description      # New feature or enhancement
fix/short-description       # Bug fix
chore/short-description     # Dependencies, tooling, docs, refactoring
hotfix/short-description    # Urgent production fix
docs/short-description      # Documentation only
refactor/short-description  # Code restructuring, no behavior change
test/short-description      # Test additions or fixes
```

### Quick daily flow

```bash
# Start of every session — always pull main first
git checkout main
git pull origin main

# Create a branch for your task
git checkout -b feat/your-feature-name

# Stage specific files and commit
git add path/to/changed/file.php
git commit -m "feat: describe what was added"

# Push and open a PR on GitHub
git push -u origin feat/your-feature-name
```

### Commit message format (Conventional Commits — enforced by ruleset)

```
feat: add barangay-level risk breakdown to dashboard
fix: modal buttons invisible in dark mode due to CSS class remap
chore: update Python requirements to latest compatible versions
docs: add GIT_WORKFLOW guide for teammates
refactor: extract cluster query helpers into ClusterAnalyticsService
test: add batch KMeans validation to test_ml_pipeline.py
ci: add python-ml-tests job to GitHub Actions workflow
```

### Required CI checks (all must pass before merge)

| Check | What it validates |
|---|---|
| `ci / php-checks` | PHP syntax, migrations, PHPUnit tests, no debug statements |
| `ci / python-ml-tests` | Full ML pipeline integration tests |
| `ci / js-build` | Frontend Vite build succeeds |

---

## Troubleshooting

### Login times out with "Maximum execution time exceeded"

Caused by `TelescopeServiceProvider` being registered in `bootstrap/providers.php` while Telescope's database tables have not been migrated. **This is already fixed** — Telescope has been removed from `bootstrap/providers.php` and added to `dont-discover` in `composer.json`. If the error reappears after a `composer install`, check that `bootstrap/providers.php` does not contain `TelescopeServiceProvider::class`.

### Charts appear briefly then disappear

Caused by Livewire 3 firing `livewire:navigated` on the initial page load, which triggered a global Chart.js destroy handler. Fixed by rewriting chart init scripts to use `livewire:navigated` + `setTimeout`. Rebuild assets after any JS changes: `npm run build`.

### Python services not starting

1. Verify the virtual environment exists at `python/venv/Scripts/python.exe` (Windows)
2. Check service logs at `storage/logs/preprocess.log` and `storage/logs/inference.log`
3. Ensure ports 5001 and 5002 are free: `netstat -ano | findstr ":5001 :5002"`
4. Confirm `ML_MODELS_PATH` in `.env` points to a directory containing the required `.pkl` files
5. The system falls back automatically if model files are missing — check the `warnings` field in ML result output to confirm which fallback is active

### UMAP `WinError 10106` on Windows

The Python services set `NUMBA_THREADING_LAYER=workqueue`, `NUMBA_NUM_THREADS=1`, and `OMP_NUM_THREADS=1` before any imports. These are set in both `local_ml_runner.py` (for subprocess mode) and in `MlService::pythonEnvironment()` (for the subprocess environment). If the error persists, ensure the `venv` is clean and `numba` is installed from `requirements.txt`.

### Batch analysis seems slow

Running batch analysis with the Flask services already running (HTTP mode) is significantly faster than subprocess mode because models are already loaded in memory. Before running a batch:
1. Navigate to `/ml/status` and verify both services show `ok`
2. If not, click "Start ML Services" on that page
3. Re-run the batch

Cold-start in subprocess mode for 178 seniors takes approximately 25–40 seconds. HTTP mode reduces this to under 10 seconds.

### `Class not found` or autoload errors

```bash
composer dump-autoload
```

### Sessions or cache errors on fresh install

Run `php artisan migrate` to ensure all required tables exist before starting the server.

### `osca.csv not found` during seeding

`OscaCsvSeeder` expects the CSV at `../osca.csv` — one level above the project root. Place the file there and re-run `php artisan db:seed`.

### Vite manifest not found

Run `npm run build` (production) or keep `npm run dev` running (development).

### MySQL JSON column migration errors

The migration `2026_04_25_000001_convert_health_concerns_to_json.php` converts health concern columns to JSON. Requires MySQL 5.7.8+ for native JSON support. SQLite handles this as text without issue.

---

## Notes for Future Developers

- **Role-based access control:** `spatie/laravel-permission` is installed but no roles or permissions have been defined. All authenticated users currently have full system access. Recommended next step: define at least `osca_staff` and `admin` roles, restricting batch ML operations, force-delete, and user management to administrators.

- **Activity logging:** The `activity_logs` table schema is defined but no logging code exists. Consider adding Eloquent observers on `SeniorCitizen`, `QolSurvey`, and `Recommendation` to record create/update/delete events.

- **Cluster snapshots:** The `cluster_snapshots` table exists but is never populated. A Laravel scheduled command could generate weekly snapshots to enable longitudinal trend tracking.

- **Batch inference as a queued job:** `MlController::batchRun()` runs synchronously. For very large datasets, move this to a Laravel queued job. The `jobs` table already exists.

- **WHOQOL-BREF instrument alignment:** QoL scoring logic exists in two places: `app/Models/QolSurvey.php` (`computeScores()`) and `python/services/preprocess_service.py`. Any changes to the survey instrument or scoring must be coordinated across both files to keep the ML feature vector consistent.

- **ML model versioning:** When retraining models, replace artefacts in `storage/app/ml_models/` and restart both Flask services. The Python services load models at startup via `lru_cache` — a running service will not pick up new files without a restart. Run `python/services/test_ml_pipeline.py` after replacing artefacts to verify the pipeline end-to-end before putting into production.

- **Replacing model artefacts:** Only the files listed in [ML Model Artefacts](#ml-model-artefacts) are loaded. `umap_2d.pkl`, `hdbscan.pkl`, and `pca.pkl` were removed as they are unused experiment artefacts. Do not add them back.

- **Windows-only deployment assumption:** `python/start_services.ps1` is PowerShell-only. For Linux/macOS deployment, an equivalent shell script is needed. The `ServeCommand` also calls this script directly.

- **OSCA ID format:** Auto-generated OSCA IDs follow `PAG-YYYY-NNNN` (e.g., `PAG-2024-0001`), where `PAG` is derived from the municipality name and `NNNN` is zero-padded per year. This logic is in `SeniorCitizen::generateOscaId()`.

- **`store()` and `update()` controller stubs:** `SeniorCitizenController::store()` and `update()` are placeholders. Actual profile creation and editing are handled by the `ProfileSurvey` Livewire component. The stub methods should either be removed or clearly documented.

- **`ENABLE_NOTEBOOK_OVERRIDES`:** When set to `true` in `.env`, the inference service attempts to match each senior to a pre-exported CSV of notebook predictions (`senior_predictions.csv`) and uses those values instead of live model output. This is a validation tool only — keep it `false` in production.

---

## Additional Documentation

| Document | Description |
|---|---|
| [docs/GIT_WORKFLOW.md](docs/GIT_WORKFLOW.md) | Full teammate guide: clone, setup, branching, commits, PRs, do's and don'ts, common mistake fixes |
| [docs/ML_PIPELINE.md](docs/ML_PIPELINE.md) | ML architecture deep-dive: feature engineering, clustering, risk ensemble, recommendations, fallback strategy |
| [docs/SYSTEM_FUNCTIONALITY.md](docs/SYSTEM_FUNCTIONALITY.md) | Comprehensive system reference: all modules, data schema, capabilities, limitations, and thesis documentation |
| [.github/PULL_REQUEST_TEMPLATE.md](.github/PULL_REQUEST_TEMPLATE.md) | PR template auto-filled when opening a pull request on GitHub |
