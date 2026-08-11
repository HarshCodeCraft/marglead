<?php
/**
 * Marg ERP CRM - API CORS & Response Helper with Strict Security Context
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Name, X-User-Role");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? $_POST;
}

function getAuthUserContext() {
    // Extract user info from custom HTTP headers or GET/POST parameters
    $user_name = $_SERVER['HTTP_X_USER_NAME'] ?? $_GET['user_name'] ?? $_POST['user_name'] ?? '';
    $user_role = $_SERVER['HTTP_X_USER_ROLE'] ?? $_GET['user_role'] ?? $_POST['user_role'] ?? '';
    
    $user_name = trim($user_name);
    $user_role = trim($user_role);

    $isAdmin = false;
    if (!empty($user_role)) {
        $lr = strtolower($user_role);
        if (str_contains($lr, 'admin') || str_contains($lr, 'super') || str_contains($lr, 'leader') || str_contains($lr, 'manager')) {
            $isAdmin = true;
        }
    }

    return [
        'name' => $user_name,
        'role' => $user_role,
        'isAdmin' => $isAdmin
    ];
}
