<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$message = '';
$message_type = '';

if (isset($_GET['verified'])) {
    if ($_GET['verified'] === 'success') {
        $message = "Email verified successfully! Your profile is pending administrator approval.";
        $message_type = "success";
    } elseif ($_GET['verified'] === 'already') {
        $message = "Your email address is already verified. Please sign in.";
        $message_type = "info";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reset session variables & regenerate ID to prevent session hijacking while preserving cookie
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION = array();
    @session_regenerate_id(true);

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Allow login by Email, Company Code, or Phone
    if (empty($email) || empty($password)) {
        $message = "Please enter your Email / Company Code and Password.";
        $message_type = "danger";
    } else {
        if ($db_connected && (isset($pdo_master) || isset($pdo))) {
            try {
                $effective_pdo = $pdo_master ?? $pdo;
                $tenant_db_target = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';

                // Check Anti-Brute Force Lockout (5 failed attempts in last 15 minutes)
                $lockout_stmt = $effective_pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip_address = ? OR email = ?) AND attempt_time > NOW() - INTERVAL 15 MINUTE");
                $lockout_stmt->execute([$user_ip, $email]);
                $failed_attempts = (int)$lockout_stmt->fetchColumn();

                if ($failed_attempts >= 5) {
                    $message = "Account temporarily locked due to 5 consecutive failed login attempts. Please try again in 15 minutes.";
                    $message_type = "danger";
                    logActivity('LOGIN_LOCKED', 'Authentication', "Locked out 15m for email: $email, IP: $user_ip");
                } else {
                    // 1. Check master users table by Email or Name with PDO Prepared Statement (100% SQL Injection Safe)
                    $stmt = $effective_pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? OR LOWER(name) = ?");
                    $stmt->execute([$email, $email]);
                    $user = $stmt->fetch();

                    // 2. If not found in master users, check tenant_companies (SaaS CRM Clients)
                    if (!$user) {
                        // Check if email matches a Tenant Company Owner Email or Company Code
                        $stmtT = $effective_pdo->prepare("SELECT * FROM tenant_companies WHERE (LOWER(owner_email) = ? OR LOWER(company_code) = ?) AND status = 'Active'");
                        $stmtT->execute([$email, $email]);
                        $tenantComp = $stmtT->fetch();
                        
                        if ($tenantComp) {
                            $user_pwd_hash = $tenantComp['password'] ?? '';
                            $is_tenant_valid = false;

                            if (!empty($user_pwd_hash)) {
                                if (password_verify($password, $user_pwd_hash) || $password === $user_pwd_hash) {
                                    $is_tenant_valid = true;
                                }
                            }
                            
                            // Legacy tenant check: if password column in tenant_companies is empty, check t_code_users or users table
                            if (!$is_tenant_valid) {
                                $tDb = $tenantComp['db_name'] ?? '';
                                if (!empty($tDb) && strpos($tDb, 't_') === 0) {
                                    $targetUsersTbl = "{$tDb}users";
                                    try {
                                        $stmtTU = $effective_pdo->prepare("SELECT * FROM `{$targetUsersTbl}` WHERE LOWER(email) = ? OR LOWER(name) = ?");
                                        $stmtTU->execute([$email, $email]);
                                        $tuUser = $stmtTU->fetch();
                                        if ($tuUser) {
                                            if (password_verify($password, $tuUser['password']) || $password === $tuUser['password']) {
                                                $is_tenant_valid = true;
                                                // Auto-upgrade tenant_companies password column
                                                try {
                                                    $updPasswordHash = password_hash($password, PASSWORD_DEFAULT);
                                                    $updStmt = $effective_pdo->prepare("UPDATE tenant_companies SET password = ? WHERE id = ?");
                                                    $updStmt->execute([$updPasswordHash, $tenantComp['id']]);
                                                } catch (\PDOException $ex) {}
                                            }
                                        }
                                    } catch (\PDOException $ex) {}
                                }
                            }

                            if ($is_tenant_valid) {
                                // Reset failed login attempts on successful login
                                $del_attempts = $effective_pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?");
                                $del_attempts->execute([$user_ip, $email]);

                                $_SESSION['user_id'] = $tenantComp['id'];
                                $_SESSION['user_role'] = 'Tenant Admin';
                                $_SESSION['login_role'] = 'Tenant Admin';
                                $_SESSION['user_name'] = $tenantComp['owner_name'];
                                $_SESSION['user_email'] = $tenantComp['owner_email'];
                                $_SESSION['user_permissions'] = null;
                                $_SESSION['tenant_db'] = $tenantComp['db_name'];
                                unset($_SESSION['impersonate_tenant_db']);

                                header("Location: ../index.php?page=dashboard");
                                exit;
                            }
                        }
                    }

                    $is_password_valid = false;
                    if ($user && !empty($user['password'])) {
                        if (password_verify($password, $user['password']) || $password === $user['password']) {
                            $is_password_valid = true;
                            // Auto-upgrade plain-text password to bcrypt hash on valid login
                            if ($password === $user['password'] && !password_verify($password, $user['password'])) {
                                try {
                                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                                    $upd = $effective_pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                                    $upd->execute([$newHash, $user['id']]);
                                } catch (\Exception $ex) {}
                            }
                        }
                    }

                    if ($user && $is_password_valid) {
                        // Reset failed login attempts on successful login
                        $del_attempts = $effective_pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?");
                        $del_attempts->execute([$user_ip, $email]);

                        if ($user['status'] === 'Unverified') {
                            // Generate new 6-digit OTP code & 10-minute expiry
                            $new_otp = sprintf("%06d", mt_rand(100000, 999999));
                            $new_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                            
                            $updStmt = $effective_pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                            $updStmt->execute([$new_otp, $new_expiry, $user['id']]);

                            require_once __DIR__ . '/../includes/mailer.php';
                            Mailer::sendEmailVerificationOTP($user['email'], $user['name'], $new_otp);

                            header("Location: verify-otp.php?email=" . urlencode($email) . "&msg=sent");
                            exit;
                        } elseif ($user['status'] === 'Pending Approval') {
                            $message = "Access Denied: Your account is pending administrator approval.";
                            $message_type = "warning";
                        } else {
                            // Set Session details, security IP binding, and active tenant database
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['login_role'] = $user['role'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['user_photo'] = $user['profile_photo'] ?? null;
                            $_SESSION['user_permissions'] = !empty($user['permissions']) ? json_decode($user['permissions'], true) : null;
                            $_SESSION['tenant_db'] = $tenant_db_target;
                            
                            // Security: Session Hijacking Guard & Activity Timestamp
                            $_SESSION['user_ip'] = $user_ip;
                            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                            $_SESSION['last_activity'] = time();
                            unset($_SESSION['impersonate_tenant_db']);

                            // Immutable Audit Log
                            logActivity('LOGIN_SUCCESS', 'Authentication', "User {$user['name']} ({$user['role']}) logged in successfully.");
                            
                            header("Location: ../index.php?page=dashboard");
                            exit;
                        }
                    } else {
                        // Log failed login attempt
                        $log_attempt = $effective_pdo->prepare("INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)");
                        $log_attempt->execute([$user_ip, $email]);

                        logActivity('LOGIN_FAILED', 'Authentication', "Failed login attempt for email: $email, IP: $user_ip");

                        $message = "Invalid email address or account password.";
                        $message_type = "danger";
                    }
                }
        } catch (PDOException $e) {
            $message = "Database query failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback check for offline prototype mode
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'Super Admin';
        $_SESSION['login_role'] = 'Super Admin';
        $_SESSION['user_name'] = 'Super Admin';
        $_SESSION['user_email'] = !empty($email) ? $email : 'operator@domain.local';
        $_SESSION['user_photo'] = null;
        $_SESSION['user_permissions'] = null;
        header("Location: ../index.php?page=dashboard");
        exit;
    }
}
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Friendly AI & Marg Solution</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modules/auth.css?v=<?php echo time(); ?>">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-body">
    <!-- Ambient Decor Elements -->
    <div class="auth-bg-decor-1"></div>
    <div class="auth-bg-decor-2"></div>

    <!-- Top Navigation Bar with Back to Home -->
    <div class="auth-top-bar">
        <a href="../landing.php" class="auth-back-btn" title="Go to Main Website">
            <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
            <span>Back to Home</span>
        </a>
        <div class="auth-top-nav-links">
            <a href="../landing.php" class="auth-nav-link">Home</a>
            <a href="../features.php" class="auth-nav-link">Features</a>
            <a href="../pricing.php" class="auth-nav-link">Pricing</a>
            <a href="../contact.php" class="auth-nav-link">Contact</a>
            <a href="register.php" class="auth-nav-link" style="color: #2563eb; font-weight: 600;">Create Account</a>
        </div>
    </div>

    <div class="auth-wrapper">
        <!-- Left Panel: Promo Info -->
        <div class="auth-panel-left">
            <a href="../landing.php" class="auth-brand" title="Friendly AI Solution">
                <img src="../assets/image.png" alt="Marg Logo">
                <span>Friendly AI Solution</span>
            </a>
            
            <div class="auth-promo-content">
                <h1>Supercharge Your Marg Sales & CRM Operations</h1>
                <p>Engage leads, automate follow-ups, dispatch quotes, monitor installations, and drive renewals inside a single unified dashboard.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
                        <span>16-Stage Interactive Lead Pipeline</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
                        <span>Official WhatsApp Cloud API & Invoicing</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
                        <span>Role-Based Multi-Panel Workspace</span>
                    </div>
                </div>

                <div class="auth-trust-badges">
                    <span class="trust-badge-pill">
                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> 256-Bit SSL Safe
                    </span>
                    <span class="trust-badge-pill" style="background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.35); color: #bfdbfe;">
                        <i data-lucide="zap" style="width: 14px; height: 14px;"></i> 99.9% Uptime
                    </span>
                </div>
            </div>
            
            <div class="auth-panel-footer">
                <span>© <?php echo date('Y'); ?> Friendly AI Solution.</span>
                <a href="../landing.php">Visit Main Portal</a>
            </div>
        </div>
        
        <!-- Right Panel: Login Form -->
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="auth-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to access your dashboard workspace</p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="badge-alert <?php echo htmlspecialchars($message_type); ?>">
                        <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
                
                <form action="login.php" method="POST" class="flex flex-col gap-4">
                    <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                    <div class="form-group m-0">
                        <label for="email" class="form-label">Username, Email or Company Code <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                            <input type="text" id="email" name="email" class="form-control" placeholder="Email or Company Code (e.g. poshak)" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group m-0">
                        <div class="flex justify-between align-center mb-1">
                            <label for="password" class="form-label m-0">Password <span style="color: #ef4444;">*</span></label>
                            <a href="forgot-password.php" class="text-xs font-semibold hover-underline" style="color: #2563eb; text-decoration: none;">Forgot password?</a>
                        </div>
                        <div class="input-icon-wrapper">
                            <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Toggle Password Visibility">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="flex align-center justify-between mt-1">
                        <label class="flex align-center gap-2 pointer text-sm" style="color: #64748b; font-size: 0.85rem;">
                            <input type="checkbox" style="accent-color: #2563eb; width: 15px; height: 15px;">
                            <span>Keep me logged in</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-full mt-2">
                        <span>Sign In to Dashboard</span>
                        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
                    </button>
                </form>
                
                <div class="auth-switch-text">
                    Need an account? <a href="register.php">Create Account</a>
                </div>
            </div>
            
            <!-- Anchored Bottom Footer -->
            <div class="auth-panel-footer-right">
                <a href="../landing.php">Home Page</a> • 
                <a href="../privacy.php" target="_blank">Privacy</a> • 
                <a href="../terms.php" target="_blank">Terms</a> • 
                <a href="../contact.php" target="_blank">Contact Support</a>
            </div>
        </div>
    </div>
    
    <!-- Script for password toggle & Lucide Icons -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            document.querySelectorAll('.password-toggle-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const wrapper = this.closest('.input-icon-wrapper');
                    const input = wrapper ? wrapper.querySelector('input') : null;
                    if (input) {
                        const isPass = input.getAttribute('type') === 'password';
                        input.setAttribute('type', isPass ? 'text' : 'password');
                        this.innerHTML = `<i data-lucide="${isPass ? 'eye-off' : 'eye'}" style="width: 18px; height: 18px;"></i>`;
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
