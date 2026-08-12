<?php
/**
 * Marg CRM - API Endpoint to Trigger/Send WhatsApp Flow
 * 
 * Sends a WhatsApp Flow interactive message to a specific customer mobile.
 * Access via POST request.
 */

require_once __DIR__ . '/whatsapp-api.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed. Use POST.', null, 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$phone    = $input['phone'] ?? $input['mobile'] ?? null;
$flowId   = $input['flow_id'] ?? FLOW_ID;
$ctaText  = $input['cta_text'] ?? 'Create Ticket';
$bodyText = $input['body_text'] ?? "Provide info and problem here";
$screen   = $input['screen'] ?? 'WELCOME_SCREEN';
$header   = $input['header'] ?? 'Marg Help soft solution';
$footer   = $input['footer'] ?? 'Support Desk';

$mode     = $input['mode'] ?? 'published';

if (empty($phone)) {
    json_response(false, 'Missing required parameter: phone', null, 400);
}

$whatsapp = new WhatsAppAPI($pdo);

$dataPayload = [
    'init_time' => date('Y-m-d H:i:s')
];

$result = $whatsapp->sendFlow($phone, $flowId, $ctaText, $bodyText, $screen, $dataPayload, $header, $footer, $mode);

if ($result['success']) {
    json_response(true, 'WhatsApp Flow sent successfully.', $result['response']);
} else {
    json_response(false, 'Failed to send WhatsApp Flow.', $result, 500);
}
