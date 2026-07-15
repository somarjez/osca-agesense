<#
.SYNOPSIS
    Windowless variant of start.ps1 for the "Start OSCA System" desktop/Start Menu
    shortcut. Same startup sequence, but:
      - artisan serve runs in the BACKGROUND (not foreground) so this script can exit
        and no console window lingers.
      - No Read-Host prompts (they would hang invisibly when run hidden via wscript).
      - Fatal errors show a MessageBox instead of console text, since there is no
        console for staff to read.
      - A branded loading page (resources\branding\loading.html) opens immediately
        once startup is committed, and polls until the server responds, so a click
        gets INSTANT visible feedback instead of a silent multi-second wait — this
        also discourages staff from clicking the icon again "because nothing happened".
      - A lock file + a live already-running check make repeat clicks harmless: a
        click while already starting shows a "please wait" popup instead of
        re-running the whole sequence; a click while already running just reopens
        the (instantly-redirecting) loading page.
      - Progress is written to storage\logs\quiet-launcher.log instead of the screen.

    Not meant to be double-clicked directly — launched hidden via launch-osca.vbs.
    For interactive/development use, use start.bat / start.ps1 instead.
#>
#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

$PROJECT      = Split-Path -Parent $MyInvocation.MyCommand.Path
$LOG          = "$PROJECT\storage\logs\quiet-launcher.log"
$LOCK         = "$PROJECT\storage\logs\.osca-start.lock"
$LOADING_PAGE = "$PROJECT\resources\branding\loading.html"
$APP_URL      = "http://127.0.0.1:8000"
$LOCK_MAX_AGE_SECONDS = 90   # a lock older than this is assumed stale (crashed run), not "still starting"

function Log($msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    try { Add-Content -Path $LOG -Value $line -ErrorAction SilentlyContinue } catch {}
}

function Fail($title, $msg) {
    Log "FATAL: $msg"
    [System.Windows.Forms.MessageBox]::Show(
        $msg, "AgeSense OSCA - $title",
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
    throw $msg
}

function Test-PortOpen($port) {
    try {
        $t = New-Object Net.Sockets.TcpClient
        $t.Connect('127.0.0.1', $port)
        $t.Close()
        return $true
    } catch { return $false }
}

try { New-Item -ItemType Directory -Path "$PROJECT\storage\logs" -Force -ErrorAction SilentlyContinue | Out-Null } catch {}
Log "=== Start OSCA System (quiet launcher) ==="

# ── Already running? Just reopen the (instantly-redirecting) loading page ──────
if (Test-PortOpen 8000) {
    Log "Already running - reopening loading page (will redirect immediately)."
    Start-Process $LOADING_PAGE
    exit 0
}

# ── Already starting (another click still in flight)? Don't restart everything ──
if (Test-Path $LOCK) {
    $age = (Get-Date) - (Get-Item $LOCK).LastWriteTime
    if ($age.TotalSeconds -lt $LOCK_MAX_AGE_SECONDS) {
        Log "Start already in progress (lock age $([int]$age.TotalSeconds)s) - ignoring duplicate click."
        [System.Windows.Forms.MessageBox]::Show(
            "AgeSense OSCA System is already starting - please wait a few more seconds.`n`nA browser tab with a loading screen should already be open.",
            "AgeSense OSCA - Please wait",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Information
        ) | Out-Null
        exit 0
    } else {
        Log "Stale lock found (age $([int]$age.TotalSeconds)s) - treating as a crashed previous run and continuing."
    }
}
Set-Content -Path $LOCK -Value (Get-Date -Format 'o') -Force

try {
    # ── Check for .env ───────────────────────────────────────────────────────────
    if (-not (Test-Path "$PROJECT\.env")) {
        Fail "Setup required" "The system has not been set up yet on this computer.`n`nRun setup.bat first (see the project folder), then try again."
    }

    # ── Sync any new keys from .env.example into .env (never overwrites) ─────────
    try {
        $example = Get-Content "$PROJECT\.env.example" | Where-Object { $_ -match '^[A-Z_]+=' }
        $current = Get-Content "$PROJECT\.env"
        $added   = 0
        foreach ($line in $example) {
            $key = $line -replace '=.*', ''
            if (-not ($current -match "^$([regex]::Escape($key))=")) {
                Add-Content "$PROJECT\.env" $line
                $added++
            }
        }
        if ($added -gt 0) { Log "Added $added new key(s) to .env from .env.example." }
    } catch { Log "WARN: .env key sync failed: $_" }

    # ── Check prerequisites ───────────────────────────────────────────────────────
    if (-not (Test-Path "$PROJECT\vendor\autoload.php")) {
        Fail "Setup incomplete" "PHP dependencies are not installed.`n`nRun setup.bat first, then try again."
    }
    if (-not (Test-Path "$PROJECT\public\build")) {
        Fail "Setup incomplete" "Frontend assets are not built.`n`nRun setup.bat first, then try again."
    }
    if (-not (Test-Path "$PROJECT\python\venv\Scripts\python.exe")) {
        Fail "Setup incomplete" "The Python virtual environment was not found.`n`nRun setup.bat first, then try again."
    }

    # ── Resolve PHP executable ────────────────────────────────────────────────────
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
        foreach ($p in @("C:\xampp\php\php.exe","D:\xampp\php\php.exe")) {
            if (Test-Path $p) { $PHP = $p; break }
        }
    }

    if (-not $PHP) {
        Fail "PHP not found" "php.exe could not be found on this computer.`n`nInstall Laragon or add PHP to your system PATH, then try again."
    }

    Log "Using PHP: $PHP"

    # ── Auto-start MySQL if needed ────────────────────────────────────────────────
    $envContent = Get-Content "$PROJECT\.env"
    $dbConn = ($envContent | Where-Object { $_ -match '^DB_CONNECTION=' }) -replace '^DB_CONNECTION=', '' | ForEach-Object { $_.Trim() }

    if ($dbConn -eq 'mysql') {
        Log "Checking MySQL..."
        $mysqlUp = Test-PortOpen 3306

        if ($mysqlUp) {
            Log "MySQL already running."
        } else {
            Log "MySQL not running - attempting to start..."
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
                Fail "MySQL not found" "MySQL is not running and could not be started automatically.`n`nStart Laragon/MySQL manually, then click 'Start OSCA System' again."
            }

            Log "Starting MySQL from $mysqld ..."
            Start-Process "$mysqld\bin\mysqld.exe" -ArgumentList "--no-defaults","--port=3306","--datadir=`"$mysqld\data`"" -WindowStyle Hidden
            $waited = 0
            do {
                Start-Sleep -Seconds 2
                $waited += 2
                $mysqlUp = Test-PortOpen 3306
            } while (-not $mysqlUp -and $waited -lt 30)

            if ($mysqlUp) {
                Log "MySQL is ready."
            } else {
                Fail "MySQL did not start" "MySQL did not respond after 30 seconds.`n`nCheck Laragon and try again."
            }
        }
    }

    # ── We're committed to starting now — show the loading page immediately ──────
    # Everything above this point can Fail() with a specific error message; nothing
    # below it does, so it's safe to put the "starting..." UI up now.
    Log "Opening loading page..."
    Start-Process $LOADING_PAGE

    # ── Clear view cache ──────────────────────────────────────────────────────────
    Log "Clearing compiled view cache..."
    & $PHP "$PROJECT\artisan" view:clear 2>&1 | Out-Null

    # ── [1/4] Python ML services ──────────────────────────────────────────────────
    Log "[1/4] Starting Python ML services in background..."
    $mlStartLog    = "$PROJECT\storage\logs\python-services-start.log"
    $mlStartErrLog = "$PROJECT\storage\logs\python-services-start.err.log"
    Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","`"$PROJECT\python\start_services.ps1`"" -WindowStyle Hidden -RedirectStandardOutput $mlStartLog -RedirectStandardError $mlStartErrLog

    # ── [2/4] Queue workers ───────────────────────────────────────────────────────
    Log "[2/4] Starting Laravel queue workers in background (default + ml)..."

    Get-WmiObject Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*queue:work*" } |
        ForEach-Object { $_.Terminate() | Out-Null }

    $queuePsi = New-Object System.Diagnostics.ProcessStartInfo
    $queuePsi.FileName        = $PHP
    $queuePsi.Arguments       = "-d max_execution_time=0 `"$PROJECT\artisan`" queue:work --queue=default --tries=1 --sleep=3"
    $queuePsi.WorkingDirectory = $PROJECT
    $queuePsi.WindowStyle     = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $queuePsi.UseShellExecute = $false
    $queuePsi.RedirectStandardOutput = $true
    $queuePsi.RedirectStandardError  = $true
    [System.Diagnostics.Process]::Start($queuePsi) | Out-Null

    $mlQueuePsi = New-Object System.Diagnostics.ProcessStartInfo
    $mlQueuePsi.FileName        = $PHP
    $mlQueuePsi.Arguments       = "-d max_execution_time=0 `"$PROJECT\artisan`" queue:work --queue=ml --tries=1 --sleep=3"
    $mlQueuePsi.WorkingDirectory = $PROJECT
    $mlQueuePsi.WindowStyle     = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $mlQueuePsi.UseShellExecute = $false
    $mlQueuePsi.RedirectStandardOutput = $true
    $mlQueuePsi.RedirectStandardError  = $true
    [System.Diagnostics.Process]::Start($mlQueuePsi) | Out-Null

    # ── [2b] Task scheduler ───────────────────────────────────────────────────────
    Log "[2b] Starting Laravel task scheduler in background..."
    Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","`"$PROJECT\scheduler_loop.ps1`"","-PhpExe","`"$PHP`"","-ProjectDir","`"$PROJECT`"" -WindowStyle Hidden

    # ── [3/4] Laravel server — BACKGROUND, not foreground ─────────────────────────
    # Kill any stale `artisan serve` left over from a previous quiet-launch so a
    # re-click of "Start OSCA System" doesn't collide on port 8000. (Harmless here
    # even though the top-of-script port-8000 check normally prevents reaching this
    # point while a server is already accepting connections — it only guards against
    # a half-dead process still holding the port without answering.)
    Get-WmiObject Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*artisan*serve*" } |
        ForEach-Object { $_.Terminate() | Out-Null }

    Log "[3/4] Starting Laravel server in background..."
    $servePsi = New-Object System.Diagnostics.ProcessStartInfo
    $servePsi.FileName        = $PHP
    $servePsi.Arguments       = "`"$PROJECT\artisan`" serve"
    $servePsi.WorkingDirectory = $PROJECT
    $servePsi.WindowStyle     = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $servePsi.UseShellExecute = $false
    $servePsi.RedirectStandardOutput = $true
    $servePsi.RedirectStandardError  = $true
    [System.Diagnostics.Process]::Start($servePsi) | Out-Null

    # ── [4/4] Done — the loading page's own JS polls $APP_URL and redirects ───────
    Log "[4/4] All services launched. Loading page will redirect to $APP_URL once it responds."
    Log "System start sequence complete."
    exit 0
} catch {
    # Fail() already logged the specific reason and showed a MessageBox; anything
    # else unexpected still exits non-zero rather than leaving a half-started state.
    Log "Startup aborted: $_"
    exit 1
} finally {
    Remove-Item -Path $LOCK -Force -ErrorAction SilentlyContinue
}
