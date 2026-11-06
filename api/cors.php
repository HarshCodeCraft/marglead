<?php
/**
 * Marg ERP CRM - API CORS, Mobile Bearer Token & Security Middleware
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Dynamic & Secure CORS Origin Validation
$httpOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://friendlyaisolution.com',
    'https://www.friendlyaisolution.com',
    'http://localhost',
    'http://127.0.0.1',
    'capacitor://localhost',
    'ionic://localhost'
];

if (!empty($httpOrigin)) {
    $parsedHost = parse_url($httpOrigin, PHP_URL_HOST) ?? '';
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    if (in_array(rtrim($httpOrigin, '/'), $allowedOrigins, true) || 
        (!empty($serverHost) && strpos($serverHost, $parsedHost) !== false) ||
        (strpos($httpOrigin, 'capacitor://') === 0) ||
        (strpos($httpOrigin, 'ionic://') === 0)) {
        if (!headers_sent()) {
            header("Access-Control-Allow-Origin: {$httpOrigin}");
            header("Access-Control-Allow-Credentials: true");
        }
    }
} else {
    // Permitted for native mobile apps (Flutter, Kotlin, Swift do not send an Origin header)
    if (!headers_sent()) {
        header("Access-Control-Allow-Origin: *");
    }
}

if (!headers_sent()) {
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY, X-User-Name, X-User-Role, X-Mobile-Token, X-Device-ID, X-Device-Name");
    header("Content-Type: application/json; charset=UTF-8");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

/**
 * Ensure mobile app token persistence table exists
 */
function ensure_mobile_token_table(PDO $pdo) {
    static $provisioned = false;
    if ($provisioned) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `mobile_app_tokens` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 0,
            `user_id` INT NOT NULL,
            `user_type` ENUM('user', 'tenant') DEFAULT 'user',
            `token_hash` CHAR(64) NOT NULL UNIQUE,
            `device_name` VARCHAR(150) NULL,
            `device_model` VARCHAR(100) NULL,
            `device_os` VARCHAR(50) NULL,
            `device_id` VARCHAR(150) NULL,
            `ip_address` VARCHAR(45) NULL,
            `is_revoked` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NULL,
            INDEX idx_token_hash (`token_hash`),
            INDEX idx_user_tenant (`user_id`, `tenant_id`),
            INDEX idx_revoked_expiry (`is_revoked`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $provisioned = true;
    } catch (\Throwable $e) {
        error_log("Failed to create mobile_app_tokens: " . $e->getMessage());
    }
}

function sendJsonResponse($data, $statusCode = 200) {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getJsonInput() {
    $input = file_get_contents('php://input');
    $decoded = json_decode($input, true);
    return is_array($decoded) ? $decoded : $_POST;
}

/**
 * Enforce strict API authentication:
 * Supports Mobile Bearer Token, Active CRM Session, or X-API-KEY header
 */
function requireApiAuth() {
    global $pdo, $pdo_master;
    $db = $pdo ?? $pdo_master;

    // 0. Allow CLI / Cron execution without blocking
    if (php_sapi_name() === 'cli' || (defined('IS_CRON_EXECUTION') && IS_CRON_EXECUTION)) {
        return [
            'user_id'   => 1,
            'tenant_id' => 0,
            'name'      => 'Cron System',
            'role'      => 'Super Admin',
            'isAdmin'   => true
        ];
    }

    // 1. Mobile App Bearer Token / X-Mobile-Token Authentication
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    
    $bearerToken = '';
    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $bearerToken = trim($matches[1]);
    }
    if (empty($bearerToken)) {
        $bearerToken = $headers['X-Mobile-Token'] ?? $headers['x-mobile-token'] ?? $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? $_GET['mobile_token'] ?? $_POST['mobile_token'] ?? '';
    }

    if (!empty($bearerToken)) {
        if ($db) {
            ensure_mobile_token_table($db);
            $tokenHash = hash('sha256', $bearerToken);
            $stmt = $db->prepare("SELECT * FROM mobile_app_tokens WHERE token_hash = ? AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
            $stmt->execute([$tokenHash]);
            $tokenRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tokenRecord) {
                $userId = (int)$tokenRecord['user_id'];
                $tenantId = (int)$tokenRecord['tenant_id'];
                $userType = $tokenRecord['user_type'] ?? 'user';

                $userData = null;
                if ($userType === 'tenant') {
                    $uStmt = $db->prepare("SELECT id, owner_name as name, owner_email as email, status, company_name FROM tenant_companies WHERE id = ? LIMIT 1");
                    $uStmt->execute([$userId]);
                    $userData = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($userData) {
                        $userData['role'] = 'Tenant Admin';
                    }
                } else {
                    $uStmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1");
                    $uStmt->execute([$userId]);
                    $userData = $uStmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($userData && in_array($userData['status'], ['Active', 'Approved'])) {
                    // Update heartbeat
                    try {
                        $upd = $db->prepare("UPDATE mobile_app_tokens SET last_used_at = NOW(), ip_address = ? WHERE id = ?");
                        $upd->execute([$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $tokenRecord['id']]);
                    } catch (\Throwable $e) {}

                    $role = $userData['role'] ?? 'Employee';
                    $lr = strtolower($role);
                    $isAdmin = (str_contains($lr, 'admin') || str_contains($lr, 'super'));

                    // Populate session context for downstream compatibility
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['tenant_id'] = $tenantId;
                    $_SESSION['user_name'] = $userData['name'];
                    $_SESSION['user_role'] = $role;

                    return [
                        'user_id'   => $userId,
                        'tenant_id' => $tenantId,
                        'name'      => $userData['name'],
                        'email'     => $userData['email'],
                        'role'      => $role,
                        'isAdmin'   => $isAdmin,
                        'user_type' => $userType,
                        'auth_type' => 'bearer_token'
                    ];
                } elseif ($userData) {
                    sendJsonResponse([
                        'success' => false,
                        'error'   => 403,
                        'message' => 'Access Denied: Your account is currently suspended or pending administrator approval.'
                    ], 403);
                }
            }

            // Explicit token was provided but invalid/expired/revoked
            sendJsonResponse([
                'success' => false,
                'error'   => 401,
                'message' => 'Unauthorized: Invalid, expired, or revoked mobile authorization token.'
            ], 401);
        }
    }

    // 2. Check if user is logged into CRM Session (Web Browser)
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_name'])) {
        $role = $_SESSION['user_role'] ?? 'User';
        $lr = strtolower($role);
        $isAdmin = (str_contains($lr, 'admin') || str_contains($lr, 'super'));
        return [
            'user_id'   => (int)$_SESSION['user_id'],
            'tenant_id' => (int)($_SESSION['tenant_id'] ?? 0),
            'name'      => $_SESSION['user_name'],
            'email'     => $_SESSION['user_email'] ?? '',
            'role'      => $role,
            'isAdmin'   => $isAdmin,
            'auth_type' => 'session'
        ];
    }

    // 3. Check for Secret API Key in Request Headers
    $apiKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    $definedKey = getenv('MARG_API_KEY') ?: 'MARG_CRM_SECURE_API_KEY_2026';
    
    if (!empty($apiKey) && hash_equals($definedKey, (string)$apiKey)) {
        return [
            'user_id'   => 0,
            'tenant_id' => 0,
            'name'      => 'API System',
            'role'      => 'Super Admin',
            'isAdmin'   => true,
            'auth_type' => 'api_key'
        ];
    }

    // 4. Fallback check for session role/name if present
    if (isset($_SESSION['user_role'])) {
        $role = $_SESSION['user_role'];
        $name = $_SESSION['user_name'] ?? 'Session User';
        $lr = strtolower($role);
        return [
            'user_id'   => (int)($_SESSION['user_id'] ?? 0),
            'tenant_id' => (int)($_SESSION['tenant_id'] ?? 0),
            'name'      => $name,
            'email'     => $_SESSION['user_email'] ?? '',
            'role'      => $role,
            'isAdmin'   => (str_contains($lr, 'admin') || str_contains($lr, 'super')),
            'auth_type' => 'session_fallback'
        ];
    }

    // 5. Access Denied: Render 404 UI only for direct browser hits on index.php, JSON for all API endpoint calls
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
        'message' => 'Unauthorized: Direct API access forbidden without active login session, valid Bearer token, or X-API-KEY header.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getAuthUserContext() {
    return requireApiAuth();
}
