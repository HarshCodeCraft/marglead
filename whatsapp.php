<?php
/**
 * Friendly AI Solution - Dedicated Meta WhatsApp Cloud API Showcase
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$requested_page = 'whatsapp';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official WhatsApp Cloud API Suite - Friendly AI Solution</title>
    <meta name="description" content="Integrate official Meta WhatsApp Cloud API with Marg ERP 9+. Send automated bill PDFs, collect UPI payments, broadcast promotional offers with zero ban risk.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Modern Public SaaS Theme CSS -->
    <link rel="stylesheet" href="assets/css/public_theme.css?v=<?php echo time(); ?>">
</head>
<body>

    <!-- Shared Navigation -->
    <?php require_once __DIR__ . '/includes/public_nav.php'; ?>

    <!-- WhatsApp Hero -->
    <section class="hero-wrapper">
        <div class="public-container">
            <div class="hero-content">
                <div class="hero-badge-pill">
                    <i data-lucide="check-circle-2" style="width: 15px; height: 15px; color: var(--whatsapp);"></i>
                    <span>Official Meta Tech Partner Integration</span>
                </div>

                <h1 class="hero-main-title">
                    Official <span>Meta WhatsApp Cloud API</span> for Marg ERP
                </h1>

                <p class="hero-lead-text">
                    Send GST bills, recover payments, and run 98% open-rate broadcast campaigns directly from your official WhatsApp Business number with <strong>0% Number Ban Risk</strong>.
                </p>

                <div class="hero-cta-group">
                    <button type="button" onclick="openLeadModal('WhatsApp API Page CTA')" class="btn-brand btn-brand-whatsapp">
                        <i data-lucide="message-square" style="width: 16px; height: 16px;"></i>
                        <span>Get Started with WhatsApp API</span>
                    </button>
                    <a href="index.php?page=pricing" class="btn-brand btn-brand-secondary">
                        <span>View Plans & Pricing</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 4 Key WhatsApp Capabilities -->
    <section class="section-spacing">
        <div class="public-container">
            <div class="section-header">
                <div class="section-tag">Enterprise WhatsApp Automation</div>
                <h2 class="section-title">Why Businesses Choose <span>Official WhatsApp Cloud API</span></h2>
                <p class="section-subtitle">
                    Unofficial WhatsApp extensions frequently result in permanent business number bans. Friendly AI Solution connects directly to Meta's verified enterprise cloud infrastructure.
                </p>
            </div>

            <div class="features-grid-3">
                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-green">
                        <i data-lucide="shield-check"></i>
                    </div>
                    <h3 class="feature-box-title">Zero Number Ban Risk</h3>
                    <p class="feature-box-desc">
                        Powered by official Meta Cloud API. Your business number is 100% compliant with Meta Business Policies. No risk of sudden disconnects or bans.
                    </p>
                </div>

                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-blue">
                        <i data-lucide="badge-check"></i>
                    </div>
                    <h3 class="feature-box-title">Green Tick Verification</h3>
                    <p class="feature-box-desc">
                        Build supreme credibility with your retail customers and pharma stockists. Our team assists your brand in getting the coveted Meta Green Verified Tick.
                    </p>
                </div>

                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-purple">
                        <i data-lucide="bot"></i>
                    </div>
                    <h3 class="feature-box-title">24/7 AI Chatbot & Flows</h3>
                    <p class="feature-box-desc">
                        Let customers check ledger balance, request price lists, and download duplicate invoice PDFs automatically 24/7 without manual staff intervention.
                    </p>
                </div>

                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-amber">
                        <i data-lucide="megaphone"></i>
                    </div>
                    <h3 class="feature-box-title">Marketing Broadcasts</h3>
                    <p class="feature-box-desc">
                        Send promotional offers, seasonal discounts, and new product launch catalogs with 98% open rates and interactive CTA buttons.
                    </p>
                </div>

                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-blue">
                        <i data-lucide="inbox"></i>
                    </div>
                    <h3 class="feature-box-title">Multi-Agent Team Inbox</h3>
                    <p class="feature-box-desc">
                        Multiple staff members can chat with customers from one single official WhatsApp Business number simultaneously with assigned agent tags.
                    </p>
                </div>

                <div class="feature-box">
                    <div class="feature-box-icon metric-icon-green">
                        <i data-lucide="zap"></i>
                    </div>
                    <h3 class="feature-box-title">2-Minute 1-Click Setup</h3>
                    <p class="feature-box-desc">
                        Connect your Facebook Business Manager and WhatsApp number instantly with Meta Embedded Signup in just 2 minutes.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Shared Footer -->
    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>

</body>
</html>
