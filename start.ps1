#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$PROJECT = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - Quick Launcher"
Write-Host " =========================================="
Write-Host ""

# ── Check for .env ─────────────────────────────────────────────────────────────
if (-not (Test-Path "$PROJECT\.env")) {
    Write-Host " [!] .env file not found."
    Write-Host "     Run setup.bat first to configure the system."
    Read-Host " Press Enter to exit"
    exit 1
}

# ── Sync any new keys from .env.example into .env (never overwrites) ───────────
$example = Get-Content "$PROJECT\.env.example" | Where-Object { $_ -match '^[A-Z_]+=' }
$current = Get-Content "$PROJECT\.env"
$added   = 0
foreach ($line in $example) {
    $key = $line -replace '=.*', ''
    if (-not ($current -match "^$([regex]::Escape($key))=")) {
        Add-Content "$PROJECT\.env" $line
        Write-Host "  [.env] Added missing key: $key"
        $added++
    }
}
if ($added -gt 0) { Write-Host "  [.env] $added new key(s) added from .env.example." }

# ── Check prerequisites ─────────────────────────────────────────────────────────
if (-not (Test-Path "$PROJECT\vendor\autoload.php")) {
    Write-Host " [!] PHP dependencies not installed. Run setup.bat first."
    Read-Host " Press Enter to exit"; exit 1
}
if (-not (Test-Path "$PROJECT\public\build")) {
    Write-Host " [!] Frontend assets not built. Run setup.bat first."
    Read-Host " Press Enter to exit"; exit 1
}
if (-not (Test-Path "$PROJECT\python\venv\Scripts\python.exe")) {
    Write-Host " [!] Python virtual environment not found. Run setup.bat first."
    Read-Host " Press Enter to exit"; exit 1
}

# ── Resolve PHP executable ──────────────────────────────────────────────────────
$PHP = $null

# PATH first
$phpOnPath = Get-Command php -ErrorAction SilentlyContinue
if ($phpOnPath) { $PHP = $phpOnPath.Source }

# Laragon (user install, then system install)
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

# XAMPP
if (-not $PHP) {
    foreach ($p in @("C:\xampp\php\php.exe","D:\xampp\php\php.exe")) {
        if (Test-Path $p) { $PHP = $p; break }
    }
}

if (-not $PHP) {
    Write-Host " [!] php.exe not found. Install Laragon or add PHP to PATH."
    Read-Host " Press Enter to exit"; exit 1
}

Write-Host " Using PHP: $PHP"

# ── Auto-start MySQL if needed ──────────────────────────────────────────────────
$envContent = Get-Content "$PROJECT\.env"
$dbConn = ($envContent | Where-Object { $_ -match '^DB_CONNECTION=' }) -replace '^DB_CONNECTION=', '' | ForEach-Object { $_.Trim() }

if ($dbConn -eq 'mysql') {
    Write-Host " Checking MySQL..."
    $mysqlUp = $false
    try {
        $t = New-Object Net.Sockets.TcpClient
        $t.Connect('127.0.0.1', 3306)
        $t.Close()
        $mysqlUp = $true
    } catch {}

    if ($mysqlUp) {
        Write-Host " [ OK ] MySQL already running."
    } else {
        Write-Host " MySQL not running - attempting to start..."
        $mysqld = $null

        foreach ($base in @("$env:USERPROFILE\laragon\bin\mysql", "C:\laragon\bin\mysql")) {
            if (-not $mysqld -and (Test-Path $base)) {
                $found = Get-ChildItem $base -Directory -ErrorAction SilentlyContinue |
                         Where-Object { $_.Name -match '^(mysql|mariadb)' -and (Test-Path "$($_.FullName)\bin\mysqld.exe") } |
                         Sort-Object Name -Descending | Select-Object -First 1
                if ($found) { $mysqld = $found.FullName }
            }
        }
        foreach ($p in @("C:\xampp\mysql","D:\xampp\mysql")) {
            if (-not $mysqld -and (Test-Path "$p\bin\mysqld.exe")) { $mysqld = $p }
        }

        if (-not $mysqld) {
            Write-Host " [WARN] Could not find mysqld.exe. Start MySQL manually then press Enter."
            Read-Host
        } else {
            Write-Host " Starting MySQL from $mysqld ..."
            Start-Process "$mysqld\bin\mysqld.exe" -ArgumentList "--no-defaults","--port=3306","--datadir=`"$mysqld\data`"" -WindowStyle Hidden
            Write-Host " Waiting for MySQL to be ready..."
            $waited = 0
            do {
                Start-Sleep -Seconds 2
                $waited += 2
                $mysqlUp = $false
                try { $t = New-Object Net.Sockets.TcpClient; $t.Connect('127.0.0.1',3306); $t.Close(); $mysqlUp = $true } catch {}
            } while (-not $mysqlUp -and $waited -lt 30)

            if ($mysqlUp) { Write-Host " [ OK ] MySQL is ready." }
            else {
                Write-Host " [WARN] MySQL did not respond after 30s. Check Laragon and try again."
                Read-Host " Press Enter to exit"; exit 1
            }
        }
    }
}

# ── Clear view cache ────────────────────────────────────────────────────────────
Write-Host " Clearing compiled view cache..."
& $PHP "$PROJECT\artisan" view:clear 2>&1 | Out-Null

# ── [1/3] Python ML services ────────────────────────────────────────────────────
Write-Host " [1/3] Starting Python ML services in background..."
Write-Host "       (Models load ~30 seconds on first run)"
Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","$PROJECT\python\start_services.ps1" -WindowStyle Hidden

# ── [2/3] Queue worker ──────────────────────────────────────────────────────────
Write-Host " [2/3] Starting Laravel queue worker in background..."
Start-Process $PHP -ArgumentList "-d","max_execution_time=0","$PROJECT\artisan","queue:work","--queue=default","--tries=1","--sleep=3" `
    -WorkingDirectory $PROJECT -WindowStyle Hidden `
    -RedirectStandardOutput "$PROJECT\storage\logs\queue.log" `
    -RedirectStandardError  "$PROJECT\storage\logs\queue.err.log"

# ── [2b] Task scheduler ─────────────────────────────────────────────────────────
Write-Host " [2b]  Starting Laravel task scheduler in background..."
Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","$PROJECT\scheduler_loop.ps1","-PhpExe",$PHP,"-ProjectDir",$PROJECT -WindowStyle Hidden

# ── [3/3] Laravel server ────────────────────────────────────────────────────────
Write-Host " [3/3] Starting Laravel development server..."
Write-Host "       (Browser opening in 5 seconds)"
Start-Sleep -Seconds 5
Start-Process "http://127.0.0.1:8000"

Write-Host ""
Write-Host " -----------------------------------------------"
Write-Host "  System URL : http://127.0.0.1:8000"
Write-Host "  Email      : admin@osca.local"
Write-Host "  Password   : password"
Write-Host ""
Write-Host "  Background processes started silently."
Write-Host "  Logs: storage\logs\ml_startup.log  (ML services)"
Write-Host "        storage\logs\queue.log        (queue worker)"
Write-Host "        storage\logs\scheduler.log    (task scheduler)"
Write-Host ""
Write-Host "  Press Ctrl+C to stop the server."
Write-Host " -----------------------------------------------"
Write-Host ""

Set-Location $PROJECT
& $PHP "$PROJECT\artisan" serve
