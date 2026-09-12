@echo off
title Marg ERP - WhatsApp Web Engine
color 0A
echo ===================================================================
echo     Marg ERP 9+ WhatsApp Web Engine (Port 3005)
echo ===================================================================
echo.
cd /d "%~dp0whatsapp_engine"

rem Check if Node.js is installed
where node >nul 2>nul
if %errorlevel% neq 0 (
    color 0C
    echo [ERROR] Node.js is not found in PATH!
    echo Please install Node.js from https://nodejs.org
    pause
    exit /b 1
)

rem Check if node_modules exists
if not exist "node_modules\" (
    echo [INFO] Installing required dependencies (first-time only)...
    call npm install
)

echo [INFO] Starting WhatsApp Web Engine on port 3005...
echo [INFO] Keep this window open in background to keep WhatsApp paired.
echo.

:loop
node index.js
echo.
echo [WARNING] WhatsApp Engine stopped or crashed. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
