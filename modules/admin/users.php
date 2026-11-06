<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

// -----------------------------------------------------------------------------
// 1. RBAC Security Gate: Strictly restricted to System Administrators
// -----------------------------------------------------------------------------
$role = $_SESSION['user_role'] ?? 'Sales Executive';
$current_user_id = intval($_SESSION['user_id'] ?? 0);
$is_admin = isSystemAdminRole($role) || ($role === 'Super Admin' || $role === 'Admin');

if (!$is_admin) {
    echo "<div class='card p-6 text-center' style='max-width: 500px; margin: 4rem auto; border: 1px solid var(--danger); background: var(--bg-card); border-radius: var(--border-radius-sm);'>
        <i data-lucide='shield-alert' style='width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1rem auto;'></i>
        <h3 class='text-lg font-bold mb-2' style='color: var(--danger); font-family: var(--font-heading);'>Access Denied</h3>
        <p class='text-muted text-sm mb-4'>The Users & Permissions Management console is strictly restricted to Administrators.</p>
        <a href='index.php?page=dashboard' class='btn btn-primary text-xs font-semibold'>Return to Dashboard</a>
    </div>";
    return;
}

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper to resolve user permissions with role fallback
if (!function_exists('getUserPermissions')) {
    function getUserPermissions($user) {
        if (!empty($user['permissions'])) {
            $perms = json_decode($user['permissions'], true);
            if (is_array($perms)) {
                return $perms;
            }
        }
        
        // Fallback to role-based default permissions
        $role = $user['role'] ?? '';
        if ($role === 'Super Admin' || $role === 'Admin') {
            return ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
        }
        
        $role_permissions = [
            'Regional Manager'     => ['dashboard', 'leads', 'pipeline', 'demo', 'quotation', 'payments', 'renewals', 'reports'],
            'Team Leader'          => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'renewals'],
            'Sales Executive'      => ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments'],
            'Telecaller'           => ['dashboard', 'leads', 'followups'],
            'Support Executive'    => ['dashboard', 'support', 'support_create', 'support_edit'],
            'Installation Engineer'=> ['dashboard', 'installation', 'training'],
            'Accounts'             => ['dashboard', 'payments', 'quotation', 'renewals']
        ];
        
        return isset($role_permissions[$role]) ? $role_permissions[$role] : ['dashboard'];
    }
}

// Ensure database schema integrity for inbox_visibility column
if ($db_connected && $pdo) {
    try {
        $chkCols = $pdo->query("SHOW COLUMNS FROM users");
        $allCols = $chkCols ? $chkCols->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!in_array('inbox_visibility', $allCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN inbox_visibility VARCHAR(50) DEFAULT 'default'");
        }
        if (!in_array('action_permissions', $allCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN action_permissions TEXT NULL");
        }
        if (!in_array('permissions', $allCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL");
        }
    } catch (\Throwable $t) {
        error_log('Users schema auto-check notice: ' . $t->getMessage());
    }
}

// -----------------------------------------------------------------------------
// 2. Process Admin POST actions (Update permissions & Create operator)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    
    // CSRF token validation
    $post_csrf = $_POST['csrf_token'] ?? '';
    if (!empty($_POST['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $post_csrf)) {
        $_SESSION['flash_error'] = "Security validation error. Invalid CSRF token. Please refresh and try again.";
        header("Location: index.php?page=admin_users");
        exit;
    }

    if ($act === 'update_permissions') {
        $userId = intval($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $requested_role = trim($_POST['role'] ?? '');
        $inbox_visibility = trim($_POST['inbox_visibility'] ?? 'default');
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';
        $selected_modules = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : [];
        $selected_actions = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : [];

        // Validation
        if (empty($name) || empty($email)) {
            $_SESSION['flash_error'] = "Operator name and email address are required.";
            header("Location: index.php?page=admin_users&user_id=" . $userId);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Please provide a valid email address.";
            header("Location: index.php?page=admin_users&user_id=" . $userId);
            exit;
        }

        // Self-Protection: Admin cannot demote or deactivate their own active account
        if ($userId === $current_user_id) {
            if ($status !== 'Active') {
                $_SESSION['flash_error'] = "Security restriction: You cannot deactivate or suspend your own active administrator account.";
                header("Location: index.php?page=admin_users&user_id=" . $userId);
                exit;
            }
            if (!in_array($requested_role, ['Super Admin', 'Admin'])) {
                $_SESSION['flash_error'] = "Security restriction: You cannot revoke administrator rights from your own active session.";
                header("Location: index.php?page=admin_users&user_id=" . $userId);
                exit;
            }
        }

        // Auto-synchronize shared permission mappings
        syncPermissionMappings($selected_modules, $selected_actions);
        
        if ($db_connected && $pdo) {
            try {
                $permissions_json = json_encode(array_values(array_unique($selected_modules)));
                $actions_json = json_encode(array_values(array_unique($selected_actions)));
                
                // Fetch previous user status for email triggers & role protection
                $prevStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
                $prevStmt->execute([$userId]);
                $prevUser = $prevStmt->fetch(PDO::FETCH_ASSOC);

                if (!$prevUser) {
                    $_SESSION['flash_error'] = "Operator account not found in database.";
                    header("Location: index.php?page=admin_users");
                    exit;
                }

                // Super Admin protection: Non-superadmin cannot modify master Super Admin account
                if ($prevUser['role'] === 'Super Admin' && $role !== 'Super Admin' && $userId !== $current_user_id) {
                    $_SESSION['flash_error'] = "Security restriction: Only a Master Super Admin can modify another Super Admin account.";
                    header("Location: index.php?page=admin_users&user_id=" . $userId);
                    exit;
                }
                
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, inbox_visibility = ?, status = ?, permissions = ?, action_permissions = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $hash, $requested_role, $inbox_visibility, $status, $permissions_json, $actions_json, $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, inbox_visibility = ?, status = ?, permissions = ?, action_permissions = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $requested_role, $inbox_visibility, $status, $permissions_json, $actions_json, $userId]);
                }

                // Trigger approval/decline email safely
                if ($prevUser && $prevUser['status'] === 'Pending Approval' && $status !== 'Pending Approval') {
                    try {
                        Mailer::sendUserApproval($email, $name, $status);
                    } catch (\Throwable $mErr) {
                        error_log('Mailer notice on user status update: ' . $mErr->getMessage());
                    }
                }
                
                // Log notification
                try {
                    $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Operator Details Updated', ?, 'info')");
                    $actionMsg = "Admin updated user details & privileges (User ID: " . $userId . ", Name: " . $name . ", Role: " . $requested_role . ", Status: " . $status . ")";
                    $logStmt->execute([$actionMsg]);
                } catch (\Throwable $nErr) {}
                
                // If updated user is currently logged-in user, refresh their session
                if ($email === ($_SESSION['user_email'] ?? '') || $userId === $current_user_id) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = $requested_role;
                    $_SESSION['user_permissions'] = $selected_modules;
                    $_SESSION['user_action_permissions'] = $selected_actions;
                }
                
                $_SESSION['flash_success'] = "Operator details and privileges updated and synchronized successfully across all modules.";
                header("Location: index.php?page=admin_users&user_id=" . $userId);
                exit;
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Operator update failure: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = "Database connection offline. Changes could not be saved.";
            header("Location: index.php?page=admin_users&user_id=" . $userId);
            exit;
        }
    } elseif ($act === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? 'Sales Executive');
        $custom_password = trim($_POST['password'] ?? '');
        $password = !empty($custom_password) ? $custom_password : 'password123';
        
        if (empty($name) || empty($email)) {
            $_SESSION['flash_error'] = "Name and email address are required to register an operator.";
            header("Location: index.php?page=admin_users");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Please provide a valid email address.";
            header("Location: index.php?page=admin_users");
            exit;
        }

        if ($db_connected && $pdo) {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $_SESSION['flash_error'] = "An employee or operator with this email address already exists.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $default_perms = getUserPermissions(['role' => $role]);
                    $default_actions = getUserActionPermissions(['role' => $role]);
                    $permissions_json = json_encode($default_perms);
                    $actions_json = json_encode($default_actions);
                    
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status, permissions, action_permissions, inbox_visibility) VALUES (?, ?, ?, ?, 'Active', ?, ?, 'default')");
                    $stmt->execute([$name, $email, $hash, $role, $permissions_json, $actions_json]);
                    $newId = $pdo->lastInsertId();
                    
                    // Log notification
                    try {
                        $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'New Operator Registered', ?, 'success')");
                        $actionMsg = "Admin added new operator: " . $name . " (Role: " . $role . ")";
                        $logStmt->execute([$actionMsg]);
                    } catch (\Throwable $nErr) {}
                    
                    $_SESSION['flash_success'] = "New user operator registered successfully. Password set to: " . htmlspecialchars($password);
                    header("Location: index.php?page=admin_users&user_id=" . $newId);
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Failed to add operator: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_error'] = "Database connection offline. Could not register operator.";
            header("Location: index.php?page=admin_users");
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// 3. Process Admin Status & Deletion actions (with protection against self/master deletion)
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && isset($_GET['id'])) {
    $userId = intval($_GET['id']);
    $act = $_GET['action'];

    // Verify token for state modification actions
    $req_token = $_GET['token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $req_token)) {
        $_SESSION['flash_error'] = "Security validation failed. Invalid action authorization token.";
        header("Location: index.php?page=admin_users");
        exit;
    }

    if ($db_connected && $pdo) {
        try {
            // Fetch target user details
            $targetStmt = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE id = ?");
            $targetStmt->execute([$userId]);
            $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $_SESSION['flash_error'] = "Operator account not found.";
                header("Location: index.php?page=admin_users");
                exit;
            }

            if ($act === 'approve_user') {
                $default_perms = getUserPermissions(['role' => $targetUser['role']]);
                $default_actions = getUserActionPermissions(['role' => $targetUser['role']]);
                $permissions_json = json_encode($default_perms);
                $actions_json = json_encode($default_actions);
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Active', permissions = ?, action_permissions = ? WHERE id = ?");
                $stmt->execute([$permissions_json, $actions_json, $userId]);
                
                try {
                    Mailer::sendUserApproval($targetUser['email'], $targetUser['name'], 'Active');
                } catch (\Throwable $mErr) {
                    error_log('Mailer error on approve: ' . $mErr->getMessage());
                }
                $_SESSION['flash_success'] = "Operator approved and activated successfully.";
            } elseif ($act === 'decline_user') {
                // Prevent self-decline
                if ($userId === $current_user_id) {
                    $_SESSION['flash_error'] = "Security restriction: You cannot suspend your own active administrator account.";
                    header("Location: index.php?page=admin_users&user_id=" . $userId);
                    exit;
                }
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Declined' WHERE id = ?");
                $stmt->execute([$userId]);
                
                try {
                    Mailer::sendUserApproval($targetUser['email'], $targetUser['name'], 'Declined');
                } catch (\Throwable $mErr) {
                    error_log('Mailer error on decline: ' . $mErr->getMessage());
                }
                $_SESSION['flash_success'] = "Operator account marked as Declined/Suspended.";
            } elseif ($act === 'delete_user') {
                // 1. Prevent self-deletion
                if ($userId === $current_user_id) {
                    $_SESSION['flash_error'] = "Security restriction: You cannot delete your own active administrator account.";
                    header("Location: index.php?page=admin_users&user_id=" . $userId);
                    exit;
                }

                // 2. Prevent deletion of Master Super Admin (ID: 1 or Super Admin)
                if ($targetUser['role'] === 'Super Admin') {
                    $_SESSION['flash_error'] = "Security restriction: The Master Super Admin account cannot be deleted.";
                    header("Location: index.php?page=admin_users&user_id=" . $userId);
                    exit;
                }

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $_SESSION['flash_success'] = "Operator (" . htmlspecialchars($targetUser['name']) . ") deleted successfully.";
            }
            
            // Log notification
            try {
                $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Operator Status Modified', ?, 'info')");
                $actionMsg = "Admin performed " . str_replace('_user', '', $act) . " on operator (ID: " . $userId . ", Name: " . $targetUser['name'] . ")";
                $logStmt->execute([$actionMsg]);
            } catch (\Throwable $nErr) {}
            
            header("Location: index.php?page=admin_users" . ($act !== 'delete_user' ? "&user_id=" . $userId : ""));
            exit;
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Operator action failure: " . $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------------------
// 4. Fetch System Users Dynamically (Including action_permissions & inbox_visibility)
// -----------------------------------------------------------------------------
$users_list = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("
            SELECT id, name, email, role, status, permissions, action_permissions, inbox_visibility 
            FROM users 
            WHERE LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') 
              AND LOWER(role) NOT LIKE 'tenant%' 
            ORDER BY id DESC
        ");
        $users_list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('Users query error: ' . $e->getMessage());
        $users_list = [];
    }
}

// Compute status counts for live filters
$count_all = count($users_list);
$count_active = 0;
$count_pending = 0;
$count_declined = 0;

foreach ($users_list as $uItem) {
    $st = $uItem['status'] ?? 'Active';
    if ($st === 'Active') $count_active++;
    elseif ($st === 'Pending Approval') $count_pending++;
    elseif ($st === 'Declined') $count_declined++;
}

// Determine selected user
$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$selected_user = null;

if ($selected_user_id) {
    foreach ($users_list as $u) {
        if (intval($u['id']) === $selected_user_id) {
            $selected_user = $u;
            break;
        }
    }
}

// If no user is selected and the list is not empty, default to the first user
if (!$selected_user && !empty($users_list)) {
    $selected_user = $users_list[0];
    $selected_user_id = intval($selected_user['id']);
}

// Modules list for permissions matrix with rich color grading & icons
$modules_meta = [
    'dashboard'             => ['label' => 'Dashboard Workspace',            'icon' => 'layout-dashboard', 'color' => '#6366f1'],
    'leads'                 => ['label' => 'Leads Directory',                'icon' => 'users',            'color' => '#3b82f6'],
    'pipeline'              => ['label' => 'Kanban Pipeline',                'icon' => 'git-commit',       'color' => '#8b5cf6'],
    'followups'             => ['label' => 'Follow-up Planner',              'icon' => 'calendar-clock',   'color' => '#ec4899'],
    'demo'                  => ['label' => 'Demos & Feedback',               'icon' => 'presentation',     'color' => '#f59e0b'],
    'quotation'             => ['label' => 'Quotations Proposal',            'icon' => 'file-text',        'color' => '#10b981'],
    'payments'              => ['label' => 'Invoicing & Receipts',           'icon' => 'credit-card',      'color' => '#06b6d4'],
    'bank_accounts'         => ['label' => 'Bank & QR Payment Details',      'icon' => 'landmark',         'color' => '#14b8a6'],
    'installation'          => ['label' => 'Installation Checklist',         'icon' => 'wrench',           'color' => '#f97316'],
    'training'              => ['label' => 'Operator Training',              'icon' => 'graduation-cap',   'color' => '#84cc16'],
    'support'               => ['label' => 'Helpdesk View Tickets',          'icon' => 'life-buoy',        'color' => '#ef4444'],
    'support_create'        => ['label' => 'Helpdesk: Generate Tickets',     'icon' => 'plus-circle',      'color' => '#10b981'],
    'support_edit'          => ['label' => 'Helpdesk: Edit Tickets',         'icon' => 'edit-3',           'color' => '#8b5cf6'],
    'support_assign'        => ['label' => 'Helpdesk: Assign/Transfer',      'icon' => 'user-check',       'color' => '#06b6d4'],
    'support_close'         => ['label' => 'Helpdesk: Close Tickets',        'icon' => 'check-circle-2',   'color' => '#6366f1'],
    'renewals'              => ['label' => 'Renewals Manager',               'icon' => 'refresh-cw',       'color' => '#0284c7'],
    'reports'               => ['label' => 'Reports & Audits',               'icon' => 'bar-chart-3',      'color' => '#a855f7'],
    'team_inbox'            => ['label' => 'Team Inbox & Live Chat',         'icon' => 'message-square',   'color' => '#10b981'],
    'broadcast_campaigns'   => ['label' => 'WhatsApp Broadcast & Templates', 'icon' => 'send',             'color' => '#2563eb'],
    'bulk_broadcast'        => ['label' => 'Bulk Marketing Broadcast Engine','icon' => 'upload-cloud',     'color' => '#8b5cf6'],
    'bot_flows'             => ['label' => 'WhatsApp Bots & Auto-Responders','icon' => 'bot',              'color' => '#f59e0b'],
    'merchant_waba_settings'=> ['label' => 'WhatsApp Cloud WABA Setup',      'icon' => 'qr-code',          'color' => '#ec4899'],
    'clients'               => ['label' => 'Client Directory & Customers',   'icon' => 'user-check',       'color' => '#06b6d4'],
    'admin_users'           => ['label' => 'User Management & Operators',    'icon' => 'shield-check',     'color' => '#6366f1'],
    'admin_permissions'     => ['label' => 'Employee Permissions Matrix',    'icon' => 'lock',             'color' => '#ef4444'],
    'privacy_policy'        => ['label' => 'Privacy Policy Document',        'icon' => 'file-text',        'color' => '#10b981'],
    'terms_conditions'      => ['label' => 'Terms & Conditions Agreement',   'icon' => 'file-check',       'color' => '#3b82f6'],
    'refund_policy'         => ['label' => 'Refund & Cancellation Policy',   'icon' => 'rotate-ccw',       'color' => '#f59e0b'],
    'settings'              => ['label' => 'Control Settings',               'icon' => 'sliders',          'color' => '#64748b']
];

$actions_meta = [
    'can_view'          => ['label' => 'View Records & Registers',  'icon' => 'eye',          'color' => '#3b82f6', 'desc' => 'Read-only view access across modules'],
    'can_create'        => ['label' => 'Create & Add Records',      'icon' => 'plus-circle',  'color' => '#10b981', 'desc' => 'Add new leads, proposals & records'],
    'can_edit'          => ['label' => 'Edit & Modify Records',     'icon' => 'edit-3',       'color' => '#8b5cf6', 'desc' => 'Modify customer & lead file records'],
    'can_delete'        => ['label' => 'Delete / Drop Records',     'icon' => 'trash-2',      'color' => '#ef4444', 'desc' => 'Delete entries & drop customer files'],
    'can_update_status' => ['label' => 'Update Pipeline & Approve', 'icon' => 'refresh-cw',  'color' => '#f59e0b', 'desc' => 'Advance pipeline stages & approvals'],
    'can_share'         => ['label' => 'Share Proposals & Alerts',  'icon' => 'share-2',      'color' => '#06b6d4', 'desc' => 'Dispatch emails & WhatsApp messages'],
    'can_bulk_upload'   => ['label' => 'Bulk Lead Upload',          'icon' => 'upload-cloud', 'color' => '#ec4899', 'desc' => 'Import CSV / Excel lead directories'],
    'can_export'        => ['label' => 'Export Reports & Data',     'icon' => 'download',     'color' => '#6366f1', 'desc' => 'Download system registers & reports'],
    'can_assign'        => ['label' => 'Assign & Transfer Accounts','icon' => 'user-check',   'color' => '#14b8a6', 'desc' => 'Reassign leads & transfer ownership']
];
?>

<style>
.users-permissions-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.8fr);
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 992px) {
    .users-permissions-grid {
        grid-template-columns: 1fr !important;
    }
}
.perm-tab-btn-active {
    background: linear-gradient(135deg, var(--primary), #8b5cf6) !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
    border: none !important;
}
.perm-tab-btn-inactive {
    background: var(--border-card) !important;
    color: var(--text-muted) !important;
    border: 1px solid var(--border-color) !important;
}
.perm-card-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.6rem;
    max-height: 380px;
    overflow-y: auto;
    padding-right: 4px;
}
@media (max-width: 600px) {
    .perm-card-grid {
        grid-template-columns: 1fr !important;
    }
}
.perm-item-card {
    border-radius: 8px;
    padding: 0.65rem 0.8rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    user-select: none;
}
.operator-row.active {
    background-color: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.09) !important;
    border-left: 3px solid var(--primary);
}
.user-filter-pill {
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 20px;
    cursor: pointer;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    transition: all 0.15s ease;
}
.user-filter-pill.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
</style>

<div class="users-permissions-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-5 flex-wrap gap-3">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.65rem; font-weight: 700;" class="mb-1">Users & Permissions Matrix</h2>
            <p class="text-muted text-sm">Configure system operators, maintain access security, and map individual module and action privileges.</p>
        </div>
        <button class="btn btn-primary text-sm font-semibold" onclick="window.openModal('create-user-modal');">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Add User Operator</span>
        </button>
    </div>

    <!-- Layout Grid: Users List + Permissions Matrix -->
    <div class="users-permissions-grid">
        
        <!-- Left: Users List Card with Live Search & Status Filters -->
        <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
            <div class="p-3 border-bottom" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
                <div class="flex justify-between align-center mb-2">
                    <h3 class="text-xs uppercase font-bold text-muted m-0">Registered Operators (<?php echo $count_all; ?>)</h3>
                    <?php if ($count_pending > 0): ?>
                        <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-size: 10px; font-weight: 700;">
                            <?php echo $count_pending; ?> Pending Approval
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Instant Search Input -->
                <div style="position: relative; margin-bottom: 8px;">
                    <i data-lucide="search" style="position: absolute; left: 9px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted);"></i>
                    <input type="text" id="userSearchInput" class="form-control text-xs" style="padding-left: 28px; height: 32px; font-size: 11.5px;" placeholder="Search by name, email, EMP ID, role..." onkeyup="filterOperatorsList()">
                </div>

                <!-- Status Filter Pills -->
                <div class="flex gap-1 flex-wrap" id="statusFilterPills">
                    <span class="user-filter-pill active" onclick="setUserStatusFilter('all', this)">All (<?php echo $count_all; ?>)</span>
                    <span class="user-filter-pill" onclick="setUserStatusFilter('Active', this)">Active (<?php echo $count_active; ?>)</span>
                    <span class="user-filter-pill" onclick="setUserStatusFilter('Pending Approval', this)">Pending (<?php echo $count_pending; ?>)</span>
                    <span class="user-filter-pill" onclick="setUserStatusFilter('Declined', this)">Declined (<?php echo $count_declined; ?>)</span>
                </div>
            </div>

            <!-- Operators Scrollable Table -->
            <div class="table-responsive" style="max-height: 620px; overflow-y: auto;">
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="padding: 8px 12px; font-size: 11px;">Operator / Account</th>
                            <th style="padding: 8px 12px; font-size: 11px;">Role</th>
                            <th style="padding: 8px 12px; font-size: 11px; text-align: right;">Status / Actions</th>
                        </tr>
                    </thead>
                    <tbody id="operatorsTableBody">
                        <?php if (!empty($users_list)): ?>
                            <?php foreach ($users_list as $u): 
                                $isActiveRow = ($selected_user_id === intval($u['id']));
                                $statusStr = $u['status'] ?? 'Active';
                            ?>
                                <tr class="operator-row <?php echo $isActiveRow ? 'active' : ''; ?>"
                                    data-id="<?php echo $u['id']; ?>"
                                    data-name="<?php echo htmlspecialchars(strtolower($u['name'])); ?>"
                                    data-email="<?php echo htmlspecialchars(strtolower($u['email'])); ?>"
                                    data-role="<?php echo htmlspecialchars(strtolower($u['role'])); ?>"
                                    data-status="<?php echo htmlspecialchars($statusStr); ?>">
                                    <td style="padding: 10px 12px;">
                                        <a href="index.php?page=admin_users&user_id=<?php echo $u['id']; ?>" style="color: inherit; text-decoration: none; display: block;">
                                            <span class="text-xs font-semibold text-primary">EMP-<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                            <span class="font-semibold text-sm block <?php echo $isActiveRow ? 'text-primary' : ''; ?>"><?php echo htmlspecialchars($u['name']); ?></span>
                                            <span class="text-xs text-muted block" style="font-size: 11px;"><?php echo htmlspecialchars($u['email']); ?></span>
                                        </a>
                                    </td>
                                    <td class="text-xs font-semibold" style="vertical-align: middle; padding: 10px 8px;">
                                        <?php echo htmlspecialchars($u['role']); ?>
                                    </td>
                                    <td style="vertical-align: middle; padding: 10px 12px; text-align: right;">
                                        <div class="flex flex-col gap-1 align-end">
                                            <?php if ($statusStr === 'Active'): ?>
                                                <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-size: 10px;">Active</span>
                                            <?php elseif ($statusStr === 'Declined'): ?>
                                                <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); font-size: 10px;">Declined</span>
                                            <?php else: ?>
                                                <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-size: 10px; font-weight: 700;"><?php echo htmlspecialchars($statusStr); ?></span>
                                            <?php endif; ?>
                                            
                                            <!-- Action Triggers -->
                                            <?php if ($u['role'] !== 'Super Admin' && intval($u['id']) !== 1): ?>
                                                <div class="flex gap-1" style="margin-top: 4px;">
                                                    <?php if ($statusStr === 'Pending Approval'): ?>
                                                        <a href="index.php?page=admin_users&user_id=<?php echo $u['id']; ?>" class="btn btn-primary text-xs font-semibold" style="padding: 0.2rem 0.5rem; font-size: 10px;">Configure</a>
                                                    <?php elseif (intval($u['id']) !== $current_user_id): ?>
                                                        <a href="index.php?page=admin_users&action=delete_user&id=<?php echo $u['id']; ?>&token=<?php echo $csrf_token; ?>" 
                                                           class="btn btn-secondary text-xs" 
                                                           style="padding: 0.15rem 0.4rem; font-size: 10px; border-color: var(--danger); color: var(--danger);" 
                                                           onclick="return confirm('Permanently delete operator <?php echo htmlspecialchars(addslashes($u['name'])); ?>?');">Delete</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="noOperatorsRow">
                                <td colspan="3" class="text-center py-6 text-muted text-xs">
                                    No registered staff operators found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Permissions Grid Matrix & Operator Configuration -->
        <div class="card p-5" style="border: 1px solid var(--border-color);">
            <?php if ($selected_user): ?>
                <form action="index.php?page=admin_users" method="POST" id="operatorPermissionsForm">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="flex justify-between align-center mb-4 flex-wrap gap-2">
                        <div>
                            <div class="flex align-center gap-2">
                                <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 10px; font-weight: 700;">EMP-<?php echo str_pad($selected_user['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                <h3 class="text-base font-bold m-0"><?php echo htmlspecialchars($selected_user['name']); ?></h3>
                            </div>
                            <p class="text-xs text-muted m-0 mt-1">Configure profile credentials, team inbox visibility, and granular module access.</p>
                        </div>
                        <div>
                            <?php if ($selected_user['status'] === 'Pending Approval'): ?>
                                <input type="hidden" name="status" value="Active">
                                <button type="submit" class="btn btn-primary text-xs" style="padding: 0.45rem 0.9rem; background-color: var(--success); font-weight: 600;">Approve & Save Privileges</button>
                                <a href="index.php?page=admin_users&action=decline_user&id=<?php echo $selected_user['id']; ?>&token=<?php echo $csrf_token; ?>" class="btn btn-secondary text-xs" style="padding: 0.45rem 0.8rem; border-color: var(--danger); color: var(--danger); margin-left: 0.25rem; font-weight: 600; text-decoration: none; display: inline-block;">Decline</a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary text-xs font-semibold" style="padding: 0.5rem 1.1rem;">Save Privileges</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Operator Details Inputs Grid -->
                    <div class="card p-4 mb-4" style="background-color: var(--border-card); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                        <h4 class="text-xs uppercase text-muted font-bold mb-3">Operator Profile & Credentials</h4>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.9rem;">
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Full Name *</label>
                                <input type="text" name="name" class="form-control text-xs" style="height: 36px;" value="<?php echo htmlspecialchars($selected_user['name']); ?>" required>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Email Address *</label>
                                <input type="email" name="email" class="form-control text-xs" style="height: 36px;" value="<?php echo htmlspecialchars($selected_user['email']); ?>" required>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Update Password (leave blank to retain current)</label>
                                <input type="password" name="password" class="form-control text-xs" style="height: 36px;" placeholder="Enter new password">
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Access Role *</label>
                                <select name="role" class="form-control text-xs font-semibold" style="height: 36px;" required>
                                    <?php 
                                    $available_edit_roles = (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== (defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata')) ? $EMPLOYEE_ROLES : $ROLES;
                                    if ($selected_user['role'] === 'Super Admin' && !isset($available_edit_roles['Super Admin'])) {
                                        $available_edit_roles = array_merge(['Super Admin' => 'Master Super Admin'], $available_edit_roles);
                                    }
                                    foreach ($available_edit_roles as $roleName => $desc): 
                                    ?>
                                        <option value="<?php echo $roleName; ?>" <?php echo ($selected_user['role'] === $roleName) ? 'selected' : ''; ?>><?php echo $roleName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Account Status *</label>
                                <select name="status" class="form-control text-xs font-semibold" style="height: 36px;" required>
                                    <option value="Active" <?php echo ($selected_user['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="Pending Approval" <?php echo ($selected_user['status'] === 'Pending Approval') ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="Declined" <?php echo ($selected_user['status'] === 'Declined') ? 'selected' : ''; ?>>Declined / Suspended</option>
                                </select>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Team Inbox Chat Visibility</label>
                                <select name="inbox_visibility" class="form-control text-xs font-semibold" style="height: 36px;">
                                    <?php $curVis = $selected_user['inbox_visibility'] ?? 'default'; ?>
                                    <option value="default" <?php echo ($curVis === 'default') ? 'selected' : ''; ?>>Auto / Role Default</option>
                                    <option value="all" <?php echo ($curVis === 'all') ? 'selected' : ''; ?>>Full Company Access (All Chats)</option>
                                    <option value="support_all" <?php echo ($curVis === 'support_all') ? 'selected' : ''; ?>>All Support Tickets (Hide Sales)</option>
                                    <option value="support_assigned" <?php echo ($curVis === 'support_assigned') ? 'selected' : ''; ?>>My Assigned Tickets Only</option>
                                    <option value="sales_all" <?php echo ($curVis === 'sales_all') ? 'selected' : ''; ?>>All Sales & AI Leads (Hide Support)</option>
                                    <option value="sales_assigned" <?php echo ($curVis === 'sales_assigned') ? 'selected' : ''; ?>>My Assigned Leads Only</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if ($selected_user['role'] === 'Admin' || $selected_user['role'] === 'Super Admin'): ?>
                        <div class="badge w-full text-center py-2 flex align-center justify-center gap-2 mb-3" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); display: flex; border-radius: var(--border-radius-sm); border: 1px dashed rgba(59, 130, 246, 0.3);">
                            <i data-lucide="shield-check" style="width: 15px; height: 15px;"></i>
                            <span class="font-semibold text-xs">Administrator accounts automatically retain master access across all module directories.</span>
                        </div>
                    <?php endif; ?>

                    <!-- Tab Switcher Header -->
                    <div class="flex justify-between align-center mb-3 mt-3 border-bottom pb-2" style="border-bottom: 1px solid var(--border-color);">
                        <div class="flex gap-2">
                            <button type="button" class="btn text-xs font-bold perm-tab-btn perm-tab-btn-active" id="btn-tab-modules" onclick="switchPermTab('modules')" style="padding: 0.45rem 0.9rem; border-radius: 8px;">
                                <i data-lucide="layout-grid" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i>
                                <span>Workspace Modules (<?php echo count($modules_meta); ?>)</span>
                            </button>
                            <button type="button" class="btn text-xs font-bold perm-tab-btn perm-tab-btn-inactive" id="btn-tab-actions" onclick="switchPermTab('actions')" style="padding: 0.45rem 0.9rem; border-radius: 8px;">
                                <i data-lucide="shield-check" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i>
                                <span>Action Privileges (<?php echo count($actions_meta); ?>)</span>
                            </button>
                        </div>
                        <div class="flex gap-1">
                            <button type="button" class="btn btn-secondary text-xs font-semibold" style="padding: 0.3rem 0.6rem; font-size: 11px;" onclick="toggleCurrentTabCheckboxes(true)">Select All</button>
                            <button type="button" class="btn btn-secondary text-xs font-semibold" style="padding: 0.3rem 0.6rem; font-size: 11px;" onclick="toggleCurrentTabCheckboxes(false)">Clear All</button>
                        </div>
                    </div>

                    <!-- Tab 1: Workspace Modules (Color-Graded Cards) -->
                    <div id="perm-tab-content-modules" class="perm-tab-content">
                        <div class="perm-card-grid">
                            <?php 
                            $current_perms = getUserPermissions($selected_user);
                            foreach ($modules_meta as $key => $item): 
                                $isChecked = in_array($key, $current_perms);
                                $isDisabled = ($selected_user['role'] === 'Admin' || $selected_user['role'] === 'Super Admin');
                                $color = $item['color'];
                            ?>
                                <label class="perm-item-card border perm-card-module" 
                                       data-color="<?php echo $color; ?>"
                                       style="background: <?php echo $isChecked ? "{$color}12" : 'var(--bg-app)'; ?>; border: <?php echo $isChecked ? "2px solid {$color}" : '1px solid var(--border-color)'; ?>;">
                                    <div class="flex align-center gap-2" style="overflow: hidden;">
                                        <div style="width: 26px; height: 26px; border-radius: 6px; background: <?php echo "{$color}20"; ?>; color: <?php echo $color; ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <i data-lucide="<?php echo $item['icon']; ?>" style="width: 14px; height: 14px;"></i>
                                        </div>
                                        <span class="font-bold text-xs perm-label-text" style="color: <?php echo $isChecked ? 'var(--text-main)' : 'var(--text-muted)'; ?>; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($item['label']); ?></span>
                                    </div>
                                    <div class="flex align-center gap-2 flex-shrink-0">
                                        <span class="badge text-xs perm-status-badge" style="font-size: 9px; padding: 2px 6px; --badge-bg: <?php echo $isChecked ? "{$color}25" : 'var(--border-card)'; ?>; --badge-color: <?php echo $isChecked ? $color : 'var(--text-muted)'; ?>; font-weight: 700;">
                                            <?php echo $isChecked ? 'GRANTED' : 'OFF'; ?>
                                        </span>
                                        <input type="checkbox" name="modules[]" value="<?php echo $key; ?>" class="perm-cb-module"
                                            <?php echo $isChecked ? 'checked' : ''; ?> 
                                            <?php echo $isDisabled ? 'disabled' : ''; ?> 
                                            style="accent-color: <?php echo $color; ?>; width: 17px; height: 17px; cursor: <?php echo $isDisabled ? 'not-allowed' : 'pointer'; ?>;">
                                        <?php if ($isDisabled): ?>
                                            <input type="hidden" name="modules[]" value="<?php echo $key; ?>">
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Tab 2: Action Privileges (Color-Graded Cards) -->
                    <div id="perm-tab-content-actions" class="perm-tab-content hidden">
                        <div class="perm-card-grid">
                            <?php 
                            $current_actions = getUserActionPermissions($selected_user);
                            foreach ($actions_meta as $key => $item): 
                                $isCheckedAction = in_array($key, $current_actions);
                                $isDisabled = ($selected_user['role'] === 'Admin' || $selected_user['role'] === 'Super Admin');
                                $color = $item['color'];
                            ?>
                                <label class="perm-item-card border perm-card-action" 
                                       data-color="<?php echo $color; ?>"
                                       style="background: <?php echo $isCheckedAction ? "{$color}12" : 'var(--bg-app)'; ?>; border: <?php echo $isCheckedAction ? "2px solid {$color}" : '1px solid var(--border-color)'; ?>;">
                                    <div class="flex align-center gap-2" style="overflow: hidden;">
                                        <div style="width: 26px; height: 26px; border-radius: 6px; background: <?php echo "{$color}20"; ?>; color: <?php echo $color; ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <i data-lucide="<?php echo $item['icon']; ?>" style="width: 14px; height: 14px;"></i>
                                        </div>
                                        <div class="flex flex-col" style="overflow: hidden;">
                                            <span class="font-bold text-xs perm-label-text" style="color: <?php echo $isCheckedAction ? 'var(--text-main)' : 'var(--text-muted)'; ?>; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($item['label']); ?></span>
                                            <span class="text-xs text-muted" style="font-size: 9.5px; line-height: 1.1;"><?php echo htmlspecialchars($item['desc']); ?></span>
                                        </div>
                                    </div>
                                    <div class="flex align-center gap-2 flex-shrink-0">
                                        <span class="badge text-xs perm-status-badge" style="font-size: 9px; padding: 2px 6px; --badge-bg: <?php echo $isCheckedAction ? "{$color}25" : 'var(--border-card)'; ?>; --badge-color: <?php echo $isCheckedAction ? $color : 'var(--text-muted)'; ?>; font-weight: 700;">
                                            <?php echo $isCheckedAction ? 'ALLOWED' : 'OFF'; ?>
                                        </span>
                                        <input type="checkbox" name="actions[]" value="<?php echo $key; ?>" class="perm-cb-action"
                                            <?php echo $isCheckedAction ? 'checked' : ''; ?> 
                                            <?php echo $isDisabled ? 'disabled' : ''; ?> 
                                            style="accent-color: <?php echo $color; ?>; width: 17px; height: 17px; cursor: <?php echo $isDisabled ? 'not-allowed' : 'pointer'; ?>;">
                                        <?php if ($isDisabled): ?>
                                            <input type="hidden" name="actions[]" value="<?php echo $key; ?>">
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>

                <script>
                    let activePermTab = 'modules';
                    function switchPermTab(tabName) {
                        activePermTab = tabName;
                        const modContent = document.getElementById('perm-tab-content-modules');
                        const actContent = document.getElementById('perm-tab-content-actions');
                        const btnMod = document.getElementById('btn-tab-modules');
                        const btnAct = document.getElementById('btn-tab-actions');

                        if (tabName === 'modules') {
                            if (modContent) modContent.classList.remove('hidden');
                            if (actContent) actContent.classList.add('hidden');
                            if (btnMod) {
                                btnMod.classList.add('perm-tab-btn-active');
                                btnMod.classList.remove('perm-tab-btn-inactive');
                            }
                            if (btnAct) {
                                btnAct.classList.remove('perm-tab-btn-active');
                                btnAct.classList.add('perm-tab-btn-inactive');
                            }
                        } else {
                            if (modContent) modContent.classList.add('hidden');
                            if (actContent) actContent.classList.remove('hidden');
                            if (btnAct) {
                                btnAct.classList.add('perm-tab-btn-active');
                                btnAct.classList.remove('perm-tab-btn-inactive');
                            }
                            if (btnMod) {
                                btnMod.classList.remove('perm-tab-btn-active');
                                btnMod.classList.add('perm-tab-btn-inactive');
                            }
                        }
                    }

                    function updateCardStyle(card, checked) {
                        if (!card) return;
                        const color = card.getAttribute('data-color') || '#6366f1';
                        const labelText = card.querySelector('.perm-label-text');
                        const badge = card.querySelector('.perm-status-badge');

                        if (checked) {
                            card.style.background = color + '15';
                            card.style.border = '2px solid ' + color;
                            if (labelText) labelText.style.color = 'var(--text-main)';
                            if (badge) {
                                badge.style.setProperty('--badge-bg', color + '25');
                                badge.style.setProperty('--badge-color', color);
                                badge.textContent = card.classList.contains('perm-card-action') ? 'ALLOWED' : 'GRANTED';
                            }
                        } else {
                            card.style.background = 'var(--bg-app)';
                            card.style.border = '1px solid var(--border-color)';
                            if (labelText) labelText.style.color = 'var(--text-muted)';
                            if (badge) {
                                badge.style.setProperty('--badge-bg', 'var(--border-card)');
                                badge.style.setProperty('--badge-color', 'var(--text-muted)');
                                badge.textContent = 'OFF';
                            }
                        }
                    }

                    function toggleCurrentTabCheckboxes(state) {
                        const selector = activePermTab === 'modules' ? '.perm-cb-module' : '.perm-cb-action';
                        document.querySelectorAll(selector).forEach(cb => {
                            if (!cb.disabled) {
                                cb.checked = state;
                                const card = cb.closest('.perm-item-card');
                                updateCardStyle(card, state);
                            }
                        });
                    }

                    document.querySelectorAll('.perm-cb-module, .perm-cb-action').forEach(cb => {
                        cb.addEventListener('change', (e) => {
                            const card = e.target.closest('.perm-item-card');
                            updateCardStyle(card, e.target.checked);
                        });
                    });
                </script>
            <?php else: ?>
                <div class="text-center py-8">
                    <i data-lucide="shield-alert" style="width: 48px; height: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                    <h4 class="m-0">No Operator Selected</h4>
                    <p class="text-xs text-muted mt-1">Select an active staff employee or operator from the left directory to configure their permissions.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Modal: Add User Operator -->
<div id="create-user-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700;">Register User Operator</h3>
            <button class="btn-icon" onclick="window.closeModal('create-user-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-3" method="POST" action="index.php?page=admin_users">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="form-group m-0">
                <label class="form-label text-xs">Full Name *</label>
                <input type="text" name="name" class="form-control text-xs" required placeholder="E.g. Karan Kapoor">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Email Address *</label>
                <input type="email" name="email" class="form-control text-xs" required placeholder="E.g. kapoor@friendlyaisolution.com">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Custom Password (Default: password123)</label>
                <input type="password" name="password" class="form-control text-xs" placeholder="Leave blank to use default password123">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Access Role *</label>
                <select name="role" class="form-control text-xs font-semibold" style="height: 38px;">
                    <?php 
                    $available_create_roles = (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== (defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata')) ? $EMPLOYEE_ROLES : $ROLES;
                    foreach ($available_create_roles as $roleName => $desc): 
                    ?>
                        <option value="<?php echo $roleName; ?>"><?php echo $roleName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('create-user-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm font-semibold">Save User Operator</button>
            </div>
        </form>
    </div>
</div>

<!-- Client-side Search and Filter Script -->
<script>
let currentStatusFilter = 'all';

function setUserStatusFilter(status, pill) {
    currentStatusFilter = status;
    document.querySelectorAll('#statusFilterPills .user-filter-pill').forEach(p => p.classList.remove('active'));
    if (pill) pill.classList.add('active');
    filterOperatorsList();
}

function filterOperatorsList() {
    const searchVal = (document.getElementById('userSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#operatorsTableBody .operator-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const role = row.getAttribute('data-role') || '';
        const id = 'emp-' + (row.getAttribute('data-id') || '').padStart(4, '0');
        const rawId = row.getAttribute('data-id') || '';
        const status = row.getAttribute('data-status') || '';

        const matchesSearch = !searchVal || 
            name.includes(searchVal) || 
            email.includes(searchVal) || 
            role.includes(searchVal) || 
            id.includes(searchVal) || 
            rawId === searchVal;

        const matchesStatus = (currentStatusFilter === 'all') || (status === currentStatusFilter);

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    let emptyRow = document.getElementById('searchEmptyRow');
    if (visibleCount === 0) {
        if (!emptyRow) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'searchEmptyRow';
            emptyRow.innerHTML = '<td colspan="3" class="text-center py-6 text-muted text-xs">No operators match the specified search / filter criteria.</td>';
            document.getElementById('operatorsTableBody')?.appendChild(emptyRow);
        } else {
            emptyRow.style.display = '';
        }
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
}
</script>
