<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

try {
    $auth = getAuthUserContext();
    $user_id = strval($auth['user_id'] ?? ($_SESSION['user_id'] ?? ''));
    $user_name = trim($auth['name'] ?? ($_SESSION['user_name'] ?? ''));
    $user_email = trim($_SESSION['user_email'] ?? '');
    $role = $auth['role'] ?? ($_SESSION['user_role'] ?? '');

    // Refresh role directly from users table to prevent stale session role
    if ($db_connected && $pdo && (!empty($user_id) || !empty($user_email) || !empty($user_name))) {
        try {
            $uStmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE (id = ? AND ? != '') OR (email = ? AND ? != '') OR (name = ? AND ? != '')");
            $uStmt->execute([$user_id, $user_id, $user_email, $user_email, $user_name, $user_name]);
            $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uData) {
                if (!empty($uData['role'])) {
                    $role = $uData['role'];
                    $_SESSION['user_role'] = $uData['role'];
                }
                if (!empty($uData['name'])) $user_name = $uData['name'];
                if (!empty($uData['email'])) $user_email = $uData['email'];
            }
        } catch (PDOException $e) {}
    }

    $lr = strtolower(trim($role));
    $is_admin = (
        in_array($lr, ['admin', 'super admin', 'superadmin', 'administrator', 'system admin']) ||
        str_contains($lr, 'admin') ||
        str_contains($lr, 'super') ||
        str_contains($lr, 'manager') ||
        in_array($user_id, ['1', '16', '19', '20']) ||
        (stripos($user_name, 'Sahil') !== false && stripos($user_name, 'Savita') !== false) ||
        (stripos($user_email, 'sahilsavita') !== false) ||
        (stripos($user_name, 'Deepak') !== false && stripos($user_name, 'Awasthi') !== false)
    );

    // Collect user identifiers for employees
    $user_idents = [];
    if (!empty($user_name)) $user_idents[] = $user_name;
    if (!empty($user_email)) $user_idents[] = $user_email;

    // Admin & Super Admin get full company metrics.
    // Employees get strictly their assigned leads metrics.
    $metrics = getLiveMetricCounts($pdo, $is_admin, $user_idents);

    sendJsonResponse([
        'success' => true,
        'is_admin' => $is_admin,
        'metrics' => $metrics
    ]);
} catch (Exception $e) {
    sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
