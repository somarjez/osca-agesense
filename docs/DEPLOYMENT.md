# Deployment Guide — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Audience:** System administrators and developers setting up the system for the first time or deploying to a new environment.
> **Last Updated:** 2026-05-19

---

## Table of Contents

1. [System Requirements](#1-system-requirements)
2. [Directory Structure Overview](#2-directory-structure-overview)
3. [Installation — Development (Windows)](#3-installation--development-windows)
4. [Environment Configuration](#4-environment-configuration)
5. [Database Setup](#5-database-setup)
6. [Python ML Services Setup](#6-python-ml-services-setup)
7. [Running the Application](#7-running-the-application)
8. [First Login and Default Credentials](#8-first-login-and-default-credentials)
9. [Loading Existing Data](#9-loading-existing-data)
10. [What Other Devices Need To Do](#10-what-other-devices-need-to-do)
11. [Production Deployment Checklist](#11-production-deployment-checklist)
12. [Common Setup Errors](#12-common-setup-errors)

---

## 1. System Requirements

### Minimum (development / pilot)

| Component | Requirement |
|---|---|
| OS | Windows 10/11 (production: Ubuntu 22.04 LTS recommended) |
| PHP | 8.2 or higher |
| Composer | 2.x |
| Node.js | 18 LTS or higher |
| NPM | 9 or higher |
| MySQL | 8.0 or higher (or MariaDB 10.6+) |
| **Python** | **3.12.x exactly** — see note below |
| Git | 2.x |
| RAM | 4 GB minimum, 8 GB recommended |
| Disk | 2 GB free (models + database + app) |

> **Python version must be 3.12.x.** The ML dependencies (numba 0.65.0, umap-learn 0.5.12) are pinned to versions that are fully tested on Python 3.12. Python 3.13 causes a numba JIT compilation error (`pop from empty list` in byteflow.py) that prevents UMAP from running. Python 3.10 or 3.11 may work but are untested. Download Python 3.12 from: https://www.python.org/downloads/release/python-3126/

### PHP extensions required

```
php-pdo, php-mysql, php-mbstring, php-xml, php-curl, php-zip,
php-fileinfo, php-bcmath, php-tokenizer, php-ctype, php-json
```

Verify with: `php -m | findstr -i "pdo mysql mbstring"`

---

## 2. Directory Structure Overview

```
osca-system/
├── app/
│   ├── Console/Commands/   Artisan commands (osca:purge-expired)
│   ├── Http/Controllers/   Route controllers (BulkUploadController, MlController, etc.)
│   ├── Http/Middleware/    NoTimeLimit middleware (applied to ML and bulk upload routes)
│   ├── Jobs/               ProcessMlBatch queued job
│   ├── Livewire/           Livewire components (dashboard, reports, forms)
│   ├── Models/             Eloquent models (including ActivityLog)
│   ├── Observers/          ActivityLogObserver
│   ├── Services/           MlService, ClusterAnalyticsService
│   └── Support/            ClusterMetrics helper
├── database/
│   ├── migrations/         Database schema definitions
│   └── seeders/            OscaCsvSeeder (bulk import)
├── docs/                   This documentation
├── python/
│   ├── models/             Trained artefacts (.pkl, .json) including cluster_mapping.json
│   ├── services/           preprocess_service.py, inference_service.py, local_ml_runner.py
│   ├── tests/              test_ml_pipeline.py, test_inference_paths.py, test_inference_e2e.py
│   ├── venv/               Python virtual environment (not committed)
│   ├── fix_cluster_distribution.py   One-time cluster alignment script (run after every seed)
│   ├── start_services.ps1  Windows startup script
│   └── start_services.sh   Linux/macOS startup script
├── resources/
│   ├── js/                 Alpine.js + Chart.js frontend
│   └── views/              Blade templates
├── routes/                 web.php, auth.php, seniors.php, surveys.php, ml.php, reports.php, recommendations.php, users.php
├── pyrightconfig.json      VS Code Pylance config (points to python/venv for import resolution)
├── storage/
│   └── logs/               queue.log, queue.err.log, ml_startup.log
└── .env                    Environment configuration (not committed)
```

---

## 3. Installation — Development (Windows)

### Recommended path — `setup.bat` (automated)

For any machine cloning the project for the first time (including collaborators), the recommended approach is the automated batch script.

#### Before running setup — collect these private files

One file is gitignored and must be obtained from the project lead before setup. It contains real personal data and is never committed to the repository.

| File | Where to place it |
|---|---|
| `osca.csv` | Project root — same folder as `setup.bat` |

> **Note:** The prediction CSV files (`senior_predictions.csv`, `senior_recommendations_flat.csv`) are no longer required. The system uses trained model files committed to the repository.

#### Setup steps

```
1. git clone https://github.com/somarjez/osca-agesense.git
2. cd osca-agesense
3. Create the MySQL database: CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
4. Place osca.csv in the project root
5. Double-click setup.bat
```

`setup.bat` handles everything automatically:
- `composer install`, `npm install`
- `.env` creation and key sync, application key generation
- Database migrations and CSV seeding (if `osca.csv` present)
- Frontend build (`npm run build`)
- Python virtual environment creation (`python/venv`)
- Python dependency installation from `requirements.txt`
- ML model file sync from `osca_output/model/` if present
- **Auto-runs `fix_cluster_distribution.py`** to align cluster assignments (Step 10/10 of setup)

After setup completes, use `start.bat` to launch the system every session.

> **Why `fix_cluster_distribution.py` runs automatically:** Each device has its own local MySQL database seeded from `osca.csv`. UMAP's transform can orient cluster IDs differently on different machines. This script runs all seniors through a single batch transform and **auto-calibrates `cluster_mapping.json`** to ensure the cluster labels (High Functioning / Moderate / Low Functioning) are correct on every device. Without it, C1 and C2 may be swapped.

---

### Manual setup (alternative)

### Step 1 — Clone the repository

```powershell
git clone https://github.com/somarjez/osca-agesense.git
cd osca-agesense
```

### Step 2 — Install PHP dependencies

```powershell
composer install
```

### Step 3 — Install Node dependencies and build assets

```powershell
npm install
npm run build
```

### Step 4 — Copy and configure environment file

```powershell
copy .env.example .env
php artisan key:generate
```

Edit `.env` — at minimum set the database credentials (see Section 4).

### Step 5 — Run database migrations

```powershell
php artisan migrate
```

### Step 6 — Set up Python virtual environment

```powershell
cd python
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
cd ..
```

> Use Python 3.12 specifically. If you have multiple Python versions installed, use `py -3.12 -m venv venv`.

### Step 7 — Seed the database

```powershell
php artisan db:seed   # requires osca.csv at project root or ../osca.csv
```

### Step 8 — Align cluster assignments (required after every seed)

```powershell
python\venv\Scripts\python.exe python\fix_cluster_distribution.py
```

This takes ~1–2 minutes. See Section 9 for details on why this is required.

### Step 9 — Start the application

```powershell
# Easiest: double-click start.bat
# Or manually:
php artisan serve
```

The application opens at `http://127.0.0.1:8000`.

---

## 4. Environment Configuration

Key `.env` variables:

### Application

| Variable | Example | Description |
|---|---|---|
| `APP_NAME` | `AgeSense` | Application name shown in the browser title |
| `APP_ENV` | `local` / `production` | Environment mode |
| `APP_DEBUG` | `true` / `false` | Show detailed errors (set `false` in production) |
| `APP_URL` | `http://localhost:8000` | Base URL of the application |

### Database

| Variable | Example | Description |
|---|---|---|
| `DB_CONNECTION` | `mysql` | Database driver |
| `DB_HOST` | `127.0.0.1` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `osca_db` | Database name |
| `DB_USERNAME` | `root` | Database username |
| `DB_PASSWORD` | `yourpassword` | Database password |

### Session and Cache

| Variable | Recommended | Description |
|---|---|---|
| `SESSION_DRIVER` | `database` | Stores sessions in the database |
| `SESSION_LIFETIME` | `120` | Session expiry in minutes |
| `CACHE_STORE` | `database` | Cache driver |
| `QUEUE_CONNECTION` | `database` | Queue driver (uses `jobs` table) |

### ML Services

| Variable | Default | Description |
|---|---|---|
| `PYTHON_SERVICE_URL` | `http://127.0.0.1` | Base URL for Python microservices — **no port suffix** |
| `PYTHON_PREPROCESS_PORT` | `5001` | Preprocessor service port |
| `PYTHON_INFERENCE_PORT` | `5002` | Inference service port |
| `ML_MODELS_PATH` | `python/models` | Path to `.pkl` / `.json` model artefacts |
| `ENABLE_NOTEBOOK_OVERRIDES` | `false` | Retired — CSV override system replaced by live ML inference. Leave `false`. |
| `PYTHON_TIMEOUT` | `120` | Seconds to wait for a local Python subprocess. Increase on slow machines. |
| `PYTHON_EXECUTABLE` | _(auto)_ | Override the Python binary. Leave blank to auto-detect from `python/venv`. |

### Mail (notifications)

| Variable | Default | Description |
|---|---|---|
| `MAIL_MAILER` | `log` | Set to `smtp` for real email delivery |
| `MAIL_HOST` | `127.0.0.1` | SMTP host |
| `MAIL_PORT` | `1025` | SMTP port |

---

## 5. Database Setup

### Create the database

```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Run migrations

```powershell
php artisan migrate
```

This creates all tables: `users`, `senior_citizens`, `qol_surveys`, `ml_results`, `recommendations`, `activity_logs`, `cluster_snapshots`, `jobs`, `sessions`, etc.

### Reset and re-seed (development only)

```powershell
php artisan migrate:fresh
php artisan db:seed   # requires osca.csv at project root or ../osca.csv
python\venv\Scripts\python.exe python\fix_cluster_distribution.py
```

> **Warning:** `migrate:fresh` drops all tables. Never run on a device with live data you care about. Always run `fix_cluster_distribution.py` after re-seeding.

---

## 6. Python ML Services Setup

### Virtual environment

```powershell
cd python
py -3.12 -m venv venv        # use py -3.12 if multiple Python versions are installed
venv\Scripts\activate
pip install -r requirements.txt
cd ..
```

### Start services manually

```powershell
# Windows (PowerShell) — start both services
cd python
.\start_services.ps1

# Or start individually
cd python/services
python preprocess_service.py   # runs on port 5001
python inference_service.py    # runs on port 5002
```

### Verify services are running

```powershell
Invoke-WebRequest http://127.0.0.1:5001/health
Invoke-WebRequest http://127.0.0.1:5002/health
```

Both should return `{"status": "ok"}`.

### Model artefacts

All trained `.pkl` files are committed to `python/models/` and downloaded automatically on `git clone` or `git pull`. Verify with:

```powershell
dir python\models\
```

Expected files: `scaler.pkl`, `umap_nd.pkl`, `umap_nd.pkl`, `kmeans.pkl`, `gbr_ic_risk.pkl`, `gbr_env_risk.pkl`, `gbr_func_risk.pkl`, `rfr_ic_risk.pkl`, `rfr_env_risk.pkl`, `rfr_func_risk.pkl`, `edu_encoder.pkl`, `income_encoder.pkl`, `feature_list.json`, `cluster_mapping.json`, `asset_weights.json`, `cluster_metadata.json`, `cluster_eval_metrics.json`.

> **`cluster_mapping.json` is auto-updated** by `fix_cluster_distribution.py` on each device. It maps raw KMeans IDs to named cluster IDs (1=High Functioning, 2=Moderate, 3=Low Functioning). This file is committed to git as a baseline but will be correctly re-calibrated for each device's UMAP orientation when the alignment script runs.

---

## 7. Running the Application

### Development (Windows — recommended)

Double-click `start.bat`. It does everything automatically:

1. Detects PHP (PATH → Laragon → XAMPP)
2. Starts Python ML services in the background (`start_services.ps1`)
3. Starts the Laravel queue worker as a hidden background process (logs to `storage/logs/queue.log`)
4. Opens `http://127.0.0.1:8000` in your browser
5. Starts the Laravel development server in the foreground

Press `Ctrl+C` to stop the server. The ML services and queue worker continue in the background until you close all terminals or restart.

### Development (manual — single terminal)

```powershell
# Start Python ML services
powershell -File python\start_services.ps1

# Start queue worker (keep running for batch ML inference)
php artisan queue:work --queue=default

# Start Laravel in a separate terminal
php artisan serve
```

### Production (Ubuntu/nginx)

1. Build frontend assets:
   ```bash
   npm run build
   ```

2. Configure nginx to point to `/public`.

3. Set permissions:
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

4. Run migrations:
   ```bash
   php artisan migrate --force
   ```

5. Start Python services as system services (systemd):
   ```bash
   # /etc/systemd/system/osca-inference.service
   [Service]
   ExecStart=/var/www/osca-agesense/python/venv/bin/python /var/www/osca-agesense/python/services/inference_service.py
   Restart=always
   User=www-data
   ```

---

## 8. First Login and Default Credentials

Three accounts are created automatically by `UserSeeder` (runs as part of `php artisan db:seed`).

| Role | Email | Initial Password |
|---|---|---|
| Administrator | `admin@osca.local` | `Admin@OSCA2026!` |
| Encoder | `encoder@osca.local` | `Encoder@OSCA2026!` |
| Viewer | `viewer@osca.local` | `Viewer@OSCA2026!` |

> **Change all passwords immediately after first login.**

### What each role can do

| Capability | admin | encoder | viewer |
|---|---|---|---|
| Dashboard, reports, recommendations (view) | ✅ | ✅ | ✅ |
| Create and edit senior profiles | ✅ | ✅ | ❌ |
| Bulk CSV upload | ✅ | ✅ | ❌ |
| Manage QoL surveys | ✅ | ✅ | ❌ |
| Run ML inference | ✅ | ✅ | ❌ |
| Archive / restore / permanently delete seniors | ✅ | ❌ | ❌ |
| Activity log, CSV exports, cluster snapshots | ✅ | ❌ | ❌ |
| User account management | ✅ | ❌ | ❌ |

### Resetting a locked account

```powershell
php artisan db:seed --class=UserSeeder
```

This restores default passwords for all three seed accounts without touching senior data.

---

## 9. Loading Existing Data

### Important — each device has its own local database

Each device runs its own local MySQL database seeded from `osca.csv`. This means cluster assignments must be **auto-calibrated on every device after seeding** — otherwise devices may show different health group counts for the same seniors.

**After every fresh seed, run:**

```powershell
python\venv\Scripts\python.exe python\fix_cluster_distribution.py
```

This script:
1. Preprocesses all 288 seniors
2. Runs one batch UMAP + KMeans transform on all seniors together
3. Computes mean QoL score per raw cluster and **auto-calibrates `cluster_mapping.json`** to ensure C1 = High Functioning, C2 = Moderate, C3 = Low Functioning on this specific device
4. Runs full inference for all seniors and updates every `ml_results` row
5. Takes ~1–2 minutes

**Expected output (example):**
```
Step 3: Auto-calibrating cluster mapping for this device...
  Raw cluster mean QoL scores: {2: 3.9648, 1: 4.6354, 0: 3.2365}
  Corrected raw->named mapping: {1: 1, 2: 2, 0: 3}
  [ OK ] cluster_mapping.json updated.
...
New cluster distribution:
  C1: 77
  C2: 134
  C3: 77
New risk distribution:
  HIGH: 56
  LOW: 39
  MODERATE: 193
```

The risk distribution (HIGH/MODERATE/LOW counts) must be **identical** across all devices. The C1/C2/C3 counts may differ by ±1–2 seniors due to UMAP borderline cases — this is normal and does not affect individual senior assessments.

### Why results are consistent across devices

The 283 seniors in `osca.csv` are the same data the model was trained on. All 5 additional seniors were added via bulk upload after training. The model files (`.pkl`, `.json`) are identical on every device since they are committed to git. The `fix_cluster_distribution.py` auto-calibration ensures the cluster labels mean the same thing on every device regardless of UMAP orientation.

### Validation

After running `fix_cluster_distribution.py`, verify the results make sense:
- HIGH risk seniors should have low QoL scores, informal settler housing, heavy disease burden
- LOW risk seniors should have high QoL scores, owned property, pension income, good health
- No HIGH risk seniors should appear in C1 (High Functioning)
- No LOW risk seniors should appear in C3 (Low Functioning)

### CSV bulk import

Upload new seniors in bulk via **Senior Records → Bulk Upload** in the web interface (supports CSV and Excel). The template can be downloaded from the same page.

After a large bulk upload, run `fix_cluster_distribution.py` again to re-calibrate cluster assignments across the new population.

### Manual entry

Register seniors one at a time via **Senior Records → New Profile** in the web interface.

---

## 10. What Other Devices Need To Do

### First-time setup on a new device

```powershell
# Step 1 — Clone the repository
git clone https://github.com/somarjez/osca-agesense.git
cd osca-agesense

# Step 2 — Create MySQL database
# Run in MySQL: CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Step 3 — Place osca.csv in the project root

# Step 4 — Run automated setup (installs all dependencies, seeds DB, builds frontend, creates venv)
setup.bat
# setup.bat automatically runs fix_cluster_distribution.py at the end (Step 10/10)

# Step 5 — Start the system
start.bat
```

> **Python version:** If `setup.bat` creates the venv with the wrong Python version, delete `python\venv` and recreate it manually:
> ```powershell
> Remove-Item -Recurse -Force python\venv
> py -3.12 -m venv python\venv
> python\venv\Scripts\pip.exe install -r python\requirements.txt
> python\venv\Scripts\python.exe python\fix_cluster_distribution.py
> ```

---

### After a code update (lead device pushed new changes)

```powershell
# Step 1 — Pull latest code
git pull

# Step 2 — Apply any new database migrations
php artisan migrate

# Step 3 — Restart start.bat (close all terminals first)
#           Queue worker MUST restart to load updated PHP classes.
start.bat
```

If frontend assets changed (`resources/js/` or `resources/css/`):
```powershell
npm run build
```

If `python/requirements.txt` changed:
```powershell
python\venv\Scripts\pip.exe install -r python\requirements.txt
```

---

### After a queue worker crash or "Finalising health group assignments…" stuck

The `RecalculateClusters` job does not auto-retry (`tries = 1`).

```powershell
# Step 1 — Restart start.bat (close all terminals first)
# Step 2 — Clear the failed job
php artisan queue:flush
# Step 3 — Go to /ml/batch and click Run Full Batch again
```

---

### Cluster results look wrong after a fresh setup

If C1 shows ~132 seniors instead of ~77, the cluster mapping is inverted. Run:

```powershell
python\venv\Scripts\python.exe python\fix_cluster_distribution.py
```

The script auto-detects and corrects the raw→named cluster mapping for this device.

---

### Quick checklist — after any pull

- [ ] `git pull` completed without merge conflicts
- [ ] `php artisan migrate` if new migration files exist
- [ ] `start.bat` restarted (queue worker picks up new code)
- [ ] `npm run build` if JS/CSS files changed
- [ ] `pip install -r python\requirements.txt` if `requirements.txt` changed
- [ ] Green dot in nav bar (Python services online)

---

## 11. Production Deployment Checklist

Before going live with real data:

- [ ] Change all default account passwords (admin, encoder, viewer) via **User Management** or re-seed
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Confirm `SESSION_DRIVER=database` and sessions table exists
- [ ] Confirm `QUEUE_CONNECTION=database` and `jobs` / `job_batches` tables exist
- [ ] Confirm queue worker starts on boot (systemd on Linux, Task Scheduler on Windows)
- [ ] Confirm ML model artefacts are present in `python/models/` (auto-present after `git clone`)
- [ ] Confirm `ENABLE_NOTEBOOK_OVERRIDES=false` in `.env`
- [ ] Verify Python 3.12 is the version used by `python/venv`
- [ ] Verify Python services start on boot
- [ ] Set up automated database backups (daily minimum)
- [ ] Configure HTTPS (TLS certificate)
- [ ] Test all three ML fallback tiers on the target server
- [ ] Verify Activity Log is recording entries at `/activity-log`
- [ ] Run `fix_cluster_distribution.py` after initial seed on every device

---

## 12. Common Setup Errors

| Error | Cause | Fix |
|---|---|---|
| `php artisan migrate` fails with "Access denied" | Wrong DB credentials | Check `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST` in `.env` |
| `composer install` fails with PHP version error | PHP < 8.2 installed | Install PHP 8.2+ |
| `php` not found | PHP not on PATH | Install Laragon (auto-detected) or add PHP to system PATH |
| Python services show "Offline" | Services not started | Run `.\python\start_services.ps1` or use `start.bat` |
| Batch ML inference stuck at 0% | Queue worker not running | Check `storage/logs/queue.log`; run `php artisan queue:work` manually |
| `pop from empty list` in numba during UMAP | Stale numba JIT cache or Python version mismatch | Delete and recreate `python\venv` with Python 3.12; re-run `pip install -r python\requirements.txt` |
| `batch KMeans path failed (pop from empty list)` | numba can't compile UMAP distance function — Python version incompatible with numba 0.65.0 | Confirm `python\venv\Scripts\python.exe --version` shows Python 3.12.x; if not, recreate venv with `py -3.12 -m venv python\venv` |
| C1 shows ~132 seniors (cluster labels swapped) | UMAP orientation flipped on this device — `cluster_mapping.json` not yet calibrated | Run `python\venv\Scripts\python.exe python\fix_cluster_distribution.py` |
| C1/C2/C3 counts differ by ±1–2 from another device | Normal UMAP borderline variance on identical data | Expected — differences of 1–2 seniors are acceptable. Risk counts (HIGH/MODERATE/LOW) must be identical. |
| Wrong cluster distribution after seeding | `fix_cluster_distribution.py` not run after seed | Run `python\venv\Scripts\python.exe python\fix_cluster_distribution.py` |
| "Finalising health group assignments…" stuck | Queue worker not restarted after code update | Restart `start.bat`, run `php artisan queue:flush`, re-run batch from `/ml/batch` |
| `Call to undefined method MlService::runRecluster()` | Queue worker loaded old MlService class | Restart `start.bat` — worker reloads all classes on startup |
| Pylance shows "Import could not be resolved" for pymysql / preprocess_service | VS Code not pointing at the venv | `pyrightconfig.json` is committed — reload VS Code window to apply it |
| `osca.csv not found` during seeding | File not placed before running setup | Place `osca.csv` in the project root (same folder as `setup.bat`) |
| PHP Fatal error: Maximum execution time of 30 seconds | Long ML operation on a non-ML route | ML routes and bulk upload route have `no.time.limit` middleware applied; if occurring elsewhere, check which route is timing out |
| `WinError 10106` in Python service logs | Numba socket conflict on Windows | Restart the ML services from `/ml/status` |
| `UMAP` import error on Python startup | Missing packages | Re-run `pip install -r python/requirements.txt` with venv activated |
| Assets not loading (404 on `/build/`) | Vite build not run | Run `npm run build` |
| Encrypted field shows gibberish in database | `APP_KEY` changed after data was encrypted | Restore the original `APP_KEY` from a backup `.env` |
