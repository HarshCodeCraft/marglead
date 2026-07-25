<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$isAdmin = (isset($_SESSION['login_role']) && ($_SESSION['login_role'] === 'Admin' || $_SESSION['login_role'] === 'Super Admin'));

// Fetch user data
$user_data = null;
if ($db_connected && $pdo && isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data = $stmt->fetch();
    } catch (PDOException $e) {
        $user_data = null;
    }
}

if (!$user_data) {
    $user_data = [
        'id' => 1,
        'name' => $_SESSION['user_name'] ?? 'Harsh Vardhan',
        'email' => $_SESSION['user_email'] ?? 'admin@marglead.com',
        'role' => $_SESSION['user_role'] ?? 'Admin',
        'status' => 'Active',
        'created_at' => date('Y-m-d H:i:s'),
        'permissions' => null
    ];
}

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

// Process update actions (Processed at index.php header level)


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
    'settings' => 'Control Settings'
];
?>

<div class="settings-container" style="max-width: 900px; margin: 0 auto;">
    <!-- Header -->
    <div class="mb-6">
        <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">
            <?php echo $isAdmin ? 'Control Settings' : 'My Operator Profile'; ?>
        </h2>
        <p class="text-muted text-sm">
            <?php echo $isAdmin ? 'Configure company profile details, branding assets, email templates, backup actions, and account parameters.' : 'View your operational details, roles privileges, and update account credentials.'; ?>
        </p>
    </div>

    <!-- Tabs Grid Layout -->
    <div class="grid" style="grid-template-columns: <?php echo $isAdmin ? '240px minmax(0, 1fr)' : '1fr'; ?>; gap: 1.5rem; align-items: start;">
        
        <?php if ($isAdmin): ?>
        <!-- Left Nav Toggles (Only visible to admin) -->
        <div class="card p-2 flex flex-col gap-1" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
            <button class="btn btn-primary active settings-tab-btn text-xs" data-settings="settings-profile" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                <i data-lucide="user" style="width: 14px; height: 14px;"></i>
                <span>My Profile</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-company" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                <i data-lucide="building" style="width: 14px; height: 14px;"></i>
                <span>Company Profile</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-branding" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                <i data-lucide="palette" style="width: 14px; height: 14px;"></i>
                <span>Branding Theme</span>
            </button>
            <button class="btn btn-secondary settings-tab-btn text-xs" data-settings="settings-security" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                <i data-lucide="shield" style="width: 14px; height: 14px;"></i>
                <span>Security & Backups</span>
            </button>
        </div>
        <?php endif; ?>

        <!-- Right Panels workspace -->
        <div class="flex flex-col gap-4">
            
            <!-- PANEL 0: My Profile (Visible to everyone) -->
            <div id="settings-profile" class="settings-pane active">
                
                <!-- VIEW CARD: Premium Profile Card -->
                <div id="profile-view-card" class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card); position: relative; box-shadow: var(--shadow-md);">
                    <!-- Brand Cover Image -->
                    <div style="background: linear-gradient(135deg, var(--primary), var(--accent)); height: 150px; border-bottom: 1px solid var(--border-color); position: relative;">
                        <!-- Absolute Top Right edit button -->
                        <!-- <button class="btn btn-primary text-xs" onclick="toggleProfileEdit(true)" style="position: absolute; bottom: 1rem; right: 1rem; padding: 0.4rem 0.8rem; background-color: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.25); color: #fff; backdrop-filter: blur(10px);">
                            <i data-lucide="user-cog" style="width: 14px; height: 14px; margin-right: 0.25rem;"></i>
                            <span>Edit Profile</span>
                        </button> -->
                    </div>

                    <!-- Profile Photo Overlay -->
                    <div style="position: relative; margin-top: -65px; display: flex; align-items: flex-end; justify-content: space-between; padding: 0 2rem; flex-wrap: wrap;">
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
                            <div style="position: relative; cursor: pointer;" onclick="triggerAvatarUpload()" title="Click to update profile photo">
                                <img id="avatar-preview-img" src="<?php echo htmlspecialchars($profile_avatar); ?>" style="width: 120px; height: 120px; border-radius: var(--border-radius-full); border: 4px solid var(--bg-card); object-fit: cover; box-shadow: var(--shadow-md); transition: transform 0.2s ease;">
                                <div style="position: absolute; bottom: 6px; right: 6px; background-color: var(--primary); color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-card); box-shadow: var(--shadow-sm);" title="Change Photo">
                                    <i data-lucide="camera" style="width: 16px; height: 16px;"></i>
                                </div>
                            </div>
                            <div style="margin-bottom: 0.5rem;">
                                <h3 style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem; line-height: 1.2;">
                                    <?php echo htmlspecialchars($user_data['name']); ?>
                                </h3>
                                <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.6rem;">
                                    <?php echo htmlspecialchars($user_data['role']); ?>
                                </span>
                            </div>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-size: 0.75rem; font-weight: 600; padding: 0.35rem 0.75rem; border-radius: var(--border-radius-full); display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid rgba(16, 185, 129, 0.2);">
                                <span style="width: 6px; height: 6px; border-radius: 50%; background-color: var(--success); display: inline-block;"></span>
                                <span><?php echo htmlspecialchars($user_data['status'] ?? 'Active'); ?></span>
                            </span>
                        </div>
                    </div>

                    <!-- Profile Details Section -->
                    <div class="p-6">
                        <div style="border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <h4 class="text-xs uppercase text-muted font-bold mb-4" style="letter-spacing: 0.05em;">Employee Information</h4>
                            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                                <div>
                                    <span class="text-xs text-muted block mb-1">Operator ID</span>
                                    <span class="text-sm font-semibold text-main">EMP-<?php echo str_pad($user_data['id'], 4, '0', STR_PAD_LEFT); ?></span>
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
                        <div class="mt-6" style="border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <h4 class="text-xs uppercase text-muted font-bold mb-3" style="letter-spacing: 0.05em;">Assigned Workspace Modules</h4>
                            <div class="flex flex-wrap gap-2">
                                <?php 
                                $current_perms = getUserPermissions($user_data);
                                foreach ($modules as $key => $label): 
                                    if (in_array($key, $current_perms)):
                                ?>
                                    <span class="badge" style="--badge-bg: rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.06); --badge-color: var(--primary); padding: 0.4rem 0.75rem; font-size: 0.75rem; font-weight: 600; border-radius: var(--border-radius-sm); border: 1px solid rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.15); display: inline-flex; align-items: center; gap: 0.25rem;">
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

                <!-- EDIT FORM CARD (Hidden by default) -->
                <form id="profile-edit-card" class="card p-6 hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card);" action="index.php?action=update_profile" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                        <h3 class="text-base font-semibold m-0" style="color: var(--primary);">Edit Profile Credentials</h3>
                        <button type="button" class="btn btn-secondary text-xs" onclick="toggleProfileEdit(false)" style="padding: 0.3rem 0.6rem;">Cancel</button>
                    </div>
                    
                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label text-xs">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user_data['name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label text-xs">Corporate Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label text-xs">Access Role</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['role']); ?>" disabled style="background-color: var(--border-card); opacity: 0.8; color: var(--text-muted);">
                        </div>
                        <div class="form-group">
                            <label class="form-label text-xs">Operational Status</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['status'] ?? 'Active'); ?>" disabled style="background-color: var(--border-card); opacity: 0.8; color: var(--text-muted);">
                        </div>
                        <div class="form-group mb-4" style="grid-column: span 2;">
                            <label class="form-label text-xs font-semibold">Profile Photo</label>
                            <div class="flex align-center gap-4 mt-2">
                                <img id="edit-form-avatar-preview" src="<?php echo htmlspecialchars($profile_avatar); ?>" style="width: 64px; height: 64px; border-radius: var(--border-radius-full); object-fit: cover; border: 2px solid var(--border-color);">
                                <div style="flex: 1;">
                                    <input type="file" name="profile_photo" id="profile-photo-input" class="form-control text-xs" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewSelectedAvatar(this)" style="padding: 0.4rem 0.5rem; width: 100%;">
                                    <span class="text-xs text-muted block mt-1">Upload a JPG, PNG, WEBP or GIF image (Max size 5MB).</span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label class="form-label text-xs">Update Password (Leave blank to keep current password)</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Enter new password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary text-xs mt-4">Save Profile Info</button>
                </form>

            </div>

            <?php if ($isAdmin): ?>
            <!-- PANEL 1: Company Profile (Admin only) -->
            <form id="settings-company" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card);" onsubmit="event.preventDefault(); alert('Company profile settings saved.');">
                <h3 class="text-base font-semibold mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">Company Profile Details</h3>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label text-xs">Registered Business Name</label>
                        <input type="text" class="form-control" value="Marg ERP Limited">
                    </div>
                    <div class="form-group">
                        <label class="form-label text-xs">Corporate Email</label>
                        <input type="email" class="form-control" value="sales@mangerp.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label text-xs">Contact Number</label>
                        <input type="tel" class="form-control" value="+91 11 4500 9000">
                    </div>
                    <div class="form-group">
                        <label class="form-label text-xs">Business GSTIN</label>
                        <input type="text" class="form-control" value="07AAAAM4509A1Z2">
                    </div>
                    <div class="form-group">
                        <label class="form-label text-xs">Company PAN</label>
                        <input type="text" class="form-control" value="AAAAM4509A">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary text-xs mt-4">Save Company Settings</button>
            </form>

            <!-- PANEL 2: Branding Theme (Admin only) -->
            <form id="settings-branding" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card);" onsubmit="event.preventDefault(); alert('Branding configuration saved.');">
                <h3 class="text-base font-semibold mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">Theme & Colors Defaults</h3>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label text-xs">Default Workspace Theme</label>
                        <select class="form-control">
                            <option value="dark" selected>Sleek Dark Mode (Default)</option>
                            <option value="light">Vibrant Light Mode</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label text-xs">Primary Brand Color</label>
                        <div class="flex align-center gap-2">
                            <input type="color" class="form-control" style="width: 50px; padding: 0; height: 38px; cursor: pointer;" value="#3b82f6">
                            <input type="text" class="form-control" value="#3b82f6" style="flex:1;">
                        </div>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label text-xs">Branding Logo Asset</label>
                        <input type="file" class="form-control" accept="image/*">
                        <span class="text-xs text-muted mt-1 block">Supports PNG, SVG assets. Suggested dimensions 250x60px.</span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary text-xs mt-4">Save Colors Branding</button>
            </form>

            <!-- PANEL 3: Security & Backups (Admin only) -->
            <div id="settings-security" class="card p-6 settings-pane hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
                <h3 class="text-base font-semibold mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; color: var(--primary);">Security Operations & Database Backups</h3>
                
                <div class="flex flex-col gap-4">
                    <!-- Backup actions -->
                    <div class="flex align-center justify-between p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                        <div class="flex flex-col">
                            <span class="font-semibold text-sm">Download System Backup database</span>
                            <span class="text-xs text-muted">Generates a complete SQL dump + files directory package.</span>
                        </div>
                        <button class="btn btn-secondary text-xs" onclick="alert('Compiling backups package... Database dump ready for download.');">Backup Now</button>
                    </div>

                    <!-- 2FA Checkbox -->
                    <label class="flex align-center gap-3 pointer p-2" style="background-color: var(--bg-app); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                        <input type="checkbox" style="accent-color: var(--primary);">
                        <div class="flex flex-col">
                            <span class="font-semibold text-xs">Enforce OTP Verification at Login</span>
                            <span class="text-xs text-muted">Sends verification code OTP to user's registered email at each sign-in.</span>
                        </div>
                    </label>
                </div>
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
            viewCard.classList.add('hidden');
            editCard.classList.remove('hidden');
        } else {
            viewCard.classList.remove('hidden');
            editCard.classList.add('hidden');
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

                // Special handling for profile tab container
                const profilePane = document.getElementById('settings-profile');
                if (targetId === 'settings-profile') {
                    profilePane.classList.remove('hidden');
                } else {
                    profilePane.classList.add('hidden');
                }

                tab.classList.remove('btn-secondary');
                tab.classList.add('active', 'btn-primary');
                if (targetPane && targetId !== 'settings-profile') {
                    targetPane.classList.remove('hidden');
                }
            });
        });
    });
</script>
