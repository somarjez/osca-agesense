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

function Test-PortOpen($port, $timeoutMs = 400) {
    # NOTE: TcpClient's synchronous Connect() has no timeout parameter and, on
    # some machines/network stacks, does NOT fail fast when nothing is
    # listening (observed: ~5-6s per attempt instead of an instant refusal) -
    # so a caller looping on this with its own short per-attempt budget can
    # end up waiting far longer in total than intended. Use the async
    # BeginConnect/WaitOne pattern instead, which bounds the wait to
    # $timeoutMs no matter how the OS/network handles an unanswered attempt.
    $t = New-Object Net.Sockets.TcpClient
    try {
        $async = $t.BeginConnect('127.0.0.1', $port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne($timeoutMs)) {
            return $false
        }
        $t.EndConnect($async)
        return $true
    } catch {
        return $false
    } finally {
        $t.Close()
    }
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

    # ── Resolve php-cgi.exe (same directory as $PHP; do not hardcode) ────────────
    $PHPCGI = Join-Path (Split-Path -Parent $PHP) "php-cgi.exe"
    if (-not (Test-Path $PHPCGI)) {
        Fail "php-cgi not found" "php-cgi.exe was not found next to php.exe ($PHP).`n`nInstall/enable the php-cgi component alongside your PHP install, then try again."
    }
    Log "Using php-cgi: $PHPCGI"

    # ── Resolve nginx.exe ──────────────────────────────────────────────────────────
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
        Fail "nginx not found" "nginx.exe could not be found on this computer.`n`nInstall Laragon (with nginx) or add nginx to your system PATH, then try again."
    }
    Log "Using nginx: $NGINX"

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
    # Everything above this point can Fail() with a specific error message.
    # Below this point, only the web-server readiness check further down (see
    # the nginx/php-cgi section) still calls Fail() — a dead web server is
    # worse than any prerequisite check above, so it gets the same clear
    # MessageBox treatment rather than silently exiting 0. Nothing else below
    # this line fails.
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
    # Deliberately NOT redirected: PHP's queue:work logs every processed job
    # to stderr. RedirectStandardOutput/Error=$true with no reader attached
    # fills the OS's ~4KB pipe buffer and the child blocks forever on its
    # next write - an intermittent full hang. -WindowStyle Hidden already
    # means no console appears either way, so output going nowhere is
    # strictly better than that deadlock.
    $queuePsi.RedirectStandardOutput = $false
    $queuePsi.RedirectStandardError  = $false
    [System.Diagnostics.Process]::Start($queuePsi) | Out-Null

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

    # ── [2b] Task scheduler ───────────────────────────────────────────────────────
    Log "[2b] Starting Laravel task scheduler in background..."
    Start-Process powershell.exe -ArgumentList "-NoProfile","-NonInteractive","-WindowStyle","Hidden","-File","`"$PROJECT\scheduler_loop.ps1`"","-PhpExe","`"$PHP`"","-ProjectDir","`"$PROJECT`"" -WindowStyle Hidden

    # ── [3/4] nginx + php-cgi worker pool — BACKGROUND, not foreground ────────────
    # Replaces `php artisan serve` (PHP's built-in dev server), which is
    # strictly single-request on Windows (PHP_CLI_SERVER_WORKERS needs
    # pcntl_fork(), which doesn't exist on PHP_OS_FAMILY=Windows) — a second
    # sidebar click could not be served until the first response finished.
    # nginx now fronts a pool of 4 persistent php-cgi FastCGI workers, giving
    # real concurrency.
    #
    # Kill any stale nginx/php-cgi (or a leftover `artisan serve` from before
    # this change) left over from a previous quiet-launch so a re-click of
    # "Start OSCA System" doesn't collide on port 8000. (Harmless here even
    # though the top-of-script port-8000 check normally prevents reaching
    # this point while a server is already accepting connections — it only
    # guards against a half-dead process still holding the port without
    # answering.) Matched by command line containing this project's path, so
    # an unrelated nginx/php-cgi elsewhere on the machine is never touched;
    # php-cgi is given a -d error_log override below that embeds the project
    # path for exactly this matching to key off.
    Get-WmiObject Win32_Process -Filter "Name='php.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*artisan*serve*" } |
        ForEach-Object { $_.Terminate() | Out-Null }
    Get-WmiObject Win32_Process -Filter "Name='nginx.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*" } |
        ForEach-Object { $_.Terminate() | Out-Null }
    Get-WmiObject Win32_Process -Filter "Name='php-cgi.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*" } |
        ForEach-Object { $_.Terminate() | Out-Null }
    Start-Sleep -Seconds 1   # let Windows release ports 8000/9000-9003 before rebinding

    # nginx.conf's client_body_temp_path etc. point here; create once
    # (harmless if already present) since nginx refuses to start otherwise.
    foreach ($d in @('client_body', 'fastcgi', 'proxy', 'uwsgi', 'scgi')) {
        New-Item -ItemType Directory -Force -Path "$PROJECT\storage\logs\nginx-temp\$d" | Out-Null
    }

    Log "[3/4] Starting php-cgi worker pool (4 workers) + nginx in background..."

    # 4 persistent FastCGI workers on 127.0.0.1:9000-9003 (nginx's
    # osca_php_pool upstream). PHP_FCGI_MAX_REQUESTS=0 is required: without
    # it, php-cgi exits after its default request count and the pool
    # silently shrinks over time. Not redirected, for the same
    # pipe-buffer-deadlock reason as the queue workers above.
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

    # nginx: master + 1 worker, listening on the same 127.0.0.1:8000 the
    # loading page below polls — no change needed to $APP_URL or the poll logic.
    Start-Process -FilePath $NGINX -ArgumentList "-p", "`"$PROJECT`"", "-c", "`"$PROJECT\conf\nginx\local\nginx.conf`"" -WindowStyle Hidden

    # ── Readiness check: verify nginx/php-cgi actually bound port 8000 ───────────
    # The loading page's own JS polls $APP_URL for up to ~60s and shows a
    # "failed" state if it never responds, so staff aren't left staring at a
    # blank tab forever — but that client-side timeout gives no *reason*, and
    # this script would otherwise Log "complete" / exit 0 regardless of
    # whether the server ever came up. Give nginx + the php-cgi pool a short
    # budget to bind the port before declaring success.
    Log "Verifying web server is responding on port 8000..."
    $webUp = $false
    # Use a Stopwatch for the overall budget rather than incrementing a counter
    # by an assumed per-iteration cost - Test-PortOpen is now bounded per call,
    # but measuring real elapsed time (instead of guessing 400ms/iteration) is
    # what actually keeps this loop honest to the ~8s budget.
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while (-not $webUp -and $sw.Elapsed.TotalSeconds -lt 8) {
        $webUp = Test-PortOpen 8000 400
        if (-not $webUp) { Start-Sleep -Milliseconds 400 }
    }
    $sw.Stop()
    Log "Readiness check finished after $([math]::Round($sw.Elapsed.TotalSeconds, 1))s (up=$webUp)."

    if ($webUp) {
        Log "Web server responding on port 8000."
    } else {
        Fail "Web server did not start" "nginx/php-cgi did not start listening on port 8000.`n`nCheck storage\logs\nginx-error.log and storage\logs\php-cgi-900x.log for the reason (e.g. a config error or the port already in use), then try again."
    }

    # ── [4/4] Done — the loading page's own JS polls $APP_URL and redirects ───────
    Log "[4/4] All services launched. Loading page will redirect to $APP_URL once it responds."
    Log "Optional local speed-up: bootstrap\cache\ has no config/route cache yet. Run 'php artisan config:cache && php artisan route:cache' manually for a faster boot (not run automatically here, so local config/route edits keep taking effect without a manual cache:clear)."
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
