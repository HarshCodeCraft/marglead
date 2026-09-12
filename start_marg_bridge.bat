@echo off
title Marg ERP - Python Cloud Bridge
color 0B
echo ===================================================================
echo     Marg ERP 9+ Auto-Sync Python Bridge
echo ===================================================================
echo.
cd /d "%~dp0"

where python >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Python is not installed or not in PATH!
    pause
    exit /b 1
)

python marg_bridge.py
pause