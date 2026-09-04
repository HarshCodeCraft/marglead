<?php
/**
 * Friendly AI Solution - Marg ERP 9+ Price List & Dynamic Plan Selector
 * Authentic Marg Compusoft official pricing & ARC rules:
 * - Nano: ₹5,550 (ARC: ₹2,400)
 * - Basic: ₹10,300 (ARC: ₹3,990)
 * - Silver: ₹13,900 (ARC: ₹4,780) [Active by Default]
 * - Gold: ₹26,000 (ARC: ₹9,550 / ₹19,100)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$requested_page = 'pricing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marg ERP Price List & Plans | Friendly AI Solution</title>
    <meta name="description" content="Official Marg ERP 9+ price list, ARC charges, and detailed feature comparison: Nano (₹5,550), Basic (₹10,300), Silver (₹13,900), Gold (₹26,000) with Official WhatsApp Automation & Marg Cloud.">
    <meta name="keywords" content="Marg Price List, Marg ERP Price, Marg ARC Charges, Marg Basic Edition, Marg Silver Edition, Marg Gold Edition, Marg WhatsApp Automation">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Modern Public SaaS Theme CSS -->
    <link rel="stylesheet" href="assets/css/public_theme.css?v=<?php echo time(); ?>">
    
    <style>
        .edition-badge {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 50px;
            margin-bottom: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            width: fit-content;
        }
        .edition-badge-nano { background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; }
        .edition-badge-basic { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .edition-badge-silver { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .edition-badge-gold { background: #fefce8; color: #a16207; border: 1px solid #fef08a; }
        
        .comp-cat-row td {
            background: #f8fafc !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            font-size: 0.88rem !important;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1rem 1.5rem !important;
            border-top: 2px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .arc-card-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            position: relative;
            transition: all 0.25s ease;
        }
        .arc-card-box.active-arc {
            border: 2px solid #2563eb;
            background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.18);
        }
    </style>
</head>
<body>

    <!-- Shared Navigation -->
    <?php require_once __DIR__ . '/includes/public_nav.php'; ?>

    <!-- Pricing Hero Section -->
    <section class="hero-wrapper" style="padding-bottom: 1.5rem;">
        <div class="public-container">
            <div class="hero-content">
                
                <!-- Category Tabs on Top -->
                <div class="marg-cat-nav">
                    <button type="button" class="marg-cat-btn active" onclick="switchCategory('erp', this)">
                        <i data-lucide="layers" style="width: 16px; height: 16px;"></i>
                        <span>Marg ERP 9+ Software</span>
                    </button>
                    <button type="button" class="marg-cat-btn" onclick="switchCategory('whatsapp', this)">
                        <i data-lucide="message-circle" style="width: 16px; height: 16px;"></i>
                        <span>WhatsApp Automation API</span>
                    </button>
                    <button type="button" class="marg-cat-btn" onclick="switchCategory('ebusiness', this)">
                        <i data-lucide="smartphone" style="width: 16px; height: 16px;"></i>
                        <span>eBusiness Mobile Apps</span>
                    </button>
                    <button type="button" class="marg-cat-btn" onclick="switchCategory('cloud', this)">
                        <i data-lucide="cloud" style="width: 16px; height: 16px;"></i>
                        <span>Marg Cloud NVMe VPC</span>
                    </button>
                </div>

                <h1 class="hero-main-title">
                    Pricing, <span>Simplified!</span>
                </h1>

                <p class="hero-lead-text">
                    Take the perfect strategy for boosting your business with authentic Marg ERP licenses and official Meta WhatsApp Cloud API automation.
                </p>

                <!-- Region Currency Switcher -->
                <div class="region-tabs-row">
                    <div class="region-tab-group">
                        <button type="button" class="region-tab-btn active" id="btn-region-in" onclick="switchRegion('in')">
                            🇮🇳 India & South Asia (₹ INR)
                        </button>
                        <button type="button" class="region-tab-btn" id="btn-region-intl" onclick="switchRegion('intl')">
                            🌐 Other Countries ($ USD)
                        </button>
                    </div>
                </div>

                <!-- Tax & Notice Banner -->
                <div style="display: inline-flex; align-items: center; gap: 8px; background: #ffffff; border: 1px dashed #cbd5e1; padding: 6px 18px; border-radius: 50px; font-size: 0.85rem; color: #475569; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <i data-lucide="info" style="width: 15px; height: 15px; color: #2563eb;"></i>
                    <span><strong>18% GST Extra</strong> on all licenses & services. Click any card below to see AMC & expansion details.</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Pricing Grid: 4 Selectable Cards -->
    <section class="section-spacing" style="padding-top: 1.5rem;">
        <div class="public-container">

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
                <div>
                    <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: #0f172a; margin: 0;">Select Your Marg ERP Edition</h3>
                    <p style="font-size: 0.88rem; color: #64748b; margin: 0;">Click on any plan to view its tailored AMC, extra user costs, and exact specifications.</p>
                </div>
                <span id="selected-indicator-text" style="font-size: 0.85rem; font-weight: 700; color: #16a34a; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 4px 12px; border-radius: 50px;">
                    Currently Selected: Silver Edition
                </span>
            </div>

            <!-- 4 Interactive Selectable Cards -->
            <div class="pricing-grid-4" id="editions-card-grid">
                
                <!-- 1. Marg ERP Nano -->
                <div class="pricing-card-box" id="card-nano" onclick="selectEdition('nano')">
                    <div class="active-check-badge"><i data-lucide="check" style="width: 14px; height: 14px;"></i></div>
                    <span class="edition-badge edition-badge-nano">Nano 1.0 - 4.0</span>
                    <h3 class="pricing-plan-name">Marg ERP Nano</h3>
                    <p class="pricing-plan-desc">For small single-counter retail shops & micro billing counters.</p>
                    
                    <div class="pricing-price-box">
                        <div class="pricing-price-val">
                            <span class="price-currency" id="curr-nano">₹</span>
                            <span class="price-amount" id="amt-nano">5,550</span>
                        </div>
                        <div class="pricing-price-meta">
                            <span class="price-tax-badge">+ 18% GST</span>
                            <span class="price-type-text">One-time</span>
                        </div>
                    </div>

                    <ul class="plan-features-list">
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span><strong>1 User Included</strong> (Max 2 Users)</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Max 2 Companies Supported</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>₹ 3,000/- per extra user & company</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Fast POS Billing, Barcode & Cash Ledger</span>
                        </li>
                    </ul>

                    <button type="button" class="btn-brand btn-brand-secondary w-full" style="margin-top: auto; justify-content: center; width: 100%; pointer-events: none;">
                        <span id="btn-label-nano">Select Nano</span>
                    </button>
                </div>

                <!-- 2. Basic Edition -->
                <div class="pricing-card-box" id="card-basic" onclick="selectEdition('basic')">
                    <div class="active-check-badge"><i data-lucide="check" style="width: 14px; height: 14px;"></i></div>
                    <span class="edition-badge edition-badge-basic">Limited Edition</span>
                    <h3 class="pricing-plan-name">Basic Edition</h3>
                    <p class="pricing-plan-desc">For growing retail pharmacies & single-location wholesalers.</p>
                    
                    <div class="pricing-price-box">
                        <div class="pricing-price-val">
                            <span class="price-currency" id="curr-basic">₹</span>
                            <span class="price-amount" id="amt-basic">10,300</span>
                        </div>
                        <div class="pricing-price-meta">
                            <span class="price-tax-badge">+ 18% GST</span>
                            <span class="price-type-text">One-time</span>
                        </div>
                    </div>

                    <ul class="plan-features-list">
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span><strong>1 User (Full Rights)</strong> • Max 2 Users</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Max 2 Companies Supported</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Complete GST Returns (GSTR-1, 2, 3B)</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Batch, Expiry & Auto BRS Banking</span>
                        </li>
                    </ul>

                    <button type="button" class="btn-brand btn-brand-secondary w-full" style="margin-top: auto; justify-content: center; width: 100%; pointer-events: none;">
                        <span id="btn-label-basic">Select Basic</span>
                    </button>
                </div>

                <!-- 3. Silver Edition (ACTIVE BY DEFAULT) -->
                <div class="pricing-card-box active-edition" id="card-silver" onclick="selectEdition('silver')">
                    <div class="featured-pill-badge">Most Popular Choice</div>
                    <div class="active-check-badge"><i data-lucide="check" style="width: 14px; height: 14px;"></i></div>
                    <span class="edition-badge edition-badge-silver">Standard Edition</span>
                    <h3 class="pricing-plan-name">Silver Edition</h3>
                    <p class="pricing-plan-desc">The industry standard for pharma distributors, busy retail & stockists.</p>
                    
                    <div class="pricing-price-box" style="background: #ffffff; border-color: #bfdbfe;">
                        <div class="pricing-price-val">
                            <span class="price-currency" id="curr-silver" style="color: #2563eb;">₹</span>
                            <span class="price-amount" id="amt-silver" style="color: #2563eb;">13,900</span>
                        </div>
                        <div class="pricing-price-meta">
                            <span class="price-tax-badge">+ 18% GST</span>
                            <span class="price-type-text">One-time</span>
                        </div>
                    </div>

                    <ul class="plan-features-list">
                        <li class="plan-feature-row">
                            <div class="feat-icon" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;"><i data-lucide="check"></i></div>
                            <span><strong>1 Full + 1 View-Only User</strong> (LAN Ready)</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;"><i data-lucide="check"></i></div>
                            <span><strong>E-Invoicing & E-Way Bill Direct</strong></span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;"><i data-lucide="check"></i></div>
                            <span>Multi-Counter LAN Billing (₹3000/extra)</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;"><i data-lucide="check"></i></div>
                            <span>WhatsApp Bill Delivery & UPI Reminders</span>
                        </li>
                    </ul>

                    <button type="button" class="btn-brand btn-brand-primary w-full" style="margin-top: auto; justify-content: center; width: 100%; pointer-events: none;">
                        <span id="btn-label-silver">Selected Plan ✓</span>
                    </button>
                </div>

                <!-- 4. Gold Edition -->
                <div class="pricing-card-box" id="card-gold" onclick="selectEdition('gold')">
                    <div class="active-check-badge"><i data-lucide="check" style="width: 14px; height: 14px;"></i></div>
                    <span class="edition-badge edition-badge-gold">Enterprise Edition</span>
                    <h3 class="pricing-plan-name">Gold Edition</h3>
                    <p class="pricing-plan-desc">For large pharma distributors, C&F agents, manufacturing & branches.</p>
                    
                    <div class="pricing-price-box">
                        <div class="pricing-price-val">
                            <span class="price-currency" id="curr-gold">₹</span>
                            <span class="price-amount" id="amt-gold">26,000</span>
                        </div>
                        <div class="pricing-price-meta">
                            <span class="price-tax-badge">+ 18% GST</span>
                            <span class="price-type-text">One-time</span>
                        </div>
                    </div>

                    <ul class="plan-features-list">
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span><strong>Unlimited Users on LAN Network</strong></span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span><strong>Unlimited Companies & Godowns</strong></span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Full Manufacturing & Assembly Module</span>
                        </li>
                        <li class="plan-feature-row">
                            <div class="feat-icon"><i data-lucide="check"></i></div>
                            <span>Multi-Currency & Cloud Sync Ready</span>
                        </li>
                    </ul>

                    <button type="button" class="btn-brand btn-brand-secondary w-full" style="margin-top: auto; justify-content: center; width: 100%; pointer-events: none;">
                        <span id="btn-label-gold">Select Gold</span>
                    </button>
                </div>
            </div>

            <!-- DYNAMIC SELECTED EDITION BREAKDOWN & AMC PANEL -->
            <div class="selected-edition-panel" id="dynamic-edition-panel">
                <div class="dyn-panel-grid">
                    
                    <!-- Left: Selected Plan Specs & Inclusions -->
                    <div>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.5rem;">
                            <span class="edition-badge edition-badge-silver" id="dyn-badge">Standard Edition</span>
                            <span style="font-size: 0.8rem; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 2px 10px; border-radius: 50px;">Configured Setup</span>
                        </div>
                        
                        <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.85rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem 0;" id="dyn-title">
                            Marg ERP Silver Edition
                        </h2>
                        
                        <p style="font-size: 0.95rem; color: #64748b; margin: 0 0 1.5rem 0; line-height: 1.55;" id="dyn-desc">
                            The most popular package for growing pharma stockists and distributors with multi-counter billing, official E-Invoicing, and WhatsApp automation.
                        </p>

                        <!-- Highlights Grid -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                            <div style="background: #ffffff; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase;">User Capacity</div>
                                <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a; margin-top: 2px;" id="dyn-users">1 Full + 1 View-Only User</div>
                                <div style="font-size: 0.76rem; color: #2563eb; margin-top: 2px;" id="dyn-users-sub">₹ 3,000/- per extra user on LAN</div>
                            </div>
                            
                            <div style="background: #ffffff; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <div style="font-size: 0.78rem; font-weight: 600; color: #64748b; text-transform: uppercase;">Multi-Company Support</div>
                                <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a; margin-top: 2px;" id="dyn-companies">Multi-Firm Ready</div>
                                <div style="font-size: 0.76rem; color: #2563eb; margin-top: 2px;" id="dyn-companies-sub">₹ 3,000/- per extra company</div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 1.25rem; font-size: 0.85rem; color: #334155; font-weight: 600;">
                            <span style="display: inline-flex; align-items: center; gap: 6px;"><i data-lucide="shield-check" style="width: 16px; height: 16px; color: #10b981;"></i> 7-Layer Backup</span>
                            <span style="display: inline-flex; align-items: center; gap: 6px;"><i data-lucide="check-circle" style="width: 16px; height: 16px; color: #10b981;"></i> Full GST Returns</span>
                            <span style="display: inline-flex; align-items: center; gap: 6px;"><i data-lucide="message-circle" style="width: 16px; height: 16px; color: #10b981;"></i> WhatsApp Ready</span>
                        </div>
                    </div>

                    <!-- Right: Cost Summary & ARC Details -->
                    <div style="background: #ffffff; padding: 2rem; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                        <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem; margin-bottom: 1.25rem;">
                            <div style="font-size: 0.85rem; font-weight: 600; color: #64748b;">One-Time Software License Fee:</div>
                            <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 4px;">
                                <span style="font-size: 1.5rem; font-weight: 700; color: #0f172a;" id="dyn-curr">₹</span>
                                <span style="font-family: 'Outfit', sans-serif; font-size: 2.5rem; font-weight: 900; color: #0f172a; line-height: 1;" id="dyn-price">13,900</span>
                                <span style="font-size: 0.85rem; color: #64748b; margin-left: 6px;">+ 18% GST</span>
                            </div>
                        </div>

                        <!-- Tailored ARC / AMC Block for Selected Edition -->
                        <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 0.88rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                    <i data-lucide="refresh-cw" style="width: 15px; height: 15px; color: #2563eb;"></i>
                                    Annual Maintenance (ARC):
                                </span>
                                <span style="font-size: 1.15rem; font-weight: 800; color: #2563eb;" id="dyn-arc">₹ 4,780/- <span style="font-size: 0.75rem; font-weight: 500; color: #64748b;">/ yr</span></span>
                            </div>
                            <p style="font-size: 0.8rem; color: #64748b; margin: 0.5rem 0 0 0; line-height: 1.4;" id="dyn-arc-desc">
                                From 2nd year onwards. Includes statutory GST patch updates, e-way/e-invoice bridge, and support. (Extra User ARC: ₹ 1,050/yr, Extra Co ARC: ₹ 1,030/yr).
                            </p>
                        </div>

                        <button type="button" id="dyn-cta-btn" onclick="openLeadModal('Pricing: Silver Edition (₹13,900)')" class="btn-brand btn-brand-primary w-full" style="justify-content: center; width: 100%; padding: 0.9rem;">
                            <span id="dyn-cta-text">Book Silver Edition Free Demo →</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 5. All Editions ARC / Maintenance Table (Matching Official Marg Price List) -->
            <div style="margin-top: 4.5rem;">
                <div style="text-align: center; margin-bottom: 2.25rem;">
                    <div class="section-tag" style="margin-bottom: 0.5rem;">Official Support Standards</div>
                    <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin: 0 0 0.5rem 0;">
                        Marg ARC Charges (Annual Maintenance)
                    </h3>
                    <p style="font-size: 0.95rem; color: var(--text-muted); margin: 0;">
                        Official Marg Compusoft renewal & support pricing for uninterrupted GST compliance and updates.
                    </p>
                </div>

                <div class="pricing-grid-4">
                    <!-- ARC Nano -->
                    <div class="arc-card-box" id="arc-box-nano">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">Marg ERP Nano</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.65rem; font-weight: 800; color: #0f172a; margin: 6px 0;">₹ 2,400/- <span style="font-size: 0.78rem; font-weight: 400; color: #64748b;">+ GST</span></div>
                        <ul style="list-style: none; padding: 0; margin: 10px 0 0 0; font-size: 0.82rem; color: #475569; display: flex; flex-direction: column; gap: 6px;">
                            <li>• Base Nano ARC: ₹ 2,400/yr</li>
                            <li>• Extra User ARC: ₹ 1,050/yr</li>
                            <li>• Extra Company ARC: ₹ 1,030/yr</li>
                        </ul>
                    </div>

                    <!-- ARC Basic -->
                    <div class="arc-card-box" id="arc-box-basic">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase;">Basic Edition</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.65rem; font-weight: 800; color: #0f172a; margin: 6px 0;">₹ 3,990/- <span style="font-size: 0.78rem; font-weight: 400; color: #64748b;">+ GST</span></div>
                        <ul style="list-style: none; padding: 0; margin: 10px 0 0 0; font-size: 0.82rem; color: #475569; display: flex; flex-direction: column; gap: 6px;">
                            <li>• Base Limited ARC: ₹ 3,990/yr</li>
                            <li>• Additional User ARC: ₹ 1,050/yr</li>
                            <li>• Extra Company ARC: ₹ 1,030/yr</li>
                        </ul>
                    </div>

                    <!-- ARC Silver (Active Highlight) -->
                    <div class="arc-card-box active-arc" id="arc-box-silver">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #15803d; text-transform: uppercase;">Silver Edition (Standard)</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.65rem; font-weight: 800; color: #2563eb; margin: 6px 0;">₹ 4,780/- <span style="font-size: 0.78rem; font-weight: 400; color: #64748b;">+ GST</span></div>
                        <ul style="list-style: none; padding: 0; margin: 10px 0 0 0; font-size: 0.82rem; color: #475569; display: flex; flex-direction: column; gap: 6px;">
                            <li>• Base Standard ARC: ₹ 4,780/yr</li>
                            <li>• Additional User ARC: ₹ 1,050/yr</li>
                            <li>• Extra Company ARC: ₹ 1,030/yr</li>
                        </ul>
                    </div>

                    <!-- ARC Gold -->
                    <div class="arc-card-box" id="arc-box-gold">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #a16207; text-transform: uppercase;">Gold Edition (Enterprise)</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.65rem; font-weight: 800; color: #0f172a; margin: 6px 0;">₹ 9,550/- <span style="font-size: 0.78rem; font-weight: 400; color: #64748b;">+ GST</span></div>
                        <ul style="list-style: none; padding: 0; margin: 10px 0 0 0; font-size: 0.82rem; color: #475569; display: flex; flex-direction: column; gap: 6px;">
                            <li>• Up to 25 Users: ₹ 9,550/yr</li>
                            <li>• Unlimited Users: ₹ 19,100/yr</li>
                            <li>• Unlimited Companies Included</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 6. Detailed Feature Comparison Matrix (Comprehensive Table) -->
            <div class="comparison-table-wrapper">
                <div style="padding: 1.85rem 2.25rem; border-bottom: 1px solid var(--border-subtle); background: #f8fafc; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.45rem; font-weight: 800; color: var(--text-primary); margin: 0 0 0.25rem 0;">Detailed Plan Feature Comparison</h3>
                        <p style="font-size: 0.925rem; color: var(--text-muted); margin: 0;">Compare technical capabilities, limits, and module availability across all Marg ERP editions.</p>
                    </div>
                    <span style="font-size: 0.85rem; font-weight: 700; color: #2563eb; background: #eff6ff; border: 1px solid #bfdbfe; padding: 6px 14px; border-radius: 50px;">Authentic Marg Compusoft Matrix</span>
                </div>

                <div style="overflow-x: auto;">
                    <table class="comp-table">
                        <thead>
                            <tr>
                                <th style="width: 36%;">Core Capabilities & Modules</th>
                                <th style="width: 16%; text-align: center;">Nano (₹5,550)</th>
                                <th style="width: 16%; text-align: center;">Basic (₹10,300)</th>
                                <th style="width: 16%; text-align: center; color: var(--primary);">Silver (₹13,900)</th>
                                <th style="width: 16%; text-align: center; color: #ca8a04;">Gold (₹26,000)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Category: License & Architecture -->
                            <tr class="comp-cat-row">
                                <td colspan="5">1. Architecture, Users & Capacity</td>
                            </tr>
                            <tr>
                                <td>Default Included Users</td>
                                <td style="text-align: center;">1 User (Single PC)</td>
                                <td style="text-align: center;">1 User (Full Rights)</td>
                                <td style="text-align: center; font-weight: 700; color: var(--primary);">1 Full + 1 View-Only</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Unlimited (LAN)</td>
                            </tr>
                            <tr>
                                <td>Maximum User Expansion Limit</td>
                                <td style="text-align: center;">Max 2 Users</td>
                                <td style="text-align: center;">Max 2 Users</td>
                                <td style="text-align: center;">Multi-User Add-ons</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Unlimited Users</td>
                            </tr>
                            <tr>
                                <td>Multi-Company Support</td>
                                <td style="text-align: center;">Max 2 Companies</td>
                                <td style="text-align: center;">Max 2 Companies</td>
                                <td style="text-align: center;">Add-on Companies</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Unlimited Companies</td>
                            </tr>
                            <tr>
                                <td>Multi-Location / Multi-Godown</td>
                                <td style="text-align: center;">Single Location</td>
                                <td style="text-align: center;">Limited</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>7-Layer Auto Backup & Security</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>

                            <!-- Category: GST & Billing -->
                            <tr class="comp-cat-row">
                                <td colspan="5">2. GST Invoicing & Compliance</td>
                            </tr>
                            <tr>
                                <td>GST Billing (B2B, B2C, POS Retail)</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>E-Way Bill Generation & Portal Sync</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>E-Invoicing (IRN & B2B QR Code Direct)</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>GST Return Filing (GSTR-1, 2, 3B, 9 JSON/Excel)</td>
                                <td style="text-align: center;">Basic Summary</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Multi-Rate Tax & Cess Calculation</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>

                            <!-- Category: Inventory & Pharma Control -->
                            <tr class="comp-cat-row">
                                <td colspan="5">3. Inventory, Batch & Supply Chain Management</td>
                            </tr>
                            <tr>
                                <td>Pharma Batch & Expiry Tracking</td>
                                <td style="text-align: center;">Basic</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Barcode Scanning & Thermal Label Printing</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Purchase Bill Import (Excel/CSV/PDF)</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Scheme, Deal & Free Item Matrix (Pharma/FMCG)</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;">Standard</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Re-order Management & Minimum Stock Alerts</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Manufacturing & Production Assembly Module</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Included Free</td>
                            </tr>

                            <!-- Category: Accounting & Banking -->
                            <tr class="comp-cat-row">
                                <td colspan="5">4. Financial Accounting & Digital Banking</td>
                            </tr>
                            <tr>
                                <td>General Ledger, Trial Balance, P&L & Balance Sheet</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Auto Bank Reconciliation (ICICI, Axis, HDFC BRS)</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Customer Credit Limit & Outstanding Management</td>
                                <td style="text-align: center;">Basic</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Multi-Currency Accounting</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Included Free</td>
                            </tr>

                            <!-- Category: Friendly AI WhatsApp & Cloud Automation -->
                            <tr class="comp-cat-row">
                                <td colspan="5">5. Friendly AI Official WhatsApp Automation & Cloud VPC</td>
                            </tr>
                            <tr>
                                <td>Official Meta WhatsApp Bill Delivery (Zero Ban Risk)</td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center; font-weight: 700; color: var(--primary);">Instant Ready</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Instant Ready</td>
                            </tr>
                            <tr>
                                <td>Auto Payment & Outstanding UPI Reminders</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Customer Ledger PDF & 2-Way Chatbot on WhatsApp</td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="minus" style="color: #94a3b8; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                                <td style="text-align: center;"><i data-lucide="check" style="color: #10b981; margin: 0 auto;"></i></td>
                            </tr>
                            <tr>
                                <td>Marg Cloud NVMe VPC Remote Server Hosting</td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center;">Optional Add-on</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">Included Ready</td>
                            </tr>
                            <tr>
                                <td>Technical Support SLA & Onboarding</td>
                                <td style="text-align: center;">Standard Email</td>
                                <td style="text-align: center;">Priority Helpdesk</td>
                                <td style="text-align: center; font-weight: 700; color: var(--primary);">15-Min Priority SLA</td>
                                <td style="text-align: center; font-weight: 700; color: #16a34a;">24/7 Dedicated Manager</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing FAQs -->
    <section class="section-spacing" style="background: #f1f5f9; border-top: 1px solid var(--border-subtle);">
        <div class="public-container">
            <div class="section-header">
                <div class="section-tag">Got Questions?</div>
                <h2 class="section-title">Frequently Asked <span>Questions</span></h2>
                <p class="section-subtitle">Transparent answers about Marg ERP editions, add-on user licenses, and WhatsApp integration.</p>
            </div>

            <div class="faq-grid">
                <div class="faq-box">
                    <h4 class="faq-question"><i data-lucide="help-circle"></i> Are Marg ERP license prices one-time or recurring?</h4>
                    <p class="faq-answer">Marg ERP software licenses (Nano, Basic, Silver, Gold) are **one-time purchase fees**. In subsequent years, you only pay the nominal Annual Maintenance Charges (AMC / ARC) for statutory GST patch updates and continuous support.</p>
                </div>

                <div class="faq-box">
                    <h4 class="faq-question"><i data-lucide="help-circle"></i> How much do extra users and extra companies cost?</h4>
                    <p class="faq-answer">Extra user and extra company capacity costs **₹ 3,000/- + 18% GST** per user/company on Silver and Basic editions (subject to edition limits). Gold Edition comes with **unlimited users and unlimited companies** on your LAN network.</p>
                </div>

                <div class="faq-box">
                    <h4 class="faq-question"><i data-lucide="help-circle"></i> Is WhatsApp billing integrated with Marg ERP safe from blocking?</h4>
                    <p class="faq-answer">100% safe! Friendly AI Solution uses the **Official Meta WhatsApp Cloud API** (Facebook Developer Enterprise Platform). Your Marg ERP invoices, payment links, and ledger statements are dispatched via Meta-approved templates with zero risk of number blocking.</p>
                </div>

                <div class="faq-box">
                    <h4 class="faq-question"><i data-lucide="help-circle"></i> Can I upgrade from Basic or Silver to Gold Edition later?</h4>
                    <p class="faq-answer">Yes, absolutely! You can upgrade your edition at any time by paying the license differential, and all your existing products, batches, customer ledgers, and transaction history will be preserved seamlessly.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Shared Footer -->
    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>

    <!-- Interactive Data Store & Selection Logic -->
    <script>
        const EDITIONS_DATA = {
            in: {
                nano: {
                    name: 'Marg ERP Nano',
                    badge: 'Nano 1.0 - 4.0',
                    badgeClass: 'edition-badge-nano',
                    currency: '₹',
                    price: '5,550',
                    desc: 'Entry-level GST billing software for small single-counter retail pharmacies & grocery shops.',
                    users: '1 User (Single PC)',
                    usersSub: '₹ 3,000/- per extra user (Max 2 Users)',
                    companies: 'Max 2 Companies',
                    companiesSub: '₹ 3,000/- per extra company',
                    arc: '₹ 2,400/-',
                    arcDesc: 'Base Nano Annual Maintenance. Includes statutory tax updates. (Extra User ARC: ₹ 1,050/yr, Extra Co: ₹ 1,030/yr).',
                    cta: 'Book Marg ERP Nano (₹5,550)',
                    btnText: 'Book Nano Edition Free Demo →'
                },
                basic: {
                    name: 'Marg ERP Basic Edition',
                    badge: 'Limited Edition',
                    badgeClass: 'edition-badge-basic',
                    currency: '₹',
                    price: '10,300',
                    desc: 'For growing retail pharmacies & single-location wholesalers needing GST returns, expiry management & BRS.',
                    users: '1 User (Full Rights)',
                    usersSub: '₹ 3,000/- per extra user (Max 2 Users)',
                    companies: 'Max 2 Companies',
                    companiesSub: '₹ 3,000/- per extra company',
                    arc: '₹ 3,990/-',
                    arcDesc: 'Base Limited Annual Maintenance. Includes GST patch updates & BRS bank sync. (Extra User ARC: ₹ 1,050/yr, Extra Co: ₹ 1,030/yr).',
                    cta: 'Book Basic Edition (₹10,300)',
                    btnText: 'Book Basic Edition Free Demo →'
                },
                silver: {
                    name: 'Marg ERP Silver Edition',
                    badge: 'Standard Edition (Most Popular)',
                    badgeClass: 'edition-badge-silver',
                    currency: '₹',
                    price: '13,900',
                    desc: 'The most popular package for growing pharma stockists and distributors with multi-counter billing, official E-Invoicing, and WhatsApp automation.',
                    users: '1 Full + 1 View-Only User',
                    usersSub: '₹ 3,000/- per extra user on LAN',
                    companies: 'Multi-Firm Ready',
                    companiesSub: '₹ 3,000/- per extra company',
                    arc: '₹ 4,780/-',
                    arcDesc: 'From 2nd year onwards. Includes statutory GST patch updates, e-way/e-invoice bridge, and support. (Extra User ARC: ₹ 1,050/yr, Extra Co ARC: ₹ 1,030/yr).',
                    cta: 'Book Silver Edition (₹13,900)',
                    btnText: 'Book Silver Edition Free Demo →'
                },
                gold: {
                    name: 'Marg ERP Gold Edition',
                    badge: 'Enterprise Edition',
                    badgeClass: 'edition-badge-gold',
                    currency: '₹',
                    price: '26,000',
                    desc: 'Comprehensive multi-user ERP for large distributors, C&F agents, manufacturing & multi-branch enterprises.',
                    users: 'Unlimited Users (LAN)',
                    usersSub: 'Unlimited Users on Local Area Network',
                    companies: 'Unlimited Companies',
                    companiesSub: 'Unlimited Multi-Godowns Included',
                    arc: '₹ 9,550/- (≤25 Users)',
                    arcDesc: 'Up to 25 Users: ₹ 9,550/yr + GST. Unlimited Users: ₹ 19,100/yr + GST. Includes unlimited company maintenance.',
                    cta: 'Book Gold Enterprise (₹26,000)',
                    btnText: 'Book Gold Enterprise Consultation →'
                }
            },
            intl: {
                nano: {
                    name: 'Marg ERP Nano (Global)',
                    badge: 'Nano Global',
                    badgeClass: 'edition-badge-nano',
                    currency: '$',
                    price: '85',
                    desc: 'Entry-level billing software for single-counter international businesses.',
                    users: '1 User (Single PC)',
                    usersSub: '$50 per extra user (Max 2)',
                    companies: 'Max 2 Companies',
                    companiesSub: '$50 per extra company',
                    arc: '$ 45 / yr',
                    arcDesc: 'Annual software updates and cloud patch services.',
                    cta: 'Book Nano Global ($85)',
                    btnText: 'Book Nano Global Demo →'
                },
                basic: {
                    name: 'Marg ERP Basic Edition (Global)',
                    badge: 'Limited Edition',
                    badgeClass: 'edition-badge-basic',
                    currency: '$',
                    price: '145',
                    desc: 'Single-location international inventory and accounting management.',
                    users: '1 User (Full Rights)',
                    usersSub: '$50 per extra user (Max 2)',
                    companies: 'Max 2 Companies',
                    companiesSub: '$50 per extra company',
                    arc: '$ 65 / yr',
                    arcDesc: 'Annual software updates, backup security and technical support.',
                    cta: 'Book Basic Global ($145)',
                    btnText: 'Book Basic Global Demo →'
                },
                silver: {
                    name: 'Marg ERP Silver Edition (Global)',
                    badge: 'Standard Edition (Global)',
                    badgeClass: 'edition-badge-silver',
                    currency: '$',
                    price: '195',
                    desc: 'Multi-counter LAN standard package for established international distributors & retailers.',
                    users: '1 Full + 1 View-Only User',
                    usersSub: '$50 per extra user on LAN',
                    companies: 'Multi-Firm License',
                    companiesSub: '$50 per extra company',
                    arc: '$ 80 / yr',
                    arcDesc: 'Annual support and international invoicing updates. ($20 per extra user ARC).',
                    cta: 'Book Silver Global ($195)',
                    btnText: 'Book Silver Global Demo →'
                },
                gold: {
                    name: 'Marg ERP Gold Edition (Global)',
                    badge: 'Enterprise Global',
                    badgeClass: 'edition-badge-gold',
                    currency: '$',
                    price: '365',
                    desc: 'Unlimited multi-user, multi-company ERP with manufacturing & multi-currency.',
                    users: 'Unlimited Users (LAN)',
                    usersSub: 'Unlimited Users on Local Network',
                    companies: 'Unlimited Companies',
                    companiesSub: 'Unlimited Godowns & Branches',
                    arc: '$ 160 / yr',
                    arcDesc: 'Up to 25 users ($160/yr) or Unlimited Users ($320/yr) with 24/7 support SLA.',
                    cta: 'Book Gold Global ($365)',
                    btnText: 'Book Gold Global Consultation →'
                }
            }
        };

        let currentRegion = 'in';
        let currentSelectedEdition = 'silver';

        function selectEdition(editionKey) {
            currentSelectedEdition = editionKey;
            
            // 1. Update card active classes
            const allCards = ['nano', 'basic', 'silver', 'gold'];
            allCards.forEach(key => {
                const cardEl = document.getElementById('card-' + key);
                const btnLabel = document.getElementById('btn-label-' + key);
                const arcBox = document.getElementById('arc-box-' + key);
                
                if (key === editionKey) {
                    cardEl.classList.add('active-edition');
                    if (btnLabel) btnLabel.innerText = 'Selected Plan ✓';
                    if (arcBox) arcBox.classList.add('active-arc');
                } else {
                    cardEl.classList.remove('active-edition');
                    if (btnLabel) btnLabel.innerText = 'Select ' + (key.charAt(0).toUpperCase() + key.slice(1));
                    if (arcBox) arcBox.classList.remove('active-arc');
                }
            });

            // 2. Fetch edition details
            const data = EDITIONS_DATA[currentRegion][editionKey];
            if (!data) return;

            // 3. Update dynamic panel elements
            document.getElementById('dyn-title').innerText = data.name;
            document.getElementById('dyn-desc').innerText = data.desc;
            document.getElementById('dyn-badge').innerText = data.badge;
            document.getElementById('dyn-badge').className = 'edition-badge ' + data.badgeClass;
            document.getElementById('dyn-curr').innerText = data.currency;
            document.getElementById('dyn-price').innerText = data.price;
            document.getElementById('dyn-users').innerText = data.users;
            document.getElementById('dyn-users-sub').innerText = data.usersSub;
            document.getElementById('dyn-companies').innerText = data.companies;
            document.getElementById('dyn-companies-sub').innerText = data.companiesSub;
            document.getElementById('dyn-arc').innerHTML = data.arc + ' <span style="font-size: 0.75rem; font-weight: 500; color: #64748b;">/ yr</span>';
            document.getElementById('dyn-arc-desc').innerText = data.arcDesc;
            document.getElementById('dyn-cta-text').innerText = data.btnText;
            
            // Update CTA onclick
            const ctaBtn = document.getElementById('dyn-cta-btn');
            ctaBtn.setAttribute('onclick', `openLeadModal('${data.cta}')`);

            // Update top selected indicator
            document.getElementById('selected-indicator-text').innerText = 'Currently Selected: ' + data.name;

            // Smooth subtle highlight pulse on dynamic panel
            const dynPanel = document.getElementById('dynamic-edition-panel');
            dynPanel.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
            dynPanel.style.borderColor = '#2563eb';
            dynPanel.style.boxShadow = '0 20px 45px -10px rgba(37, 99, 235, 0.25)';
            setTimeout(() => {
                dynPanel.style.boxShadow = '0 15px 35px -10px rgba(59, 130, 246, 0.15)';
            }, 600);

            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        function switchRegion(regionKey) {
            currentRegion = regionKey;
            
            const btnIn = document.getElementById('btn-region-in');
            const btnIntl = document.getElementById('btn-region-intl');
            
            if (regionKey === 'in') {
                btnIn.classList.add('active');
                btnIntl.classList.remove('active');
                
                document.getElementById('curr-nano').innerText = '₹';
                document.getElementById('amt-nano').innerText = '5,550';
                document.getElementById('curr-basic').innerText = '₹';
                document.getElementById('amt-basic').innerText = '10,300';
                document.getElementById('curr-silver').innerText = '₹';
                document.getElementById('amt-silver').innerText = '13,900';
                document.getElementById('curr-gold').innerText = '₹';
                document.getElementById('amt-gold').innerText = '26,000';
            } else {
                btnIntl.classList.add('active');
                btnIn.classList.remove('active');
                
                document.getElementById('curr-nano').innerText = '$';
                document.getElementById('amt-nano').innerText = '85';
                document.getElementById('curr-basic').innerText = '$';
                document.getElementById('amt-basic').innerText = '145';
                document.getElementById('curr-silver').innerText = '$';
                document.getElementById('amt-silver').innerText = '195';
                document.getElementById('curr-gold').innerText = '$';
                document.getElementById('amt-gold').innerText = '365';
            }

            selectEdition(currentSelectedEdition);
        }

        function switchCategory(catKey, btnEl) {
            document.querySelectorAll('.marg-cat-btn').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');

            if (catKey === 'whatsapp') {
                openLeadModal('Category Inquiry: Official Meta WhatsApp Automation API');
            } else if (catKey === 'ebusiness') {
                openLeadModal('Category Inquiry: eBusiness Mobile Apps (eOrder, eRetail, eOwner)');
            } else if (catKey === 'cloud') {
                openLeadModal('Category Inquiry: Marg Cloud NVMe VPC Remote Server');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            // Initialize default selected edition (Silver)
            selectEdition('silver');
        });
    </script>
</body>
</html>
