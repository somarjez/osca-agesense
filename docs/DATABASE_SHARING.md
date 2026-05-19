# AgeSense — Database Sharing & Dump Guide

This guide covers two approaches for keeping all three team devices in sync:

| Approach | Best for | Requires |
|---|---|---|
| **Shared Remote MySQL** | Final defense, live demo | Same WiFi/LAN |
| **Database Dump Import** | Offline, backup, travel | USB / file transfer |

---

## Official Validated Seed Result

Before sharing the database, verify the main laptop shows exactly:

| Metric | Expected |
|---|---|
| Total active seniors | 283 |
| Risk — HIGH | 54 |
| Risk — MODERATE | 191 |
| Risk — LOW | 38 |
| Critical flag | 1 (HIGH + composite ≥ 0.70) |
| Cluster C1 — High Functioning | 75 |
| Cluster C2 — Moderate / Mixed Needs | 132 |
| Cluster C3 — Low Functioning / Multi-domain Risk | 76 |
| Prediction source | Notebook-Validated Cache: 283 |

Run the validation script to confirm:
```powershell
python python/check_prediction_sources.py
```
Expected last line: `OVERALL VALIDATION: PASS`

---

## A — Export / Dump the Main Validated Database

Run this on the **main laptop only**, after confirming the result above.

### Basic export (PowerShell)
```powershell
mysqldump -u root -p --databases osca_db --routines --triggers --events > database\backups\agesense_main_validated_dump.sql
```

### If `mysqldump` is not on PATH
```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" `
    -u root -p --databases osca_db --routines --triggers --events `
    > database\backups\agesense_main_validated_dump.sql
```

Replace `osca_db` with whatever `DB_DATABASE` is in your `.env` if different.

### What the dump contains
- All table structure (`CREATE TABLE`)
- All 283 senior records
- All QoL survey data
- All `ml_results` (prediction_source, scores, clusters)
- All recommendations

### Important
- The dump file contains **personal senior citizen data** — treat it as confidential.
- **Do not commit it to GitHub.** It is gitignored via `database/backups/*.sql`.
- Share only within the team via USB or a private secure channel.

---

## B — Import the Dump on Another Device

### Pre-import checklist
- [ ] `git pull` to get the latest code
- [ ] `.env` is configured (copy from `.env.example`)
- [ ] MySQL is running on this device
- [ ] Back up any existing local data you want to keep

### Option 1 — Simple import (dump includes `CREATE DATABASE`)
```powershell
mysql -u root -p < database\backups\agesense_main_validated_dump.sql
```

If `mysql` is not on PATH:
```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p `
    < database\backups\agesense_main_validated_dump.sql
```

### Option 2 — Drop and recreate first (clean slate)
```powershell
mysql -u root -p
```
Inside MySQL:
```sql
DROP DATABASE IF EXISTS osca_db;
CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```
Then import:
```powershell
mysql -u root -p osca_db < database\backups\agesense_main_validated_dump.sql
```

### Post-import Laravel commands
```powershell
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan migrate --force
```

### Post-import frontend (if needed)
```powershell
npm install
npm run build
```

### Post-import Python ML services
```powershell
cd python
.\venv\Scripts\activate
pip install -r requirements.txt
```
Then start services as usual (see `docs/ML_PIPELINE.md`).

---

## C — Shared Remote MySQL Setup (Recommended for Defense)

This gives all three devices a **single live database** with no syncing required.

### Step 1 — Find the host laptop's IP
On the host laptop (PowerShell):
```powershell
ipconfig
```
Look for **IPv4 Address** under the WiFi adapter (e.g., `192.168.1.100`).

### Step 2 — Import the validated dump on the host
Follow section B above to import `agesense_main_validated_dump.sql` into the host's MySQL.

### Step 3 — Create a remote MySQL user on the host
Open MySQL on the host laptop:
```sql
CREATE USER 'osca_user'@'%' IDENTIFIED BY 'StrongPass2024!';
GRANT ALL PRIVILEGES ON osca_db.* TO 'osca_user'@'%';
FLUSH PRIVILEGES;
```

### Step 4 — Allow MySQL through Windows Firewall (host laptop, run as Administrator)
```powershell
New-NetFirewallRule -DisplayName "MySQL Remote Access" `
    -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Allow
```

### Step 5 — Allow remote connections in MySQL config
Find `my.ini` (usually `C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`) and set:
```ini
bind-address = 0.0.0.0
```
Then restart MySQL:
```powershell
Restart-Service -Name "MySQL*"
```

### Step 6 — Update `.env` on the two client devices
```
DB_HOST=192.168.1.100
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=osca_user
DB_PASSWORD=StrongPass2024!
```
Then:
```powershell
php artisan config:clear
php artisan cache:clear
```
And restart Flask inference service on those devices.

### Step 7 — Verify all devices show the same dashboard
Open the dashboard on each device and confirm:
- Same total seniors
- Same Risk Distribution (HIGH=54, MODERATE=191, LOW=38)
- Same Health Groups (C1=75, C2=132, C3=76)
- Same Prediction Source Summary (Notebook-Validated Cache: 283)
- Same Model Version
- Same DB host shown in the info strip

---

## D — Verification Checklist After Import or Shared DB Setup

### 1. Check DB connection (Laravel Tinker)
```powershell
php artisan tinker
```
```php
DB::connection()->getDatabaseName();
```
Expected: `osca_db`

### 2. Run the ML validation script
```powershell
python python/check_prediction_sources.py
```
Expected output:
```
  PREDICTION SOURCE BREAKDOWN
    Notebook-Validated Cache : 283
    Live ML Model            : 0
    Heuristic Fallback       : 0

  RISK INDICATOR DISTRIBUTION
    HIGH                     : 54   (expected 54)
    MODERATE                 : 191  (expected 191)
    LOW                      : 38   (expected 38)
    Total                    : 283  [PASS]

  CLUSTER DISTRIBUTION
    C1 High Functioning      : 75   (expected 75)
    C2 Moderate / Mixed Needs: 132  (expected 132)
    C3 Low Functioning ...   : 76   (expected 76)
    Cluster check            : [PASS]

  OVERALL VALIDATION: PASS
```

### 3. Check via Tinker
```php
// Active seniors
App\Models\SeniorCitizen::active()->count();              // 283

// ML results
App\Models\MlResult::count();                            // ≥ 283

// Prediction sources
App\Models\MlResult::groupBy('prediction_source')
    ->selectRaw('prediction_source, COUNT(*) as cnt')
    ->pluck('cnt','prediction_source');
// ['notebook_cache' => 283]

// Risk distribution
App\Models\MlResult::groupBy('overall_risk_level')
    ->selectRaw('overall_risk_level, COUNT(*) as cnt')
    ->pluck('cnt','overall_risk_level');
// ['HIGH'=>54, 'MODERATE'=>191, 'LOW'=>38]

// Critical flag
App\Models\MlResult::where('critical_flag', true)->count(); // 1
```

---

## E — Team Workflow Rules

### Official workflow (for thesis defense screenshots and Chapter 4)
1. Main laptop validates and confirms the correct output.
2. Main laptop exports `agesense_main_validated_dump.sql`.
3. All devices either:
   - Connect to the shared remote MySQL on the host laptop, **or**
   - Import the exact same dump into their local MySQL.
4. All official screenshots, counts, and results must come from the shared DB or the validated import.
5. Do not run a fresh batch recompute on the defense day unless intentional.

### Development workflow (for coding and testing only)
- Members may use their own local database during development.
- Local results should not be treated as official.
- Local recomputation, especially UMAP, may produce slightly different cluster assignments for new seniors.
- Before defense, reset to the shared DB or import the validated dump.

### Adding new seniors during defense
- If using shared MySQL: add the senior on any device — it appears on all others immediately.
- The new senior will be scored by the **Live ML Model** (not notebook cache), demonstrating the live pipeline.
- The prediction source badge on that senior's profile will show **"Live ML Model"**.

---

## F — Data Privacy Warning

Senior citizen records in AgeSense contain personal identifiable information (PII):
- Full name, date of birth, barangay
- Health concerns, income data, family composition

**Rules for the dump file:**
- Do not commit to GitHub (gitignored via `database/backups/*.sql`).
- Do not share through public channels (email, group chats, cloud storage without password).
- Transfer only via USB or a password-protected private channel within the team.
- If demoing outside the team, use anonymized test data.
- Delete dump files from devices after the defense.
