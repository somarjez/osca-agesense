# Deployment Guide — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Audience:** System administrators and developers setting up the system for the first time or deploying to a new environment.
> **Last Updated:** 2026-05-03

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
10. [Production Deployment Checklist](#10-production-deployment-checklist)
11. [Common Setup Errors](#11-common-setup-errors)

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
| Python | 3.10 or higher |
| Git | 2.x |
| RAM | 4 GB minimum, 8 GB recommended |
| Disk | 2 GB free (models + database + app) |

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
├── app/                    Laravel application layer
│   ├── Http/Controllers/   Route controllers
│   ├── Livewire/           Livewire components (dashboard, reports, forms)
│   ├── Models/             Eloquent models
│   └── Services/           MlService, ClusterAnalyticsService
├── database/
│   ├── migrations/         Database schema definitions
│   └── seeders/            OscaCsvSeeder (bulk import)
├── docs/                   This documentation
├── python/
│   ├── services/           preprocess_service.py, inference_service.py, local_ml_runner.py
│   ├── tests/              test_ml_pipeline.py
│   ├── venv/               Python virtual environment (not committed)
│   └── start_services.ps1  Windows startup script
├── resources/
│   ├── js/                 Alpine.js + Chart.js frontend
│   └── views/              Blade templates
├── routes/                 web.php, seniors.php, surveys.php, ml.php, reports.php, recommendations.php
├── storage/
│   └── app/ml_models/      Trained model artefacts (.pkl, .json)
└── .env                    Environment configuration (not committed)
```

---

## 3. Installation — Development (Windows)

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

For development with hot reload:
```powershell
npm run dev
```

### Step 4 — Copy and configure environment file

```powershell
copy .env.example .env
php artisan key:generate
```

Edit `.env` — at minimum set the database credentials (see Section 4).

### Step 5 — Create storage symlink

```powershell
php artisan storage:link
```

### Step 6 — Run database migrations

```powershell
php artisan migrate
```

### Step 7 — Set up Python virtual environment

```powershell
cd python
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
cd ..
```

### Step 8 — Start the application

```powershell
php artisan serve
```

The application opens at `http://127.0.0.1:8000`. The Python services start automatically when using `php artisan serve` via the custom `ServeCommand`.

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
| `DB_DATABASE` | `osca_agesense` | Database name |
| `DB_USERNAME` | `root` | Database username |
| `DB_PASSWORD` | `yourpassword` | Database password |

### Session and Cache

| Variable | Recommended | Description |
|---|---|---|
| `SESSION_DRIVER` | `database` | Stores sessions in the database |
| `SESSION_LIFETIME` | `120` | Session expiry in minutes |
| `CACHE_STORE` | `file` | Cache driver |

### ML Services

| Variable | Default | Description |
|---|---|---|
| `PYTHON_SERVICE_URL` | `http://127.0.0.1` | Base URL for Python microservices |
| `ML_PREPROCESS_PORT` | `5001` | Preprocessor service port |
| `ML_INFERENCE_PORT` | `5002` | Inference service port |
| `ML_MODELS_PATH` | *(auto)* | Path to `.pkl` model artefacts directory |
| `ENABLE_NOTEBOOK_OVERRIDES` | `false` | Use notebook predictions instead of live models — keep `false` in production |

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
CREATE DATABASE osca_agesense CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'osca_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON osca_agesense.* TO 'osca_user'@'localhost';
FLUSH PRIVILEGES;
```

### Run migrations

```powershell
php artisan migrate
```

This creates all tables: `users`, `senior_citizens`, `qol_surveys`, `ml_results`, `recommendations`, `activity_logs`, `cluster_snapshots`, `jobs`, `sessions`, etc.

### Reset and re-seed (development only)

```powershell
php artisan migrate:fresh
php artisan db:seed   # requires osca.csv at ../osca.csv
```

> **Warning:** `migrate:fresh` drops all tables. Never run on a production database with live data.

---

## 6. Python ML Services Setup

### Virtual environment

```powershell
cd python
python -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
```

### Start services manually

```powershell
# PowerShell — start both services
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

The trained `.pkl` files must be present in `storage/app/ml_models/`. If they are missing, the system falls back to Tier 2 (local subprocess) or Tier 3 (PHP heuristic). Verify with:

```powershell
dir storage\app\ml_models\
```

Expected files: `scaler.pkl`, `umap_nd.pkl`, `kmeans.pkl`, `gbr_ic_risk.pkl`, `gbr_env_risk.pkl`, `gbr_func_risk.pkl`, `gbr_composite_risk.pkl`, `rfr_ic_risk.pkl`, `rfr_env_risk.pkl`, `rfr_func_risk.pkl`, `rfr_composite_risk.pkl`, `edu_encoder.pkl`, `income_encoder.pkl`, `feature_list.json`, `cluster_mapping.json`, `asset_weights.json`.

---

## 7. Running the Application

### Development

```powershell
# Terminal 1 — Laravel + auto-starts Python services
php artisan serve

# Terminal 2 — Vite hot reload (optional, for frontend development)
npm run dev
```

### Production (Ubuntu/nginx)

1. Build frontend assets:
   ```bash
   npm run build
   ```

2. Configure nginx to point to `/public`:
   ```nginx
   server {
       listen 80;
       server_name yourdomain.com;
       root /var/www/osca-agesense/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
       }
   }
   ```

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
   # /etc/systemd/system/osca-preprocess.service
   [Service]
   ExecStart=/var/www/osca-agesense/python/venv/bin/python /var/www/osca-agesense/python/services/preprocess_service.py
   Restart=always
   User=www-data
   ```

---

## 8. First Login and Default Credentials

A default admin account is automatically created when the `users` table is empty:

| Field | Value |
|---|---|
| Email | `admin@osca.local` |
| Password | `password` |

**Change this password immediately after first login.** The system has no UI for user management — use Laravel Tinker or a direct database update:

```powershell
php artisan tinker
>>> App\Models\User::first()->update(['password' => bcrypt('new-secure-password')]);
```

---

## 9. Loading Existing Data

### CSV bulk import

If you have an existing OSCA registry in CSV format (`osca.csv`), place it one directory above the project root (`../osca.csv`) and run:

```powershell
php artisan db:seed --class=OscaCsvSeeder
```

This creates senior profiles, QoL surveys, computes domain scores, and runs the full ML pipeline for each imported record.

Refer to the seeder source (`database/seeders/OscaCsvSeeder.php`) for the expected CSV column headers.

### Manual entry

Register seniors one at a time via **Senior Records → New Profile** in the web interface.

---

## 10. Production Deployment Checklist

Before going live with real data:

- [ ] Change default admin password (`admin@osca.local` / `password`)
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Confirm `SESSION_DRIVER=database` and sessions table exists
- [ ] Confirm ML model artefacts are present in `storage/app/ml_models/`
- [ ] Verify Python services start on boot (systemd or equivalent)
- [ ] Set up automated database backups (daily minimum)
- [ ] Review and configure `MAIL_MAILER` for notifications
- [ ] Set `ENABLE_NOTEBOOK_OVERRIDES=false`
- [ ] Implement role-based access control before multi-staff deployment (see SYSTEM_FUNCTIONALITY.md §17)
- [ ] Review Philippine Data Privacy Act compliance (see SYSTEM_FUNCTIONALITY.md §16)
- [ ] Configure HTTPS (TLS certificate — Let's Encrypt recommended for production)
- [ ] Test all three ML fallback tiers on the target server

---

## 11. Common Setup Errors

| Error | Cause | Fix |
|---|---|---|
| `php artisan migrate` fails with "Access denied" | Wrong DB credentials | Check `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST` in `.env` |
| `composer install` fails with PHP version error | PHP < 8.2 installed | Install PHP 8.2+ and confirm `php --version` |
| Python services show "Offline" in the dashboard | Services not started | Run `.\python\start_services.ps1` in a separate terminal |
| `WinError 10106` in Python service logs | Numba socket conflict on Windows | The `ServeCommand` sets `NUMBA_DISABLE_JIT=0` and threading overrides automatically; restart the service |
| `UMAP` import error on Python startup | Missing packages | Re-run `pip install -r python/requirements.txt` with venv activated |
| Git `index.lock` error during branch switch | Another git process is running | Delete `.git/index.lock` manually: `Remove-Item .git\index.lock -Force` |
| Storage directory deletion fails during `git switch` | Windows file lock (IDE or PHP server has cache directory open) | Close IDE file watchers or stop `php artisan serve`, then switch branches |
| `Class 'App\Http\Controllers\HelpController' not found` | Composer autoload cache stale | Run `composer dump-autoload` |
| Assets not loading (404 on `/build/`) | Vite build not run | Run `npm run build` |
| `npm run build` fails | Node modules not installed | Run `npm install` first |
