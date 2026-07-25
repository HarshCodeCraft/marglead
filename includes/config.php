<?php
/**
 * Marg ERP CRM & Lead Management System - Global Configuration
 * Contains roles, mock data, and layout helper utilities.
 */

// Set default system timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

// Security HTTP Headers
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// Start session to persist user role & theme preferences
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Connect XAMPP Database server
require_once __DIR__ . '/db.php';

// Live session synchronization with DB user records for instant role & permission changes
if (isset($db_connected) && $db_connected && isset($pdo) && $pdo) {
    $sync_user_id = $_SESSION['user_id'] ?? null;
    $sync_user_email = $_SESSION['user_email'] ?? null;

    if ($sync_user_id || $sync_user_email) {
        try {
            if ($sync_user_id) {
                $syncStmt = $pdo->prepare("SELECT id, name, email, role, status, permissions, action_permissions FROM users WHERE id = ?");
                $syncStmt->execute([$sync_user_id]);
            } else {
                $syncStmt = $pdo->prepare("SELECT id, name, email, role, status, permissions, action_permissions FROM users WHERE email = ?");
                $syncStmt->execute([$sync_user_email]);
            }

            $dbUser = $syncStmt->fetch(PDO::FETCH_ASSOC);

            if ($dbUser) {
                if ($dbUser['status'] === 'Declined' || $dbUser['status'] === 'Inactive') {
                    // Instantly block access for deactivated users
                    session_unset();
                    session_destroy();
                    session_start();
                    $_SESSION['flash_error'] = "Your account access has been updated to " . $dbUser['status'] . ". Please contact administrator.";
                } else {
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
define('APP_NAME', 'Marg Soft Solution');
define('APP_VERSION', '1.0.0');

// Default Session values for prototyping
if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'Admin'; // Default role for demonstration
}
if (!isset($_SESSION['login_role'])) {
    $_SESSION['login_role'] = 'Admin'; // Default login role for demonstration
}
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'dark'; // Default premium dark theme
}

// User Roles List
$ROLES = [
    'Super Admin' => 'Full access to settings, databases, integrations & roles.',
    'Admin' => 'Full system management with employee & client oversight.',
    'Regional Manager' => 'Regional team allocations, approvals, & visual reports.',
    'Team Leader' => 'Team allocations, follow-up monitoring, & targets tracking.',
    'Sales Executive' => 'Lead pipeline management, quotes, demo sheets, & payment logs.',
    'Telecaller' => 'Lead lists, cold/warm calling registers, and follow-ups.',
    'Support Executive' => 'Ticketing panel, troubleshooting logs, and chat/email support.',
    'Installation Engineer' => 'Client installation schedules, checklists, and sign-offs.',
    'Accounts' => 'Invoice updates, payments validation, and receipt records.'
];

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

// Load notifications dynamically from DB based on role and user id
$NOTIFICATIONS = [];
if (isset($_SESSION['user_role'])) {
    $role = $_SESSION['user_role'];
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    
    if (isset($db_connected) && $db_connected && isset($pdo) && $pdo) {
        // Mark all read check
        if (isset($_GET['action']) && $_GET['action'] === 'mark_notifs_read') {
            try {
                if ($role === 'Super Admin' || $role === 'Admin') {
                    $pdo->exec("UPDATE notifications SET unread = 0");
                } else {
                    $stmt = $pdo->prepare("UPDATE notifications SET unread = 0 WHERE role = ? OR user_id = ?");
                    $stmt->execute([$role, $user_id]);
                }
            } catch (PDOException $e) {}
            // Clean redirect back
            $clean_url = strtok($_SERVER["REQUEST_URI"], '?');
            $query_params = $_GET;
            unset($query_params['action']);
            $query_string = http_build_query($query_params);
            header("Location: " . $clean_url . ($query_string ? '?' . $query_string : ''));
            exit;
        }

        try {
            // Admins see all notifications
            if ($role === 'Super Admin' || $role === 'Admin') {
                $stmt = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 15");
            } else {
                // Standard operators see notifications targeting their role or user_id
                $stmt = $pdo->prepare("SELECT * FROM notifications WHERE role = ? OR user_id = ? ORDER BY created_at DESC LIMIT 15");
                $stmt->execute([$role, $user_id]);
            }
            $db_notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($db_notifs as $n) {
                $time_diff = time() - strtotime($n['created_at']);
                if ($time_diff < 60) {
                    $rel_time = 'Just now';
                } elseif ($time_diff < 3600) {
                    $rel_time = round($time_diff / 60) . ' mins ago';
                } elseif ($time_diff < 86400) {
                    $rel_time = round($time_diff / 3600) . ' hours ago';
                } else {
                    $rel_time = date('Y-m-d', strtotime($n['created_at']));
                }
                
                $NOTIFICATIONS[] = [
                    'id' => $n['id'],
                    'title' => htmlspecialchars($n['title']),
                    'message' => htmlspecialchars($n['message']),
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

// Default fallback notifications if list is empty
if (empty($NOTIFICATIONS)) {
    $NOTIFICATIONS = [
        [
            'id' => 1,
            'title' => 'Welcome to Marg Soft Solution',
            'message' => 'Your workspace setup is complete and database notifications are ready.',
            'time' => '10 mins ago',
            'type' => 'info',
            'unread' => true
        ]
    ];
}

// Helper to check user permission
function hasAccess($module, $role) {
    // For demo purposes, we define roles navigation filters
    if ($role === 'Super Admin' || $role === 'Admin') {
        return true;
    }
    
    // Everyone is allowed to view/edit their own profile settings page
    if ($module === 'settings') {
        return true;
    }
    
    // Normalize sub-pages to their parent permissions
    $normalized_module = $module;
    if (in_array($module, ['lead_form', 'lead_details', 'lead_import'])) {
        $normalized_module = 'leads';
    } elseif (in_array($module, ['quotation_create', 'quotation_view'])) {
        $normalized_module = 'quotation';
    } elseif ($module === 'admin_reports') {
        $normalized_module = 'reports';
    }
    
    // Check custom user-specific permissions if logged in and not currently mimicking a different role
    $login_role = isset($_SESSION['login_role']) ? $_SESSION['login_role'] : $role;
    if (isset($_SESSION['user_permissions']) && is_array($_SESSION['user_permissions']) && $role === $login_role) {
        return in_array($normalized_module, $_SESSION['user_permissions']);
    }
    
    $permissions = [
        'Regional Manager' => ['dashboard', 'leads', 'pipeline', 'demo', 'quotation', 'payments', 'renewals', 'reports', 'manager'],
        'Team Leader' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'renewals', 'manager'],
        'Sales Executive' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'employee'],
        'Telecaller' => ['dashboard', 'leads', 'followups', 'employee'],
        'Support Executive' => ['dashboard', 'support', 'employee'],
        'Installation Engineer' => ['dashboard', 'installation', 'training', 'employee'],
        'Accounts' => ['dashboard', 'payments', 'quotation', 'renewals']
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
                return $perms;
            }
        }
        
        $role = is_array($user) ? ($user['role'] ?? '') : (string)$user;
        if ($role === 'Super Admin' || $role === 'Admin') {
            return ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
        }
        
        $role_permissions = [
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
