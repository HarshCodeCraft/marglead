<?php
/**
 * Marg ERP CRM - API Directory Access Guard
 * Displays custom 404 UI page for browser access or JSON error for API clients
 */
$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($acceptHeader, 'text/html') || empty($acceptHeader)) {
    require_once __DIR__ . '/../404.php';
    exit;
}

http_response_code(403);
header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    'success' => false,
    'error'   => 403,
    'message' => 'Access Denied: Direct API directory listing is strictly forbidden.'
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
