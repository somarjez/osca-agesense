@echo off
setlocal EnableDelayedExpansion
title AgeSense — OSCA System Launcher
color 0A

echo.
echo  ==========================================
echo   AgeSense OSCA System — Quick Launcher
echo  ==========================================
echo.

:: ── Locate project root (directory containing this file) ──────────────────────
set "PROJECT=%~dp0"
set "PROJECT=%PROJECT:~0,-1%"

:: ── Check for .env ─────────────────────────────────────────────────────────────
if not exist "%PROJECT%\.env" (
    echo  [!] .env file not found.
    echo      Run setup.bat first to configure the system.
    echo.
    pause
    exit /b 1
)

:: ── Sync any new keys from .env.example into .env (safe — never overwrites) ────
powershell -NoProfile -Command ^
    "$example = Get-Content '%PROJECT%\.env.example' | Where-Object { $_ -match '^[A-Z_]+=' }; " ^
    "$current  = Get-Content '%PROJECT%\.env'; " ^
    "$added = 0; " ^
    "foreach ($line in $example) { " ^
    "    $key = $line -replace '=.*',''; " ^
    "    if (-not ($current -match ('^' + [regex]::Escape($key) + '='))) { " ^
    "        Add-Content '%PROJECT%\.env' $line; " ^
    "        Write-Host ('  [.env] Added missing key: ' + $key); " ^
    "        $added++; " ^
    "    } " ^
    "} " ^
    "if ($added -gt 0) { Write-Host ('  [.env] ' + $added + ' new key(s) added from .env.example. Review .env if needed.') }"

:: ── Check for vendor/ ──────────────────────────────────────────────────────────
if not exist "%PROJECT%\vendor\autoload.php" (
    echo  [!] PHP dependencies not installed.
    echo      Run setup.bat first, or run:  composer install
    echo.
    pause
    exit /b 1
)

:: ── Check for public/build/ ────────────────────────────────────────────────────
if not exist "%PROJECT%\public\build" (
    echo  [!] Frontend assets not built.
    echo      Run setup.bat first, or run:  npm run build
    echo.
    pause
    exit /b 1
)

:: ── Check Python venv ──────────────────────────────────────────────────────────
if not exist "%PROJECT%\python\venv\Scripts\python.exe" (
    echo  [!] Python virtual environment not found.
    echo      Run setup.bat first to create it automatically.
    echo.
    pause
    exit /b 1
)

:: ── Check PHP on PATH ──────────────────────────────────────────────────────────
where php >nul 2>&1
if errorlevel 1 (
    echo  [!] php.exe not found on PATH.
    echo      Install PHP 8.2+ and add it to your PATH, then re-run.
    echo.
    pause
    exit /b 1
)

echo  [1/3] Starting Python ML services in background...
echo        (Models load in ~30 seconds on first run)
start "" /B powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden ^
    -File "%PROJECT%\python\start_services.ps1" ^
    > "%PROJECT%\storage\logs\ml_startup.log" 2>&1

echo  [2/3] Opening browser in 5 seconds...
timeout /t 5 /nobreak >nul
start "" http://127.0.0.1:8000

echo  [3/3] Starting Laravel development server...
echo.
echo  -----------------------------------------------
echo   System URL : http://127.0.0.1:8000
echo   Email      : admin@osca.local
echo   Password   : password
echo.
echo   ML services start in the background.
echo   Check storage\logs\preprocess.log if analysis
echo   returns errors on the first run.
echo.
echo   Press Ctrl+C to stop the server.
echo  -----------------------------------------------
echo.

cd /d "%PROJECT%"
php artisan serve

endlocal
