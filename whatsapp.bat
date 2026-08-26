@echo off
rem ============================================================
rem Marg ERP 9+ WhatsApp PDF Auto-Uploader Batch Script
rem Automatically uploads Marg generated PDF invoices to Hostinger Live Server
rem Multi-Drive & Shareable Disk Compatible (C:, D:, E:, F:, etc.)
rem ============================================================

set API_KEY=%~1
set MOB=%~2
set MSG=%~3
set PDF_PATH=%~f4

if defined PDF_PATH (
    if exist "%PDF_PATH%" (
        if not exist "%PDF_PATH%\" (
            curl.exe -s -F "api_key=%API_KEY%" -F "mob=%MOB%" -F "msg=%MSG%" -F "pdf=@%PDF_PATH%" "https://friendlyaisolution.com/api/marg_erp_gateway.php"
            exit /b
        )
    )
)

curl.exe -s -F "api_key=%API_KEY%" -F "mob=%MOB%" -F "msg=%MSG%" "https://friendlyaisolution.com/api/marg_erp_gateway.php"

