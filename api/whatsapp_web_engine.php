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

// Central 24/7 Oracle Cloud WhatsApp Engine URL (Port 3000)
$localNodeEngineUrl = defined('WHATSAPP_ENGINE_URL') ? WHATSAPP_ENGINE_URL : (getenv('WHATSAPP_ENGINE_URL') ?: 'http://140.238.167.58:3000');

// Fetch merchant's current settings from DB
$currentMerchant = null;
if (isset($pdo) && $pdo && $user_id) {
    try {
        $stmtUrl = $pdo->prepare("SELECT web_api_url, gateway_type, web_api_session_status, business_phone FROM merchant_waba_settings WHERE user_id = ?");
        $stmtUrl->execute([$user_id]);
        $currentMerchant = $stmtUrl->fetch(PDO::FETCH_ASSOC);
        if (!empty($currentMerchant['web_api_url']) && strpos($currentMerchant['web_api_url'], 'whatsapp_web_engine.php') === false && strpos($currentMerchant['web_api_url'], 'http') === 0) {
            $localNodeEngineUrl = rtrim($currentMerchant['web_api_url'], '/');
        }
    } catch (PDOException $e) {}
}

function callLocalNodeEngine($endpoint, $postData = null) {
    global $localNodeEngineUrl;
    $ch = curl_init(rtrim($localNodeEngineUrl, '/') . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

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

function syncUserWebStatus($pdo, $userId, $status, $phone = '') {
    if (!$pdo || !$userId) return;
    try {
        $cleanPhone = !empty($phone) ? ('+' . ltrim($phone, '+')) : '';
        
        // Fetch current gateway type so we preserve Meta users
        $currentGw = 'meta';
        try {
            $stmtGw = $pdo->prepare("SELECT gateway_type FROM merchant_waba_settings WHERE user_id = ?");
            $stmtGw->execute([$userId]);
            $currentGw = $stmtGw->fetchColumn() ?: 'meta';
        } catch (\Exception $e) {}

        $targetGw = ($currentGw === 'meta') ? 'meta' : 'web_api';
        
        // 1. Update master merchant_waba_settings for this specific user only
        if ($status === 'connected' && !empty($cleanPhone)) {
            $stmt = $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'connected', business_phone = ?, gateway_type = ? WHERE user_id = ?");
            $stmt->execute([$cleanPhone, $targetGw, $userId]);
        } else if ($status === 'disconnected') {
            $stmt = $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'disconnected', business_phone = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        }

        // 2. If this user is a tenant, update ONLY that tenant's dedicated table
        if ($userId > 1) {
            $stmtTenant = $pdo->prepare("SELECT db_name FROM tenant_companies WHERE id = ?");
            $stmtTenant->execute([$userId]);
            $tDb = $stmtTenant->fetchColumn() ?: '';
            if (!empty($tDb) && strpos($tDb, 't_') === 0) {
                $tbl = "{$tDb}merchant_waba_settings";
                try {
                    if ($status === 'connected' && !empty($cleanPhone)) {
                        $stmtT = $pdo->prepare("UPDATE `{$tbl}` SET web_api_session_status = 'connected', business_phone = ?, gateway_type = 'web_api' WHERE user_id = ?");
                        $stmtT->execute([$cleanPhone, $userId]);
                    } else if ($status === 'disconnected') {
                        $stmtT = $pdo->prepare("UPDATE `{$tbl}` SET web_api_session_status = 'disconnected', business_phone = NULL WHERE user_id = ?");
                        $stmtT->execute([$userId]);
                    }
                } catch (\Exception $ex) {}
            }
        }
    } catch (\Exception $e) {}
}

if ($action === 'get_qr') {
    // Attempt fetching live QR or status from Node Baileys engine for this specific user
    $nodeRes = callLocalNodeEngine('/qr?user_id=' . $user_id);
    if ($nodeRes) {
        $engineUserId = isset($nodeRes['user_id']) ? (string)$nodeRes['user_id'] : '';
        $isMultiUserMatch = ($engineUserId === (string)$user_id);

        if (!empty($nodeRes['status']) && $nodeRes['status'] === 'connected') {
            $phone = $nodeRes['phone'] ?? $nodeRes['phone_number'] ?? '';
            $isSameUserPhone = !empty($currentMerchant['business_phone']) && (trim($currentMerchant['business_phone'], '+') === trim($phone, '+'));

            $tenantPhone = '';
            if (isset($pdo) && $pdo && $user_id > 1) {
                try {
                    $stmtTP = $pdo->prepare("SELECT phone FROM tenant_companies WHERE id = ?");
                    $stmtTP->execute([$user_id]);
                    $tenantPhone = $stmtTP->fetchColumn() ?: '';
                } catch (\Exception $e) {}
            }
            $isTenantPhoneMatch = !empty($tenantPhone) && (substr(preg_replace('/\D/', '', $tenantPhone), -10) === substr(preg_replace('/\D/', '', $phone), -10));

            $canAssociate = ($user_id > 1) && ($isMultiUserMatch || $isSameUserPhone || $isTenantPhoneMatch || empty($currentMerchant['business_phone']));
            if ($user_id === 1) {
                $canAssociate = $isMultiUserMatch || $isSameUserPhone;
            }

            if ($canAssociate && !empty($phone)) {
                syncUserWebStatus($pdo, $user_id, 'connected', $phone);
                echo json_encode([
                    'status'       => 'connected',
                    'phone'        => $phone,
                    'phone_number' => $phone,
                    'user_id'      => $user_id,
                    'session_id'   => 'session_user_' . $user_id
                ], JSON_PRETTY_PRINT);
                exit;
            }
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
    if ($currentMerchant && $currentMerchant['web_api_session_status'] === 'connected') {
        echo json_encode([
            'status'       => 'connected',
            'phone'        => ltrim($currentMerchant['business_phone'] ?? '', '+'),
            'phone_number' => ltrim($currentMerchant['business_phone'] ?? '', '+'),
            'user_id'      => $user_id,
            'session_id'   => 'session_user_' . $user_id
        ], JSON_PRETTY_PRINT);
        exit;
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
        $engineUserId = isset($nodeRes['user_id']) ? (string)$nodeRes['user_id'] : '';
        $isMultiUserMatch = ($engineUserId === (string)$user_id);

        if ($status === 'connected' && !empty($phone) && isset($pdo) && $pdo) {
            $isSameUserPhone = !empty($currentMerchant['business_phone']) && (trim($currentMerchant['business_phone'], '+') === trim($phone, '+'));

            $tenantPhone = '';
            if ($user_id > 1) {
                try {
                    $stmtTP = $pdo->prepare("SELECT phone FROM tenant_companies WHERE id = ?");
                    $stmtTP->execute([$user_id]);
                    $tenantPhone = $stmtTP->fetchColumn() ?: '';
                } catch (\Exception $e) {}
            }
            $isTenantPhoneMatch = !empty($tenantPhone) && (substr(preg_replace('/\D/', '', $tenantPhone), -10) === substr(preg_replace('/\D/', '', $phone), -10));

            $canAssociate = ($user_id > 1) && ($isMultiUserMatch || $isSameUserPhone || $isTenantPhoneMatch || empty($currentMerchant['business_phone']));
            if ($user_id === 1) {
                $canAssociate = $isMultiUserMatch || $isSameUserPhone;
            }

            if ($canAssociate) {
                syncUserWebStatus($pdo, $user_id, 'connected', $phone);

                echo json_encode([
                    'status'       => 'connected',
                    'phone'        => $phone,
                    'phone_number' => $phone,
                    'user_id'      => $user_id,
                    'engine'       => $nodeRes['engine'] ?? 'Self-Hosted Multi-Session Baileys Engine',
                    'uptime'       => $nodeRes['uptime'] ?? 0
                ], JSON_PRETTY_PRINT);
                exit;
            }
        } else if (in_array($status, ['disconnected', 'scan_qr'])) {
            if ($user_id > 1) {
                syncUserWebStatus($pdo, $user_id, 'disconnected');
            }

            echo json_encode([
                'status'       => $status,
                'phone'        => null,
                'phone_number' => null,
                'user_id'      => $user_id,
                'engine'       => $nodeRes['engine'] ?? 'Self-Hosted Multi-Session Baileys Engine',
                'uptime'       => $nodeRes['uptime'] ?? 0
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    // Check DB status fallback ONLY if engine was unreachable or in sync
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
    syncUserWebStatus($pdo, $user_id, 'disconnected');
    echo json_encode(['status' => 'success', 'message' => "Self-hosted session for user {$user_id} cleared."], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action.'], JSON_PRETTY_PRINT);
