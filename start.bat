@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul
title AgeSense - OSCA System Launcher
color 0A

echo.
echo  ==========================================
echo   AgeSense OSCA System - Quick Launcher
echo  ==========================================
echo.

:: ── Locate project root ────────────────────────────────────────────────────────
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

:: ── Sync any new keys from .env.example into .env (safe - never overwrites) ────
powershell -NoProfile -Command "$ex=Get-Content '%PROJECT%\.env.example'|Where-Object{$_ -match '^[A-Z_]+='};$cur=Get-Content '%PROJECT%\.env';$n=0;foreach($l in $ex){$k=$l -replace '=.*','';if(-not($cur -match('^'+[regex]::Escape($k)+'='))){Add-Content '%PROJECT%\.env' $l;Write-Host('  [.env] Added: '+$k);$n++}};if($n -gt 0){Write-Host('  [.env] '+$n+' key(s) added.')}"

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

:: ── Resolve PHP executable (PATH → Laragon → XAMPP → give up) ─────────────────
set "PHP="
where php >nul 2>&1
if not errorlevel 1 (
    for /f "delims=" %%i in ('where php') do (
        if not defined PHP set "PHP=%%i"
    )
)

if not defined PHP (
    for %%d in ("%USERPROFILE%\laragon\bin\php" "C:\laragon\bin\php") do (
        if not defined PHP (
            for /d %%v in ("%%~d\php*") do (
                if not defined PHP if exist "%%~v\php.exe" set "PHP=%%~v\php.exe"
            )
            if not defined PHP if exist "%%~d\php.exe" set "PHP=%%~d\php.exe"
        )
    )
)

if not defined PHP (
    for %%d in ("C:\xampp\php\php.exe" "D:\xampp\php\php.exe") do (
        if not defined PHP if exist "%%~d" set "PHP=%%~d"
    )
)

if not defined PHP (
    echo  [!] php.exe not found on PATH or in Laragon/XAMPP default locations.
    echo      Add PHP 8.2+ to your PATH and re-run, or install Laragon.
    echo.
    pause
    exit /b 1
)

echo  Using PHP: %PHP%

:: ── Auto-start MySQL if DB_CONNECTION=mysql and port 3306 not responding ───────
for /f "tokens=2 delims==" %%v in ('findstr /i "^DB_CONNECTION=" "%PROJECT%\.env"') do set "DB_CONN=%%v"
:: strip any trailing whitespace/CR from DB_CONN
for /f "tokens=* delims= " %%v in ("%DB_CONN%") do set "DB_CONN=%%v"

if /i "%DB_CONN%"=="mysql" (
    echo  Checking MySQL...
    powershell -NoProfile -Command "try { $t=New-Object Net.Sockets.TcpClient; $t.Connect('127.0.0.1',3306); $t.Close(); exit 0 } catch { exit 1 }" >nul 2>&1
    if errorlevel 1 (
        echo  MySQL not running - attempting to start...
        set "MYSQL_STARTED=0"

        for %%d in ("%USERPROFILE%\laragon\bin\mysql" "C:\laragon\bin\mysql") do (
            if "!MYSQL_STARTED!"=="0" (
                for /d %%v in ("%%~d\mysql*" "%%~d\mariadb*") do (
                    if "!MYSQL_STARTED!"=="0" if exist "%%~v\bin\mysqld.exe" (
                        echo  Starting Laragon MySQL from %%~v
                        start "" /B "%%~v\bin\mysqld.exe" --no-defaults --port=3306 --datadir="%%~v\data"
                        set "MYSQL_STARTED=1"
                    )
                )
            )
        )

        if "!MYSQL_STARTED!"=="0" (
            for %%d in ("C:\xampp\mysql\bin\mysqld.exe" "D:\xampp\mysql\bin\mysqld.exe") do (
                if "!MYSQL_STARTED!"=="0" if exist "%%~d" (
                    echo  Starting XAMPP MySQL: %%~d
                    start "" /B "%%~d" --no-defaults
                    set "MYSQL_STARTED=1"
                )
            )
        )

        if "!MYSQL_STARTED!"=="0" (
            echo  [WARN] Could not locate mysqld.exe. Start MySQL manually then press any key.
            pause
        ) else (
            echo  Waiting for MySQL to be ready...
            set /a MYSQL_WAIT=0
            :wait_mysql
            timeout /t 2 /nobreak >nul
            powershell -NoProfile -Command "try { $t=New-Object Net.Sockets.TcpClient; $t.Connect('127.0.0.1',3306); $t.Close(); exit 0 } catch { exit 1 }" >nul 2>&1
            if errorlevel 1 (
                set /a MYSQL_WAIT+=2
                if !MYSQL_WAIT! LSS 30 goto wait_mysql
                echo  [WARN] MySQL did not respond after 30 seconds. Check Laragon and try again.
                pause
            ) else (
                echo  [ OK ] MySQL is ready.
            )
        )
    ) else (
        echo  [ OK ] MySQL already running.
    )
)

echo  Clearing compiled view cache...
"%PHP%" artisan view:clear >nul 2>&1

echo  [1/3] Starting Python ML services in background...
echo        (Models load ~30 seconds on first run)
powershell -NoProfile -WindowStyle Hidden -Command "Start-Process powershell.exe -ArgumentList '-NoProfile -NonInteractive -WindowStyle Hidden -File ""%PROJECT%\python\start_services.ps1""' -WindowStyle Hidden"

echo  [2/3] Starting Laravel queue worker in background...
powershell -NoProfile -WindowStyle Hidden -Command "Start-Process '%PHP%' -ArgumentList '-d max_execution_time=0 artisan queue:work --queue=default --tries=1 --sleep=3' -WorkingDirectory '%PROJECT%' -WindowStyle Hidden -RedirectStandardOutput '%PROJECT%\storage\logs\queue.log' -RedirectStandardError '%PROJECT%\storage\logs\queue.err.log'"

echo  [2b]  Starting Laravel task scheduler in background...
powershell -NoProfile -WindowStyle Hidden -Command "Start-Process powershell.exe -ArgumentList '-NoProfile -NonInteractive -WindowStyle Hidden -File ""%PROJECT%\scheduler_loop.ps1"" -PhpExe ""%PHP%"" -ProjectDir ""%PROJECT%""' -WindowStyle Hidden"

echo  [3/3] Starting Laravel development server...
echo        (Browser opening in 5 seconds)
timeout /t 5 /nobreak >nul
start "" http://127.0.0.1:8000
echo.
echo.
echo  -----------------------------------------------
echo   System URL : http://127.0.0.1:8000
echo.
echo   Background processes started silently.
echo   Logs: storage\logs\ml_startup.log  (ML services)
echo         storage\logs\queue.log       (queue worker)
echo         storage\logs\scheduler.log   (task scheduler)
echo.
echo   Press Ctrl+C to stop the server.
echo  -----------------------------------------------
echo.

cd /d "%PROJECT%"
"%PHP%" artisan serve

endlocal
