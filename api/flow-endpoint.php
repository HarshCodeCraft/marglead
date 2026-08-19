<?php
/**
 * Marg CRM - WhatsApp Flow Endpoint (Data Exchange & Dynamic Lookup)
 * 
 * URL: https://friendlyaisolution.com/api/flow-endpoint.php
 * 
 * Supports:
 * 1. Meta WhatsApp Flows Encryption/Decryption Protocol (RSA OAEP SHA-256 + AES-128-GCM)
 * 2. Unencrypted Plain JSON Data Exchange (For local testing & direct cURL execution)
 * 3. Dynamic MySQL License Number Lookup (customers & client_directory tables)
 * 4. Automated Ticket Creation (TK-2026-XXXXXX) & WhatsApp Confirmation Dispatch.
 */

require_once __DIR__ . '/whatsapp-api.php';

// Handle GET request (Health check ping from Meta or browser)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'version' => '3.0',
        'data'    => [
            'status' => 'active'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// Read raw body from incoming POST request
$rawInput = file_get_contents('php://input');
write_log('flow', "Incoming Flow Endpoint Request Payload", $rawInput);

$requestJson = json_decode($rawInput, true) ?? [];

$isEncrypted = isset($requestJson['encrypted_aes_key']) && isset($requestJson['encrypted_flow_data']) && isset($requestJson['initial_vector']);
$decryptedAesKey = null;
$initialVector   = null;

// MGF1 SHA-256 Mask Generation Function
function mgf1_sha256(string $seed, int $maskLen): string {
    $mask = '';
    $counter = 0;
    while (strlen($mask) < $maskLen) {
        $C = pack('N', $counter);
        $mask .= hash('sha256', $seed . $C, true);
        $counter++;
    }
    return substr($mask, 0, $maskLen);
}

// RSA OAEP SHA-256 Decryption Function (Meta WhatsApp Flow Specification)
function rsa_oaep_sha256_decrypt(string $encryptedData, string $privateKeyPem): ?string {
    // Attempt standard OpenSSL decryption first
    $decrypted = null;
    if (@openssl_private_decrypt($encryptedData, $decrypted, $privateKeyPem, OPENSSL_PKCS1_OAEP_PADDING)) {
        return $decrypted;
    }

    // Fallback to manual RSA-OAEP SHA-256 MGF1 SHA-256 decoding
    $EM = null;
    $success = @openssl_private_decrypt($encryptedData, $EM, $privateKeyPem, OPENSSL_NO_PADDING);
    if (!$success || strlen($EM) !== 256) {
        return null;
    }

    $hLen = 32; // SHA-256 Hash length
    $k = 256;   // 2048-bit RSA key length

    $maskedSeed = substr($EM, 1, $hLen);
    $maskedDB   = substr($EM, 1 + $hLen);

    $seedMask = mgf1_sha256($maskedDB, $hLen);
    $seed     = $maskedSeed ^ $seedMask;

    $dbMask = mgf1_sha256($seed, $k - $hLen - 1);
    $DB     = $maskedDB ^ $dbMask;

    $lHash = substr($DB, 0, $hLen);
    $expectedLHash = hash('sha256', '', true);

    if ($lHash !== $expectedLHash) {
        return null;
    }

    $pos = strpos($DB, "\x01", $hLen);
    if ($pos === false) {
        return null;
    }

    return substr($DB, $pos + 1);
}

// -------------------------------------------------------------
// 1. Decrypt Request Payload (If Meta sends encrypted flow data)
// -------------------------------------------------------------
if ($isEncrypted) {
    try {
        $privateKeyPem = null;
        if (defined('FLOW_PRIVATE_KEY_PATH') && file_exists(FLOW_PRIVATE_KEY_PATH)) {
            $privateKeyPem = file_get_contents(FLOW_PRIVATE_KEY_PATH);
        }
        if (empty($privateKeyPem) && file_exists(__DIR__ . '/../config/private_key.pem')) {
            $privateKeyPem = file_get_contents(__DIR__ . '/../config/private_key.pem');
        }

        if (empty($privateKeyPem)) {
            write_log('error', "Flow private key pem file missing at config/private_key.pem");
            http_response_code(500);
            echo "Private Key Missing";
            exit;
        }

        $encryptedAesKey  = base64_decode($requestJson['encrypted_aes_key']);
        $encryptedFlowData= base64_decode($requestJson['encrypted_flow_data']);
        $initialVector    = base64_decode($requestJson['initial_vector']);

        // Step A: Decrypt AES key using RSA OAEP SHA-256
        $decryptedAesKey = rsa_oaep_sha256_decrypt($encryptedAesKey, $privateKeyPem);
        
        if (!$decryptedAesKey) {
            throw new Exception("Decryption of AES key failed");
        }

        // Step B: Decrypt flow data using AES-128-GCM
        $tagLength = 16;
        $ciphertext = substr($encryptedFlowData, 0, -$tagLength);
        $tag        = substr($encryptedFlowData, -$tagLength);

        $decryptedJson = openssl_decrypt($ciphertext, 'aes-128-gcm', $decryptedAesKey, OPENSSL_RAW_DATA, $initialVector, $tag);

        if (!$decryptedJson) {
            throw new Exception("Decryption of flow data body failed");
        }

        $requestData = json_decode($decryptedJson, true) ?? [];
        write_log('flow', "Decrypted Flow Payload", $requestData);

    } catch (Throwable $e) {
        write_log('error', "Flow Decryption Error: " . $e->getMessage());
        http_response_code(400);
        echo "Decryption Failed";
        exit;
    }
} else {
    // Plain JSON Request
    $requestData = $requestJson;
}

// -------------------------------------------------------------
// 2. Process Business Logic (License Lookup & Ticket Submit)
// -------------------------------------------------------------
$action    = $requestData['action'] ?? 'data_exchange';
$data      = $requestData['data'] ?? $requestData;
$flowToken = $requestData['flow_token'] ?? $data['flow_token'] ?? ('token_' . time());
$screen    = $requestData['screen'] ?? 'screen_1';

// Case 0: Meta Health Check Ping vs Flow INIT
if ($action === 'ping') {
    $responsePayload = [
        'version' => '3.0',
        'data'    => [
            'status' => 'active'
        ]
    ];
} elseif ($action === 'INIT') {
    $responsePayload = [
        'version' => '3.0',
        'screen'  => 'WELCOME_SCREEN',
        'data'    => [
            'department' => 'support'
        ]
    ];
}

// Case B: Ticket Submission / Form Completion
elseif ($action === 'submit' || $action === 'complete' || $action === 'create_ticket' || !empty($data['problem']) || !empty($data['description']) || !empty($data['c3'])) {

    $licenseNo    = trim($data['license_number'] ?? $data['c1'] ?? 'N/A');
    $customerName = trim($data['customer_name'] ?? $data['contact_person'] ?? 'Valued Customer');
    $firmName     = trim($data['firm_name'] ?? $data['company'] ?? 'N/A');
    $mobile       = trim($data['mobile_number'] ?? $data['callback_number'] ?? $data['c4'] ?? $data['phone'] ?? '');
    $email        = trim($data['email_address'] ?? 'N/A');
    $category     = trim($data['issue_category'] ?? $data['subject'] ?? $data['c2'] ?? 'Technical Support');
    $priority     = trim($data['priority'] ?? 'Medium');
    $description  = trim($data['description'] ?? $data['problem'] ?? $data['c3'] ?? '');
    $attachment   = trim($data['attachment'] ?? '');

    // Generate Ticket Number (TK-2026-XXXXXX)
    $ticketNumber = generate_ticket_number($pdo);

    try {
        $stmt = $pdo->prepare("INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, attachment, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open')");
        $stmt->execute([
            $ticketNumber,
            $licenseNo,
            $firmName,
            $customerName,
            $mobile,
            $email,
            $category,
            $priority,
            $description,
            $attachment
        ]);

        // Sync insert into main support_tickets table
        try {
            $subj = (!empty($category) ? $category : 'Technical Support') . ($firmName !== 'N/A' && !empty($firmName) ? " - " . $firmName : "");
            $stmtSup = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, phone, email, problem, callback_number, lead_id, product, date_created) VALUES (?, ?, ?, ?, 'open', 'Unassigned', ?, ?, ?, ?, ?, 'Marg ERP Pro', NOW())");
            $stmtSup->execute([
                $ticketNumber,
                $customerName,
                $subj,
                strtolower($priority),
                $mobile,
                ($email !== 'N/A' ? $email : ''),
                $description,
                $mobile,
                $licenseNo
            ]);
        } catch (Throwable $eSup) {}

        write_log('flow', "Ticket Created Successfully: $ticketNumber", ['ticket_number' => $ticketNumber, 'mobile' => $mobile]);

        // Send Confirmation WhatsApp Message
        if (!empty($mobile)) {
            $whatsapp = new WhatsAppAPI($pdo);
            $confirmText = "✅ *Ticket Created Successfully*\n\n" .
                           "*Ticket Number*\n" .
                           "{$ticketNumber}\n\n" .
                           "Thank you for contacting Marg Soft Solution.\n\n" .
                           "Our support engineer will contact you shortly.";
            $whatsapp->sendText($mobile, $confirmText);
        }

    } catch (Throwable $e) {
        write_log('error', "Failed inserting ticket: " . $e->getMessage());
    }

    $targetSuccessScreen = ($screen === 'REVIEW_SCREEN' || $action === 'create_ticket') ? 'SUCCESS_SCREEN' : 'SUCCESS';

    $responsePayload = [
        'version' => '3.0',
        'screen'  => $targetSuccessScreen,
        'data'    => [
            'ticket_number' => $ticketNumber,
            'extension_message_response' => [
                'params' => [
                    'flow_token'    => $flowToken,
                    'ticket_number' => $ticketNumber,
                    'status'        => 'SUCCESS',
                    'message'       => 'Ticket Created Successfully'
                ]
            ]
        ]
    ];
}

// Case A: Dynamic License Number Lookup (data_exchange / license_lookup)
elseif ($action === 'data_exchange' || $action === 'license_lookup' || isset($data['license_number']) || isset($data['license_no'])) {
    
    $licenseNo = trim($data['license_number'] ?? $data['license_no'] ?? $data['c1'] ?? '');
    $custData = null;

    if (!empty($licenseNo)) {
        try {
            // Step 1: Search in primary customers table
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE license_no = ? LIMIT 1");
            $stmt->execute([$licenseNo]);
            $custData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Step 2: Fallback to client_directory table if not found
            if (!$custData) {
                $stmtCD = $pdo->prepare("SELECT customer_id as license_no, party_name as customer_name, company_using as firm_name, mobile, email, due_on as amc_expiry FROM client_directory WHERE customer_id = ? LIMIT 1");
                $stmtCD->execute([$licenseNo]);
                $custData = $stmtCD->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            write_log('error', "License lookup database error: " . $e->getMessage());
        }
    }

    $nextScreen = ($screen === 'LICENSE_SCREEN' || $action === 'license_lookup') ? 'CUSTOMER_DETAILS_SCREEN' : 'screen_1';

    if ($custData) {
        $responsePayload = [
            'version' => '3.0',
            'screen'  => $nextScreen,
            'data'    => [
                'department'     => $data['department'] ?? 'support',
                'license_number' => $custData['license_no'],
                'customer_name'  => $custData['customer_name'] ?? $custData['party_name'] ?? '',
                'firm_name'      => $custData['firm_name'] ?? $custData['company_using'] ?? '',
                'mobile_number'  => $custData['mobile'] ?? '',
                'email_address'  => $custData['email'] ?? '',
                'amc_expiry_date'=> $custData['amc_expiry'] ?? 'Active',
                'license_status' => 'License Found',
                'is_found'       => true
            ]
        ];
    } else {
        $responsePayload = [
            'version' => '3.0',
            'screen'  => $nextScreen,
            'data'    => [
                'department'     => $data['department'] ?? 'support',
                'license_number' => $licenseNo,
                'customer_name'  => 'N/A',
                'firm_name'      => 'N/A',
                'mobile_number'  => '',
                'email_address'  => '',
                'amc_expiry_date'=> 'N/A',
                'license_status' => 'License Not Found',
                'is_found'       => false
            ]
        ];
    }

    write_log('flow', "License Lookup Response for '$licenseNo'", $responsePayload);
} else {
    $responsePayload = [
        'version' => '3.0',
        'screen'  => 'WELCOME_SCREEN',
        'data'    => [
            'department' => 'support'
        ]
    ];
}

// -------------------------------------------------------------
// 3. Send Response (Encrypted if Meta, Plain JSON if unencrypted)
// -------------------------------------------------------------
if ($isEncrypted && !empty($decryptedAesKey) && !empty($initialVector)) {
    // Flip IV for Meta Response Encryption
    $flipped_iv = ~$initialVector;
    $cipherText = openssl_encrypt(json_encode($responsePayload), 'aes-128-gcm', $decryptedAesKey, OPENSSL_RAW_DATA, $flipped_iv, $tag);
    $responseBody = base64_encode($cipherText . $tag);

    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo $responseBody;
    exit;
} else {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
