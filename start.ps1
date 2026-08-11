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

# ── Resolve php-cgi.exe (same directory as $PHP; do not hardcode) ──────────────
$PHPCGI = Join-Path (Split-Path -Parent $PHP) "php-cgi.exe"
if (-not (Test-Path $PHPCGI)) {
    Write-Host " [!] php-cgi.exe not found next to php.exe ($PHP)."
    Write-Host "     Install/enable the php-cgi component alongside your PHP install."
    Read-Host " Press Enter to exit"; exit 1
}
Write-Host " Using php-cgi: $PHPCGI"

# ── Resolve nginx.exe ────────────────────────────────────────────────────────────
# Same defensive resolution style as PHP/MySQL above: try common Laragon
# locations, then PATH, and fail with a clear message rather than assuming
# Laragon is the only possible install.
$NGINX = $null
foreach ($base in @("$env:USERPROFILE\laragon\bin\nginx", "C:\laragon\bin\nginx")) {
    if (-not $NGINX -and (Test-Path $base)) {
        $found = Get-ChildItem "$base\nginx-*" -Directory -ErrorAction SilentlyContinue |
                 Where-Object { Test-Path "$($_.FullName)\nginx.exe" } |
                 Sort-Object Name -Descending | Select-Object -First 1
        if ($found) { $NGINX = "$($found.FullName)\nginx.exe" }
        elseif (Test-Path "$base\nginx.exe") { $NGINX = "$base\nginx.exe" }
    }
}
if (-not $NGINX) {
    $nginxOnPath = Get-Command nginx -ErrorAction SilentlyContinue
    if ($nginxOnPath) { $NGINX = $nginxOnPath.Source }
}
if (-not $NGINX) {
    Write-Host " [!] nginx.exe not found. Install Laragon (with nginx) or add nginx to PATH."
    Read-Host " Press Enter to exit"; exit 1
}
Write-Host " Using nginx: $NGINX"

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
# start_services.ps1 writes individual logs to python-preprocess.log / python-inference.log.
# This outer log captures the startup orchestration output from start_services.ps1 itself.
$mlStartLog    = "$PROJECT\storage\logs\python-services-start.log"
$mlStartErrLog = "$PROJECT\storage\logs\python-services-start.err.log"
Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","`"$PROJECT\python\start_services.ps1`"" -WindowStyle Hidden -RedirectStandardOutput $mlStartLog -RedirectStandardError $mlStartErrLog

# ── [2/3] Queue workers ─────────────────────────────────────────────────────────
Write-Host " [2/3] Starting Laravel queue workers in background (default + ml)..."

# Kill any stale queue workers from a previous session so they pick up the
# current .env (e.g. QUEUE_CONNECTION change). Use WMI to match by command line
# so we only kill *this project's* worker, not an unrelated php process.
Get-WmiObject Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*queue:work*" } |
    ForEach-Object { $_.Terminate() | Out-Null }

# Use ProcessStartInfo so that paths containing spaces are passed as a single
# argument, not split on whitespace (Start-Process -ArgumentList array breaks
# on paths like "02. AgeSense\...").
$queuePsi = New-Object System.Diagnostics.ProcessStartInfo
$queuePsi.FileName        = $PHP
$queuePsi.Arguments       = "-d max_execution_time=0 `"$PROJECT\artisan`" queue:work --queue=default --tries=1 --sleep=3"
$queuePsi.WorkingDirectory = $PROJECT
$queuePsi.WindowStyle     = [System.Diagnostics.ProcessWindowStyle]::Hidden
$queuePsi.UseShellExecute = $false
# Deliberately NOT redirected: PHP's queue:work logs every processed job to
# stderr. RedirectStandardOutput/Error=$true with no reader attached fills
# the OS's ~4KB pipe buffer and the child blocks forever on its next write —
# an intermittent full hang. -WindowStyle Hidden already means no console
# appears either way, so output going nowhere is strictly better than that
# deadlock.
$queuePsi.RedirectStandardOutput = $false
$queuePsi.RedirectStandardError  = $false
[System.Diagnostics.Process]::Start($queuePsi) | Out-Null

# Dedicated worker for the `ml` queue (ProcessMlSingle, RunMlPipeline) so a
# senior's re-analysis never waits behind heavier `default`-queue work (e.g.
# the GIS route-distance recompute a barangay edit queues) — real concurrency
# via a second OS process, not just queue-order priority, since a single
# worker can't preempt a job it's already mid-way through.
$mlQueuePsi = New-Object System.Diagnostics.ProcessStartInfo
$mlQueuePsi.FileName        = $PHP
$mlQueuePsi.Arguments       = "-d max_execution_time=0 `"$PROJECT\artisan`" queue:work --queue=ml --tries=1 --sleep=3"
$mlQueuePsi.WorkingDirectory = $PROJECT
$mlQueuePsi.WindowStyle     = [System.Diagnostics.ProcessWindowStyle]::Hidden
$mlQueuePsi.UseShellExecute = $false
# See the `default`-queue worker above for why these are $false, not $true.
$mlQueuePsi.RedirectStandardOutput = $false
$mlQueuePsi.RedirectStandardError  = $false
[System.Diagnostics.Process]::Start($mlQueuePsi) | Out-Null

# ── [2b] Task scheduler ─────────────────────────────────────────────────────────
Write-Host " [2b]  Starting Laravel task scheduler in background..."
Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","`"$PROJECT\scheduler_loop.ps1`"","-PhpExe","`"$PHP`"","-ProjectDir","`"$PROJECT`"" -WindowStyle Hidden

# ── [3/3] nginx + php-cgi worker pool ───────────────────────────────────────────
# Replaces `php artisan serve` (PHP's built-in dev server), which is strictly
# single-request on Windows (PHP_CLI_SERVER_WORKERS needs pcntl_fork(), which
# doesn't exist on PHP_OS_FAMILY=Windows) — a second sidebar click could not
# be served until the first response finished. nginx now fronts a pool of 4
# persistent php-cgi FastCGI workers, giving real concurrency.
Write-Host " [3/3] Starting nginx + php-cgi worker pool..."

# Kill any stale nginx/php-cgi from a previous session before starting new
# ones, so a crashed prior run never blocks this one (e.g. a lingering nginx
# still holding port 8000). Matched by command line containing this project's
# path, same pattern as the queue-worker cleanup above, so an unrelated
# nginx/php-cgi elsewhere on the machine is never touched. php-cgi doesn't
# take a project-path argument by default, so each worker below is started
# with a -d error_log override that embeds the project path for this
# matching to key off.
Get-WmiObject Win32_Process -Filter "Name='nginx.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*" } |
    ForEach-Object { $_.Terminate() | Out-Null }
Get-WmiObject Win32_Process -Filter "Name='php-cgi.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*" } |
    ForEach-Object { $_.Terminate() | Out-Null }
Start-Sleep -Seconds 1   # let Windows release ports 8000/9000-9003 before rebinding

# nginx.conf's client_body_temp_path etc. point here; create once (harmless
# if already present) since nginx refuses to start if these don't exist.
foreach ($d in @('client_body', 'fastcgi', 'proxy', 'uwsgi', 'scgi')) {
    New-Item -ItemType Directory -Force -Path "$PROJECT\storage\logs\nginx-temp\$d" | Out-Null
}

# 4 persistent FastCGI workers on 127.0.0.1:9000-9003 (nginx's osca_php_pool
# upstream). PHP_FCGI_MAX_REQUESTS=0 is required: without it, php-cgi exits
# after its default request count and the pool silently shrinks over time.
# Not redirected, for the same pipe-buffer-deadlock reason as the queue
# workers above.
for ($i = 0; $i -lt 4; $i++) {
    $port = 9000 + $i
    $cgiPsi = New-Object System.Diagnostics.ProcessStartInfo
    $cgiPsi.FileName          = $PHPCGI
    $cgiPsi.Arguments         = "-b 127.0.0.1:$port -d error_log=`"$PROJECT\storage\logs\php-cgi-$port.log`""
    $cgiPsi.WorkingDirectory  = $PROJECT
    $cgiPsi.WindowStyle       = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $cgiPsi.UseShellExecute   = $false
    $cgiPsi.RedirectStandardOutput = $false
    $cgiPsi.RedirectStandardError  = $false
    $cgiPsi.EnvironmentVariables["PHP_FCGI_MAX_REQUESTS"] = "0"
    [System.Diagnostics.Process]::Start($cgiPsi) | Out-Null
}

# nginx: master + 1 worker, listening on the same 127.0.0.1:8000 the app has
# always used — shortcuts/launch-osca.vbs/bookmarks need no changes.
Start-Process -FilePath $NGINX -ArgumentList "-p", "`"$PROJECT`"", "-c", "`"$PROJECT\conf\nginx\local\nginx.conf`"" -WindowStyle Hidden

Write-Host "       (Browser opening in 5 seconds)"
Start-Sleep -Seconds 5
Start-Process "http://127.0.0.1:8000"

Write-Host ""
Write-Host " -----------------------------------------------"
Write-Host "  System URL : http://127.0.0.1:8000"
Write-Host ""
Write-Host "  Background processes started silently."
Write-Host "  Web server : nginx + 4 php-cgi FastCGI workers (real concurrency -"
Write-Host "               replaces the old single-request 'artisan serve')."
Write-Host "  Logs: storage\logs\python-preprocess.log      (preprocess service)"
Write-Host "        storage\logs\python-inference.log       (inference service)"
Write-Host "        storage\logs\python-services-start.log  (ML startup output)"
Write-Host "        storage\logs\scheduler.log              (task scheduler)"
Write-Host "        storage\logs\nginx-error.log             (nginx error log)"
Write-Host "        storage\logs\nginx-access.log            (nginx access log)"
Write-Host "        storage\logs\php-cgi-900x.log            (per php-cgi worker PHP error log)"
Write-Host ""
Write-Host "  Queue worker output is not written to disk (avoids a Windows pipe-"
Write-Host "  buffer deadlock on unread redirected output) - use Get-Process to"
Write-Host "  confirm the workers are still running."
Write-Host ""
Write-Host "  All services now run in the background - this window can be closed."
Write-Host "  Run stop.ps1 to stop nginx, php-cgi, the queue workers, and the scheduler."
Write-Host " -----------------------------------------------"
Write-Host ""
