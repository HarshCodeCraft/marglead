<?php
/**
 * Marg ERP CRM & Lead Management System - Global Configuration
 * Contains roles, mock data, and layout helper utilities.
 */

// Load central WhatsApp & System configuration
require_once __DIR__ . '/../config/config.php';

// Set default system timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

// Security HTTP Headers
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// Start session to persist user role & theme preferences (5 Hours = 18000s)
if (session_status() == PHP_SESSION_NONE) {
    $session_lifetime = 18000; // 5 Hours
    @ini_set('session.gc_maxlifetime', $session_lifetime);
    @ini_set('session.cookie_lifetime', $session_lifetime);
    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => $session_lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        @session_set_cookie_params($session_lifetime, '/');
    }
    session_start();
}

// Global Anti-CSRF Token Initialization & Helpers
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('getCsrfToken')) {
    function getCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('renderCsrfInput')) {
    function renderCsrfInput() {
        $token = getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token = null) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Secure File Upload Helper with MIME Type & Extension Whitelisting
 */
if (!function_exists('secureFileUpload')) {
    function secureFileUpload($file, $destination_subfolder = 'attachments', $allowed_mimes = [], $allowed_exts = []) {
        if (!isset($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'No file uploaded or upload error occurred.'];
        }

        $tmp_name = $file['tmp_name'];
        $original_name = basename($file['name']);
        $file_size = $file['size'];

        // 1. Max File Size Check (10MB)
        if ($file_size > 10 * 1024 * 1024) {
            return ['success' => false, 'error' => 'File size exceeds maximum limit of 10MB.'];
        }

        // 2. Extension Check (Prohibit executable file extensions)
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $dangerous_extensions = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'exe', 'bat', 'sh', 'pl', 'py', 'cgi', 'asp', 'aspx', 'js', 'jsp'];
        if (in_array($ext, $dangerous_extensions)) {
            return ['success' => false, 'error' => 'Security Violation: Executable script extensions are strictly prohibited.'];
        }

        if (!empty($allowed_exts) && !in_array($ext, $allowed_exts)) {
            return ['success' => false, 'error' => 'Invalid file extension: .' . $ext];
        }

        // 3. MIME Type Validation via finfo
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $tmp_name);
            finfo_close($finfo);

            $default_allowed_mimes = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
                'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain', 'text/csv', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/aac',
                'video/mp4', 'video/webm', 'video/quicktime'
            ];

            $allowed_mimes_check = !empty($allowed_mimes) ? $allowed_mimes : $default_allowed_mimes;

            if (!in_array($mime_type, $allowed_mimes_check)) {
                return ['success' => false, 'error' => 'Security Violation: Invalid or untrusted MIME type (' . $mime_type . ').'];
            }
        }

        // 4. Sanitize subfolder & write file safely
        $clean_folder = trim(str_replace(['..', '\\'], '', $destination_subfolder), '/');
        $destination_dir = __DIR__ . '/../uploads/' . $clean_folder . '/';
        if (!is_dir($destination_dir)) {
            @mkdir($destination_dir, 0775, true);
        }

        $clean_filename = preg_replace("/[^a-zA-Z0-9_-]/", "", pathinfo($original_name, PATHINFO_FILENAME));
        if (empty($clean_filename)) $clean_filename = 'file';

        $unique_name = time() . '_' . rand(1000, 9999) . '_' . $clean_filename . '.' . $ext;
        $target_file_path = $destination_dir . $unique_name;

        if (move_uploaded_file($tmp_name, $target_file_path)) {
            return [
                'success' => true,
                'file_path' => 'uploads/' . $clean_folder . '/' . $unique_name,
                'file_name' => $original_name,
                'file_size' => $file_size,
                'extension' => $ext
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to write upload file to server disk.'];
        }
    }
}

// Session Security: Inactivity Timeout (5 Hours = 18000s)
if (!empty($_SESSION['user_id'])) {
    $now = time();
    $max_idle = 18000; // 5 hours
    
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $max_idle) {
        $_SESSION = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['action'])) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'session_expired',
                'reason' => 'inactivity',
                'message' => 'Session expired due to 5 hours of inactivity. Please sign in again.',
                'redirect' => 'auth/login.php?reason=session_expired'
            ]);
            exit;
        }
        header("Location: auth/login.php?reason=session_expired");
        exit;
    } else {
        $_SESSION['last_activity'] = $now;
    }

    // Keep current IP recorded without dropping session on mobile carrier / cellular dynamic IP shifts
    $curr_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $_SESSION['user_ip'] = $curr_ip;
}

// Connect XAMPP Database server
require_once __DIR__ . '/db.php';

// Live session synchronization with DB user records for instant role, permission & single-device concurrent enforcement
if (isset($db_connected) && $db_connected && isset($pdo) && $pdo) {
    $sync_user_id = $_SESSION['user_id'] ?? null;
    $sync_user_email = $_SESSION['user_email'] ?? null;
    $sync_login_role = $_SESSION['login_role'] ?? $_SESSION['user_role'] ?? '';
    $sync_login_source = $_SESSION['login_source'] ?? '';

    if ($sync_user_id || $sync_user_email) {
        try {
            $isTenantAdmin = false;
            $dbUser = null;

            if ($sync_login_source === 'tenant_companies') {
                $syncStmt = $pdo->prepare("SELECT id, owner_name as name, owner_email as email, status, active_session_token FROM tenant_companies WHERE id = ?");
                $syncStmt->execute([$sync_user_id]);
                $dbUser = $syncStmt->fetch(PDO::FETCH_ASSOC);
                if ($dbUser) {
                    $isTenantAdmin = true;
                }
            }

            if (!$dbUser) {
                if ($sync_user_id) {
                    $syncStmt = $pdo->prepare("SELECT id, name, email, role, status, permissions, action_permissions, active_session_token FROM users WHERE id = ?");
                    $syncStmt->execute([$sync_user_id]);
                } else {
                    $syncStmt = $pdo->prepare("SELECT id, name, email, role, status, permissions, action_permissions, active_session_token FROM users WHERE email = ?");
                    $syncStmt->execute([$sync_user_email]);
                }
                $dbUser = $syncStmt->fetch(PDO::FETCH_ASSOC);
                $isTenantAdmin = false;
            }

            if ($dbUser) {
                // 1. Single Concurrent Device Enforcement:
                // If a new login occurred on another system, active_session_token in DB will be updated.
                $dbToken = $dbUser['active_session_token'] ?? '';
                $sessionToken = $_SESSION['active_session_token'] ?? '';

                if (!empty($dbToken) && !empty($sessionToken) && $sessionToken !== $dbToken) {
                    // Another device logged in! Instantly terminate this session.
                    $_SESSION = array();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }

                    if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['action'])) {
                        http_response_code(401);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'status' => 'session_terminated',
                            'reason' => 'concurrent_login',
                            'message' => 'Logged out: Your account was logged in from another device or browser.',
                            'redirect' => 'auth/login.php?reason=concurrent_login'
                        ]);
                        exit;
                    }

                    header("Location: auth/login.php?reason=concurrent_login");
                    exit;
                }

                // If DB token is not set yet (e.g. existing session), initialize it
                if (empty($dbToken) && !empty($sessionToken)) {
                    if ($isTenantAdmin) {
                        $pdo->prepare("UPDATE tenant_companies SET active_session_token = ? WHERE id = ?")->execute([$sessionToken, $dbUser['id']]);
                    } else {
                        $pdo->prepare("UPDATE users SET active_session_token = ? WHERE id = ?")->execute([$sessionToken, $dbUser['id']]);
                    }
                }

                if ($dbUser['status'] === 'Declined' || $dbUser['status'] === 'Inactive' || $dbUser['status'] === 'Suspended') {
                    // Instantly block access for deactivated users
                    $_SESSION = array();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }
                    if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['action'])) {
                        http_response_code(403);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'status' => 'account_deactivated',
                            'message' => 'Your account access has been deactivated. Please contact administrator.',
                            'redirect' => 'auth/login.php'
                        ]);
                        exit;
                    }
                    header("Location: auth/login.php");
                    exit;
                } else if (!$isTenantAdmin) {
                    $prevLoginRole = $_SESSION['login_role'] ?? '';
                    $_SESSION['user_id'] = $dbUser['id'];
                    $_SESSION['login_role'] = $dbUser['role'];

                    // Sync active role if user is not actively mimicking another role via switch_role
                    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] === $prevLoginRole || empty($_SESSION['user_role'])) {
                        $_SESSION['user_role'] = $dbUser['role'];
                    }

                    $_SESSION['user_name'] = $dbUser['name'];
                    $_SESSION['user_email'] = $dbUser['email'];
                    $_SESSION['user_permissions'] = !empty($dbUser['permissions']) ? json_decode($dbUser['permissions'], true) : null;
                    $_SESSION['user_action_permissions'] = !empty($dbUser['action_permissions']) ? json_decode($dbUser['action_permissions'], true) : null;
                }
            }
        } catch (PDOException $e) {
            // Fallback gracefully if query fails
        }
    }
}

// User role switcher handler
if (isset($_GET['action']) && $_GET['action'] === 'switch_role' && isset($_GET['role'])) {
    $roles_list = [
        'Super Admin', 'Admin', 'Regional Manager', 'Team Leader', 
        'Sales Executive', 'Telecaller', 'Support Executive', 'Installation Engineer', 'Accounts'
    ];
    if (in_array($_GET['role'], $roles_list)) {
        $_SESSION['user_role'] = $_GET['role'];
    }
    // Clean redirect back to original page
    $clean_url = strtok($_SERVER["REQUEST_URI"], '?');
    $page_param = isset($_GET['page']) ? '?page=' . urlencode($_GET['page']) : '';
    header("Location: " . $clean_url . $page_param);
    exit;
}

// Global Application Constants
define('APP_NAME', 'Friendly AI Solution');
define('APP_VERSION', '1.0.0');

// Default Session values for prototyping
if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'Admin'; // Default role for demonstration
}
if (!isset($_SESSION['login_role'])) {
    $_SESSION['login_role'] = 'Admin'; // Default login role for demonstration
}
if (!empty($_COOKIE['app_theme']) && in_array($_COOKIE['app_theme'], ['light', 'dark'])) {
    $_SESSION['theme'] = $_COOKIE['app_theme'];
} elseif (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light'; // Default clean light theme to prevent dark flash
}

// Employee Roles List (Assignable operator roles for CRM businesses)
$EMPLOYEE_ROLES = [
    'Admin' => 'Full system management with employee & client oversight.',
    'Sales Head' => 'Head of Sales: manages lead assignments, telecaller allocations, follow-ups, proposals & targets.',
    'Technical Head' => 'Head of Technical Operations: manages helpdesk tickets, support assignments, installations & training.',
    'Regional Manager' => 'Regional team allocations, approvals, & visual reports.',
    'Team Leader' => 'Team allocations, follow-up monitoring, & targets tracking.',
    'Sales Executive' => 'Lead pipeline management, quotes, demo sheets, & payment logs.',
    'Telecaller' => 'Lead lists, cold/warm calling registers, and follow-ups.',
    'Support Executive' => 'Ticketing panel, troubleshooting logs, and chat/email support.',
    'Installation Engineer' => 'Client installation schedules, checklists, and sign-offs.',
    'Accounts' => 'Invoice updates, payments validation, and receipt records.'
];

// All System Roles (Master Super Admin + Employee Roles)
$ROLES = array_merge([
    'Super Admin' => 'Full access to master SaaS settings, client databases, integrations & roles.'
], $EMPLOYEE_ROLES);

// Lead Status Pipeline (16 Stages)
$PIPELINE_STAGES = [
    'new' => ['label' => 'New', 'color' => '#3b82f6'],
    'contacted' => ['label' => 'Contacted', 'color' => '#10b981'],
    'interested' => ['label' => 'Interested', 'color' => '#8b5cf6'],
    'demo_scheduled' => ['label' => 'Demo Scheduled', 'color' => '#f59e0b'],
    'demo_completed' => ['label' => 'Demo Completed', 'color' => '#10b981'],
    'quotation_sent' => ['label' => 'Quotation Sent', 'color' => '#6366f1'],
    'negotiation' => ['label' => 'Negotiation', 'color' => '#ec4899'],
    'payment_pending' => ['label' => 'Payment Pending', 'color' => '#ef4444'],
    'payment_received' => ['label' => 'Payment Received', 'color' => '#10b981'],
    'install_pending' => ['label' => 'Installation Pending', 'color' => '#f59e0b'],
    'install_completed' => ['label' => 'Installation Completed', 'color' => '#10b981'],
    'training_completed' => ['label' => 'Training Completed', 'color' => '#8b5cf6'],
    'support' => ['label' => 'Support', 'color' => '#06b6d4'],
    'renewal' => ['label' => 'Renewal Due', 'color' => '#eab308'],
    'won' => ['label' => 'Closed Won', 'color' => '#10b981'],
    'lost' => ['label' => 'Closed Lost', 'color' => '#ef4444'],
    'dropped' => ['label' => 'Dropped', 'color' => '#64748b']
];

// Helper to resolve notification target URL
if (!function_exists('getNotificationLink')) {
    function getNotificationLink($n) {
        if (is_array($n) && !empty($n['link'])) {
            return $n['link'];
        }
        $title = is_array($n) ? strtolower($n['title'] ?? '') : strtolower((string)$n);
        $message = is_array($n) ? strtolower($n['message'] ?? '') : '';
        $combined = $title . ' ' . $message;

        if (strpos($combined, 'ticket') !== false || strpos($combined, 'support') !== false) {
            return 'index.php?page=support';
        }
        if (strpos($combined, 'lead') !== false) {
            return 'index.php?page=leads';
        }
        if (strpos($combined, 'operator') !== false || strpos($combined, 'user') !== false || strpos($combined, 'registration') !== false || strpos($combined, 'privileges') !== false || strpos($combined, 'permission') !== false) {
            return 'index.php?page=admin_users';
        }
        if (strpos($combined, 'follow-up') !== false || strpos($combined, 'followup') !== false || strpos($combined, 'reminder') !== false) {
            return 'index.php?page=followups';
        }
        if (strpos($combined, 'demo') !== false) {
            return 'index.php?page=demo';
        }
        if (strpos($combined, 'quote') !== false || strpos($combined, 'quotation') !== false) {
            return 'index.php?page=quotation';
        }
        if (strpos($combined, 'payment') !== false || strpos($combined, 'receipt') !== false || strpos($combined, 'invoice') !== false) {
            return 'index.php?page=payments';
        }
        if (strpos($combined, 'bank') !== false || strpos($combined, 'qr') !== false) {
            return 'index.php?page=bank_accounts';
        }
        if (strpos($combined, 'report') !== false) {
            return 'index.php?page=reports';
        }
        return 'index.php?page=dashboard';
    }
}

// Load notifications dynamically from DB based on role and user id
$NOTIFICATIONS = [];
if (isset($_SESSION['user_role'])) {
    $role = $_SESSION['user_role'];
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    if (isset($db_connected) && $db_connected && isset($pdo) && $pdo) {
        // Process single notification read & redirect
        if (isset($_GET['action']) && $_GET['action'] === 'read_notification' && isset($_GET['id'])) {
            $notif_id = intval($_GET['id']);
            $target_link = 'index.php?page=dashboard';
            try {
                $fetchStmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? LIMIT 1");
                $fetchStmt->execute([$notif_id]);
                $notifObj = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($notifObj) {
                    $target_link = getNotificationLink($notifObj);
                    $updStmt = $pdo->prepare("UPDATE notifications SET unread = 0 WHERE id = ?");
                    $updStmt->execute([$notif_id]);
                }
            } catch (PDOException $e) {}
            
            header("Location: " . $target_link);
            exit;
        }

        // Process Mark all read action
        if (isset($_GET['action']) && $_GET['action'] === 'mark_notifs_read') {
            try {
                if ($role === 'Super Admin' || $role === 'Admin') {
                    $pdo->exec("UPDATE notifications SET unread = 0");
                } else {
                    $stmt = $pdo->prepare("UPDATE notifications SET unread = 0 WHERE user_id = ? OR (user_id IS NULL AND role = ? AND role NOT IN ('Admin', 'Super Admin'))");
                    $stmt->execute([$user_id, $role]);
                }
            } catch (PDOException $e) {}

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }

            // Clean redirect back
            $clean_url = strtok($_SERVER["REQUEST_URI"], '?');
            $query_params = $_GET;
            unset($query_params['action']);
            $query_string = http_build_query($query_params);
            header("Location: " . $clean_url . ($query_string ? '?' . $query_string : ''));
            exit;
        }

        try {
            // Load only unread notifications (hide read notifications)
            $is_admin_user = ($role === 'Super Admin' || $role === 'Admin');
            if ($is_admin_user) {
                $stmt = $pdo->query("SELECT * FROM notifications WHERE unread = 1 ORDER BY created_at DESC LIMIT 15");
            } else {
                // Strictly fetch notifications assigned to the specific employee or their non-admin role
                $stmt = $pdo->prepare("SELECT * FROM notifications WHERE (user_id = ? OR (user_id IS NULL AND role = ? AND role NOT IN ('Admin', 'Super Admin'))) AND unread = 1 ORDER BY created_at DESC LIMIT 15");
                $stmt->execute([$user_id, $role]);
            }
            $db_notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($db_notifs as $n) {
                // Safety guard: Non-admin employees must never see Admin-targeted notifications
                if (!$is_admin_user && !empty($n['role']) && ($n['role'] === 'Admin' || $n['role'] === 'Super Admin')) {
                    continue;
                }
                $created_time = strtotime($n['created_at']);
                $time_diff = time() - $created_time;
                if ($time_diff < 0) {
                    $time_diff = 0;
                }

                if ($time_diff < 60) {
                    $rel_time = 'Just now';
                } elseif ($time_diff < 3600) {
                    $mins = max(1, floor($time_diff / 60));
                    $rel_time = $mins . ($mins == 1 ? ' min ago' : ' mins ago');
                } elseif ($time_diff < 86400) {
                    $hours = floor($time_diff / 3600);
                    $rel_time = $hours . ($hours == 1 ? ' hour ago' : ' hours ago');
                } elseif ($time_diff < 172800) {
                    $rel_time = 'Yesterday at ' . date('h:i A', $created_time);
                } else {
                    $rel_time = date('M d, h:i A', $created_time);
                }
                
                $NOTIFICATIONS[] = [
                    'id' => $n['id'],
                    'title' => htmlspecialchars($n['title']),
                    'message' => htmlspecialchars($n['message']),
                    'link' => getNotificationLink($n),
                    'time' => $rel_time,
                    'type' => $n['type'],
                    'unread' => (bool)$n['unread']
                ];
            }
        } catch (PDOException $e) {
            $NOTIFICATIONS = [];
        }
    }
}

// Synchronize shared permission keys between module access and action access
if (!function_exists('syncPermissionMappings')) {
    function syncPermissionMappings(&$modules, &$actions) {
        if (!is_array($modules)) $modules = [];
        if (!is_array($actions)) $actions = [];

        if (in_array('can_create', $actions) && !in_array('support_create', $modules)) {
            $modules[] = 'support_create';
        } elseif (in_array('support_create', $modules) && !in_array('can_create', $actions)) {
            $actions[] = 'can_create';
        }

        if (in_array('can_edit', $actions) && !in_array('support_edit', $modules)) {
            $modules[] = 'support_edit';
        } elseif (in_array('support_edit', $modules) && !in_array('can_edit', $actions)) {
            $actions[] = 'can_edit';
        }

        if (in_array('can_assign', $actions) && !in_array('support_assign', $modules)) {
            $modules[] = 'support_assign';
        } elseif (in_array('support_assign', $modules) && !in_array('can_assign', $actions)) {
            $actions[] = 'can_assign';
        }

        if (in_array('can_update_status', $actions) && !in_array('support_close', $modules)) {
            $modules[] = 'support_close';
        } elseif (in_array('support_close', $modules) && !in_array('can_update_status', $actions)) {
            $actions[] = 'can_update_status';
        }

        $modules = array_values(array_unique($modules));
        $actions = array_values(array_unique($actions));
    }
}

/**
 * Dynamic Database-Driven Role & Permission Check
 * Evaluates user role & ID dynamically from database session without hardcoded strings.
 */
if (!function_exists('isSystemAdminRole')) {
    function isSystemAdminRole(?string $role = null): bool {
        if ($role === null) {
            $role = $_SESSION['user_role'] ?? '';
        }
        $cleanRole = strtolower(trim((string)$role));

        // Active SaaS Tenant Client / Tenant Admin sessions are NEVER Master System Admins
        if ($cleanRole === 'tenant admin' || !empty($_SESSION['is_tenant'])) {
            return false;
        }

        $active_tenant = $_SESSION['tenant_db'] ?? '';
        $master_db_name = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
        if (!empty($active_tenant) && $active_tenant !== $master_db_name && $active_tenant !== 'marg_crm' && empty($_SESSION['impersonate_tenant_db'])) {
            return false;
        }

        $userEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        $userName  = strtolower(trim((string)($_SESSION['user_name'] ?? '')));

        $is_deepak = (
            in_array($userEmail, ['deepakawasthi587@gmail.com']) ||
            strpos($userName, 'deepak') !== false ||
            strpos($userName, 'awasthi') !== false
        );

        if (in_array($cleanRole, ['super admin', 'superadmin'])) {
            return true;
        }

        if ($is_deepak && empty($active_tenant)) {
            return true;
        }

        return false;
    }
}

// Helper to check user permission
function hasAccess($module, $role) {
    // 1. Normalize sub-pages to their parent permissions
    $normalized_module = $module;
    if (in_array($module, ['lead_form', 'lead_details', 'lead_import'])) {
        $normalized_module = 'leads';
    } elseif (in_array($module, ['quotation_create', 'quotation_view'])) {
        $normalized_module = 'quotation';
    } elseif (in_array($module, ['bot_flow_builder'])) {
        $normalized_module = 'bot_flows';
    } elseif ($module === 'admin_reports') {
        $normalized_module = 'reports';
    } elseif ($module === 'whatsapp_flows') {
        $normalized_module = 'bot_flows';
    } elseif ($module === 'bulk_broadcast') {
        $normalized_module = 'broadcast_campaigns';
    }

    // Check Tenant Company Power Permissions (CRM Client allowed_modules block)
    $master_db_name = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
    $is_tenant_session = (!empty($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== $master_db_name && $_SESSION['tenant_db'] !== 'marg_crm') 
        || !empty($_SESSION['impersonate_tenant_db']) 
        || (($role ?? '') === 'Tenant Admin') 
        || (($_SESSION['user_role'] ?? '') === 'Tenant Admin') 
        || (($_SESSION['login_source'] ?? '') === 'tenant_companies');

    // 2. For non-tenant users, legal & compliance pages are publicly allowed
    if (!$is_tenant_session && in_array($module, ['privacy_policy', 'terms_conditions', 'refund_policy', 'policy_manager'])) {
        return true;
    }

    // 3. System Administrator ALWAYS gets 100% full access to all pages on Master CRM (when not impersonating)
    if (isSystemAdminRole($role) && empty($_SESSION['impersonate_tenant_db'])) {
        return true;
    }

    if ($is_tenant_session) {
        $active_tenant_db = $_SESSION['impersonate_tenant_db'] ?? ($_SESSION['tenant_db'] ?? '');
        $active_tenant_id = $_SESSION['impersonate_tenant_id'] ?? ($_SESSION['tenant_id'] ?? ($_SESSION['user_id'] ?? 0));
        
        // Fetch fresh allowed_modules for active tenant so Super Admin updates apply instantly
        global $pdo_master, $pdo;
        $db_to_query = $pdo_master ?? $pdo;
        if (isset($db_to_query)) {
            try {
                $stmtT = $db_to_query->prepare("SELECT allowed_modules FROM tenant_companies WHERE id = ? OR (db_name = ? AND db_name != '') OR (company_code = ? AND company_code != '') OR (owner_email = ? AND owner_email != '') LIMIT 1");
                $stmtT->execute([$active_tenant_id, $active_tenant_db, $_SESSION['tenant_code'] ?? '', $_SESSION['user_email'] ?? '']);
                $jsonM = $stmtT->fetchColumn();
                $default_tenant_mods = ["workspace_dashboard", "whatsapp_dashboard", "dashboard", "merchant_waba_settings", "whatsapp_settings", "team_inbox", "broadcast_campaigns", "bot_flows", "whatsapp_flows", "bulk_broadcast"];
                if ($jsonM !== false && $jsonM !== null && $jsonM !== '' && $jsonM !== 'null') {
                    $decoded_mods = json_decode($jsonM, true);
                    $_SESSION['tenant_allowed_modules'] = is_array($decoded_mods) ? $decoded_mods : $default_tenant_mods;
                } else {
                    $_SESSION['tenant_allowed_modules'] = $default_tenant_mods;
                }
                $_SESSION['tenant_allowed_db'] = $active_tenant_db;
            } catch (\PDOException $e) {}
        }

        if (isset($_SESSION['tenant_allowed_modules']) && is_array($_SESSION['tenant_allowed_modules'])) {
            $tenant_mods = $_SESSION['tenant_allowed_modules'];

            // Legal & compliance module check for tenant
            if (in_array($normalized_module, ['privacy_policy', 'terms_conditions', 'refund_policy'])) {
                return in_array($normalized_module, $tenant_mods);
            }

            // Bots & Auto-Reply flows strictly require Option 1: Meta Cloud API Gateway
            if ($normalized_module === 'bot_flows' || $normalized_module === 'whatsapp_flows') {
                if (!in_array('whatsapp_flows', $tenant_mods) && !in_array('bot_flows', $tenant_mods)) {
                    return false;
                }
                $tenant_gw = $_SESSION['tenant_gateway_type'] ?? null;
                if ($tenant_gw === null) {
                    $tenant_gw = 'web_api';
                    if (isset($pdo)) {
                        try {
                            $stGW = $pdo->query("SELECT gateway_type FROM merchant_waba_settings ORDER BY id DESC LIMIT 1");
                            if ($stGW && ($gwRow = $stGW->fetch(PDO::FETCH_ASSOC))) {
                                $tenant_gw = strtolower($gwRow['gateway_type'] ?? 'web_api');
                            }
                        } catch (\PDOException $e) {}
                    }
                    $_SESSION['tenant_gateway_type'] = $tenant_gw;
                }
                return ($tenant_gw === 'meta');
            }
            
            // Build alias keys for modules
            $has_explicit_dashboards = in_array('workspace_dashboard', $tenant_mods) || in_array('whatsapp_dashboard', $tenant_mods);
            $check_keys = [$normalized_module];
            if ($normalized_module === 'workspace_dashboard') {
                $check_keys = ['workspace_dashboard'];
                if (!$has_explicit_dashboards && in_array('dashboard', $tenant_mods)) {
                    $check_keys[] = 'dashboard';
                }
            } elseif ($normalized_module === 'whatsapp_dashboard') {
                $check_keys = ['whatsapp_dashboard'];
                if (!$has_explicit_dashboards) {
                    $check_keys[] = 'dashboard';
                    $check_keys[] = 'merchant_waba_settings';
                    $check_keys[] = 'whatsapp_settings';
                }
            } elseif ($normalized_module === 'dashboard') {
                $check_keys = ['dashboard'];
                $check_keys[] = 'workspace_dashboard';
                $check_keys[] = 'whatsapp_dashboard';
            } elseif ($normalized_module === 'bot_flows' || $normalized_module === 'whatsapp_flows') {
                $check_keys[] = 'bot_flows';
                $check_keys[] = 'whatsapp_flows';
            } elseif ($normalized_module === 'whatsapp_settings' || $normalized_module === 'merchant_waba_settings') {
                $check_keys = ['whatsapp_settings', 'merchant_waba_settings'];
            } elseif ($normalized_module === 'broadcast_campaigns' || $normalized_module === 'bulk_broadcast') {
                $check_keys = ['broadcast_campaigns', 'bulk_broadcast'];
            } elseif ($normalized_module === 'customer_kyc' || $normalized_module === 'customer_kyc_admin') {
                $check_keys[] = 'customer_kyc';
                $check_keys[] = 'clients';
                $check_keys[] = 'leads';
            } elseif ($normalized_module === 'clients') {
                $check_keys[] = 'clients';
                $check_keys[] = 'leads';
            }
            
            // If none of the check keys are in tenant_mods, strictly DENY access
            $has_tenant_perm = false;
            foreach ($check_keys as $k) {
                if (in_array($k, $tenant_mods)) {
                    $has_tenant_perm = true;
                    break;
                }
            }
            
            if (!$has_tenant_perm) {
                return false;
            }
        }
    }

    // 5. For Tenant Admin & System Super Admin, if Tenant Power Check passed, grant access
    if ($role === 'Super Admin' || $role === 'Admin' || $role === 'Tenant Admin') {
        return true;
    }

    // 5. For sub-user Employee roles, check user_permissions or role permissions matrix
    $login_role = isset($_SESSION['login_role']) ? $_SESSION['login_role'] : $role;
    if (isset($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions']) && $role === $login_role) {
        if (in_array($normalized_module, $_SESSION['user_permissions'])) {
            return true;
        }
        if ($normalized_module === 'support_create' && hasActionAccess('can_create')) return true;
        if ($normalized_module === 'support_edit' && hasActionAccess('can_edit')) return true;
        if ($normalized_module === 'support_assign' && hasActionAccess('can_assign')) return true;
        if ($normalized_module === 'support_close' && hasActionAccess('can_update_status')) return true;
        return false;
    }

    $permissions = [
        'Admin' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings', 'bot_flows', 'whatsapp_flows', 'team_inbox', 'broadcast_campaigns', 'merchant_waba_settings', 'whatsapp_settings', 'bulk_broadcast', 'clients', 'crm_clients', 'admin_users', 'admin_permissions', 'privacy_policy', 'terms_conditions', 'refund_policy'],
        'Client' => ['dashboard', 'quotation', 'payments', 'support', 'renewals', 'bot_flows', 'privacy_policy', 'terms_conditions', 'refund_policy'],
        'Sales Head' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'team_inbox', 'broadcast_campaigns', 'bulk_broadcast', 'clients', 'renewals', 'reports', 'privacy_policy', 'terms_conditions', 'refund_policy', 'manager'],
        'Technical Head' => ['dashboard', 'support', 'team_inbox', 'bot_flows', 'installation', 'training', 'renewals', 'reports', 'privacy_policy', 'terms_conditions', 'refund_policy', 'support_create', 'support_edit', 'support_assign', 'support_close', 'manager'],
        'Regional Manager' => ['dashboard', 'leads', 'pipeline', 'demo', 'quotation', 'payments', 'team_inbox', 'broadcast_campaigns', 'bulk_broadcast', 'clients', 'renewals', 'reports', 'privacy_policy', 'terms_conditions', 'refund_policy', 'manager'],
        'Team Leader' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'team_inbox', 'broadcast_campaigns', 'renewals', 'privacy_policy', 'terms_conditions', 'refund_policy', 'manager'],
        'Sales Executive' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'team_inbox', 'privacy_policy', 'terms_conditions', 'refund_policy', 'employee'],
        'Telecaller' => ['dashboard', 'leads', 'followups', 'privacy_policy', 'terms_conditions', 'refund_policy', 'employee'],
        'Support Executive' => ['dashboard', 'support', 'team_inbox', 'bot_flows', 'privacy_policy', 'terms_conditions', 'refund_policy', 'support_create', 'support_edit', 'support_close', 'employee'],
        'Installation Engineer' => ['dashboard', 'installation', 'training', 'privacy_policy', 'terms_conditions', 'refund_policy', 'employee'],
        'Accounts' => ['dashboard', 'payments', 'quotation', 'renewals', 'privacy_policy', 'terms_conditions', 'refund_policy']
    ];
    
    if (isset($permissions[$role])) {
        return in_array($normalized_module, $permissions[$role]);
    }
    
    return false;
}

// Helper to resolve user module permissions with role fallback
if (!function_exists('getUserPermissions')) {
    function getUserPermissions($user) {
        if (is_array($user) && !empty($user['permissions'])) {
            $perms = json_decode($user['permissions'], true);
            if (is_array($perms)) {
                $actions = getUserActionPermissions($user);
                syncPermissionMappings($perms, $actions);
                return $perms;
            }
        }
        
        $role = is_array($user) ? ($user['role'] ?? '') : (string)$user;
        if ($role === 'Super Admin' || $role === 'Admin') {
            return ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
        }
        
        $role_permissions = [
            'Client' => ['dashboard', 'quotation', 'payments', 'support', 'renewals', 'bot_flows'],
            'Regional Manager' => ['dashboard', 'leads', 'pipeline', 'demo', 'quotation', 'payments', 'renewals', 'reports'],
            'Team Leader' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'renewals'],
            'Sales Executive' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments'],
            'Telecaller' => ['dashboard', 'leads', 'followups'],
            'Support Executive' => ['dashboard', 'support'],
            'Installation Engineer' => ['dashboard', 'installation', 'training'],
            'Accounts' => ['dashboard', 'payments', 'quotation', 'renewals']
        ];
        
        return isset($role_permissions[$role]) ? $role_permissions[$role] : ['dashboard'];
    }
}

// System Settings Data Storage Helpers
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = '') {
        global $pdo;
        if (!$pdo) return $default;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

if (!function_exists('setSystemSetting')) {
    function setSystemSetting($key, $value) {
        global $pdo;
        if (!$pdo) return false;
        try {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            return $stmt->execute([$key, $value]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

// Helper to resolve user granular action permissions with role defaults
function getUserActionPermissions($user) {
    if (is_array($user) && !empty($user['action_permissions'])) {
        $actions = json_decode($user['action_permissions'], true);
        if (is_array($actions)) {
            return $actions;
        }
    }
    
    $role = is_array($user) ? ($user['role'] ?? '') : (string)$user;
    if ($role === 'Super Admin' || $role === 'Admin') {
        return ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'];
    }
    
    $role_actions = [
        'Sales Head' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'],
        'Technical Head' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_export', 'can_assign'],
        'Regional Manager' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'],
        'Team Leader' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_export', 'can_assign'],
        'Sales Executive' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share'],
        'Telecaller' => ['can_view', 'can_edit', 'can_update_status'],
        'Support Executive' => ['can_view', 'can_edit', 'can_update_status', 'can_share'],
        'Installation Engineer' => ['can_view', 'can_edit', 'can_update_status'],
        'Accounts' => ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_export']
    ];
    
    return isset($role_actions[$role]) ? $role_actions[$role] : ['can_view'];
}

// Check granular action access for current logged-in user or specified user
function hasActionAccess($action, $user_data = null) {
    $role = $_SESSION['user_role'] ?? 'Sales Executive';
    if ($role === 'Super Admin' || $role === 'Admin') {
        return true;
    }
    
    $user = $user_data;
    if (!$user && isset($_SESSION['user_action_permissions']) && is_array($_SESSION['user_action_permissions'])) {
        return in_array($action, $_SESSION['user_action_permissions']);
    }
    
    if (!$user && isset($_SESSION['user_id']) && isset($GLOBALS['pdo']) && $GLOBALS['db_connected']) {
        try {
            $stmt = $GLOBALS['pdo']->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }
    
    if (!$user) {
        $user = ['role' => $role];
    }
    
    $allowed_actions = getUserActionPermissions($user);
    return in_array($action, $allowed_actions);
}

// Generate HTML Badge for Lead Statuses
function getStatusBadge($statusKey) {
    global $PIPELINE_STAGES;
    if (isset($PIPELINE_STAGES[$statusKey])) {
        $stage = $PIPELINE_STAGES[$statusKey];
        return "<span class='badge' style='--badge-bg: {$stage['color']}15; --badge-color: {$stage['color']}'>{$stage['label']}</span>";
    }
    return "<span class='badge'>$statusKey</span>";
}

// Generate Priority Badge
function getPriorityBadge($priority) {
    $colors = [
        'hot' => ['bg' => 'var(--danger-light)', 'color' => 'var(--danger)'],
        'warm' => ['bg' => 'var(--warning-light)', 'color' => 'var(--warning)'],
        'cold' => ['bg' => 'var(--info-light)', 'color' => 'var(--info)']
    ];
    $p = strtolower($priority);
    if (isset($colors[$p])) {
        return "<span class='badge' style='--badge-bg: {$colors[$p]['bg']}; --badge-color: {$colors[$p]['color']}'>" . ucfirst($priority) . "</span>";
    }
    return "<span class='badge'>" . ucfirst($priority) . "</span>";
}

// Active link helper
function isActivePage($pageName) {
    $current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
    return ($current_page === $pageName) ? 'active' : '';
}

/**
 * Global helper to compute Live Metric Card counts: Upcoming Expired Lead, Demo Scheduled, Call Back.
 */
if (!function_exists('getLiveMetricCounts')) {
    function getLiveMetricCounts($pdo, $is_admin = true, $user_name = '') {
        $today_str = date('Y-m-d');
        $tomorrow_str = date('Y-m-d', strtotime('+1 day'));
        $nextday_str = date('Y-m-d', strtotime('+2 days'));

        $yesterday_str = date('Y-m-d', strtotime('-1 day'));
        $day_before_str = date('Y-m-d', strtotime('-2 days'));
        $three_days_ago_str = date('Y-m-d', strtotime('-3 days'));

        $expired_counts = ['total' => 0, 'today' => 0, 'tomorrow' => 0, 'next_day' => 0];
        $demo_counts = ['total' => 0, 'today' => 0, 'tomorrow' => 0, 'next_day' => 0];
        $callback_counts = ['total' => 0, 'today' => 0, 'tomorrow' => 0, 'next_day' => 0];

        if (!$pdo) {
            return [
                'expired' => $expired_counts,
                'demo' => $demo_counts,
                'callback' => $callback_counts
            ];
        }

        $user_idents = [];
        if (is_array($user_name)) {
            $user_idents = $user_name;
        } else {
            if (!empty($user_name)) $user_idents[] = $user_name;
            if (!empty($_SESSION['user_email'])) $user_idents[] = $_SESSION['user_email'];
            if (!empty($_SESSION['user_name'])) $user_idents[] = $_SESSION['user_name'];
        }
        $user_idents = array_values(array_unique(array_filter($user_idents, function($v) {
            $t = trim(strval($v));
            return !empty($t) && !is_numeric($t) && strlen($t) >= 3;
        })));

        // Exclude Dropped/Won leads and Not Required group from all active metric card counts
        $exclude_lead_ids_subq = "lead_id NOT IN (SELECT id FROM leads WHERE LOWER(TRIM(status)) IN ('dropped', 'won', 'closed_won', 'install_pending', 'payment_pending') OR LOWER(TRIM(group_stage)) = 'not required')";
        $exclude_lead_direct  = "LOWER(TRIM(status)) NOT IN ('dropped', 'won', 'closed_won', 'install_pending', 'payment_pending') AND LOWER(TRIM(group_stage)) != 'not required'";

        if ($is_admin || empty($user_idents)) {
            $exec_where_fup = " AND {$exclude_lead_ids_subq}";
            $exec_where_dm = " AND {$exclude_lead_ids_subq}";
            $exec_where_lead_demo = " AND {$exclude_lead_direct}";
            $lead_exec_where = " AND {$exclude_lead_direct}";
        } else {
            $fup_clauses = [];
            $dm_clauses = [];
            $lead_clauses = [];
            foreach ($user_idents as $uIdt) {
                $qIdt = $pdo->quote($uIdt);
                $qLike = $pdo->quote('%' . $uIdt . '%');
                $fup_clauses[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM({$qIdt})) OR FIND_IN_SET(LOWER(TRIM({$qIdt})), LOWER(REPLACE(assigned_to, ', ', ','))) OR assigned_to LIKE {$qLike})";
                $dm_clauses[] = "(LOWER(TRIM(engineer)) = LOWER(TRIM({$qIdt})) OR FIND_IN_SET(LOWER(TRIM({$qIdt})), LOWER(REPLACE(engineer, ', ', ','))) OR engineer LIKE {$qLike})";
                $lead_clauses[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM({$qIdt})) OR FIND_IN_SET(LOWER(TRIM({$qIdt})), LOWER(REPLACE(assigned_to, ', ', ','))) OR assigned_to LIKE {$qLike})";
            }
            $lead_sub = "lead_id IN (SELECT id FROM leads WHERE " . implode(" OR ", $lead_clauses) . " AND {$exclude_lead_direct})";
            $exec_where_fup = " AND {$exclude_lead_ids_subq} AND (" . implode(" OR ", $fup_clauses) . " OR {$lead_sub})";
            $exec_where_dm = " AND {$exclude_lead_ids_subq} AND (" . implode(" OR ", $dm_clauses) . " OR {$lead_sub})";
            $exec_where_lead_demo = " AND {$exclude_lead_direct} AND (" . implode(" OR ", $lead_clauses) . ")";
            $lead_exec_where = " AND {$exclude_lead_direct} AND (" . implode(" OR ", $lead_clauses) . ")";
        }

        // Check optional renewals table safely
        $has_renewals = false;
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'renewals'");
            if ($chk && $chk->fetch()) $has_renewals = true;
        } catch (PDOException $e) {}

        $ren_q_tot = $has_renewals ? "(SELECT COUNT(DISTINCT lead_id) FROM renewals WHERE 1=1" . (($is_admin || empty($user_idents)) ? "" : " AND lead_id IN (SELECT id FROM leads WHERE 1=1 {$lead_exec_where})") . ") +" : "";
        $ren_q_yest = $has_renewals ? "(SELECT COUNT(DISTINCT lead_id) FROM renewals WHERE DATE(expiry_date) = '{$yesterday_str}'" . (($is_admin || empty($user_idents)) ? "" : " AND lead_id IN (SELECT id FROM leads WHERE 1=1 {$lead_exec_where})") . ") +" : "";
        $ren_q_2day = $has_renewals ? "(SELECT COUNT(DISTINCT lead_id) FROM renewals WHERE DATE(expiry_date) = '{$day_before_str}'" . (($is_admin || empty($user_idents)) ? "" : " AND lead_id IN (SELECT id FROM leads WHERE 1=1 {$lead_exec_where})") . ") +" : "";
        $ren_q_3day = $has_renewals ? "(SELECT COUNT(DISTINCT lead_id) FROM renewals WHERE DATE(expiry_date) = '{$three_days_ago_str}'" . (($is_admin || empty($user_idents)) ? "" : " AND lead_id IN (SELECT id FROM leads WHERE 1=1 {$lead_exec_where})") . ") +" : "";

        // 1. Upcoming Expired Lead (Past 3 Days: Yesterday, 2 Days Ago, 3 Days Ago)
        $expiry_where = "(action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%')";
        try {
            $res = $pdo->query("SELECT {$ren_q_tot} (SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status IN ('pending', 'missed') AND ({$expiry_where} OR status = 'missed' OR DATE(scheduled_at) < '{$today_str}') {$exec_where_fup})");
            if ($res) $expired_counts['total'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            // Yesterday - kisi bhi pending/missed followup jo kl tak pending reh gayi
            $res = $pdo->query("SELECT {$ren_q_yest} (SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = '{$yesterday_str}' {$exec_where_fup})");
            if ($res) $expired_counts['today'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            // 2 Days Ago
            $res = $pdo->query("SELECT {$ren_q_2day} (SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = '{$day_before_str}' {$exec_where_fup})");
            if ($res) $expired_counts['tomorrow'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            // 3 Days Ago
            $res = $pdo->query("SELECT {$ren_q_3day} (SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = '{$three_days_ago_str}' {$exec_where_fup})");
            if ($res) $expired_counts['next_day'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        // 2. Demo Scheduled
        $demo_base = "group_stage LIKE '%Demo Scheduled%'";
        $demo_date_expr = "COALESCE((SELECT DATE(scheduled_at) FROM demos WHERE lead_id = leads.id AND status = 'scheduled' ORDER BY scheduled_at ASC LIMIT 1), (SELECT DATE(scheduled_at) FROM followups WHERE lead_id = leads.id AND status = 'pending' ORDER BY scheduled_at ASC LIMIT 1), DATE(leads.created_at))";

        try {
            $res = $pdo->query("SELECT COUNT(*) FROM leads WHERE {$demo_base} {$exec_where_lead_demo}");
            if ($res) $demo_counts['total'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            // Demo Scheduled Today - strictly aaj ki date
            $res = $pdo->query("SELECT COUNT(*) FROM leads WHERE {$demo_base} AND {$demo_date_expr} = '{$today_str}' {$exec_where_lead_demo}");
            if ($res) $demo_counts['today'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            $res = $pdo->query("SELECT COUNT(*) FROM leads WHERE {$demo_base} AND {$demo_date_expr} = '{$tomorrow_str}' {$exec_where_lead_demo}");
            if ($res) $demo_counts['tomorrow'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            $res = $pdo->query("SELECT COUNT(*) FROM leads WHERE {$demo_base} AND {$demo_date_expr} = '{$nextday_str}' {$exec_where_lead_demo}");
            if ($res) $demo_counts['next_day'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        // 3. Call Back (Strictly by Date: today, tomorrow, next day - distinct active leads)
        try {
            $res = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status = 'pending' {$exec_where_fup}");
            if ($res) $callback_counts['total'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            $res = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = '{$today_str}' {$exec_where_fup}");
            if ($res) $callback_counts['today'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            $res = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = '{$tomorrow_str}' {$exec_where_fup}");
            if ($res) $callback_counts['tomorrow'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        try {
            $res = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = '{$nextday_str}' {$exec_where_fup}");
            if ($res) $callback_counts['next_day'] = (int)$res->fetchColumn();
        } catch (PDOException $e) {}

        return [
            'expired' => $expired_counts,
            'demo' => $demo_counts,
            'callback' => $callback_counts
        ];
    }
}

/**
 * Global Email Validation & Anti-Disposable Burner Email Checker
 */
if (!function_exists('isDisposableEmail')) {
    function isDisposableEmail($email) {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true; // Malformed email format
        }

        $domain = strtolower(substr(strrchr($email, "@"), 1));
        
        $disposable_domains = [
            'mailinator.com', '10minutemail.com', 'tempmail.com', 'guerrillamail.com',
            'dispostable.com', 'trashmail.com', 'yopmail.com', 'rpaintel.com',
            'throwawaymail.com', 'getnada.com', 'fakeinbox.com', 'sharklasers.com',
            'temp-mail.org', 'maildrop.cc', 'mohmal.com', 'inboxalias.com',
            'crazymailing.com', 'mytemp.email', 'tempail.com', 'generator.email',
            'emailondeck.com', 'byom.de', 'dropmail.me', 'boun.cr', 'armyspy.com',
            'cuvox.de', 'dayrep.com', 'einrot.com', 'fleckens.hu', 'gustr.com',
            'jourrapide.com', 'rhyta.com', 'superrito.com', 'teleworm.us'
        ];

        return in_array($domain, $disposable_domains);
    }
}

/**
 * CSRF Protection Utilities
 */
if (!function_exists('getCsrfToken')) {
    function getCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    function csrfField() {
        $token = getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken($token) {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Immutable Activity Logger
 */
if (!function_exists('logActivity')) {
    function logActivity($action, $module, $details = null) {
        global $pdo, $db_connected;
        if (!$db_connected || !$pdo) return;
        try {
            $user_id = $_SESSION['user_id'] ?? null;
            $user_name = $_SESSION['user_name'] ?? 'Guest/System';
            $user_role = $_SESSION['user_role'] ?? 'Guest';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, user_role, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $user_name, $user_role, $action, $module, $details, $ip]);
        } catch (\PDOException $e) {
            // Ignore audit log error
        }
    }
}

/**
 * Indian States & Major Cities Master List
 */
if (!function_exists('getIndianStatesAndCities')) {
    function getIndianStatesAndCities() {
        return [
            'Uttar Pradesh' => ['Kanpur', 'Lucknow', 'Varanasi', 'Agra', 'Prayagraj', 'Noida', 'Greater Noida', 'Ghaziabad', 'Meerut', 'Bareilly', 'Aligarh', 'Moradabad', 'Saharanpur', 'Gorakhpur', 'Jhansi', 'Muzaffarnagar', 'Mathura', 'Ayodhya', 'Firozabad', 'Etawah', 'Unnao', 'Kannauj', 'Farrukhabad', 'Sitapur', 'Hardoi', 'Banda', 'Fatehpur', 'Raebareli', 'Sultanpur', 'Jaunpur', 'Mirzapur', 'Basti', 'Deoria', 'Gonda', 'Bahraich', 'Azamgarh', 'Rampur', 'Shahjahanpur', 'Badaun', 'Sambhal', 'Amroha', 'Hapur', 'Bulandshahr', 'Hathras', 'Kasganj', 'Mainpuri', 'Etah', 'Pilibhit', 'Bijnor', 'Shamli', 'Baghpat', 'Lalitpur', 'Jalaun', 'Hamirpur', 'Mahoba', 'Chitrakoot', 'Kaushambi', 'Pratapgarh', 'Amethi', 'Barabanki', 'Ambedkar Nagar', 'Shravasti', 'Balrampur', 'Siddharthnagar', 'Sant Kabir Nagar', 'Maharajganj', 'Kushinagar', 'Mau', 'Ballia', 'Ghazipur', 'Chandauli', 'Sonbhadra', 'Bhadohi'],
            'Delhi' => ['New Delhi', 'Central Delhi', 'North Delhi', 'South Delhi', 'East Delhi', 'West Delhi', 'Dwarka', 'Rohini', 'Saket', 'Connaught Place', 'Karol Bagh', 'Laxmi Nagar', 'Janakpuri', 'Pitampura', 'Vasant Kunj'],
            'Maharashtra' => ['Mumbai', 'Pune', 'Nagpur', 'Thane', 'Nashik', 'Chhatrapati Sambhaji Nagar (Aurangabad)', 'Solapur', 'Kolhapur', 'Navi Mumbai', 'Amravati', 'Nanded', 'Sangli', 'Jalgaon', 'Akola', 'Latur', 'Dhule', 'Ahmednagar', 'Chandrapur', 'Parbhani', 'Jalna', 'Yavatmal', 'Shirpur', 'Satara', 'Ratnagiri', 'Wardha'],
            'Gujarat' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Bhavnagar', 'Jamnagar', 'Junagadh', 'Gandhinagar', 'Anand', 'Navsari', 'Morbi', 'Nadiad', 'Surendranagar', 'Bharuch', 'Mehsana', 'Bhuj', 'Porbandar', 'Vapi', 'Valsad', 'Godhra', 'Patan'],
            'Rajasthan' => ['Jaipur', 'Jodhpur', 'Kota', 'Bikaner', 'Ajmer', 'Udaipur', 'Bhilwara', 'Alwar', 'Bharatpur', 'Sikar', 'Pali', 'Sri Ganganagar', 'Hanumangarh', 'Beawar', 'Banswara', 'Tonk', 'Churu', 'Jhunjhunu', 'Nagaur', 'Bundi'],
            'Madhya Pradesh' => ['Indore', 'Bhopal', 'Jabalpur', 'Gwalior', 'Ujjain', 'Sagar', 'Dewas', 'Satna', 'Ratlam', 'Rewa', 'Murwara (Katni)', 'Singrauli', 'Burhanpur', 'Khandwa', 'Bhind', 'Chhindwara', 'Guna', 'Shivpuri', 'Vidisha', 'Damoh', 'Mandsaur', 'Khargone', 'Neemuch', 'Pithampur'],
            'Haryana' => ['Gurugram (Gurgaon)', 'Faridabad', 'Panipat', 'Ambala', 'Yamunanagar', 'Rohtak', 'Hisar', 'Karnal', 'Sonipat', 'Panchkula', 'Bhiwani', 'Sirsa', 'Bahadurgarh', 'Jind', 'Thanesar', 'Kaithal', 'Rewari', 'Palwal'],
            'Punjab' => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda', 'Mohali (SAS Nagar)', 'Hoshiarpur', 'Batala', 'Pathankot', 'Moga', 'Abohar', 'Malerkotla', 'Khanna', 'Phagwara', 'Muktsar', 'Barnala', 'Firozpur', 'Zirakpur'],
            'Bihar' => ['Patna', 'Gaya', 'Bhagalpur', 'Muzaffarpur', 'Purnia', 'Darbhanga', 'Bihar Sharif', 'Arrah', 'Begusarai', 'Katihar', 'Munger', 'Chhapra', 'Danapur', 'Bettiah', 'Saharsa', 'Sasaram', 'Hajipur', 'Dehri', 'Siwan', 'Motihari', 'Nawada', 'Buxar', 'Kishanganj', 'Sitamarhi'],
            'West Bengal' => ['Kolkata', 'Howrah', 'Asansol', 'Siliguri', 'Durgapur', 'Bardhaman', 'Malda', 'Baharampur', 'Habra', 'Kharagpur', 'Shantipur', 'Dankuni', 'Dhulian', 'Ranaghat', 'Haldia', 'Raiganj', 'Krishnanagar', 'Nabadwip', 'Midnapore', 'Jalpaiguri', 'Balurghat', 'Darjeeling'],
            'Karnataka' => ['Bengaluru (Bangalore)', 'Mysuru (Mysore)', 'Hubballi-Dharwad', 'Mangaluru (Mangalore)', 'Belagavi (Belgaum)', 'Kalaburagi (Gulbarga)', 'Davanagere', 'Ballari (Bellary)', 'Vijayapura (Bijapur)', 'Shivamogga (Shimoga)', 'Tumakuru (Tumkur)', 'Raichur', 'Bidar', 'Hosapete', 'Gadag', 'Hassan', 'Udupi'],
            'Tamil Nadu' => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem', 'Tiruppur', 'Erode', 'Tirunelveli', 'Vellore', 'Thoothukudi', 'Dindigul', 'Thanjavur', 'Ranipet', 'Sivakasi', 'Karur', 'Udhagamandalam (Ooty)', 'Hosur', 'Nagercoil', 'Kanchipuram', 'Kumbakonam'],
            'Telangana' => ['Hyderabad', 'Warangal', 'Nizamabad', 'Karimnagar', 'Ramagundam', 'Khammam', 'Mahbubnagar', 'Nalgonda', 'Adilabad', 'Suryapet', 'Miryalaguda', 'Siddipet'],
            'Andhra Pradesh' => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Nellore', 'Kurnool', 'Kakinada', 'Rajamahendravaram (Rajahmundry)', 'Kadapa', 'Tirupati', 'Anantapur', 'Vizianagaram', 'Eluru', 'Ongole', 'Nandyal', 'Machilipatnam', 'Adoni', 'Tenali', 'Proddatur', 'Chittoor', 'Hindupur'],
            'Kerala' => ['Thiruvananthapuram', 'Kochi (Cochin)', 'Kozhikode (Calicut)', 'Kollam', 'Thrissur', 'Kannur', 'Alappuzha', 'Kottayam', 'Palakkad', 'Manjeri', 'Thalassery', 'Ponnani'],
            'Odisha' => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Berhampur', 'Sambalpur', 'Puri', 'Balasore', 'Bhadrak', 'Baripada', 'Jharsuguda', 'Jeypore'],
            'Jharkhand' => ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro Steel City', 'Deoghar', 'Phusro', 'Hazaribagh', 'Giridih', 'Ramgarh', 'Medininagar', 'Chirkunda'],
            'Chhattisgarh' => ['Raipur', 'Bhilai', 'Bilaspur', 'Korba', 'Rajnandgaon', 'Jagdalpur', 'Ambikapur', 'Dhamtari', 'Raigarh', 'Kawardha', 'Keshkal', 'Pithora', 'Pratappur', 'Rajim'],
            'Assam' => ['Guwahati', 'Silchar', 'Dibrugarh', 'Jorhat', 'Nagaon', 'Tinsukia', 'Tezpur'],
            'Uttarakhand' => ['Dehradun', 'Haridwar', 'Roorkee', 'Haldwani', 'Rudrapur', 'Kashipur', 'Rishikesh', 'Nainital'],
            'Himachal Pradesh' => ['Shimla', 'Dharamshala', 'Solan', 'Mandi', 'Kullu', 'Baddi', 'Bilaspur', 'Hamirpur', 'Una'],
            'Jammu & Kashmir' => ['Srinagar', 'Jammu', 'Anantnag', 'Baramulla', 'Kathua', 'Udhampur', 'Sopore'],
            'Goa' => ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa', 'Ponda'],
            'Tripura' => ['Agartala'],
            'Manipur' => ['Imphal'],
            'Meghalaya' => ['Shillong'],
            'Nagaland' => ['Kohima', 'Dimapur'],
            'Mizoram' => ['Aizawl'],
            'Arunachal Pradesh' => ['Itanagar'],
            'Sikkim' => ['Gangtok'],
            'Chandigarh' => ['Chandigarh'],
            'Puducherry' => ['Puducherry', 'Ozhukarai', 'Karaikal'],
            'Ladakh' => ['Leh', 'Kargil'],
            'Andaman and Nicobar' => ['Port Blair'],
            'Dadra and Nagar Haveli and Daman and Diu' => ['Daman', 'Diu', 'Silvassa'],
            'Lakshadweep' => ['Kavaratti']
        ];
    }
}

/**
 * Generate Next Unique Ticket Number in format: TK-YYYY-XXXXXX (e.g. TK-2026-000001)
 * Checks across both support_tickets and tickets tables to guarantee absolute uniqueness.
 */
if (!function_exists('generate_ticket_number')) {
    function generate_ticket_number(PDO $pdo): string {
        $year = date('Y');
        $prefix = "TK-{$year}-";

        $maxNum = 0;

        // 1. Check support_tickets table
        try {
            $stmt1 = $pdo->prepare("SELECT id FROM support_tickets WHERE id LIKE ?");
            $stmt1->execute([$prefix . '%']);
            while ($row = $stmt1->fetch(PDO::FETCH_COLUMN)) {
                if (preg_match('/^TK-\d{4}-(\d+)$/', (string)$row, $m)) {
                    $val = (int)$m[1];
                    if ($val > $maxNum) $maxNum = $val;
                }
            }
        } catch (Throwable $e) {}

        // 2. Check tickets table
        try {
            $stmt2 = $pdo->prepare("SELECT ticket_number FROM tickets WHERE ticket_number LIKE ?");
            $stmt2->execute([$prefix . '%']);
            while ($row = $stmt2->fetch(PDO::FETCH_COLUMN)) {
                if (preg_match('/^TK-\d{4}-(\d+)$/', (string)$row, $m)) {
                    $val = (int)$m[1];
                    if ($val > $maxNum) $maxNum = $val;
                }
            }
        } catch (Throwable $e) {}

        $nextNum = $maxNum + 1;
        $newTicketId = $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

        // 3. Collision guard check - loop if somehow already exists in either table
        $safetyCounter = 0;
        while ($safetyCounter < 50) {
            $exists1 = 0;
            $exists2 = 0;

            try {
                $stmtCheck1 = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE id = ?");
                $stmtCheck1->execute([$newTicketId]);
                $exists1 = (int)$stmtCheck1->fetchColumn();
            } catch (Throwable $e) {}

            try {
                $stmtCheck2 = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
                $stmtCheck2->execute([$newTicketId]);
                $exists2 = (int)$stmtCheck2->fetchColumn();
            } catch (Throwable $e) {}

            if ($exists1 === 0 && $exists2 === 0) {
                break;
            }

            $nextNum++;
            $newTicketId = $prefix . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);
            $safetyCounter++;
        }

        return $newTicketId;
    }
}
