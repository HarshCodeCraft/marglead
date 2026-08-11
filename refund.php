<?php
/**
 * Public Standalone Refund & Cancellation Policy Page - Marg ERP CRM
 * Publicly accessible for compliance verification and subscription holders.
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund & Cancellation Policy - Marg Soft Solutions</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            --bg-main: #0b0f19;
            --bg-card: rgba(18, 24, 38, 0.75);
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.25);
            --accent: #10b981;
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
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(at 0% 0%, rgba(16, 185, 129, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(59, 130, 246, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
            padding-bottom: 4rem;
        }

        .header-bar {
            border-bottom: 1px solid var(--border-color);
            background: rgba(11, 15, 25, 0.8);
            backdrop-filter: blur(16px);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 1rem 2rem;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-main);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.25rem;
        }

        .brand-logo img {
            width: 28px;
            height: 28px;
            object-fit: contain;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .nav-links a:hover, .nav-links a.active {
            color: var(--primary);
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-back:hover {
            background: var(--primary);
            border-color: var(--primary);
            box-shadow: 0 0 15px var(--primary-glow);
        }

        .hero-banner {
            text-align: center;
            padding: 4rem 1.5rem 3rem 1.5rem;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: var(--accent);
            padding: 0.35rem 0.9rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }

        .hero-title {
            font-family: var(--font-heading);
            font-size: 2.75rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            color: var(--text-muted);
            font-size: 1.05rem;
        }

        .policy-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 2.5rem;
        }

        @media (max-width: 900px) {
            .policy-container {
                grid-template-columns: 1fr;
            }
            .toc-sidebar {
                display: none;
            }
        }

        .toc-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
            position: sticky;
            top: 5rem;
            backdrop-filter: blur(12px);
        }

        .toc-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .toc-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .toc-list a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            padding: 0.4rem 0.6rem;
            border-radius: 6px;
            display: block;
            transition: all 0.2s ease;
        }

        .toc-list a:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .content-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2.5rem;
            backdrop-filter: blur(12px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .last-updated {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 2rem;
        }

        .section-block {
            margin-bottom: 2.5rem;
            scroll-margin-top: 6rem;
        }

        .section-block:last-child {
            margin-bottom: 0;
        }

        .section-heading {
            font-family: var(--font-heading);
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-heading i {
            color: var(--accent);
        }

        .section-body {
            color: #cbd5e1;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .section-body p {
            margin-bottom: 1rem;
        }

        .section-body ul {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .section-body li {
            margin-bottom: 0.4rem;
        }

        .highlight-box {
            background: rgba(16, 185, 129, 0.08);
            border-left: 4px solid var(--accent);
            border-radius: 0 8px 8px 0;
            padding: 1.25rem;
            margin: 1.25rem 0;
            font-size: 0.9rem;
            color: #e2e8f0;
        }

        .footer-legal {
            margin-top: 4rem;
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .footer-links a {
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="header-bar">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <img src="assets/image.png" alt="Marg Logo">
                <span>Marg Soft Solution</span>
            </a>
            <div class="nav-links">
                <a href="privacy.php">Privacy Policy</a>
                <a href="terms.php">Terms & Conditions</a>
                <a href="refund.php" class="active">Refund Policy</a>
            </div>
            <a href="index.php" class="btn-back">
                <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                <span>Back to App</span>
            </a>
        </div>
    </header>

    <!-- Hero Banner -->
    <section class="hero-banner">
        <div class="hero-badge">
            <i data-lucide="refresh-cw" style="width: 16px; height: 16px;"></i>
            <span>Commercial Licensing Guidelines</span>
        </div>
        <h1 class="hero-title">Refund & Cancellation Policy</h1>
        <p class="hero-subtitle">Clear, transparent terms regarding ERP subscriptions, license cancellations, and refund eligibility.</p>
    </section>

    <!-- Main Content Layout -->
    <div class="policy-container">
        
        <!-- Navigation Table of Contents -->
        <aside class="toc-sidebar">
            <div class="toc-card">
                <div class="toc-title">
                    <i data-lucide="list" style="width: 16px; height: 16px;"></i>
                    <span>Policy Index</span>
                </div>
                <ul class="toc-list">
                    <li><a href="#overview">1. Overview</a></li>
                    <li><a href="#cancellation">2. Cancellation Process</a></li>
                    <li><a href="#refund-eligibility">3. Refund Eligibility</a></li>
                    <li><a href="#non-refundable">4. Non-Refundable Items</a></li>
                    <li><a href="#processing-time">5. Processing & Payouts</a></li>
                    <li><a href="#contact-billing">6. Billing Support</a></li>
                </ul>
            </div>
        </aside>

        <!-- Document Content -->
        <main class="content-card">
            <div class="last-updated">
                <i data-lucide="calendar" style="width: 16px; height: 16px; color: var(--accent);"></i>
                <span>Effective Date: August 5, 2026 | Document Version 1.0.0</span>
            </div>

            <!-- Section 1 -->
            <section id="overview" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="info" style="width: 20px; height: 20px;"></i>
                    1. Subscription Overview
                </h2>
                <div class="section-body">
                    <p>Marg Soft Solutions Inc. offers cloud-based and enterprise on-premise licensing for the Marg ERP CRM & Lead Management System. We aim for complete customer satisfaction. This Refund & Cancellation Policy outlines the process, timelines, and criteria for subscription cancellations and refund requests.</p>
                </div>
            </section>

            <!-- Section 2 -->
            <section id="cancellation" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="x-circle" style="width: 20px; height: 20px;"></i>
                    2. Subscription Cancellation Process
                </h2>
                <div class="section-body">
                    <p>Subscribers may request subscription cancellation at any time under the following terms:</p>
                    <ul>
                        <li><strong>Notice Period:</strong> Cancellation requests must be submitted at least 15 days prior to the next scheduled billing cycle date.</li>
                        <li><strong>How to Cancel:</strong> Submit a cancellation ticket via the CRM Support module (`index.php?page=support`) or email <a href="mailto:billing@margsoft.com" style="color: var(--primary);">billing@margsoft.com</a> with your Tenant ID and Company Name.</li>
                        <li><strong>Data Export Guarantee:</strong> Active accounts will retain full export permissions for lead directories, invoices, and quotations during the notice period.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 3 -->
            <section id="refund-eligibility" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="rotate-ccw" style="width: 20px; height: 20px;"></i>
                    3. Refund Eligibility Criteria
                </h2>
                <div class="section-body">
                    <p>Refunds are evaluated case-by-case under the following guidelines:</p>
                    <ul>
                        <li><strong>30-Day Money-Back Guarantee:</strong> New annual cloud subscribers are eligible for a 100% refund of subscription fees if requested within 30 calendar days of initial deployment, provided less than 50% of allocated user seats have been provisioned.</li>
                        <li><strong>Service Downtime Credit:</strong> If system availability falls below our SLA threshold of 99.5% in any calendar month due to infrastructure failures on our side, affected accounts receive pro-rata service credits or refunds.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 4 -->
            <section id="non-refundable" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="ban" style="width: 20px; height: 20px;"></i>
                    4. Non-Refundable Services
                </h2>
                <div class="section-body">
                    <p>The following charges are non-refundable once delivered or provisioned:</p>
                    <ul>
                        <li>Custom ERP development hours or customized module engineering fees.</li>
                        <li>On-site engineer installation and physical user training sessions already completed.</li>
                        <li>WhatsApp API messaging top-ups or SMS gateway utility charges consumed via third-party operators.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 5 -->
            <section id="processing-time" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="credit-card" style="width: 20px; height: 20px;"></i>
                    5. Processing & Refund Delivery
                </h2>
                <div class="section-body">
                    <p>Approved refunds will be processed using the original method of payment (NEFT / RTGS / Credit Card / UPI) within 7 to 10 business days of refund approval confirmation.</p>
                </div>
            </section>

            <!-- Section 6 -->
            <section id="contact-billing" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="help-circle" style="width: 20px; height: 20px;"></i>
                    6. Billing Support
                </h2>
                <div class="section-body">
                    <p>For questions regarding invoices, renewals, or refund status, please contact our accounts team:</p>
                    <div class="highlight-box">
                        <strong>Marg Accounts & Renewal Desk</strong><br>
                        Email: <a href="mailto:billing@margsoft.com" style="color: var(--primary);">billing@margsoft.com</a><br>
                        Phone: +91 (011) 3090-6055
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Footer -->
    <footer class="footer-legal">
        <p>© 2026 Marg Soft Solutions Inc. All rights reserved.</p>
        <div class="footer-links">
            <a href="privacy.php">Privacy Policy</a> • 
            <a href="terms.php">Terms & Conditions</a> • 
            <a href="refund.php">Refund Policy</a> • 
            <a href="index.php">CRM Dashboard</a>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
