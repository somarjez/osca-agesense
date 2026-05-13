$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = Split-Path -Parent $scriptDir
$servicesDir = Join-Path $scriptDir 'services'
$venvPython = Join-Path $scriptDir 'venv\Scripts\python.exe'
$logsDir = Join-Path $projectDir 'storage\logs'
$modelsPath = if ($env:ML_MODELS_PATH) { $env:ML_MODELS_PATH } else { Join-Path $scriptDir 'models' }
$enableNotebookOverrides = if ($env:ENABLE_NOTEBOOK_OVERRIDES) { $env:ENABLE_NOTEBOOK_OVERRIDES } else { 'true' }

if (-not (Test-Path $venvPython)) {
    throw "Python venv not found at $venvPython"
}

New-Item -ItemType Directory -Force -Path $logsDir | Out-Null

$listeners = netstat -ano | Select-String 'LISTENING' | Select-String ':5001|:5002'
foreach ($line in $listeners) {
    $parts = ($line -replace '\s+', ' ').Trim().Split(' ')
    $listenerPid = [int]$parts[-1]
    if ($listenerPid -gt 0) {
        Stop-Process -Id $listenerPid -Force -ErrorAction SilentlyContinue
    }
}

Start-Sleep -Seconds 1

$preprocessLog = Join-Path $logsDir 'preprocess.log'
$preprocessErr = Join-Path $logsDir 'preprocess.err.log'
$inferenceLog = Join-Path $logsDir 'inference.log'
$inferenceErr = Join-Path $logsDir 'inference.err.log'

$preprocessCmd = "`$env:ML_MODELS_PATH='$modelsPath'; `$env:ENABLE_NOTEBOOK_OVERRIDES='$enableNotebookOverrides'; `$env:PREPROCESS_PORT='5001'; Set-Location '$projectDir'; & '$venvPython' '$servicesDir\preprocess_service.py'"
$inferenceCmd = "`$env:ML_MODELS_PATH='$modelsPath'; `$env:ENABLE_NOTEBOOK_OVERRIDES='$enableNotebookOverrides'; `$env:INFERENCE_PORT='5002'; Set-Location '$projectDir'; & '$venvPython' '$servicesDir\inference_service.py'"

Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', $preprocessCmd) -RedirectStandardOutput $preprocessLog -RedirectStandardError $preprocessErr -WindowStyle Hidden | Out-Null
Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', $inferenceCmd) -RedirectStandardOutput $inferenceLog -RedirectStandardError $inferenceErr -WindowStyle Hidden | Out-Null

Start-Sleep -Seconds 4

Write-Output "ML_MODELS_PATH=$modelsPath"
Write-Output "ENABLE_NOTEBOOK_OVERRIDES=$enableNotebookOverrides"
netstat -ano | Select-String ':5001|:5002'
