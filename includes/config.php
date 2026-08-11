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

// Helper to check user permission
function hasAccess($module, $role) {
    // For demo purposes, we define roles navigation filters
    if ($role === 'Super Admin' || $role === 'Admin') {
        return true;
    }
    
    // Everyone is allowed to view profile settings and legal policy pages
    if (in_array($module, ['settings', 'privacy_policy', 'terms_conditions', 'refund_policy'])) {
        return true;
    }
    
    // Normalize sub-pages to their parent permissions
    $normalized_module = $module;
    if (in_array($module, ['lead_form', 'lead_details', 'lead_import'])) {
        $normalized_module = 'leads';
    } elseif (in_array($module, ['quotation_create', 'quotation_view'])) {
        $normalized_module = 'quotation';
    } elseif (in_array($module, ['bot_flow_builder'])) {
        $normalized_module = 'bot_flows';
    } elseif ($module === 'admin_reports') {
        $normalized_module = 'reports';
    }
    
    // Check custom user-specific permissions if logged in and not currently mimicking a different role
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
        'Client' => ['dashboard', 'quotation', 'payments', 'support', 'renewals', 'bot_flows'],
        'Sales Head' => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'renewals', 'reports', 'manager'],
        'Technical Head' => ['dashboard', 'support', 'installation', 'training', 'renewals', 'reports', 'support_create', 'support_edit', 'support_assign', 'support_close', 'manager'],
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

        try {
            $user_name = trim($user_name);
            $exec_where_ren = ($is_admin || empty($user_name)) ? "" : " AND lead_id IN (SELECT id FROM leads WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(" . $pdo->quote($user_name) . ")))";
            $exec_where_fup = ($is_admin || empty($user_name)) ? "" : " AND (LOWER(TRIM(assigned_to)) = LOWER(TRIM(" . $pdo->quote($user_name) . ")) OR lead_id IN (SELECT id FROM leads WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(" . $pdo->quote($user_name) . "))))";
            $exec_where_dm = ($is_admin || empty($user_name)) ? "" : " AND LOWER(TRIM(engineer)) = LOWER(TRIM(" . $pdo->quote($user_name) . "))";

            // 1. Upcoming Expired Lead
            $stmtExpTotal = $pdo->query("SELECT 
                (SELECT COUNT(*) FROM renewals WHERE 1=1 {$exec_where_ren}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status IN ('pending', 'missed') AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%' OR status = 'missed' OR scheduled_at <= NOW()) {$exec_where_fup})");
            $expired_counts['total'] = (int)$stmtExpTotal->fetchColumn();

            $stmtExpT = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM renewals WHERE DATE(expiry_date) <= ? {$exec_where_ren}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) <= ? AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%' OR status = 'missed' OR scheduled_at <= NOW()) {$exec_where_fup})");
            $stmtExpT->execute([$today_str, $today_str]);
            $expired_counts['today'] = (int)$stmtExpT->fetchColumn();

            $stmtExpTom = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM renewals WHERE DATE(expiry_date) = ? {$exec_where_ren}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = ? AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%') {$exec_where_fup})");
            $stmtExpTom->execute([$tomorrow_str, $tomorrow_str]);
            $expired_counts['tomorrow'] = (int)$stmtExpTom->fetchColumn();

            $stmtExpNext = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM renewals WHERE DATE(expiry_date) = ? {$exec_where_ren}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = ? AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%') {$exec_where_fup})");
            $stmtExpNext->execute([$nextday_str, $nextday_str]);
            $expired_counts['next_day'] = (int)$stmtExpNext->fetchColumn();

            // 2. Demo Scheduled
            $stmtDmTotal = $pdo->query("SELECT 
                (SELECT COUNT(*) FROM demos WHERE status = 'scheduled' {$exec_where_dm}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') {$exec_where_fup})");
            $demo_counts['total'] = (int)$stmtDmTotal->fetchColumn();

            $stmtDmT = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM demos WHERE status = 'scheduled' AND DATE(scheduled_at) <= ? {$exec_where_dm}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') AND DATE(scheduled_at) <= ? {$exec_where_fup})");
            $stmtDmT->execute([$today_str, $today_str]);
            $demo_counts['today'] = (int)$stmtDmT->fetchColumn();

            $stmtDmTom = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM demos WHERE status = 'scheduled' AND DATE(scheduled_at) = ? {$exec_where_dm}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') AND DATE(scheduled_at) = ? {$exec_where_fup})");
            $stmtDmTom->execute([$tomorrow_str, $tomorrow_str]);
            $demo_counts['tomorrow'] = (int)$stmtDmTom->fetchColumn();

            $stmtDmNext = $pdo->prepare("SELECT 
                (SELECT COUNT(*) FROM demos WHERE status = 'scheduled' AND DATE(scheduled_at) = ? {$exec_where_dm}) 
                + 
                (SELECT COUNT(*) FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') AND DATE(scheduled_at) = ? {$exec_where_fup})");
            $stmtDmNext->execute([$nextday_str, $nextday_str]);
            $demo_counts['next_day'] = (int)$stmtDmNext->fetchColumn();

            // 3. Call Back
            $stmtCbTotal = $pdo->query("SELECT COUNT(*) FROM followups WHERE status = 'pending' {$exec_where_fup}");
            $callback_counts['total'] = (int)$stmtCbTotal->fetchColumn();

            $stmtCbT = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) <= ? {$exec_where_fup}");
            $stmtCbT->execute([$today_str]);
            $callback_counts['today'] = (int)$stmtCbT->fetchColumn();

            $stmtCbTom = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = ? {$exec_where_fup}");
            $stmtCbTom->execute([$tomorrow_str]);
            $callback_counts['tomorrow'] = (int)$stmtCbTom->fetchColumn();

            $stmtCbNext = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = ? {$exec_where_fup}");
            $stmtCbNext->execute([$nextday_str]);
            $callback_counts['next_day'] = (int)$stmtCbNext->fetchColumn();
        } catch (PDOException $e) {}

        return [
            'expired' => $expired_counts,
            'demo' => $demo_counts,
            'callback' => $callback_counts
        ];
    }
}

