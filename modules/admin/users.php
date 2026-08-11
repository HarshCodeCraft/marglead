<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

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
        return ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
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

// 1. Process Admin POST actions (Create operator & Update permissions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    
    if ($act === 'update_permissions') {
        $userId = intval($_POST['user_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role']);
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Active';
        $selected_modules = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : [];
        $selected_actions = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : [];

        // Auto-synchronize shared permission mappings
        syncPermissionMappings($selected_modules, $selected_actions);
        
        if ($db_connected && $pdo) {
            try {
                $permissions_json = json_encode($selected_modules);
                $actions_json = json_encode($selected_actions);
                
                // Fetch previous user status for email triggers
                $prevStmt = $pdo->prepare("SELECT name, email, status FROM users WHERE id = ?");
                $prevStmt->execute([$userId]);
                $prevUser = $prevStmt->fetch();
                
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, role = ?, status = ?, permissions = ?, action_permissions = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $hash, $role, $status, $permissions_json, $actions_json, $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ?, permissions = ?, action_permissions = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $role, $status, $permissions_json, $actions_json, $userId]);
                }

                // If role is Client, ensure sync in client_directory table
                if ($role === 'Client') {
                    try {
                        $chk = $pdo->prepare("SELECT id FROM client_directory WHERE email = ? LIMIT 1");
                        $chk->execute([$email]);
                        if (!$chk->fetchColumn()) {
                            $cust_id = 'CL-' . rand(10000, 99999);
                            $due_date = date('Y-m-d', strtotime('+1 year'));
                            $ins = $pdo->prepare("INSERT INTO client_directory 
                                (customer_id, party_name, email, user_type, software_type, party_status, due_on, total_amount) 
                                VALUES (?, ?, ?, 'Registered Client', 'Marg ERP Silver', ?, ?, 4661.00)");
                            $ins->execute([$cust_id, $name, $email, ($status === 'Active' ? 'Running' : $status), $due_date]);
                        } else {
                            $upd = $pdo->prepare("UPDATE client_directory SET party_name = ?, party_status = ? WHERE email = ?");
                            $upd->execute([$name, ($status === 'Active' ? 'Running' : $status), $email]);
                        }
                    } catch (PDOException $e) {}
                }
                
                // Trigger approval/decline email if status changed from Pending Approval
                if ($prevUser && $prevUser['status'] === 'Pending Approval' && $status !== 'Pending Approval') {
                    Mailer::sendUserApproval($email, $name, $status);
                }
                
                // Log notification
                $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Operator Details Updated', ?, 'info')");
                $actionMsg = "Admin updated user details & privileges (User ID: " . $userId . ", Name: " . $name . ", Role: " . $role . ", Status: " . $status . ")";
                $logStmt->execute([$actionMsg]);
                
                // If updated user is currently logged-in user, refresh their session
                if ($email === ($_SESSION['user_email'] ?? '') || $userId === intval($_SESSION['user_id'] ?? 0)) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = $role;
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
            $_SESSION['flash_success'] = "Database offline. Operator updates simulated successfully.";
            header("Location: index.php?page=admin_users&user_id=" . $userId);
            exit;
        }
    } elseif ($act === 'create_user') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = trim($_POST['role']);
        $password = 'password123'; // Standard default password
        
        if ($db_connected && $pdo) {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $_SESSION['flash_error'] = "An employee with this email already exists.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $default_perms = getUserPermissions(['role' => $role]);
                    $permissions_json = json_encode($default_perms);
                    
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status, permissions) VALUES (?, ?, ?, ?, 'Active', ?)");
                    $stmt->execute([$name, $email, $hash, $role, $permissions_json]);
                    $newId = $pdo->lastInsertId();
                    
                    // Log notification
                    $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'New Operator Registered', ?, 'success')");
                    $actionMsg = "Admin added new operator: " . $name . " (Role: " . $role . ")";
                    $logStmt->execute([$actionMsg]);
                    
                    $_SESSION['flash_success'] = "New user operator registered successfully. Default password is: password123";
                    header("Location: index.php?page=admin_users&user_id=" . $newId);
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Failed to add operator: " . $e->getMessage();
            }
        } else {
            $_SESSION['flash_success'] = "Database offline. Created operator simulated successfully.";
            header("Location: index.php?page=admin_users");
            exit;
        }
    }
}

// 2. Process Admin GET actions (decline user, delete user, approve user direct)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $userId = intval($_GET['id']);
    $act = $_GET['action'];
    if ($db_connected && $pdo) {
        try {
            if ($act === 'approve_user') {
                // Fetch user details
                $roleStmt = $pdo->prepare("SELECT name, email, role FROM users WHERE id = ?");
                $roleStmt->execute([$userId]);
                $userObj = $roleStmt->fetch();
                
                $default_perms = getUserPermissions(['role' => $userObj['role']]);
                $permissions_json = json_encode($default_perms);
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Active', permissions = ? WHERE id = ?");
                $stmt->execute([$permissions_json, $userId]);
                
                // Trigger approval email
                if ($userObj) {
                    Mailer::sendUserApproval($userObj['email'], $userObj['name'], 'Active');
                }
            } elseif ($act === 'decline_user') {
                // Fetch user details
                $roleStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                $roleStmt->execute([$userId]);
                $userObj = $roleStmt->fetch();
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Declined' WHERE id = ?");
                $stmt->execute([$userId]);
                
                // Trigger decline email
                if ($userObj) {
                    Mailer::sendUserApproval($userObj['email'], $userObj['name'], 'Declined');
                }
            } elseif ($act === 'delete_user') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
            }
            
            // Log notification
            $logStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Operator Status Updated', ?, 'info')");
            $actionMsg = "Admin modified user status (ID: " . $userId . ") to: " . str_replace('_user', '', $act);
            $logStmt->execute([$actionMsg]);
            
            $_SESSION['flash_success'] = "Operator status updated successfully.";
            header("Location: index.php?page=admin_users" . ($act !== 'delete_user' ? "&user_id=" . $userId : ""));
            exit;
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Operator action failure: " . $e->getMessage();
        }
    }
}

// 3. Fetch system users dynamically
$users_list = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name, email, role, status, permissions FROM users ORDER BY id DESC");
        $users_list = $stmt->fetchAll();
    } catch (PDOException $e) {
        $users_list = [];
    }
}

// Fallback mock accounts if database is offline or empty
if (empty($users_list)) {
    $users_list = [
        ['id' => 1, 'name' => 'Harsh Vardhan', 'email' => 'harsh@marglead.com', 'role' => 'Admin', 'status' => 'Active', 'permissions' => null],
        ['id' => 2, 'name' => 'Amit Sen', 'email' => 'amit.sen@marglead.com', 'role' => 'Sales Executive', 'status' => 'Active', 'permissions' => null],
        ['id' => 3, 'name' => 'Vikas Patel', 'email' => 'vikas@marglead.com', 'role' => 'Installation Engineer', 'status' => 'Active', 'permissions' => null],
        ['id' => 4, 'name' => 'Sonal Mehta', 'email' => 'sonal@marglead.com', 'role' => 'Team Leader', 'status' => 'Active', 'permissions' => null]
    ];
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
    'dashboard' => ['label' => 'Dashboard Workspace', 'icon' => 'layout-dashboard', 'color' => '#6366f1'],
    'leads' => ['label' => 'Leads Directory', 'icon' => 'users', 'color' => '#3b82f6'],
    'pipeline' => ['label' => 'Kanban Pipeline', 'icon' => 'git-commit', 'color' => '#8b5cf6'],
    'followups' => ['label' => 'Follow-up Planner', 'icon' => 'calendar-clock', 'color' => '#ec4899'],
    'demo' => ['label' => 'Demos & Feedback', 'icon' => 'presentation', 'color' => '#f59e0b'],
    'quotation' => ['label' => 'Quotations Proposal', 'icon' => 'file-text', 'color' => '#10b981'],
    'payments' => ['label' => 'Invoicing & Receipts', 'icon' => 'credit-card', 'color' => '#06b6d4'],
    'bank_accounts' => ['label' => 'Bank & QR Payment Details', 'icon' => 'landmark', 'color' => '#14b8a6'],
    'installation' => ['label' => 'Installation Checklist', 'icon' => 'wrench', 'color' => '#f97316'],
    'training' => ['label' => 'Operator Training', 'icon' => 'graduation-cap', 'color' => '#84cc16'],
    'support' => ['label' => 'Helpdesk View Tickets', 'icon' => 'life-buoy', 'color' => '#ef4444'],
    'support_create' => ['label' => 'Helpdesk: Generate Tickets', 'icon' => 'plus-circle', 'color' => '#10b981'],
    'support_edit' => ['label' => 'Helpdesk: Edit Tickets', 'icon' => 'edit-3', 'color' => '#8b5cf6'],
    'support_assign' => ['label' => 'Helpdesk: Assign/Transfer Tickets', 'icon' => 'user-check', 'color' => '#06b6d4'],
    'support_close' => ['label' => 'Helpdesk: Close Tickets', 'icon' => 'check-circle-2', 'color' => '#6366f1'],
    'renewals' => ['label' => 'Renewals Manager', 'icon' => 'refresh-cw', 'color' => '#0284c7'],
    'reports' => ['label' => 'Reports & Audits', 'icon' => 'bar-chart-3', 'color' => '#a855f7'],
    'settings' => ['label' => 'Control Settings', 'icon' => 'sliders', 'color' => '#64748b']
];

$actions_meta = [
    'can_view' => ['label' => 'View Records & Registers', 'icon' => 'eye', 'color' => '#3b82f6', 'desc' => 'Read-only view access'],
    'can_create' => ['label' => 'Create & Add Records', 'icon' => 'plus-circle', 'color' => '#10b981', 'desc' => 'Add new leads & tickets'],
    'can_edit' => ['label' => 'Edit & Modify Records', 'icon' => 'edit-3', 'color' => '#8b5cf6', 'desc' => 'Modify customer details'],
    'can_delete' => ['label' => 'Delete / Drop Records', 'icon' => 'trash-2', 'color' => '#ef4444', 'desc' => 'Delete records & drop files'],
    'can_update_status' => ['label' => 'Update Pipeline & Approve', 'icon' => 'refresh-cw', 'color' => '#f59e0b', 'desc' => 'Pipeline stage approvals'],
    'can_share' => ['label' => 'Share via Email / WhatsApp', 'icon' => 'share-2', 'color' => '#06b6d4', 'desc' => 'Dispatch proposals & SMS'],
    'can_bulk_upload' => ['label' => 'Bulk Lead Upload', 'icon' => 'upload-cloud', 'color' => '#ec4899', 'desc' => 'Import CSV/Excel files'],
    'can_export' => ['label' => 'Export Reports & Data', 'icon' => 'download', 'color' => '#6366f1', 'desc' => 'Download CSV reports'],
    'can_assign' => ['label' => 'Assign & Transfer Accounts', 'icon' => 'user-check', 'color' => '#14b8a6', 'desc' => 'Transfer lead ownership']
];
?>

<div class="users-permissions-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Users & Permissions Matrix</h2>
            <p class="text-muted text-sm">Add system operators, configure user profiles, and map access privileges across module workspaces.</p>
        </div>
        <button class="btn btn-primary text-sm" onclick="window.openModal('create-user-modal');">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Add User Operator</span>
        </button>
    </div>

    <!-- Layout Grid: Users List + Permissions Matrix -->
    <div class="grid" style="grid-template-columns: minmax(0, 1.25fr) minmax(0, 1.75fr); gap: 1.5rem; align-items: start;">
        
        <!-- Left: Users List Card -->
        <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
            <div class="p-4 border-bottom" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
                <h3 class="text-sm font-semibold m-0">Registered Operators</h3>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Operator / Employee ID</th>
                            <th>Role</th>
                            <th>Status Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users_list as $u): 
                            $isActiveRow = ($selected_user_id === intval($u['id']));
                        ?>
                            <tr style="<?php echo $isActiveRow ? 'background-color: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.08);' : ''; ?>">
                                <td>
                                    <a href="index.php?page=admin_users&user_id=<?php echo $u['id']; ?>" class="flex flex-col text-decoration-none pointer" style="color: inherit; text-decoration: none; display: block;">
                                        <span class="text-xs font-semibold text-primary">EMP-<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                        <span class="font-semibold text-sm block <?php echo $isActiveRow ? 'text-primary' : ''; ?>"><?php echo htmlspecialchars($u['name']); ?></span>
                                        <span class="text-xs text-muted block"><?php echo htmlspecialchars($u['email']); ?></span>
                                    </a>
                                </td>
                                <td class="text-xs font-semibold" style="vertical-align: middle;"><?php echo htmlspecialchars($u['role']); ?></td>
                                <td style="vertical-align: middle;">
                                    <div class="flex flex-col gap-1 align-start">
                                        <?php if ($u['status'] === 'Active'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Active</span>
                                        <?php elseif ($u['status'] === 'Declined'): ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Declined</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-size: 10px;"><?php echo htmlspecialchars($u['status']); ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if ($u['role'] !== 'Admin' && $u['role'] !== 'Super Admin'): ?>
                                            <div class="flex gap-2" style="margin-top: 4px;">
                                                <?php if ($u['status'] === 'Pending Approval'): ?>
                                                    <a href="index.php?page=admin_users&user_id=<?php echo $u['id']; ?>" class="btn btn-primary text-xs font-semibold" style="padding: 0.25rem 0.5rem; font-size: 10px; background-color: var(--primary);">Configure & Approve</a>
                                                <?php else: ?>
                                                    <a href="index.php?page=admin_users&action=delete_user&id=<?php echo $u['id']; ?>" class="btn btn-secondary text-xs" style="padding: 0.2rem 0.4rem; font-size: 10px; border-color: var(--danger); color: var(--danger);" onclick="return confirm('Are you sure you want to delete this operator?');">Delete</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Permissions Grid Matrix -->
        <div class="card p-6" style="border: 1px solid var(--border-color);">
            <?php if ($selected_user): ?>
                <form action="index.php?page=admin_users" method="POST">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="user_id" value="<?php echo $selected_user['id']; ?>">
                    
                    <div class="flex justify-between align-center mb-4">
                        <div>
                            <h3 class="text-base font-semibold m-0">Permissions Matrix: <?php echo htmlspecialchars($selected_user['name']); ?></h3>
                            <p class="text-xs text-muted m-0">Configure workspace accessibility mapping for this operator account.</p>
                        </div>
                        <div>
                            <?php if ($selected_user['status'] === 'Pending Approval'): ?>
                                <input type="hidden" name="status" value="Active">
                                <button type="submit" class="btn btn-primary text-xs" style="padding: 0.5rem 1rem; background-color: var(--success); font-weight: 600;">Approve & Save Privileges</button>
                                <a href="index.php?page=admin_users&action=decline_user&id=<?php echo $selected_user['id']; ?>" class="btn btn-secondary text-xs" style="padding: 0.5rem 1rem; border-color: var(--danger); color: var(--danger); margin-left: 0.25rem; font-weight: 600; text-decoration: none; display: inline-block;">Decline</a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary text-xs" style="padding: 0.5rem 1rem; font-weight: 600;">Save Privileges</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Operator Details Inputs Grid -->
                    <div class="card p-4 mb-4" style="background-color: var(--border-card); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                        <h4 class="text-xs uppercase text-muted font-semibold mb-3">Operator Account Credentials</h4>
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Full Name</label>
                                <input type="text" name="name" class="form-control text-xs" style="height: 38px;" value="<?php echo htmlspecialchars($selected_user['name']); ?>" required>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Email Address</label>
                                <input type="email" name="email" class="form-control text-xs" style="height: 38px;" value="<?php echo htmlspecialchars($selected_user['email']); ?>" required>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Update Password (leave blank to keep current)</label>
                                <input type="password" name="password" class="form-control text-xs" style="height: 38px;" placeholder="New password">
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs">Access Role</label>
                                <select name="role" class="form-control text-xs font-semibold" style="height: 38px;" required>
                                    <?php 
                                    $available_edit_roles = (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== 'marg_crm') ? $EMPLOYEE_ROLES : $ROLES;
                                    if ($selected_user['role'] === 'Super Admin' && !isset($available_edit_roles['Super Admin'])) {
                                        $available_edit_roles = array_merge(['Super Admin' => 'Master Super Admin'], $available_edit_roles);
                                    }
                                    foreach ($available_edit_roles as $roleName => $desc): 
                                    ?>
                                        <option value="<?php echo $roleName; ?>" <?php echo ($selected_user['role'] === $roleName) ? 'selected' : ''; ?>><?php echo $roleName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group m-0" style="grid-column: span 2;">
                                <label class="form-label text-xs">Account Status</label>
                                <select name="status" class="form-control text-xs font-semibold" style="height: 38px;" required>
                                    <option value="Active" <?php echo ($selected_user['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="Pending Approval" <?php echo ($selected_user['status'] === 'Pending Approval') ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="Declined" <?php echo ($selected_user['status'] === 'Declined') ? 'selected' : ''; ?>>Declined/Suspended</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if ($selected_user['role'] === 'Admin' || $selected_user['role'] === 'Super Admin'): ?>
                        <div class="badge w-full text-center py-2 flex align-center justify-center gap-2 mb-4" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); display: flex; border-radius: var(--border-radius-sm); border: 1px dashed rgba(59, 130, 246, 0.3);">
                            <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i>
                            <span class="font-semibold text-xs">Administrative accounts hold full master access privileges across all modules.</span>
                        </div>
                    <?php endif; ?>

                    <!-- Compact High-Visibility Permission Matrix Tab Navigation -->
                    <style>
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
                            max-height: 350px;
                            overflow-y: auto;
                            padding-right: 4px;
                        }
                        .perm-item-card {
                            border-radius: 8px;
                            padding: 0.6rem 0.75rem;
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            cursor: pointer;
                            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                            user-select: none;
                        }
                        .perm-tab-content {
                            margin-top: 10px;
                        }
                    </style>

                    <div class="flex justify-between align-center mb-5 mt-4 border-bottom pb-3" style="border-bottom: 1px solid var(--border-color);">
                        <div class="flex gap-2">
                            <button type="button" class="btn text-xs font-bold perm-tab-btn perm-tab-btn-active" id="btn-tab-modules" onclick="switchPermTab('modules')" style="padding: 0.5rem 1rem; border-radius: 8px;">
                                <i data-lucide="layout-grid" style="width: 15px; height: 15px; vertical-align: middle; margin-right: 5px;"></i>
                                <span>Workspace Modules (<?php echo count($modules_meta); ?>)</span>
                            </button>
                            <button type="button" class="btn text-xs font-bold perm-tab-btn perm-tab-btn-inactive" id="btn-tab-actions" onclick="switchPermTab('actions')" style="padding: 0.5rem 1rem; border-radius: 8px;">
                                <i data-lucide="shield-check" style="width: 15px; height: 15px; vertical-align: middle; margin-right: 5px;"></i>
                                <span>Action Privileges (<?php echo count($actions_meta); ?>)</span>
                            </button>
                        </div>
                        <div class="flex gap-1" id="perm-select-all-controls">
                            <button type="button" class="btn btn-secondary text-xs font-semibold" style="padding: 0.35rem 0.7rem; font-size: 11px;" onclick="toggleCurrentTabCheckboxes(true)">Select All</button>
                            <button type="button" class="btn btn-secondary text-xs font-semibold" style="padding: 0.35rem 0.7rem; font-size: 11px;" onclick="toggleCurrentTabCheckboxes(false)">Clear All</button>
                        </div>
                    </div>

                    <!-- Tab 1: Workspace Modules (High Contrast Color-Graded Grid) -->
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
                                        <div style="width: 28px; height: 28px; border-radius: 6px; background: <?php echo "{$color}20"; ?>; color: <?php echo $color; ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <i data-lucide="<?php echo $item['icon']; ?>" style="width: 15px; height: 15px;"></i>
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

                    <!-- Tab 2: Action Privileges (High Contrast Color-Graded Grid) -->
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
                                        <div style="width: 28px; height: 28px; border-radius: 6px; background: <?php echo "{$color}20"; ?>; color: <?php echo $color; ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <i data-lucide="<?php echo $item['icon']; ?>" style="width: 15px; height: 15px;"></i>
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
                    <p class="text-xs text-muted mt-1">Select a registered employee or operator from the left sidebar list to configure their individual matrix permissions.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Modal: Add User -->
<div id="create-user-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Register User Operator</h3>
            <button class="btn-icon" onclick="window.closeModal('create-user-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" method="POST" action="index.php?page=admin_users">
            <input type="hidden" name="action" value="create_user">
            <div class="form-group m-0">
                <label class="form-label text-xs">Full Name</label>
                <input type="text" name="name" class="form-control" required placeholder="E.g. Karan Kapoor">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Email Address</label>
                <input type="email" name="email" class="form-control" required placeholder="E.g. kapoor@marglead.com">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Access Role</label>
                <select name="role" class="form-control" style="height: 38px;">
                    <?php 
                    $available_create_roles = (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== 'marg_crm') ? $EMPLOYEE_ROLES : $ROLES;
                    foreach ($available_create_roles as $roleName => $desc): 
                    ?>
                        <option value="<?php echo $roleName; ?>"><?php echo $roleName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('create-user-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save User</button>
            </div>
        </form>
    </div>
</div>
