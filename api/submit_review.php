<?php
/**
 * Marg ERP CRM - Public Customer Review & Rating Submission API
 */

require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$input = getJsonInput();

$name = trim($input['name'] ?? '');
$company = trim($input['company'] ?? '');
$city = trim($input['city'] ?? '');
$rating = floatval($input['rating'] ?? 5.0);
$review_text = trim($input['review_text'] ?? '');
$service_name = trim($input['service_name'] ?? 'Marg ERP 9+');

if (empty($name) || empty($review_text)) {
    sendJsonResponse(['success' => false, 'message' => 'Name and review details are required.'], 400);
}

if ($rating < 1.0) $rating = 1.0;
if ($rating > 5.0) $rating = 5.0;

try {
    $stmt = $pdo->prepare("INSERT INTO customer_reviews (name, company, city, rating, review_text, service_name, source, status) VALUES (?, ?, ?, ?, ?, ?, 'Website Submission', 'Approved')");
    $stmt->execute([$name, $company, $city, $rating, $review_text, $service_name]);

    sendJsonResponse([
        'success' => true,
        'message' => 'Thank you for your rating & review! It has been posted successfully.'
    ]);
} catch (PDOException $e) {
    sendJsonResponse(['success' => false, 'message' => 'Failed to submit review: ' . $e->getMessage()], 500);
}
