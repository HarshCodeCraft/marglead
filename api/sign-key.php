<?php
/**
 * WhatsApp Flow Public Key Signing Tool
 * Standard tool to sign Meta WhatsApp Flow challenge strings with private_key.pem
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');

$privateKeyPath = __DIR__ . '/../config/private_key.pem';
$publicKeyPath  = __DIR__ . '/../config/public_key.pem';

$publicKeyContent = file_exists($publicKeyPath) ? file_get_contents($publicKeyPath) : '';
$privateKeyContent = file_exists($privateKeyPath) ? file_get_contents($privateKeyPath) : '';

$challenge = trim($_POST['challenge'] ?? $_GET['challenge'] ?? '');
$signatureBase64 = '';
$error = '';

if (!empty($challenge)) {
    if (empty($privateKeyContent)) {
        $error = "Private key file missing at config/private_key.pem";
    } else {
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        if (!$privateKey) {
            $error = "Invalid Private Key file.";
        } else {
            $binarySignature = '';
            $success = openssl_sign($challenge, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256);
            if ($success) {
                $signatureBase64 = base64_encode($binarySignature);
            } else {
                $error = "OpenSSL failed to sign the challenge.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WhatsApp Flow Public Key Signer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6f8; padding: 30px; margin: 0; color: #333; }
        .card { background: white; border-radius: 12px; max-width: 700px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { color: #075e54; margin-top: 0; }
        textarea, input[type="text"] { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-family: monospace; margin-top: 6px; margin-bottom: 16px; font-size: 14px; }
        button { background: #25d366; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { background: #1ebd56; }
        .result { background: #e7fceb; border: 1px solid #25d366; padding: 15px; border-radius: 6px; word-break: break-all; font-family: monospace; margin-top: 15px; }
        .error { background: #fde8e8; border: 1px solid #f98080; color: #9b1c1c; padding: 15px; border-radius: 6px; margin-top: 15px; }
        .key-box { background: #f8f9fa; border: 1px solid #e9ecef; padding: 10px; font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; border-radius: 6px; max-height: 120px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="card">
        <h2>WhatsApp Flow Public Key & Signing Tool</h2>
        <p>Use this page to get your Public Key or generate a signature for Meta's <strong>Sign public key</strong> step.</p>

        <h3>1. Your Public Key (Copy & Paste to Meta):</h3>
        <div class="key-box"><?php echo htmlspecialchars($publicKeyContent); ?></div>

        <h3 style="margin-top: 25px;">2. Sign Meta Challenge String:</h3>
        <form method="POST">
            <label>Paste Challenge String from Meta:</label>
            <input type="text" name="challenge" placeholder="e.g. 1722700000 or challenge payload string" value="<?php echo htmlspecialchars($challenge); ?>" required>
            <button type="submit">Generate Signature</button>
        </form>

        <?php if (!empty($signatureBase64)): ?>
            <div class="result">
                <strong>Base64 Signature:</strong><br>
                <span><?php echo htmlspecialchars($signatureBase64); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
