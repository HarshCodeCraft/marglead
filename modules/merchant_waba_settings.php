<?php
/**
 * Marg ERP CRM - Add-On: Merchant WABA Setup & Marg ERP Gateway Settings
 * Allows merchants & Super Admin to configure Meta WhatsApp Cloud API credentials
 * and copy their unique Marg ERP 9+ Webhook Gateway URL.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../config/config.php';

$user_id = $_SESSION['user_id'] ?? 1;
$message = '';
$message_type = '';

// Auto-generate or fetch settings for this user (NO auto-filling Super Admin tokens)
try {
    $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wabaSettings) {
        // Generate new Tenant API Key with BLANK WABA credentials
        $newApiKey = 'MARG-WABA-' . strtoupper(bin2hex(random_bytes(8)));
        $newVerifyToken = bin2hex(random_bytes(16));

        $stmtIns = $pdo->prepare("INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, tenant_api_key, webhook_verify_token) VALUES (?, '', '', '', ?, ?)");
        $stmtIns->execute([$user_id, $newApiKey, $newVerifyToken]);

        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $wabaSettings = [];
}

// Handle Form Submission - Credentials Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_waba') {
    $phone_number_id = trim($_POST['phone_number_id'] ?? '');
    $waba_id = trim($_POST['waba_id'] ?? '');
    $access_token = trim($_POST['access_token'] ?? '');
    $business_phone = trim($_POST['business_phone'] ?? '');

    try {
        $stmtUp = $pdo->prepare("
            UPDATE merchant_waba_settings 
            SET phone_number_id = ?, waba_id = ?, access_token = ?, business_phone = ? 
            WHERE user_id = ?
        ");
        $stmtUp->execute([$phone_number_id, $waba_id, $access_token, $business_phone, $user_id]);

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

        $message = "WhatsApp Cloud API credentials saved successfully & synced across all modules!";
        $message_type = "success";

        // Refresh settings
        $stmt->execute([$user_id]);
        $wabaSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = "Error saving credentials: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle Form Submission - Direct Meta WABA Test Message Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_dispatch') {
    $test_mobile = trim($_POST['test_mobile'] ?? '');
    $test_bill_no = trim($_POST['test_bill_no'] ?? 'INV-SUPER-TEST-9001');

    $phoneDigits = preg_replace('/\D/', '', $test_mobile);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
        $message = "Please enter a valid 10-digit test mobile number.";
        $message_type = "warning";
    } else {
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
                $message = "🎉 SUCCESS! Test WhatsApp Message sent successfully to {$phoneDigits}! Meta Message ID: {$metaId}";
                $message_type = "success";

                // Log in DB
                try {
                    $stmtLog = $pdo->prepare("INSERT INTO marg_erp_logs (user_id, tenant_api_key, recipient_phone, event_type, bill_number, template_name, status, meta_message_id, payload_json) VALUES (?, ?, ?, '1-Click Test', ?, 'hello_world', 'Sent', ?, ?)");
                    $stmtLog->execute([$user_id, $wabaSettings['tenant_api_key'], $phoneDigits, $test_bill_no, $metaId, json_encode($payload)]);
                } catch (PDOException $e) {}
            } else {
                $errDetail = !empty($resJson['error']['message']) ? $resJson['error']['message'] : ($errMsg ?: json_encode($resJson));
                $message = "❌ Test Dispatch Failed: " . $errDetail;
                $message_type = "danger";
            }
        }
    }
}

// Generate Full Gateway Webhook URL with Marg ERP parameters using dynamic BASE_URL
$base_gateway = defined('BASE_URL') ? BASE_URL : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
$gateway_url = rtrim($base_gateway, '/') . '/api/marg_erp_gateway.php?api_key=' . urlencode($wabaSettings['tenant_api_key'] ?? '') . '&mob={1}&msg={2}&pdf_url={PDF}';
?>

<div class="content-header" style="margin-bottom: 24px;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
            <i data-lucide="qr-code" style="width: 28px; height: 28px; color: #3b82f6; vertical-align: middle; margin-right: 8px;"></i>
            Marg ERP 9+ WhatsApp Gateway & WABA Setup
        </h1>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Super Admin Test Configuration & Marg ERP 9+ Control Room Gateway Integration.
        </p>
    </div>
</div>

<!-- Meta Embedded Signup Banner -->
<div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(16, 185, 129, 0.15)); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 16px; padding: 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 16px;">
        <div style="background: #3b82f6; width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);">
            <i data-lucide="zap" style="width: 26px; height: 26px; color: white;"></i>
        </div>
        <div>
            <h3 style="font-size: 1.15rem; font-weight: 700; color: white; margin: 0 0 4px 0;">Automated 1-Click Meta Embedded Signup</h3>
            <p style="font-size: 0.85rem; color: #cbd5e1; margin: 0;">Connect your official WhatsApp Business Account instantly without creating a Meta App.</p>
        </div>
    </div>
    <a href="index.php?page=whatsapp_settings" class="btn btn-primary" style="padding: 10px 20px; border-radius: 10px; font-weight: 700; background: #3b82f6; border: none; text-decoration: none; color: white; display: inline-flex; align-items: center; gap: 8px;">
        <i data-lucide="qr-code" style="width: 18px; height: 18px;"></i>
        <span>Connect WhatsApp with Meta Now</span>
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>" style="padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    
    <!-- Box 1: Marg ERP 9+ Connection Webhook URL -->
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
            4. Paste the <strong>Recommended Message Format</strong> below into Marg SMS Format String field:<br><br>
            <div style="background: rgba(0,0,0,0.5); padding: 10px; border-radius: 6px; font-family: monospace; color: #38bdf8; white-space: pre-wrap; user-select: all;">Dear Sir
Type:Sale No:{BILL_NO} Date:{DATE} Amount:{AMOUNT} Balance:{BALANCE}
Name {CUSTOMER}
UPI ID : {UPI_ID}
Bank Name : {BANK_NAME}
Account No : {ACCOUNT_NO}
Branch : {BRANCH}
IFSC Code : {IFSC_CODE}
For {FIRM}</div>
        </div>
    </div>

    <!-- Box 2: 1-Click Super Admin Instant Test Tool -->
    <div class="card-box" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 24px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <div style="background: rgba(16, 185, 129, 0.15); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="send" style="width: 22px; height: 22px; color: #10b981;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin: 0;">1-Click Super Admin Live Dispatch Test</h3>
                <span style="font-size: 0.8rem; color: #94a3b8;">Test WhatsApp Cloud API message dispatch immediately</span>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="test_dispatch">

            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 4px;">Enter Test WhatsApp Number (10 Digits):</label>
                <input type="text" name="test_mobile" placeholder="e.g. 7860510928" class="form-control" style="background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #fff;" required>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-size: 0.8rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 4px;">Test Bill Number:</label>
                <input type="text" name="test_bill_no" value="INV-SUPER-TEST-9001" class="form-control" style="background: rgba(0,0,0,0.4); border-color: rgba(255,255,255,0.15); color: #94a3b8;" readonly>
            </div>

            <button type="submit" class="btn btn-success" style="width: 100%; padding: 10px; border-radius: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; background: #10b981; border: none;">
                <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i> Send Test WhatsApp Message Now
            </button>
        </form>
    </div>
</div>

<!-- Meta Cloud API Credentials Form (Strictly Example Placeholders) -->
<div class="card-box" style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 24px;">
    <h3 style="font-size: 1.1rem; font-weight: 700; color: #ffffff; margin-bottom: 16px;">
        <i data-lucide="settings" style="width: 20px; height: 20px; color: #06b6d4; vertical-align: middle; margin-right: 6px;"></i>
        Merchant Meta WhatsApp Cloud API Credentials
    </h3>

    <form method="POST" action="">
        <input type="hidden" name="action" value="save_waba">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">WhatsApp Phone Number ID:</label>
                <input type="text" name="phone_number_id" value="<?php echo htmlspecialchars($wabaSettings['phone_number_id'] ?? ''); ?>" placeholder="e.g. 104928473829102" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;" required>
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">WhatsApp Business Account ID (WABA ID):</label>
                <input type="text" name="waba_id" value="<?php echo htmlspecialchars($wabaSettings['waba_id'] ?? ''); ?>" placeholder="e.g. 104928473829102" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Your Business WhatsApp Phone Number:</label>
                <input type="text" name="business_phone" value="<?php echo htmlspecialchars($wabaSettings['business_phone'] ?? ''); ?>" placeholder="e.g. +91 98765 43210" class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;">
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 6px;">Permanent Meta Access Token:</label>
                <input type="password" name="access_token" value="<?php echo htmlspecialchars($wabaSettings['access_token'] ?? ''); ?>" placeholder="e.g. EAAU44LETC4cBSD..." class="form-control" style="background: rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.12); color: #fff;" required>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 10px; font-weight: 600;">
                <i data-lucide="save" style="width: 18px; height: 18px; margin-right: 6px;"></i> Save Cloud API Credentials
            </button>
        </div>
    </form>
</div>

<script>
function copyGatewayUrl() {
    const input = document.getElementById('gatewayUrlInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);
    alert('Marg ERP Gateway URL copied to clipboard!');
}
</script>
