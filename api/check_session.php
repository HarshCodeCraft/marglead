<?php
/**
 * Marg ERP CRM - Live Session Heartbeat & Concurrent Login Checker
 * Returns active status or triggers termination if another device logged in.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'authenticated' => false,
        'status' => 'session_expired',
        'message' => 'Session expired. Please sign in again.',
        'redirect' => 'auth/login.php?reason=session_expired'
    ]);
    exit;
}

// Check database active_session_token against current session
$userId = $_SESSION['user_id'];
$sessionRole = $_SESSION['login_role'] ?? $_SESSION['user_role'] ?? '';
$loginSource = $_SESSION['login_source'] ?? '';
$currentSessionToken = $_SESSION['active_session_token'] ?? '';

if ($db_connected && $pdo && !empty($currentSessionToken)) {
    try {
        $row = null;
        if ($loginSource === 'tenant_companies') {
            $stmt = $pdo->prepare("SELECT active_session_token, status FROM tenant_companies WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row) {
            $stmt = $pdo->prepare("SELECT active_session_token, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            $dbToken = $row['active_session_token'] ?? '';
            if (!empty($dbToken) && $dbToken !== $currentSessionToken) {
                // Logged in from another device!
                $_SESSION = array();
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                http_response_code(401);
                echo json_encode([
                    'authenticated' => false,
                    'status' => 'session_terminated',
                    'reason' => 'concurrent_login',
                    'message' => 'Your account was logged in from another device or browser.',
                    'redirect' => 'auth/login.php?reason=concurrent_login'
                ]);
                exit;
            }
        }
    } catch (\Throwable $ex) {}
}

echo json_encode([
    'authenticated' => true,
    'status' => 'active',
    'expires_in' => max(0, 18000 - (time() - ($_SESSION['last_activity'] ?? time()))),
    'user' => [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? ''
    ]
]);
