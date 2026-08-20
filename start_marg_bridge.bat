@echo off
title Marg ERP WhatsApp Bridge Server
color 0A
echo ============================================================
echo   Marg ERP 9+ WhatsApp Local Bridge Server Starting...
echo ============================================================
echo.
echo Bridge Server running on http://localhost:8080/
echo Keep this window minimized while using Marg ERP.
echo.

php.exe -S localhost:8080 -t "%~dp0"
pause
