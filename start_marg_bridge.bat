@echo off
title Marg ERP WhatsApp Local Bridge Server
color 0A
cls
echo ============================================================
echo   Marg ERP 9+ WhatsApp Local Bridge Server Starting...
echo   (Zero Dependency Windows PowerShell Edition)
echo ============================================================
echo.
echo Running on: http://localhost:8080/
echo Keep this window minimized while using Marg ERP.
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0marg_bridge.ps1"
pause
