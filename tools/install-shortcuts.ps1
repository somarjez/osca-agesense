<#
.SYNOPSIS
    Creates "Start OSCA System" / "Stop OSCA System" shortcut icons on the
    Desktop and in the Start Menu, pointing at the windowless launch/stop
    scripts. Per-user locations only — no admin rights required.

    Safe to re-run (e.g. after moving the project folder): it always
    regenerates the shortcuts with the current path.
#>
#Requires -Version 5.1
$ErrorActionPreference = 'Stop'

$PROJECT = Split-Path -Parent $PSScriptRoot

Write-Host ""
Write-Host " =========================================="
Write-Host "  AgeSense OSCA System - Shortcut Installer"
Write-Host " =========================================="
Write-Host ""

# ── Ensure branded icons exist ───────────────────────────────────────────────
$startIco = "$PROJECT\resources\branding\osca.ico"
$stopIco  = "$PROJECT\resources\branding\osca-stop.ico"
if (-not (Test-Path $startIco) -or -not (Test-Path $stopIco)) {
    Write-Host " Icons not found - generating them..."
    & "$PSScriptRoot\make-icons.ps1"
}

# ── Build shortcut targets ──────────────────────────────────────────────────
$wscriptExe = "$env:WINDIR\System32\wscript.exe"
$shell = New-Object -ComObject WScript.Shell

function New-OscaShortcut {
    param(
        [string]$LnkPath,
        [string]$VbsScript,
        [string]$IconPath,
        [string]$Description
    )
    $sc = $shell.CreateShortcut($LnkPath)
    $sc.TargetPath       = $wscriptExe
    $sc.Arguments        = "`"$PROJECT\$VbsScript`""
    $sc.WorkingDirectory  = $PROJECT
    $sc.IconLocation     = "$IconPath,0"
    $sc.Description      = $Description
    $sc.Save()
}

# ── Destinations: Desktop + per-user Start Menu ─────────────────────────────
$desktop   = [Environment]::GetFolderPath('Desktop')
$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) 'AgeSense OSCA'
if (-not (Test-Path $startMenu)) { New-Item -ItemType Directory -Path $startMenu -Force | Out-Null }

$targets = @($desktop, $startMenu)

foreach ($dir in $targets) {
    New-OscaShortcut -LnkPath (Join-Path $dir 'Start OSCA System.lnk') `
                      -VbsScript 'launch-osca.vbs' -IconPath $startIco `
                      -Description 'Start the AgeSense OSCA System (opens in your browser)'

    New-OscaShortcut -LnkPath (Join-Path $dir 'Stop OSCA System.lnk') `
                      -VbsScript 'stop-osca.vbs' -IconPath $stopIco `
                      -Description 'Stop the AgeSense OSCA System'

    Write-Host " [ OK ] Shortcuts created in: $dir"
}

Write-Host ""
Write-Host " -----------------------------------------------"
Write-Host "  Done! 'Start OSCA System' and 'Stop OSCA System'"
Write-Host "  icons are now on your Desktop and in the Start Menu"
Write-Host "  (under 'AgeSense OSCA')."
Write-Host ""
Write-Host "  The Desktop icons can be copied to any folder or"
Write-Host "  pinned to the Taskbar and will still work."
Write-Host ""
Write-Host "  NOTE: if this project folder is ever MOVED to a"
Write-Host "  different location, re-run Install-Shortcuts.bat"
Write-Host "  so the shortcuts point at the new path."
Write-Host " -----------------------------------------------"
Write-Host ""
