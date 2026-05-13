param([string]$PhpExe, [string]$ProjectDir)

while ($true) {
    & $PhpExe "$ProjectDir\artisan" schedule:run >> "$ProjectDir\storage\logs\scheduler.log" 2>&1
    Start-Sleep -Seconds 60
}
