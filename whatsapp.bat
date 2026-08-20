@echo off
rem ============================================================
rem Marg ERP 9+ WhatsApp PDF Auto-Uploader Batch Script
rem Automatically uploads Marg generated PDF invoices to Hostinger Live Server
rem ============================================================

set API_KEY=%~1
set MOB=%~2
set MSG=%~3
set PDF_PATH=%~4

curl.exe -s -F "api_key=%API_KEY%" -F "mob=%MOB%" -F "msg=%MSG%" -F "pdf=@%PDF_PATH%" "https://friendlyaisolution.com/api/marg_erp_gateway.php"
