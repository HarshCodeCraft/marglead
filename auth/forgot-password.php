<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$message = '';
$message_type = '';

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'unauthorized') {
        $message = "Unauthorized request. Please verify your email with an OTP first.";
        $message_type = "danger";
    } elseif ($_GET['error'] === 'invalid_token') {
        $message = "Password reset token is invalid or has already been used. Please request a new code.";
        $message_type = "danger";
    } elseif ($_GET['error'] === 'expired_token') {
        $message = "Your password reset session has expired. Please request a new verification code.";
        $message_type = "warning";
    } elseif ($_GET['error'] === 'device_mismatch') {
        $message = "Security Violation: Password reset session is strictly bound to the original device that requested the OTP. Access denied from unrecognized device/IP.";
        $message_type = "danger";
    } elseif ($_GET['error'] === 'cookie_mismatch') {
        $message = "Security Violation: Browser session security key not found on this device. Password reset links cannot be shared or opened on another browser or device.";
        $message_type = "danger";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "danger";
    } else {
        if ($db_connected && $pdo) {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE LOWER(email) = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Generate REAL 6-digit OTP code & 10-minute expiry
                    $otp_code = sprintf("%06d", mt_rand(100000, 999999));
                    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                    $updateStmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ?, reset_token = NULL, reset_token_expires_at = NULL, reset_ip = ?, reset_user_agent = ? WHERE id = ?");
                    $updateStmt->execute([$otp_code, $otp_expires_at, $user_ip, $user_agent, $user['id']]);

                    // Send email OTP
                    Mailer::sendEmailVerificationOTP($user['email'], $user['name'], $otp_code);

                    $_SESSION['reset_email'] = $user['email'];
                    $_SESSION['reset_device_ip'] = $user_ip;
                    header("Location: otp-reset.php?email=" . urlencode($user['email']) . "&msg=sent");
                    exit;
                } else {
                    $message = "No registered account found with that email address.";
                    $message_type = "danger";
                }
            } catch (PDOException $e) {
                $message = "Database execution failure: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            // Fallback for offline prototype
            $_SESSION['reset_email'] = $email;
            header("Location: otp-reset.php?email=" . urlencode($email) . "&msg=sent");
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
    <title>Forgot Password - Friendly AI Solution</title>
    
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
            <a href="login.php" class="auth-nav-link" style="color: #2563eb; font-weight: 600;">Sign In</a>
            <a href="register.php" class="auth-nav-link">Sign Up</a>
            <a href="../contact.php" class="auth-nav-link">Contact</a>
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
                <h1>Secure Account Recovery Protocol</h1>
                <p>Forgot your login credentials? No worries. We use strict verification security methods to protect your data and restore account access safely.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-alert" style="width: 20px; height: 20px;"></i>
                        <span>Encrypted Session Identifiers</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="clock" style="width: 20px; height: 20px;"></i>
                        <span>10-Minute OTP Expiration Window</span>
                    </div>
                </div>

                <div class="auth-trust-badges">
                    <span class="trust-badge-pill">
                        <i data-lucide="lock" style="width: 14px; height: 14px;"></i> Device Guarded
                    </span>
                    <span class="trust-badge-pill" style="background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.35); color: #bfdbfe;">
                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> Safe & Instant
                    </span>
                </div>
            </div>
            
            <div class="auth-panel-footer">
                <span>© <?php echo date('Y'); ?> Friendly AI Solution.</span>
                <a href="../landing.php">Visit Main Portal</a>
            </div>
        </div>
        
        <!-- Right Panel: Password Recovery Request -->
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="auth-header">
                    <h2>Reset password</h2>
                    <p>Enter your email to receive a secure recovery code</p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="badge-alert <?php echo htmlspecialchars($message_type); ?>">
                        <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
                
                <form action="forgot-password.php" method="POST" class="flex flex-col gap-4">
                    <div class="form-group m-0">
                        <label for="email" class="form-label">Registered Email Address <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                            <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required autofocus>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-full mt-2">
                        <span>Send Verification Code</span>
                        <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                    </button>
                </form>
                
                <div class="auth-switch-text">
                    Remember your password? <a href="login.php">Sign In</a>
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
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
