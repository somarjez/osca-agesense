#Requires -Version 5.1
$PROJECT = Split-Path -Parent $MyInvocation.MyCommand.Path

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - Stopping"
Write-Host " =========================================="
Write-Host ""

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
        $pid = $conn.OwningProcess
        $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
        if ($proc) {
            Write-Host "  Stopping $($proc.ProcessName) on port $port (PID $pid)..."
            Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
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
    Write-Host " [ OK ] Stopped $killed process(es). All AgeSense services are offline."
} else {
    Write-Host " [ -- ] No running AgeSense processes found."
}

Write-Host ""
Write-Host " You can now safely close this window."
Write-Host ""
Read-Host " Press Enter to exit"
