<?php
/**
 * Public Standalone Terms & Conditions Page - Marg ERP CRM
 * Publicly accessible for compliance verification and system users.
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions - Marg Soft Solutions</title>
    
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
                radial-gradient(at 100% 100%, rgba(124, 58, 237, 0.08) 0px, transparent 50%);
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
            background: rgba(124, 58, 237, 0.1);
            border: 1px solid rgba(124, 58, 237, 0.25);
            color: #a78bfa;
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
            background: rgba(124, 58, 237, 0.08);
            border-left: 4px solid #8b5cf6;
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
                <a href="terms.php" class="active">Terms & Conditions</a>
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
            <i data-lucide="file-text" style="width: 16px; height: 16px;"></i>
            <span>Terms of Service Agreement</span>
        </div>
        <h1 class="hero-title">Terms & Conditions</h1>
        <p class="hero-subtitle">Governing terms for accessing and operating the Marg ERP CRM platform and automated services.</p>
    </section>

    <!-- Main Content Layout -->
    <div class="policy-container">
        
        <!-- Navigation Table of Contents -->
        <aside class="toc-sidebar">
            <div class="toc-card">
                <div class="toc-title">
                    <i data-lucide="list" style="width: 16px; height: 16px;"></i>
                    <span>Terms Index</span>
                </div>
                <ul class="toc-list">
                    <li><a href="#acceptance">1. Acceptance of Terms</a></li>
                    <li><a href="#accounts">2. Accounts & Role Responsibilities</a></li>
                    <li><a href="#acceptable-use">3. Acceptable Use Policy</a></li>
                    <li><a href="#system-availability">4. System Availability & SLA</a></li>
                    <li><a href="#intellectual-property">5. Intellectual Property Rights</a></li>
                    <li><a href="#liability">6. Limitation of Liability</a></li>
                    <li><a href="#termination">7. Account Termination</a></li>
                    <li><a href="#governing-law">8. Governing Law & Jurisdiction</a></li>
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
            <section id="acceptance" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
                    1. Acceptance of Terms
                </h2>
                <div class="section-body">
                    <p>By registering an account, logging into, or utilizing the Marg ERP CRM & Lead Management System provided by Marg Soft Solutions Inc., you agree to be bound by these Terms and Conditions ("Terms"). If you do not accept these terms in full, you must refrain from accessing or using the platform.</p>
                    <p>These terms apply to all corporate accounts, regional administrators, sales executives, telecallers, support engineers, and third-party API integrations.</p>
                </div>
            </section>

            <!-- Section 2 -->
            <section id="accounts" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="users" style="width: 20px; height: 20px;"></i>
                    2. User Accounts & Role Responsibilities
                </h2>
                <div class="section-body">
                    <p>Subscribers and registered operators must maintain accurate account information and safeguard authentication credentials:</p>
                    <ul>
                        <li><strong>Credential Security:</strong> Users are responsible for maintaining the confidentiality of their login password and session tokens. Sharing credentials outside authorized organization roles is strictly prohibited.</li>
                        <li><strong>Role Authorization:</strong> System operations (such as lead dropping, quotation approval, tenant database impersonation, and batch exports) are strictly gated by permission matrices. Attempting to bypass role-based security is a violation of these terms.</li>
                        <li><strong>Administrator Duties:</strong> Super Admin and Admin account holders are responsible for verifying operator status upon registration (`Pending Approval` status) before granting workspace privileges.</li>
                    </ul>
                </div>
            </section>

            <!-- Section 3 -->
            <section id="acceptable-use" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="shield-alert" style="width: 20px; height: 20px;"></i>
                    3. Acceptable Use Policy
                </h2>
                <div class="section-body">
                    <p>When operating the CRM, lead directory, team inbox, and communication tools, you agree not to:</p>
                    <ul>
                        <li>Use the messaging tools (WhatsApp API, SMS Gateway, Emailer) to transmit unsolicited commercial spam, unlawful content, or deceptive communications.</li>
                        <li>Attempt automated scraping, unauthorized reverse-engineering, or unauthorized penetration testing of system endpoints.</li>
                        <li>Upload malicious payloads, XLSX viruses, or corrupt data streams via batch upload tools.</li>
                        <li>Harvest or extract third-party client directory records for unauthorized redistribution.</li>
                    </ul>
                    <div class="highlight-box">
                        <strong>Anti-Spam Policy:</strong> Violating Meta WhatsApp Business Messaging Policies or sending bulk unsolicited messages through system gateways will result in immediate suspension of messaging features without notice.
                    </div>
                </div>
            </section>

            <!-- Section 4 -->
            <section id="system-availability" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="server" style="width: 20px; height: 20px;"></i>
                    4. System Availability & Service Level Agreement (SLA)
                </h2>
                <div class="section-body">
                    <p>Marg Soft Solution endeavors to maintain 99.9% uptime for core CRM modules and background database auto-sync engines. Scheduled maintenance windows will be communicated via top header banner notifications. We are not responsible for delays caused by upstream carrier networks, third-party internet service disruptions, or Meta WhatsApp Cloud API outages.</p>
                </div>
            </section>

            <!-- Section 5 -->
            <section id="intellectual-property" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="award" style="width: 20px; height: 20px;"></i>
                    5. Intellectual Property Rights
                </h2>
                <div class="section-body">
                    <p>All software code, database architecture, design systems, glassmorphism UI layouts, logos, trademarks, and documentation associated with Marg ERP CRM are the exclusive intellectual property of Marg Soft Solutions Inc. Clients retain full proprietary rights over their customer lead data, quotations, and commercial records stored within their database instance.</p>
                </div>
            </section>

            <!-- Section 6 -->
            <section id="liability" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="alert-triangle" style="width: 20px; height: 20px;"></i>
                    6. Limitation of Liability
                </h2>
                <div class="section-body">
                    <p>To the maximum extent permitted by applicable law, Marg Soft Solutions Inc. shall not be liable for any indirect, incidental, special, consequential, or punitive damages (including loss of profits, loss of business opportunities, or data loss) arising out of your use of or inability to use the CRM software.</p>
                </div>
            </section>

            <!-- Section 7 -->
            <section id="termination" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="user-x" style="width: 20px; height: 20px;"></i>
                    7. Account Termination & Data Export
                </h2>
                <div class="section-body">
                    <p>Subscribers may terminate their subscription at any time by providing written notice. Upon termination, client accounts can export all stored lead records, client directories, and invoices via native CSV/XLSX export tools. Marg Soft Solution reserves the right to suspend or terminate accounts that breach these Terms or engage in fraudulent activities.</p>
                </div>
            </section>

            <!-- Section 8 -->
            <section id="governing-law" class="section-block">
                <h2 class="section-heading">
                    <i data-lucide="globe" style="width: 20px; height: 20px;"></i>
                    8. Governing Law & Jurisdiction
                </h2>
                <div class="section-body">
                    <p>These Terms shall be governed by and construed in accordance with the laws of India. Any disputes arising in connection with these terms shall be subject to the exclusive jurisdiction of the competent courts located in New Delhi, India.</p>
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
