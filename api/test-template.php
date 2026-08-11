<?php
/**
 * WhatsApp Template Message Testing Utility
 * Test Meta Pre-approved Message Templates like 'hello_world'
 */

require_once __DIR__ . '/whatsapp-api.php';

header('Content-Type: text/html; charset=utf-8');

$phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
$template = trim($_POST['template'] ?? $_GET['template'] ?? 'hello_world');
$lang = trim($_POST['lang'] ?? $_GET['lang'] ?? 'en_US');

$response = null;

if (!empty($phone)) {
    $whatsapp = new WhatsAppAPI($pdo);
    $response = $whatsapp->sendTemplate($phone, $template, $lang);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WhatsApp Message Template Tester</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6f8; padding: 30px; margin: 0; color: #333; }
        .card { background: white; border-radius: 12px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { color: #075e54; margin-top: 0; }
        input[type="text"], select { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-family: monospace; margin-top: 6px; margin-bottom: 16px; font-size: 14px; }
        button { background: #25d366; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { background: #1ebd56; }
        .result { background: #111827; color: #10b981; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; white-space: pre-wrap; margin-top: 20px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="card">
        <h2>WhatsApp Template Message Tester</h2>
        <p>Use this tool to test Meta Pre-approved Message Templates (e.g. <code>hello_world</code>).</p>

        <form method="POST">
            <label>Recipient WhatsApp Number (with Country Code):</label>
            <input type="text" name="phone" placeholder="e.g. 919876543210" value="<?php echo htmlspecialchars($phone); ?>" required>

            <label>Template Name:</label>
            <input type="text" name="template" placeholder="e.g. hello_world" value="<?php echo htmlspecialchars($template); ?>" required>

            <label>Language Code:</label>
            <select name="lang">
                <option value="en_US" <?php echo $lang === 'en_US' ? 'selected' : ''; ?>>en_US (English US)</option>
                <option value="en" <?php echo $lang === 'en' ? 'selected' : ''; ?>>en (English)</option>
                <option value="hi" <?php echo $lang === 'hi' ? 'selected' : ''; ?>>hi (Hindi)</option>
            </select>

            <button type="submit">Send Template Message</button>
        </form>

        <?php if ($response !== null): ?>
            <div class="result">
                <strong>Meta API Response:</strong><br>
                <?php echo htmlspecialchars(json_encode($response, JSON_PRETTY_PRINT)); ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
