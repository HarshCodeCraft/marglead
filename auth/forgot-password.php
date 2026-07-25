<?php
require_once __DIR__ . '/../includes/db.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if ($db_connected && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                // Email matches, forward with email parameter to verify OTP
                header("Location: otp-reset.php?email=" . urlencode($email));
                exit;
            } else {
                $message = "No registered operator found with that email address.";
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            $message = "Database execution failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback for offline prototype
        header("Location: otp-reset.php?email=" . urlencode($email));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Marg Soft Solution</title>
    
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
                <h1>Secure Recovery Protocol</h1>
                <p>Forgot your login credentials? No worries. We use verification security methods to keep your data safe and assist you in regaining system access.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-alert" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Encrypted Session Identifiers</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="clock" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>2-Minute Fast OTP Expiration Window</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg ERP Limited. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: Password Recovery Request -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Forgot Password?</h2>
                <p>Enter your email below to receive a secure recovery code</p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form action="forgot-password.php" method="POST" class="flex flex-col gap-4">
                <div class="form-group m-0">
                    <label for="email" class="form-label text-sm">Registered Email</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required value="admin@marglead.com">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-4" style="padding: 0.8rem;">
                    <span>Send Verification Code</span>
                    <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Remember your password? <a href="login.php" class="text-primary font-semibold">Sign In</a>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
