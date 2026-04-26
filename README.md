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
10. [Login & Default Account](#login--default-account)
11. [Project Structure](#project-structure)
12. [Key Directories Explained](#key-directories-explained)
13. [Main Modules & Features](#main-modules--features)
14. [Common Developer Commands](#common-developer-commands)
15. [Git Workflow for Collaborators](#git-workflow-for-collaborators)
16. [Troubleshooting](#troubleshooting)
17. [Notes for Future Developers](#notes-for-future-developers)

---

## Project Overview

AgeSense is a web-based analytics system developed for OSCA Pagsanjan, Laguna. It profiles senior citizens using demographic, socioeconomic, and health survey data, then runs a machine learning pipeline to:

- Assign each senior to a health-functioning cluster (K-Means, K=3)
- Compute domain-specific risk scores across Intrinsic Capacity, Environment, and Functional Ability
- Generate prescriptive, prioritized recommendations per senior
- Provide analytical dashboards and exportable reports for OSCA staff

The system is built on Laravel 11 with Livewire 3 for real-time UI, and Python microservices (Flask) for ML preprocessing and inference. A local subprocess fallback and a PHP heuristic fallback ensure the system remains operational even when Python services are unavailable.

---

## Technology Stack

| Layer | Technology |
|---|---|
| **Backend Framework** | Laravel 11 (PHP 8.2+) |
| **Reactive UI** | Livewire 3 |
| **Frontend JS** | Alpine.js 3, Chart.js 4 |
| **CSS** | Tailwind CSS 3 |
| **Build Tool** | Vite 5 |
| **Database** | MySQL 8+ (SQLite supported for development) |s
| **ML Services** | Python 3.10+, Flask 3, scikit-learn, UMAP, NumPy, pandas |
| **PDF Export** | barryvdh/laravel-dompdf |
| **CSV Export** | league/csv, maatwebsite/excel |
| **Icons** | blade-ui-kit/blade-heroicons |
| **Roles Package** | spatie/laravel-permission *(installed, not yet configured)* |
| **Debugging** | Laravel Telescope |
| **HTTP Client** | Guzzle 7 |

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
> To seed with randomly generated demo data instead, run:
> ```bash
> php artisan db:seed --class=OscaSeeder
> ```

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
> ```
> Then create the file: `touch database/database.sqlite`

### Python ML Services

```env
PYTHON_SERVICE_URL=http://127.0.0.1:5000   # Base URL (ports 5001/5002 appended automatically)
PYTHON_BINARY=python3                        # Python executable name on PATH
ML_MODELS_PATH=storage/app/ml_models        # Path to trained model artefact files
```

> `ML_MODELS_PATH` can be an absolute path. Place all trained model artefacts in this directory:
> `scaler.pkl`, `kmeans_model.pkl`, `umap_reducer.pkl`, `edu_encoder.pkl`, `income_encoder.pkl`, `feature_list.json`

### Municipality (used in PDF headers and OSCA ID generation)

```env
MUNICIPALITY_NAME="Pagsanjan"
PROVINCE_NAME="Laguna"
```

### Session, Cache, and Queue

The default configuration stores sessions, cache, and queue jobs in the database:

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
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
| `ml_results` | ML pipeline output: cluster, risk scores, risk levels |
| `recommendations` | Generated prescriptive recommendations per senior |
| `cluster_snapshots` | Historical cluster analytics snapshots *(schema only; not yet populated)* |
| `activity_logs` | Audit trail *(schema only; logging not yet implemented)* |
| `sessions`, `cache`, `jobs` | Laravel infrastructure tables |
| `telescope_entries` | Laravel Telescope debugging data |

### Seed the database

**Option A — Import from real CSV data:**

Place your `osca.csv` file one directory above the project root, then run:

```bash
php artisan db:seed
```

The CSV seeder (`database/seeders/OscaCsvSeeder.php`) maps columns such as `first_name`, `last_name`, `barangay`, `dob`, `gender`, `education`, `medical_concern`, `qol_enjoy_life`, `phy_energy`, etc. After importing each senior and their QoL survey, it automatically runs the ML pipeline to generate initial results.

**Option B — Generate random demo data:**

```bash
php artisan db:seed --class=OscaSeeder
```

Generates 60 realistic demo seniors with age-biased QoL responses and a natural distribution of risk levels.

### Reset and re-seed

```bash
php artisan migrate:fresh --seed
```

---

## Running the Application

### Development

The system includes a custom `ServeCommand` (`app/Console/Commands/ServeCommand.php`) that automatically starts the Python ML services before launching the Laravel development server:

```bash
php artisan serve
```

This will:
1. Execute `python/start_services.ps1` to launch the preprocessor on port 5001 and the inference service on port 5002 as background processes
2. Start the Laravel development server at `http://127.0.0.1:8000`

If the Python services do not respond within the timeout, the system automatically falls back to a local Python subprocess, and finally to a PHP heuristic if Python is entirely unavailable.

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

### Manual startup

If you prefer not to use the auto-start, launch the services manually from the `python/` directory with the virtual environment activated:

```bash
python services/preprocess_service.py   # Starts on port 5001
python services/inference_service.py    # Starts on port 5002
```

### Using the PowerShell start script (Windows)

```powershell
powershell -File python/start_services.ps1
```

This script kills any existing processes on ports 5001/5002, starts both services as hidden background processes, and writes logs to `storage/logs/preprocess.log` and `storage/logs/inference.log`.

### Service health check

Navigate to `/ml/status` in the browser to view the health of both services and see processing statistics. The dashboard topbar also shows live status indicators.

### Required trained model artefacts

Place the following files in `storage/app/ml_models/` (or the directory set in `ML_MODELS_PATH`):

| File | Purpose |
|---|---|
| `kmeans_model.pkl` | Trained K-Means model (K=3) |
| `scaler.pkl` | StandardScaler fitted on training features |
| `umap_reducer.pkl` | Trained UMAP reducer (10-dimensional output) |
| `edu_encoder.pkl` | Ordinal encoder for educational attainment |
| `income_encoder.pkl` | Ordinal encoder for monthly income range |
| `feature_list.json` | VIF-retained feature names expected by the scaler |

If these files are absent, the system falls back to the PHP heuristic estimator.

---

## Login & Default Account

The system uses Laravel session-based authentication. All application routes require login.

The login page automatically creates a default account when the `users` table is empty:

| Field | Value |
|---|---|
| Email | `admin@osca.local` |
| Password | `password` |

> **Change this password immediately** in any non-development environment.

Currently all authenticated users have full access to all features. A role/permission system (`spatie/laravel-permission`) is installed but not yet configured.

---

## Project Structure

```
osca-system/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── ServeCommand.php              # Overrides artisan serve to auto-start ML services
│   ├── Http/
│   │   └── Controllers/
│   │       ├── DashboardController.php
│   │       ├── MlController.php              # ML health, batch run, single inference trigger
│   │       ├── RecommendationController.php  # Recommendation listing, status, assignment
│   │       ├── ReportController.php          # Cluster + risk reports, CSV export
│   │       ├── SeniorCitizenController.php   # Senior CRUD, archive, restore, PDF export
│   │       └── SurveyController.php          # QoL survey management
│   ├── Livewire/
│   │   ├── Dashboard/
│   │   │   └── MainDashboard.php             # Real-time dashboard: KPIs, charts, filters
│   │   ├── Reports/
│   │   │   ├── ClusterAnalysis.php           # Cluster detail explorer with sort/filter
│   │   │   └── RiskReport.php                # Paginated risk table with filters
│   │   └── Surveys/
│   │       ├── ProfileSurvey.php             # 6-step senior profile creation/edit form
│   │       └── QolSurveyForm.php             # 8-step QoL survey with ML pipeline trigger
│   ├── Models/
│   │   ├── MlResult.php                      # Cluster assignment, risk scores, risk levels
│   │   ├── QolSurvey.php                     # Survey responses, domain scoring, feature export
│   │   ├── Recommendation.php                # Prescriptive action items per senior
│   │   ├── SeniorCitizen.php                 # Core profile: demographics, health, economic data
│   │   └── User.php
│   └── Services/
│       ├── ClusterAnalyticsService.php       # Reusable cluster query builders for reports
│       └── MlService.php                     # Full ML pipeline orchestration (3-tier strategy)
│
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2024_01_01_000001_create_osca_tables.php     # All 6 domain tables
│   │   ├── 2026_04_14_213910_create_telescope_entries_table.php
│   │   └── 2026_04_25_000001_convert_health_concerns_to_json.php
│   └── seeders/
│       ├── DatabaseSeeder.php                # Entry point → calls OscaCsvSeeder
│       ├── OscaCsvSeeder.php                 # Production seeder from osca.csv
│       └── OscaSeeder.php                    # Demo seeder: 60 random seniors
│
├── docs/
│   └── SYSTEM_FUNCTIONALITY.md              # Detailed system/thesis documentation
│
├── python/
│   ├── services/
│   │   ├── inference_service.py              # Flask: clustering + risk scoring + recommendations
│   │   ├── local_ml_runner.py                # Subprocess runner (preprocess/infer/combined/batch)
│   │   └── preprocess_service.py             # Flask: feature engineering (port 5001)
│   ├── venv/                                 # Python virtual environment (do not commit)
│   ├── requirements.txt                      # Python package dependencies
│   └── start_services.ps1                    # PowerShell: background-launch both services
│
├── resources/
│   ├── css/
│   │   └── app.css                           # Tailwind CSS entry point
│   ├── js/
│   │   ├── app.js                            # Alpine.js init, Chart.js defaults, OSCA utilities
│   │   └── bootstrap.js                      # Axios setup
│   └── views/
│       ├── auth/
│       │   └── login.blade.php
│       ├── components/                        # Reusable Blade UI components
│       │   ├── card.blade.php
│       │   ├── cluster-badge.blade.php
│       │   ├── kpi.blade.php
│       │   ├── profile-field.blade.php
│       │   ├── risk-badge.blade.php
│       │   └── risk-bar.blade.php
│       ├── dashboard.blade.php
│       ├── layouts/
│       │   └── app.blade.php                 # Main layout: collapsible sidebar, topbar, dark mode
│       ├── livewire/
│       │   ├── dashboard/
│       │   │   └── main-dashboard.blade.php
│       │   ├── reports/
│       │   │   ├── cluster-analysis.blade.php
│       │   │   └── risk-report.blade.php
│       │   └── surveys/
│       │       ├── profile-survey.blade.php
│       │       └── qol-survey-form.blade.php
│       ├── ml/
│       │   ├── batch.blade.php
│       │   └── status.blade.php
│       ├── recommendations/
│       │   ├── index.blade.php
│       │   └── show.blade.php
│       ├── reports/
│       │   ├── cluster.blade.php
│       │   └── risk.blade.php
│       ├── seniors/
│       │   ├── archives.blade.php
│       │   ├── create.blade.php
│       │   ├── edit.blade.php
│       │   ├── index.blade.php
│       │   ├── pdf.blade.php
│       │   └── show.blade.php
│       └── surveys/
│           └── qol/
│               ├── create.blade.php
│               ├── index.blade.php
│               └── results.blade.php
│
├── routes/
│   ├── auth.php                              # Login / logout routes
│   └── web.php                               # All application routes (auth-protected)
│
├── storage/
│   └── app/
│       └── ml_models/                        # Trained model artefacts (.pkl, .json)
│
├── .env.example
├── composer.json
├── package.json
├── vite.config.js
└── README.md
```

---

## Key Directories Explained

| Directory | Purpose |
|---|---|
| `app/Http/Controllers/` | Handles HTTP requests: data retrieval, view rendering, CSV/PDF streaming |
| `app/Livewire/` | Reactive Livewire components for real-time dashboard, multi-step forms, and filterable reports |
| `app/Models/` | Eloquent models with relationships, casts, scopes, accessors, and computed scoring methods |
| `app/Services/MlService.php` | Core ML orchestration: HTTP services → local Python subprocess → PHP heuristic fallback |
| `app/Services/ClusterAnalyticsService.php` | Reusable query helpers for cluster distribution and reporting |
| `database/migrations/` | Complete schema definitions for all application tables |
| `database/seeders/` | CSV-based production seeder and randomized demo seeder |
| `python/services/` | Flask microservices handling feature engineering, K-Means clustering, and recommendation generation |
| `python/services/local_ml_runner.py` | Subprocess entry point — activated when HTTP services are unavailable |
| `resources/views/livewire/` | Blade templates rendered and controlled by Livewire components |
| `resources/views/components/` | Reusable UI primitives: KPI cards, risk badges, cluster badges, progress bars |
| `storage/app/ml_models/` | Trained scikit-learn and UMAP model artefacts consumed by the Python services |

---

## Main Modules & Features

| Module | Description |
|---|---|
| **Senior Citizen Profiles** | 6-step Livewire form capturing demographics, family, education, household, economic, and health data. Supports create, edit, soft delete, archive, restore, and PDF export per senior. |
| **QoL Survey** | 8-step WHO-aligned Quality of Life questionnaire (32 items across 8 domains). Domain scores are computed and normalized (0–1) on submission. |
| **ML Pipeline** | Feature engineering → K-Means clustering (K=3) → Domain risk scoring → Recommendation generation. Three-tier fallback: HTTP services → local Python → PHP heuristic. |
| **Dashboard** | Real-time KPIs (total seniors, surveyed count, critical risk, pending recommendations), interactive Chart.js charts, barangay breakdown table, and recent activity panels. Filterable by barangay and risk level. |
| **Cluster Analysis Report** | Full cluster summaries, WHO domain risk comparison chart, cluster evaluation metrics (Silhouette, Davies-Bouldin, Calinski-Harabász), barangay × cluster breakdown, and an interactive member table. |
| **Risk Report** | Paginated at-risk senior list (HIGH + CRITICAL), domain-level risk averages, barangay risk breakdown, CSV export. Filterable and sortable. |
| **Recommendations** | Per-senior prioritized action list with urgency levels (immediate / urgent / planned / maintenance), status tracking, and staff assignment. |
| **ML Service Management** | Health status monitoring for preprocessor and inference services, batch inference runner (processes 100 seniors per chunk), and single-record re-analysis trigger. |
| **Archives** | Soft-deleted senior citizen records with restore and permanent-delete capability. |
| **Exports** | Cluster analysis CSV, risk report CSV, and individual senior PDF profile. |

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

# Seed with random demo data
php artisan db:seed --class=OscaSeeder

# Clear all compiled caches
php artisan optimize:clear

# Cache config, routes, and views for production
php artisan optimize

# Open interactive Tinker REPL
php artisan tinker

# List all registered routes
php artisan route:list

# ── Python ──────────────────────────────────────────────
# Install Python dependencies (run from python/ with venv active)
pip install -r requirements.txt

# Start preprocessing service manually (port 5001)
python python/services/preprocess_service.py

# Start inference service manually (port 5002)
python python/services/inference_service.py
```

---

## Git Workflow for Collaborators

### Rules

- **Never push directly to `main`.**
- **Never force-push to any shared branch.**
- All changes must go through a Pull Request reviewed by at least one other contributor before merging.
- Delete feature branches after merging.

### Branch naming convention

```
feature/short-description      # New feature or enhancement
fix/short-description           # Bug fix
chore/short-description         # Dependencies, tooling, docs, refactoring
hotfix/short-description        # Urgent fix applied to production
```

### Daily workflow

```bash
# 1. Always start from an up-to-date main
git checkout main
git pull origin main

# 2. Create a branch for your work
git checkout -b feature/your-feature-name

# 3. Commit frequently with clear messages
git add path/to/changed/files
git commit -m "feat: describe what was added"

# 4. Push your branch and open a Pull Request
git push origin feature/your-feature-name
# Then create a Pull Request on GitHub/GitLab with a short description
```

### Commit message format (Conventional Commits)

```
feat: add barangay-level risk breakdown to dashboard
fix: correct cluster chart disappearing on Livewire update
chore: update Python requirements to latest compatible versions
docs: document ML fallback strategy in README
refactor: extract cluster query helpers into ClusterAnalyticsService
```

---

## Troubleshooting

### Charts appear briefly then disappear

Caused by Livewire 3 firing `livewire:navigated` on the initial page load, which triggered a global Chart.js destroy handler in `resources/js/app.js`. Fixed by removing the handler and rewriting all chart init scripts to use `livewire:navigated` + `setTimeout` and `livewire:updated`. Rebuild assets after any JS changes: `npm run build`.

### Python services not starting

1. Verify the virtual environment exists at `python/venv/Scripts/python.exe` (Windows)
2. Check service logs at `storage/logs/preprocess.log` and `storage/logs/inference.log`
3. Ensure ports 5001 and 5002 are free: `netstat -ano | findstr ":5001 :5002"`
4. Confirm `ML_MODELS_PATH` in `.env` points to a directory containing the required `.pkl` files
5. If model files are missing, the system falls back to the PHP heuristic automatically — no error is thrown

### UMAP `WinError 10106` on Windows

The Python preprocess service sets `NUMBA_THREADING_LAYER=workqueue` and `OMP_NUM_THREADS=1` before any imports to avoid this Windows-specific threading issue. If the error persists, ensure the `venv` is clean and `numba` is installed from `requirements.txt`.

### `Class not found` or autoload errors

```bash
composer dump-autoload
```

### Sessions or cache errors on fresh install

The application uses database-backed sessions and cache. Run `php artisan migrate` to ensure all required tables exist before starting the server.

### `osca.csv not found` during seeding

`OscaCsvSeeder` expects the CSV at `../osca.csv` — one level above the project root. Either place the file there or use the demo seeder:

```bash
php artisan db:seed --class=OscaSeeder
```

### Vite manifest not found

Run `npm run build` (production) or keep `npm run dev` running (development). The layout requires compiled assets to load correctly.

### MySQL JSON column migration errors

The migration `2026_04_25_000001_convert_health_concerns_to_json.php` converts four health concern columns from `string` to `json`. Requires MySQL 5.7.8+ for native JSON column support. If using SQLite, the migration runs without issue but JSON storage is handled as text.

---

## Notes for Future Developers

- **Role-based access control:** `spatie/laravel-permission` is installed but no roles or permissions have been defined. All authenticated users currently have full system access. The recommended next step is to define at least two roles (`osca_staff` and `admin`), restricting batch ML operations, force-delete, and user management to administrators.

- **Activity logging:** The `activity_logs` table schema and the foreign key on `User` are defined in migrations, but no logging code has been implemented. Consider adding Eloquent observers on `SeniorCitizen`, `QolSurvey`, and `Recommendation` to record create/update/delete events.

- **Cluster snapshots:** The `cluster_snapshots` table exists but is never populated. A Laravel scheduled command could generate daily or weekly cluster snapshots to enable longitudinal trend tracking over time.

- **Batch inference as a queued job:** `MlController::batchRun()` uses `set_time_limit(0)` to handle large batches synchronously. For very large datasets, move this to a Laravel queued job. The `jobs` table already exists from the default migrations.

- **`store()` and `update()` controller stubs:** `SeniorCitizenController::store()` and `update()` are placeholders that redirect without persisting data. Actual profile creation and editing are handled by the `ProfileSurvey` Livewire component. The stub methods should either be removed or properly documented to avoid confusion.

- **WHOQOL-BREF instrument alignment:** The QoL survey is adapted from the WHOQOL-BREF. Scoring logic exists in two places: `app/Models/QolSurvey.php` (`computeScores()`) and `python/services/preprocess_service.py`. Any changes to the survey instrument or scoring must be coordinated across both files to keep the ML feature vector consistent.

- **ML model versioning:** When retraining models, replace artefacts in `storage/app/ml_models/` and update the `model_version` field convention in new `MlResult` records. The Python services load models at startup — restart both services after replacing artefact files.

- **Windows-only deployment assumption:** `python/start_services.ps1` is PowerShell-only. For Linux or macOS deployment, an equivalent shell script is needed. The `ServeCommand` also calls this PowerShell script directly.

- **OSCA ID format:** Auto-generated OSCA IDs follow the format `PAG-YYYY-NNNN` (e.g., `PAG-2024-0001`), where `PAG` is derived from the municipality name and `NNNN` is a zero-padded sequence per year. This logic is in `SeniorCitizen::generateOscaId()`.
