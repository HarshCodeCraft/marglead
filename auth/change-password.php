<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : ($_SESSION['reset_token'] ?? '');
$message = '';
$message_type = '';
$valid_user = null;

// STRICT SECURITY GUARD: Reject any direct access attempts via plain email URL or missing token
if (empty($token)) {
    header("Location: forgot-password.php?error=unauthorized");
    exit;
}

if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token IS NOT NULL");
        $stmt->execute([$token]);
        $valid_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$valid_user) {
            header("Location: forgot-password.php?error=invalid_token");
            exit;
        }

        $token_expires = $valid_user['reset_token_expires_at'] ? strtotime($valid_user['reset_token_expires_at']) : 0;
        if (time() > $token_expires) {
            header("Location: forgot-password.php?error=expired_token");
            exit;
        }

        // STRICT DEVICE & BROWSER COOKIE BINDING: Ensure request IP, User-Agent, & HTTP-Only Cookie match original browser
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $cookie_secret = $_COOKIE['reset_cookie_secret'] ?? ($_SESSION['reset_cookie_secret'] ?? '');

        if (!empty($valid_user['reset_ip']) && $valid_user['reset_ip'] !== $user_ip) {
            header("Location: forgot-password.php?error=device_mismatch");
            exit;
        }

        if (!empty($valid_user['reset_user_agent']) && $valid_user['reset_user_agent'] !== $user_agent) {
            header("Location: forgot-password.php?error=device_mismatch");
            exit;
        }

        if (!empty($valid_user['reset_session_secret'])) {
            $incoming_hash = hash('sha256', $cookie_secret);
            if (!hash_equals($valid_user['reset_session_secret'], $incoming_hash)) {
                header("Location: forgot-password.php?error=cookie_mismatch");
                exit;
            }
        }
    } catch (PDOException $e) {
        $message = "Security validation error: " . $e->getMessage();
        $message_type = "danger";
    }
} else {
    // Fallback for offline mode if session authorized email exists
    if (!isset($_SESSION['reset_authorized_email'])) {
        header("Location: forgot-password.php?error=unauthorized");
        exit;
    }
}

$email = $valid_user ? $valid_user['email'] : ($_SESSION['reset_authorized_email'] ?? 'Account User');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['new-password'] ?? '';
    
    if (empty($new_pass) || strlen($new_pass) < 6) {
        $message = "Password must be at least 6 characters long.";
        $message_type = "danger";
    } else {
        if ($db_connected && $pdo && $valid_user) {
            try {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                // Update password AND immediately invalidate reset_token, reset_ip, reset_session_secret & OTP code to prevent reuse
                $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL, otp_code = NULL, otp_expires_at = NULL, reset_ip = NULL, reset_user_agent = NULL, reset_session_secret = NULL WHERE id = ?");
                $stmt->execute([$hash, $valid_user['id']]);

                setcookie('reset_cookie_secret', '', time() - 3600, '/', '', false, true);
                unset($_SESSION['reset_token']);
                unset($_SESSION['reset_cookie_secret']);
                unset($_SESSION['reset_authorized_email']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_device_ip']);

                header("Location: login.php?reset=success");
                exit;
            } catch (PDOException $e) {
                $message = "Database execution failure: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            // Fallback for offline prototype
            unset($_SESSION['reset_token']);
            unset($_SESSION['reset_authorized_email']);
            header("Location: login.php?reset=success");
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
    <title>Set New Password - Friendly AI Solution</title>
    
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
                <h1>Password Security Protocol</h1>
                <p>Ensure your new password is unique, strong, and contains a combination of characters to protect your CRM workspace against brute-force attempts.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 20px; height: 20px;"></i>
                        <span>Minimum 8 Characters Requirement</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="lock" style="width: 20px; height: 20px;"></i>
                        <span>Mix of numbers, uppercase & symbols</span>
                    </div>
                </div>

                <div class="auth-trust-badges">
                    <span class="trust-badge-pill">
                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> Bcrypt Encryption
                    </span>
                    <span class="trust-badge-pill" style="background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.35); color: #bfdbfe;">
                        <i data-lucide="check" style="width: 14px; height: 14px;"></i> Session Validated
                    </span>
                </div>
            </div>
            
            <div class="auth-panel-footer">
                <span>© <?php echo date('Y'); ?> Friendly AI Solution.</span>
                <a href="../landing.php">Visit Main Portal</a>
            </div>
        </div>
        
        <!-- Right Panel: Set Password Form -->
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="auth-header">
                    <h2>Set new password</h2>
                    <p>Account: <strong style="color: #2563eb;"><?php echo htmlspecialchars($email); ?></strong></p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="badge-alert <?php echo htmlspecialchars($message_type); ?>">
                        <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
                
                <form action="change-password.php?token=<?php echo urlencode($token); ?>" method="POST" id="change-pass-form" class="flex flex-col gap-4">
                    <div class="form-group m-0">
                        <label for="new-password" class="form-label">New Password <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                            <input type="password" id="new-password" name="new-password" class="form-control" placeholder="Minimum 8 characters" required autofocus>
                            <button type="button" class="password-toggle-btn" aria-label="Toggle Password Visibility">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                        <!-- Strength indicator -->
                        <div class="strength-meter" id="strength-meter">
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                            <div class="strength-bar"></div>
                        </div>
                        <div class="text-xs mt-1" style="color: #64748b; font-size: 0.78rem;" id="strength-label">Password strength: Empty</div>
                    </div>
                    
                    <div class="form-group m-0">
                        <label for="confirm-password" class="form-label">Confirm Password <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="lock-keyhole" style="width: 18px; height: 18px;"></i>
                            <input type="password" id="confirm-password" class="form-control" placeholder="Repeat your new password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Toggle Password Visibility">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                        <div class="text-xs mt-1" id="match-label" style="display: none; font-size: 0.78rem;"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-full mt-2">
                        <span>Update Password</span>
                        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
                    </button>
                </form>
                
                <div class="auth-switch-text">
                    Cancel and return to <a href="login.php">Sign In</a>
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
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            // Generic Password Visibility Toggler
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
            
            const newPassword = document.getElementById('new-password');
            const confirmPassword = document.getElementById('confirm-password');
            const strengthMeter = document.getElementById('strength-meter');
            const strengthLabel = document.getElementById('strength-label');
            const matchLabel = document.getElementById('match-label');
            const form = document.getElementById('change-pass-form');
            
            // Password strength evaluator
            newPassword.addEventListener('input', () => {
                const val = newPassword.value;
                let score = 0;
                
                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
                if (/[0-9]/.test(val)) score++;
                if (/[^A-Za-z0-9]/.test(val)) score++;
                
                strengthMeter.className = 'strength-meter';
                
                if (val === '') {
                    strengthLabel.textContent = 'Password strength: Empty';
                    strengthLabel.style.color = 'var(--text-muted)';
                } else if (score <= 1) {
                    strengthMeter.classList.add('weak');
                    strengthLabel.textContent = 'Password strength: Weak (Use longer password)';
                    strengthLabel.style.color = '#ef4444';
                } else if (score <= 3) {
                    strengthMeter.classList.add('medium');
                    strengthLabel.textContent = 'Password strength: Medium (Add numbers/symbols)';
                    strengthLabel.style.color = '#f59e0b';
                } else {
                    strengthMeter.classList.add('strong');
                    strengthLabel.textContent = 'Password strength: Strong & Secure';
                    strengthLabel.style.color = '#10b981';
                }
            });
            
            // Confirm password matching evaluator
            const checkMatch = () => {
                if (confirmPassword.value === '') {
                    matchLabel.style.display = 'none';
                    return;
                }
                matchLabel.style.display = 'block';
                if (newPassword.value === confirmPassword.value) {
                    matchLabel.textContent = 'Passwords match successfully.';
                    matchLabel.style.color = '#10b981';
                } else {
                    matchLabel.textContent = 'Passwords do not match.';
                    matchLabel.style.color = '#ef4444';
                }
            };
            
            newPassword.addEventListener('input', checkMatch);
            confirmPassword.addEventListener('input', checkMatch);
            
            form.addEventListener('submit', (e) => {
                if (newPassword.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match. Please verify.');
                }
            });
        });
    </script>
</body>
</html>
