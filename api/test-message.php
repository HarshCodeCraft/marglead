<?php
/**
 * WhatsApp Message & Flow Tester (For Real Phone Number ID)
 * Allows testing Text Messages, Interactive Reply Buttons, and WhatsApp Flows
 */

require_once __DIR__ . '/whatsapp-api.php';

header('Content-Type: text/html; charset=utf-8');

$phone = trim($_POST['phone'] ?? $_GET['phone'] ?? '');
$msgType = trim($_POST['msg_type'] ?? $_GET['msg_type'] ?? 'buttons');
$customText = trim($_POST['custom_text'] ?? 'Welcome to Marg Soft Solution Support! How can we help you?');

$response = null;

if (!empty($phone)) {
    $whatsapp = new WhatsAppAPI($pdo);
    
    if ($msgType === 'text') {
        $response = $whatsapp->sendText($phone, $customText);
    } elseif ($msgType === 'flow') {
        $flowId = FLOW_ID;
        $response = $whatsapp->sendFlow($phone, $flowId, "Create Ticket", "Provide info and problem here", 'WELCOME_SCREEN', null, "Marg Help soft solution", "Managed by Marg soft solution.");
    } else {
        // Buttons
        $buttons = [
            ['id' => 'btn_sales', 'title' => 'Sales'],
            ['id' => 'btn_support', 'title' => 'Support']
        ];
        $headerImage = "https://datapartner.btpr.online/ProductPictures/20851800671_download(4).png";
        $response = $whatsapp->sendReplyButtons($phone, $customText, $buttons, "Marg Soft Solution", "Please select an option", $headerImage);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WhatsApp Direct Message Tester</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f4f6f8; padding: 30px; margin: 0; color: #333; }
        .card { background: white; border-radius: 12px; max-width: 650px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { color: #075e54; margin-top: 0; }
        input[type="text"], select, textarea { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #ccc; border-radius: 6px; font-family: sans-serif; margin-top: 6px; margin-bottom: 16px; font-size: 14px; }
        button { background: #25d366; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { background: #1ebd56; }
        .result { background: #111827; color: #10b981; padding: 15px; border-radius: 6px; font-family: monospace; font-size: 13px; white-space: pre-wrap; margin-top: 20px; overflow-x: auto; }
        .info-box { background: #e8f4fd; border-left: 4px solid #3b82f6; padding: 12px; font-size: 13px; color: #1e3a8a; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>WhatsApp Live Message & Flow Tester</h2>
        <div class="info-box">
            Connected Phone Number ID: <strong><?php echo htmlspecialchars(PHONE_NUMBER_ID); ?></strong><br>
            Use this tool to test sending live <strong>Text Messages</strong>, <strong>Interactive Reply Buttons</strong>, or <strong>WhatsApp Flows</strong> directly to your phone.
        </div>

        <form method="POST">
            <label>Recipient WhatsApp Number (with Country Code):</label>
            <input type="text" name="phone" placeholder="e.g. 917860510928" value="<?php echo htmlspecialchars($phone); ?>" required>

            <label>Select Message Type:</label>
            <select name="msg_type">
                <option value="buttons" <?php echo $msgType === 'buttons' ? 'selected' : ''; ?>>Interactive Reply Buttons (Sales / Support + Image Header)</option>
                <option value="flow" <?php echo $msgType === 'flow' ? 'selected' : ''; ?>>WhatsApp Flow Form (Support Form)</option>
                <option value="text" <?php echo $msgType === 'text' ? 'selected' : ''; ?>>Plain Text Message</option>
            </select>

            <label>Message Content / Body:</label>
            <textarea name="custom_text" rows="3"><?php echo htmlspecialchars($customText); ?></textarea>

            <button type="submit">Send Test Message Now</button>
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
