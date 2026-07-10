#Requires -Version 5.1
<#
    AgeSense OSCA System - Database Backup
    ---------------------------------------
    Dumps the project's MySQL database (credentials read from .env) into
    database\backups\ with a timestamped filename.

    Usage (from repo root):
        .\backup-database.ps1

    See docs\BACKUP_AND_RECOVERY.md for restore instructions and storage
    guidance (these dumps contain unencrypted senior-citizen PII and must
    never be committed to git or shared over insecure channels).
#>

$ErrorActionPreference = 'Stop'
$PROJECT = Split-Path -Parent $MyInvocation.MyCommand.Path

function Bail($msg) {
    Write-Host ""
    Write-Host " [FAIL] $msg"
    Write-Host ""
    exit 1
}

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - Database Backup"
Write-Host " =========================================="
Write-Host ""

# ── STEP 1: Locate and parse .env ───────────────────────────────────────────
$envPath = Join-Path $PROJECT ".env"

if (-not (Test-Path $envPath)) {
    Bail ".env not found at $envPath`n        Copy .env.example to .env and configure it first."
}

$envValues = @{}
foreach ($line in Get-Content $envPath) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }

    # Match KEY=VALUE (value may be blank, quoted, or unquoted).
    if ($trimmed -match '^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
        $key = $Matches[1]
        $val = $Matches[2].Trim()

        # Strip a single pair of surrounding quotes, if present.
        if ($val.Length -ge 2 -and (
            ($val.StartsWith('"') -and $val.EndsWith('"')) -or
            ($val.StartsWith("'") -and $val.EndsWith("'"))
        )) {
            $val = $val.Substring(1, $val.Length - 2)
        }

        $envValues[$key] = $val
    }
}

$dbConnection = $envValues['DB_CONNECTION']
$dbDatabase   = $envValues['DB_DATABASE']
$dbHost       = $envValues['DB_HOST']
$dbPort       = $envValues['DB_PORT']
$dbUsername   = $envValues['DB_USERNAME']
$dbPassword   = $envValues['DB_PASSWORD']   # may legitimately be blank locally

if ([string]::IsNullOrWhiteSpace($dbDatabase)) {
    Bail "DB_DATABASE is not set in .env - nothing to back up."
}
if ([string]::IsNullOrWhiteSpace($dbUsername)) {
    Bail "DB_USERNAME is not set in .env."
}
if ([string]::IsNullOrWhiteSpace($dbHost)) { $dbHost = '127.0.0.1' }
if ([string]::IsNullOrWhiteSpace($dbPort)) { $dbPort = '3306' }

if ($dbConnection -and $dbConnection -ne 'mysql') {
    Write-Host " [WARN] DB_CONNECTION is '$dbConnection', not 'mysql'."
    Write-Host "        This script only supports mysqldump-based backups."
    Bail "Unsupported DB_CONNECTION for this backup script."
}

Write-Host "  [ OK ] .env loaded (database: $dbDatabase, host: ${dbHost}:${dbPort}, user: $dbUsername)"

# ── STEP 2: Find mysqldump ──────────────────────────────────────────────────
$MYSQLDUMP = $null
$onPath = Get-Command mysqldump -ErrorAction SilentlyContinue
if ($onPath) { $MYSQLDUMP = $onPath.Source }

if (-not $MYSQLDUMP) {
    $candidates = @()
    $candidates += Get-ChildItem "$env:USERPROFILE\laragon\bin\mysql\*\bin\mysqldump.exe" -ErrorAction SilentlyContinue
    $candidates += Get-ChildItem "C:\laragon\bin\mysql\*\bin\mysqldump.exe" -ErrorAction SilentlyContinue
    $candidates += Get-ChildItem "C:\xampp\mysql\bin\mysqldump.exe" -ErrorAction SilentlyContinue
    $candidates += Get-ChildItem "D:\xampp\mysql\bin\mysqldump.exe" -ErrorAction SilentlyContinue
    $candidates += Get-ChildItem "C:\Program Files\MySQL\MySQL Server*\bin\mysqldump.exe" -ErrorAction SilentlyContinue

    $found = $candidates | Where-Object { $_ } | Select-Object -First 1
    if ($found) { $MYSQLDUMP = $found.FullName }
}

if (-not $MYSQLDUMP) {
    Bail "mysqldump not found on PATH or in common Laragon/XAMPP/MySQL install locations.`n        Install MySQL client tools or add mysqldump.exe's folder to PATH, then retry.`n        (Not producing an empty/partial dump - refusing to proceed.)"
}

Write-Host "  [ OK ] mysqldump found: $MYSQLDUMP"

# ── STEP 3: Prepare output path ─────────────────────────────────────────────
$backupsDir = Join-Path $PROJECT "database\backups"
if (-not (Test-Path $backupsDir)) {
    New-Item -ItemType Directory -Path $backupsDir -Force | Out-Null
}

$timestamp  = Get-Date -Format "yyyyMMdd_HHmmss"
$outputFile = Join-Path $backupsDir "${dbDatabase}_backup_${timestamp}.sql"

Write-Host "  [ OK ] Output file: $outputFile"
Write-Host ""
Write-Host " -- Running mysqldump --"
Write-Host ""

# ── STEP 4: Run mysqldump ───────────────────────────────────────────────────
# Password is passed via the MYSQL_PWD environment variable (not as a CLI
# argument) so it never appears in process listings or shell history.
$previousMysqlPwd = $env:MYSQL_PWD
$env:MYSQL_PWD = $dbPassword

$mysqldumpArgs = @(
    "--host=$dbHost",
    "--port=$dbPort",
    "--user=$dbUsername",
    "--routines",
    "--triggers",
    "--single-transaction",
    "--default-character-set=utf8mb4",
    "--result-file=$outputFile",
    $dbDatabase
)

$exitCode = 0
try {
    & $MYSQLDUMP @mysqldumpArgs
    $exitCode = $LASTEXITCODE
} finally {
    # Restore/clear the env var immediately so the password doesn't linger
    # in this shell session any longer than necessary.
    if ($null -eq $previousMysqlPwd) {
        Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    } else {
        $env:MYSQL_PWD = $previousMysqlPwd
    }
}

# ── STEP 5: Verify the result ───────────────────────────────────────────────
if ($exitCode -ne 0) {
    if (Test-Path $outputFile) { Remove-Item $outputFile -Force -ErrorAction SilentlyContinue }
    Bail "mysqldump exited with code $exitCode. No backup file was kept.`n        Check that MySQL is running and the credentials in .env are correct."
}

if (-not (Test-Path $outputFile)) {
    Bail "mysqldump reported success but no output file was created at $outputFile."
}

$fileInfo = Get-Item $outputFile
if ($fileInfo.Length -eq 0) {
    Remove-Item $outputFile -Force -ErrorAction SilentlyContinue
    Bail "mysqldump produced a 0-byte file. Removed it. Check DB connectivity/credentials and try again."
}

$sizeKb = [Math]::Round($fileInfo.Length / 1KB, 1)
$sizeDisplay = if ($fileInfo.Length -ge 1MB) {
    "$([Math]::Round($fileInfo.Length / 1MB, 2)) MB"
} else {
    "$sizeKb KB"
}

Write-Host ""
Write-Host " =========================================="
Write-Host "  Backup complete"
Write-Host " =========================================="
Write-Host ""
Write-Host "  [ OK ] Database : $dbDatabase"
Write-Host "  [ OK ] File     : $outputFile"
Write-Host "  [ OK ] Size     : $sizeDisplay"
Write-Host ""
Write-Host "  Reminder: this file contains senior-citizen PII. Do not commit it"
Write-Host "  to git (already gitignored) and do not share it over insecure"
Write-Host "  channels. See docs\BACKUP_AND_RECOVERY.md."
Write-Host ""

exit 0
