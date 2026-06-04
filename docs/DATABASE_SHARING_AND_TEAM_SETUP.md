# AgeSense — Database Sharing and Team Setup Guide

> **One source of truth.**
> All official results for the thesis defense must come from either:
> (a) a shared remote MySQL database that all devices connect to, or
> (b) a validated database dump imported identically on every device.
>
> Do not let each member work from a separate local database and compare results.

---

## Official Validated Seed Result

Before any sharing, the main device must confirm these exact numbers:

| Metric | Expected |
|---|---|
| Total active seniors | 283 |
| QoL Surveyed | 283 |
| Risk — HIGH | 55 |
| Risk — MODERATE | 191 |
| Risk — LOW | 37 |
| Cluster C1 — High Functioning / Well-Supported Seniors | 60 |
| Cluster C2 — Stable Ageing / Moderate Support Needs | 84 |
| Cluster C3 — Environmentally and Financially Vulnerable Seniors | 74 |
| Cluster C4 — Low Functioning / Multi-Domain Priority Seniors | 65 |
| Prediction Source | Notebook-Validated Cache: 283 |
| Model Version | 2.0.0 |

---

## Understanding Prediction Sources

| Source | When it applies | Official for defense? |
|---|---|---|
| `notebook_cache` | Original 283 seed seniors matched in `senior_predictions.csv` | **Yes** |
| `live_model` | New seniors not found in the CSV — scored by GBR/RFR pipeline | **Yes** |
| `fallback` | Python ML service was unavailable when scoring ran | No — recompute when service is healthy |

---

## The Two Correct Workflows

### A — Recommended: Shared Remote MySQL

```
All devices → same Laravel codebase → same remote MySQL → same ml_results
```

- Any senior added on one device appears on all others immediately.
- ML results are saved once and read by every device — no recomputation.
- All dashboards show identical totals, risk distribution, cluster distribution,
  prediction source counts, and critical flag count.
- This is the preferred setup for the final defense.

### B — Backup/Offline: Validated Database Dump

```
Main device (validated) → export SQL dump → other devices (clean DB) → import dump
```

- Export the dump only after the main device shows the correct validated result.
- Import into a **clean** database — drop any old data first.
- Once imported, the other device has an exact copy of the main validated database.
- No one should manually re-enter the same official data after importing.

---

## What NOT To Do

- **Do not** let each member manually add the same official senior records into separate local databases — this causes duplicate records, different IDs, and different dashboard counts.
- **Do not** compare official results taken from different local databases that were set up independently.
- **Do not** import a dump on top of an existing old database without dropping it first — you will get mixed/duplicate data.
- **Do not** commit real SQL dumps to GitHub — they contain personal senior citizen data.
- **Do not** treat fallback results as official — recompute when the ML service is healthy.
- **Do not** recompute ML results on every dashboard load — the dashboard reads from saved `ml_results`.
- **Do not** re-fit UMAP in production inference — the system uses `transform()` only from the exported `umap_reducer.pkl`.

---

## MAIN DEVICE: Preparing the Validated Database

Run these steps on the laptop that holds the validated data before exporting.

### Step 1 — Pull latest code
```powershell
git pull
```

### Step 2 — Run migrations
```powershell
php artisan migrate --force
```

### Step 3 — Clear caches
```powershell
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Step 4 — Verify the dashboard shows the correct result

Open the app in a browser and confirm:

```
Total Seniors   : 283
QoL Surveyed    : 283

Risk Distribution:
  LOW           : 37
  MODERATE      : 191
  HIGH          : 55

Health Groups:
  C1 High Functioning / Well-Supported Seniors          : 60
  C2 Stable Ageing / Moderate Support Needs             : 84
  C3 Environmentally and Financially Vulnerable Seniors : 74
  C4 Low Functioning / Multi-Domain Priority Seniors    : 65

Prediction Source Summary:
  Notebook-Validated Cache : 283
  Live ML Model            : 0
  Fallback                 : 0
```

### Step 5 — Run the validation script

Install pymysql if not yet installed:
```powershell
python\venv\Scripts\pip.exe install pymysql
```

Run the script:
```powershell
python\venv\Scripts\python.exe python/check_prediction_sources.py
```

Expected final line:
```
OVERALL VALIDATION: PASS
```

### Step 6 — Take screenshots

Screenshot the dashboard and the validation script output. These are your official reference for Chapter 4.

---

## MAIN DEVICE: Exporting the Validated Database Dump

Run this **only after** Step 5 above shows `OVERALL VALIDATION: PASS`.

Check your actual database name in `.env`:
```
DB_DATABASE=osca_db
```

### Option 1 — Laragon (this project uses Laragon)
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe" -u root --databases osca_db --routines --triggers --events "--result-file=database\backups\agesense_main_validated_dump.sql"
```
If your root account has a password, add `-p` before `--databases` and enter it when prompted.

### Option 2 — If `mysqldump` is available in PATH
```powershell
mysqldump -u root --databases osca_db --routines --triggers --events "--result-file=database\backups\agesense_main_validated_dump.sql"
```

### Option 3 — Standard MySQL install (non-Laragon)
```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" `
    -u root --databases osca_db --routines --triggers --events `
    "--result-file=database\backups\agesense_main_validated_dump.sql"
```

Replace `osca_db` with your actual `DB_DATABASE` value if different.

> **Important — use `--result-file`, not `>`**
> In PowerShell, using `> file.sql` (redirect operator) produces a 0-byte file because
> PowerShell intercepts the output before mysqldump can write it.
> Always use `"--result-file=database\backups\filename.sql"` as shown above.

> **The dump file will be at:** `database/backups/agesense_main_validated_dump.sql`
>
> This file contains personal senior citizen data.
> Transfer it only via USB or a private secure channel — **never via GitHub or public group chats.**

### Step — Verify the dump is not empty
```powershell
Get-Item "database\backups\agesense_main_validated_dump.sql" | Select-Object Name, @{N='Size(MB)';E={[math]::Round($_.Length/1MB,2)}}
```
Expected: **Size(MB) = 2 or more**. A 0-byte file means the export failed — re-run using `--result-file` as shown above.

---

## OTHER DEVICE: Complete Teammate Setup (8 Steps)

> **This is the standard setup order for every teammate.** Follow all 8 steps in sequence. Do not skip Steps 6–7 — they are the steps most commonly missed and are what ensures the ML pipeline produces identical results across devices.

### Step 1 — Get the latest code

```powershell
git switch main
git pull origin main
```

### Step 2 — Install dependencies

```powershell
composer install
npm install
npm run build
```

### Step 3 — Set up `.env`

Run `start.bat` once so it generates `.env` from `.env.example` and syncs any new keys, then close after the browser opens:

```
Double-click  start.bat   (close the window after the browser opens)
```

> If `.env` does not exist yet, `start.bat` creates it automatically. If it already exists, it only adds missing keys — it never overwrites values you have set.

### Step 4 — Import the dump (clean slate)

Get the dump file `agesense_defense_20260521.sql` from the main laptop via USB and place it anywhere accessible.

Open MySQL (Laragon):
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root
```

Inside MySQL, drop and recreate the database:
```sql
DROP DATABASE IF EXISTS osca_db;
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

Import the dump:
```powershell
# Replace the path with where you placed the dump file
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root osca_db < "path\to\agesense_defense_20260521.sql"
```

> **Important — PowerShell and the `<` operator:**
> The `<` redirect operator is NOT supported in PowerShell for native executables. If you get a `RedirectionNotSupported` error, use `Get-Content` instead:
> ```powershell
> Get-Content "path\to\agesense_defense_20260521.sql" | & "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root osca_db
> ```

### Step 5 — Run migrations and clear caches

```powershell
php artisan migrate
php artisan config:clear
php artisan cache:clear
```

### Step 6 — Sync ML model artifacts

Copy the `python/models/` directory from the main laptop (the same folder you sync on the main laptop). This includes all `.pkl`, `.json` files, and the `predictions/` subdirectory.

**Option A — ZIP transfer (recommended for USB):**
```powershell
# On the main laptop — create the ZIP:
Compress-Archive -Path python/models -DestinationPath osca_models_v2.0.0.zip

# On the target device — extract:
Expand-Archive -Path osca_models_v2.0.0.zip -DestinationPath . -Force
```

**Option B — Direct folder copy:**
Copy the entire `python/models/` folder from the main laptop to `osca-system/python/models/` on this device, replacing any existing files.

> After copying, confirm these settings are in `.env`:
> ```
> ML_MODELS_PATH=python/models
> ENABLE_NOTEBOOK_OVERRIDES=true
> ```

### Step 7 — Validate ML artifacts and pipeline

Run all three validation scripts in order. Each must pass before the system is ready.

```powershell
# 1. Validate all model artifact files are present and correct
python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
```
Expected: **51 PASS, 0 FAIL, 0 WARN**

```powershell
# 2. Confirm the pipeline produces identical results across two runs and between single/batch inference
python\venv\Scripts\python.exe python\scripts\test_reproducibility.py
```
Expected: **28 PASS, 0 FAIL**

```powershell
# 3. Confirm stale-result detection works correctly
python\venv\Scripts\python.exe python\scripts\test_staleness.py
```
Expected: **20 PASS, 0 FAIL**

> If any script shows FAIL: re-transfer the `python/models/` artifact bundle from the main laptop. Do not retrain models.

### Step 8 — Check the dashboard

Start the system:
```
Double-click  start.bat
```

Open `http://127.0.0.1:8000` and confirm the dashboard shows:

```
Total Seniors              : 283
Risk — HIGH                : 55
Risk — MODERATE            : 191
Risk — LOW                 : 37
Notebook-Validated Cache   : 283
```

If the numbers are wrong, confirm `ENABLE_NOTEBOOK_OVERRIDES=true` in `.env`, then run `stop.bat` → `start.bat` to restart the Flask services.

---

> **Why Steps 6–7 matter:** The database dump gives you the correct senior records and ML result rows, but the Flask services need the matching `.pkl` model files to score any new senior added after the dump. Steps 6–7 confirm the artifact bundle is intact and reproducible — skipping them is the most common cause of wrong results across devices.

---

## SHARED REMOTE MYSQL SETUP FOR FINAL DEFENSE

This is the preferred setup for the final defense. All three devices use one shared database — no syncing needed.

> **Laragon note:** `mysql` and `mysqldump` are NOT in PATH on Laragon installs.
> Always use the full binary path: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`

### Step 1 — Choose the host device

Pick one laptop that will stay on during the defense. This laptop's MySQL becomes the shared server.

### Step 2 — Import the validated dump on the host

Follow the "OTHER DEVICE: Importing" steps above on the host laptop, then confirm:
```powershell
python\venv\Scripts\python.exe python/check_prediction_sources.py
# Must show: OVERALL VALIDATION: PASS
```

### Step 3 — Find the host laptop's IP address
```powershell
ipconfig
```
Look for **IPv4 Address** under your WiFi adapter — for example, `192.168.1.100`.
All devices must be on the **same WiFi network**.

### Step 4 — Create a MySQL remote user on the host

Open MySQL on the host laptop:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root
```

Run:
```sql
CREATE USER 'agesense_user'@'%' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON osca_db.* TO 'agesense_user'@'%';
FLUSH PRIVILEGES;
EXIT;
```

Replace `strong_password_here` with a real password. Write it down — all members need it.

### Step 5 — Allow MySQL to accept remote connections

**Laragon's `my.ini` has no `bind-address` line** — this means MySQL is already listening on all interfaces by default. No changes to `my.ini` are needed.

> If you want to verify: open `C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini` in Notepad. If there is no `bind-address` line under `[mysqld]`, you are good — skip ahead to the firewall step.

**Open port 3306 in Windows Firewall** (PowerShell as Administrator on host):
```powershell
New-NetFirewallRule -DisplayName "MySQL Remote Access" `
    -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Allow
```

### Step 6 — Update `.env` on every other device

```
DB_CONNECTION=mysql
DB_HOST=192.168.1.100
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=agesense_user
DB_PASSWORD=strong_password_here
```

Replace `192.168.1.100` with the actual host laptop IP.

Then run on each client device:
```powershell
php artisan config:clear
php artisan cache:clear
```

Also restart Flask inference service on client devices so it reconnects to the new DB host.

### Step 7 — Verify all devices show the same dashboard

Open the Analysis Services page on each device. The **Database & System Status** panel shows:
- DB Host (should all show the same IP)
- DB Database (should all show `osca_db`)
- Notebook-Validated Cache count (should all show 283)
- Model Version (should all show 2.0.0)

All dashboards should show identical results.

---

## CAN MEMBERS STILL USE THEIR OWN LOCAL DATABASE?

Yes — but only for development and testing. Local database results are not official unless the local database was imported from the same validated dump.

| Setup | Use case | Official result? |
|---|---|---|
| Shared remote MySQL | Final defense / live demo | **Yes** |
| Same imported validated dump | Offline backup / demo | **Yes** |
| Separate local DB (own seeding) | Coding / testing only | No |
| Manually re-entered same data in separate DBs | Not recommended | No |

---

## Troubleshooting

### Running batch analysis changes results on the teammate device

**Symptom:** After importing the same dump, the teammate device runs batch analysis and ends up with different risk or cluster values from the main device.

**Root cause — two separate bugs (both now fixed):**

1. **`runBatchPipeline()` did not reuse valid rows.** The UI batch button dispatches `ProcessMlBatch` jobs, which called `runBatchPipeline()`. That method previously sent every senior to Python regardless of whether a valid result already existed. `live_model` rows were recomputed through UMAP on the teammate's machine, producing different cluster assignments if artifacts differed even slightly.

2. **`RecalculateClusters` overwrote `notebook_cache` rows.** After every UI batch completes, `RecalculateClusters` dispatches `fix_cluster_distribution.py`, which re-ran UMAP batch transform across **all seniors** and wrote the results back — including `notebook_cache` rows. This destroyed the validated notebook baseline on the teammate device every time the UI batch button was pressed.

**Fixes applied:**
- `MlService::runBatchPipeline()` now calls `findReusableResult()` first. Valid `notebook_cache` and `live_model` rows are returned as-is without touching Python.
- `MlService::runRecluster()` now skips the entire recluster subprocess when all active rows are `notebook_cache` — a no-op for the normal defense state.
- `fix_cluster_distribution.py` now explicitly protects `notebook_cache` rows from being overwritten even when the script does run (for the case where `live_model` rows genuinely need reclustering after new seniors are added).

**Rule going forward:**
- After a clean dump import, running the UI batch button should be a no-op — all rows are valid and will be reused.
- After adding new seniors and running their analysis, `RecalculateClusters` will recluster only the `live_model` rows, not the `notebook_cache` baseline.
- Do not use `--force` or `php artisan ml:batch-analyze --force` for cross-device consistency tests.

---

### Dashboard counts are different from the main device

**How to tell which problem it is:**
- Dashboard shows `DB: 127.0.0.1:osca_db` → device is still reading its own local database, not the shared one. Fix the `.env` first (see below).
- Dashboard shows the correct `DB_HOST` IP but wrong counts → dump was imported on top of old data. Do a clean reimport.

**Possible causes:**
- `DB_HOST` is still `127.0.0.1` — device never switched to shared MySQL
- Typo in `DB_HOST` (e.g. `192.168.1.4s` instead of `192.168.1.4`)
- Config cache not cleared after editing `.env` — Laravel is still reading the old value
- Dump was imported on top of an old database — mixed/duplicate records
- `ml_results` rows missing (migration not run)

**Fix — wrong DB_HOST (device reading its own local DB):**
```
DB_HOST=<host-laptop-IP>
DB_USERNAME=agesense_user
DB_PASSWORD=osca_2026_pass
```
Then:
```powershell
php artisan config:clear
php artisan cache:clear
```
Restart `php artisan serve` and confirm the dashboard shows `DB: <host-IP>:osca_db`.

**Fix — dump imported on top of old data (mixed records):**

Always drop the database first — never import on top of existing data.

```powershell
# Step 1 — Open MySQL and drop the old database
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root
```
```sql
DROP DATABASE IF EXISTS osca_db;
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```
```powershell
# Step 2 — Reimport the validated dump
Get-Content "database\backups\agesense_main_validated_dump.sql" | & "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root

# Step 3 — Clear caches and run migrations
php artisan config:clear
php artisan cache:clear
php artisan migrate --force
```

---

### Other device cannot connect to shared MySQL

**Possible causes:**
- Wrong `DB_HOST` — double-check `ipconfig` on the host laptop (typos like `192.168.1.4s` are easy to miss)
- Config cache not cleared after editing `.env` — run `php artisan config:clear`
- Firewall rule not created on the host — see Step 5
- Wrong username or password (`agesense_user` / the password you set in Step 4)

**Fix:**

Test the connection directly from the client device:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -h 192.168.1.100 -u agesense_user -p
```
If this fails, the problem is network/firewall — not the app.

Check host MySQL firewall rule:
```powershell
Get-NetFirewallRule -DisplayName "MySQL Remote Access"
```

Check `my.ini` on host (`C:\laragon\bin\mysql\mysql-8.4.3-winx64\my.ini`) — if there is no `bind-address` line, MySQL is already listening on all interfaces (Laragon default). No change needed.

---

### Prediction source shows Fallback

**Possible causes:**
- Flask/Python ML service not running
- Model `.pkl` files missing from `python/models/`
- Python venv not set up

**Fix:**
```powershell
# Install Python dependencies
python\venv\Scripts\pip.exe install -r python\requirements.txt

# Start ML services
cd python
.\start_services.ps1
```

Then re-run the assessment only after the Analysis Services page shows all services Online.

---

### New senior result differs across separate local databases

**Explanation:** Each local database has a different population in `ml_results`. UMAP cluster assignment is sensitive to population size, so a new senior scored on Database A may get a different cluster than the same senior scored on Database B. The risk score (GBR/RFR) will be the same, but the cluster may differ.

**Fix:** Use shared MySQL or import the same validated dump before adding new seniors so the population is identical.

---

## Privacy and Security

- Database dumps contain personal identifiable information (PII): full names, dates of birth, barangay, health concerns, income data, family composition.
- **Do not upload real dumps to GitHub.** Dump files are gitignored via `database/backups/*.sql`.
- **Do not send dumps through public group chats or unencrypted email.**
- Transfer dump files only via USB or a password-protected private channel within the team.
- Do not expose MySQL passwords in screenshots, documents, or presentations.
- Delete dump files from devices after the defense if no longer needed.
- If demoing outside the team or to evaluators, use only the app interface — do not share the raw SQL file.

---

## Quick Reference: Commands at a Glance

### Main device — validate and export dump (Laragon)
```powershell
# Validate prediction sources
python\venv\Scripts\pip.exe install pymysql
python\venv\Scripts\python.exe python/check_prediction_sources.py
# Expected: OVERALL VALIDATION: PASS

# Export dump
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe" -u root --databases osca_db --routines --triggers --events "--result-file=database\backups\agesense_defense_20260521.sql"
```

### Other device — complete 8-step setup
```powershell
# Step 1 — Get code
git switch main
git pull origin main

# Step 2 — Install dependencies
composer install
npm install
npm run build

# Step 3 — Set up .env (run start.bat once, close after browser opens)
.\start.bat

# Step 4 — Import dump (clean slate)
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root
# DROP DATABASE IF EXISTS osca_db;
# CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
# EXIT;
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root osca_db < "path\to\agesense_defense_20260521.sql"
# (if < fails in PS, use: Get-Content "path\to\dump.sql" | & "...mysql.exe" -u root osca_db)

# Step 5 — Migrations and cache clear
php artisan migrate
php artisan config:clear
php artisan cache:clear

# Step 6 — Sync ML artifacts (copy python/models/ from main laptop, same as you do on main laptop)
# ZIP option: Expand-Archive -Path osca_models_v2.0.0.zip -DestinationPath . -Force

# Step 7 — Validate ML pipeline (all three must pass)
python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
# Expected: 51 PASS, 0 FAIL, 0 WARN
python\venv\Scripts\python.exe python\scripts\test_reproducibility.py
# Expected: 28 PASS, 0 FAIL
python\venv\Scripts\python.exe python\scripts\test_staleness.py
# Expected: 20 PASS, 0 FAIL

# Step 8 — Start and verify dashboard
.\start.bat
# Expected: Total=283, HIGH=55, MODERATE=191, LOW=37, Notebook-Validated Cache: 283
```

### Shared MySQL — client device `.env`
```
DB_HOST=<host-laptop-IP>
DB_DATABASE=osca_db
DB_USERNAME=agesense_user
DB_PASSWORD=strong_password_here
```
Then: `php artisan config:clear` and `php artisan cache:clear`
