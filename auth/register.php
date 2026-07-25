<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = 'Telecaller'; // Default signup role, admin can switch role in Permissions Matrix

    if ($db_connected && $pdo) {
        try {
            // Check if email already registered
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $message = "Email address is already registered.";
                $message_type = "danger";
            } else {
                // Insert as Pending Approval
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, 'Pending Approval')");
                $stmt->execute([$name, $email, $hash, $role]);
                
                // Trigger mailer notifications
                Mailer::sendUserRegistrationNotification($email, $name);
                
                // Trigger dashboard notification for Admin
                $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'New User Registration', ?, 'warning')");
                $adminNotifMsg = "New operator \"" . $name . "\" (" . $role . ") registered and is pending approval.";
                $adminNotifStmt->execute([$adminNotifMsg]);
                
                $message = "Registration successful! Your account status is: Pending Admin Approval. Notifications sent.";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            $message = "Database execution failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback for offline prototype
        $message = "Database connection offline. Account simulated as [Pending Approval].";
        $message_type = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Operator - Marg Soft Solution</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modules/auth.css">
    
    <!-- Premium Styles Upgrade -->
    <style>
    /* Glassmorphism Auth Wrapper */
    .auth-wrapper {
        background: rgba(15, 23, 42, 0.65);
        border: 1px solid rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(25px);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 40px rgba(59, 130, 246, 0.05);
        border-radius: 16px;
    }

    /* Beautiful Left Panel Gradient overlay */
    .auth-panel-left {
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.85) 0%, rgba(124, 58, 237, 0.85) 100%), 
                    url('https://images.unsplash.com/photo-1557804506-669a67965ba0?q=80&w=1000') center/cover no-repeat !important;
        padding: 3.5rem 3rem;
    }

    /* Auth right panel premium layout */
    .auth-panel-right {
        background-color: rgba(9, 15, 28, 0.9) !important;
        padding: 4rem 3rem;
    }

    /* Input boxes adjustments */
    .form-control {
        background: rgba(15, 23, 42, 0.5) !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        color: #f8fafc !important;
        border-radius: 8px !important;
        height: 44px !important;
        font-size: 0.9rem !important;
        transition: all 0.3s ease !important;
    }
    .form-control:focus {
        background: rgba(15, 23, 42, 0.7) !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
    }

    .input-icon-wrapper i,
    .input-icon-wrapper svg {
        position: absolute !important;
        left: 1rem !important;
        color: #64748b !important;
        pointer-events: none !important;
        transition: color 0.3s ease;
    }
    .input-icon-wrapper:focus-within i,
    .input-icon-wrapper:focus-within svg {
        color: #3b82f6 !important;
    }
    .input-icon-wrapper .form-control {
        padding-left: 2.75rem !important;
    }

    /* Form Label premium spacing */
    .form-label {
        color: #94a3b8 !important;
        font-weight: 600 !important;
        margin-bottom: 0.5rem !important;
        display: inline-block;
    }

    /* Submit Button premium style */
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
    </style>

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
                <h1>Join the CRM Workspace</h1>
                <p>Register as an operator. After completion, your regional head or site administrator will authorize permissions mapping for your profile.</p>
                
                <div class="auth-promo-features">
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Role-Based Operational Access</span>
                    </div>
                    <div class="promo-feature-item">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px; color: #34d399;"></i>
                        <span>Audit Log Signature Records</span>
                    </div>
                </div>
            </div>
            
            <div class="text-xs text-muted" style="color: rgba(255, 255, 255, 0.5);">
                © 2026 Marg Soft Solutions. All rights reserved.
            </div>
        </div>
        
        <!-- Right Panel: Registration Form -->
        <div class="auth-panel-right">
            <div class="auth-header">
                <h2>Account Signup</h2>
                <p>Create your operational system credentials</p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); width: 100%; justify-content: flex-start; display: flex; text-align: left; font-size: 0.825rem;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form action="register.php" method="POST" class="flex flex-col gap-4">
                <div class="form-group m-0">
                    <label for="name" class="form-label text-sm">Full Name</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="user" style="width: 18px; height: 18px;"></i>
                        <input type="text" id="name" name="name" class="form-control" placeholder="E.g. Vikas Patel" required>
                    </div>
                </div>
                
                <div class="form-group m-0">
                    <label for="email" class="form-label text-sm">Company Email</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@marglead.com" required>
                    </div>
                </div>

                <div class="form-group m-0">
                    <label for="password" class="form-label text-sm">Password</label>
                    <div class="input-icon-wrapper">
                        <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-4" style="padding: 0.8rem;">
                    <span>Create Account</span>
                    <i data-lucide="user-plus" style="width: 18px; height: 18px;"></i>
                </button>
            </form>
            
            <div class="text-center mt-6 text-xs text-muted">
                Already have an account? <a href="login.php" class="text-primary font-semibold">Sign In</a>
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
