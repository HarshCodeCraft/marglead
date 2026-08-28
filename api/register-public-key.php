<?php
/**
 * Marg CRM - Register WhatsApp Flow Public Key with Meta Graph API
 * 
 * URL: https://friendlyaisolution.com/api/register-public-key.php
 * 
 * Performs POST to: https://graph.facebook.com/v20.0/{PHONE_NUMBER_ID}/whatsapp_business_encryption
 * sending 'business_public_key' from config/public_key.pem.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/whatsapp-api.php';

$pubKeyPath = __DIR__ . '/../config/public_key.pem';

if (!file_exists($pubKeyPath)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Public key file missing at config/public_key.pem']);
    exit;
}

$publicKeyPem = file_get_contents($pubKeyPath);

$phoneId = '1361533150369205';
$wabaId = '1363197648586760';
$flowId = '869669339414580';

$accessToken = trim($_GET['access_token'] ?? $_POST['access_token'] ?? $_GET['token'] ?? '');
if (empty($accessToken) && defined('ACCESS_TOKEN')) {
    $accessToken = ACCESS_TOKEN;
}

// Update DB record if needed
if (isset($pdo) && $pdo) {
    try {
        $pdo->exec("UPDATE merchant_waba_settings SET phone_number_id = '1361533150369205', waba_id = '1363197648586760', access_token = " . $pdo->quote($accessToken) . " WHERE user_id = 1 OR id = 1");
    } catch (Throwable $e) {}
}

// Execute Meta Graph API call
$graphVersion = defined('GRAPH_API_VERSION') ? GRAPH_API_VERSION : 'v20.0';

function sendPublicKeyToMeta($targetId, $publicKeyPem, $accessToken, $graphVersion) {
    $url = "https://graph.facebook.com/{$graphVersion}/{$targetId}/whatsapp_business_encryption";
    
    // Attempt 1: x-www-form-urlencoded
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['business_public_key' => $publicKeyPem]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/x-www-form-urlencoded"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resData = json_decode($response, true) ?? ['raw' => $response];
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'target_id' => $targetId, 'http_code' => $httpCode, 'response' => $resData];
    }

    // Attempt 2: JSON payload
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['business_public_key' => $publicKeyPem]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $responseJson = curl_exec($ch);
    $httpCodeJson = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resDataJson = json_decode($responseJson, true) ?? ['raw' => $responseJson];
    return [
        'success' => ($httpCodeJson >= 200 && $httpCodeJson < 300),
        'target_id' => $targetId,
        'http_code' => $httpCodeJson,
        'response' => $resDataJson
    ];
}

// Try Phone ID & Flow ID calls
$attempts = [];

// Method 1: POST /{FLOW_ID} (form-urlencoded public_key)
$ch = curl_init("https://graph.facebook.com/{$graphVersion}/{$flowId}");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['public_key' => $publicKeyPem]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}", "Content-Type: application/x-www-form-urlencoded"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$attempts[] = ['name' => "POST Flow ID Form ('public_key')", 'code' => $code1, 'res' => json_decode($res1, true) ?? $res1];

// Method 2: POST /{FLOW_ID} (JSON public_key)
$ch = curl_init("https://graph.facebook.com/{$graphVersion}/{$flowId}");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['public_key' => $publicKeyPem]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}", "Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$attempts[] = ['name' => "POST Flow ID JSON ('public_key')", 'code' => $code2, 'res' => json_decode($res2, true) ?? $res2];

// Method 3: POST /{PHONE_NUMBER_ID}/whatsapp_business_encryption (form)
$ch = curl_init("https://graph.facebook.com/{$graphVersion}/{$phoneId}/whatsapp_business_encryption");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['business_public_key' => $publicKeyPem]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}", "Content-Type: application/x-www-form-urlencoded"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res3 = curl_exec($ch);
$code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$attempts[] = ['name' => "POST Phone ID Encryption Form ('business_public_key')", 'code' => $code3, 'res' => json_decode($res3, true) ?? $res3];

// Method 4: POST /{PHONE_NUMBER_ID}/whatsapp_business_encryption (JSON)
$ch = curl_init("https://graph.facebook.com/{$graphVersion}/{$phoneId}/whatsapp_business_encryption");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['business_public_key' => $publicKeyPem]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}", "Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res4 = curl_exec($ch);
$code4 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$attempts[] = ['name' => "POST Phone ID Encryption JSON ('business_public_key')", 'code' => $code4, 'res' => json_decode($res4, true) ?? $res4];

$isSuccess = false;
$successfulAttempt = null;
foreach ($attempts as $att) {
    if ($att['code'] >= 200 && $att['code'] < 300) {
        $isSuccess = true;
        $successfulAttempt = $att;
        break;
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $isSuccess,
        'flow_id' => $flowId,
        'phone_number_id' => $phoneId,
        'attempts' => $attempts,
        'public_key' => $publicKeyPem
    ], JSON_PRETTY_PRINT);
    exit;
}

// Display HTML Output for browser
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WhatsApp Flow RSA Public Key Signing & Registration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem; line-height: 1.6; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2rem; max-width: 800px; margin: 0 auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        .success { color: #34d399; background: rgba(16, 185, 129, 0.1); border-left: 4px solid #10b981; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .error { color: #f87171; background: rgba(239, 68, 68, 0.1); border-left: 4px solid #ef4444; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        textarea { width: 100%; height: 160px; background: #090d16; border: 1px solid #34d399; color: #34d399; font-family: monospace; font-size: 0.85rem; padding: 1rem; border-radius: 8px; box-sizing: border-box; }
        .btn { background: #3b82f6; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🔐 Meta WhatsApp Flow RSA Public Key Registration</h2>
        
        <?php if ($isSuccess && $successfulAttempt): ?>
            <div class="success">
                <strong>🎉 SUCCESS! RSA Public Key registered with Meta Graph API!</strong><br>
                Successful Method: <code><?php echo htmlspecialchars($successfulAttempt['name']); ?></code><br>
                Meta Response: <code><?php echo htmlspecialchars(json_encode($successfulAttempt['res'])); ?></code>
            </div>
        <?php else: ?>
            <div class="error">
                <strong>⚠️ Meta API Graph Registration Attempts:</strong><br>
                <ul style="padding-left:20px; font-size:0.85rem;">
                <?php foreach ($attempts as $att): ?>
                    <li><strong><?php echo htmlspecialchars($att['name']); ?></strong> - Code: <?php echo $att['code']; ?><br>
                    <code style="font-size:0.75rem; color:#cbd5e1;"><?php echo htmlspecialchars(json_encode($att['res'])); ?></code></li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <h3>Copy Public Key PEM (For Manual Meta Manager Paste):</h3>
        <textarea id="pemBox" readonly><?php echo htmlspecialchars($publicKeyPem); ?></textarea>
        <button class="btn" onclick="navigator.clipboard.writeText(document.getElementById('pemBox').value); alert('Public Key PEM copied!');" style="margin-top:10px;">📋 Copy Public Key</button>
    </div>
</body>
</html>
