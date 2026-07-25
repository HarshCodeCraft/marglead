<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Helper for breadcrumbs title
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$pageTitle = ucwords(str_replace('_', ' ', $page));

// Fetch latest user details from database to avoid stale session data
$logged_user_name = $_SESSION['user_name'] ?? 'Harsh Vardhan';
$logged_user_photo = $_SESSION['user_photo'] ?? null;
$logged_user_role = $_SESSION['user_role'] ?? 'Admin';

if ($db_connected && $pdo && isset($_SESSION['user_id'])) {
    try {
        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $userRow = $stmtUser->fetch();
        if ($userRow) {
            $logged_user_name = $userRow['name'];
            $logged_user_photo = $userRow['profile_photo'];
            $logged_user_role = $userRow['role'];
            
            // Sync session
            $_SESSION['user_name'] = $userRow['name'];
            $_SESSION['user_photo'] = $userRow['profile_photo'];
            $_SESSION['user_role'] = $userRow['role'];
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo APP_NAME; ?></title>
    <link rel="shortcut icon" href="assets/image.png" type="image/png">
    
    <!-- Meta tags for basic SEO -->
    <meta name="description" content="Marg ERP CRM & Lead Management System - Professional lead tracking, pipeline visualization, quotations, and customer support.">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome fallback (optional, Lucide is main) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Global CSS Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    
    <!-- Module-specific CSS files -->
    <?php if ($page === 'dashboard'): ?>
        <link rel="stylesheet" href="assets/css/modules/dashboard.css">
    <?php elseif ($page === 'pipeline'): ?>
        <link rel="stylesheet" href="assets/css/modules/pipeline.css">
    <?php elseif ($page === 'followups'): ?>
        <link rel="stylesheet" href="assets/css/modules/calendar.css">
    <?php elseif (in_array($page, ['login', 'forgot_password', 'otp_reset', 'change_password'])): ?>
        <link rel="stylesheet" href="assets/css/modules/auth.css">
    <?php endif; ?>
    
    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <?php include_once __DIR__ . '/sidebar.php'; ?>
        
        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Header Navbar -->
            <header class="header">
                <div class="header-left">
                    <button class="sidebar-toggle" aria-label="Toggle Sidebar">
                        <i data-lucide="menu"></i>
                    </button>
                    
                    <!-- Breadcrumbs -->
                    <nav class="breadcrumbs flex align-center gap-2 text-sm text-muted">
                        <span>Home</span>
                        <i data-lucide="chevron-right" style="width: 14px; height: 14px;"></i>
                        <span class="font-semibold text-main"><?php echo $pageTitle; ?></span>
                    </nav>
                </div>
                
                <div class="header-right">
                    <!-- Global Search trigger button -->
                    <button class="global-search-trigger" aria-label="Search leads and records">
                        <i data-lucide="search" style="width: 16px; height: 16px;"></i>
                        <span>Search...</span>
                        <kbd class="search-shortcut">Ctrl+K</kbd>
                    </button>
                    
                    <!-- Role Switcher Panel for Prototyping -->
                    <?php if (isset($_SESSION['login_role']) && ($_SESSION['login_role'] === 'Admin' || $_SESSION['login_role'] === 'Super Admin')): ?>
                    <div class="role-switcher-container flex align-center gap-2" style="background-color: var(--border-card); padding: 0.25rem 0.75rem; border-radius: var(--border-radius-full); border: 1px solid var(--border-color);">
                        <i data-lucide="shield-check" class="text-muted" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <select id="global-role-switcher" class="text-xs font-semibold pointer" style="border: none; background: transparent; padding-right: 0.5rem; text-transform: uppercase; color: var(--text-main);">
                            <?php foreach ($ROLES as $roleName => $desc): ?>
                                <option value="<?php echo $roleName; ?>" <?php echo ($_SESSION['user_role'] === $roleName) ? 'selected' : ''; ?>>
                                    <?php echo $roleName; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Theme Toggle Btn -->
                    <button class="header-action-btn theme-toggle" aria-label="Toggle visual theme">
                        <i data-lucide="<?php echo ($_SESSION['theme'] === 'light') ? 'moon' : 'sun'; ?>" style="width: 20px; height: 20px;"></i>
                    </button>
                    
                    <!-- Notifications Dropdown -->
                    <div class="profile-menu-container">
                        <button class="header-action-btn dropdown-trigger" data-dropdown="notifications-dropdown" aria-label="View notifications" onclick="playNotificationSound()">
                            <i data-lucide="bell" style="width: 20px; height: 20px;"></i>
                            <?php 
                            $unreadCount = count(array_filter($NOTIFICATIONS, function($n) { return $n['unread']; }));
                            if ($unreadCount > 0): 
                            ?>
                                <span class="btn-badge"></span>
                            <?php endif; ?>
                        </button>
                        
                        <div id="notifications-dropdown" class="dropdown-menu" style="width: 320px; right: 0;">
                            <div class="dropdown-header flex justify-between align-center" style="padding: 1rem; border-bottom: 1px solid var(--border-color);">
                                <h4 class="font-semibold text-sm m-0">Notifications</h4>
                                <a href="index.php?action=mark_notifs_read" class="text-xs text-muted pointer hover-primary" style="text-decoration: none;">Mark all read</a>
                            </div>
                            <div class="notifications-list" style="max-height: 280px; overflow-y: auto;">
                                <?php foreach ($NOTIFICATIONS as $notif): ?>
                                    <div class="notif-item flex gap-4 pointer" style="padding: 1rem; border-bottom: 1px solid var(--border-color); background-color: <?php echo $notif['unread'] ? 'rgba(var(--primary-h), var(--primary-s), var(--primary-l), 0.02)' : 'transparent'; ?>;">
                                        <div class="notif-icon flex align-center justify-center" style="width: 36px; height: 36px; border-radius: var(--border-radius-full); background-color: <?php 
                                            if ($notif['type'] === 'danger') echo 'var(--danger-light)';
                                            elseif ($notif['type'] === 'success') echo 'var(--success-light)';
                                            elseif ($notif['type'] === 'warning') echo 'var(--warning-light)';
                                            else echo 'var(--primary-light)';
                                        ?>; color: <?php
                                            if ($notif['type'] === 'danger') echo 'var(--danger)';
                                            elseif ($notif['type'] === 'success') echo 'var(--success)';
                                            elseif ($notif['type'] === 'warning') echo 'var(--warning)';
                                            else echo 'var(--primary)';
                                        ?>;">
                                            <i data-lucide="<?php 
                                                if ($notif['type'] === 'danger') echo 'alert-circle';
                                                elseif ($notif['type'] === 'success') echo 'check-circle';
                                                elseif ($notif['type'] === 'warning') echo 'clock';
                                                else echo 'bell';
                                            ?>" style="width: 16px; height: 16px;"></i>
                                        </div>
                                        <div class="notif-info flex-1">
                                            <div class="notif-title text-sm font-semibold"><?php echo $notif['title']; ?></div>
                                            <div class="notif-desc text-xs text-muted" style="margin-top: 0.15rem;"><?php echo $notif['message']; ?></div>
                                            <div class="notif-time text-xs text-muted" style="margin-top: 0.4rem;"><?php echo $notif['time']; ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="dropdown-footer text-center" style="padding: 0.75rem; border-top: 1px solid var(--border-color);">
                                <a href="#" class="text-xs font-semibold text-primary">View all alerts</a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile Dropdown Menu -->
                    <div class="profile-menu-container">
                        <div class="profile-trigger dropdown-trigger" data-dropdown="profile-dropdown">
                            <?php 
                            $header_avatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
                            if (!empty($logged_user_photo)) {
                                $clean_photo = ltrim($logged_user_photo, '/\\');
                                if (file_exists(__DIR__ . '/../' . $clean_photo)) {
                                    $header_avatar = $clean_photo;
                                }
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($header_avatar); ?>" alt="Profile" class="profile-trigger-avatar">
                            <div class="profile-info">
                                <span class="profile-name"><?php echo htmlspecialchars($logged_user_name); ?></span>
                                <span class="profile-role"><?php echo htmlspecialchars($logged_user_role); ?></span>
                            </div>
                            <i data-lucide="chevron-down" style="width: 14px; height: 14px;" class="text-muted"></i>
                        </div>
                        
                        <div id="profile-dropdown" class="dropdown-menu" style="width: 220px; right: 0;">
                            <a href="?page=settings" class="dropdown-item">
                                <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                                <span>My Profile</span>
                            </a>
                            <?php if (isset($_SESSION['login_role']) && ($_SESSION['login_role'] === 'Admin' || $_SESSION['login_role'] === 'Super Admin')): ?>
                            <a href="?page=settings" class="dropdown-item">
                                <i data-lucide="settings" style="width: 16px; height: 16px;"></i>
                                <span>Account Settings</span>
                            </a>
                            <a href="?page=admin_users" class="dropdown-item">
                                <i data-lucide="shield" style="width: 16px; height: 16px;"></i>
                                <span>Permissions Matrix</span>
                            </a>
                            <?php endif; ?>
                            <hr style="border: none; border-top: 1px solid var(--border-color); margin: 0.25rem 0;">
                            <a href="auth/logout.php" class="dropdown-item text-danger">
                                <i data-lucide="log-out" style="width: 16px; height: 16px; color: var(--danger);"></i>
                                <span>Sign Out</span>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <script>
            function playNotificationSound() {
                try {
                    const AudioContext = window.AudioContext || window.webkitAudioContext;
                    if (!AudioContext) return;
                    const ctx = new AudioContext();
                    const now = ctx.currentTime;
                    
                    const playBeep = (freq, startTime, duration) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, startTime);
                        gain.gain.setValueAtTime(0.08, startTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(startTime);
                        osc.stop(startTime + duration);
                    };
                    
                    // Double tone chime (high and clear)
                    playBeep(987.77, now, 0.12); // B5
                    playBeep(1318.51, now + 0.08, 0.20); // E6
                } catch (e) {
                    console.warn("Notification audio blocked or unsupported:", e);
                }
            }

            // Play sound on first user interaction if unread count is > 0
            document.addEventListener('DOMContentLoaded', () => {
                const hasUnread = <?php echo ($unreadCount > 0) ? 'true' : 'false'; ?>;
                if (hasUnread) {
                    const triggerChime = () => {
                        playNotificationSound();
                        document.removeEventListener('click', triggerChime);
                    };
                    document.addEventListener('click', triggerChime);
                }
            });
            </script>
            
            <!-- Dynamic Module Content wrapper -->
            <main class="content-body">
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="badge mb-4" style="--badge-bg: var(--success-light); --badge-color: var(--success); padding: 0.75rem 1rem; width: 100%; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i>
                        <span><?php echo $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['flash_error'])): ?>
                    <div class="badge mb-4" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); padding: 0.75rem 1rem; width: 100%; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; border: 1px solid rgba(239, 68, 68, 0.2);">
                        <i data-lucide="alert-triangle" style="width: 16px; height: 16px;"></i>
                        <span><?php echo $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?></span>
                    </div>
                <?php endif; ?>
