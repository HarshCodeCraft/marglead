<?php
/**
 * Marg ERP 9+ Custom Gateway API Endpoint (Cloud SaaS Edition)
 * Receives POST/GET requests and dispatches WhatsApp messages with PDF.
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

$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'success' => false, 'error' => 401, 'message' => 'Missing api_key parameter.'], JSON_PRETTY_PRINT);
    exit;
}

if (!$db_connected || !$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'success' => false, 'error' => 500, 'message' => 'Database connection offline.'], JSON_PRETTY_PRINT);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE tenant_api_key = ? AND status = 'Active'");
    $stmt->execute([$api_key]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'success' => false, 'error' => 403, 'message' => 'Invalid or inactive Tenant API Key.'], JSON_PRETTY_PRINT);
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
$pdf_url = $_POST['pdf_url'] ?? $_GET['pdf_url'] ?? '';

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

    return [
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

$phone_number_id = $merchant['phone_number_id'] ?? '';
$access_token = $merchant['access_token'] ?? '';

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

// Meta API Template Dispatch
$metaUrl = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
$template_name_in_meta = 'marg_pdf'; 

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
                            //'filename' => "Invoice_" . $safeBillNo . ".pdf"
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
                    ['type' => 'text', 'text' => '+91 92773 87778'],
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

$metaResponse = json_decode($resRaw, true);

if ($httpCode === 200 && isset($metaResponse['messages'][0]['id'])) {
    echo json_encode(['status' => 'success', 'message' => 'Dispatched successfully with real PDF.', 'recipient' => $phoneDigits], JSON_PRETTY_PRINT);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'meta_response' => $metaResponse], JSON_PRETTY_PRINT);
}
?>