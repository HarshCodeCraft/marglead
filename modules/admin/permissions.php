<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_id = $_SESSION['user_id'] ?? 1;
$is_admin = ($role === 'Super Admin' || $role === 'Admin');

// Security check: Only Admins can access employee permissions management
if (!$is_admin) {
    $_GET['requested'] = 'admin_permissions';
    include_once __DIR__ . '/../access_denied.php';
    return;
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

// All Available Workspace Modules
$WORKSPACE_MODULES = [
    'dashboard' => 'Dashboard & Analytics',
    'leads' => 'Leads Directory',
    'pipeline' => 'Pipeline Kanban',
    'followups' => 'Follow-up Schedules',
    'demo' => 'Product Demos & Feedback',
    'quotation' => 'Quotations & Proposals',
    'payments' => 'Payments & Invoices',
    'bank_accounts' => 'Bank & QR Payment Details',
    'installation' => 'Technical Installations',
    'training' => 'Client Training',
    'support' => 'Support Desk & Tickets',
    'renewals' => 'Software Support Renewals',
    'reports' => 'Business Reports',
    'settings' => 'Control Settings'
];

// Process POST Save Granular Permissions Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_employee_permissions') {
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
            if ($target_user_id === $user_id) {
                $_SESSION['user_permissions'] = $selected_modules;
                $_SESSION['user_action_permissions'] = $selected_actions;
            }

            $message = "Employee permissions matrix updated and synchronized successfully across all modules.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error updating permissions: " . $e->getMessage();
            $message_type = "danger";
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

// Calculate permission metrics
$custom_override_count = 0;
foreach ($users_list as $u) {
    if (!empty($u['action_permissions']) || !empty($u['permissions'])) {
        $custom_override_count++;
    }
}
?>

<div class="permissions-module-container" style="max-width: 1200px; margin: 0 auto; padding-bottom: 4rem;">
    <!-- Page Header & Action Controls -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.85rem; font-weight: 800; color: var(--text-main);" class="mb-1 flex align-center gap-2">
                <i data-lucide="shield-check" style="width: 30px; height: 30px; color: var(--primary);"></i>
                <span>Employee Access Permission Matrix</span>
            </h2>
            <p class="text-muted text-sm m-0">
                Configure granular action privileges (Edit, Delete, Update, Share, Bulk Upload, Export) and module access per employee.
            </p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-6" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.9rem 1.25rem; width: 100%; display: flex; font-size: 0.875rem; border-radius: 12px; border: 1px solid rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.2);">
            <i data-lucide="info" style="width: 18px; height: 18px; margin-right: 0.6rem; flex-shrink: 0;"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary Stats Banner -->
    <div class="card p-6 mb-8 overflow-hidden" style="border: 1px solid var(--border-color); background: linear-gradient(135deg, rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.08) 0%, rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.02) 100%); border-radius: 16px;">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; align-items: center;">
            <div class="flex align-center gap-4">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="users" style="width: 24px; height: 24px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Total Employees</span>
                    <span class="font-bold text-main" style="font-size: 1.4rem; font-family: var(--font-heading);"><?php echo count($users_list); ?> Operators</span>
                </div>
            </div>

            <div class="flex align-center gap-4">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="sliders" style="width: 24px; height: 24px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Custom Overrides</span>
                    <span class="font-bold text-main" style="font-size: 1.4rem; font-family: var(--font-heading);"><?php echo $custom_override_count; ?> Configured</span>
                </div>
            </div>

            <div class="flex align-center gap-4">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); flex-shrink: 0;">
                    <i data-lucide="lock" style="width: 24px; height: 24px;"></i>
                </div>
                <div>
                    <span class="text-xs text-muted block uppercase font-bold" style="letter-spacing: 0.05em;">Granular Protection</span>
                    <span class="text-xs text-success block font-semibold mt-1">✓ Active System-Wide</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Role Filter Toolbar -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4 p-4 card" style="border: 1px solid var(--border-color); border-radius: 12px; background-color: var(--bg-card);">
        <div class="flex align-center gap-2 flex-1" style="max-width: 450px;">
            <i data-lucide="search" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
            <input type="text" id="user-perm-search" class="form-control text-sm" placeholder="Search employee name (e.g. Harsh), email, or operator ID..." oninput="filterUserCards()" style="border: none; background: transparent; padding: 0.35rem 0.5rem;">
        </div>

        <div class="flex align-center gap-3">
            <span class="text-xs text-muted font-semibold">Filter Role:</span>
            <select id="role-filter-select" class="form-control text-sm" onchange="filterUserCards()" style="padding: 0.35rem 0.75rem; width: 180px; height: 36px;">
                <option value="">All Roles & Teams</option>
                <?php 
                $filter_roles = (isset($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== 'marg_crm') ? $EMPLOYEE_ROLES : $ROLES;
                foreach ($filter_roles as $rName => $rDesc): 
                ?>
                    <option value="<?php echo $rName; ?>"><?php echo $rName; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Employee Permission Cards Directory Grid -->
    <div class="user-perm-grid" id="user-perm-grid">
        <?php foreach ($users_list as $user): ?>
            <?php 
            $user_act_perms = getUserActionPermissions($user);
            $user_mod_perms = getUserPermissions($user);
            
            $avatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
            if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../../' . ltrim($user['profile_photo'], '/\\'))) {
                $avatar = ltrim($user['profile_photo'], '/\\');
            }
            ?>
            <div class="user-perm-card card p-6" data-name="<?php echo htmlspecialchars(strtolower($user['name'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>" data-role="<?php echo htmlspecialchars(strtolower($user['role'])); ?>" data-id="emp-<?php echo str_pad($user['id'], 4, '0', STR_PAD_LEFT); ?>">
                
                <!-- User Header Badge -->
                <div class="flex justify-between align-start mb-4">
                    <div class="flex align-center gap-3">
                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($user['name']); ?>" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); box-shadow: var(--shadow-xs);">
                        <div>
                            <h3 class="text-base font-bold m-0 user-card-name" style="color: var(--text-main); line-height: 1.2;">
                                <?php echo htmlspecialchars($user['name']); ?>
                            </h3>
                            <span class="text-xs text-muted font-mono block mt-1">EMP-<?php echo str_pad($user['id'], 4, '0', STR_PAD_LEFT); ?> • <?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                    </div>
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.7rem; font-weight: 600;">
                        <?php echo htmlspecialchars($user['role']); ?>
                    </span>
                </div>

                <hr style="border: none; border-top: 1px solid var(--border-color); margin: 0.75rem 0 1rem 0;">

                <!-- Active Granular Action Privileges Badges -->
                <div class="mb-4">
                    <span class="text-xs text-muted block uppercase font-bold mb-2" style="letter-spacing: 0.05em;">Granted Action Permissions</span>
                    <div class="flex flex-wrap gap-1">
                        <?php 
                        foreach ($ACTION_PERMISSIONS as $actKey => $actMeta):
                            $has_act = in_array($actKey, $user_act_perms);
                            if ($has_act):
                        ?>
                            <span class="badge" style="--badge-bg: <?php echo $actMeta['color']; ?>15; --badge-color: <?php echo $actMeta['color']; ?>; font-size: 0.68rem; padding: 0.2rem 0.5rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i data-lucide="<?php echo $actMeta['icon']; ?>" style="width: 11px; height: 11px;"></i>
                                <span><?php echo htmlspecialchars($actMeta['label']); ?></span>
                            </span>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>

                <!-- Active Modules Count Badge -->
                <div class="flex justify-between align-center p-3 mb-4" style="background-color: var(--border-card); border-radius: 8px; border: 1px solid var(--border-color);">
                    <span class="text-xs text-muted font-semibold">Accessible Modules:</span>
                    <span class="font-bold text-xs text-main"><?php echo count($user_mod_perms); ?> / <?php echo count($WORKSPACE_MODULES); ?> Modules Enabled</span>
                </div>

                <!-- Footer Action Button -->
                <button type="button" class="btn btn-primary text-xs w-100 flex align-center justify-center gap-2" onclick="openConfigurePermissionsModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" style="padding: 0.6rem; font-weight: 700;">
                    <i data-lucide="sliders" style="width: 14px; height: 14px;"></i>
                    <span>Configure Employee Permissions</span>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- CONFIGURE GRANULAR PERMISSIONS MODAL -->
<div class="modal-overlay" id="configure-perm-modal">
    <div class="modal-content" style="max-width: 800px; width: 94%;">
        <div class="modal-header">
            <h3 class="modal-title">Configure Employee Permissions Matrix</h3>
            <button class="modal-close" onclick="closeModal('configure-perm-modal')">&times;</button>
        </div>
        <form action="index.php?page=admin_permissions" method="POST" id="perm-matrix-form">
            <input type="hidden" name="action" value="save_employee_permissions">
            <input type="hidden" name="user_id" id="perm-modal-user-id" value="0">

            <div class="modal-body p-6">
                <!-- User Profile Summary Header in Modal -->
                <div class="flex align-center gap-4 p-4 mb-6" style="background-color: var(--border-card); border-radius: 12px; border: 1px solid var(--border-color);">
                    <img id="perm-modal-avatar" src="" style="width: 54px; height: 54px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary);">
                    <div>
                        <h4 class="text-base font-bold m-0" id="perm-modal-user-name" style="color: var(--text-main);">User Name</h4>
                        <div class="flex align-center gap-2 mt-1">
                            <span class="badge" id="perm-modal-user-role" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.7rem;">Role</span>
                            <span class="text-xs text-muted font-mono" id="perm-modal-user-email">email@domain.com</span>
                        </div>
                    </div>
                </div>

                <!-- Department Presets Bar -->
                <div class="mb-6">
                    <span class="text-xs text-muted block uppercase font-bold mb-2" style="letter-spacing: 0.05em;">Quick Role Presets:</span>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary text-xs" onclick="applyRolePreset('sales_exec')">Sales Executive Standard</button>
                        <button type="button" class="btn btn-secondary text-xs" onclick="applyRolePreset('sales_power')">Sales Executive (With Bulk Upload)</button>
                        <button type="button" class="btn btn-secondary text-xs" onclick="applyRolePreset('manager')">Manager / Team Lead</button>
                        <button type="button" class="btn btn-secondary text-xs" onclick="applyRolePreset('tech')">Tech / Support Team</button>
                        <button type="button" class="btn btn-secondary text-xs" onclick="applyRolePreset('full_admin')">Full Admin Access</button>
                    </div>
                </div>

                <!-- SECTION 1: Granular Action Permissions (Toggles) -->
                <div class="mb-6 pt-4" style="border-top: 1px solid var(--border-color);">
                    <h4 class="text-sm font-bold text-main uppercase mb-3" style="letter-spacing: 0.05em; color: var(--primary);">
                        1. Action-Level Privilege Controls
                    </h4>
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                        <?php foreach ($ACTION_PERMISSIONS as $actKey => $actMeta): ?>
                            <div class="action-perm-box p-3" style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 10px;">
                                <label class="flex align-start gap-3 cursor-pointer" style="margin: 0;">
                                    <input type="checkbox" name="actions[]" value="<?php echo $actKey; ?>" class="act-checkbox" id="chk-act-<?php echo $actKey; ?>" style="width: 18px; height: 18px; margin-top: 2px; accent-color: <?php echo $actMeta['color']; ?>;">
                                    <div>
                                        <span class="font-bold text-xs text-main flex align-center gap-1">
                                            <i data-lucide="<?php echo $actMeta['icon']; ?>" style="width: 13px; height: 13px; color: <?php echo $actMeta['color']; ?>;"></i>
                                            <span><?php echo htmlspecialchars($actMeta['label']); ?></span>
                                        </span>
                                        <span class="text-xs text-muted block mt-1" style="font-size: 0.725rem; line-height: 1.3;"><?php echo htmlspecialchars($actMeta['desc']); ?></span>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- SECTION 2: Accessible Workspace Modules (Checkboxes) -->
                <div class="pt-4" style="border-top: 1px solid var(--border-color);">
                    <div class="flex justify-between align-center mb-3">
                        <h4 class="text-sm font-bold text-main uppercase m-0" style="letter-spacing: 0.05em; color: var(--primary);">
                            2. Accessible Workspace Modules
                        </h4>
                        <div class="flex gap-2">
                            <button type="button" class="text-xs text-primary cursor-pointer border-none bg-transparent" onclick="selectAllModules(true)">Select All</button>
                            <span class="text-muted text-xs">•</span>
                            <button type="button" class="text-xs text-muted cursor-pointer border-none bg-transparent" onclick="selectAllModules(false)">Deselect All</button>
                        </div>
                    </div>

                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                        <?php foreach ($WORKSPACE_MODULES as $modKey => $modLabel): ?>
                            <div class="module-perm-box p-3" style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px;">
                                <label class="flex align-center gap-2 cursor-pointer" style="margin: 0;">
                                    <input type="checkbox" name="modules[]" value="<?php echo $modKey; ?>" class="mod-checkbox" id="chk-mod-<?php echo $modKey; ?>" style="width: 16px; height: 16px; accent-color: var(--primary);">
                                    <span class="text-xs font-semibold text-main"><?php echo htmlspecialchars($modLabel); ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeModal('configure-perm-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.6rem 1.5rem;">Save Employee Permissions</button>
            </div>
        </form>
    </div>
</div>

<style>
.user-perm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 1.5rem;
}

.user-perm-card {
    border: 1px solid var(--border-color);
    background-color: var(--bg-card);
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.user-perm-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.action-perm-box, .module-perm-box {
    transition: border-color 0.2s ease, background-color 0.2s ease;
}

.action-perm-box:hover, .module-perm-box:hover {
    border-color: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.4) !important;
}

@media (max-width: 768px) {
    .user-perm-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
    function filterUserCards() {
        const query = document.getElementById('user-perm-search').value.toLowerCase().trim();
        const roleFilter = document.getElementById('role-filter-select').value.toLowerCase().trim();
        const cards = document.querySelectorAll('.user-perm-card');

        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const email = card.getAttribute('data-email') || '';
            const id = card.getAttribute('data-id') || '';
            const role = card.getAttribute('data-role') || '';

            const matchesSearch = !query || name.includes(query) || email.includes(query) || id.includes(query);
            const matchesRole = !roleFilter || role === roleFilter;

            card.style.display = (matchesSearch && matchesRole) ? '' : 'none';
        });
    }

    function openConfigurePermissionsModal(user) {
        document.getElementById('perm-modal-user-id').value = user.id;
        document.getElementById('perm-modal-user-name').textContent = user.name;
        document.getElementById('perm-modal-user-role').textContent = user.role;
        document.getElementById('perm-modal-user-email').textContent = user.email;

        // Set avatar image
        const avatarImg = document.getElementById('perm-modal-avatar');
        let avatarSrc = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
        if (user.profile_photo) {
            avatarSrc = user.profile_photo;
        }
        avatarImg.src = avatarSrc;

        // Reset all checkboxes
        document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = false);

        // Parse user active action permissions
        let actPerms = [];
        if (user.action_permissions) {
            try { actPerms = JSON.parse(user.action_permissions); } catch(e){}
        }
        if (!Array.isArray(actPerms) || actPerms.length === 0) {
            // Default based on role
            actPerms = getDefaultActionsByRole(user.role);
        }

        actPerms.forEach(act => {
            const cb = document.getElementById('chk-act-' + act);
            if (cb) cb.checked = true;
        });

        // Parse user active module permissions
        let modPerms = [];
        if (user.permissions) {
            try { modPerms = JSON.parse(user.permissions); } catch(e){}
        }
        if (!Array.isArray(modPerms) || modPerms.length === 0) {
            modPerms = ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation'];
        }

        modPerms.forEach(mod => {
            const cb = document.getElementById('chk-mod-' + mod);
            if (cb) cb.checked = true;
        });

        openModal('configure-perm-modal');
    }

    function getDefaultActionsByRole(role) {
        if (role === 'Super Admin' || role === 'Admin') {
            return ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'];
        }
        if (role === 'Regional Manager' || role === 'Team Leader') {
            return ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'];
        }
        return ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share'];
    }

    function selectAllModules(select) {
        document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = select);
    }

    function applyRolePreset(presetType) {
        document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = false);

        if (presetType === 'sales_exec') {
            ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share'].forEach(a => {
                const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
            });
            ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments'].forEach(m => {
                const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
            });
        } else if (presetType === 'sales_power') {
            ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload'].forEach(a => {
                const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
            });
            ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments'].forEach(m => {
                const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
            });
        } else if (presetType === 'manager') {
            ['can_view', 'can_create', 'can_edit', 'can_update_status', 'can_share', 'can_bulk_upload', 'can_export', 'can_assign'].forEach(a => {
                const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
            });
            ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'renewals', 'reports'].forEach(m => {
                const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
            });
        } else if (presetType === 'tech') {
            ['can_view', 'can_edit', 'can_update_status', 'can_share'].forEach(a => {
                const cb = document.getElementById('chk-act-' + a); if (cb) cb.checked = true;
            });
            ['dashboard', 'installation', 'training', 'support'].forEach(m => {
                const cb = document.getElementById('chk-mod-' + m); if (cb) cb.checked = true;
            });
        } else if (presetType === 'full_admin') {
            document.querySelectorAll('.act-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.mod-checkbox').forEach(cb => cb.checked = true);
        }
    }
</script>
