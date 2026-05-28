# Deployment Guide — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Audience:** Anyone setting up the system on a new device.
> **Platform:** Windows 10/11 with Laragon (recommended) or standard PHP/MySQL install.

---

## Table of Contents

1. [System Requirements](#1-system-requirements)
2. [Recommended Setup — setup.bat](#2-recommended-setup--setupbat)
3. [Manual Setup — Step by Step](#3-manual-setup--step-by-step)
4. [Starting the System](#4-starting-the-system)
5. [Stopping the System](#5-stopping-the-system)
6. [Environment Configuration Reference](#6-environment-configuration-reference)
7. [Default Login Accounts](#7-default-login-accounts)
8. [Pre-Defense Device Checklist](#8-pre-defense-device-checklist)
9. [Before Push Checklist](#9-before-push-checklist)
10. [After a Code Update (git pull)](#10-after-a-code-update-git-pull)
11. [Common Setup Errors](#11-common-setup-errors)

---

## 1. System Requirements

| Component | Requirement |
|---|---|
| OS | Windows 10 or Windows 11 |
| PHP | 8.2 or higher |
| Composer | 2.x |
| Node.js | 18 LTS or higher |
| NPM | 9 or higher |
| MySQL | 8.0 or higher (Laragon includes this) |
| Python | **3.12.x exactly** — see note below |
| Git | 2.x |
| RAM | 4 GB minimum, 8 GB recommended |
| Disk | 3 GB free |

> **Python must be 3.12.x.** The ML dependencies (numba 0.65.0, umap-learn 0.5.12) are tested only on Python 3.12. Python 3.13 causes a numba error that prevents UMAP from running. Download Python 3.12 at: https://www.python.org/downloads/release/python-3126/
> During installation, check **"Add Python to PATH"**.

**Recommended tool stack for Windows:**
- [Laragon](https://laragon.org/) — provides PHP, MySQL, and Composer in one installer
- [Node.js 18 LTS](https://nodejs.org/)
- [Python 3.12](https://www.python.org/downloads/release/python-3126/)
- [Git for Windows](https://git-scm.com/)

---

## 2. Recommended Setup — setup.bat

`setup.bat` handles the full setup automatically. Use this for any device setting up the system for the first time.

### Before running setup

You need the following files that are not in the git repository:

| File | Where to place it | Why needed |
|---|---|---|
| `osca.csv` | Project root (same folder as `setup.bat`) | Imports 283 senior citizen records |
| `python/models/` artifact bundle | `osca-system/python/models/` | ML model files (.pkl, .json) |

The artifact bundle must be transferred from the main laptop via USB or ZIP file. See [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) for transfer instructions.

### Create the MySQL database first

Before running setup, create the database in MySQL:

```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Using Laragon:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Run setup

```
Double-click setup.bat
```

Or from a terminal:
```powershell
.\setup.bat
```

`setup.bat` will:
1. Check prerequisites (PHP, Composer, Node.js, Python)
2. Install PHP dependencies (`composer install`)
3. Install Node.js dependencies (`npm install`)
4. Create `.env` from `.env.example` and generate application key
5. Warn if `ML_MODELS_PATH` or `ENABLE_NOTEBOOK_OVERRIDES` are missing from `.env`
6. Run database migrations
7. Import senior citizen data from `osca.csv` (if present)
8. Build frontend assets (`npm run build`)
9. Create Python virtual environment at `python/venv`
10. Install Python ML dependencies from `python/requirements.txt`
11. Copy ML model files from `osca_output/model/` (if present)
12. **Run `validate_model_artifacts.py`** — setup stops with a clear error if artifacts are missing or mismatched

When setup completes, you will see:

```
  Next step — start the system:
    Double-click  start.bat
```

If setup stops at step 12 with an artifact validation failure, the `python/models/` bundle is missing or incomplete. Copy the correct bundle from the main laptop and re-run setup. **Do not retrain models.**

---

## 3. Manual Setup — Step by Step

Use this only if `setup.bat` is unavailable or you need to set up components individually.

### Step 1 — Clone the repository

```powershell
git clone https://github.com/somarjez/osca-agesense.git
cd osca-agesense
```

### Step 2 — Install PHP dependencies

```powershell
composer install
```

### Step 3 — Install Node.js dependencies

```powershell
npm install
```

### Step 4 — Copy and configure environment file

```powershell
copy .env.example .env
php artisan key:generate
```

Then open `.env` and set your database credentials and ML settings (see [Section 6](#6-environment-configuration-reference)).

### Step 5 — Create the MySQL database

```sql
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Step 6 — Run database migrations

```powershell
php artisan migrate
```

### Step 7 — Import senior citizen data (if osca.csv is available)

Place `osca.csv` in the project root, then:
```powershell
php artisan db:seed
```

### Step 8 — Build frontend assets

```powershell
npm run build
```

### Step 9 — Create the Python virtual environment

```powershell
py -3.12 -m venv python\venv
```

> Use `py -3.12` to ensure Python 3.12 is used, not another installed version.

### Step 10 — Install Python ML dependencies

```powershell
python\venv\Scripts\pip.exe install -r python\requirements.txt
```

### Step 11 — Transfer the ML artifact bundle

Copy the `python/models/` directory from the main laptop. See [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md).

### Step 12 — Validate ML artifacts

```powershell
python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
```

Expected: **51 PASS, 0 FAIL, 0 WARN**

If any check fails, the artifact bundle is incomplete. Re-transfer from the main laptop.

### Step 13 — Start the system

```powershell
.\start.bat
```

---

## 4. Starting the System

### Recommended — start.bat

```
Double-click start.bat
```

`start.bat` does the following in order:
1. Checks for `.env` and prerequisites
2. Auto-adds any new keys from `.env.example` into `.env`
3. Detects PHP (PATH → Laragon → XAMPP)
4. Checks if MySQL is running; tries to start it if not
5. Clears compiled view cache
6. Starts both Python ML services in the background (logs to `storage/logs/python-preprocess.log` and `storage/logs/python-inference.log`)
7. Starts the Laravel queue worker in the background
8. Starts the Laravel task scheduler in the background
9. Opens `http://127.0.0.1:8000` in your browser after 5 seconds
10. Starts the Laravel development server in the foreground

Press **Ctrl+C** to stop the development server. The ML services and queue worker continue running in the background until you run `stop.bat` or restart your computer.

**Log files produced by start.bat:**

| Log file | Contents |
|---|---|
| `storage/logs/python-preprocess.log` | Preprocess service output |
| `storage/logs/python-inference.log` | Inference service output (model loading, errors) |
| `storage/logs/python-services-start.log` | Startup orchestration output |
| `storage/logs/queue.log` | Queue worker output |
| `storage/logs/scheduler.log` | Scheduled task output |

**Confirming services are running:**
```powershell
Invoke-WebRequest http://127.0.0.1:5001/health -UseBasicParsing
Invoke-WebRequest http://127.0.0.1:5002/health -UseBasicParsing
```

Both should return `{"status":"ok"}`.

### Manual start (individual terminals)

Open three separate terminals in the project root:

```powershell
# Terminal 1 — Preprocess service (port 5001)
python\venv\Scripts\python.exe python\services\preprocess_service.py

# Terminal 2 — Inference service (port 5002)
python\venv\Scripts\python.exe python\services\inference_service.py

# Terminal 3 — Laravel
php artisan serve
```

---

## 5. Stopping the System

```
Double-click stop.bat
```

`stop.bat` will:
- Stop PHP processes for this project (artisan serve, queue worker, scheduler)
- Stop the Python ML services on ports 5001 and 5002
- Confirm that ports 5001 and 5002 are free after stopping

Always run `stop.bat` before running `start.bat` again. This prevents duplicate services from binding the same port.

---

## 6. Environment Configuration Reference

The `.env` file in the project root controls all runtime settings. Never commit `.env` to git.

### Required settings — fill these in after creating `.env`

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=root
DB_PASSWORD=

ML_MODELS_PATH=python/models
ENABLE_NOTEBOOK_OVERRIDES=true
```

### ML settings

| Variable | Value | Description |
|---|---|---|
| `ML_MODELS_PATH` | `python/models` | Path to ML artifact files. Always set this explicitly. |
| `ENABLE_NOTEBOOK_OVERRIDES` | `true` | `true` = the original 283 seed seniors use stored notebook results (fast, deterministic). New seniors always use the live model regardless of this setting. Set to `true` for demos and defense. |
| `PYTHON_SERVICE_URL` | `http://127.0.0.1` | Base URL for Python services — no port suffix. |
| `PYTHON_PREPROCESS_PORT` | `5001` | Port for the preprocess service. |
| `PYTHON_INFERENCE_PORT` | `5002` | Port for the inference service. |
| `PYTHON_TIMEOUT` | `120` | Seconds to wait for a Python subprocess. Increase on slow machines. |

### Application settings

| Variable | Development value | Description |
|---|---|---|
| `APP_ENV` | `local` | Change to `production` for live deployment. |
| `APP_DEBUG` | `true` | Change to `false` in production to hide error details. |
| `APP_URL` | `http://localhost` | Base URL of the application. |

### Database settings (for shared remote MySQL during defense)

```env
DB_HOST=192.168.1.100    # Host laptop's IP address
DB_USERNAME=agesense_user
DB_PASSWORD=your_password
```

After changing `.env`, run:
```powershell
php artisan config:clear
php artisan cache:clear
```
Then restart both Flask services (stop.bat → start.bat).

---

## 7. Default Login Accounts

Three accounts are created automatically when you seed the database.

| Role | Email | Initial Password |
|---|---|---|
| Administrator | `admin@osca.local` | `Admin@OSCA2026!` |
| Encoder | `encoder@osca.local` | `Encoder@OSCA2026!` |
| Viewer | `viewer@osca.local` | `Viewer@OSCA2026!` |

> Change all passwords after first login. To reset to defaults: `php artisan db:seed --class=UserSeeder`

| Capability | admin | encoder | viewer |
|---|---|---|---|
| Dashboard, reports, recommendations (view) | Yes | Yes | Yes |
| Create and edit senior profiles | Yes | Yes | No |
| Run ML analysis | Yes | Yes | No |
| Archive / restore / permanently delete seniors | Yes | No | No |
| User account management | Yes | No | No |

---

## 8. Pre-Defense Device Checklist

Run these steps in order on every device before a demo or defense. Do not skip steps.

```
[ ] 1. Pull the latest code:
        git pull origin main

[ ] 2. Transfer the python/models artifact bundle (if not already present):
        - Copy osca_models_v1.1.1.zip from the main laptop via USB
        - Expand-Archive -Path osca_models_v1.1.1.zip -DestinationPath . -Force
        - Or copy the python/models/ folder directly

[ ] 3. Check .env settings:
        - ML_MODELS_PATH=python/models
        - ENABLE_NOTEBOOK_OVERRIDES=true
        - ENABLE_DETERMINISTIC_CLUSTER=true
        - DB_HOST, DB_USERNAME, DB_PASSWORD are correct

[ ] 4. Run setup.bat (or confirm all dependencies are installed):
        .\setup.bat
        OR confirm:
          - vendor/ exists (composer install done)
          - public/build/ exists (npm run build done)
          - python/venv/Scripts/python.exe exists

[ ] 5. Validate ML artifact bundle:
        python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
        → Expected: 51 PASS, 0 FAIL, 0 WARN

[ ] 6. Stop any running services first:
        .\stop.bat

[ ] 7. Start the system:
        .\start.bat

[ ] 8. Confirm both Python services are healthy:
        Invoke-WebRequest http://127.0.0.1:5001/health -UseBasicParsing
        Invoke-WebRequest http://127.0.0.1:5002/health -UseBasicParsing
        → Both should return {"status":"ok"}

[ ] 9. Run the reproducibility test:
        python\venv\Scripts\python.exe python\scripts\test_reproducibility.py
        → Expected: 28 PASS, 0 FAIL

[ ] 10. Import the database dump (if exact same 283 results are required):
        (follow DATABASE_SHARING_AND_TEAM_SETUP.md)
        After import, verify prediction_source distribution:
          notebook_cache: 283, live_model: 0 or more (for any new seniors added)

[ ] 11. Confirm the dashboard shows the correct numbers:
        Total seniors              : 286
        Risk — HIGH                : 56
        Risk — MODERATE            : 192
        Risk — LOW                 : 38
        Cluster C1                 : 75
        Cluster C2                 : 132
        Cluster C3                 : 76
        Notebook-Validated Cache   : 283
        Live ML Model              : 3
        Fallback                   : 0

[ ] 12. Test one senior profile page:
        - Open any senior's profile
        - Confirm cluster name, risk indicator, and recommendations are displayed
        - Confirm no "Fallback" badge appears

[ ] 13. System is ready.
        URL: http://127.0.0.1:8000
```

If step 5 fails: the artifact bundle is incomplete. Re-transfer from the main laptop. Do not retrain models.

If step 9 fails: check `storage/logs/python-inference.err.log` for the error.

If dashboard numbers are wrong: confirm `ENABLE_NOTEBOOK_OVERRIDES=true` in `.env`, then restart services (stop.bat → start.bat).

---

## 9. Before Push Checklist

Run these steps before committing and pushing code. All checks must pass.

```
[ ] 1. Artifact validation must pass:
        python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
        → Must show: 51 PASS, 0 FAIL, 0 WARN

[ ] 2. Reproducibility test must pass:
        python\venv\Scripts\python.exe python\scripts\test_reproducibility.py
        → Must show: 28 PASS, 0 FAIL

[ ] 3. Start/stop scripts must work without errors:
        .\stop.bat
        .\start.bat
        → Both services healthy on ports 5001 and 5002

[ ] 4. Individual and batch analysis must produce identical results:
        (test_reproducibility.py covers this — run 1 vs run 2 vs batch all PASS)

[ ] 5. A new senior must produce prediction_source=live_model:
        - Create a new senior in the UI and run analysis
        - Check the profile page shows "Live ML Model" as prediction source
        - No "Fallback" badge

[ ] 6. No fallback results appear unless intentionally tested.

[ ] 7. Review git status:
        git status
        → Check for accidentally staged files

[ ] 8. .env must NOT be in git status (it is gitignored):
        If it appears: git rm --cached .env

[ ] 9. Remove temporary or debug files:
        - No read_xlsx_temp.php or similar one-off scripts
        - No *.bak or *.bak_old files
        - No storage/app/ml_err_*.txt or ml_out_*.json files

[ ] 10. Commit only specific files — do not use git add . blindly:
        git status                   ← review everything
        git add path/to/file         ← stage specific files
        git diff --staged            ← review what will be committed

[ ] 11. Commit message must follow the format:
        feat: add barangay filter to risk report
        fix: correct cluster display on profile page
        docs: update DEPLOYMENT.md checklist

[ ] 12. Push and confirm CI passes:
        git push origin your-branch-name
```

Files that must never be committed:
- `.env`
- `osca.csv`
- `database/backups/*.sql`
- `python/models/predictions/senior_predictions.csv`
- `storage/app/ml_err_*.txt`, `storage/app/ml_out_*.json`
- `storage/app/ml_models/*.pkl` (mirror copies — canonical is `python/models/`)
- `vendor/`, `node_modules/`, `python/venv/`

---

## 10. After a Code Update (git pull)

When the project lead pushes new changes:

```powershell
# 1. Pull latest code
git pull origin main

# 2. Apply new database migrations (if any migration files changed)
php artisan migrate

# 3. Rebuild frontend assets (if JS or CSS files changed)
npm run build

# 4. Install new Python dependencies (if requirements.txt changed)
python\venv\Scripts\pip.exe install -r python\requirements.txt

# 5. Restart the system so services load new code
.\stop.bat
.\start.bat
```

The queue worker must be restarted after any PHP code change — it loads classes at startup and will not pick up new code until restarted. Running `stop.bat` then `start.bat` handles this automatically.

---

## 11. Common Setup Errors

| Error | Cause | Fix |
|---|---|---|
| `php artisan migrate` fails with "Access denied" | Wrong DB credentials in `.env` | Check `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST` |
| Python services show "Offline" in the UI | Services not started | Run `.\start.bat` or start services manually |
| Port already in use on 5001 or 5002 | Old service still running | Run `.\stop.bat` first, then `.\start.bat` |
| `pop from empty list` in Python logs | Wrong Python version (not 3.12) | Delete `python\venv`, recreate with `py -3.12 -m venv python\venv`, reinstall requirements |
| Artifact validation shows FAIL | Missing or wrong files in `python/models/` | Transfer the correct artifact bundle from the main laptop |
| Dashboard shows wrong risk distribution | `ENABLE_NOTEBOOK_OVERRIDES` is `false` | Set to `true` in `.env`, restart services |
| "Finalising health group assignments…" stuck | Queue worker not running | Close all terminals, run `.\stop.bat`, then `.\start.bat` |
| Assets not loading (404 on /build/) | Frontend not built | Run `npm run build` |
| `UMAP import error` on Python startup | Missing Python packages | Run `python\venv\Scripts\pip.exe install -r python\requirements.txt` |
| `osca.csv not found` during seeding | File not placed in project root | Place `osca.csv` in the same folder as `setup.bat` |
| Encrypted field shows gibberish in DB | `APP_KEY` changed after data was saved | Restore the original `APP_KEY` from the original `.env` |
| `ModuleNotFoundError: No module named pymysql` | pymysql not installed (optional) | `python\venv\Scripts\pip.exe install pymysql` |

---

## Related Documents

- [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) — Artifact validation, service startup, prediction paths
- [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md) — DB export, import, and shared MySQL setup
- [GIT_WORKFLOW.md](GIT_WORKFLOW.md) — Branching, commits, and pull requests
- [README.md](README.md) — Documentation index
