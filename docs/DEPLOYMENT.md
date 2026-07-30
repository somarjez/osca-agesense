# Deployment Guide — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Audience:** Anyone setting up the system on a new device.
> **Platform:** Windows 10/11 with Laragon (recommended) or standard PHP/MySQL install.

> **⚠️ Current state (2026-06-11): 290 seniors, live-model canonical.** This deployment runs `ENABLE_NOTEBOOK_OVERRIDES=false` — all 290 seniors are scored by the live model (`prediction_source = live_model`) and the dashboard shows the **live** distribution **HIGH=56, MODERATE=196, LOW=38** (clusters C1=61, C2=85, C3=77, C4=67). Figures below that read "283", "notebook_cache: 283", or "HIGH=55/MODERATE=191/LOW=37" describe the earlier 283-senior / overrides-`true` state. Authoritative current numbers: [2026-06-11 re-sync record](superpowers/plans/2026-06-11-model-resync-290-osca-id-overrides.md).

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
13. Install **"Start OSCA System" / "Stop OSCA System"** shortcut icons on the Desktop and Start Menu — a windowless, no-console way to run the app day-to-day. See [Section 4](#4-starting-the-system) for details.

When setup completes, you will see:

```
  Next step — start the system:
    Double-click the 'Start OSCA System' icon
    on your Desktop (or Start Menu > AgeSense OSCA).

    -- developers / terminal --
    .\start.bat
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

### Step 14 — (Optional) Install desktop/Start Menu shortcuts

Manual setup skips the shortcut installer that `setup.bat` runs automatically. To add the **"Start OSCA System" / "Stop OSCA System"** icons:

```powershell
.\Install-Shortcuts.bat
```

---

## 3b. Updating an existing deployment (pulling changes)

After `git pull` on a machine that's already set up:

```bash
composer install            # only if composer.lock changed
npm install                 # only if package-lock.json changed
npm run build               # ALWAYS — public/build/ is gitignored, so compiled CSS/JS must be rebuilt
php artisan migrate          # apply any new migrations (the GIS facility/route work added none)
php artisan config:clear     # pick up config/.env changes (e.g. ORS timeouts)
php artisan view:clear       # drop stale compiled Blade
php artisan queue:restart    # IMPORTANT: queue workers cache code; restart so chained jobs (e.g. geocode → recompute) run the new code
```

**GIS road-distance data is not in the repo** — it lives in the database. If this machine has its own database and you want road-network distances (the profile/map "X min drive" and the road-based accessibility score), populate them after coordinates exist:

```bash
php artisan gis:score-proximity                       # accessibility scores (local, fast)
php artisan gis:cache-route-distances --facilities=12  # ORS road routes (needs OPENROUTESERVICE_API_KEY; re-run until coverage completes)
```

Both also run automatically after a `gis:geocode` that changes coordinates (with a queue worker running).

**Scheduler (auto-completes route coverage).** Full road-route coverage exceeds the ORS free-tier daily quota, so `routes/console.php` schedules `gis:cache-route-distances` daily (03:30) and `gis:score-proximity` daily (05:00); coverage self-completes over a few days and stays fresh. This requires the **Laravel scheduler** to be running — add a cron entry (`* * * * * php artisan schedule:run`) on the server, or run `php artisan schedule:work` during development. (A **queue worker** is also needed for the geocode-triggered recompute: `php artisan queue:work`.)

---

## 4. Starting the System

### Office staff — "Start OSCA System" icon

Double-click **"Start OSCA System"** on the Desktop or Start Menu (`AgeSense OSCA` folder). No console window appears — a branded loading page opens immediately in the browser and auto-redirects to the app once the server responds (usually 5–15 seconds). Clicking the icon again while it's already starting shows a "please wait" popup instead of restarting everything; clicking it while already running just reopens the loading page.

This runs `start-quiet.ps1` (via `launch-osca.vbs`), the windowless equivalent of `start.bat` below — same startup sequence, but `artisan serve` runs in the background instead of the foreground, so nothing stays open on screen. If it's missing (e.g. after moving the project folder), double-click `Install-Shortcuts.bat` to recreate it.

### Developers — start.bat

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

**Office staff:** double-click **"Stop OSCA System"** (Desktop or Start Menu). A brief confirmation popup appears once everything is shut down.

**Developers:**
```
Double-click stop.bat
```

Both run the same teardown (`stop.ps1`, with `-Quiet` behind the icon so it doesn't wait on a keypress):
- Stop PHP processes for this project (artisan serve, queue worker, scheduler)
- Stop the Python ML services on ports 5001 and 5002
- Confirm that ports 5001 and 5002 are free after stopping

Always stop the system before starting it again from a different mode (e.g. `stop.bat` before switching to the icon, or vice versa). This prevents duplicate services from binding the same port — though the icon's own "already running" / "already starting" checks make accidental double-starts from the icon itself harmless.

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
ENABLE_NOTEBOOK_OVERRIDES=false
```

### ML settings

| Variable | Value | Description |
|---|---|---|
| `ML_MODELS_PATH` | `python/models` | Path to ML artifact files. Always set this explicitly. |
| `ENABLE_NOTEBOOK_OVERRIDES` | `false` | **`false` (deployed default)** = live KNN cluster + GBR/RFR risk for every senior; `prediction_source='live_model'`. Set to `true` only for demo/defense to reproduce the exact notebook distribution (then run `php artisan ml:batch-analyze --force`). |
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

Seeded accounts and their roles are defined in `database/seeders/UserSeeder.php` — initial credentials are not documented here to avoid keeping real login secrets in version control.

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
        - Copy osca_models_v2.0.0.zip from the main laptop via USB
        - Expand-Archive -Path osca_models_v2.0.0.zip -DestinationPath . -Force
        - Or copy the python/models/ folder directly

[ ] 3. Check .env settings:
        - ML_MODELS_PATH=python/models
        - ENABLE_NOTEBOOK_OVERRIDES=false  (live-model default; set true only for demo/defense)
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
        Total seniors              : 283
        Risk — HIGH                : 55
        Risk — MODERATE            : 191
        Risk — LOW                 : 37
        Cluster C1                 : 60
        Cluster C2                 : 84
        Cluster C3                 : 74
        Cluster C4                 : 65
        Notebook-Validated Cache   : 283
        Live ML Model              : 0
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

If dashboard numbers are wrong: confirm `ENABLE_NOTEBOOK_OVERRIDES=false` in `.env` and that Flask services reloaded the flag (restart with stop.bat → start.bat). Then run `php artisan ml:batch-analyze --force` so all seniors are re-scored by the live model.

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

## 12. Production reliability on Render (Phase E)

Everything above this section covers local/office Windows deployment. This section covers the **live production deployment** at `pagsanjan-osca.online` (Render free tier + Neon Postgres), which has a structural gap the local setup doesn't: on Render, nothing runs continuously in the background. `start.bat` locally launches a queue worker and a scheduler loop alongside the web services (§4 above) — Render's container is purely request-driven and exits after `scripts/00-laravel-deploy.sh` finishes migrating.

**What this means without a workaround:**
- Queued jobs (ML analysis after a QoL survey, batch analysis, bulk-upload processing, GIS geocoding) sit in the `jobs` table forever in "pending" state — nothing drains them.
- The 3 daily maintenance tasks in `routes/console.php` (cluster snapshot, GIS route-distance backfill, proximity scoring) never fire — nothing runs `schedule:run`.
- The web service sleeps after ~15 min idle, causing a ~60-90s cold start on the next visit.

**The fix — `.github/workflows/keep-alive.yml`:** a scheduled GitHub Actions workflow hits `POST /api/internal/cron-tick` (guarded by the `VerifyCronToken` middleware checking an `X-Cron-Token` header against `CRON_TRIGGER_TOKEN`) every 10 minutes during business hours (6am-8pm PHT, 7 days/week). That one request runs `schedule:run` (fires any due maintenance task) then `queue:work --queue=ml,default --stop-when-empty --max-time=240` (drains whatever's pending on both queues) — and, as a side effect of just being an HTTP request, keeps the Render service from going to sleep during that window.

**Why business hours only, not 24/7:** the Render Hobby (free) plan pools **750 instance-hours/month across every free service in the workspace** — this project has 3 (`osca-agesense` Laravel + `osca-preprocess` + `osca-inference`). Pinging the Laravel app 24/7 alone would use ~720-730 of those 750 hours, leaving almost nothing for the 2 Python ML services and risking the whole workspace being suspended until the next monthly reset if they get any meaningful daily use too. The 6am-8pm PHT window uses roughly 360 hours/month instead, leaving real headroom. Outside the window, the first visit of the day eats one cold start — same as today, just narrowed to off-hours.

**Why the Python services are never pinged:** `app/Services/MlService.php` already has a complete graceful-degradation chain for them being asleep — a fast health-gate probe, then an automatic cold-start retry (`PYTHON_COLD_START_TIMEOUT`), then a local-Python-subprocess tier (dev-only), then a pure-PHP heuristic fallback that always succeeds. Their cold start is already handled correctly; keeping them warm would only be a latency nicety, and isn't worth the shared instance-hour cost.

**Setup (one-time, admin only):**
1. Generate a random token: `openssl rand -hex 32`.
2. Render dashboard → `osca-agesense` service → Environment → add `CRON_TRIGGER_TOKEN` with that value → redeploy (env var changes need a fresh deploy since `config:cache` bakes them in at deploy time).
3. GitHub repo → Settings → Secrets and variables → Actions → add a repository secret named `CRON_TRIGGER_TOKEN` with the same value.
4. Confirm `APP_TIMEZONE=Asia/Manila` is also set in Render's env vars (it's in `.env.example`/local `.env` — the 3 daily task times in `routes/console.php` assume this timezone).

**To rotate the token:** repeat steps 1-3 with a new value — both sides must match or every request 403s.

**To check it's working:** GitHub repo → Actions tab → "keep-alive" workflow → run history should show green ticks every 10 minutes during the window (or trigger one manually via "Run workflow" / `workflow_dispatch`). A 200 response body includes `schedule_output`/`queue_output` JSON fields showing what actually ran.

---

## Related Documents

- [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) — Artifact validation, service startup, prediction paths
- [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md) — DB export, import, and shared MySQL setup
- [GIT_WORKFLOW.md](GIT_WORKFLOW.md) — Branching, commits, and pull requests
- [README.md](README.md) — Documentation index
