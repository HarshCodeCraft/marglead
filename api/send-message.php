<?php
/**
 * Marg CRM - API Endpoint to Send WhatsApp Message
 * 
 * Supports sending standard text or interactive reply buttons.
 * Access via POST request.
 */

require_once __DIR__ . '/whatsapp-api.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed. Use POST.', null, 405);
}

// Read raw JSON or POST data
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$phone = $input['phone'] ?? $input['mobile'] ?? null;
$message = $input['message'] ?? $input['text'] ?? null;
$type = $input['type'] ?? 'text'; // 'text' or 'buttons'

if (empty($phone)) {
    json_response(false, 'Missing required parameter: phone', null, 400);
}

$whatsapp = new WhatsAppAPI($pdo);

if ($type === 'buttons') {
    $bodyText = $message ?: 'Please choose an option below:';
    $buttons = $input['buttons'] ?? [
        ['id' => 'btn_sales', 'title' => 'Sales'],
        ['id' => 'btn_support', 'title' => 'Support']
    ];
    $header = $input['header'] ?? 'Welcome to ABC Software';
    $footer = $input['footer'] ?? 'Select an option';

    $result = $whatsapp->sendReplyButtons($phone, $bodyText, $buttons, $header, $footer);
} else {
    if (empty($message)) {
        json_response(false, 'Missing required parameter: message', null, 400);
    }
    $result = $whatsapp->sendText($phone, $message);
}

if ($result['success']) {
    json_response(true, 'WhatsApp message sent successfully.', $result['response']);
} else {
    json_response(false, 'Failed to send WhatsApp message.', $result, 500);
}
