<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

try {
    $auth = getAuthUserContext();
    $user_name = $auth['name'] ?? ($_SESSION['user_name'] ?? '');
    $r = strtolower($_SESSION['user_role'] ?? '');
    $is_admin = $auth['isAdmin'] ?? ($r === 'admin' || $r === 'super admin' || str_contains($r, 'admin') || str_contains($r, 'super') || str_contains($r, 'manager'));

    $metrics = getLiveMetricCounts($pdo, $is_admin, $user_name);

    sendJsonResponse([
        'success' => true,
        'metrics' => $metrics
    ]);
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
