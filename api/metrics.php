<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

try {
    $auth = getAuthUserContext();
    $user_name = $auth['name'] ?? ($_SESSION['user_name'] ?? '');
    $is_admin = $auth['isAdmin'] ?? (($_SESSION['user_role'] ?? '') === 'Admin' || ($_SESSION['user_role'] ?? '') === 'Super Admin');

    $metrics = getLiveMetricCounts($pdo, $is_admin, $user_name);

    sendJsonResponse([
        'success' => true,
        'metrics' => $metrics
    ]);
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
