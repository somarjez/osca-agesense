@echo off
setlocal EnableDelayedExpansion
title AgeSense — First-Time Setup
color 0B

echo.
echo  ==========================================
echo   AgeSense OSCA System — First-Time Setup
echo  ==========================================
echo.
echo  This script will:
echo    1. Check all prerequisites
echo    2. Install PHP (Composer) dependencies
echo    3. Install Node.js dependencies
echo    4. Create and configure .env
echo    5. Generate application key
echo    6. Run database migrations
echo    7. Seed sample data (if osca.csv is present)
echo    8. Build frontend assets
echo    9. Create Python virtual environment
echo   10. Install Python ML dependencies
echo   11. Sync ML model files from osca_output (if notebook was re-run)
echo.
echo  Estimated time: 5-15 minutes (depending on internet speed)
echo.
pause

set "PROJECT=%~dp0"
set "PROJECT=%PROJECT:~0,-1%"
set ERRORS=0

:: ═══════════════════════════════════════════════════════════════════
::  STEP 0 — Check prerequisites
:: ═══════════════════════════════════════════════════════════════════
echo.
echo  ── Checking prerequisites ──────────────────────────────────────
echo.

:: PHP
where php >nul 2>&1
if errorlevel 1 (
    echo  [FAIL] php not found on PATH.
    echo         Install PHP 8.2+ from https://windows.php.net/download/
    echo         and add it to your PATH, then re-run setup.bat.
    set ERRORS=1
) else (
    for /f "tokens=2 delims= " %%v in ('php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;"') do set PHP_VER=%%v
    echo  [ OK ] PHP found
)

:: Composer
where composer >nul 2>&1
if errorlevel 1 (
    echo  [FAIL] Composer not found on PATH.
    echo         Install from https://getcomposer.org/download/
    set ERRORS=1
) else (
    echo  [ OK ] Composer found
)

:: Node.js
where node >nul 2>&1
if errorlevel 1 (
    echo  [FAIL] Node.js not found on PATH.
    echo         Install Node.js 18+ from https://nodejs.org/
    set ERRORS=1
) else (
    echo  [ OK ] Node.js found
)

:: npm
where npm >nul 2>&1
if errorlevel 1 (
    echo  [FAIL] npm not found on PATH.
    set ERRORS=1
) else (
    echo  [ OK ] npm found
)

:: Python
set PYTHON_CMD=
where python >nul 2>&1
if not errorlevel 1 (
    set PYTHON_CMD=python
    echo  [ OK ] Python found (python)
) else (
    where python3 >nul 2>&1
    if not errorlevel 1 (
        set PYTHON_CMD=python3
        echo  [ OK ] Python found (python3)
    ) else (
        echo  [FAIL] Python not found on PATH.
        echo         Install Python 3.10+ from https://www.python.org/downloads/
        echo         Make sure to check "Add Python to PATH" during installation.
        set ERRORS=1
    )
)

:: PowerShell (should always be present on Windows)
where powershell >nul 2>&1
if errorlevel 1 (
    echo  [WARN] PowerShell not found — ML auto-start may not work.
) else (
    echo  [ OK ] PowerShell found
)

if !ERRORS! NEQ 0 (
    echo.
    echo  [!!] One or more prerequisites are missing.
    echo       Install them and re-run setup.bat.
    echo.
    pause
    exit /b 1
)

echo.
echo  All prerequisites found. Continuing...
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 1 — PHP dependencies
:: ═══════════════════════════════════════════════════════════════════
echo  ── [1/8] Installing PHP dependencies (composer install) ────────
cd /d "%PROJECT%"
composer install --no-interaction --prefer-dist
if errorlevel 1 (
    echo  [FAIL] composer install failed. Check the output above.
    pause
    exit /b 1
)
echo  [ OK ] PHP dependencies installed.
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 2 — Node.js dependencies
:: ═══════════════════════════════════════════════════════════════════
echo  ── [2/8] Installing Node.js dependencies (npm install) ─────────
npm install --no-fund --no-audit
if errorlevel 1 (
    echo  [FAIL] npm install failed. Check the output above.
    pause
    exit /b 1
)
echo  [ OK ] Node.js dependencies installed.
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 3 — Environment file
:: ═══════════════════════════════════════════════════════════════════
echo  ── [3/8] Configuring environment ────────────────────────────────
if not exist "%PROJECT%\.env" (
    copy "%PROJECT%\.env.example" "%PROJECT%\.env" >nul
    echo  [ OK ] .env created from .env.example.
) else (
    echo  [ OK ] .env already exists — checking for new keys from .env.example...
    :: Add any keys present in .env.example but missing from .env
    :: (never overwrites keys the user has already set)
    powershell -NoProfile -Command ^
        "$example = Get-Content '%PROJECT%\.env.example' | Where-Object { $_ -match '^[A-Z_]+='}; " ^
        "$current  = Get-Content '%PROJECT%\.env'; " ^
        "$added = 0; " ^
        "foreach ($line in $example) { " ^
        "    $key = $line -replace '=.*',''; " ^
        "    if (-not ($current -match ('^' + [regex]::Escape($key) + '='))) { " ^
        "        Add-Content '%PROJECT%\.env' $line; " ^
        "        Write-Host ('  [ADDED] ' + $key); " ^
        "        $added++; " ^
        "    } " ^
        "} " ^
        "if ($added -eq 0) { Write-Host '  [ OK ] .env is up to date — no new keys needed.' } " ^
        "else { Write-Host ('  [ OK ] Added ' + $added + ' new key(s) from .env.example.') }"
)

:: Generate app key if APP_KEY is blank
php artisan key:generate --ansi
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 4 — Database
:: ═══════════════════════════════════════════════════════════════════
echo  ── [4/8] Setting up database ─────────────────────────────────────
echo.
echo  The system uses MySQL by default.
echo  Your .env file contains:
echo.
type "%PROJECT%\.env" | findstr /i "DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME"
echo.
echo  If the DB settings are correct, press any key to run migrations.
echo  If you need to edit .env first — close this window, edit it,
echo  then re-run setup.bat.
echo.
pause

php artisan migrate --force
if errorlevel 1 (
    echo.
    echo  [FAIL] Migrations failed.
    echo         Common causes:
    echo           - MySQL not running
    echo           - Wrong DB credentials in .env
    echo           - Database does not exist yet
    echo.
    echo         Create the database first:
    echo           mysql -u root -p -e "CREATE DATABASE osca_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    echo.
    pause
    exit /b 1
)
echo  [ OK ] Migrations complete.
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 5 — Seed data
:: ═══════════════════════════════════════════════════════════════════
echo  ── [5/8] Seeding database ────────────────────────────────────────
echo.
if exist "%PROJECT%\..\osca.csv" (
    echo  Found osca.csv — importing senior citizen data...
    echo  This may take several minutes (ML pipeline runs for each senior).
    echo.
    php artisan db:seed --no-interaction
    if errorlevel 1 (
        echo  [WARN] Seeding encountered errors. Check output above.
        echo         The system is still usable — you can add seniors manually.
    ) else (
        echo  [ OK ] Data imported successfully.
    )
) else (
    echo  osca.csv not found at ..\osca.csv — skipping data import.
    echo  The system will start with no senior records.
    echo  You can import data later via: php artisan db:seed
    echo  (place osca.csv one folder above the project root first)
)
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 6 — Frontend build
:: ═══════════════════════════════════════════════════════════════════
echo  ── [6/8] Building frontend assets (npm run build) ───────────────
npm run build
if errorlevel 1 (
    echo  [FAIL] Frontend build failed. Check the output above.
    pause
    exit /b 1
)
echo  [ OK ] Frontend assets built.
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 7 — Python virtual environment
:: ═══════════════════════════════════════════════════════════════════
echo  ── [7/8] Creating Python virtual environment ────────────────────
if exist "%PROJECT%\python\venv\Scripts\python.exe" (
    echo  [ OK ] Virtual environment already exists — skipping.
) else (
    echo  Creating venv at python\venv ...
    %PYTHON_CMD% -m venv "%PROJECT%\python\venv"
    if errorlevel 1 (
        echo  [FAIL] Failed to create Python virtual environment.
        echo         Make sure Python 3.10+ is installed correctly.
        pause
        exit /b 1
    )
    echo  [ OK ] Virtual environment created.
)
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 8 — Python dependencies
:: ═══════════════════════════════════════════════════════════════════
echo  ── [8/8] Installing Python ML dependencies ──────────────────────
echo  This may take 3-10 minutes depending on your internet speed...
echo  (Installing: scikit-learn, umap-learn, flask, numpy, pandas...)
echo.
"%PROJECT%\python\venv\Scripts\pip.exe" install -r "%PROJECT%\python\requirements.txt"
if errorlevel 1 (
    echo  [FAIL] pip install failed. Check the output above.
    pause
    exit /b 1
)
echo  [ OK ] Python ML dependencies installed.
echo.

:: ═══════════════════════════════════════════════════════════════════
::  STEP 9 (optional) — Sync ML model files from osca_output
::
::  When you retrain the notebook and get a new osca_output/, run this
::  step (or re-run setup.bat) to copy the fresh models + prediction
::  CSVs into python/models/ so they are picked up by the inference
::  service and can be committed to git for other devices.
:: ═══════════════════════════════════════════════════════════════════
echo  ── [11/11] Syncing ML model files from osca_output ─────────────
set "OSCA_OUTPUT=%PROJECT%\..\osca_output"
if exist "%OSCA_OUTPUT%\model" (
    echo  Found osca_output\model — copying model files to python\models ...
    xcopy /Y /Q "%OSCA_OUTPUT%\model\*.pkl"  "%PROJECT%\python\models\" >nul
    xcopy /Y /Q "%OSCA_OUTPUT%\model\*.json" "%PROJECT%\python\models\" >nul
    echo  [ OK ] Model files synced.
) else (
    echo  osca_output\model not found — keeping existing python\models files.
)
if exist "%OSCA_OUTPUT%\predictions" (
    echo  Found osca_output\predictions — copying prediction CSVs ...
    if not exist "%PROJECT%\python\models\predictions" mkdir "%PROJECT%\python\models\predictions"
    xcopy /Y /Q "%OSCA_OUTPUT%\predictions\senior_predictions.csv"           "%PROJECT%\python\models\predictions\" >nul
    xcopy /Y /Q "%OSCA_OUTPUT%\predictions\senior_recommendations_flat.csv"  "%PROJECT%\python\models\predictions\" >nul
    echo  [ OK ] Prediction CSVs synced to python\models\predictions\.
) else (
    echo  osca_output\predictions not found — keeping existing prediction CSVs.
)
echo.

:: ═══════════════════════════════════════════════════════════════════
::  DONE
:: ═══════════════════════════════════════════════════════════════════
echo  ══════════════════════════════════════════════════════════════════
echo.
echo   Setup complete!
echo.
echo   To start the system:
echo     Double-click  start.bat
echo   or run:
echo     php artisan serve
echo.
echo   Login credentials:
echo     URL      : http://127.0.0.1:8000
echo     Email    : admin@osca.local
echo     Password : password
echo.
echo   IMPORTANT: Change the default password before sharing access.
echo.
echo  ══════════════════════════════════════════════════════════════════
echo.
pause
endlocal
