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

// Fallback: If msg is empty (to prevent WinHttp GET URL newline crashes in Marg ERP), extract last message from local margsms.txt
if (empty($message_body)) {
    $smsSearchFiles = [
        'C:/Users/Public/MARG/margsms.txt',
        'C:/Users/Public/MARG/margsms.log',
        'C:/Users/Public/Documents/MARG/margsms.txt'
    ];
    // Add dynamic subfolders under C:\Users\Public\MARG\
    $publicMargSub = @glob('C:/Users/Public/MARG/*', GLOB_ONLYDIR);
    if (!empty($publicMargSub)) {
        foreach ($publicMargSub as $pSub) {
            $pClean = str_replace('\\', '/', $pSub);
            $smsSearchFiles[] = rtrim($pClean, '/') . '/margsms.txt';
            $smsSearchFiles[] = rtrim($pClean, '/') . '/margsms.log';
        }
    }

    foreach (range('C', 'Z') as $driveLetter) {
        $smsSearchFiles[] = $driveLetter . ':/MARG/margsms.txt';
        $smsSearchFiles[] = $driveLetter . ':/MARGWIN/margsms.txt';
        $smsSearchFiles[] = $driveLetter . ':/MargERP/margsms.txt';
        $smsSearchFiles[] = $driveLetter . ':/Marg_ERP/margsms.txt';
        $smsSearchFiles[] = $driveLetter . ':/MARG/margsms.log';
    }

    foreach ($smsSearchFiles as $smsFile) {
        if (file_exists($smsFile)) {
            $lines = array_filter(array_map('trim', file($smsFile)));
            if (!empty($lines)) {
                $lastLine = end($lines);
                $parts = explode('|', $lastLine);
                if (isset($parts[2]) && !empty($parts[2])) {
                    $message_body = trim($parts[2]);
                    logBridge("Extracted message body from local margsms log ($smsFile): " . substr($message_body, 0, 80));
                    break;
                } elseif (count($parts) === 1 && !empty($lastLine)) {
                    $message_body = $lastLine;
                    logBridge("Extracted raw message body from local margsms log ($smsFile): " . substr($message_body, 0, 80));
                    break;
                }
            }
        }
    }
}

logBridge("Bridge invoked. Recipient: $recipient | pdf_url: $pdf_url | Query: " . ($_SERVER['QUERY_STRING'] ?? ''));

// Build full forwarding URL with query string
$queryString = $_SERVER['QUERY_STRING'] ?? '';
if (!empty($message_body) && empty($_GET['msg']) && empty($_POST['msg'])) {
    $queryString .= (!empty($queryString) ? '&' : '') . 'msg=' . urlencode($message_body);
}
$targetUrl = $live_gateway_url . (!empty($queryString) ? '?' . $queryString : '');

// Search local Windows directories across all drives (C: through Z:), Users\Public\MARG (and dynamic company folders like 31041)
$searchDirs = [];
$subDirs = ['emailserver/pdf/', 'emailserver/', 'PDF/', 'Reports/', 'export/', 'temp/', 'files/', 'Others/', ''];
$margRoots = ['MARG', 'MARGWIN', 'MARGEXE', 'MargERP', 'Marg_ERP'];

// Scan Public User MARG folders (e.g. C:\Users\Public\MARG and dynamic company subfolders like 31041)
$publicMargRoots = ['C:/Users/Public/MARG', 'C:/Users/Public/Documents/MARG', 'C:/Users/Public/MargERP'];
foreach ($publicMargRoots as $pRoot) {
    if (@is_dir($pRoot)) {
        $searchDirs[] = rtrim($pRoot, '/') . '/';
        foreach ($subDirs as $sub) {
            $pSub = rtrim($pRoot, '/') . '/' . $sub;
            if (@is_dir($pSub)) $searchDirs[] = $pSub;
        }
        // Scan 1st & 2nd level subdirectories dynamically (e.g. C:\Users\Public\MARG\31041\)
        $dynDirs = @glob(rtrim($pRoot, '/') . '/*', GLOB_ONLYDIR);
        if (!empty($dynDirs)) {
            foreach ($dynDirs as $dDir) {
                $dClean = str_replace('\\', '/', $dDir);
                $searchDirs[] = rtrim($dClean, '/') . '/';
                foreach ($subDirs as $sub) {
                    $dSub = rtrim($dClean, '/') . '/' . $sub;
                    if (@is_dir($dSub)) $searchDirs[] = $dSub;
                }
                $dynDirsL2 = @glob(rtrim($dClean, '/') . '/*', GLOB_ONLYDIR);
                if (!empty($dynDirsL2)) {
                    foreach ($dynDirsL2 as $dL2) {
                        $dL2Clean = str_replace('\\', '/', $dL2);
                        $searchDirs[] = rtrim($dL2Clean, '/') . '/';
                    }
                }
            }
        }
    }
}

// Scan all user profiles for MARG subfolders
$userMargFolders = @glob('C:/Users/*/MARG*', GLOB_ONLYDIR);
if (!empty($userMargFolders)) {
    foreach ($userMargFolders as $uDir) {
        $uClean = str_replace('\\', '/', $uDir);
        $searchDirs[] = rtrim($uClean, '/') . '/';
        foreach ($subDirs as $sub) {
            $uSub = rtrim($uClean, '/') . '/' . $sub;
            if (@is_dir($uSub)) $searchDirs[] = $uSub;
        }
    }
}

foreach (range('C', 'Z') as $driveLetter) {
    $driveRoot = $driveLetter . ':/';
    if (!@is_dir($driveRoot)) continue;
    foreach ($margRoots as $root) {
        foreach ($subDirs as $sub) {
            $dirPath = $driveRoot . $root . '/' . $sub;
            if (@is_dir($dirPath)) {
                $searchDirs[] = $dirPath;
            }
        }
    }
    $topFolders = @glob($driveRoot . '*[Mm][Aa][Rr][Gg]*', GLOB_ONLYDIR);
    if (!empty($topFolders)) {
        foreach ($topFolders as $topDir) {
            $topDirClean = str_replace('\\', '/', $topDir);
            foreach ($subDirs as $sub) {
                $dirPath = rtrim($topDirClean, '/') . '/' . $sub;
                if (@is_dir($dirPath)) {
                    $searchDirs[] = $dirPath;
                }
            }
        }
    }
}
$searchDirs[] = sys_get_temp_dir() . '/';
$searchDirs = array_unique($searchDirs);

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

    // Match most recently created PDF file on disk (created/updated within last 24 hours)
    if (!$matchedFile && !empty($pdfCandidates)) {
        usort($pdfCandidates, function($a, $b) { return $b['mtime'] - $a['mtime']; });
        $freshAge = time() - $pdfCandidates[0]['mtime'];
        if ($freshAge < 86400) {
            $matchedFile = $pdfCandidates[0]['path'];
            logBridge("Match found by Latest PDF File on disk: $matchedFile (Age: {$freshAge}s)");
        } else {
            $matchedFile = $pdfCandidates[0]['path'];
            logBridge("Fallback: Picking most recent PDF file on disk: $matchedFile (Age: {$freshAge}s)");
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
