<?php
/**
 * Marg ERP 9+ Local-to-Live PDF Bridge (Localhost Helper)
 * Captures the exact Marg ERP default PDF file generated on local Windows PC
 * and forwards the exact PDF file + Bill details to Hostinger Live Server.
 */
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$live_gateway_url = 'https://friendlyaisolution.com/api/marg_erp_gateway.php';
$log_dir = __DIR__ . '/../logs/';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$log_file = $log_dir . 'bridge.log';

function logBridge($msg) {
    global $log_file;
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Extract incoming parameters
$api_key      = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$recipient    = $_GET['mob'] ?? $_POST['mob'] ?? '';
$message_body = $_GET['msg'] ?? $_POST['msg'] ?? '';
$pdf_url      = $_GET['pdf_url'] ?? $_POST['pdf_url'] ?? '';

logBridge("Bridge invoked. Recipient: $recipient | pdf_url: $pdf_url | Query: " . ($_SERVER['QUERY_STRING'] ?? ''));

// Build full forwarding URL with query string
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$targetUrl = $live_gateway_url . (!empty($queryString) ? '?' . $queryString : '');

// Search local Windows directories across C:, D:, E:, F: drives for Marg ERP generated PDF
$searchDirs = [
    'C:/MARG/emailserver/pdf/',
    'C:/MARG/emailserver/',
    'C:/MARG/PDF/',
    'C:/MARG/Reports/',
    'C:/MARG/export/',
    'C:/MARG/temp/',
    'C:/MARG/files/',
    'C:/MARG/Others/',
    'C:/MARG/',
    'C:/MARGWIN/emailserver/pdf/',
    'C:/MARGWIN/emailserver/',
    'C:/MARGWIN/PDF/',
    'C:/MARGWIN/',
    'C:/MARGEXE/PDF/',
    'C:/MARGEXE/',
    'D:/MARG/emailserver/pdf/',
    'D:/MARG/emailserver/',
    'D:/MARG/PDF/',
    'D:/MARG/',
    'E:/MARG/emailserver/pdf/',
    'E:/MARG/emailserver/',
    'E:/MARG/PDF/',
    'E:/MARG/',
    sys_get_temp_dir() . '/'
];

$matchedFile = null;

// Strategy 1: Check explicit pdf_url parameter passed by Marg ERP (with retry loop for async writing)
if (!empty($pdf_url) && $pdf_url !== '{PDF}') {
    $rawPath = trim(rawurldecode($pdf_url), " \"'\t\n\r");
    $normalizedPath = str_replace('\\', '/', $rawPath);
    logBridge("Checking explicit pdf_url path: $normalizedPath");

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if (file_exists($normalizedPath) && is_file($normalizedPath) && filesize($normalizedPath) > 0) {
            $matchedFile = $normalizedPath;
            logBridge("Match found via explicit pdf_url: $matchedFile (Attempt $attempt)");
            break;
        }
        usleep(300000); // Wait 300ms before retry
    }
}

// Extract Bill Number from message body for fallback scanning
preg_match('/(?:Invoice|Bill|No\.?|#)\s*:?\s*([A-Za-z0-9_\-]+)/i', $message_body, $mBill);
$billNo = $mBill[1] ?? '';
$cleanBillNo = preg_replace('/[^A-Za-z0-9]/', '', $billNo);

// Strategy 2: Scan Marg directories for PDF candidates
if (!$matchedFile) {
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
                    'mtime' => filemtime($fullPath),
                    'clean' => preg_replace('/[^A-Za-z0-9]/', '', $f)
                ];
            }
        }
    }

    // Match by Bill Number in filename created within last 24 hours
    if (!empty($cleanBillNo) && !empty($pdfCandidates)) {
        foreach ($pdfCandidates as $cand) {
            if (stripos($cand['clean'], $cleanBillNo) !== false && (time() - $cand['mtime'] < 86400)) {
                $matchedFile = $cand['path'];
                logBridge("Match found by Bill Number ($billNo) in filename: $matchedFile");
                break;
            }
        }
    }

    // Match most recently created PDF file created within last 30 minutes
    if (!$matchedFile && !empty($pdfCandidates)) {
        usort($pdfCandidates, function($a, $b) { return $b['mtime'] - $a['mtime']; });
        if ((time() - $pdfCandidates[0]['mtime']) < 1800) {
            $matchedFile = $pdfCandidates[0]['path'];
            logBridge("Match found by Most Recent PDF File: $matchedFile (Age: " . (time() - $pdfCandidates[0]['mtime']) . "s)");
        }
    }
}

// Prepare cURL POST payload to Live Hostinger Server (Merge GET & POST parameters)
$postData = array_merge($_GET, $_POST);

if ($matchedFile && file_exists($matchedFile)) {
    logBridge("Attaching Marg PDF File: $matchedFile (Size: " . filesize($matchedFile) . " bytes)");
    $postData['pdf'] = new CURLFile($matchedFile, 'application/pdf', basename($matchedFile));
    $postData['pdf_base64'] = base64_encode(file_get_contents($matchedFile));
} else {
    logBridge("WARNING: No Marg PDF file found on local disk.");
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

logBridge("cURL response code: $httpCode | Error: " . ($curlErr ?: 'None'));

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Bridge cURL error: ' . $curlErr]);
} else {
    http_response_code($httpCode);
    echo $response;
}
