<?php
/**
 * Marg ERP 9+ Local-to-Live PDF Bridge (Localhost Helper)
 * Captures the exact Marg ERP default PDF file generated on local Windows PC (C:\MARG\PDF\)
 * and forwards the exact PDF file + Bill details to Hostinger Live Server.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$live_gateway_url = 'https://friendlyaisolution.com/api/marg_erp_gateway.php';

// Extract incoming parameters
$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$recipient = $_GET['mob'] ?? $_POST['mob'] ?? '';
$message_body = $_GET['msg'] ?? $_POST['msg'] ?? '';
$pdf_url = $_GET['pdf_url'] ?? $_POST['pdf_url'] ?? '';

// Build full forwarding URL with query string
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$targetUrl = $live_gateway_url . (!empty($queryString) ? '?' . $queryString : '');

// Search local Windows directories for Marg ERP generated PDF
$searchDirs = [
    'C:/MARG/emailserver/',
    'C:/MARG/PDF/',
    'C:/MARG/Reports/',
    'C:/MARG/export/',
    'C:/MARG/temp/',
    'C:/MARG/files/',
    'C:/MARG/Others/',
    'C:/MARG/',
    'C:/MARGEXE/PDF/',
    'C:/MARGEXE/',
    'C:/MARG2026/PDF/',
    'C:/MARG2025/PDF/',
    'C:/xampp/htdocs/MARGLEAD/PDF/',
    sys_get_temp_dir() . '/'
];

$matchedFile = null;
$pdfCandidates = [];

foreach ($searchDirs as $dir) {
    if (!is_dir($dir)) continue;
    $dirFiles = @scandir($dir);
    if (empty($dirFiles)) continue;

    foreach ($dirFiles as $f) {
        if ($f === '.' || $f === '..') continue;
        $fullPath = $dir . $f;
        if (is_file($fullPath) && preg_match('/\.pdf$/i', $f) && filesize($fullPath) > 0) {
            $pdfCandidates[] = [
                'path' => $fullPath,
                'basename' => $f,
                'mtime' => filemtime($fullPath)
            ];
        }
    }
}

// Find the most recently created Marg default PDF (created within last 2 hours)
if (!empty($pdfCandidates)) {
    usort($pdfCandidates, function($a, $b) { return $b['mtime'] - $a['mtime']; });
    if ((time() - $pdfCandidates[0]['mtime']) < 7200) {
        $matchedFile = $pdfCandidates[0]['path'];
    }
}

// Prepare cURL POST payload to Live Hostinger Server
$postData = $_POST;

if ($matchedFile && file_exists($matchedFile)) {
    // Attach exact Marg default PDF file
    $postData['pdf'] = new CURLFile($matchedFile, 'application/pdf', basename($matchedFile));
    // Also include Base64 fallback payload for maximum reliability
    $postData['pdf_base64'] = base64_encode(file_get_contents($matchedFile));
}

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Bridge cURL error: ' . $curlErr]);
} else {
    http_response_code($httpCode);
    echo $response;
}
