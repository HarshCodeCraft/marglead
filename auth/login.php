<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($db_connected && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && (password_verify($password, $user['password']) || $password === $user['password'])) {
                if ($user['status'] === 'Pending Approval') {
                    $message = "Access Denied: Your account is pending administrator approval.";
                    $message_type = "warning";
                } else {
                    // Set Session details
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['login_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_photo'] = $user['profile_photo'] ?? null;
                    $_SESSION['user_permissions'] = !empty($user['permissions']) ? json_decode($user['permissions'], true) : null;
                    
                    header("Location: ../index.php?page=dashboard");
                    exit;
                }
            } else {
                $message = "Invalid email address or account password.";
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            $message = "Database query failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback check for offline prototype (accept any password for admin@marglead.com)
        if ($email === 'admin@marglead.com') {
            $_SESSION['user_id'] = 1;
            $_SESSION['user_role'] = 'Admin';
            $_SESSION['login_role'] = 'Admin';
            $_SESSION['user_name'] = 'Harsh Vardhan';
            $_SESSION['user_email'] = 'admin@marglead.com';
            $_SESSION['user_photo'] = null;
            $_SESSION['user_permissions'] = null;
            header("Location: ../index.php?page=dashboard");
            exit;
        } else {
            $message = "Connection offline. Use email [admin@marglead.com] to prototype.";
            $message_type = "info";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Marg Soft Solution</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modules/auth.css">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <!-- Left Panel: Promo Info -->
        <div class="auth-panel-left">
            <div class="auth-brand">
                <img src="../assets/image.png" alt="Marg Logo" style="width: 28px; height: 28px; object-fit: contain;">
                <span>Marg Soft Solution</span>
            </div>
            
            <div class="auth-promo-content">
                <h1>Supercharge Your Sales Pipeline</h1>
                <p>Engage leads, automate follow-ups, dispatch quotes, monitor installations, and drive renewals inside a single unified dashboard.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>16-Stage Interactive Lead Pipeline</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Dynamic Quotation & Invoicing Engines</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="check-circle-2" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Role-Based Multi-Panel Workspace</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg ERP Limited. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: Login Form -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Welcome Back</h2>
                <p>Sign in to access your dashboard workspace</p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST" class="flex flex-col gap-4">
                <div class="form-group m-0">
                    <label for="email" class="form-label text-sm">Username or Email</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required>
                    </div>
                </div>
                
                <div class="form-group m-0">
                    <div class="flex justify-between align-center mb-2">
                        <label for="password" class="form-label text-sm m-0">Password</label>
                        <a href="forgot-password.php" class="text-xs text-primary font-semibold">Forgot password?</a>
                    </div>
                    <div class="input-icon-wrapper">
                        <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="password-toggle-btn" aria-label="Toggle Password Visibility">
                            <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex align-center justify-between mt-2">
                    <label class="flex align-center gap-2 pointer text-sm text-muted">
                        <input type="checkbox" style="accent-color: var(--primary);">
                        <span>Keep me logged in</span>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-4" style="padding: 0.8rem;">
                    <span>Sign In</span>
                    <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Need an account? <a href="register.php" class="text-primary font-semibold">Create account</a>
            </div>
        </div>
    </div>
    
    <!-- Script for password toggle -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            const passwordField = document.getElementById('password');
            const toggleButton = document.querySelector('.password-toggle-btn');
            
            if (toggleButton && passwordField) {
                toggleButton.addEventListener('click', () => {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);
                    
                    const icon = toggleButton.querySelector('i');
                    if (icon) {
                        icon.setAttribute('data-lucide', type === 'password' ? 'eye' : 'eye-off');
                        lucide.createIcons();
                    }
                });
            }
        });
    </script>
</body>
</html>
