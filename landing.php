<?php
/**
 * Marg Soft Solutions - Public Landing Page & SaaS Portal
 * 
 * Matches exact dark-mode glassmorphism design with CTA hero card, Pricing, FAQs,
 * WhatsApp Cloud API showcases, and full footer navigation.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marg Soft Solutions - India's #1 Marg ERP 9+ Integrated CRM & WhatsApp Cloud API</title>
    <meta name="description" content="Automate Marg ERP 9+ Bills, Invoices & Outstanding Reminders directly from your official WhatsApp Business number. Join 50,000+ growing businesses.">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            --bg-dark: #080c14;
            --bg-card: rgba(18, 24, 38, 0.75);
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
            --accent-cyan: #06b6d4;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --font-heading: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(at 10% 10%, rgba(59, 130, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 90% 90%, rgba(6, 182, 212, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(147, 51, 234, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* Container */
        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        /* Navbar */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(8, 12, 20, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
        }

        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1.25rem;
            letter-spacing: -0.02em;
            color: #ffffff;
        }

        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }

        .nav-link {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: #ffffff;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1.35rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            border: 1px solid transparent;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-main);
            border-color: var(--border-color);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-primary {
            background: #ffffff;
            color: #080c14;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 255, 255, 0.3);
        }

        .btn-cyan {
            background: #00b4d8;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.3);
        }

        .btn-cyan:hover {
            background: #0096c7;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 180, 216, 0.4);
        }

        /* Hero Section */
        .hero {
            padding: 5rem 0 4rem 0;
            text-align: center;
            position: relative;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            border-radius: 9999px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            color: #60a5fa;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .hero-title {
            font-family: var(--font-heading);
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.03em;
            margin-bottom: 1.5rem;
            color: #ffffff;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-title span {
            background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 50%, #22d3ee 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: 1.125rem;
            color: var(--text-muted);
            max-width: 680px;
            margin: 0 auto 2.5rem auto;
            line-height: 1.7;
        }

        .hero-cta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 4rem;
        }

        /* Hero Preview Screen Mockup */
        .preview-card {
            background: rgba(18, 24, 38, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), 0 0 40px rgba(59, 130, 246, 0.15);
            backdrop-filter: blur(20px);
            margin: 0 auto;
            max-width: 1050px;
            text-align: left;
        }

        .mockup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .dots {
            display: flex;
            gap: 0.5rem;
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
            text-align: center;
        }

        .stat-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .stat-number {
            font-family: var(--font-heading);
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Features Section */
        .section {
            padding: 6rem 0;
        }

        .section-title {
            text-align: center;
            font-family: var(--font-heading);
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: #ffffff;
        }

        .section-subtitle {
            text-align: center;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto 3.5rem auto;
            font-size: 1rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            border-color: rgba(59, 130, 246, 0.4);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: rgba(59, 130, 246, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            margin-bottom: 1.25rem;
        }

        .feature-title {
            font-family: var(--font-heading);
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: #ffffff;
        }

        .feature-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* Pricing Section */
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            align-items: stretch;
        }

        .pricing-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2.5rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .pricing-card.featured {
            border-color: var(--accent-cyan);
            background: linear-gradient(180deg, rgba(6, 182, 212, 0.08) 0%, rgba(18, 24, 38, 0.9) 100%);
            box-shadow: 0 20px 40px rgba(6, 182, 212, 0.15);
        }

        .featured-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: #00b4d8;
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 4px 16px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .plan-name {
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .plan-price {
            font-family: var(--font-heading);
            font-size: 2.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 1.5rem;
        }

        .plan-price span {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .plan-features {
            list-style: none;
            padding: 0;
            margin-bottom: 2rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .plan-feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        /* FAQ Section */
        .faq-accordion {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .faq-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .faq-question {
            width: 100%;
            padding: 1.25rem 1.5rem;
            background: transparent;
            border: none;
            color: #ffffff;
            font-family: var(--font-body);
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            text-align: left;
        }

        .faq-answer {
            padding: 0 1.5rem 1.25rem 1.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
            display: none;
        }

        .faq-item.active .faq-answer {
            display: block;
        }

        /* CTA Hero Card (Exact Match to User Screenshot) */
        .cta-banner-card {
            background: linear-gradient(135deg, #1d4ed8 0%, #0284c7 50%, #06b6d4 100%);
            border-radius: 24px;
            padding: 4.5rem 2rem;
            text-align: center;
            box-shadow: 0 25px 60px rgba(2, 132, 199, 0.3);
            margin: 4rem auto;
            max-width: 1000px;
            position: relative;
            overflow: hidden;
        }

        .cta-banner-title {
            font-family: var(--font-heading);
            font-size: 2.75rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .cta-banner-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
            max-width: 540px;
            margin: 0 auto 2rem auto;
            line-height: 1.6;
        }

        .cta-banner-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        /* Footer (Exact Match to User Screenshot) */
        .footer {
            border-top: 1px solid var(--border-color);
            padding: 4rem 0 2rem 0;
            background: #060911;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 3rem;
            margin-bottom: 4rem;
        }

        .footer-brand {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .footer-brand p {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 320px;
            line-height: 1.6;
        }

        .newsletter-form {
            display: flex;
            gap: 0.5rem;
            max-width: 340px;
            margin-top: 0.5rem;
        }

        .newsletter-input {
            flex: 1;
            background: rgba(18, 24, 38, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 9999px;
            padding: 0.6rem 1.25rem;
            font-size: 0.85rem;
            color: #ffffff;
            outline: none;
        }

        .newsletter-input:focus {
            border-color: var(--primary);
        }

        .footer-col-title {
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #ffffff;
            margin-bottom: 1.25rem;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .footer-link {
            font-size: 0.875rem;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .footer-link:hover {
            color: #ffffff;
        }

        .footer-bottom {
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: all 0.2s ease;
        }

        .social-icon:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        @media (max-width: 768px) {
            .hero-title { font-size: 2.25rem; }
            .footer-grid { grid-template-columns: 1fr; gap: 2rem; }
            .nav-links { display: none; }
            .cta-banner-title { font-size: 2rem; }
        }
    </style>
</head>
<body>

    <!-- Sticky Navbar -->
    <nav class="navbar">
        <div class="container navbar-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i data-lucide="layers" style="width: 22px; height: 22px;"></i>
                </div>
                <span>MARG SOFT SOLUTIONS</span>
            </a>

            <ul class="nav-links">
                <li><a href="#features" class="nav-link">Features</a></li>
                <li><a href="#whatsapp" class="nav-link">WhatsApp Cloud API</a></li>
                <li><a href="#pricing" class="nav-link">Pricing</a></li>
                <li><a href="#faq" class="nav-link">FAQ</a></li>
                <li><a href="index.php?page=contact" class="nav-link">Contact Us</a></li>
            </ul>

            <div class="nav-actions">
                <?php if ($is_logged_in): ?>
                    <a href="index.php?page=dashboard" class="btn btn-cyan">
                        <i data-lucide="layout-dashboard" style="width: 16px; height: 16px;"></i>
                        <span>Go to Dashboard</span>
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn btn-ghost">Sign In</a>
                    <a href="auth/register.php" class="btn btn-cyan">
                        <span>Start Free Trial</span>
                        <i data-lucide="arrow-right" style="width: 16px; height: 16px;"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="badge-pill">
                <i data-lucide="zap" style="width: 14px; height: 14px;"></i>
                <span>India's #1 Marg ERP 9+ WhatsApp Integrated CRM</span>
            </div>

            <h1 class="hero-title">
                Automate Bills, Reminders & Growth with <span>WhatsApp Cloud API</span>
            </h1>

            <p class="hero-subtitle">
                Connect your Marg ERP 9+ directly to your official WhatsApp Business number. Send instant GST bill PDFs, collect outstanding payments, and automate customer support — seamlessly.
            </p>

            <div class="hero-cta">
                <a href="<?php echo $is_logged_in ? 'index.php?page=dashboard' : 'auth/register.php'; ?>" class="btn btn-primary">
                    <span>Start free trial →</span>
                </a>
                <a href="#demo" class="btn btn-cyan">
                    <span>Book a demo</span>
                </a>
            </div>

            <!-- Live Product Mockup -->
            <div class="preview-card">
                <div class="mockup-header">
                    <div class="dots">
                        <div class="dot dot-red"></div>
                        <div class="dot dot-yellow"></div>
                        <div class="dot dot-green"></div>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">marg_crm_dashboard_v2026.php</div>
                    <div style="font-size: 0.75rem; color: #10b981; display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="wifi" style="width: 12px; height: 12px;"></i> Marg ERP 9+ Connected Live
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Today's Invoices Sent</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #ffffff;">1,492 Invoices</div>
                        <div style="font-size: 0.7rem; color: #10b981;">↑ 99.8% WhatsApp Delivery Rate</div>
                    </div>
                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Outstanding Collected</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #ffffff;">₹4,85,200</div>
                        <div style="font-size: 0.7rem; color: #10b981;">Auto UPI Reminders Sent</div>
                    </div>
                    <div style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Meta Embedded Cloud API</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #25D366;">Active Connected</div>
                        <div style="font-size: 0.7rem; color: #94a3b8;">0% Risk Direct Customer Card</div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number">50,000+</div>
                    <div class="stat-label">Active Marg ERP Retailers & Distributors</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">10M+</div>
                    <div class="stat-label">WhatsApp Bill PDFs Delivered</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">99.9%</div>
                    <div class="stat-label">Uptime & Instant Delivery</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">2 Mins</div>
                    <div class="stat-label">Meta Embedded 1-Click Setup</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section">
        <div class="container">
            <h2 class="section-title">Everything you need to automate your Marg ERP business</h2>
            <p class="section-subtitle">Supercharge sales, automate invoice dispatch, and manage support tickets with built-in multi-tenant SaaS intelligence.</p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i data-lucide="file-text" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Automated Bill & Invoice WhatsApp Dispatch</h3>
                    <p class="feature-desc">When a sale bill is saved in Marg ERP 9+, Marg CRM generates a formatted PDF and instantly delivers it to your customer's WhatsApp number.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(37, 211, 102, 0.15); color: #25D366;">
                        <i data-lucide="message-square" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Meta Embedded Signup (1-Click)</h3>
                    <p class="feature-desc">Connect your official WhatsApp Business number in 2 minutes. Meta bills your business credit card directly — zero risk for your agency.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i data-lucide="credit-card" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Automated Payment & Outstanding Reminders</h3>
                    <p class="feature-desc">Automatically alert party ledgers with payment due dates and UPI payment links to collect pending payments 3x faster.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(147, 51, 234, 0.15); color: #c084fc;">
                        <i data-lucide="users" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Team Inbox & Multi-Agent Support</h3>
                    <p class="feature-desc">Assign WhatsApp customer inquiries to dedicated support technicians with full ticket ID tracking (e.g. TCK-9021).</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(6, 182, 212, 0.15); color: #22d3ee;">
                        <i data-lucide="building-2" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Multi-Tenant SaaS Management</h3>
                    <p class="feature-desc">Provision isolated MySQL databases for each branch or enterprise client with 1-click Super Admin impersonation.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">
                        <i data-lucide="shield-check" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Enterprise Data Security & Backups</h3>
                    <p class="feature-desc">Bank-grade SSL encryption, role-based access control (RBAC), and automated daily database backups ensure total safety.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="section" style="background: rgba(8, 12, 20, 0.5);">
        <div class="container">
            <h2 class="section-title">Simple, transparent pricing for every business</h2>
            <p class="section-subtitle">No hidden fees. Choose a plan that fits your dukan or enterprise branch.</p>

            <div class="pricing-grid">
                <!-- Starter -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Starter Suite</div>
                        <div class="plan-price">₹499 <span>/ month</span></div>
                        <p class="text-xs text-muted mb-4">Ideal for single retail chemists & small shops starting with automated billing.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 Marg ERP 9+ License Sync</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 Official WhatsApp Number</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1,000 Bill PDFs / Month</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Standard Email Support</li>
                        </ul>
                    </div>
                    <a href="auth/register.php?plan=Starter" class="btn btn-ghost w-full">Choose Starter</a>
                </div>

                <!-- Silver (Featured) -->
                <div class="pricing-card featured">
                    <div class="featured-badge">MOST POPULAR</div>
                    <div>
                        <div class="plan-name">Silver Professional</div>
                        <div class="plan-price">₹999 <span>/ month</span></div>
                        <p class="text-xs text-muted mb-4">For growing distributors & stockists needing automated payment reminders & team inbox.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 5 Marg ERP Licenses Sync</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Meta 1-Click Embedded Signup</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Unlimited Bill & Outstanding PDFs</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 5 Team Support Accounts</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Live WhatsApp Flow Builder</li>
                        </ul>
                    </div>
                    <a href="auth/register.php?plan=Silver" class="btn btn-cyan w-full">Start 14-Day Free Trial</a>
                </div>

                <!-- Enterprise -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Gold Enterprise</div>
                        <div class="plan-price">₹1,999 <span>/ month</span></div>
                        <p class="text-xs text-muted mb-4">For multi-branch enterprise distributors requiring SaaS isolation and dedicated account management.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Unlimited Marg ERP Licenses</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Multi-Tenant SaaS DB Provisioning</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Priority Meta WABA Approval</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Dedicated 24/7 Account Manager</li>
                        </ul>
                    </div>
                    <a href="auth/register.php?plan=Enterprise" class="btn btn-ghost w-full">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section (Exact Match to User Screenshot) -->
    <section id="faq" class="section">
        <div class="container">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Got questions? We've got answers.</p>

            <div class="faq-accordion">
                <div class="faq-item active">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>What support do you provide?</span>
                        <i data-lucide="minus" style="width: 18px; height: 18px; color: var(--accent-cyan);"></i>
                    </button>
                    <div class="faq-answer">
                        We provide 24/7 technical support via phone, email, and live WhatsApp. Our dedicated onboarding specialists assist you with 2-minute Meta Cloud API setup, AnyDesk/TeamViewer remote support, and Marg ERP 9+ integration configuration.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Do I need a Meta Developer account to connect WhatsApp?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        No! With our official <strong>Meta Embedded Signup</strong> feature, you simply click "Connect WhatsApp with Meta", log into Facebook in a popup window, enter your OTP, and connect your business number in under 2 minutes without touching any developer portal.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Will Meta bill my bank account directly?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        Yes! With Direct Customer Billing, Meta deducts message fees directly from your registered credit/debit card in Meta Business Manager. Alternatively, you can use our Shared Central Number model with wallet recharge.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Can I send Bill PDFs and Payment links directly from Marg ERP 9+?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        Yes! As soon as a billing entry is created in Marg ERP 9+, Marg CRM automatically converts it to a clean GST invoice PDF, attaches payment UPI links, and delivers it to the customer's WhatsApp.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Hero Banner Card (EXACT MATCH TO USER SCREENSHOT) -->
    <div class="container">
        <div class="cta-banner-card">
            <h2 class="cta-banner-title">Ready to ship at light speed?</h2>
            <p class="cta-banner-subtitle">
                Join 50,000+ teams already building the future. Start your 14-day free trial — no card required.
            </p>

            <div class="cta-banner-actions">
                <a href="<?php echo $is_logged_in ? 'index.php?page=dashboard' : 'auth/register.php'; ?>" class="btn btn-primary">
                    <span>Start free trial →</span>
                </a>
                <a href="index.php?page=contact" class="btn btn-cyan">
                    <span>Book a demo</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Footer (EXACT MATCH TO USER SCREENSHOT) -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <!-- Brand Info -->
                <div class="footer-brand">
                    <a href="index.php" class="logo">
                        <div class="logo-icon">
                            <i data-lucide="layers" style="width: 22px; height: 22px;"></i>
                        </div>
                        <span>MARG SOFT SOLUTIONS</span>
                    </a>
                    <p>
                        India's #1 ERP for retailers, distributors, pharmacies and enterprises — automate billing, inventory and growth.
                    </p>

                    <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing!');">
                        <input type="email" class="newsletter-input" placeholder="you@company.com" required>
                        <button type="submit" class="btn btn-cyan" style="padding: 0.6rem 1.25rem;">Subscribe</button>
                    </form>
                </div>

                <!-- Column 1: Product -->
                <div>
                    <h4 class="footer-col-title">Product</h4>
                    <ul class="footer-links">
                        <li><a href="#features" class="footer-link">Features</a></li>
                        <li><a href="#pricing" class="footer-link">Pricing</a></li>
                        <li><a href="index.php?page=whatsapp_settings" class="footer-link">Integrations</a></li>
                        <li><a href="#features" class="footer-link">Changelog</a></li>
                        <li><a href="#features" class="footer-link">Roadmap</a></li>
                    </ul>
                </div>

                <!-- Column 2: Company -->
                <div>
                    <h4 class="footer-col-title">Company</h4>
                    <ul class="footer-links">
                        <li><a href="index.php?page=contact" class="footer-link">About</a></li>
                        <li><a href="index.php?page=contact" class="footer-link">Careers</a></li>
                        <li><a href="index.php?page=contact" class="footer-link">Press</a></li>
                        <li><a href="index.php?page=crm_clients" class="footer-link">Customers</a></li>
                        <li><a href="index.php?page=contact" class="footer-link">Contact</a></li>
                    </ul>
                </div>

                <!-- Column 3: Resources -->
                <div>
                    <h4 class="footer-col-title">Resources</h4>
                    <ul class="footer-links">
                        <li><a href="index.php?page=privacy" class="footer-link">Docs</a></li>
                        <li><a href="index.php?page=terms" class="footer-link">Guides</a></li>
                        <li><a href="index.php?page=privacy" class="footer-link">Privacy Policy</a></li>
                        <li><a href="index.php?page=terms" class="footer-link">Terms of Service</a></li>
                        <li><a href="index.php?page=refund" class="footer-link">Refund Policy</a></li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom Bar -->
            <div class="footer-bottom">
                <div>© 2026 Marg Soft Solutions Pvt Ltd. All rights reserved.</div>
                <div class="social-links">
                    <a href="https://twitter.com" target="_blank" class="social-icon"><i data-lucide="twitter" style="width: 16px; height: 16px;"></i></a>
                    <a href="https://github.com/HarshCodeCraft/marglead" target="_blank" class="social-icon"><i data-lucide="github" style="width: 16px; height: 16px;"></i></a>
                    <a href="https://linkedin.com" target="_blank" class="social-icon"><i data-lucide="linkedin" style="width: 16px; height: 16px;"></i></a>
                    <a href="https://youtube.com" target="_blank" class="social-icon"><i data-lucide="youtube" style="width: 16px; height: 16px;"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Initialize Lucide Icons & FAQ JS -->
    <script>
        lucide.createIcons();

        function toggleFaq(btn) {
            const item = btn.parentElement;
            const isActive = item.classList.contains('active');
            
            document.querySelectorAll('.faq-item').forEach(i => {
                i.classList.remove('active');
                const icon = i.querySelector('.faq-question i');
                if (icon) {
                    icon.setAttribute('data-lucide', 'plus');
                    icon.style.color = 'var(--text-muted)';
                }
            });

            if (!isActive) {
                item.classList.add('active');
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.setAttribute('data-lucide', 'minus');
                    icon.style.color = 'var(--accent-cyan)';
                }
            }
            lucide.createIcons();
        }
    </script>
</body>
</html>
