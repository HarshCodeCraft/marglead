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
    // 1. Exact or case-insensitive match by Tenant API Key in master merchant_waba_settings
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE (tenant_api_key = ? OR tenant_api_key = UPPER(?)) LIMIT 1");
    $stmt->execute([$api_key, $api_key]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Check in all tenant databases / isolated tables (t_{code}_merchant_waba_settings)
    $stmtTenants = $pdo->query("SELECT * FROM tenant_companies WHERE status = 'Active'");
    $tenantsList = $stmtTenants ? $stmtTenants->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($tenantsList as $tComp) {
        $tDb = $tComp['db_name'] ?? '';
        if (!empty($tDb) && strpos($tDb, 't_') === 0) {
            $tbl = "{$tDb}merchant_waba_settings";
            try {
                $stmtT = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE (tenant_api_key = ? OR tenant_api_key = UPPER(?)) LIMIT 1");
                $stmtT->execute([$api_key, $api_key]);
                $tMerchant = $stmtT->fetch(PDO::FETCH_ASSOC);
                if ($tMerchant) {
                    $merchant = $tMerchant;
                    $merchant['user_id'] = $tComp['id'];
                    break;
                }
            } catch (\PDOException $ex) {}
        }
    }

    // STRICT TENANT ISOLATION:
    // Under NO circumstances should any request fallback to Master or any other merchant!
    if (!$merchant) {
        $inputRaw = file_get_contents('php://input');
        $jsonInput = json_decode($inputRaw, true) ?? [];
        $recipient = $_POST['mob'] ?? $_GET['mob'] ?? $jsonInput['mob'] ?? '';
        $message_body = $_POST['msg'] ?? $_GET['msg'] ?? $jsonInput['msg'] ?? '';
        $bill_number = $_POST['bill_no'] ?? $_GET['bill_no'] ?? '';
        if (empty($bill_number) && preg_match('/No:\s*([A-Za-z0-9_\-]+)/i', $message_body, $mNo)) {
            $bill_number = trim($mNo[1]);
        }
        $phoneDigits = preg_replace('/\D/', '', $recipient);
        if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

        $errReason = 'Authentication Failed: Invalid or Unregistered Tenant API Key (' . $api_key . '). Dispatch blocked immediately. Cross-tenant fallback is strictly disabled.';
        try {
            $stmtLogBlocked = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (0, ?, ?, 'Marg ERP Bill', ?, 0.00, 'blocked', 'Failed', ?, ?, ?)");
            $stmtLogBlocked->execute([
                $api_key,
                $phoneDigits ?: 'N/A',
                $bill_number ?: 'UNKNOWN',
                'BLOCKED_' . time(),
                $errReason,
                json_encode(['raw_key' => $api_key, 'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'recipient' => $phoneDigits, 'bill_no' => $bill_number])
            ]);
        } catch (\PDOException $ex) {}

        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'error' => 403,
            'bill_no' => $bill_number ?: null,
            'recipient' => $phoneDigits ?: null,
            'message' => 'Invalid Tenant API Key: ' . $api_key . '. Dispatch blocked. Please copy your exact active key from your Marg ERP WhatsApp Gateway settings.'
        ], JSON_PRETTY_PRINT);
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
    $firmName = '';
    if (preg_match('/For\s+([A-Za-z0-9\s]+?)(?:\r|\n|$)/i', $rawText, $mFirm)) $firmName = trim($mFirm[1]);

    $custName = 'Valued Customer';
    if (preg_match('/Name\s+([A-Za-z0-9\s]+?)(?:\r|\n|For|$)/i', $rawText, $mCust)) $custName = trim($mCust[1]);

    $billNo = 'BILL001';
    if (preg_match('/No:\s*([A-Za-z0-9_\-]+)/i', $rawText, $mNo)) $billNo = trim($mNo[1]);

    $billAmt = '0.00';
    if (preg_match('/Amount:\s*([0-9\.,]+)/i', $rawText, $mAmt)) $billAmt = trim($mAmt[1]);

    $billBal = '0.00';
    if (preg_match('/Balance:\s*([0-9\.,]+)/i', $rawText, $mBal)) $billBal = trim($mBal[1]);

    $formatted = (!empty($firmName) ? ("From: *" . $firmName . "*\n\n") : "");
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
    $failReason = 'Invalid or missing mobile number (' . htmlspecialchars($recipient) . '). At least 10 digits required.';
    try {
        $stmtLogFail = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Marg ERP Bill', ?, ?, ?, 'Failed', ?, ?, ?)");
        $stmtLogFail->execute([
            $merchant['user_id'] ?? 1,
            $api_key,
            $recipient ?: 'Invalid',
            $parsedData['bill_no'],
            $parsedData['bill_amount'],
            $merchant['gateway_type'] ?? 'unknown',
            'FAILED_' . time(),
            $failReason,
            json_encode(['recipient_raw' => $recipient, 'bill_no' => $parsedData['bill_no'], 'error' => $failReason])
        ]);
    } catch (\PDOException $ex) {}

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'bill_no' => $parsedData['bill_no'],
        'recipient' => $recipient,
        'message' => $failReason
    ], JSON_PRETTY_PRINT);
    exit;
}

// Generate a high-entropy unguessable cryptographic token for this invoice (Prevents IDOR & URL tampering)
$secureInvoiceHash = substr(hash('sha256', $safeBillNo . '_' . $phoneDigits . '_' . microtime(true) . '_' . bin2hex(random_bytes(16))), 0, 24);
$secureFolder = $safeBillNo . '_' . $secureInvoiceHash;

// Upload Directory Setup with Cryptographically Secure Subfolder
$baseUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST']);
$baseUploadsDir = __DIR__ . '/../uploads/invoices/';
$billFolder = $baseUploadsDir . $secureFolder . '/';

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
            $pdfDownloadUrl = $baseUrl . "/uploads/invoices/" . $secureFolder . "/Invoice.pdf";
        }
    }
}

if (!$pdfDownloadUrl) {
    $pdfDownloadUrl = $baseUrl . "/uploads/invoices/" . $secureFolder . "/Invoice.pdf";
}

// -------------------------------------------------------------
// Dynamic Multi-Tenant Bank & Helpline Lookup (Per Client)
// -------------------------------------------------------------
$bankDetails = null;
$tenantId = (int)($merchant['user_id'] ?? 0);
$tenantCompany = null;

if ($tenantId > 0) {
    try {
        $stmtTComp = $pdo->prepare("SELECT * FROM tenant_companies WHERE id = ? LIMIT 1");
        $stmtTComp->execute([$tenantId]);
        $tenantCompany = $stmtTComp->fetch(PDO::FETCH_ASSOC);
        
        if ($tenantCompany) {
            $prefix = !empty($tenantCompany['db_name']) ? $tenantCompany['db_name'] : '';
            if (empty($prefix) && !empty($tenantCompany['company_code'])) {
                $prefix = "t_" . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $tenantCompany['company_code'])) . "_";
            }
            if (!empty($prefix) && strpos($prefix, 't_') === 0) {
                if (substr($prefix, -1) !== '_') $prefix .= '_';
                $tBankTbl = "{$prefix}bank_accounts";
                $stmtB = $pdo->query("SELECT * FROM `{$tBankTbl}` WHERE status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
                $bankDetails = $stmtB ? $stmtB->fetch(PDO::FETCH_ASSOC) : null;
            }
        }
    } catch (\PDOException $ex) {}
}

// Fallback ONLY for Master User ID 1 if no tenant company is associated
if (!$bankDetails && $tenantId === 1 && empty($tenantCompany)) {
    try {
        $stmtB2 = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
        $bankDetails = $stmtB2 ? $stmtB2->fetch(PDO::FETCH_ASSOC) : null;
    } catch (\PDOException $ex) {}
}

// Client Helpline Phone (ONLY for this merchant / tenant)
$merchant_helpline = '';
if (!empty($merchant['business_phone'])) {
    $merchant_helpline = $merchant['business_phone'];
} elseif (!empty($tenantCompany['phone'])) {
    $merchant_helpline = $tenantCompany['phone'];
} elseif (!empty($tenantCompany['owner_phone'])) {
    $merchant_helpline = $tenantCompany['owner_phone'];
}
if (empty($merchant_helpline)) {
    $merchant_helpline = '-';
}

// Firm Name (ONLY for this merchant / tenant)
$firmDisplay = !empty($parsedData['firm_name']) 
    ? $parsedData['firm_name'] 
    : (!empty($tenantCompany['company_name']) 
        ? $tenantCompany['company_name'] 
        : (!empty($merchant['business_name']) ? $merchant['business_name'] : 'Business Entity'));

// Extract specific bank variables for Meta Template
$bankUpi = !empty($bankDetails['upi_id']) ? $bankDetails['upi_id'] : '-';
$bankName = !empty($bankDetails['bank_name']) ? $bankDetails['bank_name'] : '-';
$bankAccNo = !empty($bankDetails['account_number']) ? $bankDetails['account_number'] : '-';
$bankBranch = !empty($bankDetails['branch']) ? $bankDetails['branch'] : '-';
$bankIfsc = !empty($bankDetails['ifsc_code']) ? $bankDetails['ifsc_code'] : '-';

// Build Complete Dynamic Bill Confirmation Message Text
$fullBillMessage = "From: *" . $firmDisplay . "*\n";
$fullBillMessage .= "Subject: *Sale Bill Confirmation*\n\n";
$fullBillMessage .= "Dear *" . $parsedData['customer_name'] . "*,\n\n";
$fullBillMessage .= "Your recent order with the invoice number *" . $parsedData['bill_no'] . "* of the amount *₹" . $parsedData['bill_amount'] . "* has been successfully generated.\n\n";
$fullBillMessage .= "Please check for your payments.\n";
$fullBillMessage .= "Your Ledger balance is *₹" . $parsedData['balance'] . "*.\n\n";

// Only include Bank Details block if the client has actually configured their bank account
if ($bankDetails && !empty($bankDetails['account_number'])) {
    $fullBillMessage .= "*Bank Details:*\n";
    if (!empty($bankDetails['upi_id'])) {
        $fullBillMessage .= "UPI ID: *" . $bankDetails['upi_id'] . "*\n";
    }
    if (!empty($bankDetails['bank_name'])) {
        $fullBillMessage .= "Bank Name: *" . $bankDetails['bank_name'] . "*\n";
    }
    $fullBillMessage .= "Account No.: *" . $bankDetails['account_number'] . "*\n";
    if (!empty($bankDetails['branch'])) {
        $fullBillMessage .= "Branch: *" . $bankDetails['branch'] . "*\n";
    }
    if (!empty($bankDetails['ifsc_code'])) {
        $fullBillMessage .= "IFSC Code: *" . $bankDetails['ifsc_code'] . "*\n";
    }
    $fullBillMessage .= "\n";
}

$fullBillMessage .= "Regards,\n";
$fullBillMessage .= "*" . $firmDisplay . "*\n";
if (!empty($merchant_helpline)) {
    $fullBillMessage .= "Helpline: *" . $merchant_helpline . "*\n";
}
$fullBillMessage .= "\n";
$fullBillMessage .= "The bill PDF is attached above.\n";
$fullBillMessage .= "Preview link: " . $pdfDownloadUrl . "\n\n";
$fullBillMessage .= "Thank you for doing business with us!";

$gateway_type = $merchant['gateway_type'] ?? 'meta';
// Only route through Self-Hosted WhatsApp Web if merchant explicitly chose web_api, or if meta credentials are missing
if ($gateway_type === 'meta' && (empty($merchant['phone_number_id']) || empty($merchant['access_token'])) && !empty($merchant['web_api_session_status']) && $merchant['web_api_session_status'] === 'connected') {
    $gateway_type = 'web_api';
}

$success = false;
$apiResponseData = [];

if ($gateway_type === 'meta') {
    // ==========================================
    // 1. OFFICIAL META CLOUD API DISPATCH
    // ==========================================
    $phone_number_id = $merchant['phone_number_id'] ?? '';
    $access_token = $merchant['access_token'] ?? '';

    if (empty($phone_number_id) || empty($access_token)) {
        $failReason = 'Meta WABA credentials missing: Phone Number ID or Access Token is empty in your WhatsApp Gateway settings.';
        try {
            $stmtLogFail = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Marg ERP Bill', ?, ?, 'meta', 'Failed', ?, ?, ?)");
            $stmtLogFail->execute([
                $merchant['user_id'] ?? 1,
                $api_key,
                $phoneDigits,
                $parsedData['bill_no'],
                $parsedData['bill_amount'],
                'FAILED_' . time(),
                $failReason,
                json_encode(['error' => $failReason, 'bill_no' => $parsedData['bill_no'], 'customer' => $parsedData['customer_name']])
            ]);
        } catch (\PDOException $ex) {}

        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'gateway' => 'meta',
            'bill_no' => $parsedData['bill_no'],
            'recipient' => $phoneDigits,
            'message' => $failReason
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $metaUrl = "https://graph.facebook.com/v20.0/{$phone_number_id}/messages";
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
                        ['type' => 'text', 'text' => (string)$firmDisplay], // {{1}} From: *{{1}}*
                        ['type' => 'text', 'text' => (string)($parsedData['customer_name'] ?: 'Valued Customer')], // {{2}} Dear *{{2}}*
                        ['type' => 'text', 'text' => (string)($parsedData['bill_no'] ?: 'Invoice')], // {{3}} invoice number *{{3}}*
                        ['type' => 'text', 'text' => (string)($parsedData['bill_amount'] ?: '0.00')], // {{4}} amount *₹{{4}}*
                        ['type' => 'text', 'text' => (string)($parsedData['balance'] ?: '0.00')], // {{5}} Ledger balance is *₹{{5}}*
                        ['type' => 'text', 'text' => (string)$bankUpi], // {{6}} UPI ID: *{{6}}*
                        ['type' => 'text', 'text' => (string)$bankName], // {{7}} Bank Name: *{{7}}*
                        ['type' => 'text', 'text' => (string)$bankAccNo], // {{8}} Account No.:*{{8}}*
                        ['type' => 'text', 'text' => (string)$bankBranch], // {{9}} Branch: *{{9}}*
                        ['type' => 'text', 'text' => (string)$bankIfsc], // {{10}} IFSC Code: *{{10}}*
                        ['type' => 'text', 'text' => (string)$firmDisplay], // {{11}} Regards, *{{11}}*
                        ['type' => 'text', 'text' => (string)$merchant_helpline], // {{12}} Helpline: *{{12}}*
                        ['type' => 'text', 'text' => (string)($pdfDownloadUrl ?: 'https://friendlyaisolution.com')] // {{13}} Preview link: *{{13}}*
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $resRaw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiResponseData = json_decode($resRaw, true) ?? ['raw_response' => $resRaw];
    if ($httpCode === 200 && isset($apiResponseData['messages'][0]['id'])) {
        $success = true;
    } else {
        $success = false;
        $metaErrMsg = $apiResponseData['error']['message'] ?? ($curlErr ? ('cURL Error: ' . $curlErr) : ('Meta Graph API returned HTTP ' . $httpCode));
        if (!empty($apiResponseData['error']['error_user_title'])) {
            $metaErrMsg = $apiResponseData['error']['error_user_title'] . ': ' . $metaErrMsg;
        }
        if (!empty($apiResponseData['error']['error_user_msg'])) {
            $metaErrMsg .= ' (' . $apiResponseData['error']['error_user_msg'] . ')';
        }
        $errorMessage = 'Meta Error: ' . $metaErrMsg;
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
        $failReason = 'WhatsApp Web API endpoint URL is missing in merchant settings.';
        try {
            $stmtLogFail = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Marg ERP Bill', ?, ?, 'web_api', 'Failed', ?, ?, ?)");
            $stmtLogFail->execute([
                $merchant['user_id'] ?? 1,
                $api_key,
                $phoneDigits,
                $parsedData['bill_no'],
                $parsedData['bill_amount'],
                'FAILED_' . time(),
                $failReason,
                json_encode(['error' => $failReason, 'bill_no' => $parsedData['bill_no']])
            ]);
        } catch (\PDOException $ex) {}

        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'gateway' => 'web_api',
            'bill_no' => $parsedData['bill_no'],
            'recipient' => $phoneDigits,
            'message' => $failReason
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $dispatchEndpoint = (strpos($webApiUrl, 'action=') !== false) 
        ? $webApiUrl . '&action=send_message' 
        : ((strpos($webApiUrl, '.php') !== false) ? ($webApiUrl . '?action=send_message') : (rtrim($webApiUrl, '/') . '/send-message'));

    $payload = [
        'user_id'      => $merchant['user_id'],
        'recipient'    => $phoneDigits,
        'phone'        => $phoneDigits,
        'mobile'       => $phoneDigits,
        'message'      => $fullBillMessage,
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resRaw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $apiResponseData = json_decode($resRaw, true);
    if (!is_array($apiResponseData)) {
        $apiResponseData = ['raw_response' => $resRaw];
    }

    $st = strtolower((string)($apiResponseData['status'] ?? ''));
    $succVal = $apiResponseData['success'] ?? null;
    $hasSuccessFlag = ($succVal === true || $succVal === 1 || $succVal === 'true' || $succVal === '1');
    $hasStatusSuccess = in_array($st, ['success', 'sent', 'ok', 'queued', 'pending', 'true', 'delivered']);
    $hasMsgId = !empty($apiResponseData['message_id']) || !empty($apiResponseData['id']) || !empty($apiResponseData['messages'][0]['id']) || !empty($apiResponseData['key']['id']);
    $hasExplicitError = !empty($apiResponseData['error']) || in_array($st, ['error', 'failed', 'fail']) || ($succVal === false || $succVal === 'false' || $succVal === 0);

    if ($httpCode >= 200 && $httpCode < 300 && (($hasSuccessFlag || $hasStatusSuccess || $hasMsgId || empty($st)) && !$hasExplicitError)) {
        $success = true;
    } else {
        $success = false;
        $webErrMsg = $apiResponseData['message'] ?? ($apiResponseData['error'] ?? ($apiResponseData['reason'] ?? ($curlErr ? ('cURL Error: ' . $curlErr) : ($resRaw ?: ('WhatsApp Engine returned HTTP ' . $httpCode)))));
        if (is_array($webErrMsg)) {
            $webErrMsg = json_encode($webErrMsg);
        }
        $errorMessage = 'WhatsApp Web API Error: ' . $webErrMsg;
    }
}

// Final Clean JSON Response & Accurate Status Logging in marg_erp_logs
if ($success) {
    // Record successful dispatch in marg_erp_logs for live stats & message counters
    try {
        $metaMsgId = $apiResponseData['messages'][0]['id'] ?? ($apiResponseData['message_id'] ?? ($apiResponseData['id'] ?? ($apiResponseData['key']['id'] ?? ('WEB_' . time()))));
        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Marg ERP Bill', ?, ?, ?, 'Sent', ?, NULL, ?)");
        $stmtLog->execute([
            $merchant['user_id'] ?? 1,
            $api_key,
            $phoneDigits,
            $parsedData['bill_no'],
            $parsedData['bill_amount'],
            $gateway_type,
            $metaMsgId,
            json_encode([
                'amount' => $parsedData['bill_amount'], 
                'customer' => $parsedData['customer_name'], 
                'balance' => $parsedData['balance'],
                'gateway' => $gateway_type,
                'pdf_url' => $pdfDownloadUrl
            ])
        ]);
    } catch (\PDOException $ex) {}

    echo json_encode([
        'status' => 'success', 
        'success' => true,
        'gateway' => $gateway_type,
        'bill_no' => $parsedData['bill_no'],
        'amount' => $parsedData['bill_amount'],
        'message' => 'Dispatched successfully with real PDF.', 
        'recipient' => $phoneDigits
    ], JSON_PRETTY_PRINT);
} else {
    // RECORD DETAILED FAILURE IN marg_erp_logs
    $failMsg = !empty($errorMessage) ? $errorMessage : 'Dispatch failed with unknown error.';
    try {
        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Marg ERP Bill', ?, ?, ?, 'Failed', ?, ?, ?)");
        $stmtLog->execute([
            $merchant['user_id'] ?? 1,
            $api_key,
            $phoneDigits,
            $parsedData['bill_no'],
            $parsedData['bill_amount'],
            $gateway_type,
            'FAILED_' . time(),
            $failMsg,
            json_encode([
                'amount' => $parsedData['bill_amount'], 
                'customer' => $parsedData['customer_name'], 
                'balance' => $parsedData['balance'],
                'gateway' => $gateway_type,
                'error' => $failMsg,
                'api_response' => $apiResponseData
            ])
        ]);
    } catch (\PDOException $ex) {}

    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'success' => false,
        'gateway' => $gateway_type,
        'bill_no' => $parsedData['bill_no'],
        'recipient' => $phoneDigits,
        'error_code' => 500,
        'error_message' => $failMsg,
        'api_response' => $apiResponseData
    ], JSON_PRETTY_PRINT);
}
?>