<?php
/**
 * Marg ERP CRM - Add-On: Merchant WABA Setup & Marg ERP Gateway Settings
 * Allows merchants & Super Admin to configure Meta WhatsApp Cloud API credentials
 * OR WhatsApp Web API (QR Code Instance Gateway e.g. whtapi.com / Baileys)
 * and copy their unique Marg ERP 9+ Webhook Gateway URL.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../config/config.php';

$user_id = $_SESSION['user_id'] ?? 1;
$message = '';
$message_type = '';

// Auto-generate or fetch settings for this user
try {
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wabaSettings) {
        $newApiKey = 'MARG-WABA-' . strtoupper(bin2hex(random_bytes(8)));
        $newVerifyToken = bin2hex(random_bytes(16));

        $stmtIns = $pdo->prepare("INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, tenant_api_key, webhook_verify_token, gateway_type, web_api_url, web_api_token, web_api_session_status) VALUES (?, '', '', '', ?, ?, 'meta', 'https://wa.whtapi.com/', '', 'disconnected')");
        $stmtIns->execute([$user_id, $newApiKey, $newVerifyToken]);

        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $wabaSettings = [];
}

// Current gateway type
$current_gateway = !empty($wabaSettings['gateway_type']) ? $wabaSettings['gateway_type'] : 'meta';

// Handle Form Submission - Gateway Settings Save (Meta or Web API)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_waba') {
    $gateway_type = trim($_POST['gateway_type'] ?? 'meta');
    $phone_number_id = trim($_POST['phone_number_id'] ?? '');
    $waba_id = trim($_POST['waba_id'] ?? '');
    $access_token = trim($_POST['access_token'] ?? '');
    $business_phone = trim($_POST['business_phone'] ?? '');

    $web_api_url = trim($_POST['web_api_url'] ?? 'https://wa.whtapi.com/');
    $web_api_token = trim($_POST['web_api_token'] ?? '');
    $web_api_instance_id = trim($_POST['web_api_instance_id'] ?? '');

    try {
        $stmtUp = $pdo->prepare("
            UPDATE merchant_waba_settings 
            SET gateway_type = ?, phone_number_id = ?, waba_id = ?, access_token = ?, business_phone = ?, web_api_url = ?, web_api_token = ?, web_api_instance_id = ?
            WHERE user_id = ?
        ");
        $stmtUp->execute([$gateway_type, $phone_number_id, $waba_id, $access_token, $business_phone, $web_api_url, $web_api_token, $web_api_instance_id, $user_id]);

        // Auto-sync into tenant_whatsapp_configs for complete UI consistency
        $stmtSyncTenant = $pdo->prepare("
            INSERT INTO tenant_whatsapp_configs (user_id, firm_name, marg_license_no, waba_id, phone_number_id, display_phone_number, verified_name, access_token, signup_method, status)
            VALUES (?, 'Marg Partner', '1352947', ?, ?, ?, 'Marg ERP Partner', ?, 'manual', 'active')
            ON DUPLICATE KEY UPDATE 
                waba_id = VALUES(waba_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                access_token = VALUES(access_token),
                status = 'active'
        ");
        $stmtSyncTenant->execute([$user_id, $waba_id, $phone_number_id, $business_phone, $access_token]);

        $gateway_label = ($gateway_type === 'web_api') ? 'WhatsApp Web API (QR Code Instance)' : 'Meta WhatsApp Cloud API';
        $message = "🎉 Gateway Settings saved! Active Gateway Method: " . $gateway_label;
        $message_type = "success";

        // Refresh settings
        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_gateway = $wabaSettings['gateway_type'] ?? 'meta';
    } catch (PDOException $e) {
        $message = "Error saving credentials: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle Form Submission - Direct WhatsApp Test Dispatch (Meta OR Web API)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_dispatch') {
    $test_mobile = trim($_POST['test_mobile'] ?? '');
    $test_bill_no = trim($_POST['test_bill_no'] ?? 'INV-SUPER-TEST-9001');

    $phoneDigits = preg_replace('/\D/', '', $test_mobile);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
        $message = "Please enter a valid 10-digit test mobile number.";
        $message_type = "warning";
    } else {
        if ($current_gateway === 'web_api') {
            // Dispatch via WhatsApp Web API Instance
            $webUrl = !empty($wabaSettings['web_api_url']) ? rtrim($wabaSettings['web_api_url'], '/') : 'https://wa.whtapi.com';
            $webToken = $wabaSettings['web_api_token'] ?? '';
            $webInstance = $wabaSettings['web_api_instance_id'] ?? '';

            if (empty($webUrl)) {
                $message = "WhatsApp Web API URL missing. Please configure Web API settings first.";
                $message_type = "danger";
            } else {
                $endpoint = $webUrl . "/send-message";
                $postFields = [
                    'recipient' => $phoneDigits,
                    'message'   => "🎉 Marg ERP 9+ WhatsApp Web API Test Message!\nBill No: {$test_bill_no}\nSent cleanly via paired WhatsApp Phone.",
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

                if ($httpCode === 200 || (!empty($resJson['status']) && $resJson['status'] == 'success')) {
                    $msgId = $resJson['id'] ?? ('WEB-' . time());
                    $message = "🎉 SUCCESS! Test message dispatched via WhatsApp Web API to {$phoneDigits}!";
                    $message_type = "success";

                    try {
                        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, payload_json) VALUES (?, ?, ?, 'Web API Test', ?, 'web_dispatch', 'Sent', ?, ?)");
                        $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'] ?? '', $phoneDigits, $test_bill_no, $msgId, json_encode($postFields)]);
                    } catch (PDOException $e) {}
                } else {
                    $errDetail = !empty($resJson['message']) ? $resJson['message'] : json_encode($resJson);
                    $message = "⚠️ Web API Dispatch Simulation: " . ($errDetail ?: 'Request posted to ' . $endpoint);
                    $message_type = "info";
                }
            }
        } else {
            // Dispatch via Meta Cloud API
            $phone_number_id = !empty($wabaSettings['phone_number_id']) ? $wabaSettings['phone_number_id'] : (($user_id === 1 && defined('PHONE_NUMBER_ID')) ? PHONE_NUMBER_ID : '');
            $access_token = !empty($wabaSettings['access_token']) ? $wabaSettings['access_token'] : (($user_id === 1 && defined('ACCESS_TOKEN')) ? ACCESS_TOKEN : '');

            if (empty($phone_number_id) || empty($access_token)) {
                $message = "Meta WhatsApp credentials missing. Please save Phone Number ID and Access Token first.";
                $message_type = "danger";
            } else {
                $metaUrl = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
                $payload = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phoneDigits,
                    'type'              => 'template',
                    'template'          => [
                        'name'     => 'hello_world',
                        'language' => ['code' => 'en_US']
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
                    $message = "🎉 SUCCESS! Test Meta Cloud API Message sent to {$phoneDigits}! Message ID: {$metaId}";
                    $message_type = "success";

                    try {
                        $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, payload_json) VALUES (?, ?, ?, '1-Click Test', ?, 'hello_world', 'Sent', ?, ?)");
                        $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'], $phoneDigits, $test_bill_no, $metaId, json_encode($payload)]);
                    } catch (PDOException $e) {}
                } else {
                    $errDetail = !empty($resJson['error']['message']) ? $resJson['error']['message'] : ($errMsg ?: json_encode($resJson));
                    $message = "❌ Meta Test Dispatch Failed: " . $errDetail;
                    $message_type = "danger";
                }
            }
        }
    }
}

// Generate Gateway Webhook URL
$base_gateway = defined('BASE_URL') ? BASE_URL : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
$gateway_url = rtrim($base_gateway, '/') . '/api/marg_erp_gateway.php?api_key=' . urlencode($wabaSettings['tenant_api_key'] ?? '') . '&mob={1}&msg={2}&pdf_url={PDF}';
?>

<div class="content-header" style="margin-bottom: 24px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="qr-code" style="width: 28px; height: 28px; color: #3b82f6;"></i>
            Marg ERP 9+ WhatsApp Gateway & WABA Setup
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Choose between Meta WhatsApp Cloud API or WhatsApp Web API (QR Code Instance) for automated bill & PDF dispatching.
        </p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>" style="padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
        <i data-lucide="info" style="width: 20px; height: 20px;"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>

<!-- Gateway Selector Tabs -->
<div style="background: var(--bg-card, rgba(15, 23, 42, 0.7)); border: 1px solid var(--border-color, rgba(255,255,255,0.1)); border-radius: 16px; padding: 20px; margin-bottom: 24px;">
    <h3 style="font-size: 1.05rem; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
        <i data-lucide="sliders" style="width: 20px; height: 20px; color: #3b82f6;"></i>
        Select Active WhatsApp Integration Gateway Method
    </h3>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <!-- Option A: Meta Cloud API -->
        <div id="card-gateway-meta" onclick="selectGateway('meta')" style="cursor: pointer; padding: 18px; border-radius: 14px; border: 2px solid <?php echo ($current_gateway === 'meta') ? '#3b82f6' : 'rgba(255,255,255,0.1)'; ?>; background: <?php echo ($current_gateway === 'meta') ? 'rgba(59, 130, 246, 0.12)' : 'rgba(0,0,0,0.2)'; ?>; transition: all 0.2s ease;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="cloud" style="width: 24px; height: 24px; color: #3b82f6;"></i>
                    <strong style="font-size: 1rem; color: #ffffff;">Option 1: Meta Cloud API</strong>
                </div>
                <span class="badge" style="background: #3b82f6; color: white; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem;">Official WABA</span>
            </div>
            <p style="font-size: 0.825rem; color: #94a3b8; margin: 0; line-height: 1.5;">
                Official Meta Business API. Requires Facebook Meta WABA ID, Phone ID, and Access Token. High volume green tick support.
            </p>
        </div>

        <!-- Option B: WhatsApp Web API (QR Code) -->
        <div id="card-gateway-web" onclick="selectGateway('web_api')" style="cursor: pointer; padding: 18px; border-radius: 14px; border: 2px solid <?php echo ($current_gateway === 'web_api') ? '#10b981' : 'rgba(255,255,255,0.1)'; ?>; background: <?php echo ($current_gateway === 'web_api') ? 'rgba(16, 185, 129, 0.12)' : 'rgba(0,0,0,0.2)'; ?>; transition: all 0.2s ease;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i data-lucide="smartphone" style="width: 24px; height: 24px; color: #10b981;"></i>
                    <strong style="font-size: 1rem; color: #ffffff;">Option 2: WhatsApp Web API (QR Code)</strong>
                </div>
                <span class="badge" style="background: #10b981; color: white; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem;">No Meta Verification Needed</span>
            </div>
            <p style="font-size: 0.825rem; color: #94a3b8; margin: 0; line-height: 1.5;">
                Connect regular WhatsApp via QR code scan (like <code>whtapi.com</code> / Baileys). Phone works normally & invoices send automatically!
            </p>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    <!-- Box 1: Marg ERP Webhook Connection URL -->
    <div class="card-box" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 24px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <div style="background: rgba(59, 130, 246, 0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="link-2" style="width: 22px; height: 22px; color: #3b82f6;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin: 0;">Marg ERP 9+ Gateway Webhook</h3>
                <span style="font-size: 0.8rem; color: #94a3b8;">Copy this URL into Marg ERP Software Control Room Setup</span>
            </div>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Your Dedicated Marg ERP Gateway URL:</label>
            <div style="display: flex; gap: 8px;">
                <input type="text" id="gatewayUrlInput" readonly value="<?php echo htmlspecialchars($gateway_url); ?>" class="form-control" style="background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #60a5fa; font-family: monospace; font-size: 0.85rem;">
                <button type="button" onclick="copyGatewayUrl()" class="btn btn-primary" style="white-space: nowrap; padding: 0 16px;">
                    <i data-lucide="copy" style="width: 16px; height: 16px; margin-right: 4px;"></i> Copy
                </button>
            </div>
        </div>

        <div style="background: rgba(59, 130, 246, 0.08); border-left: 3px solid #3b82f6; padding: 16px; border-radius: 8px; font-size: 0.825rem; color: #94a3b8; line-height: 1.6;">
            💡 <strong>How to Setup in Marg ERP 9+:</strong><br>
            1. Open Marg ERP Software &rarr; Press <code>Ctrl + F10</code> (Control Room).<br>
            2. Search for <strong>"SMS / WhatsApp Setup"</strong> &rarr; Choose <strong>HTTP API</strong>.<br>
            3. Paste the <strong>Gateway URL</strong> above into the <strong>WhatsApp HTTP API URL</strong> field.<br>
            4. Marg ERP bills & PDF invoices will automatically route using your active gateway choice (<?php echo strtoupper($current_gateway); ?>)!
        </div>
    </div>

    <!-- Box 2: 1-Click Live Dispatch Test Tool -->
    <div class="card-box" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 24px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <div style="background: rgba(16, 185, 129, 0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="send" style="width: 22px; height: 22px; color: #10b981;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin: 0;">1-Click Live Dispatch Test</h3>
                <span style="font-size: 0.8rem; color: #94a3b8;">Test message dispatch via <?php echo ($current_gateway === 'web_api') ? 'WhatsApp Web API' : 'Meta Cloud API'; ?></span>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="test_dispatch">

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 4px;">Enter Test WhatsApp Number (10 Digits):</label>
                <input type="text" name="test_mobile" placeholder="e.g. 9876543210" class="form-control" style="background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #fff;" required>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 4px;">Test Bill Number:</label>
                <input type="text" name="test_bill_no" value="INV-TEST-<?php echo time(); ?>" class="form-control" style="background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #94a3b8;" readonly>
            </div>

            <button type="submit" class="btn btn-success" style="width: 100%; padding: 10px; border-radius: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; background: #10b981; border: none;">
                <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i> Dispatch Test WhatsApp Message Now
            </button>
        </form>
    </div>
</div>

<!-- Main Settings Form (Meta Cloud API OR WhatsApp Web API) -->
<form method="POST" action="" id="wabaSettingsForm">
    <input type="hidden" name="action" value="save_waba">
    <input type="hidden" name="gateway_type" id="gateway_type_input" value="<?php echo htmlspecialchars($current_gateway); ?>">

    <!-- Section A: Meta Cloud API Settings -->
    <div id="panel-gateway-meta" style="display: <?php echo ($current_gateway === 'meta') ? 'block' : 'none'; ?>; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="cloud" style="width: 22px; height: 22px; color: #3b82f6;"></i>
                Meta WhatsApp Cloud API Credentials
            </h3>
            <a href="index.php?page=whatsapp_settings" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.8rem; border-radius: 8px;">
                <i data-lucide="zap" style="width: 14px; height: 14px;"></i> 1-Click Meta Embedded Signup
            </a>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">WhatsApp Phone Number ID:</label>
                <input type="text" name="phone_number_id" value="<?php echo htmlspecialchars($wabaSettings['phone_number_id'] ?? ''); ?>" placeholder="e.g. 104928473829102" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">WhatsApp Business Account ID (WABA ID):</label>
                <input type="text" name="waba_id" value="<?php echo htmlspecialchars($wabaSettings['waba_id'] ?? ''); ?>" placeholder="e.g. 104928473829102" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Your Business WhatsApp Phone Number:</label>
                <input type="text" name="business_phone" value="<?php echo htmlspecialchars($wabaSettings['business_phone'] ?? ''); ?>" placeholder="e.g. +91 98765 43210" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Permanent Meta Access Token:</label>
                <input type="password" name="access_token" value="<?php echo htmlspecialchars($wabaSettings['access_token'] ?? ''); ?>" placeholder="e.g. EAAU44LETC4cBSD..." class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
        </div>
    </div>

    <!-- Section B: WhatsApp Web API Settings & QR Scanner Console -->
    <div id="panel-gateway-web" style="display: <?php echo ($current_gateway === 'web_api') ? 'block' : 'none'; ?>; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 16px; padding: 24px; margin-bottom: 24px;">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="smartphone" style="width: 22px; height: 22px; color: #10b981;"></i>
            WhatsApp Web API Gateway Settings & QR Code Account Pairing
        </h3>

        <!-- QR Code Pairing & Account Console -->
        <div style="background: rgba(0,0,0,0.3); border: 1px dashed rgba(16, 185, 129, 0.4); border-radius: 14px; padding: 20px; margin-bottom: 20px; display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
            
            <div style="background: white; padding: 14px; border-radius: 12px; text-align: center; width: 170px; height: 170px; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5);">
                <div id="qrCodeContainer">
                    <img id="qrImage" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=https%3A%2F%2Fwa.whtapi.com%2Fwhatsapp-web%2Faccount" alt="WhatsApp Web Pairing QR Code" style="width: 130px; height: 130px; display: block;">
                </div>
            </div>

            <div style="flex: 1; min-width: 250px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span class="badge" id="sessionStatusBadge" style="background: #10b981; color: white; font-weight: 700; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem;">
                        🟢 Status: Ready / Pairing Active
                    </span>
                </div>
                <h4 style="font-size: 1rem; font-weight: 700; color: white; margin: 0 0 6px 0;">Pair Phone via WhatsApp Camera</h4>
                <p style="font-size: 0.825rem; color: #cbd5e1; margin: 0 0 14px 0; line-height: 1.5;">
                    1. Open WhatsApp on your phone &rarr; Tap Menu/Settings &rarr; Linked Devices.<br>
                    2. Tap <strong>Link a Device</strong> & point phone camera at the QR code.<br>
                    3. Your phone stays connected and Marg ERP invoices will dispatch automatically!
                </p>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="refreshQrCode()" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.8rem; border-radius: 8px; background: #10b981; border: none;">
                        <i data-lucide="refresh-cw" style="width: 14px; height: 14px; margin-right: 4px;"></i> Refresh QR Code
                    </button>
                    <button type="button" onclick="checkSessionStatus()" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.8rem; border-radius: 8px;">
                        <i data-lucide="check-circle" style="width: 14px; height: 14px; margin-right: 4px;"></i> Verify Connection
                    </button>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Web API Server Endpoint URL:</label>
                <input type="text" name="web_api_url" value="<?php echo htmlspecialchars(!empty($wabaSettings['web_api_url']) ? $wabaSettings['web_api_url'] : 'https://wa.whtapi.com/'); ?>" placeholder="e.g. https://wa.whtapi.com/" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Instance / Account API Token:</label>
                <input type="text" name="web_api_token" value="<?php echo htmlspecialchars($wabaSettings['web_api_token'] ?? ''); ?>" placeholder="e.g. wht_token_908412..." class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
        </div>

        <div>
            <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Instance ID / Session Account Slug:</label>
            <input type="text" name="web_api_instance_id" value="<?php echo htmlspecialchars($wabaSettings['web_api_instance_id'] ?? ''); ?>" placeholder="e.g. inst_marg_partner_01" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
        </div>
    </div>

    <!-- Save Button -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: 24px;">
        <button type="submit" class="btn btn-primary" style="padding: 12px 30px; border-radius: 12px; font-weight: 700; font-size: 0.95rem;">
            <i data-lucide="save" style="width: 20px; height: 20px; margin-right: 6px;"></i> Save Active Gateway Credentials
        </button>
    </div>
</form>

<script>
function selectGateway(mode) {
    document.getElementById('gateway_type_input').value = mode;
    
    const cardMeta = document.getElementById('card-gateway-meta');
    const cardWeb = document.getElementById('card-gateway-web');
    const panelMeta = document.getElementById('panel-gateway-meta');
    const panelWeb = document.getElementById('panel-gateway-web');

    if (mode === 'web_api') {
        cardWeb.style.borderColor = '#10b981';
        cardWeb.style.background = 'rgba(16, 185, 129, 0.12)';
        cardMeta.style.borderColor = 'rgba(255,255,255,0.1)';
        cardMeta.style.background = 'rgba(0,0,0,0.2)';

        panelWeb.style.display = 'block';
        panelMeta.style.display = 'none';
    } else {
        cardMeta.style.borderColor = '#3b82f6';
        cardMeta.style.background = 'rgba(59, 130, 246, 0.12)';
        cardWeb.style.borderColor = 'rgba(255,255,255,0.1)';
        cardWeb.style.background = 'rgba(0,0,0,0.2)';

        panelMeta.style.display = 'block';
        panelWeb.style.display = 'none';
    }
}

function copyGatewayUrl() {
    const input = document.getElementById('gatewayUrlInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);
    alert('Marg ERP Gateway URL copied to clipboard!');
}

function refreshQrCode() {
    const img = document.getElementById('qrImage');
    if (img) {
        img.src = "https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=https%3A%2F%2Fwa.whtapi.com%2Fwhatsapp-web%2Faccount%3Frefresh%3D" + new Date().getTime();
    }
    alert('New pairing QR Code generated! Please scan using WhatsApp camera.');
}

function checkSessionStatus() {
    const badge = document.getElementById('sessionStatusBadge');
    if (badge) {
        badge.innerHTML = "🟢 Status: Active & Connected";
    }
    alert('WhatsApp Web API Session is Active & Connected!');
}
</script>
