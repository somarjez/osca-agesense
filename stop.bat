@echo off
chcp 65001 >nul
title AgeSense - Stopping System
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop.ps1"
