<?php
http_response_code(404);

// Dynamic Base URL Resolution to prevent nested relative path bugs
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_path = '/MARGLEAD/';
if (preg_match('#^(/[^/]+/)#', $script_name, $m)) {
    $first_folder = strtolower($m[1]);
    if (!in_array($first_folder, ['/auth/', '/api/', '/modules/', '/includes/'])) {
        $base_path = $m[1];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | Marg Soft Solution</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/components.css">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root {
            --bg-dark: #090d16;
            --card-bg: rgba(15, 23, 42, 0.75);
            --primary-glow: rgba(59, 130, 246, 0.25);
            --accent-glow: rgba(99, 102, 241, 0.2);
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(99, 102, 241, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.08) 0%, transparent 60%);
            font-family: 'Inter', sans-serif;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            position: relative;
        }

        /* Floating background grid */
        .bg-grid {
            position: absolute;
            inset: 0;
            background-image: linear-gradient(to right, rgba(255, 255, 255, 0.03) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .error-card {
            width: 100%;
            max-width: 580px;
            margin: 1.5rem;
            padding: 3rem 2.5rem;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 60px var(--primary-glow);
            text-align: center;
            position: relative;
            z-index: 10;
            animation: fadeInScale 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.92) translateY(20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .error-number-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .error-number {
            font-family: 'Outfit', sans-serif;
            font-size: 7.5rem;
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 40%, #818cf8 80%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -3px;
            text-shadow: 0 10px 30px rgba(59, 130, 246, 0.3);
        }

        .floating-icon {
            position: absolute;
            top: -10px;
            right: -15px;
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.5);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
        }

        .error-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.75rem;
        }

        .error-desc {
            font-size: 0.95rem;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 2rem;
            max-width: 440px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .btn-custom-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            border: none;
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.35);
            transition: all 0.25s ease;
        }

        .btn-custom-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(37, 99, 235, 0.5);
            color: #ffffff;
        }

        .btn-custom-secondary {
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .btn-custom-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .legal-footer {
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            justify-content: center;
            gap: 1.25rem;
        }

        .legal-footer a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .legal-footer a:hover {
            color: #3b82f6;
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>

    <div class="error-card">
        <div class="error-number-container">
            <div class="error-number">404</div>
            <div class="floating-icon">
                <i data-lucide="shield-alert" style="width: 26px; height: 26px; color: #ffffff;"></i>
            </div>
        </div>

        <h1 class="error-title">Not Found</h1>
        <p class="error-desc">
            The requested URL was not found on this server.
        </p>

        <div class="btn-group">
            <a href="<?php echo $base_path; ?>landing.php" class="btn-custom-primary">
                <i data-lucide="home" style="width: 18px; height: 18px;"></i>
                <span>Back to Home</span>
            </a>
            <a href="<?php echo $base_path; ?>auth/login.php" class="btn-custom-secondary">
                <i data-lucide="log-in" style="width: 18px; height: 18px;"></i>
                <span>Sign In</span>
            </a>
        </div>

        <div class="legal-footer">
            <a href="<?php echo $base_path; ?>privacy.php" target="_blank">Privacy Policy</a> • 
            <a href="<?php echo $base_path; ?>terms.php" target="_blank">Terms & Conditions</a> • 
            <a href="<?php echo $base_path; ?>refund.php" target="_blank">Refund Policy</a>
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
