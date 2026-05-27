<#
.SYNOPSIS
    AgeSense OSCA smoke test. Authenticates as admin, verifies all key routes.
    Exits 0 if all pass, 1 if any fail.

.PARAMETER Base       Root URL. Default: http://127.0.0.1:8000
.PARAMETER Email      Admin email. Default: admin@osca.local
.PARAMETER Password   Admin password. Default from $env:OSCA_ADMIN_PASSWORD.
.PARAMETER StartFirst If set, launches start.ps1 then waits 10 s before probing.

.EXAMPLE
    .\smoke.ps1 -Password "Admin@OSCA2026!"
    .\smoke.ps1 -Password "Admin@OSCA2026!" -StartFirst
#>
param(
    [string]$Base     = "http://127.0.0.1:8000",
    [string]$Email    = "admin@osca.local",
    [string]$Password = $env:OSCA_ADMIN_PASSWORD,
    [switch]$StartFirst
)

$ErrorActionPreference = "Stop"
$SCRIPT_DIR = Split-Path -Parent $MyInvocation.MyCommand.Path
# .claude/skills/run-osca-system/ is 4 levels inside the project root
$PROJECT = (Get-Item $SCRIPT_DIR).Parent.Parent.Parent.Parent.FullName

# Optional launch
if ($StartFirst) {
    Write-Host "[boot] Running start.ps1 ..." -ForegroundColor Cyan
    Start-Process powershell.exe -ArgumentList "-NoProfile","-File","`"$PROJECT\start.ps1`"" -WindowStyle Hidden
    Write-Host "[boot] Waiting 10 s..." -ForegroundColor Cyan
    Start-Sleep -Seconds 10
}

$script:pass = 0
$script:fail = 0
$script:sess = $null

function ok  { param($lbl,$sc,$sz) Write-Host ("PASS  {0,-44} {1}  ({2} bytes)" -f $lbl,$sc,$sz) -ForegroundColor Green; $script:pass++ }
function err { param($lbl,$msg)    Write-Host ("FAIL  {0,-44} {1}" -f $lbl,$msg) -ForegroundColor Red;  $script:fail++ }

function probe {
    param([string]$Label, [string]$Url, [switch]$Auth, [string]$Contains)
    try {
        $a = @{ Uri=$Url; UseBasicParsing=$true; MaximumRedirection=10 }
        if ($Auth -and $script:sess) { $a.WebSession = $script:sess }
        $r = Invoke-WebRequest @a
        if ($Contains -and $r.Content -notmatch [regex]::Escape($Contains)) {
            err $Label "text '$Contains' not in response"
            return $null
        }
        ok $Label $r.StatusCode $r.Content.Length
        return $r
    } catch {
        $sc = $null; try { $sc = [int]$_.Exception.Response.StatusCode } catch {}
        err $Label (if ($null -ne $sc) { $sc } else { $_.Exception.Message })
        return $null
    }
}

# ── 1. ML service health ─────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== ML Services ===" -ForegroundColor Cyan
[void](probe "Preprocess :5001/health" "http://127.0.0.1:5001/health" -Contains "status")
[void](probe "Inference  :5002/health" "http://127.0.0.1:5002/health" -Contains "status")

# ── 2. Authentication ────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== Authentication ===" -ForegroundColor Cyan

# GET login - capture session
$lg = Invoke-WebRequest -Uri "$Base/login" -SessionVariable newSess -UseBasicParsing 2>$null
$script:sess = $newSess
if ($lg.StatusCode -eq 200 -and $lg.Content -match "AgeSense") {
    ok "GET /login" $lg.StatusCode $lg.Content.Length
} else {
    err "GET /login" "unexpected response"
    exit 1
}

# Extract CSRF token
if ($lg.Content -match 'name="_token"\s+value="([^"]+)"') {
    $csrf = $Matches[1]
} else {
    err "CSRF token" "not found in login page"
    exit 1
}

# POST credentials
try {
    $ar = Invoke-WebRequest -Uri "$Base/login" -Method POST `
        -Body @{ email=$Email; password=$Password; _token=$csrf } `
        -WebSession $script:sess -UseBasicParsing -MaximumRedirection 10 2>$null
    $landed = $ar.BaseResponse.ResponseUri.ToString()
    if ($landed -match "dashboard") {
        ok "POST /login -> dashboard" $ar.StatusCode $ar.Content.Length
    } else {
        err "POST /login" "landed on $landed (wrong password?)"
        exit 1
    }
} catch {
    err "POST /login" $_.Exception.Message
    exit 1
}

# ── 3. Authenticated pages ───────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== Authenticated Pages ===" -ForegroundColor Cyan
[void](probe "GET /dashboard"          "$Base/dashboard"          -Auth)
[void](probe "GET /ml/status"          "$Base/ml/status"          -Auth)
[void](probe "GET /seniors"            "$Base/seniors"            -Auth)
[void](probe "GET /reports/cluster"    "$Base/reports/cluster"    -Auth)
[void](probe "GET /reports/risk"       "$Base/reports/risk"       -Auth)
[void](probe "GET /reports/gis"        "$Base/reports/gis"        -Auth)
[void](probe "GET /reports/barangay"   "$Base/reports/barangay"   -Auth)
[void](probe "GET /help"               "$Base/help"               -Auth)
[void](probe "GET /activity-log"       "$Base/activity-log"       -Auth)

# ── 4. GIS JSON API ──────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== GIS API ===" -ForegroundColor Cyan
try {
    $gis = Invoke-WebRequest -Uri "$Base/api/gis/seniors" -WebSession $script:sess -UseBasicParsing 2>$null
    $count = ($gis.Content | ConvertFrom-Json).features.Count
    ok "GET /api/gis/seniors" $gis.StatusCode "$count features"
} catch {
    err "GET /api/gis/seniors" $_.Exception.Message
}

# ── Summary ──────────────────────────────────────────────────────────────────────
$total = $script:pass + $script:fail
Write-Host ""
Write-Host "-------------------------------------------------------------" -ForegroundColor DarkGray
if ($script:fail -eq 0) {
    Write-Host ("  ALL PASS  {0}/{1} checks OK" -f $script:pass, $total) -ForegroundColor Green
} else {
    Write-Host ("  FAILURES  {0}/{1} checks FAILED" -f $script:fail, $total) -ForegroundColor Red
}
Write-Host "-------------------------------------------------------------" -ForegroundColor DarkGray

if ($script:fail -gt 0) { exit 1 }
