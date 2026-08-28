<?php
/**
 * Marg ERP 9+ Custom Gateway API Endpoint (Cloud SaaS Edition)
 * Receives requests from Python Bridge and dispatches WhatsApp messages with PDF (Meta & Web API support).
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';

$rawApiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? $_POST['apiKey'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($rawApiKey) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $rawApiKey = $m[1];
    }
}

// Fallback to reading JSON input if API key wasn't in GET/POST headers
$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?? [];
if (empty($rawApiKey) && !empty($jsonInput['api_key'])) {
    $rawApiKey = $jsonInput['api_key'];
}

$api_key = trim($rawApiKey, " \t\n\r\0\x0B\"'");

if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'success' => false, 'error' => 401, 'message' => 'Missing api_key parameter in request.'], JSON_PRETTY_PRINT);
    exit;
}

if (!$db_connected || !$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'success' => false, 'error' => 500, 'message' => 'Database connection offline.'], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1. Exact or case-insensitive match by Tenant API Key
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE (tenant_api_key = ? OR tenant_api_key = UPPER(?)) LIMIT 1");
    $stmt->execute([$api_key, $api_key]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. If not found and key starts with MARG-WABA-, fallback to master merchant record
    if (!$merchant && strpos($api_key, 'MARG-WABA-') === 0) {
        $stmtFallback = $pdo->prepare("SELECT * FROM merchant_waba_settings ORDER BY id ASC LIMIT 1");
        $stmtFallback->execute();
        $merchant = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    }

    if (!$merchant) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'error' => 403, 'message' => 'Invalid Tenant API Key: ' . $api_key . '. Please copy your active key from Marg ERP WhatsApp Gateway settings.'], JSON_PRETTY_PRINT);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'success' => false, 'error' => 500, 'message' => 'Query error: ' . $e->getMessage()], JSON_PRETTY_PRINT);
    exit;
}

$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?? [];

$recipient = $_POST['mob'] ?? $_GET['mob'] ?? $jsonInput['mob'] ?? '';
$message_body = $_POST['msg'] ?? $_GET['msg'] ?? $jsonInput['msg'] ?? '';
$customer_name = $_POST['customer_name'] ?? $_GET['customer_name'] ?? 'Valued Customer';
$bill_number = $_POST['bill_no'] ?? $_GET['bill_no'] ?? '';
$bill_amount = $_POST['amount'] ?? $_GET['amount'] ?? '0.00';
$balance = $_POST['balance'] ?? $_GET['balance'] ?? '';
$firm_name = $_POST['firm_name'] ?? $_GET['firm_name'] ?? '';

// Format Bill Text & Extract Details from Marg Message Body
function formatMargBillText($rawText) {
    $firmName = 'POSHAK PATHAK';
    if (preg_match('/For\s+([A-Za-z0-9\s]+?)(?:\r|\n|$)/i', $rawText, $mFirm)) $firmName = trim($mFirm[1]);

    $custName = 'Valued Customer';
    if (preg_match('/Name\s+([A-Za-z0-9\s]+?)(?:\r|\n|For|$)/i', $rawText, $mCust)) $custName = trim($mCust[1]);

    $billNo = 'BILL001';
    if (preg_match('/No:\s*([A-Za-z0-9_\-]+)/i', $rawText, $mNo)) $billNo = trim($mNo[1]);

    $billAmt = '0.00';
    if (preg_match('/Amount:\s*([0-9\.,]+)/i', $rawText, $mAmt)) $billAmt = trim($mAmt[1]);

    $billBal = '0.00';
    if (preg_match('/Balance:\s*([0-9\.,]+)/i', $rawText, $mBal)) $billBal = trim($mBal[1]);

    $formatted = "From: *" . $firmName . "*\n\n";
    $formatted .= "Dear *" . $custName . "*\n\n";
    $formatted .= "Your recent order with Invoice No. *" . $billNo . "* of amount *₹" . $billAmt . "* has been successfully generated.\n\n";
    $formatted .= "Your Ledger balance is *₹" . $billBal . "*\n\n";
    $formatted .= "Bill PDF Attached\n";

    return [
        'formatted_text' => $formatted,
        'firm_name' => $firmName,
        'customer_name' => $custName,
        'bill_no' => $billNo,
        'bill_amount' => $billAmt,
        'balance' => $billBal
    ];
}

$parsedData = formatMargBillText($message_body);
if (!empty($firm_name)) $parsedData['firm_name'] = $firm_name;
if (!empty($customer_name) && $customer_name !== 'Valued Customer') $parsedData['customer_name'] = $customer_name;
if (!empty($bill_number)) $parsedData['bill_no'] = $bill_number;
if (!empty($bill_amount) && $bill_amount !== '0.00') $parsedData['bill_amount'] = $bill_amount;
if (!empty($balance)) $parsedData['balance'] = $balance;

$safeBillNo = preg_replace('/[^A-Za-z0-9_\-]/', '', $parsedData['bill_no'] ?: ('BILL_' . time()));

// Phone sanitization
$phoneDigits = preg_replace('/\D/', '', $recipient);
if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;
if (strlen($phoneDigits) < 10 && preg_match('/(?:[6-9][0-9]{9})/', $message_body, $mPhone)) {
    $phoneDigits = '91' . $mPhone[0];
}

if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid mobile number required.'], JSON_PRETTY_PRINT);
    exit;
}

// Upload Directory Setup with Bill-Specific Subfolder
$baseUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST']);
$baseUploadsDir = __DIR__ . '/../uploads/invoices/';
$billFolder = $baseUploadsDir . $safeBillNo . '/';

if (!is_dir($billFolder)) {
    @mkdir($billFolder, 0777, true);
}

$pdfDownloadUrl = null;

// Catch Uploaded Real PDF from Python Bridge and save as Invoice.pdf inside unique folder
if (!empty($_FILES)) {
    $uploadedFile = reset($_FILES);
    if (!empty($uploadedFile['tmp_name']) && is_uploaded_file($uploadedFile['tmp_name'])) {
        $targetFile = $billFolder . "Invoice.pdf";
        if (move_uploaded_file($uploadedFile['tmp_name'], $targetFile)) {
            $pdfDownloadUrl = $baseUrl . "/uploads/invoices/" . $safeBillNo . "/Invoice.pdf";
        }
    }
}

if (!$pdfDownloadUrl) {
    $pdfDownloadUrl = $baseUrl . "/uploads/invoices/" . $safeBillNo . "/Invoice.pdf";
}

$gateway_type = $merchant['gateway_type'] ?? 'meta';
// Auto-route through Self-Hosted WhatsApp Web if web session is connected or if explicitly chosen
if (!empty($merchant['web_api_session_status']) && $merchant['web_api_session_status'] === 'connected') {
    if ($gateway_type === 'web_api' || empty($merchant['phone_number_id']) || empty($merchant['access_token'])) {
        $gateway_type = 'web_api';
    }
}

$success = false;
$apiResponseData = [];

if ($gateway_type === 'meta') {
    // ==========================================
    // 1. OFFICIAL META CLOUD API DISPATCH
    // ==========================================
    $phone_number_id = $merchant['phone_number_id'] ?? '';
    $access_token = $merchant['access_token'] ?? '';
    $merchant_helpline = !empty($merchant['business_phone']) ? $merchant['business_phone'] : '+91 92773 87778';

    if (empty($phone_number_id) || empty($access_token)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Merchant Meta WABA credentials missing.'], JSON_PRETTY_PRINT);
        exit;
    }

    $metaUrl = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
    $template_name_in_meta = 'marg_bill'; 

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $phoneDigits,
        'type' => 'template',
        'template' => [
            'name' => $template_name_in_meta,
            'language' => ['code' => 'en'],
            'components' => [
                [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => 'document',
                            'document' => [
                                'link' => $pdfDownloadUrl,
                                'filename' => "Invoice.pdf"
                            ]
                        ]
                    ]
                ],
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string)$parsedData['firm_name']],
                        ['type' => 'text', 'text' => (string)$parsedData['customer_name']],
                        ['type' => 'text', 'text' => (string)$parsedData['bill_no']],
                        ['type' => 'text', 'text' => (string)$parsedData['bill_amount']],
                        ['type' => 'text', 'text' => (string)$parsedData['balance']],
                        ['type' => 'text', 'text' => 'HARSHSAINI2017@OKICCI'],
                        ['type' => 'text', 'text' => 'BOI'],
                        ['type' => 'text', 'text' => '178963542456'],
                        ['type' => 'text', 'text' => 'MANDHANA'],
                        ['type' => 'text', 'text' => 'BKI0125'],
                        ['type' => 'text', 'text' => (string)$parsedData['firm_name']],
                        ['type' => 'text', 'text' => (string)$merchant_helpline],
                        ['type' => 'text', 'text' => (string)$pdfDownloadUrl]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($metaUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resRaw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiResponseData = json_decode($resRaw, true);
    if ($httpCode === 200 && isset($apiResponseData['messages'][0]['id'])) {
        $success = true;
    }

} else if ($gateway_type === 'web_api') {
    // ==========================================
    // 2. SELF-HOSTED WHATSAPP WEB ENGINE DISPATCH
    // ==========================================
    $defaultSelfHosted = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://friendlyaisolution.com') . '/api/whatsapp_web_engine.php';
    $webApiUrl = !empty($merchant['web_api_url']) ? rtrim($merchant['web_api_url'], '/') : $defaultSelfHosted;
    $webApiToken = $merchant['web_api_token'] ?? '';
    $webApiInstance = $merchant['web_api_instance_id'] ?? '';

    if (empty($webApiUrl)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Web API URL missing for this merchant.'], JSON_PRETTY_PRINT);
        exit;
    }

    $dispatchEndpoint = (strpos($webApiUrl, 'action=') !== false) 
        ? $webApiUrl . '&action=send_message' 
        : ((strpos($webApiUrl, '.php') !== false) ? ($webApiUrl . '?action=send_message') : (rtrim($webApiUrl, '/') . '/send-message'));

    $payload = [
        'recipient'    => $phoneDigits,
        'phone'        => $phoneDigits,
        'mobile'       => $phoneDigits,
        'message'      => $parsedData['formatted_text'],
        'document_url' => $pdfDownloadUrl,
        'file_url'     => $pdfDownloadUrl,
        'pdf_url'      => $pdfDownloadUrl,
        'filename'     => "Invoice.pdf",
        'instance'     => $webApiInstance,
        'token'        => $webApiToken
    ];

    $headers = ['Content-Type: application/json'];
    if (!empty($webApiToken)) {
        $headers[] = 'Authorization: Bearer ' . $webApiToken;
        $headers[] = 'apikey: ' . $webApiToken;
    }

    $ch = curl_init($dispatchEndpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resRaw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiResponseData = json_decode($resRaw, true) ?? ['raw_response' => $resRaw];
    if ($httpCode >= 200 && $httpCode < 300) {
        $success = true;
    } else if (!empty($apiResponseData['status']) && strtolower((string)$apiResponseData['status']) === 'success') {
        $success = true;
    }
}

// Final Clean JSON Response
if ($success) {
    echo json_encode([
        'status' => 'success', 
        'gateway' => $gateway_type,
        'message' => 'Dispatched successfully with real PDF.', 
        'recipient' => $phoneDigits
    ], JSON_PRETTY_PRINT);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'gateway' => $gateway_type,
        'error_code' => 500,
        'api_response' => $apiResponseData
    ], JSON_PRETTY_PRINT);
}
?>