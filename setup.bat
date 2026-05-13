@echo off
chcp 65001 >nul
title AgeSense - First-Time Setup
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
if %ERRORLEVEL% NEQ 0 pause
