<?php
/**
 * Marg ERP CRM - Attendance & Geo-Punch API
 * Biometric Webcam Capture, GPS Geofencing, Work Hours & Multi-Tenant Isolated Approvals Engine
 */

require_once __DIR__ . '/cors.php';
date_default_timezone_set('Asia/Kolkata');

// Authenticate session or API key
$auth = requireApiAuth();
$user_id = (int)($auth['user_id'] ?? 0);
$user_name = $auth['name'] ?? 'Employee';
$is_admin = (bool)($auth['isAdmin'] ?? false);
$tenant_id = (int)($auth['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);

// Release PHP session lock immediately so concurrent requests don't block each other
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

// -----------------------------------------------------------------------------
// Database Auto-Provisioner (Guarantees multi-tenant tables & columns exist once)
// -----------------------------------------------------------------------------
function ensure_attendance_tables(PDO $pdo) {
    static $provisioned = false;
    if ($provisioned) return;

    // Always guarantee employee_shifts table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_shifts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 0,
            `user_id` INT NOT NULL UNIQUE,
            `shift_name` VARCHAR(100) DEFAULT 'Custom Shift',
            `shift_start_time` TIME NOT NULL,
            `shift_end_time` TIME NOT NULL,
            `grace_period_minutes` INT DEFAULT 15,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_shift_user (`user_id`),
            INDEX idx_shift_tenant (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        error_log("Failed to create employee_shifts: " . $e->getMessage());
    }

    $flagFile = __DIR__ . '/../logs/.attendance_schema_v4';
    if (file_exists($flagFile)) {
        $provisioned = true;
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_attendance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 0,
            `user_id` INT NOT NULL,
            `employee_name` VARCHAR(150) NOT NULL,
            `attendance_date` DATE NOT NULL,
            `checkin_time` DATETIME NULL,
            `checkin_lat` DECIMAL(10, 8) NULL,
            `checkin_lng` DECIMAL(11, 8) NULL,
            `checkin_distance_meters` FLOAT NULL DEFAULT 0,
            `checkin_is_inside` TINYINT(1) DEFAULT 1,
            `checkin_selfie` VARCHAR(255) NULL,
            `checkin_reason` TEXT NULL,
            `checkin_approval_status` ENUM('auto_approved', 'pending', 'approved', 'rejected') DEFAULT 'auto_approved',
            `checkout_time` DATETIME NULL,
            `checkout_lat` DECIMAL(10, 8) NULL,
            `checkout_lng` DECIMAL(11, 8) NULL,
            `checkout_distance_meters` FLOAT NULL DEFAULT 0,
            `checkout_is_inside` TINYINT(1) DEFAULT 1,
            `checkout_selfie` VARCHAR(255) NULL,
            `checkout_reason` TEXT NULL,
            `checkout_approval_status` ENUM('not_checked_out', 'auto_approved', 'pending', 'approved', 'rejected') DEFAULT 'not_checked_out',
            `total_work_minutes` INT DEFAULT 0,
            `status` ENUM('present', 'pending_approval', 'approved', 'rejected', 'half_day', 'late') DEFAULT 'present',
            `approved_by` VARCHAR(100) NULL,
            `approval_notes` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_date (`user_id`, `attendance_date`),
            INDEX idx_date (`attendance_date`),
            INDEX idx_tenant (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 0,
            `office_name` VARCHAR(150) DEFAULT 'Marg Soft Solution HQ',
            `office_lat` DECIMAL(10, 8) NULL,
            `office_lng` DECIMAL(11, 8) NULL,
            `geofence_radius_meters` INT DEFAULT 150,
            `shift_start_time` TIME DEFAULT '09:30:00',
            `shift_end_time` TIME DEFAULT '18:30:00',
            `grace_period_minutes` INT DEFAULT 15,
            `half_day_hours` FLOAT DEFAULT 4.5,
            `full_day_hours` FLOAT DEFAULT 8.0,
            `require_selfie` TINYINT DEFAULT 1,
            `require_gps` TINYINT DEFAULT 1,
            `is_configured` TINYINT DEFAULT 0,
            INDEX idx_tenant_sett (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_leaves` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tenant_id` INT DEFAULT 0,
            `user_id` INT NOT NULL,
            `employee_name` VARCHAR(150) NOT NULL,
            `leave_type` VARCHAR(50) DEFAULT 'casual',
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `total_days` FLOAT DEFAULT 1,
            `reason` TEXT NULL,
            `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            `approved_by` VARCHAR(100) NULL,
            `rejection_reason` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leave_user (`user_id`),
            INDEX idx_leave_dates (`start_date`, `end_date`),
            INDEX idx_leave_tenant (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Safe column migration on existing tables
        try {
            $pdo->exec("ALTER TABLE `attendance_settings` ADD COLUMN `tenant_id` INT DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `attendance_settings` ADD COLUMN `is_configured` TINYINT DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `attendance_settings` ADD COLUMN `require_gps` TINYINT DEFAULT 1");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `attendance_settings` ADD COLUMN `require_selfie` TINYINT DEFAULT 1");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `employee_attendance` ADD COLUMN `tenant_id` INT DEFAULT 0");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `employee_leaves` ADD COLUMN `tenant_id` INT DEFAULT 0");
        } catch (\Throwable $e) {}

        // Ensure default row exists for master company (id = 1)
        $chkMaster = $pdo->query("SELECT id FROM attendance_settings WHERE id = 1 LIMIT 1");
        if (!$chkMaster || !$chkMaster->fetchColumn()) {
            $pdo->exec("INSERT INTO `attendance_settings` (`id`, `tenant_id`, `office_name`, `office_lat`, `office_lng`, `geofence_radius_meters`, `shift_start_time`, `shift_end_time`, `require_gps`, `require_selfie`, `is_configured`) 
                        VALUES (1, 0, 'Marg Soft Solution HQ', NULL, NULL, 150, '09:30:00', '18:30:00', 1, 1, 0);");
        }

        $uploadDir = __DIR__ . '/../uploads/attendance';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        @file_put_contents($flagFile, date('Y-m-d H:i:s'));
        $provisioned = true;
    } catch (\Throwable $t) {
        error_log("Attendance table provisioning notice: " . $t->getMessage());
    }
}

ensure_attendance_tables($pdo);

// -----------------------------------------------------------------------------
// Helper: Haversine Great-Circle Distance (in meters)
// -----------------------------------------------------------------------------
function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) {
        return 9999999;
    }
    $earthRadius = 6371000; // in meters
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLon = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c, 1);
}

// -----------------------------------------------------------------------------
// Helper: Save Base64 Selfie Image Securely
// -----------------------------------------------------------------------------
function save_selfie_base64($base64Data, $prefix, $userId) {
    if (empty($base64Data)) return null;

    $uploadDir = __DIR__ . '/../uploads/attendance/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        $type = strtolower($type[1]); // jpg, png, jpeg, webp
        if (!in_array($type, ['jpg', 'jpeg', 'png', 'webp'])) {
            $type = 'jpg';
        }
    } else {
        $type = 'jpg';
    }

    $decoded = base64_decode($base64Data);
    if (!$decoded) return null;

    $fileName = sprintf("%s_u%d_%s_%s.%s", $prefix, $userId, date('Ymd_His'), bin2hex(random_bytes(4)), $type);
    $filePath = $uploadDir . $fileName;

    if (file_put_contents($filePath, $decoded) !== false) {
        return 'uploads/attendance/' . $fileName;
    }
    return null;
}

// -----------------------------------------------------------------------------
// Multi-Tenant Aware Office Settings Resolver
// -----------------------------------------------------------------------------
function get_attendance_settings(PDO $pdo, int $tenant_id = 0) {
    try {
        if ($tenant_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM attendance_settings WHERE tenant_id = ? LIMIT 1");
            $stmt->execute([$tenant_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $res['is_configured'] = (!empty($res['office_lat']) && (float)$res['office_lat'] != 0) ? 1 : (isset($res['is_configured']) ? (int)$res['is_configured'] : 0);
                $res['require_gps'] = isset($res['require_gps']) ? (int)$res['require_gps'] : 1;
                $res['require_selfie'] = isset($res['require_selfie']) ? (int)$res['require_selfie'] : 1;
                return $res;
            }
        }

        $stmt = $pdo->query("SELECT * FROM attendance_settings WHERE id = 1 LIMIT 1");
        $res = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($res) {
            $res['is_configured'] = (!empty($res['office_lat']) && (float)$res['office_lat'] != 0) ? 1 : (isset($res['is_configured']) ? (int)$res['is_configured'] : 0);
            $res['require_gps'] = isset($res['require_gps']) ? (int)$res['require_gps'] : 1;
            $res['require_selfie'] = isset($res['require_selfie']) ? (int)$res['require_selfie'] : 1;
            return $res;
        }
    } catch (\Throwable $t) {}

    return [
        'id' => 1,
        'tenant_id' => $tenant_id,
        'office_name' => 'Marg Soft Solution HQ',
        'office_lat' => null,
        'office_lng' => null,
        'geofence_radius_meters' => 150,
        'shift_start_time' => '10:15:00',
        'shift_end_time' => '20:00:00',
        'grace_period_minutes' => 15,
        'half_day_hours' => 4.5,
        'full_day_hours' => 8.0,
        'require_gps' => 1,
        'require_selfie' => 1,
        'is_configured' => 0
    ];
}

/**
 * Resolve individual employee shift schedule (falling back to organization defaults)
 */
function get_user_effective_shift(PDO $pdo, int $user_id, int $tenant_id, array $defaultSettings) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_shifts WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['shift_start_time']) && !empty($row['shift_end_time'])) {
            return [
                'is_custom'            => true,
                'shift_name'           => $row['shift_name'] ?: 'Custom Shift',
                'shift_start_time'     => $row['shift_start_time'],
                'shift_end_time'       => $row['shift_end_time'],
                'grace_period_minutes' => (int)($row['grace_period_minutes'] ?? $defaultSettings['grace_period_minutes'] ?? 15)
            ];
        }
    } catch (\Throwable $e) {}

    return [
        'is_custom'            => false,
        'shift_name'           => 'Standard Shift',
        'shift_start_time'     => $defaultSettings['shift_start_time'] ?? '10:15:00',
        'shift_end_time'       => $defaultSettings['shift_end_time'] ?? '20:00:00',
        'grace_period_minutes' => (int)($defaultSettings['grace_period_minutes'] ?? 15)
    ];
}

$input = getJsonInput();
$action = trim($_GET['action'] ?? ($input['action'] ?? ''));

// =============================================================================
// ACTION 1: GET STATUS (Today's status for current employee + office settings)
// =============================================================================
if ($action === 'get_status') {
    $today = date('Y-m-d');
    $settings = get_attendance_settings($pdo, $tenant_id);
    $user_shift = get_user_effective_shift($pdo, $user_id, $tenant_id, $settings);

    $stmt = $pdo->prepare("SELECT * FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $stmt->execute([$user_id, $today]);
    $punch = $stmt->fetch(PDO::FETCH_ASSOC);

    $running_seconds = 0;
    if ($punch && !empty($punch['checkin_time']) && empty($punch['checkout_time'])) {
        $running_seconds = max(0, time() - strtotime($punch['checkin_time']));
    }

    $admin_stats = null;
    $pending_approvals_count = 0;
    if ($is_admin) {
        try {
            $stmtPending = $pdo->query("SELECT COUNT(*) FROM employee_attendance WHERE checkin_approval_status = 'pending' OR checkout_approval_status = 'pending'");
            $pending_approvals_count = (int)($stmtPending ? $stmtPending->fetchColumn() : 0);

            $stmtPresent = $pdo->prepare("SELECT COUNT(*) FROM employee_attendance WHERE attendance_date = ? AND checkin_time IS NOT NULL");
            $stmtPresent->execute([$today]);
            $present_today = (int)$stmtPresent->fetchColumn();

            $stmtUsers = $pdo->query("SELECT COUNT(*) FROM users 
                WHERE status = 'Active' 
                  AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') 
                  AND LOWER(role) NOT LIKE 'tenant%' 
                  AND LOWER(role) NOT LIKE 'client%' 
                  AND LOWER(role) NOT LIKE 'customer%'");
            $total_active_users = (int)($stmtUsers ? $stmtUsers->fetchColumn() : 0);

            $stmtLeaves = $pdo->prepare("SELECT COUNT(*) FROM employee_leaves WHERE ? BETWEEN start_date AND end_date AND status = 'approved'");
            $stmtLeaves->execute([$today]);
            $on_leave_today = (int)$stmtLeaves->fetchColumn();

            $admin_stats = [
                'pending_approvals_count' => $pending_approvals_count,
                'present_today'           => $present_today,
                'total_active_users'      => $total_active_users,
                'on_leave_today'          => $on_leave_today
            ];
        } catch (\Throwable $t) {}
    }

    sendJsonResponse([
        'success'           => true,
        'current_time_ist'  => date('Y-m-d H:i:s'),
        'today_date'        => $today,
        'user'              => [
            'id'       => $user_id,
            'name'     => $user_name,
            'is_admin' => $is_admin
        ],
        'settings'          => $settings,
        'user_shift'        => $user_shift,
        'punch'             => $punch,
        'running_seconds'   => $running_seconds,
        'admin_stats'       => $admin_stats
    ]);
}

// =============================================================================
// ACTION 2: CHECK-IN (GPS Geofence + Selfie Snapshot)
// =============================================================================
if ($action === 'checkin') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // 0. Verify Office Settings are calibrated
    $settings = get_attendance_settings($pdo, $tenant_id);
    if (empty($settings['is_configured']) || empty($settings['office_lat']) || empty($settings['office_lng'])) {
        sendJsonResponse([
            'success' => false,
            'not_configured' => true,
            'message' => 'Office location setup is pending. Your Super Admin has not yet calibrated the office coordinates.'
        ], 400);
    }

    // 1. Verify not already checked in today
    $checkStmt = $pdo->prepare("SELECT id, checkin_time FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $checkStmt->execute([$user_id, $today]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && !empty($existing['checkin_time'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Attendance already recorded for today at ' . date('h:i A', strtotime($existing['checkin_time'])) . '.'
        ], 400);
    }

    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;
    $selfieBase64 = $input['selfie'] ?? '';
    $reason = trim($input['reason'] ?? '');
    $requireSelfie = isset($settings['require_selfie']) ? (int)$settings['require_selfie'] : 1;

    // Biometric camera selfie snapshot is mandatory when required by policy (1)
    if ($requireSelfie === 1 && empty($selfieBase64)) {
        sendJsonResponse(['success' => false, 'message' => 'Biometric selfie photo is required for attendance.'], 422);
    }

    if (empty($lat) || empty($lng)) {
        sendJsonResponse(['success' => false, 'message' => 'Device location coordinates are required for attendance verification.'], 422);
    }

    $officeLat = (float)$settings['office_lat'];
    $officeLng = (float)$settings['office_lng'];
    $radiusMeters = (float)$settings['geofence_radius_meters'];

    // 2. Calculate Distance via Haversine Formula (Strictly matched!)
    $distanceMeters = haversine_distance($lat, $lng, $officeLat, $officeLng);
    $isInside = ($distanceMeters <= $radiusMeters) ? 1 : 0;

    // 3. Save Selfie Image if provided
    $selfiePath = save_selfie_base64($selfieBase64, 'checkin', $user_id);

    // 4. Determine Approval Status
    $user_shift = get_user_effective_shift($pdo, $user_id, $tenant_id, $settings);
    $shiftStartTime = $user_shift['shift_start_time'] ?? '10:15:00';
    $graceMinutes = (int)($user_shift['grace_period_minutes'] ?? 15);
    $shiftStartTimestamp = strtotime($today . ' ' . $shiftStartTime) + ($graceMinutes * 60);

    if ($isInside) {
        $checkinApproval = 'auto_approved';
        $dayStatus = (time() > $shiftStartTimestamp) ? 'late' : 'present';
        $responseMsg = sprintf("Check-in successful. Office geofence verified (Distance: %.1f meters). Have a great shift!", $distanceMeters);
    } else {
        if (empty($reason)) {
            sendJsonResponse([
                'success' => false,
                'require_reason' => true,
                'distance' => $distanceMeters,
                'radius' => $radiusMeters,
                'message' => sprintf("You are outside the office perimeter (Distance: %.1f meters, Allowed: %.0f meters). Please provide a reason for Super Admin verification.", $distanceMeters, $radiusMeters)
            ], 422);
        }
        $checkinApproval = 'pending';
        $dayStatus = 'pending_approval';
        $responseMsg = sprintf("Check-in recorded outside office radius (Distance: %.1f meters). Submitted for Super Admin approval.", $distanceMeters);
    }

    if ($existing) {
        $insStmt = $pdo->prepare("UPDATE employee_attendance SET 
            employee_name = ?,
            checkin_time = ?,
            checkin_lat = ?,
            checkin_lng = ?,
            checkin_distance_meters = ?,
            checkin_is_inside = ?,
            checkin_selfie = ?,
            checkin_reason = ?,
            checkin_approval_status = ?,
            status = ?,
            tenant_id = ?,
            updated_at = NOW()
            WHERE id = ?");
        $insStmt->execute([
            $user_name,
            $now,
            $lat,
            $lng,
            $distanceMeters,
            $isInside,
            $selfiePath,
            $reason ?: null,
            $checkinApproval,
            $dayStatus,
            $tenant_id,
            $existing['id']
        ]);
        $recordId = $existing['id'];
    } else {
        $insStmt = $pdo->prepare("INSERT INTO employee_attendance 
            (tenant_id, user_id, employee_name, attendance_date, checkin_time, checkin_lat, checkin_lng, checkin_distance_meters, checkin_is_inside, checkin_selfie, checkin_reason, checkin_approval_status, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insStmt->execute([
            $tenant_id,
            $user_id,
            $user_name,
            $today,
            $now,
            $lat,
            $lng,
            $distanceMeters,
            $isInside,
            $selfiePath,
            $reason ?: null,
            $checkinApproval,
            $dayStatus
        ]);
        $recordId = (int)$pdo->lastInsertId();
    }

    sendJsonResponse([
        'success'                 => true,
        'record_id'               => $recordId,
        'checkin_time'            => $now,
        'distance_meters'         => $distanceMeters,
        'is_inside'               => (bool)$isInside,
        'checkin_approval_status' => $checkinApproval,
        'status'                  => $dayStatus,
        'selfie_url'              => $selfiePath,
        'message'                 => $responseMsg
    ]);
}

// =============================================================================
// ACTION 3: CHECK-OUT (Re-verifies GPS Geofence + Exit Selfie + Calculates Hours)
// =============================================================================
if ($action === 'checkout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // 0. Verify Office Settings are calibrated
    $settings = get_attendance_settings($pdo, $tenant_id);
    if (empty($settings['is_configured']) || empty($settings['office_lat']) || empty($settings['office_lng'])) {
        sendJsonResponse([
            'success' => false,
            'not_configured' => true,
            'message' => 'Office location setup is pending. Please contact your Super Admin.'
        ], 400);
    }

    // 1. Verify employee has an active checkin today
    $st = $pdo->prepare("SELECT * FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $st->execute([$user_id, $today]);
    $punch = $st->fetch(PDO::FETCH_ASSOC);

    if (!$punch || empty($punch['checkin_time'])) {
        sendJsonResponse(['success' => false, 'message' => 'No active check-in found for today. Please check in first.'], 400);
    }

    if (!empty($punch['checkout_time']) && $punch['checkout_approval_status'] !== 'not_checked_out') {
        sendJsonResponse(['success' => false, 'message' => 'You have already checked out for today at ' . date('h:i A', strtotime($punch['checkout_time'])) . '.'], 400);
    }

    $lat = isset($input['lat']) ? (float)$input['lat'] : null;
    $lng = isset($input['lng']) ? (float)$input['lng'] : null;
    $selfieBase64 = $input['selfie'] ?? '';
    $reason = trim($input['reason'] ?? '');
    $requireSelfie = isset($settings['require_selfie']) ? (int)$settings['require_selfie'] : 1;

    // Biometric camera selfie snapshot is mandatory when required by policy (1)
    if ($requireSelfie === 1 && empty($selfieBase64)) {
        sendJsonResponse(['success' => false, 'message' => 'Biometric selfie photo is required for check-out.'], 422);
    }

    if (empty($lat) || empty($lng)) {
        sendJsonResponse(['success' => false, 'message' => 'Device GPS coordinates are required to verify check-out.'], 422);
    }

    $officeLat = (float)$settings['office_lat'];
    $officeLng = (float)$settings['office_lng'];
    $radiusMeters = (float)$settings['geofence_radius_meters'];

    // 2. Re-calculate Distance for Check-out (Strictly matched!)
    $distanceMeters = haversine_distance($lat, $lng, $officeLat, $officeLng);
    $isInside = ($distanceMeters <= $radiusMeters) ? 1 : 0;

    // 3. Save Check-out Selfie
    $selfiePath = save_selfie_base64($selfieBase64, 'checkout', $user_id);

    // 4. Calculate total work minutes
    $checkinTs = strtotime($punch['checkin_time']);
    $checkoutTs = strtotime($now);
    $workMinutes = max(0, round(($checkoutTs - $checkinTs) / 60));

    // 5. Determine Check-out Approval Status:
    if ($isInside) {
        $checkoutApproval = 'auto_approved';
        $dayStatus = ($punch['checkin_approval_status'] === 'auto_approved' || $punch['checkin_approval_status'] === 'approved') ? 'present' : $punch['status'];
        $responseMsg = sprintf("Check-out verified successfully. Total shift duration: %d hrs %d mins.", floor($workMinutes / 60), ($workMinutes % 60));
    } else {
        if (empty($reason)) {
            sendJsonResponse([
                'success' => false,
                'require_reason' => true,
                'distance' => $distanceMeters,
                'radius' => $radiusMeters,
                'message' => sprintf("You are checking out outside the office perimeter (Distance: %.1f meters, Allowed: %.0f meters). Please provide a reason for Super Admin verification.", $distanceMeters, $radiusMeters)
            ], 422);
        }
        $checkoutApproval = 'pending';
        $dayStatus = 'pending_approval';
        $responseMsg = sprintf("Check-out recorded outside office radius (Distance: %.1f meters). Submitted for Super Admin verification.", $distanceMeters);
    }

    $updStmt = $pdo->prepare("UPDATE employee_attendance SET 
        checkout_time = ?,
        checkout_lat = ?,
        checkout_lng = ?,
        checkout_distance_meters = ?,
        checkout_is_inside = ?,
        checkout_selfie = ?,
        checkout_reason = ?,
        checkout_approval_status = ?,
        total_work_minutes = ?,
        status = ?,
        updated_at = NOW()
        WHERE id = ?");
    $updStmt->execute([
        $now,
        $lat,
        $lng,
        $distanceMeters,
        $isInside,
        $selfiePath,
        $reason ?: null,
        $checkoutApproval,
        $workMinutes,
        $dayStatus,
        $punch['id']
    ]);

    sendJsonResponse([
        'success'                  => true,
        'checkout_time'            => $now,
        'distance_meters'          => $distanceMeters,
        'is_inside'                => (bool)$isInside,
        'checkout_approval_status' => $checkoutApproval,
        'total_work_minutes'       => $workMinutes,
        'status'                   => $dayStatus,
        'message'                  => $responseMsg
    ]);
}

// =============================================================================
// ACTION 4: ADMIN REVIEW (Approve / Reject Outside Check-in or Check-out)
// =============================================================================
if ($action === 'admin_review') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $attendanceId = (int)($input['attendance_id'] ?? 0);
    $reviewType = trim($input['review_type'] ?? 'checkin');
    $decision = trim($input['decision'] ?? '');
    $notes = trim($input['notes'] ?? '');

    if (!$attendanceId || !in_array($decision, ['approved', 'rejected'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid review request.'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM employee_attendance WHERE id = ? LIMIT 1");
    $stmt->execute([$attendanceId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        sendJsonResponse(['success' => false, 'message' => 'Attendance record not found.'], 404);
    }

    if ($reviewType === 'checkin') {
        $newApproval = ($decision === 'approved') ? 'approved' : 'rejected';
        $newStatus = ($decision === 'approved') ? 'present' : 'rejected';

        $upd = $pdo->prepare("UPDATE employee_attendance SET 
            checkin_approval_status = ?, 
            status = ?, 
            approved_by = ?, 
            approval_notes = ?,
            updated_at = NOW() 
            WHERE id = ?");
        $upd->execute([$newApproval, $newStatus, $user_name, $notes ?: null, $attendanceId]);

        $msg = sprintf("Check-in request for %s has been %s. Attendance marked as Present.", $rec['employee_name'], strtoupper($decision));
    } else {
        $newApproval = ($decision === 'approved') ? 'approved' : 'rejected';
        $newStatus = ($decision === 'approved') ? 'present' : $rec['status'];

        $upd = $pdo->prepare("UPDATE employee_attendance SET 
            checkout_approval_status = ?, 
            status = ?, 
            approved_by = ?, 
            approval_notes = ?,
            updated_at = NOW() 
            WHERE id = ?");
        $upd->execute([$newApproval, $newStatus, $user_name, $notes ?: null, $attendanceId]);

        $msg = sprintf("Check-out request for %s has been %s.", $rec['employee_name'], strtoupper($decision));
    }

    sendJsonResponse(['success' => true, 'message' => $msg]);
}

// =============================================================================
// ACTION 5: GET PENDING APPROVALS QUEUE (Admin View)
// =============================================================================
if ($action === 'get_pending_approvals') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $stmt = $pdo->query("SELECT a.*, u.email as user_email, u.role as user_role, u.profile_photo 
                         FROM employee_attendance a 
                         LEFT JOIN users u ON a.user_id = u.id 
                         WHERE a.checkin_approval_status = 'pending' OR a.checkout_approval_status = 'pending' 
                         ORDER BY a.attendance_date DESC, a.created_at DESC");
    $list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    sendJsonResponse(['success' => true, 'records' => $list]);
}

// =============================================================================
// ACTION 6: GET DAILY ATTENDANCE BOARD (Today's Company-Wide Status)
// =============================================================================
if ($action === 'get_daily_board') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $targetDate = trim($_GET['date'] ?? date('Y-m-d'));

    try {
        $stmt = $pdo->prepare("SELECT 
                u.id as user_id, 
                u.name as employee_name, 
                u.email, 
                u.role, 
                u.profile_photo,
                a.id as attendance_id,
                a.checkin_time,
                a.checkin_lat,
                a.checkin_lng,
                a.checkin_distance_meters,
                a.checkin_is_inside,
                a.checkin_approval_status,
                a.checkin_reason,
                a.checkin_selfie,
                a.checkout_time,
                a.checkout_distance_meters,
                a.checkout_is_inside,
                a.checkout_approval_status,
                a.checkout_reason,
                a.checkout_selfie,
                a.total_work_minutes,
                a.status as punch_status,
                l.leave_type,
                l.status as leave_status,
                es.shift_name as custom_shift_name,
                es.shift_start_time as custom_shift_start,
                es.shift_end_time as custom_shift_end,
                es.grace_period_minutes as custom_grace_period
            FROM users u 
            LEFT JOIN employee_attendance a ON (u.id = a.user_id AND a.attendance_date = ?)
            LEFT JOIN employee_leaves l ON (u.id = l.user_id AND ? BETWEEN l.start_date AND l.end_date AND l.status = 'approved')
            LEFT JOIN employee_shifts es ON (u.id = es.user_id)
            WHERE u.status = 'Active'
              AND LOWER(u.role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') 
              AND LOWER(u.role) NOT LIKE 'tenant%' 
              AND LOWER(u.role) NOT LIKE 'client%' 
              AND LOWER(u.role) NOT LIKE 'customer%'
            ORDER BY a.checkin_time DESC, u.name ASC");
        $stmt->execute([$targetDate, $targetDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback if employee_shifts was just creating
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_shifts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `tenant_id` INT DEFAULT 0,
                `user_id` INT NOT NULL UNIQUE,
                `shift_name` VARCHAR(100) DEFAULT 'Custom Shift',
                `shift_start_time` TIME NOT NULL,
                `shift_end_time` TIME NOT NULL,
                `grace_period_minutes` INT DEFAULT 15,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_shift_user (`user_id`),
                INDEX idx_shift_tenant (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt->execute([$targetDate, $targetDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            sendJsonResponse(['success' => false, 'message' => 'Database error compiling daily board: ' . $e2->getMessage()], 500);
        }
    }

    $sett = get_attendance_settings($pdo, $tenant_id);

    sendJsonResponse([
        'success'  => true, 
        'date'     => $targetDate, 
        'settings' => $sett,
        'board'    => $rows
    ]);
}

// =============================================================================
// ACTION 6D: GET ALL STAFF SHIFT SCHEDULES (Dedicated Shift Manager Roster)
// =============================================================================
if ($action === 'get_staff_shifts') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    try {
        $stmt = $pdo->prepare("SELECT 
                u.id as user_id, 
                u.name as employee_name, 
                u.email, 
                u.role, 
                u.profile_photo,
                es.shift_name as custom_shift_name,
                es.shift_start_time as custom_shift_start,
                es.shift_end_time as custom_shift_end,
                es.grace_period_minutes as custom_grace_period,
                es.updated_at as shift_updated_at
            FROM users u 
            LEFT JOIN employee_shifts es ON (u.id = es.user_id)
            WHERE u.status = 'Active'
              AND LOWER(u.role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') 
              AND LOWER(u.role) NOT LIKE 'tenant%' 
              AND LOWER(u.role) NOT LIKE 'client%' 
              AND LOWER(u.role) NOT LIKE 'customer%'
            ORDER BY u.name ASC");
        $stmt->execute();
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sett = get_attendance_settings($pdo, $tenant_id);

        sendJsonResponse([
            'success' => true,
            'staff' => $staff,
            'default_settings' => [
                'shift_start_time' => $sett['shift_start_time'] ?? '10:15:00',
                'shift_end_time'   => $sett['shift_end_time'] ?? '20:00:00',
                'grace_period_minutes' => (int)($sett['grace_period_minutes'] ?? 15)
            ]
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to load staff shifts: ' . $e->getMessage()], 500);
    }
}

// =============================================================================
// ACTION 6C: ASSIGN / UPDATE CUSTOM SHIFT FOR SPECIFIC EMPLOYEE
// =============================================================================
if ($action === 'save_user_shift') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $targetUserId = (int)($input['target_user_id'] ?? 0);
    $shiftName = trim($input['shift_name'] ?? 'Custom Shift');
    $shiftStart = trim($input['shift_start_time'] ?? '10:15:00');
    $shiftEnd = trim($input['shift_end_time'] ?? '20:00:00');
    $graceMinutes = (int)($input['grace_period_minutes'] ?? 15);
    $resetToDefault = !empty($input['reset_to_default']);

    if ($targetUserId <= 0) {
        sendJsonResponse(['success' => false, 'message' => 'Valid Employee is required.'], 422);
    }

    if ($resetToDefault) {
        $del = $pdo->prepare("DELETE FROM employee_shifts WHERE user_id = ?");
        $del->execute([$targetUserId]);
        sendJsonResponse(['success' => true, 'message' => 'Shift timing reset to company default successfully!']);
    }

    if (empty($shiftStart) || empty($shiftEnd)) {
        sendJsonResponse(['success' => false, 'message' => 'Shift start and end times are required.'], 422);
    }

    if (strlen($shiftStart) === 5) $shiftStart .= ':00';
    if (strlen($shiftEnd) === 5) $shiftEnd .= ':00';

    $stmtUpsert = $pdo->prepare("INSERT INTO employee_shifts 
        (tenant_id, user_id, shift_name, shift_start_time, shift_end_time, grace_period_minutes) 
        VALUES (?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
            shift_name = VALUES(shift_name), 
            shift_start_time = VALUES(shift_start_time), 
            shift_end_time = VALUES(shift_end_time), 
            grace_period_minutes = VALUES(grace_period_minutes)");
    $stmtUpsert->execute([$tenant_id, $targetUserId, $shiftName, $shiftStart, $shiftEnd, $graceMinutes]);

    sendJsonResponse(['success' => true, 'message' => 'Custom employee shift saved successfully!']);
}

// =============================================================================
// ACTION 6B: QUICK CALIBRATE OFFICE LOCATION (1-Click for Super Admin)
// =============================================================================
if ($action === 'quick_calibrate_office') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $lat = isset($input['lat']) ? (float)$input['lat'] : 0;
    $lng = isset($input['lng']) ? (float)$input['lng'] : 0;
    $radius = isset($input['radius']) ? (int)$input['radius'] : 0;

    if (empty($lat) || empty($lng)) {
        sendJsonResponse(['success' => false, 'message' => 'Valid Latitude & Longitude are required to calibrate.'], 422);
    }

    $sett = get_attendance_settings($pdo, $tenant_id);
    $targetRadius = $radius > 0 ? $radius : ($sett['geofence_radius_meters'] ?: 250);

    if ($tenant_id > 0) {
        $chk = $pdo->prepare("SELECT id FROM attendance_settings WHERE tenant_id = ? LIMIT 1");
        $chk->execute([$tenant_id]);
        $existId = $chk->fetchColumn();
        if ($existId) {
            $upd = $pdo->prepare("UPDATE attendance_settings SET office_lat = ?, office_lng = ?, geofence_radius_meters = ?, is_configured = 1 WHERE id = ?");
            $upd->execute([$lat, $lng, $targetRadius, $existId]);
        } else {
            $ins = $pdo->prepare("INSERT INTO attendance_settings (tenant_id, office_name, office_lat, office_lng, geofence_radius_meters, is_configured) VALUES (?, 'Marg Soft Solution HQ', ?, ?, ?, 1)");
            $ins->execute([$tenant_id, $lat, $lng, $targetRadius]);
        }
    } else {
        $upd = $pdo->prepare("UPDATE attendance_settings SET office_lat = ?, office_lng = ?, geofence_radius_meters = ?, is_configured = 1 WHERE id = 1");
        $upd->execute([$lat, $lng, $targetRadius]);
    }

    sendJsonResponse([
        'success' => true,
        'lat'     => $lat,
        'lng'     => $lng,
        'radius'  => $targetRadius,
        'message' => sprintf("Office HQ location successfully calibrated to Lat: %.5f, Lng: %.5f (Radius: %dm). You are now inside the verified office perimeter!", $lat, $lng, $targetRadius)
    ]);
}

// =============================================================================
// ACTION 6C: ADMIN MANUAL PUNCH / ATTENDANCE ADJUSTMENT
// =============================================================================
if ($action === 'admin_manual_punch') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $targetUserId = (int)($input['target_user_id'] ?? 0);
    $punchType = trim($input['punch_type'] ?? 'checkin');
    $targetDate = trim($input['target_date'] ?? date('Y-m-d'));
    $punchTime = trim($input['punch_time'] ?? date('H:i'));
    $notes = trim($input['notes'] ?? 'Administrative Manual Punch');

    if ($targetUserId <= 0) {
        sendJsonResponse(['success' => false, 'message' => 'Please select a valid employee.'], 422);
    }

    $uStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([$targetUserId]);
    $empName = $uStmt->fetchColumn() ?: 'Staff Member';

    $fullDateTime = $targetDate . ' ' . (strlen($punchTime) === 5 ? $punchTime . ':00' : $punchTime);

    $checkStmt = $pdo->prepare("SELECT * FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
    $checkStmt->execute([$targetUserId, $targetDate]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($punchType === 'checkin') {
        if ($existing) {
            $upd = $pdo->prepare("UPDATE employee_attendance SET 
                employee_name = ?,
                checkin_time = ?, 
                checkin_approval_status = 'approved', 
                status = 'present', 
                approved_by = ?,
                approval_notes = ?,
                updated_at = NOW() 
                WHERE id = ?");
            $upd->execute([$empName, $fullDateTime, $user_name, $notes, $existing['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO employee_attendance 
                (tenant_id, user_id, employee_name, attendance_date, checkin_time, checkin_is_inside, checkin_approval_status, status, approved_by, approval_notes) 
                VALUES (?, ?, ?, ?, ?, 1, 'approved', 'present', ?, ?)");
            $ins->execute([$tenant_id, $targetUserId, $empName, $targetDate, $fullDateTime, $user_name, $notes]);
        }

        sendJsonResponse([
            'success' => true,
            'message' => "Manual check-in recorded for {$empName} at " . date('h:i A', strtotime($fullDateTime)) . "."
        ]);
    } else {
        // checkout
        if (!$existing || empty($existing['checkin_time'])) {
            sendJsonResponse(['success' => false, 'message' => "Cannot check out: {$empName} has not recorded a check-in for {$targetDate}."], 400);
        }

        $inTs = strtotime($existing['checkin_time']);
        $outTs = strtotime($fullDateTime);
        $wMins = max(0, round(($outTs - $inTs) / 60));

        $upd = $pdo->prepare("UPDATE employee_attendance SET 
            checkout_time = ?, 
            checkout_approval_status = 'approved', 
            total_work_minutes = ?, 
            approved_by = ?, 
            approval_notes = ?,
            updated_at = NOW() 
            WHERE id = ?");
        $upd->execute([$fullDateTime, $wMins, $user_name, $notes, $existing['id']]);

        sendJsonResponse([
            'success' => true,
            'message' => "Manual check-out recorded for {$empName}. Verified shift duration: " . floor($wMins / 60) . "h " . ($wMins % 60) . "m."
        ]);
    }
}

// =============================================================================
// ACTION 7: SAVE OFFICE & GEOFENCE SETTINGS (Multi-Tenant Calibrated)
// =============================================================================
if ($action === 'save_settings') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $officeName = trim($input['office_name'] ?? 'Marg Soft Solution HQ');
    $officeLat = (float)($input['office_lat'] ?? 0);
    $officeLng = (float)($input['office_lng'] ?? 0);
    $radius = max(20, (int)($input['geofence_radius_meters'] ?? 150));
    $shiftStart = trim($input['shift_start_time'] ?? '09:30:00');
    $shiftEnd = trim($input['shift_end_time'] ?? '18:30:00');
    $graceMinutes = (int)($input['grace_period_minutes'] ?? 15);
    $requireGps = 1; // Strict Geofence is always enforced
    $requireSelfie = isset($input['require_selfie']) ? (int)$input['require_selfie'] : 1;

    if (empty($officeLat) || empty($officeLng)) {
        sendJsonResponse(['success' => false, 'message' => 'Valid Office Latitude & Longitude are required to calibrate the geofence.'], 422);
    }

    if ($tenant_id > 0) {
        $chk = $pdo->prepare("SELECT id FROM attendance_settings WHERE tenant_id = ? LIMIT 1");
        $chk->execute([$tenant_id]);
        $existId = $chk->fetchColumn();

        if ($existId) {
            $upd = $pdo->prepare("UPDATE attendance_settings SET 
                office_name = ?, 
                office_lat = ?, 
                office_lng = ?, 
                geofence_radius_meters = ?, 
                shift_start_time = ?, 
                shift_end_time = ?, 
                grace_period_minutes = ?,
                require_gps = ?,
                require_selfie = ?,
                is_configured = 1 
                WHERE id = ?");
            $upd->execute([$officeName, $officeLat, $officeLng, $radius, $shiftStart, $shiftEnd, $graceMinutes, $requireGps, $requireSelfie, $existId]);
        } else {
            $ins = $pdo->prepare("INSERT INTO attendance_settings 
                (tenant_id, office_name, office_lat, office_lng, geofence_radius_meters, shift_start_time, shift_end_time, grace_period_minutes, require_gps, require_selfie, is_configured) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $ins->execute([$tenant_id, $officeName, $officeLat, $officeLng, $radius, $shiftStart, $shiftEnd, $graceMinutes, $requireGps, $requireSelfie]);
        }
    } else {
        $upd = $pdo->prepare("UPDATE attendance_settings SET 
            office_name = ?, 
            office_lat = ?, 
            office_lng = ?, 
            geofence_radius_meters = ?, 
            shift_start_time = ?, 
            shift_end_time = ?, 
            grace_period_minutes = ?,
            require_gps = ?,
            require_selfie = ?,
            is_configured = 1 
            WHERE id = 1");
        $upd->execute([$officeName, $officeLat, $officeLng, $radius, $shiftStart, $shiftEnd, $graceMinutes, $requireGps, $requireSelfie]);
    }

    sendJsonResponse([
        'success' => true, 
        'is_configured' => 1,
        'message' => 'Office Geofence & Shift settings calibrated successfully. Employee punch console is now active!'
    ]);
}

// =============================================================================
// ACTION 8: APPLY LEAVE (Employee)
// =============================================================================
if ($action === 'apply_leave') {
    $leaveType = trim($input['leave_type'] ?? 'casual');
    $startDate = trim($input['start_date'] ?? '');
    $endDate = trim($input['end_date'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if (empty($startDate) || empty($endDate) || empty($reason)) {
        sendJsonResponse(['success' => false, 'message' => 'Please provide start date, end date, and leave reason.'], 422);
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($endTs < $startTs) {
        sendJsonResponse(['success' => false, 'message' => 'End date cannot be prior to start date.'], 422);
    }

    $days = round(($endTs - $startTs) / 86400) + 1;

    $stmt = $pdo->prepare("INSERT INTO employee_leaves (tenant_id, user_id, employee_name, leave_type, start_date, end_date, total_days, reason, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$tenant_id, $user_id, $user_name, $leaveType, $startDate, $endDate, $days, $reason]);

    sendJsonResponse(['success' => true, 'message' => sprintf('Leave request for %.0f day(s) submitted for Super Admin review.', $days)]);
}

// =============================================================================
// ACTION 9: REVIEW LEAVE (Admin Only)
// =============================================================================
if ($action === 'review_leave') {
    if (!$is_admin) {
        sendJsonResponse(['success' => false, 'message' => 'Unauthorized: Administrator access required.'], 403);
    }

    $leaveId = (int)($input['leave_id'] ?? 0);
    $decision = trim($input['decision'] ?? '');
    $rejectionReason = trim($input['rejection_reason'] ?? '');

    if (!$leaveId || !in_array($decision, ['approved', 'rejected'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid leave review.'], 422);
    }

    $upd = $pdo->prepare("UPDATE employee_leaves SET status = ?, approved_by = ?, rejection_reason = ? WHERE id = ?");
    $upd->execute([$decision, $user_name, $rejectionReason ?: null, $leaveId]);

    sendJsonResponse(['success' => true, 'message' => 'Leave application ' . strtoupper($decision) . ' successfully.']);
}

// =============================================================================
// ACTION 10: GET MONTHLY LOGS (Personal or Admin Employee Logs)
// =============================================================================
if ($action === 'get_monthly_logs') {
    $month = (int)($_GET['month'] ?? date('m'));
    $year = (int)($_GET['year'] ?? date('Y'));
    $targetUserId = ($is_admin && !empty($_GET['user_id'])) ? (int)$_GET['user_id'] : $user_id;

    $stmt = $pdo->prepare("SELECT * FROM employee_attendance 
                           WHERE user_id = ? AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ? 
                           ORDER BY attendance_date DESC");
    $stmt->execute([$targetUserId, $month, $year]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse(['success' => true, 'month' => $month, 'year' => $year, 'logs' => $logs]);
}

// =============================================================================
// ACTION 11: GET IP LOCATION FALLBACK
// =============================================================================
if ($action === 'get_ip_location') {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    $lat = null;
    $lng = null;
    $city = '';
    $region = '';

    if (!empty($ip) && $ip !== '127.0.0.1' && $ip !== '::1') {
        try {
            // Try cURL first for shared hosting compatibility
            if (function_exists('curl_init')) {
                $ch = curl_init("http://ip-api.com/json/{$ip}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                $raw = curl_exec($ch);
                curl_close($ch);
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                $raw = @file_get_contents("http://ip-api.com/json/{$ip}", false, $ctx);
            }

            if ($raw) {
                $j = json_decode($raw, true);
                if (!empty($j) && ($j['status'] ?? '') === 'success') {
                    $lat = (float)$j['lat'];
                    $lng = (float)$j['lon'];
                    $city = $j['city'] ?? '';
                    $region = $j['regionName'] ?? '';
                }
            }
        } catch (\Throwable $e) {}
    }

    // Smart Office Reference Fallback:
    // Cellular ISPs (Jio/Airtel) route traffic to regional gateways in Lucknow (>70km away).
    // If the detected IP is more than 30km away from office, treat it as an ISP gateway and use office coordinates
    $sett = get_attendance_settings($pdo, $tenant_id);
    if (!empty($sett['office_lat']) && !empty($sett['office_lng'])) {
        $officeLat = (float)$sett['office_lat'];
        $officeLng = (float)$sett['office_lng'];
        if (!empty($lat) && !empty($lng)) {
            $distFromOffice = haversine_distance($lat, $lng, $officeLat, $officeLng);
            if ($distFromOffice > 30000) {
                // ISP Gateway in distant city -> use office reference
                $lat = $officeLat;
                $lng = $officeLng;
                $city = $sett['office_name'] ?: 'Office HQ';
            }
        } else {
            $lat = $officeLat;
            $lng = $officeLng;
            $city = $sett['office_name'] ?: 'Office HQ';
        }
    }

    sendJsonResponse([
        'success'        => (!empty($lat) && !empty($lng)),
        'ip'             => $ip,
        'lat'            => $lat,
        'lng'            => $lng,
        'city'           => $city,
        'region'         => $region,
        'is_ip_fallback' => true
    ]);
}

sendJsonResponse(['success' => false, 'message' => 'Unrecognized attendance action.'], 400);
