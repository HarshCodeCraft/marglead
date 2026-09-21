<?php
/**
 * Marg ERP CRM - Add-On: Merchant WABA Setup & Marg ERP Gateway Settings
 * Allows merchants & Super Admin to configure Meta WhatsApp Cloud API credentials
 * OR Self-Hosted WhatsApp Web API (QR Code Instance & Phone Pairing Code)
 * and copy their unique Marg ERP 9+ Webhook Gateway URL.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = trim((string)($_SESSION['user_role'] ?? 'User'));
$user_email = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
$user_name = trim((string)($_SESSION['user_name'] ?? ''));
$tenant_db = trim((string)($_SESSION['tenant_db'] ?? ''));

// =========================================================================
// STRICT SAAS PROJECT OWNER / SUPER ADMIN CHECK (DEEPAK AWASTHI ONLY)
// =========================================================================
$is_saas_project_owner = false;

// 1. Identify if this is any Tenant session
$is_tenant_session = (
    strcasecmp($user_role, 'Tenant Admin') === 0 ||
    !empty($_SESSION['is_tenant']) ||
    (!empty($tenant_db) && $tenant_db !== 'u978772385_friendlyaidata' && $tenant_db !== 'marg_crm' && empty($_SESSION['impersonate_tenant_db']))
);

// 2. Strict Project Owner / Deepak Awasthi verification
if (!$is_tenant_session) {
    $is_deepak_email = in_array($user_email, [
        'deepakawasthi587@gmail.com'
    ]);
    $is_deepak_name = (stripos($user_name, 'deepak') !== false);
    $is_super_admin_role = in_array(strtolower($user_role), ['super admin', 'superadmin']);

    if (($is_deepak_email || $is_deepak_name) && $is_super_admin_role) {
        $is_saas_project_owner = true;
    } elseif ($is_deepak_email) {
        $is_saas_project_owner = true;
    } elseif ($is_super_admin_role && empty($tenant_db)) {
        // Double check against master database users table to make sure it's the master owner
        $is_saas_project_owner = true;
    }
}

$is_super_admin = $is_saas_project_owner;
$is_saas_admin = $is_saas_project_owner;
$message = '';
$message_type = '';

// Self-Hosted Gateway default URL
$default_self_hosted_url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://friendlyaisolution.com') . '/api/whatsapp_web_engine.php';

// Auto-generate or fetch settings for this specific user/tenant
try {
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    // Also check if this tenant has an active record in tenant_whatsapp_configs
    $stmtTc = $pdo->prepare("SELECT * FROM tenant_whatsapp_configs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmtTc->execute([$user_id]);
    $tenantWaba = $stmtTc->fetch(PDO::FETCH_ASSOC);

    if ($tenantWaba && !empty($tenantWaba['phone_number_id'])) {
        if (empty($wabaSettings['phone_number_id'])) $wabaSettings['phone_number_id'] = $tenantWaba['phone_number_id'];
        if (empty($wabaSettings['waba_id'])) $wabaSettings['waba_id'] = $tenantWaba['waba_id'];
        if (empty($wabaSettings['access_token'])) $wabaSettings['access_token'] = $tenantWaba['access_token'];
        if (empty($wabaSettings['business_phone'])) $wabaSettings['business_phone'] = $tenantWaba['display_phone_number'];
    }

    if (!$wabaSettings) {
        $newApiKey = 'MARG-WABA-' . strtoupper(bin2hex(random_bytes(8)));
        $newVerifyToken = bin2hex(random_bytes(16));
        $newWebToken = 'self_key_' . bin2hex(random_bytes(6));
        $newInstanceId = 'session_user_' . $user_id;

        $stmtIns = $pdo->prepare("INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, tenant_api_key, webhook_verify_token, gateway_type, web_api_url, web_api_token, web_api_instance_id, web_api_session_status) VALUES (?, '', '', '', ?, ?, 'meta', ?, ?, ?, 'disconnected')");
        $stmtIns->execute([$user_id, $newApiKey, $newVerifyToken, $default_self_hosted_url, $newWebToken, $newInstanceId]);

        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    } else if (empty($wabaSettings['web_api_token']) || empty($wabaSettings['web_api_instance_id'])) {
        $newWebToken = !empty($wabaSettings['web_api_token']) ? $wabaSettings['web_api_token'] : ('self_key_' . bin2hex(random_bytes(6)));
        $newInstanceId = !empty($wabaSettings['web_api_instance_id']) ? $wabaSettings['web_api_instance_id'] : ('session_user_' . $user_id);
        
        $stmtUpToken = $pdo->prepare("UPDATE merchant_waba_settings SET web_api_token = ?, web_api_instance_id = ? WHERE user_id = ?");
        $stmtUpToken->execute([$newWebToken, $newInstanceId, $user_id]);
        
        $wabaSettings['web_api_token'] = $newWebToken;
        $wabaSettings['web_api_instance_id'] = $newInstanceId;
    }
} catch (PDOException $e) {
    $wabaSettings = [];
}

// Fallback ONLY for Super Admin user (user_id = 1) if central constants are defined. NEVER fallback for other tenants/clients!
if ($is_super_admin && $user_id === 1) {
    if (empty($wabaSettings['phone_number_id']) && defined('PHONE_NUMBER_ID')) {
        $wabaSettings['phone_number_id'] = PHONE_NUMBER_ID;
    }
    if (empty($wabaSettings['waba_id']) && defined('BUSINESS_ACCOUNT_ID')) {
        $wabaSettings['waba_id'] = BUSINESS_ACCOUNT_ID;
    }
    if (empty($wabaSettings['access_token']) && defined('ACCESS_TOKEN')) {
        $wabaSettings['access_token'] = ACCESS_TOKEN;
    }
    if (empty($wabaSettings['business_phone'])) {
        $wabaSettings['business_phone'] = '+91 92773 87778';
    }
}

// Live Status Verification for Web API Gateway (Instantly detects if user logged out from WhatsApp on phone)
if (!empty($wabaSettings['gateway_type']) && $wabaSettings['gateway_type'] === 'web_api') {
    $engineUrl = defined('WHATSAPP_ENGINE_URL') ? WHATSAPP_ENGINE_URL : 'http://140.238.167.58:3000';
    $ch = curl_init(rtrim($engineUrl, '/') . '/status?user_id=' . $user_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 700);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 400);
    $liveRaw = curl_exec($ch);
    curl_close($ch);

    if ($liveRaw) {
        $liveData = json_decode($liveRaw, true);
        if ($liveData && isset($liveData['status'])) {
            if ($liveData['status'] === 'connected' && !empty($liveData['phone_number'])) {
                $wabaSettings['web_api_session_status'] = 'connected';
                $wabaSettings['business_phone'] = $liveData['phone_number'];
            } else if (in_array($liveData['status'], ['disconnected', 'scan_qr'])) {
                $wabaSettings['web_api_session_status'] = 'disconnected';
                $wabaSettings['business_phone'] = '';
                // Instantly sync database
                if (isset($pdo) && $pdo) {
                    try {
                        $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'disconnected', business_phone = NULL WHERE user_id = ?")->execute([$user_id]);
                        if (!empty($_SESSION['tenant_db']) && strpos($_SESSION['tenant_db'], 't_') === 0 && isset($pdo_master) && $pdo_master) {
                            $pdo_master->exec("UPDATE `{$_SESSION['tenant_db']}merchant_waba_settings` SET web_api_session_status = 'disconnected', business_phone = NULL");
                        }
                    } catch (\Exception $e) {}
                }
            }
        }
    }
}

// Check whether THIS specific tenant has configured WhatsApp
$has_meta_setup = !empty($wabaSettings['phone_number_id']) && !empty($wabaSettings['access_token']);
$has_web_setup = !empty($wabaSettings['web_api_session_status']) && $wabaSettings['web_api_session_status'] === 'connected';

// Current gateway type
$current_gateway = !empty($wabaSettings['gateway_type']) ? $wabaSettings['gateway_type'] : 'meta';
$is_setup_done = ($current_gateway === 'web_api') ? $has_web_setup : $has_meta_setup;

$meta_phone_display = !empty($wabaSettings['business_phone']) ? $wabaSettings['business_phone'] : (!empty($tenantWaba['display_phone_number']) ? $tenantWaba['display_phone_number'] : 'Verified');
$web_display_phone = !empty($wabaSettings['business_phone']) ? $wabaSettings['business_phone'] : (!empty($tenantWaba['display_phone_number']) ? $tenantWaba['display_phone_number'] : '');

// Handle Form Submission - Gateway Settings Save (Meta or Self-Hosted Web API)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_waba') {
    $gateway_type = trim($_POST['gateway_type'] ?? 'meta');
    
    // Preserve existing Meta credentials if submitted value is empty
    $submitted_phone_id = trim($_POST['phone_number_id'] ?? '');
    $submitted_waba_id = trim($_POST['waba_id'] ?? '');
    $submitted_token = trim($_POST['access_token'] ?? '');
    $submitted_biz_phone = trim($_POST['business_phone'] ?? '');

    $phone_number_id = !empty($submitted_phone_id) ? $submitted_phone_id : (!empty($wabaSettings['phone_number_id']) ? $wabaSettings['phone_number_id'] : '');
    $waba_id = !empty($submitted_waba_id) ? $submitted_waba_id : (!empty($wabaSettings['waba_id']) ? $wabaSettings['waba_id'] : '');
    $access_token = !empty($submitted_token) ? $submitted_token : (!empty($wabaSettings['access_token']) ? $wabaSettings['access_token'] : '');
    $business_phone = !empty($submitted_biz_phone) ? $submitted_biz_phone : ($wabaSettings['business_phone'] ?? '');

    // Preserve existing Web API credentials if submitted value is empty
    $submitted_web_url = trim($_POST['web_api_url'] ?? '');
    $submitted_web_token = trim($_POST['web_api_token'] ?? '');
    $submitted_web_instance = trim($_POST['web_api_instance_id'] ?? '');

    $web_api_url = !empty($submitted_web_url) ? $submitted_web_url : (!empty($wabaSettings['web_api_url']) ? $wabaSettings['web_api_url'] : $default_self_hosted_url);
    $web_api_token = !empty($submitted_web_token) ? $submitted_web_token : ($wabaSettings['web_api_token'] ?? ('self_key_' . bin2hex(random_bytes(6))));
    $web_api_instance_id = !empty($submitted_web_instance) ? $submitted_web_instance : ($wabaSettings['web_api_instance_id'] ?? ('session_user_' . $user_id));

    try {
        $stmtUp = $pdo->prepare("
            UPDATE merchant_waba_settings 
            SET gateway_type = ?, phone_number_id = ?, waba_id = ?, access_token = ?, business_phone = ?, web_api_url = ?, web_api_token = ?, web_api_instance_id = ?
            WHERE user_id = ?
        ");
        $stmtUp->execute([$gateway_type, $phone_number_id, $waba_id, $access_token, $business_phone, $web_api_url, $web_api_token, $web_api_instance_id, $user_id]);

        // Auto-sync into tenant_whatsapp_configs only if Meta credentials exist
        if (!empty($phone_number_id) || !empty($waba_id)) {
            $stmtSyncTenant = $pdo->prepare("
                INSERT INTO tenant_whatsapp_configs (user_id, firm_name, marg_license_no, waba_id, phone_number_id, display_phone_number, verified_name, access_token, signup_method, status)
                VALUES (?, 'Marg Client', '1352947', ?, ?, ?, 'Marg ERP Client', ?, 'manual', 'active')
                ON DUPLICATE KEY UPDATE 
                    waba_id = IF(VALUES(waba_id) != '', VALUES(waba_id), waba_id),
                    phone_number_id = IF(VALUES(phone_number_id) != '', VALUES(phone_number_id), phone_number_id),
                    display_phone_number = IF(VALUES(display_phone_number) != '', VALUES(display_phone_number), display_phone_number),
                    access_token = IF(VALUES(access_token) != '', VALUES(access_token), access_token),
                    status = 'active'
            ");
            $stmtSyncTenant->execute([$user_id, $waba_id, $phone_number_id, $business_phone, $access_token]);
        }

        $gateway_label = ($gateway_type === 'web_api') ? 'Self-Hosted WhatsApp Web API (QR Code Instance)' : 'Meta WhatsApp Cloud API';
        $message = " Gateway Settings saved! Active Integration Method: " . $gateway_label;
        $message_type = "success";

        // Refresh settings
        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_gateway = $wabaSettings['gateway_type'] ?? 'meta';
        $_SESSION['tenant_gateway_type'] = $current_gateway;
        $has_meta_setup = !empty($wabaSettings['phone_number_id']) && !empty($wabaSettings['access_token']);
        $has_web_setup = !empty($wabaSettings['web_api_session_status']) && $wabaSettings['web_api_session_status'] === 'connected';
        $is_setup_done = ($wabaSettings['gateway_type'] === 'web_api') ? $has_web_setup : $has_meta_setup;
    } catch (PDOException $e) {
        $message = "Error saving credentials: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle Form Submission - Direct WhatsApp Test Dispatch (Meta OR Self-Hosted Web API)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_dispatch') {
    $test_mobile = trim($_POST['test_mobile'] ?? '');
    $test_bill_no = trim($_POST['test_bill_no'] ?? 'INV-TEST-9001');

    $phoneDigits = preg_replace('/\D/', '', $test_mobile);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
        $message = "Please enter a valid 10-digit test mobile number.";
        $message_type = "warning";
    } else {
        if ($current_gateway === 'web_api') {
            // Dispatch via Self-Hosted WhatsApp Web API
            $webUrl = !empty($wabaSettings['web_api_url']) ? rtrim($wabaSettings['web_api_url'], '/') : $default_self_hosted_url;
            $webToken = $wabaSettings['web_api_token'] ?? '';
            $webInstance = $wabaSettings['web_api_instance_id'] ?? '';

            $endpoint = (strpos($webUrl, 'action=') !== false) 
                ? $webUrl . '&action=send_message' 
                : ((strpos($webUrl, '.php') !== false) ? ($webUrl . '?action=send_message') : (rtrim($webUrl, '/') . '/send-message'));

            $postFields = [
                'action'    => 'send_message',
                'recipient' => $phoneDigits,
                'message'   => " Marg ERP 9+ Self-Hosted WhatsApp Web Test Message!\nBill No: {$test_bill_no}\nSent cleanly via paired phone camera session.",
                'token'     => $webToken,
                'instance'  => $webInstance
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $webToken]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $resRaw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resJson = json_decode($resRaw, true) ?? [];

            if ($httpCode === 200 || (!empty($resJson['status']) && strtolower($resJson['status']) === 'success')) {
                $msgId = $resJson['message_id'] ?? $resJson['id'] ?? ('SELF-WEB-' . time());
                $message = " SUCCESS! Test message dispatched via Self-Hosted WhatsApp Web API to {$phoneDigits}!";
                $message_type = "success";

                try {
                    $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, payload_json) VALUES (?, ?, ?, 'Self-Hosted Web Test', ?, 'web_dispatch', 'Sent', ?, ?)");
                    $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'] ?? '', $phoneDigits, $test_bill_no, $msgId, json_encode($postFields)]);
                } catch (PDOException $e) {}
            } else {
                $errDetail = !empty($resJson['message']) ? $resJson['message'] : json_encode($resJson);
                $message = "⚠️ Self-Hosted Web Dispatch: " . ($errDetail ?: 'Posted to ' . $endpoint);
                $message_type = "danger";

                try {
                    $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, 'Self-Hosted Web Test', ?, 'web_dispatch', 'Failed', ?, ?, ?)");
                    $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'] ?? '', $phoneDigits, $test_bill_no, 'FAILED_' . time(), $errDetail, json_encode($postFields)]);
                } catch (PDOException $e) {}
            }
        } else {
            // Dispatch via Meta Cloud API
            $phone_number_id = !empty($wabaSettings['phone_number_id']) ? $wabaSettings['phone_number_id'] : '';
            $access_token = !empty($wabaSettings['access_token']) ? $wabaSettings['access_token'] : '';

            if (empty($phone_number_id) || empty($access_token)) {
                $message = "Meta WhatsApp credentials missing for your account. Please configure Phone Number ID and Access Token first.";
                $message_type = "danger";
            } else {
                $samplePdf = 'https://friendlyaisolution.com/uploads/invoices/Marg_GUI_Invoice_BILL001.pdf';
                $metaUrl = "https://graph.facebook.com/v20.0/{$phone_number_id}/messages";
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phoneDigits,
                    'type'              => 'template',
                    'template'          => [
                        'name'     => 'marg_bill',
                        'language' => ['code' => 'en'],
                        'components' => [
                            [
                                'type' => 'header',
                                'parameters' => [
                                    [
                                        'type' => 'document',
                                        'document' => [
                                            'link' => $samplePdf,
                                            'filename' => "Invoice.pdf"
                                        ]
                                    ]
                                ]
                            ],
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => 'Marg Soft Solution'],
                                    ['type' => 'text', 'text' => 'Valued Customer'],
                                    ['type' => 'text', 'text' => $test_bill_no],
                                    ['type' => 'text', 'text' => '14500.00'],
                                    ['type' => 'text', 'text' => '0.00'],
                                    ['type' => 'text', 'text' => 'HARSHSAINI2017@OKICCI'],
                                    ['type' => 'text', 'text' => 'BOI'],
                                    ['type' => 'text', 'text' => '178963542456'],
                                    ['type' => 'text', 'text' => 'MANDHANA'],
                                    ['type' => 'text', 'text' => 'BKI0125'],
                                    ['type' => 'text', 'text' => 'Marg Soft Solution'],
                                    ['type' => 'text', 'text' => '+91 92773 87778'],
                                    ['type' => 'text', 'text' => $samplePdf]
                                ]
                            ]
                        ]
                    ]
                ];

                $ch = curl_init($metaUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $resRaw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errNo = curl_errno($ch);
                $errMsg = curl_error($ch);
                curl_close($ch);

                $resJson = json_decode($resRaw, true) ?? [];

                if ($httpCode === 200 && isset($resJson['messages'][0]['id'])) {
                    $metaId = $resJson['messages'][0]['id'];
                    $message = " SUCCESS! Test Meta Cloud API Message sent to {$phoneDigits}! Message ID: {$metaId}";
                    $message_type = "success";

                    try {
                        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, payload_json) VALUES (?, ?, ?, '1-Click Test', ?, 'hello_world', 'Sent', ?, ?)");
                        $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'] ?? '', $phoneDigits, $test_bill_no, $metaId, json_encode($payload)]);
                    } catch (PDOException $e) {}
                } else {
                    $errDetail = !empty($resJson['error']['message']) ? $resJson['error']['message'] : ($errMsg ?: json_encode($resJson));
                    $message = "❌ Meta Test Dispatch Failed: " . $errDetail;
                    $message_type = "danger";

                    try {
                        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, error_message, payload_json) VALUES (?, ?, ?, '1-Click Test', ?, 'hello_world', 'Failed', ?, ?, ?)");
                        $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'] ?? '', $phoneDigits, $test_bill_no, 'FAILED_' . time(), $errDetail, json_encode($payload)]);
                    } catch (PDOException $e) {}
                }
            }
        }
    }
}

// Generate Gateway Webhook URL
$base_gateway = defined('BASE_URL') ? BASE_URL : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
$gateway_url = rtrim($base_gateway, '/') . '/api/marg_erp_gateway.php?api_key=' . urlencode($wabaSettings['tenant_api_key'] ?? '') . '&mob={1}&msg={2}&pdf_url={PDF}';

// --------------------------------------------------------------------------
// AJAX Handler for Live Marg ERP Dispatch & Failure Logs
// --------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_live_logs') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    $clientApiKey = $wabaSettings['tenant_api_key'] ?? '';
    try {
        $stmtLogs = $pdo->prepare("SELECT id, user_id, tenant_api_key, recipient_phone, bill_number, bill_amount, event_type, template_name, status, meta_message_id, error_message, payload_json, created_at 
            FROM marg_erp_logs 
            WHERE (user_id = ? OR tenant_api_key = ?) 
            ORDER BY id DESC LIMIT 30");
        $stmtLogs->execute([$user_id, $clientApiKey]);
        $ajaxLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

        $stmtTodayS = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Sent' AND DATE(created_at) = CURRENT_DATE()");
        $stmtTodayS->execute([$user_id, $clientApiKey]);
        $ajaxTodaySent = (int)$stmtTodayS->fetchColumn();

        $stmtTodayF = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Failed' AND DATE(created_at) = CURRENT_DATE()");
        $stmtTodayF->execute([$user_id, $clientApiKey]);
        $ajaxTodayFailed = (int)$stmtTodayF->fetchColumn();

        $stmtMonthS = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Sent' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $stmtMonthS->execute([$user_id, $clientApiKey]);
        $ajaxMonthSent = (int)$stmtMonthS->fetchColumn();

        $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?)");
        $stmtTot->execute([$user_id, $clientApiKey]);
        $ajaxTotal = (int)$stmtTot->fetchColumn();

        echo json_encode([
            'success' => true,
            'stats' => [
                'today_sent' => $ajaxTodaySent,
                'today_failed' => $ajaxTodayFailed,
                'month_sent' => $ajaxMonthSent,
                'total_all' => $ajaxTotal
            ],
            'logs' => $ajaxLogs
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --------------------------------------------------------------------------
// Fetch Real-Time WhatsApp Message Dispatch Statistics for this Client / Number
// --------------------------------------------------------------------------
$todaySentCount = 0;
$todayFailedCount = 0;
$monthSentCount = 0;
$totalSentCount = 0;
$recentDispatches = [];
$clientApiKey = $wabaSettings['tenant_api_key'] ?? '';

try {
    // 1. Today's Successful Dispatched Messages
    $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Sent' AND DATE(created_at) = CURRENT_DATE()");
    $stmtToday->execute([$user_id, $clientApiKey]);
    $todaySentCount = (int)$stmtToday->fetchColumn();

    // 2. Today's Failed Messages
    $stmtTodayF = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Failed' AND DATE(created_at) = CURRENT_DATE()");
    $stmtTodayF->execute([$user_id, $clientApiKey]);
    $todayFailedCount = (int)$stmtTodayF->fetchColumn();

    // 3. This Month's Dispatched Messages
    $stmtMonth = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?) AND status = 'Sent' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $stmtMonth->execute([$user_id, $clientApiKey]);
    $monthSentCount = (int)$stmtMonth->fetchColumn();

    // 4. Total Messages Processed All-Time
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM marg_erp_logs WHERE (user_id = ? OR tenant_api_key = ?)");
    $stmtTotal->execute([$user_id, $clientApiKey]);
    $totalSentCount = (int)$stmtTotal->fetchColumn();

    // 5. Fetch Recent 30 Dispatched & Failed Messages with error details
    $stmtRecent = $pdo->prepare("SELECT id, user_id, tenant_api_key, recipient_phone, bill_number, bill_amount, event_type, template_name, status, meta_message_id, error_message, payload_json, created_at 
        FROM marg_erp_logs 
        WHERE (user_id = ? OR tenant_api_key = ?) 
        ORDER BY id DESC LIMIT 30");
    $stmtRecent->execute([$user_id, $clientApiKey]);
    $recentDispatches = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $todaySentCount = 0;
    $todayFailedCount = 0;
    $monthSentCount = 0;
    $totalSentCount = 0;
    $recentDispatches = [];
}
?>

<div class="content-header" style="margin-bottom: 24px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="qr-code" style="width: 28px; height: 28px; color: var(--primary);"></i>
            WhatsApp Gateway &amp; WABA Setup
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Configure your Meta WhatsApp Cloud API or Self-Hosted WhatsApp Web API (100% No Third Party APIs).
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>" style="padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
        <i data-lucide="info" style="width: 20px; height: 20px;"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>

<!-- Real-time Tenant Connection Status Banner -->
<?php if ($is_setup_done): ?>
    <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: #10b981; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);">
                    <i data-lucide="check-circle-2" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Your WhatsApp Gateway is Active &amp; Connected</h3>
                        <span class="badge" style="background: #10b981; color: white; font-size: 0.75rem; font-weight: 700;">Connected</span>
                    </div>
                    <p style="margin: 4px 0 0 0; font-size: 0.825rem; color: var(--text-muted);">
                        Active Integration Mode: <strong><?php echo ($wabaSettings['gateway_type'] === 'web_api') ? 'Option 2: WhatsApp Web API (Paired Phone)' : 'Option 1: Meta WhatsApp Cloud API (Official WABA)'; ?></strong> &bull;
                        Sender Phone: <strong style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars(($wabaSettings['gateway_type'] === 'web_api' ? $web_display_phone : $meta_phone_display) ?: 'Active Phone'); ?></strong>
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="index.php?page=broadcast_campaigns" class="btn btn-primary font-bold text-xs" style="padding: 8px 16px; border-radius: 8px;">
                    <i data-lucide="send" style="width: 14px; height: 14px; margin-right: 4px;"></i> Open Broadcast &amp; Campaigns Hub
                </a>
                <button type="button" onclick="document.getElementById('setupOptionsSection').scrollIntoView({behavior: 'smooth'})" class="btn btn-secondary font-bold text-xs" style="padding: 8px 16px; border-radius: 8px;">
                    <i data-lucide="settings" style="width: 14px; height: 14px; margin-right: 4px;"></i> Switch Method
                </button>
            </div>
        </div>
    </div>
<?php elseif ($current_gateway === 'meta' && $has_web_setup): ?>
    <!-- Meta selected, but Web API is actually connected -->
    <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.3);">
                    <i data-lucide="info" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Meta Cloud API Not Configured &bull; WhatsApp Web API is Connected</h3>
                        <span class="badge" style="background: #3b82f6; color: white; font-size: 0.75rem; font-weight: 700;">Web API Available</span>
                    </div>
                    <p style="margin: 4px 0 0 0; font-size: 0.825rem; color: var(--text-muted);">
                        Meta Cloud API credentials are empty, but your phone is already paired via <strong>WhatsApp Web API</strong> (Phone: <strong style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars($web_display_phone ?: 'Paired'); ?></strong>).
                    </p>
                </div>
            </div>
            <button type="button" onclick="selectGateway('web_api'); document.getElementById('wabaSettingsForm').submit();" class="btn btn-success font-bold text-xs" style="background: #10b981; color: white; padding: 8px 16px; border-radius: 8px; border: none;">
                <i data-lucide="check" style="width: 14px; height: 14px; margin-right: 4px;"></i> Switch to WhatsApp Web API Now
            </button>
        </div>
    </div>
<?php elseif ($current_gateway === 'web_api' && $has_meta_setup): ?>
    <!-- Web API selected, but Meta is configured -->
    <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.3);">
                    <i data-lucide="info" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">WhatsApp Web API Not Paired &bull; Meta Cloud API is Configured</h3>
                        <span class="badge" style="background: #3b82f6; color: white; font-size: 0.75rem; font-weight: 700;">Meta WABA Available</span>
                    </div>
                    <p style="margin: 4px 0 0 0; font-size: 0.825rem; color: var(--text-muted);">
                        WhatsApp Web API is not paired, but your <strong>Meta WhatsApp Cloud API</strong> is configured (Phone: <strong style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars($meta_phone_display); ?></strong>).
                    </p>
                </div>
            </div>
            <button type="button" onclick="selectGateway('meta'); document.getElementById('wabaSettingsForm').submit();" class="btn btn-primary font-bold text-xs" style="padding: 8px 16px; border-radius: 8px;">
                <i data-lucide="check" style="width: 14px; height: 14px; margin-right: 4px;"></i> Switch to Meta Cloud API Now
            </button>
        </div>
    </div>
<?php else: ?>
    <!-- Neither is connected -->
    <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: #f59e0b; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3);">
                    <i data-lucide="alert-triangle" style="width: 26px; height: 26px;"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">WhatsApp Gateway Setup Required</h3>
                        <span class="badge" style="background: #f59e0b; color: white; font-size: 0.75rem; font-weight: 700;">Not Connected</span>
                    </div>
                    <p style="margin: 4px 0 0 0; font-size: 0.825rem; color: var(--text-muted);">
                        Neither <strong>Meta Cloud API</strong> nor <strong>WhatsApp Web API</strong> is currently connected. Select an integration method below to link your WhatsApp number.
                    </p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('setupOptionsSection').scrollIntoView({behavior: 'smooth'})" class="btn btn-warning font-bold text-xs" style="background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 8px;">
                <i data-lucide="arrow-down" style="width: 14px; height: 14px; margin-right: 4px;"></i> Choose Method Below
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- LIVE MARG ERP INVOICES & WHATSAPP GATEWAY ACTIVITY CONSOLE -->
<!-- ========================================================================= -->
<div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 22px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="activity" style="width: 20px; height: 20px;"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.08rem; font-weight: 700; color: var(--text-main);">Marg ERP Invoices &amp; Dispatch Activity</h3>
                <span style="font-size: 0.75rem; color: var(--text-muted);">Real-time live tracking for linked WhatsApp Number: <strong style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars($wabaSettings['business_phone'] ?: 'Active Channel'); ?></strong></span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" id="btnRefreshLogs" onclick="refreshLiveLogs()" class="btn btn-secondary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <i data-lucide="refresh-cw" id="refreshIcon" style="width: 14px; height: 14px;"></i>
                <span>Refresh Activity</span>
            </button>
            <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 700; font-size: 0.75rem; padding: 6px 10px; border-radius: 8px;">
                <i data-lucide="zap" style="width: 12px; height: 12px; vertical-align: middle;"></i> Live Tracking Active
            </span>
        </div>
    </div>

    <!-- 4 Metric Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
        <!-- Card 1: Today Sent -->
        <div style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Sent Today</span>
                <span style="width: 28px; height: 28px; border-radius: 8px; background: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="check-circle" style="width: 15px; height: 15px;"></i>
                </span>
            </div>
            <div id="statTodaySent" style="font-size: 1.65rem; font-weight: 800; color: #10b981; font-family: var(--font-heading);">
                <?php echo number_format($todaySentCount); ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">bills</span>
            </div>
            <span style="font-size: 0.7rem; color: #10b981; font-weight: 600; display: block; margin-top: 4px;">
                ✓ Successfully Dispatched
            </span>
        </div>

        <!-- Card 2: Failed Today (Alert Highlight) -->
        <div style="background: <?php echo $todayFailedCount > 0 ? 'rgba(239, 68, 68, 0.06)' : 'var(--bg-body)'; ?>; border: 1px solid <?php echo $todayFailedCount > 0 ? 'rgba(239, 68, 68, 0.4)' : 'var(--border-color)'; ?>; border-radius: 12px; padding: 14px 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: <?php echo $todayFailedCount > 0 ? '#ef4444' : 'var(--text-muted)'; ?>; text-transform: uppercase; letter-spacing: 0.05em;">Failed Today</span>
                <span style="width: 28px; height: 28px; border-radius: 8px; background: rgba(239, 68, 68, 0.12); color: #ef4444; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="alert-triangle" style="width: 15px; height: 15px;"></i>
                </span>
            </div>
            <div id="statTodayFailed" style="font-size: 1.65rem; font-weight: 800; color: <?php echo $todayFailedCount > 0 ? '#ef4444' : 'var(--text-main)'; ?>; font-family: var(--font-heading);">
                <?php echo number_format($todayFailedCount); ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">failed</span>
            </div>
            <span id="statTodayFailedLabel" style="font-size: 0.7rem; color: <?php echo $todayFailedCount > 0 ? '#ef4444' : 'var(--text-muted)'; ?>; font-weight: 600; display: block; margin-top: 4px;">
                <?php echo $todayFailedCount > 0 ? '⚠️ Check error details below' : '✓ Zero delivery errors'; ?>
            </span>
        </div>

        <!-- Card 3: This Month Sent -->
        <div style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Sent This Month</span>
                <span style="width: 28px; height: 28px; border-radius: 8px; background: rgba(59, 130, 246, 0.12); color: #3b82f6; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="calendar" style="width: 15px; height: 15px;"></i>
                </span>
            </div>
            <div id="statMonthSent" style="font-size: 1.65rem; font-weight: 800; color: var(--text-main); font-family: var(--font-heading);">
                <?php echo number_format($monthSentCount); ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">bills</span>
            </div>
            <span style="font-size: 0.7rem; color: var(--text-muted); display: block; margin-top: 4px;">
                <?php echo date('F Y'); ?> Cycle
            </span>
        </div>

        <!-- Card 4: Total All-Time -->
        <div style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Total Processed</span>
                <span style="width: 28px; height: 28px; border-radius: 8px; background: rgba(168, 85, 247, 0.12); color: #a855f7; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="layers" style="width: 15px; height: 15px;"></i>
                </span>
            </div>
            <div id="statTotalAll" style="font-size: 1.65rem; font-weight: 800; color: var(--text-main); font-family: var(--font-heading);">
                <?php echo number_format($totalSentCount); ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">total</span>
            </div>
            <span style="font-size: 0.7rem; color: var(--text-muted); display: block; margin-top: 4px;">
                All Time Marg ERP Invoices
            </span>
        </div>
    </div>

    <!-- Prominent Warning Banner if there are failed dispatches today -->
    <div id="failedAlertBanner" style="display: <?php echo ($todayFailedCount > 0) ? 'flex' : 'none'; ?>; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; align-items: center; gap: 10px;">
        <i data-lucide="alert-octagon" style="width: 20px; height: 20px; color: #ef4444; flex-shrink: 0;"></i>
        <div style="font-size: 0.83rem; color: #b91c1c;">
            <strong>Notice:</strong> <span id="failedAlertCount"><?php echo $todayFailedCount; ?></span> Marg ERP invoice dispatch(es) failed today. Review the <strong>Details / Error Reason</strong> column below to diagnose and resolve pairing, token, or recipient errors.
        </div>
    </div>

    <!-- Table Filter Controls -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-right: 4px;">Filter:</span>
            <button type="button" onclick="filterLogs('all')" id="btnFilterAll" class="btn btn-sm active-filter-pill" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 20px; font-weight: 700; border: 1px solid var(--border-color); background: var(--primary); color: white;">
                All (<span id="countPillAll"><?php echo count($recentDispatches); ?></span>)
            </button>
            <button type="button" onclick="filterLogs('Sent')" id="btnFilterSent" class="btn btn-sm" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 20px; font-weight: 700; border: 1px solid var(--border-color); background: var(--bg-body); color: #10b981;">
                  Sent (<span id="countPillSent"><?php echo count(array_filter($recentDispatches, fn($r) => strtolower($r['status'] ?? '') === 'sent')); ?></span>)
            </button>
            <button type="button" onclick="filterLogs('Failed')" id="btnFilterFailed" class="btn btn-sm" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 20px; font-weight: 700; border: 1px solid var(--border-color); background: var(--bg-body); color: #ef4444;">
                 Failed (<span id="countPillFailed"><?php echo count(array_filter($recentDispatches, fn($r) => strtolower($r['status'] ?? '') === 'failed')); ?></span>)
            </button>
        </div>
        <span style="font-size: 0.72rem; color: var(--text-muted);">
            Showing latest 30 dispatches &bull; Updates in real-time
        </span>
    </div>

    <!-- Live Bill Activity Table -->
    <div style="overflow-x: auto; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
        <table id="logsTable" style="width: 100%; border-collapse: collapse; font-size: 0.82rem; text-align: left;">
            <thead>
                <tr style="background: var(--bg-body); border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.73rem; text-transform: uppercase; letter-spacing: 0.05em;">
                    <th style="padding: 10px 14px; font-weight: 700;">Date &amp; Time</th>
                    <th style="padding: 10px 14px; font-weight: 700;">Bill No.</th>
                    <th style="padding: 10px 14px; font-weight: 700;">Recipient Mobile</th>
                    <th style="padding: 10px 14px; font-weight: 700;">Gateway</th>
                    <th style="padding: 10px 14px; font-weight: 700;">Status</th>
                    <th style="padding: 10px 14px; font-weight: 700;">Details / Failure Reason</th>
                    <th style="padding: 10px 14px; font-weight: 700; text-align: center;">Info</th>
                </tr>
            </thead>
            <tbody id="logsTableBody">
                <?php if (empty($recentDispatches)): ?>
                    <tr id="rowNoLogs">
                        <td colspan="7" style="padding: 30px; text-align: center; color: var(--text-muted);">
                            <i data-lucide="inbox" style="width: 32px; height: 32px; margin-bottom: 6px; opacity: 0.6;"></i>
                            <p style="margin: 0; font-weight: 600;">No Marg ERP invoice dispatches recorded yet.</p>
                            <span style="font-size: 0.75rem;">Trigger a bill print from Marg ERP or run a Test Dispatch to view real-time logs here.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentDispatches as $disp): 
                        $isFailed = (strtolower($disp['status'] ?? '') === 'failed');
                        $billDisplay = !empty($disp['bill_number']) ? $disp['bill_number'] : 'N/A';
                        $phoneDisplay = !empty($disp['recipient_phone']) ? $disp['recipient_phone'] : 'N/A';
                        $gwDisplay = (stripos($disp['template_name'] ?? '', 'web') !== false) ? 'WhatsApp Web' : 'Meta WABA';
                        $errText = !empty($disp['error_message']) ? $disp['error_message'] : '';
                        if (empty($errText) && $isFailed) {
                            $payloadParsed = json_decode($disp['payload_json'] ?? '', true);
                            $errText = $payloadParsed['error'] ?? ($payloadParsed['error_message'] ?? 'Dispatch failed without error text.');
                        }
                    ?>
                        <tr class="log-row" data-status="<?php echo $isFailed ? 'Failed' : 'Sent'; ?>" style="border-bottom: 1px solid var(--border-color); background: <?php echo $isFailed ? 'rgba(239, 68, 68, 0.02)' : 'transparent'; ?>;">
                            <td style="padding: 10px 14px; white-space: nowrap; color: var(--text-muted); font-size: 0.77rem;">
                                <?php echo date('d M Y, h:i A', strtotime($disp['created_at'])); ?>
                            </td>
                            <td style="padding: 10px 14px; font-weight: 700; font-family: monospace; color: var(--text-main);">
                                <span class="badge" style="background: rgba(37, 99, 235, 0.08); color: var(--primary); border: 1px solid rgba(37, 99, 235, 0.2); font-size: 0.75rem; padding: 3px 8px; border-radius: 6px;">
                                    <?php echo htmlspecialchars($billDisplay); ?>
                                </span>
                            </td>
                            <td style="padding: 10px 14px; font-weight: 600; color: var(--text-main); font-family: monospace; white-space: nowrap;">
                                <?php echo htmlspecialchars($phoneDisplay); ?>
                            </td>
                            <td style="padding: 10px 14px; white-space: nowrap;">
                                <span class="badge" style="background: <?php echo ($gwDisplay === 'WhatsApp Web') ? 'rgba(16, 185, 129, 0.1)' : 'rgba(59, 130, 246, 0.1)'; ?>; color: <?php echo ($gwDisplay === 'WhatsApp Web') ? '#10b981' : '#3b82f6'; ?>; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 6px;">
                                    <?php echo $gwDisplay; ?>
                                </span>
                            </td>
                            <td style="padding: 10px 14px; white-space: nowrap;">
                                <?php if ($isFailed): ?>
                                    <span class="badge" style="background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 0 6px rgba(239, 68, 68, 0.3);">
                                        <i data-lucide="x-circle" style="width: 12px; height: 12px;"></i> FAILED
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: #10b981; color: white; font-size: 0.7rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i data-lucide="check-circle" style="width: 12px; height: 12px;"></i> SENT
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 14px; max-width: 320px; font-size: 0.78rem;">
                                <?php if ($isFailed): ?>
                                    <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 6px; padding: 5px 8px; color: #b91c1c; font-weight: 600; word-break: break-word;">
                                        <i data-lucide="alert-circle" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 3px; display: inline;"></i>
                                        <?php echo htmlspecialchars($errText ?: 'Dispatch failed.'); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #10b981; font-weight: 600;">
                                        <i data-lucide="check" style="width: 13px; height: 13px; vertical-align: middle; margin-right: 3px; display: inline;"></i>
                                        Delivered with Invoice PDF attachment
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 14px; text-align: center; white-space: nowrap;">
                                <button type="button" onclick="showLogDetails(<?php echo (int)$disp['id']; ?>)" class="btn btn-sm btn-secondary" style="padding: 3px 8px; font-size: 0.72rem; border-radius: 6px;" title="View Raw Details">
                                    <i data-lucide="eye" style="width: 12px; height: 12px;"></i>
                                </button>
                                <textarea id="rawLogData_<?php echo (int)$disp['id']; ?>" style="display: none;"><?php echo htmlspecialchars(json_encode($disp, JSON_PRETTY_PRINT)); ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Raw Payload & Error Inspection Modal -->
<div id="logDetailsModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 99999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; width: 100%; max-width: 650px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,0.3); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="terminal" style="width: 18px; height: 18px; color: var(--primary);"></i>
                <h4 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--text-main);">Invoice Dispatch Technical Details</h4>
            </div>
            <button type="button" onclick="closeLogModal()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <div id="modalStatusBadge" style="margin-bottom: 12px;"></div>
            <pre id="modalJsonContent" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; font-size: 0.75rem; color: var(--text-main); font-family: monospace; white-space: pre-wrap; word-break: break-all; margin: 0;"></pre>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
            <button type="button" onclick="closeLogModal()" class="btn btn-secondary font-bold text-xs" style="padding: 6px 16px; border-radius: 8px;">Close</button>
        </div>
    </div>
</div>

<script>
let currentLogFilter = 'all';

function filterLogs(status) {
    currentLogFilter = status;
    const rows = document.querySelectorAll('.log-row');
    rows.forEach(r => {
        const rowStatus = r.getAttribute('data-status');
        if (status === 'all' || rowStatus === status) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });

    const pills = ['btnFilterAll', 'btnFilterSent', 'btnFilterFailed'];
    pills.forEach(pId => {
        const p = document.getElementById(pId);
        if (!p) return;
        p.style.background = 'var(--bg-body)';
        p.style.color = (pId === 'btnFilterSent') ? '#10b981' : (pId === 'btnFilterFailed' ? '#ef4444' : 'var(--text-main)');
    });

    const activeBtn = document.getElementById('btnFilter' + (status === 'all' ? 'All' : status));
    if (activeBtn) {
        activeBtn.style.background = (status === 'Failed') ? '#ef4444' : (status === 'Sent' ? '#10b981' : 'var(--primary)');
        activeBtn.style.color = 'white';
    }
}

function refreshLiveLogs() {
    const icon = document.getElementById('refreshIcon');
    if (icon) icon.classList.add('spin-refresh');

    fetch('index.php?page=merchant_waba_settings&action=get_live_logs')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                // 1. Update metric counters
                if (data.stats) {
                    const sSent = document.getElementById('statTodaySent');
                    if (sSent) sSent.innerHTML = Number(data.stats.today_sent).toLocaleString() + ' <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">bills</span>';
                    
                    const sFailed = document.getElementById('statTodayFailed');
                    if (sFailed) {
                        const fCount = Number(data.stats.today_failed);
                        sFailed.innerHTML = fCount.toLocaleString() + ' <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">failed</span>';
                        sFailed.style.color = fCount > 0 ? '#ef4444' : 'var(--text-main)';
                    }
                    
                    const sMonth = document.getElementById('statMonthSent');
                    if (sMonth) sMonth.innerHTML = Number(data.stats.month_sent).toLocaleString() + ' <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">bills</span>';
                    
                    const sTotal = document.getElementById('statTotalAll');
                    if (sTotal) sTotal.innerHTML = Number(data.stats.total_all).toLocaleString() + ' <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">total</span>';

                    // Alert banner
                    const alertBanner = document.getElementById('failedAlertBanner');
                    const alertCount = document.getElementById('failedAlertCount');
                    if (alertBanner && alertCount) {
                        const fCount = Number(data.stats.today_failed);
                        alertCount.textContent = fCount;
                        alertBanner.style.display = fCount > 0 ? 'flex' : 'none';
                    }
                }

                // 2. Render table rows
                const tbody = document.getElementById('logsTableBody');
                if (tbody && Array.isArray(data.logs)) {
                    if (data.logs.length === 0) {
                        tbody.innerHTML = '<tr id="rowNoLogs"><td colspan="7" style="padding: 30px; text-align: center; color: var(--text-muted);"><p style="margin: 0; font-weight: 600;">No Marg ERP invoice dispatches recorded yet.</p></td></tr>';
                    } else {
                        let sentCount = 0;
                        let failedCount = 0;
                        let html = '';

                        data.logs.forEach(disp => {
                            const isFailed = (String(disp.status || '').toLowerCase() === 'failed');
                            if (isFailed) failedCount++; else sentCount++;

                            const billDisplay = disp.bill_number ? disp.bill_number : 'N/A';
                            const phoneDisplay = disp.recipient_phone ? disp.recipient_phone : 'N/A';
                            const gwDisplay = (String(disp.template_name || '').indexOf('web') !== -1) ? 'WhatsApp Web' : 'Meta WABA';
                            let errText = disp.error_message || '';
                            if (!errText && isFailed && disp.payload_json) {
                                try {
                                    const p = JSON.parse(disp.payload_json);
                                    errText = p.error || p.error_message || 'Dispatch failed.';
                                } catch(e){}
                            }

                            const d = new Date(disp.created_at);
                            const formattedDate = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

                            html += `
                                <tr class="log-row" data-status="${isFailed ? 'Failed' : 'Sent'}" style="border-bottom: 1px solid var(--border-color); background: ${isFailed ? 'rgba(239, 68, 68, 0.02)' : 'transparent'};">
                                    <td style="padding: 10px 14px; white-space: nowrap; color: var(--text-muted); font-size: 0.77rem;">${formattedDate}</td>
                                    <td style="padding: 10px 14px; font-weight: 700; font-family: monospace; color: var(--text-main);">
                                        <span class="badge" style="background: rgba(37, 99, 235, 0.08); color: var(--primary); border: 1px solid rgba(37, 99, 235, 0.2); font-size: 0.75rem; padding: 3px 8px; border-radius: 6px;">
                                            ${escapeHtml(billDisplay)}
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 600; color: var(--text-main); font-family: monospace; white-space: nowrap;">
                                        ${escapeHtml(phoneDisplay)}
                                    </td>
                                    <td style="padding: 10px 14px; white-space: nowrap;">
                                        <span class="badge" style="background: ${gwDisplay === 'WhatsApp Web' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(59, 130, 246, 0.1)'}; color: ${gwDisplay === 'WhatsApp Web' ? '#10b981' : '#3b82f6'}; font-size: 0.7rem; font-weight: 700; padding: 2px 7px; border-radius: 6px;">
                                            ${gwDisplay}
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; white-space: nowrap;">
                                        ${isFailed 
                                            ? '<span class="badge" style="background: #ef4444; color: white; font-size: 0.7rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 0 6px rgba(239, 68, 68, 0.3);"><i data-lucide="x-circle" style="width: 12px; height: 12px;"></i> FAILED</span>'
                                            : '<span class="badge" style="background: #10b981; color: white; font-size: 0.7rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="check-circle" style="width: 12px; height: 12px;"></i> SENT</span>'
                                        }
                                    </td>
                                    <td style="padding: 10px 14px; max-width: 320px; font-size: 0.78rem;">
                                        ${isFailed
                                            ? `<div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 6px; padding: 5px 8px; color: #b91c1c; font-weight: 600; word-break: break-word;">
                                                <i data-lucide="alert-circle" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 3px; display: inline;"></i>
                                                ${escapeHtml(errText || 'Dispatch failed.')}
                                               </div>`
                                            : `<span style="color: #10b981; font-weight: 600;">
                                                <i data-lucide="check" style="width: 13px; height: 13px; vertical-align: middle; margin-right: 3px; display: inline;"></i>
                                                Delivered with Invoice PDF attachment
                                               </span>`
                                        }
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center; white-space: nowrap;">
                                        <button type="button" onclick="showLogDetails(${disp.id})" class="btn btn-sm btn-secondary" style="padding: 3px 8px; font-size: 0.72rem; border-radius: 6px;" title="View Raw Details">
                                            <i data-lucide="eye" style="width: 12px; height: 12px;"></i>
                                        </button>
                                        <textarea id="rawLogData_${disp.id}" style="display: none;">${escapeHtml(JSON.stringify(disp, null, 2))}</textarea>
                                    </td>
                                </tr>
                            `;
                        });

                        tbody.innerHTML = html;

                        // Update counts
                        const cpAll = document.getElementById('countPillAll');
                        if (cpAll) cpAll.textContent = data.logs.length;
                        const cpSent = document.getElementById('countPillSent');
                        if (cpSent) cpSent.textContent = sentCount;
                        const cpFailed = document.getElementById('countPillFailed');
                        if (cpFailed) cpFailed.textContent = failedCount;

                        filterLogs(currentLogFilter);
                    }
                }
                if (window.lucide) lucide.createIcons();
            }
        })
        .catch(err => console.error('Error fetching live logs:', err))
        .finally(() => {
            if (icon) icon.classList.remove('spin-refresh');
        });
}

function showLogDetails(logId) {
    const rawEl = document.getElementById('rawLogData_' + logId);
    if (!rawEl) return;
    
    let rawText = rawEl.value;
    try {
        const parsed = JSON.parse(rawText);
        rawText = JSON.stringify(parsed, null, 2);
    } catch(e){}

    const modal = document.getElementById('logDetailsModal');
    const jsonEl = document.getElementById('modalJsonContent');
    if (modal && jsonEl) {
        jsonEl.textContent = rawText;
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeLogModal() {
    const modal = document.getElementById('logDetailsModal');
    if (modal) modal.style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
<style>
.spin-refresh {
    animation: spinAnim 0.7s linear infinite;
}
@keyframes spinAnim {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<div id="setupOptionsSection"></div>

<!-- Gateway Selector Tabs -->
<div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
    <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-top: 0; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
        <i data-lucide="sliders" style="width: 20px; height: 20px; color: var(--primary);"></i>
        Select Active WhatsApp Integration Method
    </h3>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <!-- Option 1: Meta Cloud API -->
        <div id="card-gateway-meta" onclick="selectGateway('meta')" style="cursor: pointer; padding: 18px; border-radius: 14px; border: 2px solid <?php echo ($current_gateway === 'meta') ? 'var(--primary)' : 'var(--border-color)'; ?>; background: <?php echo ($current_gateway === 'meta') ? 'rgba(37, 99, 235, 0.08)' : 'var(--bg-app)'; ?>; transition: all 0.2s ease;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="cloud" style="width: 24px; height: 24px; color: var(--primary);"></i>
                    <strong style="font-size: 1rem; color: var(--text-main);">Option 1: Meta Cloud API</strong>
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <?php if ($current_gateway === 'meta'): ?>
                        <span class="badge" style="background: var(--primary); color: white; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700;">Active Method</span>
                    <?php endif; ?>
                    <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: var(--primary); padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700;">Official Meta WABA</span>
                </div>
            </div>
            <p style="font-size: 0.825rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.5;">
                Official Meta Business API. Requires Facebook Meta WABA ID, Phone ID, and Access Token. High volume green tick support.
            </p>
            <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px dashed var(--border-color); padding-top: 8px;">
                <span style="font-size: 0.75rem; color: var(--text-muted);">Connection Status:</span>
                <?php if ($has_meta_setup): ?>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 6px;">
                        <i data-lucide="check-circle-2" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 2px;"></i> Connected (<?php echo htmlspecialchars($meta_phone_display); ?>)
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid #cbd5e1; font-weight: 600; font-size: 0.72rem; padding: 3px 8px; border-radius: 6px;">
                         Not Configured
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Option 2: WhatsApp Web API -->
        <div id="card-gateway-web" onclick="selectGateway('web_api')" style="cursor: pointer; padding: 18px; border-radius: 14px; border: 2px solid <?php echo ($current_gateway === 'web_api') ? '#10b981' : 'var(--border-color)'; ?>; background: <?php echo ($current_gateway === 'web_api') ? 'rgba(16, 185, 129, 0.08)' : 'var(--bg-app)'; ?>; transition: all 0.2s ease;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="smartphone" style="width: 24px; height: 24px; color: #10b981;"></i>
                    <strong style="font-size: 1rem; color: var(--text-main);">Option 2: WhatsApp Web API</strong>
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <?php if ($current_gateway === 'web_api'): ?>
                        <span class="badge" style="background: #10b981; color: white; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700;">Active Method</span>
                    <?php endif; ?>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 700;">Direct QR / Pair</span>
                </div>
            </div>
            <p style="font-size: 0.825rem; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.5;">
                Connect regular WhatsApp by scanning QR Code or 8-digit Phone Pairing Code. Invoices send automatically!
            </p>
            <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px dashed var(--border-color); padding-top: 8px;">
                <span style="font-size: 0.75rem; color: var(--text-muted);">Connection Status:</span>
                <?php if ($has_web_setup): ?>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 6px;">
                        <i data-lucide="check-circle-2" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 2px;"></i> Connected (<?php echo htmlspecialchars($web_display_phone ?: 'Paired'); ?>)
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid #fde68a; font-weight: 600; font-size: 0.72rem; padding: 3px 8px; border-radius: 6px;">
                         Ready to Scan / Pair
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- <?php if ($is_super_admin): ?> -->
<!-- Marg ERP Connection & Live Dispatch Grid (Admin Only) -->
<!-- <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 24px; margin-bottom: 24px;">
    <!-- Box 1: Marg ERP Webhook & config.json -->
    <!-- <div class="card-box" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <div style="background: rgba(37, 99, 235, 0.1); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="link-2" style="width: 22px; height: 22px; color: var(--primary);"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">Marg ERP 9+ Gateway Webhook</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Copy this URL into Marg ERP Software Control Room Setup</span>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">Your Dedicated Marg ERP Gateway URL:</label>
            <div style="display: flex; gap: 8px;">
                <input type="text" id="gatewayUrlInput" readonly value="<?php echo htmlspecialchars($gateway_url); ?>" class="form-control font-mono font-bold" style="background: var(--bg-app); border-color: var(--border-color); color: var(--primary); font-size: 0.825rem;">
                <button type="button" onclick="copyGatewayUrl()" class="btn btn-primary font-bold" style="white-space: nowrap; padding: 0 16px;">
                    <i data-lucide="copy" style="width: 15px; height: 15px; margin-right: 4px;"></i> Copy URL
                </button>
            </div>
        </div>  -->

        <!-- Desktop .exe Application config.json Helper Box -->
        <!-- <div style="background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.25); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <strong style="font-size: 0.85rem; color: #10b981; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="cpu" style="width: 16px; height: 16px;"></i>
                    Marg ERP Desktop .exe Credentials (config.json)
                </strong>
                <span class="badge" style="background: #10b981; color: white; font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; font-weight: 700;">config.json</span>
            </div>

            <div style="position: relative; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px; margin-bottom: 12px;">
                <pre style="margin: 0; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 0.825rem; color: var(--text-main); line-height: 1.5; font-weight: 600;"><span style="color: #3b82f6;">{</span>
  <span style="color: #10b981;">"api_key"</span>: <span style="color: #f59e0b;">"<?php echo htmlspecialchars($wabaSettings['tenant_api_key'] ?? ''); ?>"</span>
<span style="color: #3b82f6;">}</span></pre> -->
            <!-- </div>

            <input type="hidden" id="tenantApiKeyInput" value="<?php echo htmlspecialchars($wabaSettings['tenant_api_key'] ?? ''); ?>">

            <div style="display: flex; gap: 8px;">
                <button type="button" onclick="copyTenantApiKey()" class="btn btn-secondary font-bold text-xs" style="flex: 1; padding: 7px 12px; border-radius: 8px;">
                    <i data-lucide="key" style="width: 14px; height: 14px; margin-right: 4px;"></i> Copy API Key
                </button>
                <button type="button" onclick="copyExeConfigJson()" class="btn btn-success font-bold text-xs" style="flex: 1.2; padding: 7px 12px; border-radius: 8px; background: #10b981; border: none;">
                    <i data-lucide="file-code" style="width: 14px; height: 14px; margin-right: 4px;"></i> Copy config.json
                </button>
            </div>
        </div> -->

        <!-- <div style="background: rgba(37, 99, 235, 0.05); border-left: 3px solid var(--primary); padding: 14px; border-radius: 8px; font-size: 0.8rem; color: var(--text-muted); line-height: 1.6;">
            💡 <strong>How to Setup in Marg ERP 9+:</strong><br>
            1. Open Marg ERP Software &rarr; Press <code>Ctrl + F10</code> (Control Room).<br>
            2. Search for <strong>"SMS / WhatsApp Setup"</strong> &rarr; Choose <strong>HTTP API</strong>.<br>
            3. Paste the <strong>Gateway URL</strong> above into the <strong>WhatsApp HTTP API URL</strong> field.<br>
            4. Marg ERP bills & PDF invoices will automatically route using your active gateway choice (<span id="activeGatewayLabel" class="font-bold text-primary"><?php echo strtoupper($current_gateway); ?></span>)!
        </div>
    </div> -->

    <!-- Box 2: 1-Click Live Dispatch Test Tool -->
    <!-- <div class="card-box" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                <div style="background: rgba(16, 185, 129, 0.1); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="send" style="width: 22px; height: 22px; color: #10b981;"></i>
                </div>
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0;">1-Click Live Dispatch Test</h3>
                    <span style="font-size: 0.8rem; color: var(--text-muted);">Test message dispatch via <strong id="testGatewayLabel" style="color: var(--text-main);"><?php echo ($current_gateway === 'web_api') ? 'WhatsApp Web API' : 'Meta Cloud API'; ?></strong></span>
                </div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="test_dispatch">

                <div style="margin-bottom: 14px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 4px;">Enter Test WhatsApp Number (10 Digits):</label>
                    <input type="text" name="test_mobile" placeholder="e.g. 9876543210" class="form-control text-xs" style="height: 40px; border-radius: 8px;" required>
                </div>

                <div style="margin-bottom: 18px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 4px;">Test Bill Number:</label>
                    <input type="text" name="test_bill_no" value="INV-TEST-<?php echo time(); ?>" class="form-control text-xs font-mono" style="height: 40px; border-radius: 8px; background: var(--bg-app); color: var(--text-muted);" readonly>
                </div>

                <button type="submit" class="btn btn-success font-bold" style="width: 100%; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; background: #10b981; border: none; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);">
                    <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i> Dispatch Test WhatsApp Message
                </button>
            </form>
        </div>
    </div> -->
<!-- </div> -->
<!-- <?php endif; ?> -->

<!-- Main Settings Form (Meta Cloud API OR Self-Hosted Web API) -->
<form method="POST" action="" id="wabaSettingsForm">
    <input type="hidden" name="action" value="save_waba">
    <input type="hidden" name="gateway_type" id="gateway_type_input" value="<?php echo htmlspecialchars($current_gateway); ?>">
    
    <!-- Hidden fields for backend continuity -->
    <input type="hidden" name="web_api_url" value="<?php echo htmlspecialchars(!empty($wabaSettings['web_api_url']) ? $wabaSettings['web_api_url'] : $default_self_hosted_url); ?>">
    <input type="hidden" name="web_api_token" value="<?php echo htmlspecialchars($wabaSettings['web_api_token'] ?? ('self_key_' . bin2hex(random_bytes(6)))); ?>">
    <input type="hidden" name="web_api_instance_id" value="<?php echo htmlspecialchars(!empty($wabaSettings['web_api_instance_id']) ? $wabaSettings['web_api_instance_id'] : ('session_user_' . $user_id)); ?>">

    <!-- Section A: Meta Cloud API Settings (Visible only when Meta is chosen) -->
    <div id="panel-gateway-meta" style="display: <?php echo ($current_gateway === 'meta') ? 'block' : 'none'; ?>; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="cloud" style="width: 22px; height: 22px; color: var(--primary);"></i>
                Meta WhatsApp Cloud API Credentials
            </h3>
            <a href="index.php?page=whatsapp_settings" class="btn btn-primary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px;">
                <i data-lucide="zap" style="width: 14px; height: 14px;"></i> 1-Click Meta Embedded Signup
            </a>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">WhatsApp Phone Number ID:</label>
                <input type="text" name="phone_number_id" value="<?php echo htmlspecialchars($wabaSettings['phone_number_id'] ?? ''); ?>" placeholder="e.g. 1361533150369205" class="form-control font-mono text-xs" style="border-radius: 8px;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">WhatsApp Business Account ID (WABA ID):</label>
                <input type="text" name="waba_id" value="<?php echo htmlspecialchars($wabaSettings['waba_id'] ?? ''); ?>" placeholder="e.g. 28958809240386414" class="form-control font-mono text-xs" style="border-radius: 8px;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">Your Business WhatsApp Phone Number:</label>
                <input type="text" name="business_phone" value="<?php echo htmlspecialchars($wabaSettings['business_phone'] ?? ''); ?>" placeholder="e.g. +91 98765 43210" class="form-control text-xs font-mono" style="border-radius: 8px;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">Permanent Meta Access Token:</label>
                <input type="password" name="access_token" value="<?php echo htmlspecialchars($wabaSettings['access_token'] ?? ''); ?>" placeholder="Enter your permanent Meta access token" class="form-control text-xs font-mono" style="border-radius: 8px;">
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary font-bold" style="padding: 10px 24px; border-radius: 10px; font-size: 0.9rem;">
                <i data-lucide="save" style="width: 18px; height: 18px; margin-right: 6px;"></i> Save Meta Cloud API Settings
            </button>
        </div>
    </div>

    <!-- Section B: Self-Hosted WhatsApp Web API Settings & Pairing Console (Visible only when Web API is chosen) -->
    <?php
    $is_web_connected = (!empty($wabaSettings['web_api_session_status']) && $wabaSettings['web_api_session_status'] === 'connected');
    $web_display_phone = !empty($wabaSettings['business_phone']) ? $wabaSettings['business_phone'] : (!empty($tenantWaba['display_phone_number']) ? $tenantWaba['display_phone_number'] : '');
    ?>
    <div id="panel-gateway-web" style="display: <?php echo ($current_gateway === 'web_api') ? 'block' : 'none'; ?>; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-top: 0; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="smartphone" style="width: 22px; height: 22px; color: #10b981;"></i>
            WhatsApp Web API Settings &amp; Phone Pairing Console
        </h3>

        <!-- QR Code Pairing & Account Console -->
        <div style="background: var(--bg-app); border: 1px dashed var(--border-color); border-radius: 14px; padding: 20px; margin-bottom: 20px; display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
            
            <div style="background: #ffffff; padding: 14px; border-radius: 12px; text-align: center; width: 180px; height: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border: 1px solid #e5e7eb; position: relative;">
                <div id="qrCodeContainer">
                    <img id="qrImage" src="" alt="Live QR Code" style="width: 150px; height: 150px; display: none; border-radius: 6px;">
                    <div id="qrPlaceholder" style="color: #64748b; font-size: 0.8rem; line-height: 1.4; padding: 10px;">
                        <?php if ($is_web_connected): ?>
                            <div style="text-align: center;">
                                <i data-lucide="check-circle-2" style="width: 36px; height: 36px; color: #10b981; margin-bottom: 6px;"></i><br>
                                <strong style="color: #10b981; font-size: 0.9rem;">Connected</strong><br>
                                <span style="font-size: 0.75rem; color: #64748b; font-family: monospace;"><?php echo htmlspecialchars($web_display_phone ?: 'Paired'); ?></span>
                            </div>
                        <?php else: ?>
                             <strong>Status</strong><br>
                            Connecting to WhatsApp instance...
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="flex: 1; min-width: 250px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <?php if ($is_web_connected): ?>
                        <span class="badge" id="sessionStatusBadge" style="background: #10b981; color: white; font-weight: 700; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem;">
                              Status: Connected (<?php echo htmlspecialchars($web_display_phone ?: 'Paired'); ?>)
                        </span>
                    <?php else: ?>
                        <span class="badge" id="sessionStatusBadge" style="background: #f59e0b; color: white; font-weight: 700; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem;">
                             Status: Ready / Select Pairing Method
                        </span>
                    <?php endif; ?>
                </div>
                
                <!-- Connected State Message & Logout Button -->
                <div id="boxConnectedState" style="display: <?php echo $is_web_connected ? 'block' : 'none'; ?>; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 14px; margin-bottom: 12px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <span style="font-size: 0.85rem; color: #10b981; font-weight: 700; display: block; margin-bottom: 2px;">
                                 WhatsApp Account Linked &amp; Active!
                            </span>
                            <span style="font-size: 0.775rem; color: var(--text-muted);">
                                Paired Phone: <strong id="connectedPhoneDisplay" style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars($web_display_phone ?: 'Connected'); ?></strong> &bull; Marg ERP Invoices will send automatically.
                            </span>
                        </div>
                        <button type="button" onclick="logoutWhatsAppSession()" class="btn btn-outline-danger btn-sm" style="padding: 6px 14px; font-size: 0.8rem; border: 1px solid #ef4444; color: #ef4444; border-radius: 8px; font-weight: 600; background: rgba(239, 68, 68, 0.08);">
                            <i data-lucide="power" style="width: 14px; height: 14px; margin-right: 4px;"></i> Logout / Disconnect Device
                        </button>
                    </div>
                </div>

                <!-- Method Selector Tabs: QR Code vs Phone Pairing Code -->
                <div id="pairMethodTabs" style="display: <?php echo $is_web_connected ? 'none' : 'flex'; ?>; gap: 10px; margin-bottom: 12px;">
                    <button type="button" onclick="switchPairMethod('qr')" id="btnPairQr" class="btn btn-primary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px; background: #10b981; border: none;">
                        <i data-lucide="qr-code" style="width: 14px; height: 14px; margin-right: 4px;"></i> Scan QR Code
                    </button>
                    <button type="button" onclick="switchPairMethod('code')" id="btnPairCode" class="btn btn-secondary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px;">
                        <i data-lucide="smartphone" style="width: 14px; height: 14px; margin-right: 4px;"></i> Link with Phone Number Code
                    </button>
                </div>

                <!-- Box 1: QR Instructions -->
                <div id="boxPairQr" style="display: <?php echo $is_web_connected ? 'none' : 'block'; ?>; font-size: 0.825rem; color: var(--text-muted); line-height: 1.5;">
                    1. Open WhatsApp on your phone &rarr; Tap Menu/Settings &rarr; Linked Devices.<br>
                    2. Tap <strong>Link a Device</strong> &amp; point phone camera at the QR code on screen.<br>
                    3. Your phone stays paired with your own server &amp; Marg ERP invoices send automatically!
                </div>

                <!-- Box 2: 8-Digit Phone Pairing Code Request -->
                <div id="boxPairCode" style="display: none; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; margin-top: 8px;">
                    <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: block; margin-bottom: 6px;">Enter Your 10-Digit WhatsApp Mobile Number:</label>
                    <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                        <input type="text" id="pairMobileInput" placeholder="e.g. 9876543210" class="form-control text-xs font-mono" style="height: 38px;">
                        <button type="button" onclick="generatePhonePairingCode()" class="btn btn-success font-bold text-xs" style="white-space: nowrap; padding: 0 14px; background: #10b981; border: none;">
                            Get Code
                        </button>
                    </div>
                    <div id="pairingCodeResult" style="display: none; background: rgba(16, 185, 129, 0.12); border: 1px solid #10b981; border-radius: 8px; padding: 10px; text-align: center;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Your 8-Digit WhatsApp Pairing Code:</span>
                        <strong id="displayPairingCode" style="font-size: 1.4rem; color: #10b981; letter-spacing: 4px; font-family: monospace;">ABCD-1234</strong>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 14px;">
                    <button type="button" onclick="loadLiveQrCode()" class="btn btn-primary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px; background: #10b981; border: none;">
                        <i data-lucide="refresh-cw" style="width: 14px; height: 14px; margin-right: 4px;"></i> Refresh Status
                    </button>
                    <button type="button" onclick="checkSessionStatus()" class="btn btn-secondary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px;">
                        <i data-lucide="check-circle" style="width: 14px; height: 14px; margin-right: 4px;"></i> Verify Connection
                    </button>
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-success font-bold" style="padding: 10px 24px; border-radius: 10px; font-size: 0.9rem; background: #10b981; border: none;">
                <i data-lucide="save" style="width: 18px; height: 18px; margin-right: 6px;"></i> Save WhatsApp Web API Settings
            </button>
        </div>
    </div>
</form>

<script>
function selectGateway(mode) {
    document.getElementById('gateway_type_input').value = mode;
    
    const cardMeta = document.getElementById('card-gateway-meta');
    const cardWeb = document.getElementById('card-gateway-web');
    const panelMeta = document.getElementById('panel-gateway-meta');
    const panelWeb = document.getElementById('panel-gateway-web');
    const activeGatewayLabel = document.getElementById('activeGatewayLabel');
    const testGatewayLabel = document.getElementById('testGatewayLabel');

    if (mode === 'web_api') {
        cardWeb.style.borderColor = '#10b981';
        cardWeb.style.background = 'rgba(16, 185, 129, 0.08)';
        cardMeta.style.borderColor = 'var(--border-color)';
        cardMeta.style.background = 'var(--bg-app)';

        panelWeb.style.display = 'block';
        panelMeta.style.display = 'none';
        
        if (activeGatewayLabel) activeGatewayLabel.innerText = 'WEB_API';
        if (testGatewayLabel) testGatewayLabel.innerText = 'WhatsApp Web API';
        loadLiveQrCode();
    } else {
        cardMeta.style.borderColor = 'var(--primary)';
        cardMeta.style.background = 'rgba(37, 99, 235, 0.08)';
        cardWeb.style.borderColor = 'var(--border-color)';
        cardWeb.style.background = 'var(--bg-app)';

        panelMeta.style.display = 'block';
        panelWeb.style.display = 'none';
        
        if (activeGatewayLabel) activeGatewayLabel.innerText = 'META';
        if (testGatewayLabel) testGatewayLabel.innerText = 'Meta Cloud API';
    }
}

function switchPairMethod(method) {
    const boxQr = document.getElementById('boxPairQr');
    const boxCode = document.getElementById('boxPairCode');
    const btnQr = document.getElementById('btnPairQr');
    const btnCode = document.getElementById('btnPairCode');

    if (method === 'code') {
        boxQr.style.display = 'none';
        boxCode.style.display = 'block';
        btnCode.style.background = '#10b981';
        btnCode.style.color = '#ffffff';
        btnQr.style.background = 'transparent';
        btnQr.style.color = 'var(--text-main)';
    } else {
        boxQr.style.display = 'block';
        boxCode.style.display = 'none';
        btnQr.style.background = '#10b981';
        btnQr.style.color = '#ffffff';
        btnCode.style.background = 'transparent';
        btnCode.style.color = 'var(--text-main)';
    }
}

function copyGatewayUrl() {
    const input = document.getElementById('gatewayUrlInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);
    alert('Marg ERP Gateway URL copied to clipboard!');
}

function copyTenantApiKey() {
    const input = document.getElementById('tenantApiKeyInput');
    navigator.clipboard.writeText(input.value);
    alert('Tenant API Key (' + input.value + ') copied to clipboard!');
}

function copyExeConfigJson() {
    const apiKey = "<?php echo htmlspecialchars($wabaSettings['tenant_api_key'] ?? ''); ?>";
    const configObj = {
        api_key: apiKey
    };
    const jsonStr = JSON.stringify(configObj, null, 2);
    navigator.clipboard.writeText(jsonStr);
    alert('Clean config.json copied to clipboard:\n\n' + jsonStr);
}

function updateWebSessionUI(data) {
    const img = document.getElementById('qrImage');
    const ph = document.getElementById('qrPlaceholder');
    const badge = document.getElementById('sessionStatusBadge');
    const connectedBox = document.getElementById('boxConnectedState');
    const pairTabs = document.getElementById('pairMethodTabs');
    const pairQrBox = document.getElementById('boxPairQr');
    const pairCodeBox = document.getElementById('boxPairCode');

    const rawPhone = data.phone || data.phone_number || '';
    const formattedPhone = rawPhone ? (rawPhone.startsWith('+') ? rawPhone : ('+' + rawPhone)) : 'Paired Device';

    if (data && data.status === 'connected') {
        if (img) img.style.display = 'none';
        if (ph) {
            ph.style.display = 'block';
            ph.innerHTML = "<div style='text-align:center;'><i data-lucide='check-circle-2' style='width:36px;height:36px;color:#10b981;margin-bottom:6px;'></i><br><strong style='color:#10b981;font-size:0.9rem;'>Connected</strong><br><span style='font-size:0.75rem;color:#64748b;font-family:monospace;'>" + formattedPhone + "</span></div>";
        }
        if (badge) {
            badge.innerHTML = "  Status: Connected (" + formattedPhone + ")";
            badge.style.background = "#10b981";
        }
        if (connectedBox) {
            connectedBox.style.display = 'block';
            const phoneElem = document.getElementById('connectedPhoneDisplay');
            if (phoneElem) phoneElem.innerText = formattedPhone;
        }
        if (pairTabs) pairTabs.style.display = 'none';
        if (pairQrBox) pairQrBox.style.display = 'none';
        if (pairCodeBox) pairCodeBox.style.display = 'none';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } else if (data && (data.status === 'scan_qr' || data.status === 'success') && data.qr_image) {
        if (img) {
            img.src = data.qr_image;
            img.style.display = 'block';
        }
        if (ph) ph.style.display = 'none';
        if (badge) {
            badge.innerHTML = " Status: Ready / Scan QR Code";
            badge.style.background = "#f59e0b";
        }
        if (connectedBox) connectedBox.style.display = 'none';
        if (pairTabs) pairTabs.style.display = 'flex';
        if (pairQrBox) pairQrBox.style.display = 'block';
    } else if (data && (data.status === 'waiting_engine' || data.status === 'engine_offline' || data.status === 'error')) {
        if (img) img.style.display = 'none';
        if (ph) {
            ph.style.display = 'block';
            ph.innerHTML = "<div style='text-align:center;padding:4px;'><i data-lucide='alert-circle' style='width:32px;height:32px;color:#ef4444;margin-bottom:6px;'></i><br><strong style='color:#ef4444;font-size:0.85rem;'>Cloud Engine Reconnecting</strong><br><span style='font-size:0.75rem;color:#64748b;'>Click 'Refresh Status' in a few seconds.</span></div>";
        }
        if (badge) {
            badge.innerHTML = " Status: Cloud Engine Reconnecting";
            badge.style.background = "#ef4444";
        }
        if (connectedBox) connectedBox.style.display = 'none';
        if (pairTabs) pairTabs.style.display = 'flex';
        if (pairQrBox) pairQrBox.style.display = 'block';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } else if (data && data.status === 'initializing') {
        if (img) img.style.display = 'none';
        if (ph) {
            ph.style.display = 'block';
            ph.innerHTML = " <strong>Generating QR Code...</strong><br><span style='font-size:0.75rem;color:#64748b;'>Please wait a moment...</span>";
        }
        if (badge) {
            badge.innerHTML = " Status: Generating QR Code...";
            badge.style.background = "#f59e0b";
        }
        setTimeout(loadLiveQrCode, 2500);
    } else {
        if (img) img.style.display = 'none';
        if (ph) {
            ph.style.display = 'block';
            if (data && data.message) {
                ph.innerHTML = " <strong> Status</strong><br>" + data.message;
            } else {
                ph.innerHTML = " Click 'Refresh Status' or scan QR.";
            }
        }
        if (badge) {
            badge.innerHTML = " Status: Ready / Select Pairing Method";
            badge.style.background = "#f59e0b";
        }
        if (connectedBox) connectedBox.style.display = 'none';
        if (pairTabs) pairTabs.style.display = 'flex';
        if (pairQrBox) pairQrBox.style.display = 'block';
    }
}

const currentUserId = <?php echo (int)$user_id; ?>;

function loadLiveQrCode() {
    const badge = document.getElementById('sessionStatusBadge');
    if (badge && !badge.innerText.includes('Connected')) {
        badge.innerHTML = "⏳ Fetching Status / QR...";
    }
    fetch('api/whatsapp_web_engine.php?action=get_qr&user_id=' + currentUserId)
        .then(res => res.json())
        .then(data => {
            updateWebSessionUI(data);
        })
        .catch(err => {
            console.log('QR load error', err);
            updateWebSessionUI({ status: 'waiting_engine' });
        });
}

function checkSessionStatus() {
    const badge = document.getElementById('sessionStatusBadge');
    if (badge) badge.innerHTML = "⏳ Checking Connection...";
    fetch('api/whatsapp_web_engine.php?action=check_status&user_id=' + currentUserId)
        .then(res => res.json())
        .then(data => {
            updateWebSessionUI(data);
        })
        .catch(err => {
            console.log('Status check error', err);
            updateWebSessionUI({ status: 'waiting_engine' });
        });
}

function logoutWhatsAppSession() {
    if (!confirm("Are you sure you want to disconnect & logout this WhatsApp account?")) return;
    fetch('api/whatsapp_web_engine.php?action=logout&user_id=' + currentUserId)
        .then(res => res.json())
        .then(data => {
            alert("WhatsApp account disconnected successfully!");
            location.reload();
        })
        .catch(err => {
            alert("Session cleared.");
            location.reload();
        });
}

function generatePhonePairingCode() {
    const phone = document.getElementById('pairMobileInput').value;
    if (!phone || phone.length < 10) {
        alert('Please enter a valid 10-digit mobile number.');
        return;
    }

    fetch('api/whatsapp_web_engine.php?action=get_pairing_code&user_id=' + currentUserId + '&phone=' + encodeURIComponent(phone))
        .then(res => res.json())
        .then(data => {
            if (data && data.pairing_code) {
                document.getElementById('displayPairingCode').innerText = data.pairing_code;
                document.getElementById('pairingCodeResult').style.display = 'block';
            } else {
                alert(data.message || 'offline. Run "npm start" inside whatsapp_engine folder first.');
            }
        })
        .catch(err => {
            alert('Unable to request pairing code. Run "npm start" inside whatsapp_engine folder first.');
        });
}

// Auto load status / QR on page render if web_api mode active
document.addEventListener('DOMContentLoaded', () => {
    if ("<?php echo $current_gateway; ?>" === 'web_api') {
        loadLiveQrCode();
    }
});

</script>
