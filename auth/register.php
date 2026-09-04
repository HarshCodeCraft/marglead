<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = 'Telecaller'; // Default signup role, admin can switch role in Permissions Matrix

    if (empty($name) || strlen($name) < 2) {
        $message = "Please enter a valid full name (minimum 2 characters).";
        $message_type = "danger";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "danger";
    } elseif (function_exists('isDisposableEmail') && isDisposableEmail($email)) {
        $message = "Temporary or disposable email addresses are not permitted. Please use a valid personal or business email.";
        $message_type = "danger";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $message_type = "danger";
    } else {
        if ($db_connected && $pdo) {
            try {
                // Check if email already registered with PDO Prepared Statement (100% SQL Injection Safe)
                $check = $pdo->prepare("SELECT id, status FROM users WHERE LOWER(email) = ?");
                $check->execute([$email]);
                $existingUser = $check->fetch();

                if ($existingUser && $existingUser['status'] !== 'Unverified') {
                    $message = "Email address is already registered. Please sign in.";
                    $message_type = "danger";
                } else {
                    // Generate 6-digit OTP code & 10-minute expiry
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    if ($existingUser && $existingUser['status'] === 'Unverified') {
                        // Update existing unverified profile
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = ?, otp_code = ?, otp_expires_at = ? WHERE id = ?");
                        $stmt->execute([$name, $hash, $role, $otp, $otp_expires_at, $existingUser['id']]);
                    } else {
                        // Insert as Unverified
                        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status, otp_code, otp_expires_at) VALUES (?, ?, ?, ?, 'Unverified', ?, ?)");
                        $stmt->execute([$name, $email, $hash, $role, $otp, $otp_expires_at]);
                    }
                    
                    // Trigger OTP mailer
                    Mailer::sendEmailVerificationOTP($email, $name, $otp);
                    
                    // Redirect to OTP Verification page
                    header("Location: verify-otp.php?email=" . urlencode($email) . "&msg=sent");
                    exit;
                }
            } catch (PDOException $e) {
                $message = "Database execution failure: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            // Fallback for offline prototype
            $message = "Database connection offline. Account simulated as [Pending Verification].";
            $message_type = "warning";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Friendly AI Solution</title>
    
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
            <a href="login.php" class="auth-nav-link" style="color: #2563eb; font-weight: 600;">Sign In</a>
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
                <h1>Accelerate Sales with Marg ERP & WhatsApp Automation</h1>
                <p>Register as an operator. We will dispatch a verification OTP to your email, and your administrator will activate your access permissions.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 20px; height: 20px;"></i>
                        <span>Role-Based Operational Access Control</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="zap" style="width: 20px; height: 20px;"></i>
                        <span>Real-time Multi-User Pipeline Sync</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="lock" style="width: 20px; height: 20px;"></i>
                        <span>End-to-End Encrypted Audit Trail</span>
                    </div>
                </div>

                <div class="auth-trust-badges">
                    <span class="trust-badge-pill">
                        <i data-lucide="shield-check" style="width: 14px; height: 14px;"></i> Meta Certified Partner
                    </span>
                    <span class="trust-badge-pill" style="background: rgba(59, 130, 246, 0.2); border-color: rgba(59, 130, 246, 0.35); color: #bfdbfe;">
                        <i data-lucide="clock" style="width: 14px; height: 14px;"></i> Instant Setup
                    </span>
                </div>
            </div>
            
            <div class="auth-panel-footer">
                <span>© <?php echo date('Y'); ?> Friendly AI Solution.</span>
                <a href="../landing.php">Visit Main Portal</a>
            </div>
        </div>
        
        <!-- Right Panel: Registration Form -->
        <div class="auth-panel-right">
            <div class="auth-form-container">
                <div class="auth-header">
                    <h2>Create an account</h2>
                    <p>Start your operator workspace onboarding</p>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="badge-alert <?php echo htmlspecialchars($message_type); ?>">
                        <i data-lucide="<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'alert-triangle' : 'alert-circle'); ?>" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>
                
                <form action="register.php" method="POST" class="flex flex-col gap-4">
                    <div class="form-group m-0">
                        <label for="name" class="form-label">Full Name <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="user" style="width: 18px; height: 18px;"></i>
                            <input type="text" id="name" name="name" class="form-control" placeholder="E.g. Rajesh Sharma" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group m-0">
                        <label for="email" class="form-label">Email Address <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                            <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required>
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label for="password" class="form-label">Password <span style="color: #ef4444;">*</span></label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                            <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
                            <button type="button" class="password-toggle-btn" aria-label="Toggle Password Visibility">
                                <i data-lucide="eye" style="width: 18px; height: 18px;"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-1">
                        <label class="flex align-center gap-2 pointer text-xs" style="color: #64748b; font-size: 0.825rem; line-height: 1.45;">
                            <input type="checkbox" required style="accent-color: #2563eb; width: 15px; height: 15px;">
                            <span>I agree to the <a href="../terms.php" target="_blank" style="color: #2563eb; font-weight: 600; text-decoration: underline;">Terms & Conditions</a> and <a href="../privacy.php" target="_blank" style="color: #2563eb; font-weight: 600; text-decoration: underline;">Privacy Policy</a></span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-full mt-2">
                        <span>Create Account & Send OTP</span>
                        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
                    </button>
                </form>
                
                <div class="auth-switch-text">
                    Already have an account? <a href="login.php">Sign In</a>
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
