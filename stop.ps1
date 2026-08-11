#Requires -Version 5.1
param(
    # Skip the trailing Read-Host so this can be run hidden (e.g. from the
    # "Stop OSCA System" shortcut via stop-osca.vbs), where nobody can see or
    # respond to a console prompt.
    [switch]$Quiet
)
$PROJECT = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - Stopping"
Write-Host " =========================================="
Write-Host ""

# Clear the "start in progress" lock (start-quiet.ps1) in case a stop is issued
# mid-launch, so the next "Start OSCA System" click isn't mistaken for a duplicate.
Remove-Item -Path "$PROJECT\storage\logs\.osca-start.lock" -Force -ErrorAction SilentlyContinue

$killed = 0

# ── Stop nginx (graceful) ────────────────────────────────────────────────────────
# `-s quit` asks the master process to finish in-flight requests and exit
# cleanly, rather than killing it out from under an active connection.
$nginxRunning = Get-WmiObject Win32_Process -Filter "Name='nginx.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*" }
if ($nginxRunning) {
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

    if ($NGINX) {
        Write-Host "  Stopping nginx (graceful quit)..."
        # -p/-c must match what start.ps1/start-quiet.ps1 used to start it —
        # nginx's `pid` directive (in conf/nginx/local/nginx.conf) is what
        # tells `-s quit` which process to signal, and that directive is only
        # read from this same config file.
        & $NGINX -p "$PROJECT" -c "$PROJECT\conf\nginx\local\nginx.conf" -s quit 2>&1 | Out-Null
        Start-Sleep -Seconds 1
        $killed += $nginxRunning.Count
    } else {
        Write-Host "  [WARN] nginx.exe found running for this project but the binary could not be re-resolved for a graceful quit - force-killing instead."
        foreach ($proc in $nginxRunning) {
            $proc.Terminate() | Out-Null
            $killed++
        }
    }

    # Belt-and-suspenders: `-s quit` can leave the process listed for a beat
    # while it finishes shutting down, or (rarely) not fully stop a wedged
    # worker. Anything still there for this project after the grace period
    # above gets force-terminated so stop.ps1 always leaves a clean slate.
    Get-WmiObject Win32_Process -Filter "Name='nginx.exe'" |
        Where-Object { $_.CommandLine -like "*$PROJECT*" } |
        ForEach-Object { $_.Terminate() | Out-Null }
}

# ── Kill php-cgi FastCGI worker pool tied to this project ───────────────────────
$phpCgiProcs = Get-WmiObject Win32_Process -Filter "Name='php-cgi.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*" }

foreach ($proc in $phpCgiProcs) {
    Write-Host "  Stopping php-cgi worker (PID $($proc.ProcessId))..."
    $proc.Terminate() | Out-Null
    $killed++
}

# ── Kill PHP processes tied to this project (queue:work, scheduler, any leftover artisan serve) ──
$phpProcs = Get-WmiObject Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like "*$PROJECT*" }

foreach ($proc in $phpProcs) {
    Write-Host "  Stopping PHP process (PID $($proc.ProcessId)): $($proc.CommandLine.Substring(0, [Math]::Min(80, $proc.CommandLine.Length)))..."
    $proc.Terminate() | Out-Null
    $killed++
}

# ── Kill Python ML services bound to ports 5001 / 5002 ──────────────────────────
foreach ($port in @(5001, 5002)) {
    $conn = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($conn) {
        $procId = $conn.OwningProcess
        $proc = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($proc) {
            Write-Host "  Stopping $($proc.ProcessName) on port $port (PID $procId)..."
            Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
            $killed++
        }
    }
}

# ── Kill any PowerShell scheduler loops for this project ────────────────────────
$psProcs = Get-WmiObject Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like "*scheduler_loop*" -or ($_.CommandLine -like "*$PROJECT*" -and $_.CommandLine -like "*start_services*") }

foreach ($proc in $psProcs) {
    Write-Host "  Stopping PowerShell process (PID $($proc.ProcessId))..."
    $proc.Terminate() | Out-Null
    $killed++
}

Write-Host ""
if ($killed -gt 0) {
    Write-Host " [ OK ] Stopped $killed process(es)."
} else {
    Write-Host " [ -- ] No running AgeSense processes found."
}

# ── Confirm ports 5001, 5002, 8000, and 9000-9003 are free ──────────────────────
Write-Host ""
Write-Host " Verifying ports are released..."
Start-Sleep -Seconds 1
$portsStillBound = 0
foreach ($port in @(5001, 5002, 8000, 9000, 9001, 9002, 9003)) {
    $conn = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($conn) {
        Write-Host " [WARN] Port $port still in use by PID $($conn.OwningProcess)."
        Write-Host "        Stop that process manually: Stop-Process -Id $($conn.OwningProcess) -Force"
        $portsStillBound++
    } else {
        Write-Host "  [ OK ] Port $port is free."
    }
}

Write-Host ""
if ($portsStillBound -eq 0) {
    Write-Host " All AgeSense services are offline. Ports 5001, 5002, 8000, and 9000-9003 are free."
} else {
    Write-Host " WARNING: $portsStillBound port(s) still bound. Check the processes listed above."
}

Write-Host ""
if (-not $Quiet) {
    Write-Host " You can now safely close this window."
    Write-Host ""
    Read-Host " Press Enter to exit"
}
