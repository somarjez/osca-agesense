@echo off
chcp 65001 >nul
title AgeSense - Install Desktop/Start Menu Shortcuts
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\install-shortcuts.ps1"
pause
