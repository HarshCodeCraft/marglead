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
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email OTP - Marg Soft Solution</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modules/auth.css">
    
    <style>
    .auth-wrapper {
        background: rgba(15, 23, 42, 0.65);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(25px);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 40px rgba(59, 130, 246, 0.05);
        border-radius: 16px;
    }
    .auth-panel-left {
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.85) 0%, rgba(124, 58, 237, 0.85) 100%), 
                    url('https://images.unsplash.com/photo-1557804506-669a67965ba0?q=80&w=1000') center/cover no-repeat !important;
        padding: 3.5rem 3rem;
    }
    .auth-panel-right {
        background-color: rgba(9, 15, 28, 0.9) !important;
        padding: 4rem 3rem;
    }
    .btn-primary {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
        border: none !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
        box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.3) !important;
        height: 46px !important;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        color: #ffffff !important;
        cursor: pointer;
    }
    .btn-primary:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 20px 0 rgba(59, 130, 246, 0.4) !important;
        background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%) !important;
    }
    .promo-feature-item {
        background: rgba(255, 255, 255, 0.08) !important;
        padding: 12px 16px !important;
        border-radius: 10px !important;
        border: 1px solid rgba(255, 255, 255, 0.05) !important;
        backdrop-filter: blur(5px);
        margin-bottom: 0.5rem;
    }
    .auth-header h2 {
        font-family: 'Outfit', sans-serif;
        color: #ffffff;
    }
    .resend-btn {
        background: none;
        border: none;
        color: #3b82f6;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        text-decoration: underline;
        padding: 0;
    }
    .resend-btn:disabled {
        color: #64748b;
        cursor: not-allowed;
        text-decoration: none;
    }
    </style>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <!-- Left Panel: Info -->
        <div class="auth-panel-left">
            <div class="auth-brand">
                <img src="../assets/image.png" alt="Marg Logo" style="width: 28px; height: 28px; object-fit: contain;">
                <span>Marg Soft Solution</span>
            </div>
            
            <div class="auth-promo-content">
                <h1>Verify Email OTP</h1>
                <p>We've dispatched a 6-digit verification security code to your registered email address. This ensures valid ownership of your account before administrator authorization.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="mail-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Email Verification Guard</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>10-Minute Expiring OTP Tokens</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg Soft Solutions. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: OTP Verification -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Verify Email Address</h2>
                <p>We sent a 6-digit code to <strong><?php echo htmlspecialchars(!empty($email) ? $email : 'your email'); ?></strong></p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
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
                
                <div class="timer-container flex justify-between align-center">
                    <span class="flex align-center gap-1 text-xs text-muted">
                        <i data-lucide="clock" style="width: 16px; height: 16px;"></i>
                        <span id="countdown-timer">Resend available in: 02:00</span>
                    </span>
                    
                    <button type="button" id="resend-code-btn" class="resend-btn" onclick="document.getElementById('resend-form').submit();" disabled>Resend Code</button>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-2" style="padding: 0.8rem;">
                    <span>Verify & Continue</span>
                    <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i>
                </button>
            </form>

            <!-- Separate Resend Form (Not Nested) -->
            <form id="resend-form" action="verify-otp.php?email=<?php echo urlencode($email); ?>" method="POST" style="display: none;">
                <input type="hidden" name="action" value="resend">
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Need to change your email or try again? <a href="register.php" class="text-primary font-semibold">Back to Signup</a>
            </div>

            <div class="text-center mt-4 text-xs text-muted flex justify-center gap-3" style="color: #64748b; font-size: 0.75rem;">
                <a href="../privacy.php" target="_blank" style="color: #94a3b8; text-decoration: none;">Privacy Policy</a> • 
                <a href="../terms.php" target="_blank" style="color: #94a3b8; text-decoration: none;">Terms & Conditions</a>
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
