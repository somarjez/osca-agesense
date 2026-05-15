#Requires -Version 5.1
$ErrorActionPreference = 'Continue'
$PROJECT = Split-Path -Parent $MyInvocation.MyCommand.Path

function Bail($msg) {
    Write-Host ""
    Write-Host " [FAIL] $msg"
    Write-Host ""
    Read-Host " Press Enter to exit"
    exit 1
}

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - First-Time Setup"
Write-Host " =========================================="
Write-Host ""
Write-Host "  This script will:"
Write-Host "    1.  Check all prerequisites"
Write-Host "    2.  Install PHP (Composer) dependencies"
Write-Host "    3.  Install Node.js dependencies"
Write-Host "    4.  Create and configure .env"
Write-Host "    5.  Generate application key"
Write-Host "    6.  Run database migrations"
Write-Host "    7.  Seed sample data (if osca.csv is present)"
Write-Host "    8.  Build frontend assets"
Write-Host "    9.  Create Python virtual environment"
Write-Host "   10.  Install Python ML dependencies"
Write-Host "   11.  Sync ML model files from osca_output"
Write-Host ""
Write-Host "  Estimated time: 5-15 minutes (depending on internet speed)"
Write-Host ""
Read-Host " Press Enter to begin"

# ── STEP 0: Check prerequisites ────────────────────────────────────────────────
Write-Host ""
Write-Host " -- Checking prerequisites --"
Write-Host ""

$errors = 0

# PHP
$PHP = $null
$phpOnPath = Get-Command php -ErrorAction SilentlyContinue
if ($phpOnPath) { $PHP = $phpOnPath.Source }

if (-not $PHP) {
    foreach ($base in @("$env:USERPROFILE\laragon\bin\php", "C:\laragon\bin\php")) {
        if (Test-Path $base) {
            $found = Get-ChildItem "$base\php*" -Directory -ErrorAction SilentlyContinue |
                     Where-Object { Test-Path "$($_.FullName)\php.exe" } |
                     Sort-Object Name -Descending | Select-Object -First 1
            if ($found) { $PHP = "$($found.FullName)\php.exe"; break }
            if (Test-Path "$base\php.exe") { $PHP = "$base\php.exe"; break }
        }
    }
}
if (-not $PHP) {
    foreach ($p in @("C:\xampp\php\php.exe", "D:\xampp\php\php.exe")) {
        if (Test-Path $p) { $PHP = $p; break }
    }
}

if (-not $PHP) {
    Write-Host " [FAIL] php not found on PATH or in Laragon/XAMPP."
    Write-Host "        Install Laragon from https://laragon.org/"
    $errors++
} else {
    Write-Host " [ OK ] PHP found: $PHP"
}

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    Write-Host " [FAIL] Composer not found. Install from https://getcomposer.org/download/"
    $errors++
} else {
    Write-Host " [ OK ] Composer found"
}

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Host " [FAIL] Node.js not found. Install from https://nodejs.org/"
    $errors++
} else {
    Write-Host " [ OK ] Node.js found"
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    Write-Host " [FAIL] npm not found."
    $errors++
} else {
    Write-Host " [ OK ] npm found"
}

$PYTHON = $null
$pyCmd = Get-Command python -ErrorAction SilentlyContinue
if ($pyCmd) { $PYTHON = $pyCmd.Source }
else {
    $py3Cmd = Get-Command python3 -ErrorAction SilentlyContinue
    if ($py3Cmd) { $PYTHON = $py3Cmd.Source }
}
if (-not $PYTHON) {
    Write-Host " [FAIL] Python not found. Install Python 3.10+ from https://www.python.org/"
    Write-Host "        Make sure to check 'Add Python to PATH' during installation."
    $errors++
} else {
    Write-Host " [ OK ] Python found: $PYTHON"
}

if ($errors -gt 0) {
    Write-Host ""
    Write-Host " [!!] $errors prerequisite(s) missing. Install them and re-run setup.bat."
    Read-Host " Press Enter to exit"
    exit 1
}

Write-Host ""
Write-Host " All prerequisites found. Continuing..."
Write-Host ""

Set-Location -LiteralPath $PROJECT

# ── STEP 1: PHP dependencies ────────────────────────────────────────────────────
Write-Host " -- [1/8] Installing PHP dependencies (composer install) --"
& composer install --no-interaction --prefer-dist 2>&1 | Write-Host
Write-Host " [ OK ] PHP dependencies installed."
Write-Host ""

# ── STEP 2: Node.js dependencies ────────────────────────────────────────────────
Write-Host " -- [2/8] Installing Node.js dependencies (npm install) --"
& npm install --no-fund --no-audit 2>&1 | Write-Host
Write-Host " [ OK ] Node.js dependencies installed."
Write-Host ""

# ── STEP 3: Environment file ────────────────────────────────────────────────────
Write-Host " -- [3/8] Configuring environment --"
if (-not (Test-Path "$PROJECT\.env")) {
    Copy-Item "$PROJECT\.env.example" "$PROJECT\.env"
    Write-Host " [ OK ] .env created from .env.example."
} else {
    Write-Host " [ OK ] .env already exists - checking for new keys..."
    $example = Get-Content "$PROJECT\.env.example" | Where-Object { $_ -match '^[A-Z_]+=' }
    $current = Get-Content "$PROJECT\.env"
    $added = 0
    foreach ($line in $example) {
        $key = $line -replace '=.*', ''
        if (-not ($current -match "^$([regex]::Escape($key))=")) {
            Add-Content "$PROJECT\.env" $line
            Write-Host "  [ADDED] $key"
            $added++
        }
    }
    if ($added -eq 0) { Write-Host "  [ OK ] .env is up to date." }
    else { Write-Host "  [ OK ] Added $added new key(s) from .env.example." }
}

& $PHP "$PROJECT\artisan" key:generate --ansi
Write-Host ""

# ── STEP 4: Database ────────────────────────────────────────────────────────────
Write-Host " -- [4/8] Setting up database --"
Write-Host ""
Write-Host " The system uses MySQL by default."
Write-Host " Your .env file contains:"
Write-Host ""
Get-Content "$PROJECT\.env" | Where-Object { $_ -match '^DB_(CONNECTION|HOST|DATABASE|USERNAME)=' } | ForEach-Object { Write-Host "   $_" }
Write-Host ""
Write-Host " Make sure MySQL is running and the database exists."
Write-Host " To create the database:"
Write-Host '   mysql -u root -p -e "CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
Write-Host ""
Read-Host " Press Enter to run migrations (or Ctrl+C to cancel and edit .env first)"

& $PHP "$PROJECT\artisan" migrate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host " [FAIL] Migrations failed. Common causes:"
    Write-Host "   - MySQL not running (start Laragon first)"
    Write-Host "   - Wrong DB credentials in .env"
    Write-Host "   - Database does not exist yet"
    Read-Host " Press Enter to exit"
    exit 1
}
Write-Host " [ OK ] Migrations complete."
Write-Host ""

# ── STEP 5: Seed data ───────────────────────────────────────────────────────────
Write-Host " -- [5/8] Seeding database --"
Write-Host ""
if ((Test-Path "$PROJECT\osca.csv") -or (Test-Path "$PROJECT\..\osca.csv")) {
    Write-Host " Found osca.csv - importing senior citizen data..."
    Write-Host " This may take several minutes (ML pipeline runs for each senior)."
    Write-Host ""
    & $PHP "$PROJECT\artisan" db:seed --no-interaction
    if ($LASTEXITCODE -ne 0) {
        Write-Host " [WARN] Seeding encountered errors. System is still usable - add seniors manually."
    } else {
        Write-Host " [ OK ] Data imported successfully."
    }
} else {
    Write-Host " osca.csv not found - skipping data import."
    Write-Host " You can import data later: place osca.csv in the project root and run: php artisan db:seed"
}
Write-Host ""

# ── STEP 6: Frontend build ──────────────────────────────────────────────────────
Write-Host " -- [6/8] Building frontend assets (npm run build) --"
& npm run build 2>&1 | Write-Host
if ($LASTEXITCODE -ne 0) { Bail "Frontend build failed. Check the output above." }
Write-Host " [ OK ] Frontend assets built."
Write-Host ""

# ── STEP 7: Python virtual environment ─────────────────────────────────────────
Write-Host " -- [7/8] Creating Python virtual environment --"
if (Test-Path "$PROJECT\python\venv\Scripts\python.exe") {
    Write-Host " [ OK ] Virtual environment already exists - skipping."
} else {
    Write-Host " Creating venv at python\venv ..."
    & $PYTHON -m venv "$PROJECT\python\venv"
    if ($LASTEXITCODE -ne 0) { Bail "Failed to create Python virtual environment. Make sure Python 3.10+ is installed." }
    Write-Host " [ OK ] Virtual environment created."
}
Write-Host ""

# ── STEP 8: Python dependencies ─────────────────────────────────────────────────
Write-Host " -- [8/8] Installing Python ML dependencies --"
Write-Host " This may take 3-10 minutes..."
Write-Host " (Installing: scikit-learn, umap-learn, flask, numpy, pandas...)"
Write-Host ""
& "$PROJECT\python\venv\Scripts\pip.exe" install -r "$PROJECT\python\requirements.txt"
if ($LASTEXITCODE -ne 0) { Bail "pip install failed. Check the output above." }
Write-Host " [ OK ] Python ML dependencies installed."
Write-Host ""

# ── STEP 9: Sync ML model files ─────────────────────────────────────────────────
Write-Host " -- [9/9] Syncing ML model files from osca_output --"
$oscaOutput = "$PROJECT\..\osca_output"
if (Test-Path "$oscaOutput\model") {
    Write-Host " Found osca_output\model - copying model files to python\models ..."
    Copy-Item "$oscaOutput\model\*.pkl"  "$PROJECT\python\models\" -Force -ErrorAction SilentlyContinue
    Copy-Item "$oscaOutput\model\*.json" "$PROJECT\python\models\" -Force -ErrorAction SilentlyContinue
    Write-Host " [ OK ] Model files synced."
} else {
    Write-Host " osca_output\model not found - keeping existing python\models files."
}
if (Test-Path "$oscaOutput\predictions") {
    Write-Host " Found osca_output\predictions - copying prediction CSVs ..."
    if (-not (Test-Path "$PROJECT\python\models\predictions")) {
        New-Item -ItemType Directory "$PROJECT\python\models\predictions" | Out-Null
    }
    Copy-Item "$oscaOutput\predictions\senior_predictions.csv"          "$PROJECT\python\models\predictions\" -Force -ErrorAction SilentlyContinue
    Copy-Item "$oscaOutput\predictions\senior_recommendations_flat.csv" "$PROJECT\python\models\predictions\" -Force -ErrorAction SilentlyContinue
    Write-Host " [ OK ] Prediction CSVs synced."
} else {
    Write-Host " osca_output\predictions not found - keeping existing prediction CSVs."
}
Write-Host ""

# ── DONE ────────────────────────────────────────────────────────────────────────
Write-Host " =========================================="
Write-Host ""
Write-Host "  Setup complete!"
Write-Host ""
Write-Host "  To start the system:"
Write-Host "    Double-click  start.bat"
Write-Host ""
Write-Host "  System URL : http://127.0.0.1:8000"
Write-Host ""
Write-Host "  Three accounts were created by the seeder."
Write-Host "  Refer to the OSCA Administrator for login credentials."
Write-Host ""
Write-Host " =========================================="
Write-Host ""
Read-Host " Press Enter to exit"
