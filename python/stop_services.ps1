$ErrorActionPreference = 'Stop'

# ── Release any listeners on 5001/5002 ────────────────────────────────────────
Write-Output "[ML] Stopping ML services on ports 5001/5002..."

$listeners = netstat -ano | Select-String 'LISTENING' | Select-String ':5001|:5002'
foreach ($line in $listeners) {
    $parts = ($line -replace '\s+', ' ').Trim().Split(' ')
    $listenerPid = [int]$parts[-1]
    if ($listenerPid -gt 0) {
        Stop-Process -Id $listenerPid -Force -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 1

# ── Confirm ports are free ────────────────────────────────────────────────────
$allFree = $true
foreach ($port in @(5001, 5002)) {
    $still = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($still) {
        Write-Output "[ML][WARN] Port $port still in use by PID $($still.OwningProcess) after kill attempt."
        $allFree = $false
    } else {
        Write-Output "[ML]  Port $port : free"
    }
}

if ($allFree) {
    Write-Output "[ML] ML services stopped."
} else {
    Write-Output "[ML] One or more ports could not be released. Check Task Manager for lingering python.exe processes."
}
