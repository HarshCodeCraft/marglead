<?php
/**
 * Marg ERP CRM - Mobile App Authentication & Device Security Gateway
 * High-Entropy Bearer Tokens, Anti-Brute Force Protection, Multi-Tenant Isolation & Device Revocation
 */

require_once __DIR__ . '/cors.php';
date_default_timezone_set('Asia/Kolkata');

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

// Ensure token table exists
ensure_mobile_token_table($pdo);

$action = strtolower(trim($_GET['action'] ?? ''));

// =============================================================================
// ACTION 1: MOBILE LOGIN (High Security & Anti-Brute-Force)
// =============================================================================
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Invalid HTTP method. Use POST.'], 405);
    }

    $input = getJsonInput();
    $email = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $deviceName = trim($input['device_name'] ?? 'Mobile Device');
    $deviceModel = trim($input['device_model'] ?? 'Generic Model');
    $deviceOs = trim($input['device_os'] ?? 'Android');
    $deviceId = trim($input['device_id'] ?? '');

    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (empty($email) || empty($password)) {
        sendJsonResponse(['success' => false, 'message' => 'Email/Phone and password are required.'], 422);
    }

    // 1. Anti-Brute-Force Lockout (5 attempts / 15 minutes)
    try {
        $lockoutStmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip_address = ? OR email = ?) AND attempt_time > NOW() - INTERVAL 15 MINUTE");
        $lockoutStmt->execute([$user_ip, $email]);
        $failedAttempts = (int)$lockoutStmt->fetchColumn();

        if ($failedAttempts >= 5) {
            sendJsonResponse([
                'success' => false,
                'message' => 'Account temporarily locked due to 5 consecutive failed login attempts. Please try again in 15 minutes.'
            ], 429);
        }
    } catch (\Throwable $e) {}

    $matchedUser = null;
    $userType = 'user';
    $tenantId = 0;
    $companyName = 'Marg Soft Solution HQ';
    $tenantCode = 'MASTER';

    // 2. Check tenant companies table first (Company Admins)
    try {
        $rawDigits = preg_replace('/\D/', '', $email);
        $cleanPhone10 = strlen($rawDigits) >= 10 ? substr($rawDigits, -10) : '';

        if (!empty($cleanPhone10)) {
            $stmtT = $pdo->prepare("SELECT * FROM tenant_companies WHERE (LOWER(owner_email) = ? OR LOWER(company_code) = ? OR phone LIKE ?) AND status = 'Active' AND LOWER(company_code) != 'master' AND id != 1 ORDER BY id DESC LIMIT 1");
            $stmtT->execute([$email, $email, '%' . $cleanPhone10]);
        } else {
            $stmtT = $pdo->prepare("SELECT * FROM tenant_companies WHERE (LOWER(owner_email) = ? OR LOWER(company_code) = ?) AND status = 'Active' AND LOWER(company_code) != 'master' AND id != 1 ORDER BY id DESC LIMIT 1");
            $stmtT->execute([$email, $email]);
        }
        $tenantComp = $stmtT->fetch(PDO::FETCH_ASSOC);

        if ($tenantComp) {
            $pwdHash = $tenantComp['password'] ?? '';
            if (!empty($pwdHash) && (password_verify($password, $pwdHash) || $password === $pwdHash)) {
                $matchedUser = [
                    'id'            => (int)$tenantComp['id'],
                    'name'          => $tenantComp['owner_name'],
                    'email'         => $tenantComp['owner_email'],
                    'role'          => 'Tenant Admin',
                    'status'        => $tenantComp['status'],
                    'profile_photo' => null
                ];
                $userType = 'tenant';
                $tenantId = (int)$tenantComp['id'];
                $companyName = $tenantComp['company_name'];
                $tenantCode = $tenantComp['company_code'];
            }
        }
    } catch (\Throwable $e) {}

    // 3. Check master users table (Employees, Staff, Super Admins)
    if (!$matchedUser) {
        try {
            $stmtU = $pdo->prepare("SELECT id, name, email, password, role, status, profile_photo FROM users WHERE LOWER(email) = ? OR LOWER(name) = ? LIMIT 1");
            $stmtU->execute([$email, $email]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);

            if ($uRow) {
                $pwdHash = $uRow['password'] ?? '';
                if (!empty($pwdHash) && (password_verify($password, $pwdHash) || $password === $pwdHash)) {
                    // Auto upgrade plaintext password to bcrypt hash
                    if ($password === $pwdHash && !password_verify($password, $pwdHash)) {
                        try {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $uRow['id']]);
                        } catch (\Throwable $ex) {}
                    }

                    $matchedUser = [
                        'id'            => (int)$uRow['id'],
                        'name'          => $uRow['name'],
                        'email'         => $uRow['email'],
                        'role'          => $uRow['role'] ?: 'Staff',
                        'status'        => $uRow['status'],
                        'profile_photo' => $uRow['profile_photo'] ?? null
                    ];
                    $userType = 'user';
                    $tenantId = 0;
                }
            }
        } catch (\Throwable $e) {}
    }

    // 4. Verification Check
    if (!$matchedUser) {
        try {
            $logAttempt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)");
            $logAttempt->execute([$user_ip, $email]);
        } catch (\Throwable $e) {}

        sendJsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }

    // 5. Account Status Enforcement
    if (!in_array($matchedUser['status'], ['Active', 'Approved'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Your account is currently under review or inactive. Please contact your administrator.'
        ], 403);
    }

    // 6. Reset failed attempts
    try {
        $delAttempts = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?");
        $delAttempts->execute([$user_ip, $email]);
    } catch (\Throwable $e) {}

    // 7. Generate Cryptographically Secure 256-Bit Bearer Token
    $rawToken = 'mrg_' . bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

    try {
        $insToken = $pdo->prepare("INSERT INTO mobile_app_tokens 
            (tenant_id, user_id, user_type, token_hash, device_name, device_model, device_os, device_id, ip_address, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insToken->execute([
            $tenantId,
            $matchedUser['id'],
            $userType,
            $tokenHash,
            $deviceName,
            $deviceModel,
            $deviceOs,
            $deviceId,
            $user_ip,
            $expiresAt
        ]);
    } catch (\Throwable $e) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to initialize mobile session: ' . $e->getMessage()], 500);
    }

    // 8. Fetch user effective shift timings
    $userShift = [
        'shift_name'           => 'General Shift',
        'shift_start_time'     => '10:15:00',
        'shift_end_time'       => '20:00:00',
        'grace_period_minutes' => 15
    ];
    try {
        $shStmt = $pdo->prepare("SELECT * FROM employee_shifts WHERE user_id = ? LIMIT 1");
        $shStmt->execute([$matchedUser['id']]);
        $customShift = $shStmt->fetch(PDO::FETCH_ASSOC);
        if ($customShift && !empty($customShift['shift_start_time'])) {
            $userShift = [
                'shift_name'           => $customShift['shift_name'] ?: 'Custom Shift',
                'shift_start_time'     => $customShift['shift_start_time'],
                'shift_end_time'       => $customShift['shift_end_time'],
                'grace_period_minutes' => (int)($customShift['grace_period_minutes'] ?? 15)
            ];
        }
    } catch (\Throwable $e) {}

    $roleLower = strtolower($matchedUser['role']);
    $isAdmin = (str_contains($roleLower, 'admin') || str_contains($roleLower, 'super'));

    sendJsonResponse([
        'success'         => true,
        'message'         => 'Mobile authentication successful.',
        'token'           => $rawToken,
        'token_type'      => 'Bearer',
        'expires_at'      => $expiresAt,
        'user'            => [
            'id'            => $matchedUser['id'],
            'name'          => $matchedUser['name'],
            'email'         => $matchedUser['email'],
            'role'          => $matchedUser['role'],
            'is_admin'      => $isAdmin,
            'tenant_id'     => $tenantId,
            'profile_photo' => $matchedUser['profile_photo']
        ],
        'company'         => [
            'name' => $companyName,
            'code' => $tenantCode
        ],
        'effective_shift' => $userShift
    ]);
}

// =============================================================================
// ACTION 2: VERIFY SESSION & PROFILE (Requires Bearer Token)
// =============================================================================
if ($action === 'verify' || $action === 'profile') {
    $auth = requireApiAuth();
    $userId = (int)$auth['user_id'];
    $tenantId = (int)$auth['tenant_id'];

    // Fetch shift
    $userShift = [
        'shift_name'           => 'General Shift',
        'shift_start_time'     => '10:15:00',
        'shift_end_time'       => '20:00:00',
        'grace_period_minutes' => 15
    ];
    try {
        $shStmt = $pdo->prepare("SELECT * FROM employee_shifts WHERE user_id = ? LIMIT 1");
        $shStmt->execute([$userId]);
        $customShift = $shStmt->fetch(PDO::FETCH_ASSOC);
        if ($customShift && !empty($customShift['shift_start_time'])) {
            $userShift = [
                'shift_name'           => $customShift['shift_name'] ?: 'Custom Shift',
                'shift_start_time'     => $customShift['shift_start_time'],
                'shift_end_time'       => $customShift['shift_end_time'],
                'grace_period_minutes' => (int)($customShift['grace_period_minutes'] ?? 15)
            ];
        }
    } catch (\Throwable $e) {}

    // Check today's punch status
    $today = date('Y-m-d');
    $punch = null;
    try {
        $pStmt = $pdo->prepare("SELECT id, checkin_time, checkout_time, checkin_approval_status, checkout_approval_status, status 
                                FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
        $pStmt->execute([$userId, $today]);
        $punch = $pStmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    sendJsonResponse([
        'success'         => true,
        'user'            => [
            'id'       => $userId,
            'name'     => $auth['name'],
            'email'    => $auth['email'] ?? '',
            'role'     => $auth['role'],
            'is_admin' => $auth['isAdmin'],
            'tenant_id'=> $tenantId
        ],
        'effective_shift' => $userShift,
        'today_punch'     => $punch,
        'server_time'     => date('Y-m-d H:i:s')
    ]);
}

// =============================================================================
// ACTION 3: LOGOUT (Revokes Current Device Token)
// =============================================================================
if ($action === 'logout') {
    $auth = requireApiAuth();
    
    // Extract current token
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $bearerToken = '';
    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $bearerToken = trim($matches[1]);
    }
    if (empty($bearerToken)) {
        $bearerToken = $headers['X-Mobile-Token'] ?? $headers['x-mobile-token'] ?? $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    }

    if (!empty($bearerToken)) {
        $tokenHash = hash('sha256', $bearerToken);
        try {
            $pdo->prepare("UPDATE mobile_app_tokens SET is_revoked = 1 WHERE token_hash = ?")->execute([$tokenHash]);
        } catch (\Throwable $e) {}
    }

    sendJsonResponse([
        'success' => true,
        'message' => 'Logged out successfully. Device token has been revoked.'
    ]);
}

// =============================================================================
// ACTION 4: REVOKE ALL DEVICES (Security / Lost Phone Protection)
// =============================================================================
if ($action === 'revoke_all') {
    $auth = requireApiAuth();
    $userId = (int)$auth['user_id'];
    $tenantId = (int)$auth['tenant_id'];

    try {
        $upd = $pdo->prepare("UPDATE mobile_app_tokens SET is_revoked = 1 WHERE user_id = ? AND tenant_id = ?");
        $upd->execute([$userId, $tenantId]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'All active mobile app sessions have been securely terminated.'
        ]);
    } catch (\Throwable $e) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to revoke sessions: ' . $e->getMessage()], 500);
    }
}

// =============================================================================
// ACTION 5: ACTIVE DEVICES AUDIT LIST (User Privacy & Security Feature)
// =============================================================================
if ($action === 'active_devices') {
    $auth = requireApiAuth();
    $userId = (int)$auth['user_id'];
    $tenantId = (int)$auth['tenant_id'];

    try {
        $stmt = $pdo->prepare("SELECT id, device_name, device_model, device_os, ip_address, created_at, last_used_at 
                               FROM mobile_app_tokens 
                               WHERE user_id = ? AND tenant_id = ? AND is_revoked = 0 AND (expires_at IS NULL OR expires_at > NOW()) 
                               ORDER BY last_used_at DESC");
        $stmt->execute([$userId, $tenantId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mask IP address for privacy
        foreach ($devices as &$dev) {
            $ipParts = explode('.', $dev['ip_address'] ?? '');
            if (count($ipParts) === 4) {
                $dev['ip_address'] = $ipParts[0] . '.' . $ipParts[1] . '.*.*';
            }
        }

        sendJsonResponse([
            'success' => true,
            'devices' => $devices
        ]);
    } catch (\Throwable $e) {
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// =============================================================================
// ACTION 6: SYSTEM CONFIGURATION & VERSION METADATA
// =============================================================================
if ($action === 'system_config') {
    sendJsonResponse([
        'success'                => true,
        'app_version'            => '1.0.0',
        'min_supported_version'  => '1.0.0',
        'server_time'            => date('Y-m-d H:i:s'),
        'privacy_policy_url'     => 'https://friendlyaisolution.com/privacy-policy.php',
        'terms_url'              => 'https://friendlyaisolution.com/terms.php',
        'support_email'          => 'support@friendlyaisolution.com',
        'gps_tracking_policy'    => 'Location coordinates are strictly accessed only during user-initiated Check-In and Check-Out actions. No covert 24/7 background tracking is performed.'
    ]);
}

// Default fallback
sendJsonResponse([
    'success' => false,
    'message' => 'Action parameter missing or invalid. Supported actions: login, verify, logout, revoke_all, active_devices, system_config.'
], 400);

