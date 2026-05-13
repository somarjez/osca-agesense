@echo off
chcp 65001 >nul
title AgeSense - OSCA System Launcher
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start.ps1"
