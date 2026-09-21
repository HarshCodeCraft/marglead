<?php
/**
 * Marg CRM - WhatsApp AI Sales Simulator API Endpoint
 * Dedicated AJAX endpoint for live testing AI Sales interactions in Admin Settings
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cors.php';
$auth = requireApiAuth();

require_once __DIR__ . '/../includes/ai_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$input = getJsonInput();
$testMsg = trim($input['test_message'] ?? $_POST['test_message'] ?? '');

if (empty($testMsg)) {
    sendJsonResponse(['success' => false, 'message' => 'Please type a test message'], 400);
}

$simContext = [
    'customer_name' => 'Demo Customer',
    'firm_name'     => 'Kalyan Medicos',
    'city'          => 'Kanpur',
    'phone'         => '+91 9876543210'
];

try {
    $res = callAIService([], $testMsg, $simContext, $pdo);
    sendJsonResponse($res);
} catch (Throwable $e) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
