<?php
/**
 * Public Contact Us Page - Marg Soft Solutions
 */
require_once __DIR__ . '/includes/config.php';

$is_logged_in = isset($_SESSION['user_id']);
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success_msg = "Thank you! Your message has been received. Our Marg ERP support team will get in touch with you shortly.";
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Marg Soft Solutions</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --bg-dark: #080c14;
            --bg-card: rgba(18, 24, 38, 0.75);
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --accent-cyan: #06b6d4;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
        }

        .container { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; }

        /* Navbar */
        .navbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(8, 12, 20, 0.85); backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color); padding: 1rem 0;
        }
        .navbar-inner { display: flex; align-items: center; justify-content: space-between; }
        .logo { display: flex; align-items: center; gap: 0.75rem; font-family: var(--font-heading); font-weight: 800; font-size: 1.25rem; color: #fff; text-decoration: none; }
        .logo-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #06b6d4); display: flex; align-items: center; justify-content: center; color: #fff; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.35rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid transparent; }
        .btn-cyan { background: #00b4d8; color: #fff; font-weight: 700; }

        /* Form Card */
        .contact-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 3rem 2.5rem;
            margin: 4rem auto;
            max-width: 700px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }

        .form-group { margin-bottom: 1.5rem; }
        .form-label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; color: #f1f5f9; }
        .form-control {
            width: 100%; background: rgba(18, 24, 38, 0.9);
            border: 1px solid var(--border-color); border-radius: 8px;
            padding: 0.75rem 1rem; color: #fff; font-size: 0.9rem; outline: none;
        }
        .form-control:focus { border-color: var(--primary); }

        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #10b981; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.9rem; }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="container navbar-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon"><i data-lucide="layers" style="width: 22px; height: 22px;"></i></div>
                <span>MARG SOFT SOLUTIONS</span>
            </a>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <a href="index.php" class="btn" style="color: var(--text-muted);">Home</a>
                <a href="index.php?page=pricing" class="btn" style="color: var(--text-muted);">Pricing</a>
                <a href="auth/login.php" class="btn btn-cyan">Sign In</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="contact-card">
            <h1 style="font-family: var(--font-heading); font-size: 2.25rem; font-weight: 800; margin-bottom: 0.5rem; color: #fff;">Contact Our Sales & Support Team</h1>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 2rem;">Have questions about Marg ERP 9+ WhatsApp Cloud API integration? Send us a message and our specialists will respond within 15 minutes.</p>

            <?php if (!empty($success_msg)): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Full Name / Dukan Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Rajesh Sharma (Rajesh Medicals)" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Mobile / WhatsApp Number</label>
                    <input type="text" name="phone" class="form-control" placeholder="+91 98765 43210" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="rajesh@company.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Marg ERP License Number (Optional)</label>
                    <input type="text" name="license" class="form-control" placeholder="e.g. 1352947">
                </div>
                <div class="form-group">
                    <label class="form-label">How can we help you?</label>
                    <textarea name="message" class="form-control" rows="4" placeholder="Describe your query or book a live demo..." required></textarea>
                </div>
                <button type="submit" class="btn btn-cyan" style="width: 100%; padding: 0.85rem; font-size: 1rem; border-radius: 8px;">
                    Send Message to Marg Support
                </button>
            </form>
        </div>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
