# AgeSense — OSCA Senior Citizen Profiling and Analytics System

> Machine learning-powered senior citizen risk assessment and recommendation system for the Office of Senior Citizens Affairs (OSCA), Pagsanjan, Laguna. Aligned with the WHO Healthy Ageing Framework.

---

## Table of Contents

1. [What This System Does](#what-this-system-does)
2. [Technology Stack](#technology-stack)
3. [Prerequisites](#prerequisites)
4. [Quick Start (Recommended)](#quick-start-recommended)
5. [Manual Setup](#manual-setup)
6. [Environment Configuration](#environment-configuration)
7. [Running the System](#running-the-system)
8. [Default Login](#default-login)
9. [ML Pipeline](#ml-pipeline)
10. [Updating the ML Model](#updating-the-ml-model)
11. [Project Structure](#project-structure)
12. [Developer Commands](#developer-commands)
13. [Troubleshooting](#troubleshooting)
14. [Notes for Future Developers](#notes-for-future-developers)

---

## What This System Does

AgeSense profiles senior citizens using demographic, socioeconomic, and health survey data, then runs a machine learning pipeline to:

- **Cluster** each senior into one of three health-functioning groups (K-Means, K=3) via UMAP dimensionality reduction
- **Score** domain-specific risk across Intrinsic Capacity (IC), Environment (ENV), and Functional Ability (FUNC) using an ensemble of Gradient Boosting and Random Forest regressors
- **Classify** overall risk as HIGH / MODERATE / LOW with urgency flagging (≥ 0.70 composite = urgent)
- **Generate** prescriptive, prioritised recommendations per senior driven by model output and profile data
- **Report** cluster analytics, risk breakdowns by barangay, and exportable CSV/PDF outputs

A three-tier fallback (Flask HTTP → local Python subprocess → PHP heuristic) keeps the system operational even when Python services are unavailable.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend Framework | Laravel 11 (PHP 8.2+) |
| Reactive UI | Livewire 3 |
| Frontend JS | Alpine.js 3, Chart.js 4 |
| CSS | Tailwind CSS 3 |
| Build Tool | Vite 5 |
| Database | MySQL 8+ (SQLite supported for local dev) |
| ML Services | Python 3.10+, Flask 3, scikit-learn, UMAP-learn |
| PDF Export | barryvdh/laravel-dompdf |
| Excel/CSV Export | maatwebsite/excel, league/csv |

---

## Prerequisites

Install all of the following before running setup:

| Requirement | Version | Download |
|---|---|---|
| PHP | 8.2+ | https://windows.php.net/download/ |
| Composer | 2.x | https://getcomposer.org/download/ |
| Node.js + npm | 18+ | https://nodejs.org/ |
| Python | 3.10+ | https://www.python.org/downloads/ |
| MySQL | 8.0+ | https://dev.mysql.com/downloads/mysql/ |

> **Python installation tip:** During the Python installer, check **"Add Python to PATH"**.

> **MySQL tip:** Create the database before running setup:
> ```sql
> CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> ```

---

## Quick Start (Recommended)

After cloning the repository, use the two batch files included in the project root.

### Step 1 — First-time setup (run once)

```
Double-click  setup.bat
```

This single script handles everything:
- Installs PHP, Node.js, and Python dependencies
- Creates `.env` from `.env.example`
- Generates the application key
- Runs all database migrations
- Seeds data from `osca.csv` if the file is present
- Builds frontend assets
- Creates the Python virtual environment
- Installs all Python ML packages

**Total time: 5–15 minutes** on first run (most time is spent on Python package installation and the ML pipeline during seeding).

> **For seeding with real data:** Place `osca.csv` one folder above the project root (i.e., at `..\osca.csv`) before running `setup.bat`. If the file is absent, setup completes without data and you can add seniors manually.

### Step 2 — Run every time

```
Double-click  start.bat
```

This starts the Laravel development server and the Python ML services in the background, then opens the browser automatically.

---

## Manual Setup

If you prefer to run each step yourself:

### 1. Clone

```bash
git clone <repository-url>
cd osca-system
```

### 2. PHP dependencies

```bash
composer install
```

### 3. Node.js dependencies

```bash
npm install
```

### 4. Environment file

```bash
copy .env.example .env
php artisan key:generate
```

### 5. Configure `.env`

Edit `.env` with your database credentials. See [Environment Configuration](#environment-configuration).

### 6. Create MySQL database

```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 7. Run migrations

```bash
php artisan migrate
```

### 8. Seed data (optional)

Place `osca.csv` at `..\osca.csv` (one level above project root), then:

```bash
php artisan db:seed
```

### 9. Build frontend assets

```bash
npm run build
```

### 10. Python virtual environment

```bash
cd python
python -m venv venv

# Windows:
venv\Scripts\activate

# macOS/Linux:
source venv/bin/activate

pip install -r requirements.txt
cd ..
```

### 11. Start the system

```bash
php artisan serve
```

This auto-starts the Python ML services on ports 5001 and 5002 via `python/start_services.ps1`.

---

## Environment Configuration

After copying `.env.example` to `.env`, configure these sections:

### Database

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=root
DB_PASSWORD=
```

> **SQLite alternative (local dev only):**
> ```env
> DB_CONNECTION=sqlite
> DB_DATABASE=/absolute/path/to/database/database.sqlite
> ```

### Python ML Services

```env
# Base URL only — ports 5001 (preprocess) and 5002 (inference) are appended automatically.
# Do NOT include a port number here.
PYTHON_SERVICE_URL=http://127.0.0.1

# Path to trained model files. Uses python/models/ by default.
ML_MODELS_PATH=python/models

# When true, the inference service reads composite_risk, cluster_id, and risk_level
# directly from python/models/predictions/senior_predictions.csv (the notebook's
# validated output) instead of computing them live. This ensures consistent results
# across all machines. Keep true unless you are deliberately testing live model output.
ENABLE_NOTEBOOK_OVERRIDES=true
```

### Session, Cache, Queue (recommended for local dev)

```env
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
```

### Municipality (used in OSCA ID generation and PDF headers)

```env
MUNICIPALITY_NAME="Pagsanjan"
PROVINCE_NAME="Laguna"
```

---

## Running the System

### Start (every session)

```bash
php artisan serve
```

or double-click `start.bat`.

This starts:
- **Laravel dev server** at `http://127.0.0.1:8000`
- **Python preprocessor** at `http://127.0.0.1:5001` (background)
- **Python inference service** at `http://127.0.0.1:5002` (background)

The Python services load their models in the background (first request may take 30–60 seconds). Subsequent requests are fast.

### Frontend hot-reload (optional, during active UI development)

Run this alongside `php artisan serve` in a second terminal:

```bash
npm run dev
```

### Check ML service status

Navigate to `/ml/status` in the browser. Both services should show `ok`. If they show `unreachable`, click **Start ML Services** on that page.

---

## Default Login

When you first run the system and visit the login page, a default admin account is created automatically if no users exist:

| Field | Value |
|---|---|
| URL | http://127.0.0.1:8000 |
| Email | admin@osca.local |
| Password | password |

> **Change this password immediately** before sharing access with anyone:
> ```bash
> php artisan tinker
> App\Models\User::where('email','admin@osca.local')->update(['password' => bcrypt('your-new-password')]);
> ```

---

## ML Pipeline

### How it works (single senior)

```
Senior Profile + QoL Survey
         │
         ▼
  preprocess_service.py  (port 5001)
  ├─ Encode: education, income, gender, marital status
  ├─ Score: assets, income sources, community service, skills
  ├─ Score: medical, dental, optical, hearing, social-emotional concerns
  ├─ Compute 6 section scores (age, family, HR, dependency, economic, health)
  ├─ Compute 4 WHO domain scores (IC, ENV, FUNC, QoL)
  ├─ Rule-based risk engine (7 domain risks + composite)
  ├─ StandardScaler  →  scaler.pkl
  └─ UMAP 10-D reduction  →  umap_nd.pkl
         │
         ▼
  inference_service.py  (port 5002)
  ├─ KMeans clustering  →  kmeans.pkl  →  Group 1 / 2 / 3
  ├─ Risk ensemble (GBR + RFR) for IC / ENV / FUNC risks
  ├─ Composite risk = rule_based×0.45 + ML_ensemble×0.55
  ├─ Risk level: HIGH (≥0.50) / MODERATE (≥0.30) / LOW (<0.30)
  ├─ Urgency flag: urgent (≥0.70) / priority_action / planned_monitoring / maintenance
  └─ Prescriptive recommendations (health, financial, social, functional, hc_access)
         │
         ▼
  MlResult + Recommendation rows saved to database
```

### Batch optimisation

When running **Batch Analysis**, UMAP and KMeans run once across the entire batch (not once per senior). Models remain loaded in Flask memory for the duration. For 275 seniors, this reduces total analysis time from ~45 minutes (subprocess mode) to under 60 seconds (HTTP mode).

### Cluster groups

| Group | Name | Risk Level | Description |
|---|---|---|---|
| 1 | High Functioning | LOW | Independent, financially stable, socially engaged |
| 2 | Moderate / Mixed Needs | MODERATE | Some domains need targeted support |
| 3 | Low Functioning / Multi-domain Risk | HIGH | Multi-domain vulnerabilities, immediate intervention needed |

### Risk ensemble weights

| Source | Weight |
|---|---|
| Rule-based section scores | 45% |
| Gradient Boosting regressor | 35% |
| Random Forest regressor | 20% |

Weights renormalise proportionally if a model file is missing.

### Fallback modes

| Mode | When | What runs |
|---|---|---|
| HTTP (Flask) | Both services reachable | Full ML pipeline, models in memory |
| Local subprocess | Flask unavailable | `local_ml_runner.py` — one Python process, cold-start per run |
| PHP heuristic | Python unavailable | Section score averages only, no cluster model |

The active mode is shown on `/ml/status`.

### Model files

All trained model files are committed to the repository under `python/models/`. **No manual model placement is needed after cloning** — a fresh `git clone` + `setup.bat` is sufficient to run the system with the correct validated results.

| File | Purpose |
|---|---|
| `scaler.pkl` | StandardScaler fitted on VIF-retained training features |
| `umap_nd.pkl` | UMAP reducer — outputs 10-D features for KMeans |
| `kmeans.pkl` | K-Means model (K=3), trained on UMAP-reduced features |
| `edu_encoder.pkl` | Ordinal encoder for educational attainment |
| `income_encoder.pkl` | Ordinal encoder for monthly income range |
| `gbr_ic_risk.pkl` | Gradient Boosting — Intrinsic Capacity risk |
| `gbr_env_risk.pkl` | Gradient Boosting — Environment risk |
| `gbr_func_risk.pkl` | Gradient Boosting — Functional Ability risk |
| `rfr_ic_risk.pkl` | Random Forest — Intrinsic Capacity risk |
| `rfr_env_risk.pkl` | Random Forest — Environment risk |
| `rfr_func_risk.pkl` | Random Forest — Functional Ability risk |
| `feature_list.json` | 30 final clustering feature names |
| `ml_risk_features.json` | Feature names for GBR/RFR risk models |
| `cluster_mapping.json` | Maps raw KMeans IDs (0,1,2) → named group IDs (1,2,3) |
| `cluster_metadata.json` | Cluster names and descriptions |
| `asset_weights.json` | Scoring weights for assets, income, skills, diseases |
| `vif_retained_features.json` | VIF-filtered feature subset |
| `predictions/senior_predictions.csv` | Notebook-validated composite scores, clusters, and risk levels per senior |
| `predictions/senior_recommendations_flat.csv` | Notebook-validated recommendations per senior (flat CSV, one row per action) |

> The `predictions/` files are the source of truth for risk results. When `ENABLE_NOTEBOOK_OVERRIDES=true` (the default), the inference service reads these files directly so every device produces identical output regardless of platform, Python version, or library minor-version differences. The notebook (`osca5.ipynb`) and its output folder (`osca_output/`) are **not required** on other machines.

---

## Updating the ML Model

This workflow applies whenever you retrain the notebook (`osca5.ipynb`) and want every device to use the new model automatically.

### Do other machines need the notebook or `osca_output/`?

**No.** Only the machine that runs the notebook needs those. Other machines only need the files committed to this repository — the trained model files and prediction CSVs in `python/models/`.

### Machine roles

| Machine | Has notebook? | Has `osca_output/`? | What to do after retrain |
|---|---|---|---|
| **Training machine** (yours) | Yes | Yes — generated by notebook | Run the update workflow below |
| **Other machines** | Not needed | Not needed | `git pull` + reseed if new data |

### Update workflow (training machine only)

After retraining `osca5.ipynb` and verifying the new `osca_output/`:

**Step 1 — Sync new files into the repo:**

```bat
:: From the project root, run setup.bat — Step 11 does this automatically.
:: Or run manually:
xcopy /Y osca_output\model\*.pkl  python\models\
xcopy /Y osca_output\model\*.json python\models\
xcopy /Y osca_output\predictions\senior_predictions.csv          python\models\predictions\
xcopy /Y osca_output\predictions\senior_recommendations_flat.csv python\models\predictions\
```

**Step 2 — Validate:**

```bat
python\venv\Scripts\python python\services\_validate.py
```

All checks must pass before committing.

**Step 3 — Commit and push:**

```bash
git add python/models/
git commit -m "model: update trained files from notebook rerun YYYY-MM-DD"
git push
```

**Step 4 — Other machines pull and reseed:**

```bash
git pull
php artisan migrate:fresh --seed   # re-runs ML pipeline with the new model
```

> `migrate:fresh --seed` wipes and re-creates the database, then re-imports `osca.csv` and re-runs the ML pipeline for all seniors. The new notebook override CSVs are used automatically, so results will match the notebook exactly.

### What if I only updated the prediction CSVs (re-ran the notebook on the same data)?

Same workflow — the CSVs are what drive the dashboard results when `ENABLE_NOTEBOOK_OVERRIDES=true`. Push the updated `python/models/predictions/*.csv` files and other machines will get the correct outputs after `git pull` + reseeding.

---

## Project Structure

```
osca-system/
├── app/
│   ├── Console/Commands/
│   │   ├── RunMlSingle.php          # Artisan command: ml:run-single (background ML per senior)
│   │   └── ServeCommand.php         # Overrides artisan serve to auto-start ML services
│   ├── Http/Controllers/
│   │   ├── MlController.php         # ML health check, batch run, single-run trigger, result poll
│   │   ├── SeniorCitizenController.php
│   │   ├── SurveyController.php
│   │   ├── ReportController.php
│   │   └── RecommendationController.php
│   ├── Livewire/
│   │   ├── Dashboard/MainDashboard.php   # Real-time KPIs and charts
│   │   ├── Reports/ClusterAnalysis.php   # Cluster explorer
│   │   ├── Reports/RiskReport.php        # Paginated risk table
│   │   └── Surveys/
│   │       ├── ProfileSurvey.php         # 6-step senior profile form
│   │       └── QolSurveyForm.php         # 8-step QoL survey
│   ├── Models/
│   │   ├── MlResult.php             # Cluster, risk scores, WHO scores, priority flag
│   │   ├── QolSurvey.php            # Survey responses and domain scoring
│   │   ├── Recommendation.php       # Per-senior action items
│   │   └── SeniorCitizen.php        # Core profile model
│   └── Services/
│       ├── MlService.php            # Full ML orchestration (3-tier fallback strategy)
│       └── ClusterAnalyticsService.php
│
├── database/
│   ├── migrations/                  # All schema definitions
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── OscaCsvSeeder.php        # CSV import + ML pipeline run
│
├── docs/
│   ├── GIT_WORKFLOW.md              # Branching, commits, PR guide for collaborators
│   ├── ML_PIPELINE.md               # Full ML architecture reference
│   └── SYSTEM_FUNCTIONALITY.md     # Complete system and thesis documentation
│
├── python/
│   ├── models/                      # Trained ML model files (committed to repo)
│   │   └── predictions/             # Notebook-validated CSVs: senior_predictions.csv, senior_recommendations_flat.csv
│   ├── services/
│   │   ├── preprocess_service.py    # Flask service: feature engineering (port 5001)
│   │   ├── inference_service.py     # Flask service: clustering + risk + recommendations (port 5002)
│   │   └── local_ml_runner.py       # Subprocess runner (preprocess/infer/combined/batch modes)
│   ├── requirements.txt             # Python dependencies
│   ├── start_services.ps1           # PowerShell: launches both services as background processes
│   └── venv/                        # Virtual environment (gitignored, created by setup.bat)
│
├── resources/views/
│   ├── ml/                          # batch.blade.php, status.blade.php
│   ├── seniors/                     # CRUD views and PDF template
│   ├── surveys/                     # QoL survey views
│   ├── reports/                     # Cluster and risk report views
│   └── recommendations/
│
├── routes/
│   ├── web.php, auth.php, seniors.php, surveys.php
│   ├── ml.php                       # /ml/status, /ml/batch, /ml/run/{senior}, /ml/result/{senior}
│   ├── reports.php, recommendations.php
│
├── setup.bat                        # First-time setup (run once after cloning)
├── start.bat                        # Daily launcher (run every session)
├── .env.example                     # Environment template
├── composer.json
└── package.json
```

---

## Developer Commands

```bash
# ── Laravel ─────────────────────────────────────────────────────────
# Start dev server (auto-starts Python ML services)
php artisan serve

# Reset database, run all migrations, seed from osca.csv
php artisan migrate:fresh --seed

# Run migrations only
php artisan migrate

# Seed from osca.csv (file must be at ../osca.csv)
php artisan db:seed

# Clear all caches
php artisan optimize:clear

# Re-run ML pipeline for one senior (background process)
php artisan ml:run-single {seniorId} {surveyId}

# ── Frontend ─────────────────────────────────────────────────────────
# Dev server with hot reload
npm run dev

# Production build
npm run build

# ── Python ML ────────────────────────────────────────────────────────
# Start ML services manually (from project root, venv active)
python python/services/preprocess_service.py    # port 5001
python python/services/inference_service.py     # port 5002

# Or start both via PowerShell:
powershell -File python/start_services.ps1

# Install / update Python dependencies
python\venv\Scripts\pip install -r python\requirements.txt

# Run ML pipeline integration tests
python\venv\Scripts\python python\tests\test_ml_pipeline.py
```

---

## Troubleshooting

### "Services did not respond in time" or analysis not working

This means the Flask ML services did not start or have not finished loading yet.

1. Go to `/ml/status` in the browser
2. If either service shows `unreachable`, click **Start ML Services**
3. Wait 30–60 seconds for models to load (first start only)
4. Check logs at `storage/logs/preprocess.log` and `storage/logs/inference.log` for errors
5. Verify `python/venv/Scripts/python.exe` exists (run `setup.bat` if it does not)

### Analysis runs but results look like fallback values

The system uses PHP heuristics if Python is unavailable — results will show `status: success_fallback`. Fix:

1. Start Python services (see above)
2. Re-run the individual analysis from the senior's profile page

### Batch analysis is slow or times out

Batch analysis should take under 60 seconds when the Flask services are running (HTTP mode). If it's slow:

1. Go to `/ml/status` and confirm both services show `ok`
2. If they show `unreachable`, click **Start ML Services** and wait for them to come online
3. Re-run batch analysis

### No request logs in the terminal after `php artisan serve`

The ML service launcher is blocking the PHP server from starting. Ensure `start_services.ps1` is not running synchronously. Restart with `php artisan serve` — the custom `ServeCommand` launches ML services as a detached background process using `Start-Process`.

### Database migration fails

```bash
# Check your DB connection in .env, then:
php artisan migrate:fresh
```

If using MySQL, ensure the database exists first:
```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### "Vite manifest not found" error

```bash
npm run build
```

### `composer install` fails with class not found

```bash
composer dump-autoload
```

### Python WinError 10106 on Windows

Already handled. The services set `NUMBA_THREADING_LAYER=workqueue` and `NUMBA_NUM_THREADS=1` at startup to prevent this Windows-specific Winsock error. If it still occurs, delete `python/venv/` and run `setup.bat` again to rebuild the environment cleanly.

### Different ML results on different machines (cluster mismatch)

The most common cause is a missing or stale `python/models/predictions/senior_predictions.csv`. Check:

1. Run `git pull` — the prediction CSVs are version-controlled and must match the current branch.
2. Confirm `ENABLE_NOTEBOOK_OVERRIDES=true` in `.env` (the `.env.example` default).
3. Confirm `PYTHON_SERVICE_URL=http://127.0.0.1` with **no port suffix**.
4. If you recently reseeded, make sure you seeded after pulling — old ML results in the database pre-date the new model files.

### `osca.csv not found` during seeding

Place the file at `..\osca.csv` — one folder above the project root. The seeder uses `base_path('../osca.csv')`.

---

## Notes for Future Developers

**Changing the ML models:** Replace files in `python/models/` and restart the Flask services (they cache models at startup with `lru_cache`). Run `python/tests/test_ml_pipeline.py` after replacing any model files to verify the pipeline end-to-end before sharing with the team.

**Adding new risk domains:** The scoring pipeline spans `preprocess_service.py` (section scores), `inference_service.py` (domain risk functions), `MlService.php` (`persistResults()`), and the `ml_results` migration. All four must be updated together.

**QoL survey scoring:** Scoring logic exists in two places: `app/Models/QolSurvey.php` (`computeScores()`) and `python/services/preprocess_service.py`. Any changes to the survey instrument must be coordinated across both to keep the ML feature vector consistent.

**Role-based access control:** `spatie/laravel-permission` is installed but no roles are defined. All authenticated users have full access. Next step: define `osca_staff` and `admin` roles and restrict batch ML operations, force-delete, and user management to admins.

**Batch as a queued job:** `MlController::batchRun()` is synchronous. For very large datasets, move it to a Laravel queued job — the `jobs` table already exists.

**Activity logging:** The `activity_logs` table exists but no logging code runs. Add Eloquent observers on `SeniorCitizen`, `QolSurvey`, and `Recommendation` to record create/update/delete events.

**Windows-only deployment:** `start_services.ps1` is PowerShell-only. For Linux/macOS, use the included `python/start_services.sh` equivalent or start services manually.

**ENABLE_NOTEBOOK_OVERRIDES:** When `true` (the default), the inference service reads composite risk, cluster, and risk level from `python/models/predictions/senior_predictions.csv` instead of computing them live. This guarantees identical results across all machines regardless of OS, Python minor version, or floating-point differences. Set to `false` only when you want to test raw live model output against the notebook values.

---

## Additional Documentation

| Document | Description |
|---|---|
| [docs/GIT_WORKFLOW.md](docs/GIT_WORKFLOW.md) | Branching, commit format, PR process, do's and don'ts for collaborators |
| [docs/ML_PIPELINE.md](docs/ML_PIPELINE.md) | Full ML architecture: feature engineering, clustering, risk ensemble, fallback strategy |
| [docs/SYSTEM_FUNCTIONALITY.md](docs/SYSTEM_FUNCTIONALITY.md) | Complete system reference: all modules, data schema, capabilities, limitations |
