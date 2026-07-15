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

# ── Kill PHP processes tied to this project (artisan serve, queue:work, scheduler) ──
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

# ── Confirm ports 5001 and 5002 are free ────────────────────────────────────
Write-Host ""
Write-Host " Verifying ports are released..."
Start-Sleep -Seconds 1
$portsStillBound = 0
foreach ($port in @(5001, 5002)) {
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
    Write-Host " All AgeSense services are offline. Ports 5001 and 5002 are free."
} else {
    Write-Host " WARNING: $portsStillBound port(s) still bound. Check the processes listed above."
}

Write-Host ""
if (-not $Quiet) {
    Write-Host " You can now safely close this window."
    Write-Host ""
    Read-Host " Press Enter to exit"
}
