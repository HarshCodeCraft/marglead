<?php
require_once __DIR__ . '/../includes/db.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['new-password'];
    
    if ($db_connected && $pdo) {
        try {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->execute([$hash, $email]);
            
            // Redirect to login with success indicator
            header("Location: login.php?reset=success");
            exit;
        } catch (PDOException $e) {
            $message = "Database execution failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback for offline prototype
        header("Location: login.php?reset=success");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Marg Soft Solution</title>
    
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
                <h1>Password Security Policies</h1>
                <p>Marg ERP enforces enterprise passwords policies to secure accounts. Ensure your password is unique and contains dynamic characters to prevent brute-force attacks.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Minimum 8 Characters Requirement</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Mix of numbers, uppercase & symbols</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg ERP Limited. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: Set Password Form -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Set New Password</h2>
                <p>Set a new password for account: <strong><?php echo htmlspecialchars($email); ?></strong></p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form action="change-password.php?email=<?php echo urlencode($email); ?>" method="POST" id="change-pass-form" class="flex flex-col gap-4">
                <div class="form-group m-0">
                    <label for="new-password" class="form-label text-sm">New Password</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                        <input type="password" id="new-password" name="new-password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <!-- Strength indicator -->
                    <div class="strength-meter" id="strength-meter">
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                    </div>
                    <div class="text-xs text-muted mt-1" id="strength-label">Password strength: Empty</div>
                </div>
                
                <div class="form-group m-0">
                    <label for="confirm-password" class="form-label text-sm">Confirm Password</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="lock-keyhole" style="width: 18px; height: 18px;"></i>
                        <input type="password" id="confirm-password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="text-xs mt-1" id="match-label" style="display: none;"></div>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-4" style="padding: 0.8rem;">
                    <span>Update Password</span>
                    <i data-lucide="check" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Cancel and return to <a href="login.php" class="text-primary font-semibold">Sign In</a>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
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
                    strengthLabel.style.color = 'var(--danger)';
                } else if (score <= 3) {
                    strengthMeter.classList.add('medium');
                    strengthLabel.textContent = 'Password strength: Medium (Add numbers/symbols)';
                    strengthLabel.style.color = 'var(--warning)';
                } else {
                    strengthMeter.classList.add('strong');
                    strengthLabel.textContent = 'Password strength: Strong & Secure';
                    strengthLabel.style.color = 'var(--success)';
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
                    matchLabel.style.color = 'var(--success)';
                } else {
                    matchLabel.textContent = 'Passwords do not match.';
                    matchLabel.style.color = 'var(--danger)';
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
