$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = Split-Path -Parent $scriptDir
$servicesDir = Join-Path $scriptDir 'services'
$venvPython = Join-Path $scriptDir 'venv\Scripts\python.exe'
$logsDir = Join-Path $projectDir 'storage\logs'

# Read .env so values are consistent regardless of system environment variables
$dotenv = @{}
$envFile = Join-Path $projectDir '.env'
if (Test-Path $envFile) {
    Get-Content $envFile | Where-Object { $_ -match '^[A-Z_]+=.+' -and $_ -notmatch '^#' } | ForEach-Object {
        $k, $v = $_ -split '=', 2
        $dotenv[$k.Trim()] = $v.Trim().Trim('"').Trim("'")
    }
}

$modelsPath = if ($dotenv['ML_MODELS_PATH']) { $dotenv['ML_MODELS_PATH'] } `
              elseif ($env:ML_MODELS_PATH)    { $env:ML_MODELS_PATH } `
              else                            { Join-Path $scriptDir 'models' }
$enableNotebookOverrides = if ($dotenv.ContainsKey('ENABLE_NOTEBOOK_OVERRIDES')) { $dotenv['ENABLE_NOTEBOOK_OVERRIDES'] } `
                           elseif ($env:ENABLE_NOTEBOOK_OVERRIDES)               { $env:ENABLE_NOTEBOOK_OVERRIDES } `
                           else                                                  { 'false' }

# Resolve relative ML_MODELS_PATH relative to project root
if ($modelsPath -and -not [System.IO.Path]::IsPathRooted($modelsPath)) {
    $modelsPath = Join-Path $projectDir $modelsPath
}

if (-not (Test-Path $venvPython)) {
    Write-Output "[ML] Venv not found - creating it now (first run takes a few minutes)..."

    # Find any system Python to bootstrap the venv
    $pythonCmd = Get-Command python -ErrorAction SilentlyContinue
    $systemPython = if ($pythonCmd) { $pythonCmd.Source } else { $null }
    if (-not $systemPython) {
        $python3Cmd = Get-Command python3 -ErrorAction SilentlyContinue
        $systemPython = if ($python3Cmd) { $python3Cmd.Source } else { $null }
    }
    if (-not $systemPython) {
        Write-Error "[ML] No Python found on PATH. Install Python 3.10+ and re-run."
        exit 1
    }

    # Create venv
    $venvDir = Join-Path $scriptDir 'venv'
    & $systemPython -m venv $venvDir
    if ($LASTEXITCODE -ne 0) {
        Write-Error "[ML] Failed to create venv."
        exit 1
    }

    # Install dependencies
    $venvPip = Join-Path $scriptDir 'venv\Scripts\pip.exe'
    $requirements = Join-Path $scriptDir 'requirements.txt'
    Write-Output "[ML] Installing Python dependencies (this may take several minutes)..."
    & $venvPip install -r $requirements
    if ($LASTEXITCODE -ne 0) {
        Write-Error "[ML] pip install failed. Check python/requirements.txt."
        exit 1
    }

    Write-Output "[ML] Venv ready."
}

New-Item -ItemType Directory -Force -Path $logsDir | Out-Null

# ── Release any existing listeners on 5001/5002 ──────────────────────────────
$listeners = netstat -ano | Select-String 'LISTENING' | Select-String ':5001|:5002'
foreach ($line in $listeners) {
    $parts = ($line -replace '\s+', ' ').Trim().Split(' ')
    $listenerPid = [int]$parts[-1]
    if ($listenerPid -gt 0) {
        Stop-Process -Id $listenerPid -Force -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 1

# Confirm ports are free before starting
foreach ($port in @(5001, 5002)) {
    $still = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($still) {
        Write-Output "[ML][WARN] Port $port still in use by PID $($still.OwningProcess) after kill attempt."
    }
}

# ── Log file paths (used by start.ps1 for log hints) ─────────────────────────
$preprocessLog = Join-Path $logsDir 'python-preprocess.log'
$preprocessErr = Join-Path $logsDir 'python-preprocess.err.log'
$inferenceLog  = Join-Path $logsDir 'python-inference.log'
$inferenceErr  = Join-Path $logsDir 'python-inference.err.log'

# ── Spawn services using venv python exclusively ──────────────────────────────
$preprocessCmd = "`$env:ML_MODELS_PATH='$modelsPath'; `$env:ENABLE_NOTEBOOK_OVERRIDES='$enableNotebookOverrides'; `$env:PREPROCESS_PORT='5001'; Set-Location '$projectDir'; & '$venvPython' '$servicesDir\preprocess_service.py'"
$inferenceCmd  = "`$env:ML_MODELS_PATH='$modelsPath'; `$env:ENABLE_NOTEBOOK_OVERRIDES='$enableNotebookOverrides'; `$env:INFERENCE_PORT='5002'; Set-Location '$projectDir'; & '$venvPython' '$servicesDir\inference_service.py'"

Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', $preprocessCmd) -RedirectStandardOutput $preprocessLog -RedirectStandardError $preprocessErr -WindowStyle Hidden | Out-Null
Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', $inferenceCmd)  -RedirectStandardOutput $inferenceLog  -RedirectStandardError $inferenceErr  -WindowStyle Hidden | Out-Null

Write-Output "[ML] ML_MODELS_PATH=$modelsPath"
Write-Output "[ML] ENABLE_NOTEBOOK_OVERRIDES=$enableNotebookOverrides"
Write-Output "[ML] Preprocess log : storage\logs\python-preprocess.log"
Write-Output "[ML] Inference log  : storage\logs\python-inference.log"
Write-Output "[ML] Waiting for services to start (UMAP loads ~30s on first run)..."

# ── Poll /health on both services (up to 60 seconds) ─────────────────────────
function Wait-ServiceHealth($name, $url, $timeoutSec) {
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    $ok = $false
    while ((Get-Date) -lt $deadline) {
        try {
            $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
            if ($resp.StatusCode -eq 200) { $ok = $true; break }
        } catch {}
        Start-Sleep -Seconds 2
    }
    if ($ok) {
        Write-Output "[ML]  [ OK ] $name is healthy ($url)"
    } else {
        Write-Output "[ML] [WARN] $name did not respond within ${timeoutSec}s. Check log for errors."
    }
    return $ok
}

$ppOk  = Wait-ServiceHealth "Preprocess service" "http://127.0.0.1:5001/health" 60
$infOk = Wait-ServiceHealth "Inference service"  "http://127.0.0.1:5002/health" 60

# ── Final port confirmation ───────────────────────────────────────────────────
Write-Output ""
Write-Output "[ML] Port status after startup:"
netstat -ano | Select-String ':5001|:5002' | Select-String 'LISTENING'

foreach ($port in @(5001, 5002)) {
    $conn = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($conn) {
        Write-Output "[ML]  Port $port : LISTENING (PID $($conn.OwningProcess))"
    } else {
        Write-Output "[ML]  Port $port : NOT listening — service may have crashed. Check the log."
    }
}

if (-not $ppOk -or -not $infOk) {
    Write-Output ""
    Write-Output "[ML] One or more services failed to start. Tail the logs for details:"
    Write-Output "     Get-Content storage\logs\python-preprocess.err.log -Tail 30"
    Write-Output "     Get-Content storage\logs\python-inference.err.log  -Tail 30"
}
