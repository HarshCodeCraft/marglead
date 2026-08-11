<?php
/**
 * Public Standalone Privacy Policy Page - Marg ERP CRM
 * Publicly accessible for Meta WhatsApp Cloud API app review and web visitors.
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Marg Soft Solutions</title>
    
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
                radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.08) 0px, transparent 50%);
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
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            color: var(--primary);
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
            color: var(--primary);
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
            background: rgba(59, 130, 246, 0.08);
            border-left: 4px solid var(--primary);
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
                <a href="privacy.php" class="active">Privacy Policy</a>
                <a href="terms.php">Terms & Conditions</a>
                <a href="refund.php">Refund Policy</a>
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
            <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i>
            <span>Official Legal Policy</span>
        </div>
        <h1 class="hero-title">Privacy Policy</h1>
        <p class="hero-subtitle">Learn how Marg Soft Solution collects, uses, protects, and governs your enterprise CRM data and communications.</p>
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
                    <li><a href="#overview">1. Overview & Scope</a></li>
                    <li><a href="#data-collection">2. Data We Collect</a></li>
                    <li><a href="#data-usage">3. How Information Is Used</a></li>
                    <li><a href="#whatsapp-integration">4. WhatsApp API & Communications</a></li>
                    <li><a href="#security">5. Data Security Standards</a></li>
                    <li><a href="#third-parties">6. Third-Party Sharing</a></li>
                    <li><a href="#user-rights">7. Data Retention & Rights</a></li>
                    <li><a href="#contact">8. Contact Us</a></li>
                </ul>
            </div>
        </aside>

        <!-- Document Content -->
        <main class="content-card">
            <div class="last-updated">
                <i data-lucide="calendar" style="width: 16px; height: 16px; color: var(--primary);"></i>
                <span>Effective Date: August 5, 2026 | Document Version 1.0.0</span>
            </div>

            <!-- Section 1 -->
            <section id="overview" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="info" style="width: 20px; height: 20px;"></i>
                    1. Overview & Scope
                </h2>
                <div class="section-body">
                    <p>Marg Soft Solution ("we", "our", "us") operates the Marg ERP CRM & Lead Management System. We are committed to protecting the privacy and security of data entrusted to us by our corporate clients, system administrators, sales personnel, and customer contacts.</p>
                    <p>This Privacy Policy applies to all services, web interfaces, background synchronization engines, mobile components, and communication channels (including WhatsApp Business API and email gateways) provided under the Marg ERP ecosystem.</p>
                </div>
            </section>

            <!-- Section 2 -->
            <section id="data-collection" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="database" style="width: 20px; height: 20px;"></i>
                    2. Data We Collect
                </h2>
                <div class="section-body">
                    <p>To deliver enterprise CRM functionality, customer pipeline management, quotation generation, and automated follow-ups, we collect the following categories of information:</p>
                    <ul>
                        <li><strong>Account & Credentials:</strong> User names, corporate email addresses, encrypted password hashes, system user roles (Super Admin, Admin, Sales Executive, Support Engineer, etc.), and profile preferences.</li>
                        <li><strong>Lead & Customer Information:</strong> Customer name, business entity/company name, contact phone numbers, email addresses, physical office addresses, enquiry details, quotation figures, and tags.</li>
                        <li><strong>Communication Data:</strong> Logs of WhatsApp interaction history, SMS notifications, email logs sent via PHPMailer SMTP, support ticket history, and team inbox discussions.</li>
                        <li><strong>System Audit Logs:</strong> Access logs, database sync events, batch lead import parameters, and user permission modification records.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 3 -->
            <section id="data-usage" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="sliders" style="width: 20px; height: 20px;"></i>
                    3. How Information Is Used
                </h2>
                <div class="section-body">
                    <p>We process collected data exclusively for valid business operations and system workflows, including:</p>
                    <ul>
                        <li>Managing end-to-end customer lifecycles and sales pipelines.</li>
                        <li>Generating official commercial quotations, pro-forma invoices, and payment trackings.</li>
                        <li>Dispatching transactional automated SMS, WhatsApp bot messages, and email reminders.</li>
                        <li>Enforcing role-based access security across tenant accounts and organizational tiers.</li>
                        <li>Delivering customer support, installation assignments, and client training modules.</li>
                    </ul>
                    <div class="highlight-box">
                        <strong>Notice on Commercial Selling:</strong> Marg Soft Solution NEVER sells, rents, monetizes, or trades customer leads, client contact lists, or internal CRM records to third-party advertisers or data brokers.
                    </div>
                </div>
            </section>

            <!-- Section 4 -->
            <section id="whatsapp-integration" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="message-square" style="width: 20px; height: 20px;"></i>
                    4. WhatsApp API & Communications Policy
                </h2>
                <div class="section-body">
                    <p>Our application integrates official Meta WhatsApp Cloud API services and bot flows to facilitate communication with leads and existing clients. When utilizing Meta WhatsApp integration:</p>
                    <ul>
                        <li>Messages are transmitted via encrypted HTTPS webhooks adhering to Meta's Developer Policies.</li>
                        <li>Customer phone numbers are utilized strictly to deliver requested information, quotations, or customer service support.</li>
                        <li>Opt-out and keyword triggers (such as STOP or CANCEL) are handled instantly via automated bot flow logic.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 5 -->
            <section id="security" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="lock" style="width: 20px; height: 20px;"></i>
                    5. Data Security Standards
                </h2>
                <div class="section-body">
                    <p>We enforce strict multi-layered security controls across our infrastructure to safeguard client data against unauthorized access, SQL injection, XSS exploitation, or disclosure:</p>
                    <ul>
                        <li><strong>100% PDO Prepared Statements:</strong> All database interactions utilize parametrized PDO queries to prevent SQL injection vulnerabilities.</li>
                        <li><strong>Data Sanitization & XSS Defense:</strong> Output values are sanitized using UTF-8 HTML escaping.</li>
                        <li><strong>Password Hashing:</strong> Passwords are encrypted using modern one-way cryptographic hashing algorithms (`PASSWORD_DEFAULT`).</li>
                        <li><strong>Security HTTP Headers:</strong> Active enforcement of `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy`.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 6 -->
            <section id="third-parties" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="share-2" style="width: 20px; height: 20px;"></i>
                    6. Third-Party Service Providers
                </h2>
                <div class="section-body">
                    <p>We share operational data only with verified third-party technology providers essential to system operation:</p>
                    <ul>
                        <li><strong>Meta Platforms, Inc. (WhatsApp Cloud API):</strong> For message dispatching and webhook handling.</li>
                        <li><strong>SMTP Gateway Providers (Google Workspace / PHPMailer):</strong> For automated system email dispatch.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 7 -->
            <section id="user-rights" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="user-check" style="width: 20px; height: 20px;"></i>
                    7. Data Retention & User Rights
                </h2>
                <div class="section-body">
                    <p>Subscribers and system administrators retain full ownership of their lead directory and CRM data. Administrators may export data in native CSV or XLSX formats at any time. Upon contract termination or explicit request, tenant database instances and user accounts can be permanently purged from active systems.</p>
                </div>
            </section>

            <!-- Section 8 -->
            <section id="contact" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="mail" style="width: 20px; height: 20px;"></i>
                    8. Contact Us
                </h2>
                <div class="section-body">
                    <p>For privacy inquiries, data protection questions, or security compliance requests, please contact our Data Protection Officer:</p>
                    <div class="highlight-box">
                        <strong>Marg Soft Solutions Inc.</strong><br>
                        Address: Opp. Okhla Metro Station, Phase III, New Delhi - 110020, India<br>
                        Email: <a href="mailto:privacy@margsoft.com" style="color: var(--primary);">privacy@margsoft.com</a> | <a href="mailto:support@margsoft.com" style="color: var(--primary);">support@margsoft.com</a><br>
                        Phone: +91 (011) 3090-6000
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
