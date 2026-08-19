<?php
/**
 * Marg ERP 9+ Custom Gateway API Endpoint (Add-On)
 * Receives automatic bill/invoice HTTP POST/GET requests from Marg ERP 9+
 * and dispatches WhatsApp messages with PDF Invoice Attachment & Formatted Card.
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

// Extract API Key from Query Parameter, POST body, or Headers
$api_key = $_GET['api_key'] ?? $_POST['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

if (empty($api_key)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'error' => 401,
        'message' => 'Missing api_key parameter.',
        'data' => [['msg' => 'Invalid API Key']]
    ], JSON_PRETTY_PRINT);
    exit;
}

if (!$db_connected || !$pdo) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'error' => 500,
        'message' => 'Database connection offline.',
        'data' => [['msg' => 'DB Offline']]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Match Tenant API Key to Merchant Settings
try {
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE tenant_api_key = ? AND status = 'Active'");
    $stmt->execute([$api_key]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'error' => 403,
            'message' => 'Invalid or inactive Tenant API Key.',
            'data' => [['msg' => 'Invalid Tenant API Key']]
        ], JSON_PRETTY_PRINT);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'error' => 500,
        'message' => 'Query error: ' . $e->getMessage(),
        'data' => [['msg' => 'Query Error']]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Extract Payload Data from Marg ERP POST or GET
$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?? [];

$recipient = $_POST['mob'] ?? $_GET['mob'] ?? $_POST['mobile'] ?? $_GET['mobile'] ?? $jsonInput['mob'] ?? $jsonInput['mobile'] ?? $jsonInput['phone'] ?? '';
$message_body = $_POST['msg'] ?? $_GET['msg'] ?? $_POST['message'] ?? $_GET['message'] ?? $jsonInput['msg'] ?? '';
$customer_name = $_POST['customer_name'] ?? $_GET['customer_name'] ?? $_POST['party_name'] ?? $_GET['party_name'] ?? $jsonInput['customer_name'] ?? $jsonInput['party_name'] ?? 'Valued Customer';
$bill_number = $_POST['bill_no'] ?? $_GET['bill_no'] ?? $_POST['inv_no'] ?? $_GET['inv_no'] ?? $jsonInput['bill_no'] ?? $jsonInput['bill_number'] ?? '';
$bill_amount = $_POST['amount'] ?? $_GET['amount'] ?? $_POST['amt'] ?? $_GET['amt'] ?? $jsonInput['amount'] ?? $jsonInput['bill_amount'] ?? '0.00';
$balance = $_POST['balance'] ?? $_GET['balance'] ?? $_POST['bal'] ?? $_GET['bal'] ?? $_POST['outstanding'] ?? $_GET['outstanding'] ?? $jsonInput['balance'] ?? $jsonInput['outstanding'] ?? '';
$delivery_man = $_POST['delivery_man'] ?? $_GET['delivery_man'] ?? $_POST['delivery'] ?? $_GET['delivery'] ?? $jsonInput['delivery_man'] ?? '';
$firm_name = $_POST['firm_name'] ?? $_GET['firm_name'] ?? $_POST['company_name'] ?? $_GET['company_name'] ?? $jsonInput['firm_name'] ?? '';
$helpline = $_POST['helpline'] ?? $_GET['helpline'] ?? $merchant['business_phone'] ?? '01130969600';
$pdf_url = $_POST['pdf_url'] ?? $_GET['pdf_url'] ?? $jsonInput['pdf_url'] ?? '';
$template_name = $_POST['template'] ?? $_GET['template'] ?? $jsonInput['template'] ?? 'hello_world';
$event_type = $_POST['event_type'] ?? $_GET['event_type'] ?? $jsonInput['event_type'] ?? 'Invoice';

// Extract BillHeader & BillItem parameters if sent by Marg Control Room "SALE BILL DETAIL" API
$billHeader = $_POST['BillHeader'] ?? $_GET['BillHeader'] ?? $_POST['billheader'] ?? $_GET['billheader'] ?? $jsonInput['BillHeader'] ?? '';
$billItem = $_POST['BillItem'] ?? $_GET['BillItem'] ?? $_POST['billitem'] ?? $_GET['billitem'] ?? $jsonInput['BillItem'] ?? '';

if (!empty($billHeader)) {
    // BillHeader format in Marg ERP: Mobile,Type,BillNo,Amount,Balance;
    $headerParts = explode(',', trim($billHeader, '; '));
    if (!empty($headerParts[0]) && (empty($recipient) || $recipient === '{1}')) {
        $recipient = trim($headerParts[0]);
    }
    if (!empty($headerParts[2]) && empty($bill_number)) {
        $bill_number = trim($headerParts[2]);
    }
    if (isset($headerParts[3]) && ($bill_amount === '0.00' || empty($bill_amount))) {
        $bill_amount = trim($headerParts[3]);
    }
    if (isset($headerParts[4]) && ($balance === '' || $balance === null)) {
        $balance = trim($headerParts[4]);
    }
}

// Sanitize Recipient Phone Number
$phoneDigits = preg_replace('/\D/', '', $recipient);
if ($phoneDigits === '1') $phoneDigits = ''; // Reset literal {1}

if (strlen($phoneDigits) === 10) {
    $phoneDigits = '91' . $phoneDigits; // Default India Country Code
}

// Fallback: If recipient phone is missing or incomplete, extract 10-digit number from message body, raw input, or margsms.txt
if (strlen($phoneDigits) < 10) {
    if (preg_match('/(?:[6-9][0-9]{9})/', $message_body . ' ' . $inputRaw, $mPhone)) {
        $phoneDigits = '91' . $mPhone[0];
    } elseif (file_exists('C:/MARG/margsms.txt')) {
        $mLogLines = array_filter(array_map('trim', file('C:/MARG/margsms.txt')));
        if (!empty($mLogLines)) {
            $lastLog = end($mLogLines);
            $parts = explode('|', $lastLog);
            if (!empty($parts[1])) {
                $logPhone = preg_replace('/\D/', '', $parts[1]);
                if (strlen($logPhone) === 10) {
                    $phoneDigits = '91' . $logPhone;
                } elseif (strlen($logPhone) === 12) {
                    $phoneDigits = $logPhone;
                }
            }
        }
    }
}

if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'error' => 400,
        'message' => 'Valid mobile number is required.',
        'data' => [['msg' => 'Valid mobile number required']]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Verify Merchant Meta Cloud API Credentials
$phone_number_id = !empty($merchant['phone_number_id']) ? $merchant['phone_number_id'] : (defined('PHONE_NUMBER_ID') ? PHONE_NUMBER_ID : '');
$access_token = !empty($merchant['access_token']) ? $merchant['access_token'] : (defined('ACCESS_TOKEN') ? ACCESS_TOKEN : '');

if (empty($phone_number_id) || empty($access_token)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'error' => 400,
        'message' => 'Merchant Meta WABA credentials not configured in CRM portal.',
        'data' => [['msg' => 'WABA Credentials Missing']]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Helper to format raw Marg ERP bill text into structured WhatsApp Invoice Card with Ledger Balance & Bank Details
function formatMargBillText($rawText, $defaultBillNo = '', $defaultAmt = '', $defaultCust = '', $defaultBal = '', $defaultDelivery = '', $defaultFirm = '', $defaultHelpline = '', $pdfUrl = '', $pdo = null, $merchantUserId = null) {
    // 1. Extract Firm Name
    preg_match('/From:\s*([^\n]+)/i', $rawText, $mFirm);
    if (empty($mFirm[1])) {
        preg_match('/For:\s*([^\n]+)/i', $rawText, $mFirm);
    }
    if (empty($mFirm[1])) {
        preg_match('/For\s+([A-Za-z0-9\s\.\&]+?)(?:Delivery|Helpline|Bill|Preview|Date|Amount|$)/i', $rawText, $mFirm);
    }
    $firmName = trim($mFirm[1] ?? '') ?: ($defaultFirm ?: 'Testing Suraj india Ltd.');
    $firmName = preg_replace('/^\*|\*$/', '', $firmName);

    // 2. Extract Delivery Person / Subject
    preg_match('/Subject:\s*([^\n]+)/i', $rawText, $mSub);
    preg_match('/Delivery by\s*([^\n]+)/i', $rawText, $mDeliv);
    if (empty($mDeliv[1])) {
        preg_match('/delivery person\s*([^\n]+)/i', $rawText, $mDeliv);
    }
    if (empty($mDeliv[1])) {
        preg_match('/Delivery:\s*([^\n]+)/i', $rawText, $mDeliv);
    }
    $deliveryMan = trim($mDeliv[1] ?? '') ?: ($defaultDelivery ?: '');
    $deliveryMan = preg_replace('/^\*|\*$/', '', $deliveryMan);
    
    $subjectLine = !empty($mSub[1]) ? trim($mSub[1]) : (!empty($deliveryMan) ? "Delivery by " . $deliveryMan : "Sale Bill Confirmation");
    $subjectLine = preg_replace('/^\*|\*$/', '', $subjectLine);

    // 3. Extract Customer Name
    preg_match('/Name:\s*([^\n\r]+)/i', $rawText, $mCust);
    if (empty($mCust[1])) {
        preg_match('/Name\s+([A-Za-z0-9\s]+?)(?:\r|\n|For|Your|Date|Amount|$)/i', $rawText, $mCust);
    }
    if (empty($mCust[1])) {
        preg_match('/Dear\s*([^\n,\r]+)/i', $rawText, $mCust);
    }
    $custName = trim($mCust[1] ?? '') ?: ($defaultCust ?: 'Valued Customer');
    if (strtolower($custName) === 'sir' || strtolower($custName) === 'customer') {
        if (!empty($defaultCust) && strtolower($defaultCust) !== 'valued customer') {
            $custName = $defaultCust;
        }
    }
    $custName = preg_replace('/^\*|\*$/', '', $custName);

    // 4. Extract Bill / Invoice Number
    preg_match('/Invoice No\.?\s*([A-Za-z0-9_\-]+)/i', $rawText, $mNo);
    if (empty($mNo[1])) {
        preg_match('/No:\s*([A-Za-z0-9_\-]+)/i', $rawText, $mNo);
    }
    if (empty($mNo[1])) {
        preg_match('/Bill No:?\s*([A-Za-z0-9_\-]+)/i', $rawText, $mNo);
    }
    $billNo = $mNo[1] ?? $defaultBillNo ?: 'A000391';

    // 5. Extract Bill Amount
    preg_match('/amount\s*₹?\s*([0-9\.,]+)/i', $rawText, $mAmt);
    if (empty($mAmt[1])) {
        preg_match('/Amount:\s*([0-9\.,]+)/i', $rawText, $mAmt);
    }
    if (empty($mAmt[1])) {
        preg_match('/Amt:\s*([0-9\.,]+)/i', $rawText, $mAmt);
    }
    $billAmt = isset($mAmt[1]) && $mAmt[1] !== '' ? $mAmt[1] : ($defaultAmt !== '' ? $defaultAmt : '0.00');

    // 6. Extract Outstanding / Ledger Balance (exact matching with zero support)
    $billBal = null;
    if (preg_match('/Ledger balance is\s*₹?\s*([0-9\.,]+)/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    } elseif (preg_match('/Balance:\s*([0-9\.,]+)/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    } elseif (preg_match('/Balance\s*([0-9\.,]+)/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    } elseif (preg_match('/Bal:\s*([0-9\.,]+)/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    } elseif (preg_match('/Outstanding:\s*([0-9\.,]+)/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    } elseif (preg_match('/([0-9\.]+)\s+Patient/i', $rawText, $mBal)) {
        $billBal = $mBal[1];
    }

    if ($billBal === null || $billBal === '') {
        $billBal = ($defaultBal !== '' ? $defaultBal : '0.00');
    }

    // 7. Extract Bank Details (from raw text, GET/POST parameters, or user-scoped DB fallback)
    $upiId = $_POST['upi_id'] ?? $_GET['upi_id'] ?? $_POST['upi'] ?? $_GET['upi'] ?? $jsonInput['upi_id'] ?? '';
    $bankName = $_POST['bank_name'] ?? $_GET['bank_name'] ?? $_POST['bank'] ?? $_GET['bank'] ?? $jsonInput['bank_name'] ?? '';
    $accountNo = $_POST['account_no'] ?? $_GET['account_no'] ?? $_POST['acc_no'] ?? $_GET['acc_no'] ?? $jsonInput['account_no'] ?? '';
    $branch = $_POST['branch'] ?? $_GET['branch'] ?? $jsonInput['branch'] ?? '';
    $ifscCode = $_POST['ifsc_code'] ?? $_GET['ifsc_code'] ?? $_POST['ifsc'] ?? $_GET['ifsc'] ?? $jsonInput['ifsc_code'] ?? '';

    if (empty($upiId) && preg_match('/UPI ID\s*:\s*([^\n\r]+)/i', $rawText, $mUpi)) $upiId = trim($mUpi[1]);
    if (empty($bankName) && preg_match('/Bank Name\s*:\s*([^\n\r]+)/i', $rawText, $mBName)) $bankName = trim($mBName[1]);
    if (empty($accountNo) && preg_match('/Account No\s*:\s*([^\n\r]+)/i', $rawText, $mAcc)) $accountNo = trim($mAcc[1]);
    if (empty($branch) && preg_match('/Branch\s*:\s*(.*?)(?=\s*IFSC|\r|\n|$)/i', $rawText, $mBranch)) $branch = trim($mBranch[1]);
    if (empty($ifscCode) && preg_match('/IFSC(?:\s*Code)?\s*:\s*([A-Za-z0-9]+)/i', $rawText, $mIfsc)) $ifscCode = trim($mIfsc[1]);

    // User-Scoped Database Fallback for Bank Details if missing in raw text & parameters
    if (empty($accountNo) && empty($bankName) && $pdo) {
        try {
            $primaryBank = null;
            if (!empty($merchantUserId)) {
                $stmtB = $pdo->prepare("SELECT * FROM bank_accounts WHERE company_id = ? AND status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
                $stmtB->execute([$merchantUserId]);
                $primaryBank = $stmtB->fetch(PDO::FETCH_ASSOC);
            }
            if (!$primaryBank) {
                $stmtB2 = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
                $primaryBank = $stmtB2->fetch(PDO::FETCH_ASSOC);
            }
            if ($primaryBank) {
                $upiId     = trim($primaryBank['upi_id'] ?? '');
                $bankName  = trim($primaryBank['bank_name'] ?? '');
                $accountNo = trim($primaryBank['account_number'] ?? '');
                $branch    = trim($primaryBank['branch'] ?? '');
                $ifscCode  = trim($primaryBank['ifsc_code'] ?? '');
            }
        } catch (PDOException $e) {
            error_log("Bank DB Fetch Error: " . $e->getMessage());
        }
    }

    // 8. Extract Helpline
    preg_match('/Helpline\s*([0-9\+\-\s]+)/i', $rawText, $mHelp);
    $helpline = trim($mHelp[1] ?? '') ?: ($defaultHelpline ?: '');

    // 9. Construct Structured WhatsApp Message Card matching user's screenshot
    $formatted = "From: *" . $firmName . "*\n\n";
    $formatted .= "Subject: *" . $subjectLine . "*\n\n";
    $formatted .= "Dear *" . $custName . "*\n\n";

    if (!empty($deliveryMan)) {
        $formatted .= "Your recent order with Invoice No. *" . $billNo . "* of amount *₹" . $billAmt . "* is on the way via our delivery person *" . $deliveryMan . "*\n\n";
    } else {
        $formatted .= "Your recent order with Invoice No. *" . $billNo . "* of amount *₹" . $billAmt . "* has been successfully generated.\n\n";
    }

    $formatted .= "Please take for your payments:\n\n";
    $formatted .= "Your Ledger balance is *₹" . $billBal . "*\n\n";

    if (!empty($bankName) || !empty($accountNo) || !empty($upiId)) {
        $formatted .= "Bank Details\n";
        if (!empty($upiId)) $formatted .= "UPI ID : *" . $upiId . "*\n";
        if (!empty($bankName)) $formatted .= "Bank Name : *" . $bankName . "*\n";
        if (!empty($accountNo)) $formatted .= "Account No : *" . $accountNo . "*\n";
        if (!empty($branch)) $formatted .= "Branch : *" . $branch . "*\n";
        if (!empty($ifscCode)) $formatted .= "IFSC Code : *" . $ifscCode . "*\n";
        $formatted .= "\n";
    }

    $formatted .= "Regards\n";
    $formatted .= "For *" . $firmName . "*\n";
    if (!empty($helpline)) {
        $formatted .= "Helpline *" . $helpline . "*\n\n";
    } else {
        $formatted .= "\n";
    }

    $formatted .= "Bill PDF Attached\n";

    return [
        'formatted_text' => $formatted,
        'firm_name'     => $firmName,
        'customer_name' => $custName,
        'bill_no'       => $billNo,
        'bill_amount'   => $billAmt,
        'balance'       => $billBal,
        'delivery_man'  => $deliveryMan,
        'helpline'      => $helpline,
        'upi_id'        => $upiId,
        'bank_name'     => $bankName,
        'account_no'    => $accountNo,
        'ifsc_code'     => $ifscCode
    ];
}

// Extract & parse bill parameters from message body first
$parsedData = formatMargBillText($message_body, $bill_number, $bill_amount, $customer_name, $balance, $delivery_man, $firm_name, $helpline, '', $pdo, $merchant['user_id'] ?? null);

$bill_number   = $parsedData['bill_no'];
$bill_amount   = $parsedData['bill_amount'];
$customer_name = $parsedData['customer_name'];
$firm_name     = $parsedData['firm_name'];
$balance       = $parsedData['balance'];
$helpline       = $parsedData['helpline'];

// Handle Marg ERP Direct GUI PDF File Uploads or Local PDF File Copies
$pdfDownloadUrl = null;
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : (defined('NGROK_URL') ? rtrim(NGROK_URL, '/') : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
$uploadsDir = __DIR__ . '/../uploads/invoices/';

if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0777, true);
}

$safeBillNo = preg_replace('/[^A-Za-z0-9_\-]/', '', $bill_number ?: ('BILL_' . time()));

// 1. Check if Marg ERP uploaded a direct PDF file via multipart/form-data
if (!empty($_FILES)) {
    $uploadedFile = $_FILES['pdf'] ?? $_FILES['file'] ?? $_FILES['document'] ?? $_FILES['attachment'] ?? reset($_FILES);
    if (!empty($uploadedFile['tmp_name']) && is_uploaded_file($uploadedFile['tmp_name']) && filesize($uploadedFile['tmp_name']) > 0) {
        $targetFile = $uploadsDir . "Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        if (move_uploaded_file($uploadedFile['tmp_name'], $targetFile)) {
            $pdfDownloadUrl = $baseUrl . "/uploads/invoices/Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        }
    }
}

// 1.1. Check for Base64 Encoded PDF data in POST body or JSON payload
if (!$pdfDownloadUrl) {
    $rawBase64 = $_POST['pdf_base64'] ?? $_POST['base64_pdf'] ?? $_POST['file_data'] ?? $_POST['pdf_content'] ?? $_POST['attachment_base64'] ?? $jsonInput['pdf_base64'] ?? $jsonInput['base64_pdf'] ?? $jsonInput['file_data'] ?? $jsonInput['pdf_content'] ?? '';
    if (!empty($rawBase64)) {
        $cleanBase64 = preg_replace('/^data:application\/[a-z]+;base64,/', '', trim($rawBase64));
        $decodedPdf = base64_decode($cleanBase64, true);
        if ($decodedPdf && str_starts_with($decodedPdf, '%PDF')) {
            $targetFile = $uploadsDir . "Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
            if (file_put_contents($targetFile, $decodedPdf)) {
                $pdfDownloadUrl = $baseUrl . "/uploads/invoices/Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
            }
        }
    }
}

// 2. Check if Marg ERP passed a local Windows file path or web URL in pdf_url parameter
if (!$pdfDownloadUrl && !empty($pdf_url) && $pdf_url !== '{PDF}') {
    $rawPath = trim(rawurldecode($pdf_url), " \"'\t\n\r");
    $normalizedPath = str_replace('\\', '/', $rawPath);

    if (file_exists($normalizedPath) && is_file($normalizedPath) && filesize($normalizedPath) > 0) {
        $targetFile = $uploadsDir . "Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        if (copy($normalizedPath, $targetFile)) {
            $pdfDownloadUrl = $baseUrl . "/uploads/invoices/Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        }
    } elseif (str_starts_with($normalizedPath, 'http://') || str_starts_with($normalizedPath, 'https://')) {
        $pdfDownloadUrl = $normalizedPath;
    }
}

// 3. Auto-scan Windows file system for Marg ERP generated PDF files (Exact Match or Default Export Match)
if (!$pdfDownloadUrl) {
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

    $cleanBillNo = preg_replace('/[^A-Za-z0-9]/', '', $bill_number);
    $matchedFile = null;

    // Collect all valid PDF candidate files across search directories
    $pdfCandidates = [];
    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) continue;
        $dirFiles = @scandir($dir);
        if (empty($dirFiles)) continue;

        foreach ($dirFiles as $f) {
            if ($f === '.' || $f === '..') continue;
            $fullPath = $dir . $f;
            if (is_file($fullPath) && preg_match('/\.pdf$/i', $f) && filesize($fullPath) > 0) {
                $mtime = filemtime($fullPath);
                $pdfCandidates[] = [
                    'path' => $fullPath,
                    'basename' => $f,
                    'mtime' => $mtime,
                    'clean_name' => preg_replace('/[^A-Za-z0-9]/', '', $f)
                ];
            }
        }
    }

    // Strategy 1: Check for exact/partial Bill Number match in filename (modified within 24 hrs)
    if (!empty($cleanBillNo) && !empty($pdfCandidates)) {
        $billMatches = [];
        foreach ($pdfCandidates as $cand) {
            if (stripos($cand['clean_name'], $cleanBillNo) !== false && (time() - $cand['mtime'] < 86400)) {
                $billMatches[] = $cand;
            }
        }
        if (!empty($billMatches)) {
            usort($billMatches, function($a, $b) { return $b['mtime'] - $a['mtime']; });
            $matchedFile = $billMatches[0]['path'];
        }
    }

    // Strategy 2: If no bill number match, search for recent Marg Default PDF exports (invoice*.pdf, bill*.pdf, margsms*.pdf, etc.) modified within 4 hours
    if (!$matchedFile && !empty($pdfCandidates)) {
        $defaultMatches = [];
        $defaultPatterns = '/^(invoice|bill|margsms|marg|salebill|export|print|document|temp|default)/i';
        foreach ($pdfCandidates as $cand) {
            if (preg_match($defaultPatterns, $cand['basename']) && (time() - $cand['mtime'] < 14400)) {
                $defaultMatches[] = $cand;
            }
        }
        if (!empty($defaultMatches)) {
            usort($defaultMatches, function($a, $b) { return $b['mtime'] - $a['mtime']; });
            $matchedFile = $defaultMatches[0]['path'];
        }
    }

    // Strategy 3: Absolute Fallback - Pick the most recently created PDF file anywhere in Marg directories (created within last 1 hour)
    if (!$matchedFile && !empty($pdfCandidates)) {
        $recentCandidates = [];
        foreach ($pdfCandidates as $cand) {
            if (time() - $cand['mtime'] < 3600) {
                $recentCandidates[] = $cand;
            }
        }
        if (!empty($recentCandidates)) {
            usort($recentCandidates, function($a, $b) { return $b['mtime'] - $a['mtime']; });
            $matchedFile = $recentCandidates[0]['path'];
        }
    }

    // If a candidate file was located, copy it to the invoices uploads directory
    if ($matchedFile && file_exists($matchedFile)) {
        $targetFile = $uploadsDir . "Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        if (copy($matchedFile, $targetFile)) {
            $pdfDownloadUrl = $baseUrl . "/uploads/invoices/Marg_GUI_Invoice_" . $safeBillNo . ".pdf";
        }
    }
}

// 5. Fallback to Dynamic Invoice PDF Endpoint with full parsed invoice parameters
if (!$pdfDownloadUrl) {
    $pdfParams = [
        'bill'     => $bill_number,
        'amount'   => $bill_amount,
        'customer' => $customer_name,
        'firm'     => $firm_name,
        'balance'  => $balance,
        'helpline' => $helpline,
        'upi'      => $parsedData['upi_id'],
        'bank'     => $parsedData['bank_name'],
        'account'  => $parsedData['account_no'],
        'ifsc'     => $parsedData['ifsc_code']
    ];
    $pdfDownloadUrl = $baseUrl . "/api/generate_invoice_pdf.php?" . http_build_query($pdfParams);
}

$formattedCaption = $parsedData['formatted_text'] . "Preview: " . $pdfDownloadUrl;

// Document title attachment name matching screenshot (e.g. SB_POSHAK_PATHAK.pdf)
$firmTitleClean = trim($firm_name ?: 'Testing_Suraj_india_Ltd');
$docFilename = "SB_" . str_replace(' ', '_', preg_replace('/[^A-Za-z0-9\s\_]/', '', $firmTitleClean)) . ".pdf";

// Prepare Meta Graph API Payload (Document Message with PDF attached at top)
$metaUrl = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";

// 1. Try Document Payload with PDF file attached at top
$payload = [
    'messaging_product' => 'whatsapp',
    'recipient_type'    => 'individual',
    'to'                => $phoneDigits,
    'type'              => 'document',
    'document'          => [
        'link'     => $pdfDownloadUrl,
        'filename' => $docFilename,
        'caption'  => $formattedCaption
    ]
];

function sendMetaRequest($url, $token, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $resRaw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $json = json_decode($resRaw, true);
    return [$httpCode, $json, $resRaw, $curlErr];
}

list($httpCode, $metaResponse, $resRaw, $curlErr) = sendMetaRequest($metaUrl, $access_token, $payload);

// Fallback Stage 2: Formatted Text Payload if Document payload failed
if ($httpCode !== 200) {
    $textPayload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $phoneDigits,
        'type'              => 'text',
        'text'              => [
            'preview_url'   => false,
            'body'          => $formattedCaption
        ]
    ];
    list($httpCode, $metaResponse, $resRaw, $curlErr) = sendMetaRequest($metaUrl, $access_token, $textPayload);
    if ($httpCode === 200) $payload = $textPayload;
}

// Fallback Stage 3: Meta Template Payload if 24h window restricted Text payload
if ($httpCode !== 200) {
    $fallbackPayload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phoneDigits,
        'type'              => 'template',
        'template'          => [
            'name'     => $template_name,
            'language' => ['code' => 'en_US']
        ]
    ];
    list($httpCode, $metaResponse, $resRaw, $curlErr) = sendMetaRequest($metaUrl, $access_token, $fallbackPayload);
    $payload = $fallbackPayload;
}

$status = ($httpCode === 200 && isset($metaResponse['messages'][0]['id'])) ? 'Sent' : 'Failed';
$meta_message_id = $metaResponse['messages'][0]['id'] ?? null;
$error_message = ($status === 'Failed') ? (!empty($resRaw) ? $resRaw : $curlErr) : null;

// Log Execution in marg_erp_logs
try {
    $stmtLog = $pdo->prepare("
        INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, bill_amount, template_name, status, meta_message_id, error_message, payload_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtLog->execute([
        $merchant['user_id'],
        $api_key,
        $phoneDigits,
        $event_type,
        $bill_number,
        (float)$bill_amount,
        $template_name,
        $status,
        $meta_message_id,
        $error_message,
        json_encode($payload)
    ]);
} catch (PDOException $e) {}

// Output Marg ERP Compatible JSON Response
if ($status === 'Sent') {
    echo json_encode([
        'status'          => 'success',
        'success'         => true,
        'response_code'   => 200,
        'message'         => 'Bill PDF & Message dispatched successfully via WhatsApp.',
        'meta_message_id' => $meta_message_id,
        'recipient'       => $phoneDigits,
        'bill_number'     => $bill_number,
        'data'            => [
            [
                'msg'    => 'SUCCESS',
                'status' => 'SENT',
                'phone'  => $phoneDigits
            ]
        ]
    ], JSON_PRETTY_PRINT);
} else {
    http_response_code(500);
    echo json_encode([
        'status'        => 'error',
        'success'       => false,
        'error'         => 500,
        'message'       => 'Failed to dispatch Meta WhatsApp Cloud API message.',
        'meta_response' => $metaResponse,
        'data'          => [
            [
                'msg'    => 'FAILED',
                'status' => 'FAILED'
            ]
        ]
    ], JSON_PRETTY_PRINT);
}
