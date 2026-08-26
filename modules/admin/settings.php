<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$isAdmin = (isset($_SESSION['login_role']) && ($_SESSION['login_role'] === 'Admin' || $_SESSION['login_role'] === 'Super Admin'));

$flash_msg = '';
$flash_type = '';

// Handle POST submissions inside settings module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_action'])) {
    if (!verifyCsrfToken()) {
        $flash_msg = "Security Violation: Invalid or missing CSRF token. Request denied.";
        $flash_type = "danger";
    } else {
        $action = $_POST['settings_action'];

        if ($action === 'save_company_settings' && $isAdmin) {
            setSystemSetting('company_name', trim($_POST['company_name'] ?? ''));
            setSystemSetting('company_email', trim($_POST['company_email'] ?? ''));
            setSystemSetting('company_phone', trim($_POST['company_phone'] ?? ''));
            setSystemSetting('company_gstin', trim($_POST['company_gstin'] ?? ''));
            setSystemSetting('company_pan', trim($_POST['company_pan'] ?? ''));
            setSystemSetting('company_address', trim($_POST['company_address'] ?? ''));
            setSystemSetting('company_website', trim($_POST['company_website'] ?? ''));
            
            $flash_msg = "Company profile details & tax parameters saved successfully.";
            $flash_type = "success";
        }

        if ($action === 'save_branding_settings' && $isAdmin) {
            setSystemSetting('default_theme', trim($_POST['default_theme'] ?? 'dark'));
            setSystemSetting('primary_color', trim($_POST['primary_color'] ?? '#3b82f6'));
            setSystemSetting('system_title', trim($_POST['system_title'] ?? 'MARG Lead CRM'));

            // Upload Logo Asset securely
            if (isset($_FILES['branding_logo']) && $_FILES['branding_logo']['error'] === UPLOAD_ERR_OK) {
                $upRes = secureFileUpload($_FILES['branding_logo'], 'branding', ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'], ['jpg', 'jpeg', 'png', 'webp', 'svg']);
                if ($upRes['success']) {
                    setSystemSetting('branding_logo', $upRes['file_path']);
                } else {
                    $flash_msg = $upRes['error'];
                    $flash_type = "danger";
                }
            }

            if (empty($flash_msg)) {
                $flash_msg = "Branding theme, colors, and UI defaults updated successfully.";
                $flash_type = "success";
            }
        }

        if ($action === 'save_security_settings' && $isAdmin) {
            setSystemSetting('enforce_otp', isset($_POST['enforce_otp']) ? '1' : '0');
            setSystemSetting('session_timeout', intval($_POST['session_timeout'] ?? 30));
            setSystemSetting('password_policy', trim($_POST['password_policy'] ?? 'medium'));
            
            $flash_msg = "Security parameters and authentication options saved.";
            $flash_type = "success";
        }

        if ($action === 'download_sql_backup' && $isAdmin) {
            // Generate SQL Backup Download
            if ($db_connected && $pdo) {
                try {
                    $tables = [];
                    $stmt = $pdo->query("SHOW TABLES");
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                        $tables[] = $row[0];
                    }
                    
                    $sqlDump = "-- MARG Lead CRM Database Backup\n";
                    $sqlDump .= "-- Generated on " . date('Y-m-d H:i:s') . "\n";
                    $sqlDump .= "-- Database: marglead\n\n";
                    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
                    
                    foreach ($tables as $t) {
                        $sqlDump .= "-- --------------------------------------------------------\n";
                        $sqlDump .= "-- Table structure for `$t`\n";
                        $sqlDump .= "-- --------------------------------------------------------\n";
                        $sqlDump .= "DROP TABLE IF EXISTS `$t`;\n";
                        $stmtC = $pdo->query("SHOW CREATE TABLE `$t`");
                        $createRow = $stmtC->fetch(PDO::FETCH_NUM);
                        $sqlDump .= $createRow[1] . ";\n\n";
                        
                        $stmtD = $pdo->query("SELECT * FROM `$t`");
                        while ($dataRow = $stmtD->fetch(PDO::FETCH_ASSOC)) {
                            $keys = array_map(function($k){ return "`$k`"; }, array_keys($dataRow));
                            $vals = array_map(function($v) use ($pdo) { 
                                return $v === null ? "NULL" : $pdo->quote($v); 
                            }, array_values($dataRow));
                            $sqlDump .= "INSERT INTO `$t` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
                        }
                        $sqlDump .= "\n";
                    }
                    
                    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
                    
                    ob_clean();
                    header('Content-Type: application/sql');
                    header('Content-Disposition: attachment; filename="marglead_backup_' . date('Y-m-d_H-i') . '.sql"');
                    header('Content-Length: ' . strlen($sqlDump));
                    echo $sqlDump;
                    exit;
                } catch (Exception $e) {
                    $flash_msg = "Database export error: " . $e->getMessage();
                    $flash_type = "danger";
                }
            }
        }
    }
}

// Fetch current user data
$user_data = null;
if ($db_connected && $pdo && isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $user_data = null;
    }
}

if (!$user_data) {
    $user_data = [
        'id' => $_SESSION['user_id'] ?? 1,
        'name' => $_SESSION['user_name'] ?? 'Harsh Vardhan',
        'email' => $_SESSION['user_email'] ?? 'admin@marglead.com',
        'role' => $_SESSION['user_role'] ?? 'Admin',
        'status' => 'Active',
        'created_at' => date('Y-m-d H:i:s'),
        'permissions' => null
    ];
}

// Load System Settings
$company_name = getSystemSetting('company_name', 'Marg ERP Limited');
$company_email = getSystemSetting('company_email', 'sales@margerp.com');
$company_phone = getSystemSetting('company_phone', '+91 11 4500 9000');
$company_gstin = getSystemSetting('company_gstin', '07AAAAM4509A1Z2');
$company_pan = getSystemSetting('company_pan', 'AAAAM4509A');
$company_address = getSystemSetting('company_address', 'Marg House, Plot No. 2, Wazirpur Industrial Area, New Delhi - 110052');
$company_website = getSystemSetting('company_website', 'https://margerp.com');

$default_theme = getSystemSetting('default_theme', 'dark');
$primary_color = getSystemSetting('primary_color', '#3b82f6');
$system_title = getSystemSetting('system_title', 'MARG Lead & CRM Workspace');
$branding_logo = getSystemSetting('branding_logo', '');

$enforce_otp = getSystemSetting('enforce_otp', '0');
$session_timeout = getSystemSetting('session_timeout', '30');
$password_policy = getSystemSetting('password_policy', 'medium');

// Modules list for permissions matrix mapping
$modules = [
    'dashboard' => 'Dashboard Workspace',
    'leads' => 'Leads Directory',
    'pipeline' => 'Kanban Pipeline',
    'followups' => 'Follow-up Planner',
    'demo' => 'Demos & Feedback',
    'quotation' => 'Quotations Proposal',
    'payments' => 'Invoicing & Receipts',
    'installation' => 'Installation Checklist',
    'training' => 'Operator Training',
    'support' => 'Helpdesk Tickets',
    'renewals' => 'Renewals Manager',
    'reports' => 'Reports & Audits',
    'privacy_policy' => 'Privacy Policy Document',
    'terms_conditions' => 'Terms & Conditions Agreement',
    'refund_policy' => 'Refund Policy Document',
    'settings' => 'Control Settings'
];
?>

<div class="settings-container" style="max-width: 960px; margin: 0 auto;">
    
    <!-- Flash Messages -->
    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="card p-4 mb-4 border-success flex justify-between align-center" style="background: var(--success-light); color: var(--success); border-radius: 10px;">
            <div class="flex align-center gap-2">
                <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i>
                <span class="font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="card p-4 mb-4 border-danger flex justify-between align-center" style="background: var(--danger-light); color: var(--danger); border-radius: 10px;">
            <div class="flex align-center gap-2">
                <i data-lucide="alert-triangle" style="width: 18px; height: 18px;"></i>
                <span class="font-semibold text-sm"><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($flash_msg)): ?>
        <div class="card p-4 mb-4 border-<?php echo $flash_type; ?> flex justify-between align-center" style="background: var(--<?php echo $flash_type; ?>-light); color: var(--<?php echo $flash_type; ?>); border-radius: 10px;">
            <div class="flex align-center gap-2">
                <i data-lucide="info" style="width: 18px; height: 18px;"></i>
                <span class="font-semibold text-sm"><?php echo htmlspecialchars($flash_msg); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top Header -->
    <div class="mb-6">
        <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">
            <?php echo $isAdmin ? 'System Control Settings' : 'My Operator Profile'; ?>
        </h2>
        <p class="text-muted text-sm">
            <?php echo $isAdmin ? 'Configure company profile details, branding assets, email defaults, database backup actions, and security parameters.' : 'View your operational details, roles privileges, and update account credentials.'; ?>
        </p>
    </div>

    <!-- Main Workspace Tabs Layout -->
    <div class="grid" style="grid-template-columns: <?php echo $isAdmin ? '240px minmax(0, 1fr)' : '1fr'; ?>; gap: 1.5rem; align-items: start;">
        
        <?php if ($isAdmin): ?>
        <!-- Left Navigation Tabs (Admin) -->
        <div class="card p-2 flex flex-col gap-1" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 12px;">
            <button class="btn btn-primary active settings-tab-btn text-xs" data-settings="settings-profile" style="justify-content: flex-start; padding: 0.65rem 1rem; border-radius: 8px;">
                <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                <span>My Profile</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-company" style="justify-content: flex-start; padding: 0.65rem 1rem; border-radius: 8px;">
                <i data-lucide="building" style="width: 16px; height: 16px;"></i>
                <span>Company Profile</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-branding" style="justify-content: flex-start; padding: 0.65rem 1rem; border-radius: 8px;">
                <i data-lucide="palette" style="width: 16px; height: 16px;"></i>
                <span>Branding & Theme</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-security" style="justify-content: flex-start; padding: 0.65rem 1rem; border-radius: 8px;">
                <i data-lucide="shield" style="width: 16px; height: 16px;"></i>
                <span>Security & Backups</span>
            </button>
        </div>
        <?php endif; ?>

        <!-- Right Workspace Panels -->
        <div class="flex flex-col gap-4">
            
            <!-- PANEL 0: My Profile (Visible to all users) -->
            <div id="settings-profile" class="settings-pane active">
                
                <!-- Profile Card View -->
                <div id="profile-view-card" class="card p-0 overflow-hidden mb-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 14px; position: relative; box-shadow: var(--shadow-md);">
                    <!-- Header Cover Banner -->
                    <div style="background: linear-gradient(135deg, var(--primary), #4f46e5); height: 140px; border-bottom: 1px solid var(--border-color); position: relative;">
                        <button class="btn btn-secondary text-xs" onclick="toggleProfileEdit(true)" style="position: absolute; bottom: 1rem; right: 1rem; padding: 0.4rem 0.85rem; background-color: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3); color: #fff; backdrop-filter: blur(10px); border-radius: 8px;">
                            <i data-lucide="edit-3" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                            <span>Edit Profile</span>
                        </button>
                    </div>

                    <!-- Profile Photo Overlay -->
                    <div style="position: relative; margin-top: -60px; display: flex; align-items: flex-end; justify-content: space-between; padding: 0 2rem; flex-wrap: wrap;">
                        <div class="flex align-center gap-4" style="align-items: flex-end;">
                            <?php 
                            $profile_avatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
                            $raw_photo = $user_data['profile_photo'] ?? $_SESSION['user_photo'] ?? '';
                            if (!empty($raw_photo)) {
                                $clean_photo = ltrim($raw_photo, '/\\');
                                if (file_exists(__DIR__ . '/../../' . $clean_photo)) {
                                    $profile_avatar = $clean_photo;
                                }
                            }
                            ?>
                            <div style="position: relative; cursor: pointer;" onclick="triggerAvatarUpload()" title="Click to change profile avatar">
                                <img id="avatar-preview-img" src="<?php echo htmlspecialchars($profile_avatar); ?>" style="width: 110px; height: 110px; border-radius: 50%; border: 4px solid var(--bg-card); object-fit: cover; box-shadow: var(--shadow-md); transition: transform 0.2s ease;">
                                <div style="position: absolute; bottom: 4px; right: 4px; background-color: var(--primary); color: #fff; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-card); box-shadow: var(--shadow-sm);" title="Update Avatar">
                                    <i data-lucide="camera" style="width: 15px; height: 15px;"></i>
                                </div>
                            </div>
                            <div style="margin-bottom: 0.5rem;">
                                <h3 style="font-family: var(--font-heading); font-size: 1.4rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.2rem; line-height: 1.2;">
                                    <?php echo htmlspecialchars($user_data['name']); ?>
                                </h3>
                                <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.6rem;">
                                    <?php echo htmlspecialchars($user_data['role']); ?>
                                </span>
                            </div>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-size: 0.75rem; font-weight: 600; padding: 0.35rem 0.75rem; border-radius: 20px; display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background-color: var(--success); display: inline-block;"></span>
                                <span><?php echo htmlspecialchars($user_data['status'] ?? 'Active'); ?></span>
                            </span>
                        </div>
                    </div>

                    <!-- Profile Details -->
                    <div class="p-6">
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                            <h4 class="text-xs uppercase text-muted font-bold mb-4" style="letter-spacing: 0.05em;">Employee Credentials</h4>
                            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem;">
                                <div>
                                    <span class="text-xs text-muted block mb-1">Operator ID</span>
                                    <span class="text-sm font-bold text-main">EMP-<?php echo str_pad($user_data['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-muted block mb-1">Email Address</span>
                                    <span class="text-sm font-semibold text-main"><?php echo htmlspecialchars($user_data['email']); ?></span>
                                </div>
                                <div>
                                    <span class="text-xs text-muted block mb-1">Joined Date</span>
                                    <span class="text-sm font-semibold text-main"><?php echo date('F d, Y', strtotime($user_data['created_at'] ?? 'now')); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- User Custom Privileges List -->
                        <div class="mt-6" style="border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                            <h4 class="text-xs uppercase text-muted font-bold mb-3" style="letter-spacing: 0.05em;">Assigned Workspace Privileges</h4>
                            <div class="flex flex-wrap gap-2">
                                <?php 
                                $current_perms = getUserPermissions($user_data);
                                foreach ($modules as $key => $label): 
                                    if (in_array($key, $current_perms)):
                                ?>
                                    <span class="badge" style="--badge-bg: rgba(99, 102, 241, 0.1); --badge-color: var(--primary); padding: 0.4rem 0.75rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; border: 1px solid rgba(99, 102, 241, 0.2); display: inline-flex; align-items: center; gap: 0.25rem;">
                                        <i data-lucide="check" style="width: 12px; height: 12px;"></i>
                                        <span><?php echo htmlspecialchars($label); ?></span>
                                    </span>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Edit Form Card -->
                <form id="profile-edit-card" class="card p-6 hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 14px;" action="index.php?action=update_profile" method="POST" enctype="multipart/form-data">
                    <?php echo renderCsrfInput(); ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                        <h3 class="text-sm font-bold uppercase m-0" style="color: var(--primary);">Update Account Credentials</h3>
                        <button type="button" class="btn btn-secondary text-xs" onclick="toggleProfileEdit(false)" style="padding: 0.3rem 0.6rem;">Cancel</button>
                    </div>
                    
                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold mb-1">Full Name</label>
                            <input type="text" name="name" class="form-control text-xs" value="<?php echo htmlspecialchars($user_data['name']); ?>" required style="height: 38px;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold mb-1">Corporate Email Address</label>
                            <input type="email" name="email" class="form-control text-xs" value="<?php echo htmlspecialchars($user_data['email']); ?>" required style="height: 38px;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold mb-1">Access Role</label>
                            <input type="text" class="form-control text-xs" value="<?php echo htmlspecialchars($user_data['role']); ?>" disabled style="background-color: var(--border-card); opacity: 0.8; height: 38px;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold mb-1">Operational Status</label>
                            <input type="text" class="form-control text-xs" value="<?php echo htmlspecialchars($user_data['status'] ?? 'Active'); ?>" disabled style="background-color: var(--border-card); opacity: 0.8; height: 38px;">
                        </div>
                        <div class="form-group m-0" style="grid-column: span 2;">
                            <label class="form-label text-xs font-semibold mb-1">Profile Avatar Image</label>
                            <div class="flex align-center gap-4 mt-1">
                                <img id="edit-form-avatar-preview" src="<?php echo htmlspecialchars($profile_avatar); ?>" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color);">
                                <div style="flex: 1;">
                                    <input type="file" name="profile_photo" id="profile-photo-input" class="form-control text-xs" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewSelectedAvatar(this)" style="padding: 0.35rem 0.5rem; width: 100%;">
                                    <span class="text-xs text-muted block mt-1">Upload a JPG, PNG, or WEBP image (Max size 5MB).</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group m-0" style="grid-column: span 2;">
                            <label class="form-label text-xs font-semibold mb-1">Update Password (Leave blank to keep current password)</label>
                            <input type="password" name="new_password" class="form-control text-xs" placeholder="Enter new account password" style="height: 38px;">
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-4">
                        <button type="submit" class="btn btn-primary text-xs font-bold px-6" style="height: 38px;">
                            <i data-lucide="save" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                            <span>Save Profile Info</span>
                        </button>
                    </div>
                </form>

            </div>

            <?php if ($isAdmin): ?>
            <!-- PANEL 1: Company Profile (Admin) -->
            <form id="settings-company" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 14px;" action="index.php?page=settings" method="POST">
                <?php echo renderCsrfInput(); ?>
                <input type="hidden" name="settings_action" value="save_company_settings">
                <h3 class="text-xs uppercase text-muted font-bold mb-4 flex align-center gap-2" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; color: var(--primary); letter-spacing: 0.05em;">
                    <i data-lucide="building" style="width: 16px; height: 16px;"></i>
                    <span>Registered Business & Tax Parameters</span>
                </h3>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold mb-1">Registered Business Name</label>
                        <input type="text" name="company_name" class="form-control text-xs" value="<?php echo htmlspecialchars($company_name); ?>" required style="height: 38px;">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Corporate Support Email</label>
                        <input type="email" name="company_email" class="form-control text-xs" value="<?php echo htmlspecialchars($company_email); ?>" required style="height: 38px;">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Customer Phone / Helpline</label>
                        <input type="text" name="company_phone" class="form-control text-xs" value="<?php echo htmlspecialchars($company_phone); ?>" required style="height: 38px;">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Business GSTIN</label>
                        <input type="text" name="company_gstin" class="form-control text-xs" value="<?php echo htmlspecialchars($company_gstin); ?>" style="height: 38px;">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Company PAN Number</label>
                        <input type="text" name="company_pan" class="form-control text-xs" value="<?php echo htmlspecialchars($company_pan); ?>" style="height: 38px;">
                    </div>
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold mb-1">Official Portal Website URL</label>
                        <input type="url" name="company_website" class="form-control text-xs" value="<?php echo htmlspecialchars($company_website); ?>" style="height: 38px;">
                    </div>
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold mb-1">Registered Headquarters Address</label>
                        <textarea name="company_address" class="form-control text-xs" rows="3"><?php echo htmlspecialchars($company_address); ?></textarea>
                    </div>
                </div>

                <div class="flex justify-end mt-4">
                    <button type="submit" class="btn btn-primary text-xs font-bold px-6" style="height: 38px;">
                        <i data-lucide="check" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                        <span>Save Company Settings</span>
                    </button>
                </div>
            </form>

            <!-- PANEL 2: Branding & Theme Settings (Admin) -->
            <form id="settings-branding" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 14px;" action="index.php?page=settings" method="POST" enctype="multipart/form-data">
                <?php echo renderCsrfInput(); ?>
                <input type="hidden" name="settings_action" value="save_branding_settings">
                <h3 class="text-xs uppercase text-muted font-bold mb-4 flex align-center gap-2" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; color: var(--primary); letter-spacing: 0.05em;">
                    <i data-lucide="palette" style="width: 16px; height: 16px;"></i>
                    <span>UI Theme Aesthetics & System Branding</span>
                </h3>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold mb-1">System Workspace Title</label>
                        <input type="text" name="system_title" class="form-control text-xs" value="<?php echo htmlspecialchars($system_title); ?>" required style="height: 38px;">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Default Workspace Theme Mode</label>
                        <select name="default_theme" class="form-control text-xs" style="height: 38px;">
                            <option value="dark" <?php echo ($default_theme === 'dark') ? 'selected' : ''; ?>>🌙 Sleek Dark Mode (Default)</option>
                            <option value="light" <?php echo ($default_theme === 'light') ? 'selected' : ''; ?>>☀️ Vibrant Light Mode</option>
                        </select>
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold mb-1">Primary Accent Color</label>
                        <div class="flex align-center gap-2">
                            <input type="color" id="primaryColorPicker" class="form-control p-0" style="width: 50px; height: 38px; cursor: pointer;" value="<?php echo htmlspecialchars($primary_color); ?>" onchange="document.getElementById('primaryColorText').value = this.value">
                            <input type="text" id="primaryColorText" name="primary_color" class="form-control text-xs" value="<?php echo htmlspecialchars($primary_color); ?>" style="height: 38px;">
                        </div>
                    </div>
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold mb-1">Branding Logo Asset</label>
                        <?php if (!empty($branding_logo) && file_exists(__DIR__ . '/../../' . $branding_logo)): ?>
                            <div class="mb-2 p-2 bg-body border rounded flex align-center gap-3">
                                <img src="<?php echo htmlspecialchars($branding_logo); ?>" style="height: 36px; max-width: 180px; object-fit: contain;">
                                <span class="text-xs text-success font-semibold">Active Logo Uploaded</span>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="branding_logo" class="form-control text-xs" accept="image/*" style="padding: 0.35rem 0.5rem;">
                        <span class="text-xs text-muted mt-1 block">Supports transparent PNG, SVG, or WEBP logos. Suggested size 250x60px.</span>
                    </div>
                </div>

                <div class="flex justify-end mt-4">
                    <button type="submit" class="btn btn-primary text-xs font-bold px-6" style="height: 38px;">
                        <i data-lucide="check" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                        <span>Save Branding Settings</span>
                    </button>
                </div>
            </form>

            <!-- PANEL 3: Security & Database Backups (Admin) -->
            <div id="settings-security" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: 14px;">
                <h3 class="text-xs uppercase text-muted font-bold mb-4 flex align-center gap-2" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; color: var(--primary); letter-spacing: 0.05em;">
                    <i data-lucide="shield" style="width: 16px; height: 16px;"></i>
                    <span>Security Operations & SQL Database Backups</span>
                </h3>

                <!-- 1-Click Database Dump Export Form -->
                <form action="index.php?page=settings" method="POST" class="mb-6 p-4" style="background-color: var(--bg-body); border-radius: 10px; border: 1px solid var(--border-color);">
                    <?php echo renderCsrfInput(); ?>
                    <input type="hidden" name="settings_action" value="download_sql_backup">
                    <div class="flex align-center justify-between flex-wrap gap-3">
                        <div>
                            <span class="font-bold text-sm text-main block">Download Live SQL Database Backup</span>
                            <span class="text-xs text-muted">Generates a complete `.sql` dump of all tables, schema structure, and data records.</span>
                        </div>
                        <button type="submit" class="btn btn-secondary text-xs font-bold" style="height: 38px; display: inline-flex; align-center; gap: 0.35rem;">
                            <i data-lucide="download" style="width: 15px; height: 15px;"></i>
                            <span>Download .SQL Backup</span>
                        </button>
                    </div>
                </form>

                <!-- Security Form -->
                <form action="index.php?page=settings" method="POST">
                    <?php echo renderCsrfInput(); ?>
                    <input type="hidden" name="settings_action" value="save_security_settings">
                    
                    <div class="flex flex-col gap-4">
                        <!-- Enforce OTP -->
                        <label class="flex align-center gap-3 pointer p-3" style="background-color: var(--bg-body); border-radius: 10px; border: 1px solid var(--border-color);">
                            <input type="checkbox" name="enforce_otp" value="1" <?php echo ($enforce_otp === '1') ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <div class="flex flex-col">
                                <span class="font-semibold text-xs text-main">Enforce Email OTP Verification at Sign-In</span>
                                <span class="text-xs text-muted">Requires users to verify a 6-digit one-time passcode sent to their corporate email.</span>
                            </div>
                        </label>

                        <!-- Session Timeout & Password Policy -->
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group m-0">
                                <label class="form-label text-xs font-semibold mb-1">Session Inactivity Timeout (Minutes)</label>
                                <select name="session_timeout" class="form-control text-xs" style="height: 38px;">
                                    <option value="15" <?php echo ($session_timeout == '15') ? 'selected' : ''; ?>>15 Minutes</option>
                                    <option value="30" <?php echo ($session_timeout == '30') ? 'selected' : ''; ?>>30 Minutes (Recommended)</option>
                                    <option value="60" <?php echo ($session_timeout == '60') ? 'selected' : ''; ?>>1 Hour</option>
                                    <option value="120" <?php echo ($session_timeout == '120') ? 'selected' : ''; ?>>2 Hours</option>
                                </select>
                            </div>
                            <div class="form-group m-0">
                                <label class="form-label text-xs font-semibold mb-1">Password Enforce Complexity</label>
                                <select name="password_policy" class="form-control text-xs" style="height: 38px;">
                                    <option value="standard" <?php echo ($password_policy === 'standard') ? 'selected' : ''; ?>>Standard (Min 6 Characters)</option>
                                    <option value="medium" <?php echo ($password_policy === 'medium') ? 'selected' : ''; ?>>Medium (Min 8 Characters + Numbers)</option>
                                    <option value="strict" <?php echo ($password_policy === 'strict') ? 'selected' : ''; ?>>Strict (Upper/Lower + Special Symbol)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end mt-4">
                        <button type="submit" class="btn btn-primary text-xs font-bold px-6" style="height: 38px;">
                            <i data-lucide="shield-check" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                            <span>Save Security Settings</span>
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
    function toggleProfileEdit(editMode) {
        const viewCard = document.getElementById('profile-view-card');
        const editCard = document.getElementById('profile-edit-card');
        
        if (editMode) {
            if (viewCard) viewCard.classList.add('hidden');
            if (editCard) editCard.classList.remove('hidden');
        } else {
            if (viewCard) viewCard.classList.remove('hidden');
            if (editCard) editCard.classList.add('hidden');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function triggerAvatarUpload() {
        toggleProfileEdit(true);
        setTimeout(() => {
            const fileInput = document.getElementById('profile-photo-input');
            if (fileInput) fileInput.click();
        }, 150);
    }

    function previewSelectedAvatar(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('edit-form-avatar-preview');
                const mainPreview = document.getElementById('avatar-preview-img');
                if (preview) preview.src = e.target.result;
                if (mainPreview) mainPreview.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const tabs = document.querySelectorAll('.settings-tab-btn');
        const panes = document.querySelectorAll('.settings-pane');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetId = tab.getAttribute('data-settings');
                const targetPane = document.getElementById(targetId);

                tabs.forEach(t => {
                    t.classList.remove('active', 'btn-primary');
                    t.classList.add('btn-secondary');
                });
                panes.forEach(p => p.classList.add('hidden'));

                tab.classList.remove('btn-secondary');
                tab.classList.add('active', 'btn-primary');
                if (targetPane) {
                    targetPane.classList.remove('hidden');
                }
                if (typeof lucide !== 'undefined') lucide.createIcons();
            });
        });
    });
</script>
