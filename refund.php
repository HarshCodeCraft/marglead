<?php
/**
 * Friendly AI Solution - Cancellation & Refund Policy
 * Enterprise Legal Policy Portal - Flipkart & Top Brand Standard Layout
 * 
 * Clean document architecture:
 * - Neutral light gray canvas (#f1f3f6)
 * - Left enterprise policy navigation sidebar (Terms, Privacy, Refund, Grievance)
 * - Right crisp white official paper document with readable typography (#212121)
 * - Dynamic policy clauses from database with Super Admin Add/Edit support
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$requested_page = 'refund';

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/policy_helper.php';

// Fetch points dynamically from database (with fallback)
$policy_points = get_policy_points('refund', true);
$last_updated_date = "August 28, 2026";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation & Refund Policy | Friendly AI Solution</title>
    <meta name="description" content="Official Cancellation & Refund Policy for Friendly AI Solution - 7-Day Money-Back Guarantee, SLA credits, transparent processing timelines, and billing helpdesk.">
    <meta name="keywords" content="Cancellation & Returns, Refund Policy, Friendly AI Solution Refund, Marg ERP Cancellation">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Base SaaS Theme -->
    <link rel="stylesheet" href="assets/css/public_theme.css?v=<?php echo time(); ?>">

    <style>
        /* Big-Brand Corporate Policy Layout (Flipkart / Enterprise Style) */
        body {
            background-color: #f1f3f6 !important;
            color: #212121 !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            font-size: 14px !important;
            line-height: 1.7 !important;
            margin: 0;
            padding: 0;
        }

        /* Top Breadcrumb Bar */
        .policy-breadcrumb-bar {
            background: #ffffff;
            border-bottom: 1px solid #e0e0e0;
            padding: 0.75rem 0;
            font-size: 0.82rem;
            color: #878787;
        }
        .policy-breadcrumb-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .policy-breadcrumb-container a {
            color: #2874f0;
            text-decoration: none;
        }
        .policy-breadcrumb-container a:hover {
            text-decoration: underline;
        }
        .policy-breadcrumb-sep {
            color: #c2c2c2;
            font-size: 0.75rem;
        }

        /* Two-Column Corporate Layout */
        .policy-main-container {
            max-width: 1240px;
            margin: 1.5rem auto 3.5rem auto;
            padding: 0 1.25rem;
            display: grid;
            grid-template-columns: 270px 1fr;
            gap: 1.25rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .policy-main-container {
                grid-template-columns: 1fr;
            }
        }

        /* Left Navigation Sidebar (Consumer Policy Menu) */
        .policy-sidebar {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 5rem;
        }
        .policy-sidebar-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e0e0e0;
            font-size: 0.85rem;
            font-weight: 700;
            color: #878787;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .policy-sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .policy-sidebar-item {
            border-bottom: 1px solid #f0f0f0;
        }
        .policy-sidebar-item:last-child {
            border-bottom: none;
        }
        .policy-sidebar-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1.25rem;
            color: #212121;
            font-size: 0.88rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .policy-sidebar-link:hover {
            background: #fafafa;
            color: #2874f0;
        }
        .policy-sidebar-link.active {
            background: #f5f9ff;
            color: #2874f0;
            font-weight: 700;
            border-left: 3px solid #2874f0;
            padding-left: calc(1.25rem - 3px);
        }
        .policy-sidebar-badge {
            font-size: 0.72rem;
            padding: 2px 6px;
            border-radius: 3px;
            background: #eef2ff;
            color: #2874f0;
            font-weight: 600;
        }

        /* Sidebar In-Page Clause Directory */
        .clause-index-box {
            padding: 1rem 1.25rem;
            background: #fbfbfb;
            border-top: 1px solid #e0e0e0;
        }
        .clause-index-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #878787;
            text-transform: uppercase;
            margin-bottom: 0.65rem;
        }
        .clause-index-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            max-height: 280px;
            overflow-y: auto;
        }
        .clause-index-link {
            font-size: 0.78rem;
            color: #565656;
            text-decoration: none;
            line-height: 1.4;
            display: block;
            padding: 2px 0;
        }
        .clause-index-link:hover {
            color: #2874f0;
            text-decoration: underline;
        }

        /* Right Content: Official White Paper Document */
        .policy-document-card {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 2.5rem 3rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        @media (max-width: 600px) {
            .policy-document-card {
                padding: 1.5rem;
            }
        }

        /* Document Header */
        .doc-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #212121;
            margin: 0 0 0.5rem 0;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .doc-meta {
            font-size: 0.82rem;
            color: #878787;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .doc-preamble {
            font-size: 0.88rem;
            color: #565656;
            margin-bottom: 1.75rem;
            line-height: 1.75;
            font-style: italic;
            background: #f9f9f9;
            padding: 1rem 1.25rem;
            border-left: 3px solid #2874f0;
            border-radius: 2px;
        }

        /* Document Clauses */
        .doc-section {
            margin-bottom: 2rem;
            padding-bottom: 1.75rem;
            border-bottom: 1px solid #f0f0f0;
            scroll-margin-top: 5rem;
        }
        .doc-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .doc-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        .doc-section-h2 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #212121;
            margin: 0;
            line-height: 1.4;
        }
        .doc-section-badge {
            font-size: 0.72rem;
            font-weight: 600;
            color: #2874f0;
            background: #f0f5ff;
            padding: 2px 8px;
            border-radius: 3px;
        }
        .doc-section-content {
            font-size: 0.9rem;
            color: #333333;
            line-height: 1.75;
        }
        .doc-section-content p {
            margin-bottom: 0.85rem;
        }
        .doc-section-content p:last-child {
            margin-bottom: 0;
        }
        .doc-section-content ul, .doc-section-content ol {
            padding-left: 1.5rem;
            margin: 0.75rem 0;
        }
        .doc-section-content li {
            margin-bottom: 0.45rem;
        }
        .doc-section-content strong {
            color: #212121;
        }
        .doc-section-content code {
            background: #f1f3f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.85em;
            color: #2874f0;
        }

        /* Grievance & Registered Address Card (Flipkart Corporate Footer Box) */
        .doc-grievance-box {
            background: #fafafa;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 1.5rem;
            margin-top: 2.5rem;
            font-size: 0.85rem;
        }
        .doc-grievance-title {
            font-weight: 700;
            color: #212121;
            font-size: 0.95rem;
            margin-bottom: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .doc-grievance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-top: 0.75rem;
            color: #565656;
        }
        @media (max-width: 650px) {
            .doc-grievance-grid {
                grid-template-columns: 1fr;
            }
        }
        .doc-grievance-grid strong {
            color: #212121;
        }
        .doc-grievance-grid a {
            color: #2874f0;
            text-decoration: none;
        }
        .doc-grievance-grid a:hover {
            text-decoration: underline;
        }

        @media print {
            .policy-breadcrumb-bar, .policy-sidebar, .public-nav, .public-footer {
                display: none !important;
            }
            .policy-main-container {
                grid-template-columns: 1fr !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .policy-document-card {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- Shared Navigation -->
    <?php require_once __DIR__ . '/includes/public_nav.php'; ?>

    <!-- Breadcrumb Bar -->
    <div class="policy-breadcrumb-bar">
        <div class="policy-breadcrumb-container">
            <a href="index.php">Home</a>
            <span class="policy-breadcrumb-sep">/</span>
            <span>Policies</span>
            <span class="policy-breadcrumb-sep">/</span>
            <span style="color: #212121; font-weight: 600;">Cancellation & Returns</span>
        </div>
    </div>

    <!-- Main Content Layout (Sidebar + Document) -->
    <main class="policy-main-container">
        
        <!-- Left Sidebar (Consumer Policy Links) -->
        <aside class="policy-sidebar">
            <div class="policy-sidebar-header">
                <span>Consumer Policy</span>
            </div>
            <ul class="policy-sidebar-nav">
                <li class="policy-sidebar-item">
                    <a href="terms.php" class="policy-sidebar-link">
                        <span>Terms of Use</span>
                        <span class="policy-sidebar-badge">T&C</span>
                    </a>
                </li>
                <li class="policy-sidebar-item">
                    <a href="privacy.php" class="policy-sidebar-link">
                        <span>Privacy Policy</span>
                        <span class="policy-sidebar-badge">Meta / DPDP</span>
                    </a>
                </li>
                <li class="policy-sidebar-item">
                    <a href="refund.php" class="policy-sidebar-link active">
                        <span>Cancellation & Returns</span>
                        <span class="policy-sidebar-badge"><?php echo count($policy_points); ?></span>
                    </a>
                </li>
                <li class="policy-sidebar-item">
                    <a href="contact.php" class="policy-sidebar-link">
                        <span>Help Centre & Support</span>
                    </a>
                </li>
                <li class="policy-sidebar-item">
                    <a href="#grievance-officer" class="policy-sidebar-link">
                        <span>Grievance Redressal</span>
                    </a>
                </li>
            </ul>

            <!-- Quick Table of Clauses -->
            <div class="clause-index-box">
                <div class="clause-index-title">Directory of Clauses</div>
                <div class="clause-index-list">
                    <?php foreach ($policy_points as $pt): ?>
                        <a href="#clause-<?php echo $pt['id']; ?>" class="clause-index-link">
                            <?php echo $pt['section_number']; ?>. <?php echo htmlspecialchars($pt['section_title']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <!-- Right Main Document Paper -->
        <article class="policy-document-card">
            
            <h1 class="doc-title">Cancellation & Refund Policy</h1>
            
            <div class="doc-meta">
                <div>
                    <span>Last Updated: <strong><?php echo $last_updated_date; ?></strong></span>
                    <span style="margin: 0 0.5rem;">•</span>
                    <span>Assurance: <strong>100% Risk-Free 7-Day Money-Back Guarantee & SLA Outage Protection</strong></span>
                </div>
            </div>

            <div class="doc-preamble">
                At <strong>Friendly AI Solution</strong>, we are committed to transparent, reliable, and customer-first business practices. This Cancellation and Refund Policy outlines the terms governing subscription cancellation, our 7-day evaluation money-back guarantee, non-refundable third-party costs, and direct bank settlement timelines.
            </div>

            <!-- Dynamic Policy Clauses -->
            <?php foreach ($policy_points as $point): ?>
                <section class="doc-section" id="clause-<?php echo $point['id']; ?>">
                    <div class="doc-section-header">
                        <h2 class="doc-section-h2">
                            <?php echo htmlspecialchars($point['section_number']); ?>. <?php echo htmlspecialchars($point['section_title']); ?>
                        </h2>
                        <?php if (!empty($point['section_badge'])): ?>
                            <span class="doc-section-badge">
                                <?php echo htmlspecialchars($point['section_badge']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="doc-section-content">
                        <?php echo $point['content']; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <!-- Corporate Registered Office & Grievance Address -->
            <div class="doc-grievance-box" id="grievance-officer">
                <div class="doc-grievance-title">Billing Resolution Desk & Registered Address</div>
                <p style="margin-bottom: 0.5rem;">
                    For any refund requests, billing inquiries, or invoice discrepancy resolutions, please contact our dedicated billing and accounts team:
                </p>
                <div class="doc-grievance-grid">
                    <div>
                        <strong>Billing & Accounts Desk:</strong><br>
                        Friendly AI Solution<br>
                        Email: <a href="mailto:billing@friendlyaisolution.com">billing@friendlyaisolution.com</a><br>
                        Helpline: <a href="tel:+919170897089">+91 91708 97089</a><br>
                        Resolution SLA: 24 to 48 Banking Hours
                    </div>
                    <div>
                        <strong>Registered Corporate Address:</strong><br>
                        Friendly AI Solution<br>
                        Marg ERP & WhatsApp Cloud API Suite<br>
                        Civil Lines / Mall Road<br>
                        Kanpur Nagar, Uttar Pradesh - 208001, India<br>
                        Website: <a href="https://friendlyaisolution.com">friendlyaisolution.com</a>
                    </div>
                </div>
            </div>

        </article>

    </main>

    <!-- Shared Footer -->
    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>

</body>
</html>
