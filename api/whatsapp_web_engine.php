<?php
/**
 * Self-Hosted WhatsApp Web Engine API Bridge (Marg ERP CRM)
 * Provides 100% self-hosted Multi-Tenant WhatsApp Web session pairing & message dispatching.
 * Eliminates third-party API dependencies.
 */

if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Content-Type: application/json; charset=UTF-8");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!headers_sent()) http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'check_status';
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?? [];

$user_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? $jsonInput['user_id'] ?? $_SESSION['user_id'] ?? 1);

// Node.js local self-hosted engine URL (if running locally/on server)
$localNodeEngineUrl = getenv('WHATSAPP_ENGINE_URL') ?: 'http://127.0.0.1:3005';

function callLocalNodeEngine($endpoint, $postData = null) {
    global $localNodeEngineUrl;
    $ch = curl_init(rtrim($localNodeEngineUrl, '/') . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res && !$err) {
        return json_decode($res, true);
    }
    return null;
}

if ($action === 'get_qr') {
    // Attempt fetching live QR or status from Node Baileys engine for this specific user
    $nodeRes = callLocalNodeEngine('/qr?user_id=' . $user_id);
    if ($nodeRes) {
        if (!empty($nodeRes['status']) && $nodeRes['status'] === 'connected') {
            $phone = $nodeRes['phone'] ?? $nodeRes['phone_number'] ?? '';
            if (isset($pdo) && $pdo && !empty($phone)) {
                try {
                    $stmtSync = $pdo->prepare("UPDATE merchant_waba_settings SET gateway_type = 'web_api', web_api_session_status = 'connected', business_phone = ? WHERE user_id = ?");
                    $stmtSync->execute(['+' . ltrim($phone, '+'), $user_id]);
                } catch (PDOException $e) {}
            }
            echo json_encode([
                'status'       => 'connected',
                'phone'        => $phone,
                'phone_number' => $phone,
                'user_id'      => $user_id,
                'session_id'   => 'session_user_' . $user_id
            ], JSON_PRETTY_PRINT);
            exit;
        }

        if (!empty($nodeRes['qr'])) {
            echo json_encode([
                'status'     => 'scan_qr',
                'qr_code'    => $nodeRes['qr'],
                'qr_image'   => $nodeRes['qr_image'] ?? ('https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($nodeRes['qr'])),
                'source'     => 'self_hosted_node_engine',
                'user_id'    => $user_id,
                'session_id' => 'session_user_' . $user_id
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    // Check if user is marked as connected in database
    if (isset($pdo) && $pdo) {
        try {
            $stmtChk = $pdo->prepare("SELECT web_api_session_status, business_phone FROM merchant_waba_settings WHERE user_id = ?");
            $stmtChk->execute([$user_id]);
            $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['web_api_session_status'] === 'connected') {
                echo json_encode([
                    'status'       => 'connected',
                    'phone'        => ltrim($row['business_phone'] ?? '', '+'),
                    'phone_number' => ltrim($row['business_phone'] ?? '', '+'),
                    'user_id'      => $user_id,
                    'session_id'   => 'session_user_' . $user_id
                ], JSON_PRETTY_PRINT);
                exit;
            }
        } catch (PDOException $e) {}
    }

    echo json_encode([
        'status'     => 'waiting_engine',
        'qr_code'    => null,
        'qr_image'   => null,
        'source'     => 'self_hosted_php_bridge',
        'user_id'    => $user_id,
        'session_id' => 'session_user_' . $user_id,
        'message'    => 'Run "npm start" inside whatsapp_engine folder on your server to generate live QR code'
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'get_pairing_code') {
    $phone = $_GET['phone'] ?? $_POST['phone'] ?? $jsonInput['phone'] ?? '';
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    if (empty($phoneDigits)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 10-digit mobile number.'], JSON_PRETTY_PRINT);
        exit;
    }

    $nodeRes = callLocalNodeEngine('/pairing-code', [
        'user_id' => $user_id,
        'phone'   => $phoneDigits
    ]);
    if ($nodeRes) {
        echo json_encode($nodeRes, JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        'status'  => 'engine_offline',
        'message' => 'Node Engine is offline. Run "npm start" inside whatsapp_engine folder to request pairing code.'
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'check_status') {
    $nodeRes = callLocalNodeEngine('/status?user_id=' . $user_id);
    if ($nodeRes) {
        $status = $nodeRes['status'] ?? 'disconnected';
        $phone = $nodeRes['phone_number'] ?? $nodeRes['phone'] ?? '';

        if ($status === 'connected' && !empty($phone) && isset($pdo) && $pdo) {
            try {
                $stmtSync = $pdo->prepare("UPDATE merchant_waba_settings SET gateway_type = 'web_api', web_api_session_status = 'connected', business_phone = ? WHERE user_id = ?");
                $stmtSync->execute(['+' . ltrim($phone, '+'), $user_id]);
            } catch (PDOException $e) {}
        } else if ($status === 'disconnected' && isset($pdo) && $pdo) {
            try {
                $stmtSync = $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'disconnected' WHERE user_id = ?");
                $stmtSync->execute([$user_id]);
            } catch (PDOException $e) {}
        }

        echo json_encode([
            'status'       => $status,
            'phone'        => $phone,
            'phone_number' => $phone,
            'user_id'      => $user_id,
            'engine'       => $nodeRes['engine'] ?? 'Self-Hosted Multi-Session Baileys Engine',
            'uptime'       => $nodeRes['uptime'] ?? 0
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Check DB status fallback
    if (isset($pdo) && $pdo) {
        try {
            $stmtChk = $pdo->prepare("SELECT web_api_session_status, business_phone FROM merchant_waba_settings WHERE user_id = ?");
            $stmtChk->execute([$user_id]);
            $row = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['web_api_session_status'] === 'connected') {
                echo json_encode([
                    'status'       => 'connected',
                    'phone'        => ltrim($row['business_phone'] ?? '', '+'),
                    'phone_number' => ltrim($row['business_phone'] ?? '', '+'),
                    'user_id'      => $user_id,
                    'engine'       => 'Self-Hosted Marg ERP Engine v2.0',
                    'last_ping'    => date('Y-m-d H:i:s')
                ], JSON_PRETTY_PRINT);
                exit;
            }
        } catch (PDOException $e) {}
    }

    echo json_encode([
        'status'       => 'scan_qr',
        'session'      => 'ready',
        'phone_number' => null,
        'user_id'      => $user_id,
        'engine'       => 'Self-Hosted Marg ERP Engine v2.0',
        'last_ping'    => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'send_message') {
    $post = !empty($jsonInput) ? $jsonInput : $_POST;

    $recipient = $post['recipient'] ?? $post['phone'] ?? $post['mob'] ?? '';
    $message = $post['message'] ?? $post['msg'] ?? '';
    $pdf_url = $post['document_url'] ?? $post['pdf_url'] ?? '';

    $phoneDigits = preg_replace('/\D/', '', $recipient);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    // Dispatch via local Node engine for this specific user
    $nodeRes = callLocalNodeEngine('/send-message', [
        'user_id'   => $user_id,
        'recipient' => $phoneDigits,
        'message'   => $message,
        'pdf_url'   => $pdf_url
    ]);

    if ($nodeRes) {
        echo json_encode($nodeRes, JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => 'Unable to communicate with local Node WhatsApp engine.'
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'logout') {
    callLocalNodeEngine('/logout', ['user_id' => $user_id]);
    if (isset($pdo) && $pdo) {
        try {
            $stmtSync = $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'disconnected' WHERE user_id = ?");
            $stmtSync->execute([$user_id]);
        } catch (PDOException $e) {}
    }
    echo json_encode(['status' => 'success', 'message' => "Self-hosted session for user {$user_id} cleared."], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.'], JSON_PRETTY_PRINT);
