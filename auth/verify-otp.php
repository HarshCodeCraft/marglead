<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
$message = '';
$message_type = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'sent') {
    $message = "We sent a 6-digit OTP verification code to " . htmlspecialchars($email) . ". Please enter it below to verify your email.";
    $message_type = "info";
}

if ($db_connected && $pdo && !empty($email)) {
    // 1. Handle Resend Request
    if (isset($_POST['action']) && $_POST['action'] === 'resend') {
        try {
            $stmt = $pdo->prepare("SELECT id, name, status FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'Unverified') {
                $new_otp = sprintf("%06d", mt_rand(100000, 999999));
                $new_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $updateStmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
                $updateStmt->execute([$new_otp, $new_expiry, $user['id']]);

                Mailer::sendEmailVerificationOTP($email, $user['name'], $new_otp);

                $message = "A fresh 6-digit verification code has been dispatched to " . htmlspecialchars($email) . ".";
                $message_type = "success";
            } else {
                $message = "Unable to resend OTP. User profile not found or already verified.";
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            $message = "Failed to resend verification code: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    // 2. Handle OTP Submission
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $digits = isset($_POST['otp']) ? $_POST['otp'] : [];
        $code = is_array($digits) ? implode('', $digits) : trim($digits);

        if (strlen($code) === 6 && preg_match('/^\d{6}$/', $code)) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $message = "No account found matching email: " . htmlspecialchars($email);
                    $message_type = "danger";
                } elseif ($user['status'] !== 'Unverified') {
                    // Account already verified, redirect to login
                    header("Location: login.php?verified=already");
                    exit;
                } else {
                    // Verify code and expiration
                    $db_otp = $user['otp_code'];
                    $expires_at = $user['otp_expires_at'] ? strtotime($user['otp_expires_at']) : 0;
                    
                    if (empty($db_otp) || $db_otp !== $code) {
                        $message = "Incorrect verification code. Please check your email and try again.";
                        $message_type = "danger";
                    } elseif (time() > $expires_at) {
                        $message = "This verification code has expired. Please click 'Resend Code' below.";
                        $message_type = "warning";
                    } else {
                        // Success! Transition account to Pending Approval
                        $updateStmt = $pdo->prepare("UPDATE users SET status = 'Pending Approval', otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                        $updateStmt->execute([$user['id']]);

                        // Send notifications
                        Mailer::sendUserRegistrationNotification($email, $user['name']);

                        $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'New User Registration', ?, 'warning')");
                        $adminNotifMsg = "Operator \"" . $user['name'] . "\" (" . $user['role'] . ") verified email and is pending approval.";
                        $adminNotifStmt->execute([$adminNotifMsg]);

                        header("Location: login.php?verified=success");
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $message = "Verification failed: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            $message = "Please enter a valid 6-digit numeric verification code.";
            $message_type = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email OTP - Friendly AI Solution</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modules/auth.css?v=<?php echo time(); ?>">
    
    <style>
    .resend-btn {
        background: none;
        border: none;
        color: #2563eb;
        font-weight: 600;
        font-size: 0.825rem;
        cursor: pointer;
        text-decoration: underline;
        padding: 0;
    }
    .resend-btn:disabled {
        color: #94a3b8;
        cursor: not-allowed;
        text-decoration: none;
    }
    </style>

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
            <a href="register.php" class="auth-nav-link">Register</a>
            <a href="../contact.php" class="auth-nav-link">Contact</a>
        </div>
    </div>

    <div class="auth-wrapper">
        <!-- Left Panel: Info -->
        <div class="auth-panel-left">
            <a href="../landing.php" class="auth-brand" title="Friendly AI Solution">
                <img src="../assets/image.png" alt="Marg Logo">
                <span>Friendly AI Solution</span>
            </a>
            
            <div class="auth-promo-content">
                <h1>Verify Email Ownership</h1>
                <p>We've dispatched a 6-digit verification code to your registered email address. This ensures valid ownership of your account before administrator authorization.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="mail-check" style="width: 20px; height: 20px;"></i>
                        <span>Email Verification Guard</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 20px; height: 20px;"></i>
                        <span>10-Minute Expiring OTP Tokens</span>
                    </div>
                </div>

                <div class="auth-trust-badges">
                    <span class="trust-badge-pill">
                        <i data-lucide="lock" style="width: 14px; height: 14px;"></i> Secure Validation
                    </span>
                    <span class="trust-badge-pill" style="background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.35); color: #bfdbfe;">
                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> Anti-Abuse Protected
                    </span>
                </div>
            </div>
            
            <div class="auth-panel-footer">
                <span>© <?php echo date('Y'); ?> Friendly AI Solution.</span>
                <a href="../landing.php">Visit Main Portal</a>
            </div>
        </div>
        
        <!-- Right Panel: OTP Verification -->
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="auth-header">
                    <h2>Verify email address</h2>
                    <p>Enter the 6-digit code sent to <strong style="color: #2563eb;"><?php echo htmlspecialchars(!empty($email) ? $email : 'your email'); ?></strong></p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="badge-alert <?php echo htmlspecialchars($message_type); ?>">
                        <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
                
                <form id="otp-form" action="verify-otp.php?email=<?php echo urlencode($email); ?>" method="POST" class="flex flex-col gap-4">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <!-- OTP Digit Box Grid -->
                    <div class="otp-container">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                        <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required autocomplete="off">
                    </div>
                    
                    <div class="timer-container flex justify-between align-center" style="margin: 0.5rem 0 1rem 0;">
                        <span class="flex align-center gap-1 text-xs" style="color: #64748b;">
                            <i data-lucide="clock" style="width: 14px; height: 14px;"></i>
                            <span id="countdown-timer">Resend available in: 02:00</span>
                        </span>
                        
                        <button type="button" id="resend-code-btn" class="resend-btn" onclick="document.getElementById('resend-form').submit();" disabled>Resend Code</button>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-full mt-1">
                        <span>Verify & Activate Account</span>
                        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
                    </button>
                </form>

                <!-- Separate Resend Form (Not Nested) -->
                <form id="resend-form" action="verify-otp.php?email=<?php echo urlencode($email); ?>" method="POST" style="display: none;">
                    <input type="hidden" name="action" value="resend">
                </form>
                
                <div class="auth-switch-text">
                    Need to change email or try again? <a href="register.php">Back to Signup</a>
                </div>
            </div>

            <!-- Anchored Bottom Footer -->
            <div class="auth-panel-footer-right">
                <a href="../landing.php">Home Page</a> • 
                <a href="../privacy.php" target="_blank">Privacy</a> • 
                <a href="../terms.php" target="_blank">Terms</a>
            </div>
        </div>
    </div>
    
    <!-- Script for Input Auto-focus Navigation and Countdown -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            const inputs = document.querySelectorAll('.otp-input');
            
            inputs.forEach((input, index) => {
                if (index === 0) input.focus();
                
                input.addEventListener('keyup', (e) => {
                    if (e.key >= 0 && e.key <= 9) {
                        if (index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        }
                    } else if (e.key === 'Backspace') {
                        if (index > 0) {
                            inputs[index - 1].focus();
                        }
                    }
                });
                
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const text = e.clipboardData.getData('text').trim().substring(0, inputs.length);
                    if (/^\d+$/.test(text)) {
                        for (let i = 0; i < text.length; i++) {
                            inputs[i].value = text[i];
                            if (i < inputs.length - 1) {
                                inputs[i + 1].focus();
                            }
                        }
                    }
                });
            });
            
            // 2-Minute Timer for Resend Code Button
            let duration = 120;
            const timerDisplay = document.getElementById('countdown-timer');
            const resendButton = document.getElementById('resend-code-btn');
            
            const startTimer = setInterval(() => {
                let minutes = Math.floor(duration / 60);
                let seconds = duration % 60;
                
                minutes = minutes < 10 ? '0' + minutes : minutes;
                seconds = seconds < 10 ? '0' + seconds : seconds;
                
                if (timerDisplay) {
                    timerDisplay.textContent = `Resend available in: ${minutes}:${seconds}`;
                }
                
                if (--duration < 0) {
                    clearInterval(startTimer);
                    if (timerDisplay) {
                        timerDisplay.textContent = 'Did not receive code?';
                    }
                    if (resendButton) {
                        resendButton.removeAttribute('disabled');
                    }
                }
            }, 1000);
        });
    </script>
</body>
</html>
