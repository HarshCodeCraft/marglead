<?php
require_once __DIR__ . '/../includes/db.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Concatenate code cells inputs from form POST
    $digits = isset($_POST['otp']) ? $_POST['otp'] : [];
    $code = implode('', $digits);
    
    // For demo purposes, we accept any 6-digit number
    if (strlen($code) === 6 && preg_match('/^\d+$/', $code)) {
        header("Location: change-password.php?email=" . urlencode($email));
        exit;
    } else {
        $message = "Please enter a valid 6-digit verification code.";
        $message_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - Marg Soft Solution</title>
    
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
                <h1>Double-Factor Security Lock</h1>
                <p>We've sent a 6-digit OTP security credential to your registered email address. This verification keeps your credentials private and blocks hijacking attempts.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="key-round" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Temporary Single-Use Tokens</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Secure IP Signature Logs</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg ERP Limited. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: OTP Entry -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Enter Verification Code</h2>
                <p>We sent a 6-digit code to <strong><?php echo htmlspecialchars(!empty($email) ? $email : 'your email'); ?></strong></p>
            </div>
            
            <?php if (isset($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form action="otp-reset.php?email=<?php echo urlencode($email); ?>" method="POST" class="flex flex-col gap-4">
                <!-- OTP Box Grid -->
                <div class="otp-container">
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                    <input type="text" name="otp[]" class="otp-input" maxlength="1" pattern="[0-9]" required>
                </div>
                
                <div class="timer-container">
                    <span class="flex align-center gap-1">
                        <i data-lucide="clock" style="width: 16px; height: 16px;"></i>
                        <span id="countdown-timer">Code expires in: 02:00</span>
                    </span>
                    <button type="button" id="resend-code-btn" class="text-xs text-muted pointer" style="font-weight: 600; text-decoration: underline;" disabled>Resend Code</button>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-2" style="padding: 0.8rem;">
                    <span>Verify Code</span>
                    <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Changed your mind? <a href="login.php" class="text-primary font-semibold">Back to Login</a>
            </div>
        </div>
    </div>
    
    <!-- Focus shifting script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            const inputs = document.querySelectorAll('.otp-input');
            
            inputs.forEach((input, index) => {
                // Focus first box initially
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
                    const text = e.clipboardData.getData('text').substring(0, inputs.length);
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
            
            // 2-Minute Timer
            let duration = 120;
            const timerDisplay = document.getElementById('countdown-timer');
            const resendButton = document.getElementById('resend-code-btn');
            
            const startTimer = setInterval(() => {
                let minutes = Math.floor(duration / 60);
                let seconds = duration % 60;
                
                minutes = minutes < 10 ? '0' + minutes : minutes;
                seconds = seconds < 10 ? '0' + seconds : seconds;
                
                timerDisplay.textContent = `Code expires in: ${minutes}:${seconds}`;
                
                if (--duration < 0) {
                    clearInterval(startTimer);
                    timerDisplay.textContent = 'Verification code has expired.';
                    timerDisplay.style.color = 'var(--danger)';
                    resendButton.removeAttribute('disabled');
                    resendButton.style.color = 'var(--primary)';
                }
            }, 1000);
            
            if (resendButton) {
                resendButton.addEventListener('click', () => {
                    alert('A fresh verification token has been dispatched!');
                    window.location.reload();
                });
            }
        });
    </script>
</body>
</html>
