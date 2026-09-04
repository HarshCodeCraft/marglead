<?php
/**
 * Marg Soft Solution - Employee Access Permissions Matrix Workspace
 * Clean 2-Column Responsive Matrix Interface (No Overflow Modals)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_id = $_SESSION['user_id'] ?? 1;
$is_admin = ($role === 'Super Admin' || $role === 'Admin');

// Security check: Only Admins can access employee permissions management
if (!$is_admin) {
    header('Location: ../../index.php?page=dashboard');
    exit;
}

$message = '';
$message_type = 'success';

// All Available Granular Action Permissions
$ACTION_PERMISSIONS = [
    'can_view' => [
        'label' => 'View Records',
        'desc' => 'View lead files, dashboards, and read-only registers',
        'icon' => 'eye',
        'color' => '#3b82f6'
    ],
    'can_create' => [
        'label' => 'Create & Add',
        'desc' => 'Create new leads, proposals, payment logs, and tickets',
        'icon' => 'plus-circle',
        'color' => '#10b981'
    ],
    'can_edit' => [
        'label' => 'Edit & Modify',
        'desc' => 'Edit customer details, note logs, and contact info',
        'icon' => 'edit-3',
        'color' => '#8b5cf6'
    ],
    'can_delete' => [
        'label' => 'Delete Records',
        'desc' => 'Delete leads, remove quotations, or drop records',
        'icon' => 'trash-2',
        'color' => '#ef4444'
    ],
    'can_update_status' => [
        'label' => 'Update Status',
        'desc' => 'Change pipeline stages, approve quotes, and reschedule demos',
        'icon' => 'refresh-cw',
        'color' => '#f59e0b'
    ],
    'can_share' => [
        'label' => 'Share (Email/WhatsApp)',
        'desc' => 'Dispatch proposals, bank details, & emails to clients',
        'icon' => 'share-2',
        'color' => '#06b6d4'
    ],
    'can_bulk_upload' => [
        'label' => 'Bulk Lead Upload',
        'desc' => 'Import & bulk upload lead Excel/CSV files into system',
        'icon' => 'upload-cloud',
        'color' => '#ec4899'
    ],
    'can_export' => [
        'label' => 'Export Reports',
        'desc' => 'Download CSV reports, pipeline statistics, and revenue data',
        'icon' => 'download',
        'color' => '#6366f1'
    ],
    'can_assign' => [
        'label' => 'Assign & Transfer',
        'desc' => 'Re-assign leads and customer files to other team operators',
        'icon' => 'user-check',
        'color' => '#14b8a6'
    ]
];

// Workspace Modules Categorized for Clean UI Grid
$MODULE_CATEGORIES = [
    'Core CRM Workspaces' => [
        'dashboard' => ['label' => 'Dashboard & Analytics Workspace', 'icon' => 'layout-dashboard', 'color' => '#6366f1'],
        'leads' => ['label' => 'Leads Directory & Contacts', 'icon' => 'users', 'color' => '#3b82f6'],
        'pipeline' => ['label' => 'Kanban Pipeline & Stages', 'icon' => 'git-commit', 'color' => '#8b5cf6'],
        'followups' => ['label' => 'Follow-up Schedules & Reminders', 'icon' => 'calendar-clock', 'color' => '#ec4899'],
        'demo' => ['label' => 'Product Demos & Feedback', 'icon' => 'presentation', 'color' => '#f59e0b'],
        'quotation' => ['label' => 'Quotations & Billing Proposals', 'icon' => 'file-text', 'color' => '#10b981'],
        'payments' => ['label' => 'Payments, Invoices & Receipts', 'icon' => 'credit-card', 'color' => '#06b6d4'],
        'bank_accounts' => ['label' => 'Bank & QR Payment Details', 'icon' => 'landmark', 'color' => '#14b8a6'],
        'installation' => ['label' => 'Technical Installations Checklist', 'icon' => 'wrench', 'color' => '#f97316'],
        'training' => ['label' => 'Client Operator Training', 'icon' => 'graduation-cap', 'color' => '#84cc16'],
        'support' => ['label' => 'Support Desk & Helpdesk Tickets', 'icon' => 'life-buoy', 'color' => '#ef4444'],
        'renewals' => ['label' => 'Software Support Renewals (AMC)', 'icon' => 'refresh-cw', 'color' => '#0284c7'],
        'reports' => ['label' => 'Business Performance Reports', 'icon' => 'bar-chart-3', 'color' => '#a855f7'],
        'clients' => ['label' => 'Client Directory & Marg Customers', 'icon' => 'user-check', 'color' => '#06b6d4']
    ],
    'WhatsApp Marketing & Automation Add-ons' => [
        'team_inbox' => ['label' => 'Team Inbox & Live WhatsApp Chat', 'icon' => 'message-square', 'color' => '#10b981'],
        'broadcast_campaigns' => ['label' => 'WhatsApp Broadcast & Template Hub', 'icon' => 'send', 'color' => '#2563eb'],
        'bulk_broadcast' => ['label' => 'Bulk Marketing Broadcast Engine', 'icon' => 'upload-cloud', 'color' => '#8b5cf6'],
        'bot_flows' => ['label' => 'WhatsApp Bots & Auto-Responders', 'icon' => 'bot', 'color' => '#f59e0b'],
        'merchant_waba_settings' => ['label' => 'WhatsApp WABA Cloud API Setup', 'icon' => 'qr-code', 'color' => '#ec4899']
    ],
    'Admin & System Controls' => [
        'admin_users' => ['label' => 'User Management & Operators', 'icon' => 'shield-check', 'color' => '#6366f1'],
        'admin_permissions' => ['label' => 'Employee Permissions Matrix', 'icon' => 'lock', 'color' => '#ef4444'],
        'settings' => ['label' => 'System Control Settings', 'icon' => 'sliders', 'color' => '#64748b']
    ],
    'Legal & Compliance Policies' => [
        'privacy_policy' => ['label' => 'Privacy Policy Document', 'icon' => 'file-text', 'color' => '#10b981'],
        'terms_conditions' => ['label' => 'Terms & Conditions Agreement', 'icon' => 'file-check', 'color' => '#3b82f6'],
        'refund_policy' => ['label' => 'Refund & Cancellation Policy', 'icon' => 'rotate-ccw', 'color' => '#f59e0b']
    ]
];

// Flat list of all modules
$WORKSPACE_MODULES = [];
foreach ($MODULE_CATEGORIES as $cat => $mods) {
    foreach ($mods as $mKey => $mMeta) {
        $WORKSPACE_MODULES[$mKey] = $mMeta['label'];
    }
}

// Process POST Save Granular Permissions Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_employee_permissions') {
    if (!verifyCsrfToken()) {
        $message = "Security Error: Invalid or missing CSRF token. Action blocked.";
        $message_type = "danger";
    } else {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $selected_actions = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : [];
        $selected_modules = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : [];

        if ($target_user_id > 0 && $db_connected && $pdo) {
            try {
                // Auto-synchronize shared permission mappings
                syncPermissionMappings($selected_modules, $selected_actions);

                $actions_json = json_encode($selected_actions);
                $modules_json = json_encode($selected_modules);

                $stmt = $pdo->prepare("UPDATE users SET action_permissions = ?, permissions = ? WHERE id = ?");
                $stmt->execute([$actions_json, $modules_json, $target_user_id]);

                // If current logged in user permissions were modified, update session
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $target_user_id) {
                    $_SESSION['user_permissions'] = $selected_modules;
                    $_SESSION['user_action_permissions'] = $selected_actions;
                }

                $message = "Employee permissions saved and synchronized successfully!";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Error updating permissions: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Fetch all users from database
$users_list = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM users ORDER BY role ASC, name ASC");
        $users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $users_list = [];
    }
}

// Fallback users if database returns empty
if (empty($users_list)) {
    $users_list = [
        [
            'id' => 1,
            'name' => 'Harsh Vardhan',
            'email' => 'harsh@margsoft.com',
            'role' => 'Super Admin',
            'status' => 'Active',
            'profile_photo' => null,
            'permissions' => json_encode(array_keys($WORKSPACE_MODULES)),
            'action_permissions' => json_encode(array_keys($ACTION_PERMISSIONS))
        ],
        [
            'id' => 2,
            'name' => 'Vandana Yadav',
            'email' => 'vyadav@margsoft.com',
            'role' => 'Sales Executive',
            'status' => 'Active',
            'profile_photo' => null,
            'permissions' => json_encode(['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation']),
            'action_permissions' => json_encode(['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share'])
        ],
        [
            'id' => 3,
            'name' => 'Moin Khan',
            'email' => 'mkhan@margsoft.com',
            'role' => 'Sales Executive',
            'status' => 'Active',
            'profile_photo' => null,
            'permissions' => json_encode(['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments']),
            'action_permissions' => json_encode(['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload'])
        ],
        [
            'id' => 4,
            'name' => 'Amit Sen',
            'email' => 'asen@margsoft.com',
            'role' => 'Regional Manager',
            'status' => 'Active',
            'profile_photo' => null,
            'permissions' => json_encode(['dashboard', 'leads', 'pipeline', 'demo', 'quotation', 'payments', 'renewals', 'reports']),
            'action_permissions' => json_encode(['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'])
        ]
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

if (!$selected_user && !empty($users_list)) {
    $selected_user = $users_list[0];
    $selected_user_id = intval($selected_user['id']);
}

// Parse selected user permissions
$sel_act_perms = $selected_user ? getUserActionPermissions($selected_user) : array_keys($ACTION_PERMISSIONS);
$sel_mod_perms = $selected_user ? getUserPermissions($selected_user) : array_keys($WORKSPACE_MODULES);
?>

<style>
/* Clean 2-Column Workspace Layout */
.permissions-layout-grid {
    display: grid;
    grid-template-columns: 340px minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
    margin-bottom: 4rem;
}
@media (max-width: 992px) {
    .permissions-layout-grid {
        grid-template-columns: 1fr;
    }
}

/* Sidebar Employee Cards */
.emp-list-container {
    max-height: 720px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding-right: 4px;
}
.emp-select-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    color: inherit;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.emp-select-card:hover {
    border-color: var(--primary, #10b981);
    transform: translateX(3px);
}
.emp-select-card.active {
    background: rgba(16, 185, 129, 0.08);
    border-color: var(--primary, #10b981);
    border-left: 4px solid var(--primary, #10b981);
}

/* Header Profile Card */
.emp-profile-header {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    flex-wrap: wrap;
    gap: 1rem;
}

/* Preset Action Pills */
.preset-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.preset-pill {
    background: var(--bg-body, #f3f4f6);
    border: 1px solid var(--border-color, #d1d5db);
    border-radius: 20px;
    padding: 0.4rem 0.85rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-main, #374151);
    cursor: pointer;
    transition: all 0.2s ease;
}
.preset-pill:hover {
    background: var(--primary, #10b981);
    color: white;
    border-color: var(--primary, #10b981);
}

/* Matrix Section Cards */
.matrix-section-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* Sticky Action Footer */
.permissions-sticky-footer {
    position: sticky;
    bottom: 1rem;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    z-index: 99;
}
</style>

<div class="permissions-module-container" style="max-width: 1280px; margin: 0 auto;">
    
    <!-- Top Bar -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--text-main);" class="mb-1 flex align-center gap-2">
                <i data-lucide="shield-check" style="width: 28px; height: 28px; color: var(--primary);"></i>
                <span>Employee Access Permissions Matrix</span>
            </h2>
            <p class="text-muted text-sm m-0">
                Configure granular action privileges and workspace access per employee with real-time sidebar synchronization.
            </p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-6" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.9rem 1.25rem; width: 100%; display: flex; font-size: 0.875rem; border-radius: 12px; border: 1px solid rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.2);">
            <i data-lucide="check-circle" style="width: 18px; height: 18px; margin-right: 0.6rem; flex-shrink: 0;"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- MAIN 2-COLUMN GRID -->
    <div class="permissions-layout-grid">
        
        <!-- COLUMN 1: EMPLOYEE SELECTOR SIDEBAR -->
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            
            <div class="card p-4" style="border: 1px solid var(--border-color); border-radius: 14px;">
                <div class="flex align-center gap-2 mb-3">
                    <i data-lucide="search" style="width: 16px; height: 16px; color: var(--text-muted);"></i>
                    <input type="text" id="empSearchInput" class="form-control text-xs" placeholder="Search employee by name..." onkeyup="filterEmpList()">
                </div>

                <div class="flex align-center justify-between text-xs text-muted mb-2 font-semibold">
                    <span>OPERATOR SYSTEM DIRECTORY</span>
                    <span><?php echo count($users_list); ?> EMPLOYEES</span>
                </div>

                <div class="emp-list-container" id="empListContainer">
                    <?php foreach ($users_list as $u): 
                        $isSelected = ($selected_user_id === intval($u['id']));
                        $userAvatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
                        if (!empty($u['profile_photo']) && file_exists(__DIR__ . '/../../' . ltrim($u['profile_photo'], '/\\'))) {
                            $userAvatar = ltrim($u['profile_photo'], '/\\');
                        }
                    ?>
                        <a href="index.php?page=admin_permissions&user_id=<?php echo $u['id']; ?>" class="emp-select-card <?php echo $isSelected ? 'active' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($u['name'] . ' ' . $u['email'] . ' ' . $u['role'])); ?>">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <img src="<?php echo htmlspecialchars($userAvatar); ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                <div>
                                    <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main); line-height: 1.2;">
                                        <?php echo htmlspecialchars($u['name']); ?>
                                    </div>
                                    <div class="text-xs text-muted" style="font-size: 0.725rem;">
                                        <?php echo htmlspecialchars($u['email']); ?>
                                    </div>
                                </div>
                            </div>
                            <span class="badge" style="background: rgba(59,130,246,0.1); color: #2563eb; font-size: 0.68rem; font-weight: 600;">
                                <?php echo htmlspecialchars($u['role']); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: PERMISSION MATRIX FORM WORKSPACE -->
        <form action="index.php?page=admin_permissions" method="POST" id="permMatrixForm" style="display: flex; flex-direction: column; gap: 1.25rem;">
            <?php echo renderCsrfInput(); ?>
            <input type="hidden" name="action" value="save_employee_permissions">
            <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">

            <!-- 1. Selected Employee Profile Bar -->
            <?php if ($selected_user): 
                $selAvatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
                if (!empty($selected_user['profile_photo']) && file_exists(__DIR__ . '/../../' . ltrim($selected_user['profile_photo'], '/\\'))) {
                    $selAvatar = ltrim($selected_user['profile_photo'], '/\\');
                }
            ?>
                <div class="emp-profile-header">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <img src="<?php echo htmlspecialchars($selAvatar); ?>" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary);">
                        <div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-main);">
                                <?php echo htmlspecialchars($selected_user['name']); ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 2px;">
                                <span class="badge" style="background: rgba(16,185,129,0.15); color: #047857; font-weight: 700; font-size: 0.725rem;">
                                    <?php echo htmlspecialchars($selected_user['role']); ?>
                                </span>
                                <span class="font-mono text-xs text-muted">EMP-<?php echo str_pad($selected_user['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                <span class="text-xs text-muted">• <?php echo htmlspecialchars($selected_user['email']); ?></span>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.65rem 1.25rem;">
                            💾 Save Permissions
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. Quick Preset Buttons Bar -->
            <div class="matrix-section-card">
                <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); uppercase; letter-spacing: 0.05em;">
                    ⚡ 1-CLICK ROLE PRESETS
                </div>
                <div class="preset-bar">
                    <button type="button" class="preset-pill" onclick="applyRolePreset('sales_exec')">Sales Executive Standard</button>
                    <button type="button" class="preset-pill" onclick="applyRolePreset('sales_power')">Sales Executive (Bulk Upload)</button>
                    <button type="button" class="preset-pill" onclick="applyRolePreset('manager')">Manager / Team Lead</button>
                    <button type="button" class="preset-pill" onclick="applyRolePreset('tech')">Tech / Support Team</button>
                    <button type="button" class="preset-pill" onclick="applyRolePreset('full_admin')">Full Admin Access</button>
                    <button type="button" class="preset-pill" style="background: rgba(239,68,68,0.1); color: #ef4444;" onclick="applyRolePreset('clear')">Revoke All</button>
                </div>
            </div>

            <!-- 3. SECTION 1: Action-Level Privilege Controls -->
            <div class="matrix-section-card">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                    <div style="font-size: 0.95rem; font-weight: 800; color: var(--primary);">
                        1. Action-Level Privilege Controls (9 Granular Actions)
                    </div>
                    <div class="text-xs text-muted">Controls operations like edit, delete, export, & share</div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.85rem;">
                    <?php foreach ($ACTION_PERMISSIONS as $actKey => $actMeta): 
                        $isChecked = in_array($actKey, $sel_act_perms);
                    ?>
                        <label style="background: var(--bg-body, #fafafa); border: 1px solid var(--border-color); border-radius: 10px; padding: 0.85rem; display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                            <input type="checkbox" name="actions[]" value="<?php echo $actKey; ?>" class="act-checkbox" id="chk-act-<?php echo $actKey; ?>" <?php echo $isChecked ? 'checked' : ''; ?> style="width: 18px; height: 18px; margin-top: 2px; accent-color: <?php echo $actMeta['color']; ?>;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                                    <i data-lucide="<?php echo $actMeta['icon']; ?>" style="width: 14px; height: 14px; color: <?php echo $actMeta['color']; ?>;"></i>
                                    <span><?php echo htmlspecialchars($actMeta['label']); ?></span>
                                </div>
                                <div style="font-size: 0.725rem; color: var(--text-muted); margin-top: 3px; line-height: 1.3;">
                                    <?php echo htmlspecialchars($actMeta['desc']); ?>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4. SECTION 2: Accessible Workspace Modules Categorized Grid -->
            <?php foreach ($MODULE_CATEGORIES as $catName => $catModules): ?>
                <div class="matrix-section-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.6rem;">
                        <div style="font-size: 0.9rem; font-weight: 800; color: var(--text-main);">
                            <?php echo htmlspecialchars($catName); ?>
                        </div>
                        <div style="display: flex; gap: 0.75rem;">
                            <button type="button" class="text-xs text-primary font-bold border-none bg-transparent cursor-pointer" onclick="toggleCategoryModules('<?php echo md5($catName); ?>', true)">Select Category</button>
                            <span class="text-muted text-xs">•</span>
                            <button type="button" class="text-xs text-muted font-bold border-none bg-transparent cursor-pointer" onclick="toggleCategoryModules('<?php echo md5($catName); ?>', false)">Deselect</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.75rem;" class="cat-group-<?php echo md5($catName); ?>">
                        <?php foreach ($catModules as $mKey => $mMeta): 
                            $isCheckedMod = in_array($mKey, $sel_mod_perms);
                        ?>
                            <label style="background: var(--bg-body, #fafafa); border: 1px solid var(--border-color); border-radius: 10px; padding: 0.75rem 0.85rem; display: flex; align-items: center; gap: 0.75rem; cursor: pointer;">
                                <input type="checkbox" name="modules[]" value="<?php echo $mKey; ?>" class="mod-checkbox" id="chk-mod-<?php echo $mKey; ?>" <?php echo $isCheckedMod ? 'checked' : ''; ?> style="width: 17px; height: 17px; accent-color: var(--primary);">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i data-lucide="<?php echo $mMeta['icon']; ?>" style="width: 16px; height: 16px; color: <?php echo $mMeta['color']; ?>;"></i>
                                    <span style="font-weight: 700; font-size: 0.83rem; color: var(--text-main);">
                                        <?php echo htmlspecialchars($mMeta['label']); ?>
                                    </span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- STICKY ACTION FOOTER -->
            <div class="permissions-sticky-footer">
                <div class="text-xs text-muted font-semibold">
                    Changes apply immediately to sidebar navigation and page route access.
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="index.php?page=admin_permissions&user_id=<?php echo $selected_user_id; ?>" class="btn btn-secondary text-xs">Reset Changes</a>
                    <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.65rem 1.5rem;">💾 Save Employee Permissions</button>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
function filterEmpList() {
    const query = document.getElementById('empSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.emp-select-card');
    cards.forEach(c => {
        const searchData = c.getAttribute('data-search') || '';
        c.style.display = searchData.includes(query) ? '' : 'none';
    });
}

function toggleCategoryModules(catMd5, select) {
    document.querySelectorAll('.cat-group-' + catMd5 + ' .mod-checkbox').forEach(cb => cb.checked = select);
}

function applyRolePreset(presetType) {
    if (presetType === 'clear') {
        document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = false);
        return;
    }

    document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = false);

    if (presetType === 'sales_exec') {
        ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share'].forEach(a => {
            const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
        });
        ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'team_inbox', 'privacy_policy', 'terms_conditions', 'refund_policy'].forEach(m => {
            const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
        });
    } else if (presetType === 'sales_power') {
        ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload'].forEach(a => {
            const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
        });
        ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'team_inbox', 'broadcast_campaigns', 'privacy_policy', 'terms_conditions', 'refund_policy'].forEach(m => {
            const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
        });
    } else if (presetType === 'manager') {
        ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'].forEach(a => {
            const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
        });
        ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'renewals', 'reports', 'team_inbox', 'broadcast_campaigns', 'bulk_broadcast', 'clients', 'privacy_policy', 'terms_conditions', 'refund_policy'].forEach(m => {
            const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
        });
    } else if (presetType === 'tech') {
        ['can_view', 'can_edit', 'can_update_status', 'can_share'].forEach(a => {
            const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
        });
        ['dashboard', 'installation', 'training', 'support', 'team_inbox', 'bot_flows', 'privacy_policy', 'terms_conditions', 'refund_policy'].forEach(m => {
            const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
        });
    } else if (presetType === 'full_admin') {
        document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = true);
        document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = true);
    }
}
</script>
