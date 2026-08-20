<?php
/**
 * Marg ERP 9+ Client Setup Package Downloader Endpoint
 * Allows merchants/clients to download the 1-click Marg WhatsApp Connector Package.
 */
header("Access-Control-Allow-Origin: *");

$action = $_GET['action'] ?? 'download';
$apiKey = $_GET['api_key'] ?? 'MARG-WABA-7DE9514EA1E4EF2C';

if ($action === 'info') {
    header("Content-Type: application/json");
    echo json_encode([
        'status' => 'success',
        'download_url' => 'https://friendlyaisolution.com/api/download_bridge.php?action=download&api_key=' . urlencode($apiKey),
        'control_room_url' => 'http://localhost/marglead/api/marg_local_bridge.php?api_key=' . urlencode($apiKey) . '&mob={1}&msg={2}&pdf_url={PDF}',
        'batch_command' => 'C:\MARG\whatsapp.bat "' . $apiKey . '" "{1}" "{2}" "{PDF}"'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Generate ready-to-run setup zip / batch script download
header('Content-Type: application/x-bat');
header('Content-Disposition: attachment; filename="marg_whatsapp_setup.bat"');

echo "@echo off\r\n";
echo "title Marg ERP WhatsApp 1-Click Client Setup\r\n";
echo "echo ============================================================\r\n";
echo "echo   Marg ERP 9+ WhatsApp Connector Auto-Configurator\r\n";
echo "echo ============================================================\r\n";
echo "echo.\r\n";
echo "echo Copying whatsapp.bat to C:\\MARG\\whatsapp.bat ...\r\n";

echo "if not exist C:\\MARG mkdir C:\\MARG\r\n";

echo "@echo off > C:\\MARG\\whatsapp.bat\r\n";
echo "set API_KEY=%%~1 >> C:\\MARG\\whatsapp.bat\r\n";
echo "set MOB=%%~2 >> C:\\MARG\\whatsapp.bat\r\n";
echo "set MSG=%%~3 >> C:\\MARG\\whatsapp.bat\r\n";
echo "set PDF_PATH=%%~4 >> C:\\MARG\\whatsapp.bat\r\n";
echo "curl.exe -s -F \"api_key=%%API_KEY%%\" -F \"mob=%%MOB%%\" -F \"msg=%%MSG%%\" -F \"pdf=@%%PDF_PATH%%\" \"https://friendlyaisolution.com/api/marg_erp_gateway.php\" >> C:\\MARG\\whatsapp.bat\r\n";

echo "echo.\r\n";
echo "echo ============================================================\r\n";
echo "echo SETUP COMPLETED SUCCESSFULLY!\r\n";
echo "echo ============================================================\r\n";
echo "echo Copy and paste this String into Marg ERP Control Room:\r\n";
echo "echo.\r\n";
echo "echo C:\\MARG\\whatsapp.bat \"" . $apiKey . "\" \"{1}\" \"{2}\" \"{PDF}\"\r\n";
echo "echo.\r\n";
echo "echo Press any key to exit...\r\n";
echo "pause >nul\r\n";
exit;
