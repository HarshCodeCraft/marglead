<?php
/**
 * Marg ERP CRM - API CORS & Security Middleware
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY, X-User-Name, X-User-Role");
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

/**
 * Enforce strict API authentication: Requires active CRM Session OR valid X-API-KEY header
 */
function requireApiAuth() {
    // 1. Check if user is logged into CRM Session
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_name'])) {
        $role = $_SESSION['user_role'] ?? 'User';
        $lr = strtolower($role);
        $isAdmin = (str_contains($lr, 'admin') || str_contains($lr, 'super'));
        return [
            'user_id' => $_SESSION['user_id'],
            'name'    => $_SESSION['user_name'],
            'role'    => $role,
            'isAdmin' => $isAdmin
        ];
    }

    // 2. Check for Secret API Key in Request Headers
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    
    $definedKey = getenv('MARG_API_KEY') ?: 'MARG_CRM_SECURE_API_KEY_2026';
    
    if (!empty($apiKey) && hash_equals($definedKey, (string)$apiKey)) {
        return [
            'user_id' => 0,
            'name'    => 'API System',
            'role'    => 'Super Admin',
            'isAdmin' => true
        ];
    }

    // 3. Fallback check for session role/name if present
    if (isset($_SESSION['user_role'])) {
        $role = $_SESSION['user_role'];
        $name = $_SESSION['user_name'] ?? 'Session User';
        $lr = strtolower($role);
        return [
            'user_id' => $_SESSION['user_id'] ?? 0,
            'name'    => $name,
            'role'    => $role,
            'isAdmin' => (str_contains($lr, 'admin') || str_contains($lr, 'super'))
        ];
    }

    // 4. Access Denied: Render 404 UI only for direct browser hits on index.php, JSON for all API endpoint calls
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || str_contains($acceptHeader, 'application/json');
    $scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

    if (str_contains($acceptHeader, 'text/html') && !$isAjax && $scriptName === 'index.php') {
        require_once __DIR__ . '/../404.php';
        exit;
    }

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 401,
        'message' => 'Unauthorized: Direct API access forbidden without active login session or valid X-API-KEY header.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getAuthUserContext() {
    return requireApiAuth();
}
