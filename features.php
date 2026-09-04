<?php
/**
 * Friendly AI Solution - Marg ERP 9+ Features & Official Software Comparison Chart
 * Based directly on Marg Compusoft Official Comparison: Basic, Silver & Gold Editions.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$requested_page = 'features';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marg ERP Features & Comparison Chart | Friendly AI Solution</title>
    <meta name="description" content="Official Marg ERP Software Comparison Features Chart: Compare Basic, Silver, and Gold edition capabilities for inventory, GST billing, e-Invoicing, WhatsApp automation and accounting.">
    <meta name="keywords" content="Marg Features, Marg ERP Comparison Chart, Marg Basic vs Silver vs Gold, Marg ERP Price List, Marg WhatsApp Automation">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Modern Public SaaS Theme CSS -->
    <link rel="stylesheet" href="assets/css/public_theme.css?v=<?php echo time(); ?>">
    
    <style>
        .feature-search-box {
            position: relative;
            max-width: 400px;
            width: 100%;
        }
        .feature-search-input {
            width: 100%;
            padding: 0.65rem 1rem 0.65rem 2.75rem;
            border-radius: 50px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            font-size: 0.88rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .feature-search-input:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
        }
        .search-icon-pos {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }
    </style>
</head>
<body>

    <!-- Shared Navigation -->
    <?php require_once __DIR__ . '/includes/public_nav.php'; ?>

    <!-- Features Hero -->
    <section class="hero-wrapper" style="padding-bottom: 2rem;">
        <div class="public-container">
            <div class="hero-content">
                <div class="hero-badge-pill">
                    <i data-lucide="check-square" style="width: 15px; height: 15px;"></i>
                    <span>Official Marg Compusoft Comparison Matrix</span>
                </div>

                <h1 class="hero-main-title">
                    Software Comparison <span>Features Chart</span>
                </h1>

                <p class="hero-lead-text">
                    Automate GST billing, inventory, multi-godown stock, and e-Invoicing with Marg ERP's advanced features designed to accelerate trade operations.
                </p>

                <div class="hero-cta-group">
                    <button type="button" onclick="openLeadModal('Features Hero CTA')" class="btn-brand btn-brand-primary">
                        <span>Book a 1-on-1 Feature Tour</span>
                        <i data-lucide="arrow-right" style="width: 16px; height: 16px;"></i>
                    </button>
                    <a href="pricing.php" class="btn-brand btn-brand-secondary">
                        <span>View Plans & Pricing</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Deep-Dive Features Grid -->
    <section class="section-spacing" style="padding-top: 1.5rem;">
        <div class="public-container">
            
            <!-- Feature 1: Marg ERP Sync -->
            <div id="marg-sync" style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 3.5rem; align-items: center; margin-bottom: 4.5rem;">
                <div>
                    <div class="section-tag" style="background: var(--primary-light); color: var(--primary);">Real-Time Connectivity</div>
                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 2.1rem; font-weight: 800; margin-bottom: 1rem; color: var(--text-primary);">
                        Real-Time Marg ERP 9+ Two-Way Sync
                    </h2>
                    <p style="font-size: 0.98rem; color: var(--text-secondary); line-height: 1.7; margin-bottom: 1.5rem;">
                        Our lightweight, secure Marg Bridge runs seamlessly in the background on your local computer or cloud VPC server. It continuously monitors bill creation, ledger modifications, and payment vouchers without slowing down Marg ERP.
                    </p>
                    <ul class="feature-check-list" style="margin-bottom: 1.5rem;">
                        <li class="feature-check-item"><i data-lucide="check"></i> Sub-second (0.2s) latency event detection</li>
                        <li class="feature-check-item"><i data-lucide="check"></i> Works with Marg ERP 9+, Marg Books, and multi-user LAN</li>
                        <li class="feature-check-item"><i data-lucide="check"></i> Offline queueing: Messages deliver automatically when internet reconnects</li>
                    </ul>
                </div>
                <div class="mockup-panel" style="box-shadow: var(--shadow-lg); background: #ffffff; padding: 2rem; border-radius: 18px;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div class="metric-icon-box metric-icon-blue"><i data-lucide="cpu"></i></div>
                        <div>
                            <h4 style="font-weight: 700; margin: 0;">Marg Bridge Service v4.8</h4>
                            <p style="font-size: 0.78rem; color: #10b981; margin: 0;">● Online & Monitoring (24/7 Service)</p>
                        </div>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid var(--border-subtle); border-radius: 10px; padding: 1rem; font-family: monospace; font-size: 0.8rem; color: #334155; line-height: 1.6;">
                        [12:54:02] [SYNC] New Tax Invoice #MG-9481 detected.<br>
                        [12:54:03] [PDF] Generating GST Compliant PDF... Done.<br>
                        [12:54:03] [WABA] Dispatched to +91 98765 43210.<br>
                        [12:54:04] [STATUS] Message Delivered & Read (Double Blue Tick).
                    </div>
                </div>
            </div>

            <!-- Feature 2: Automated Payment Reminders -->
            <div id="payment-recovery" style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 3.5rem; align-items: center; margin-bottom: 4.5rem;">
                <div class="mockup-panel" style="box-shadow: var(--shadow-lg); background: #ffffff; padding: 2rem; border-radius: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div class="metric-icon-box metric-icon-green"><i data-lucide="credit-card"></i></div>
                            <div>
                                <h4 style="font-weight: 700; margin: 0;">Smart Payment Chaser</h4>
                                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Dynamic UPI Links & QR Generator</p>
                            </div>
                        </div>
                        <span class="status-pill status-sent">3.2x Faster Recovery</span>
                    </div>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 1.25rem; color: #065f46; font-size: 0.88rem; line-height: 1.5;">
                        <strong>🔔 Overdue Reminder Alert</strong><br>
                        Dear Rajesh Pharma, your ledger shows an overdue balance of <strong>₹24,500</strong> for bills older than 15 days.<br>
                        <div style="margin-top: 10px; padding: 10px; background: #ffffff; border: 1px dashed #10b981; border-radius: 8px; text-align: center;">
                            <span style="font-weight: 700; color: #059669;">Scan or Click to Pay via UPI</span><br>
                            <span style="font-size: 0.75rem; color: #64748b;">(GPay, PhonePe, Paytm, BHIM Supported)</span>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="section-tag" style="background: var(--whatsapp-light); color: var(--whatsapp);">Cash Flow Acceleration</div>
                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 2.1rem; font-weight: 800; margin-bottom: 1rem; color: var(--text-primary);">
                        Automated Outstanding & UPI Payment Reminders
                    </h2>
                    <p style="font-size: 0.98rem; color: var(--text-secondary); line-height: 1.7; margin-bottom: 1.5rem;">
                        Stop wasting hours calling clients for overdue payments. Friendly AI Solution automatically categorizes debtors by aging (7 days, 15 days, 30 days) and dispatches polite, scheduled reminders with customized instant UPI payment buttons.
                    </p>
                    <ul class="feature-check-list">
                        <li class="feature-check-item"><i data-lucide="check"></i> Reduces overdue payment collection cycles by up to 65%</li>
                        <li class="feature-check-item"><i data-lucide="check"></i> Dynamic UPI QR codes embedded directly with exact payable amount</li>
                        <li class="feature-check-item"><i data-lucide="check"></i> Automatic receipt confirmation message upon clearance</li>
                    </ul>
                </div>
            </div>

            <!-- OFFICIAL MARG SOFTWARE COMPARISON FEATURES CHART (Authentic from marg-features.html) -->
            <div class="fea-chart-wrapper" id="comparison-chart">
                <div style="padding: 1.75rem 2.25rem; border-bottom: 1px solid var(--border-subtle); background: #ffffff; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <span style="font-size: 0.8rem; font-weight: 700; color: #0f766e; text-transform: uppercase; letter-spacing: 0.05em;">Marg Compusoft Official Matrix</span>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.65rem; font-weight: 800; color: var(--text-primary); margin: 0.25rem 0 0 0;">
                            Software Comparison Features Chart
                        </h3>
                    </div>

                    <!-- Search Filter Input -->
                    <div class="feature-search-box">
                        <i data-lucide="search" class="search-icon-pos" style="width: 16px; height: 16px;"></i>
                        <input type="text" id="featureSearchInput" class="feature-search-input" placeholder="Search features (e.g. Godown, E-Invoicing, Barcode)..." onkeyup="filterFeatures()">
                    </div>
                </div>

                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table class="fea-chart-table" id="featuresTable">
                        <thead>
                            <tr>
                                <th style="width: 8%;">S. No.</th>
                                <th style="width: 47%;">Features & Capabilities</th>
                                <th style="width: 15%; background: #0284c7;">Basic Edition</th>
                                <th style="width: 15%; background: #0f766e;">Silver Edition</th>
                                <th style="width: 15%; background: #ca8a04;">Gold Edition</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Official Features List from marg-features.html -->
                            <tr>
                                <td class="sno">1</td>
                                <td class="feature-title">Inventory & Stock Management</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">2</td>
                                <td class="feature-title">Multi Store Management (Godown Management)</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">3</td>
                                <td class="feature-title">Sales / Purchase Invoice Entry</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">4</td>
                                <td class="feature-title">Sales Order / Purchase Order & Delivery Challan Management</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">5</td>
                                <td class="feature-title">Counter Sale Entry & Temporary Purchase Entry</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">6</td>
                                <td class="feature-title">Sale / Purchase Breakage/Expiry Return & Replacement Issue / Receive</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">7</td>
                                <td class="feature-title">Price Difference Sale / Purchase Management</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">8</td>
                                <td class="feature-title">Conversion Entry Management (Assembly / Disassembly)</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">9</td>
                                <td class="feature-title">Pharma Batch & Expiry Control with Salt / Substitute Search</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">10</td>
                                <td class="feature-title">Barcode Generation, Scanning & Thermal Label Printing</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">11</td>
                                <td class="feature-title">Scheme, Deal & Free Item Matrix (Pharma/FMCG)</td>
                                <td class="val-col"><span style="font-size: 0.82rem; font-weight: 700; color: #475569;">Standard</span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">12</td>
                                <td class="feature-title">Re-order Management & Minimum Stock Alerts</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">13</td>
                                <td class="feature-title">GST Invoicing (B2B, B2C, POS Retail) & E-Way Bill</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">14</td>
                                <td class="feature-title">E-Invoicing (Direct IRN & QR Code Generation)</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">15</td>
                                <td class="feature-title">GST Returns Export (GSTR-1, 2, 3B, 9 JSON/Excel)</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">16</td>
                                <td class="feature-title">Complete Financial Accounting (Ledger, P&L, Balance Sheet)</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">17</td>
                                <td class="feature-title">Auto Bank Reconciliation (BRS Integration with Banks)</td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">18</td>
                                <td class="feature-title">Multi-Currency International Accounting</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">19</td>
                                <td class="feature-title">Manufacturing, Production BOM & Assembly Module</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">20</td>
                                <td class="feature-title">Multi-User LAN Network Collaboration</td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 700;">1 User (Max 2)</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 700; color: #0f766e;">1 Full + 1 View (Multi)</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 800; color: #16a34a;">Unlimited Users</span></td>
                            </tr>
                            <tr>
                                <td class="sno">21</td>
                                <td class="feature-title">Multi-Company Licensing & Multi-Firm Setup</td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 700;">Max 2 Companies</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 700; color: #0f766e;">Add-on Companies</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 800; color: #16a34a;">Unlimited Companies</span></td>
                            </tr>
                            <tr>
                                <td class="sno">22</td>
                                <td class="feature-title">Operator-Wise Hotkeys & Menu Security Rights</td>
                                <td class="val-col"><span class="fea-check-no"><i data-lucide="x" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">23</td>
                                <td class="feature-title">Official Meta WhatsApp Automation (Zero Ban Risk)</td>
                                <td class="val-col"><span style="font-size: 0.8rem; color: #64748b;">Add-on</span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">24</td>
                                <td class="feature-title">eBusiness Mobile Apps (eOrder, eRetail, eOwner)</td>
                                <td class="val-col"><span style="font-size: 0.8rem; color: #64748b;">Add-on</span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                                <td class="val-col"><span class="fea-check-yes"><i data-lucide="check" style="width: 16px; height: 16px;"></i></span></td>
                            </tr>
                            <tr>
                                <td class="sno">25</td>
                                <td class="feature-title">Marg Cloud NVMe VPC Remote Server Hosting</td>
                                <td class="val-col"><span style="font-size: 0.8rem; color: #64748b;">Add-on</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; color: #64748b;">Add-on</span></td>
                                <td class="val-col"><span style="font-size: 0.8rem; font-weight: 800; color: #16a34a;">Cloud Ready</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </section>

    <!-- Old Way vs Friendly AI Solution Way -->
    <section class="section-spacing" style="background: #f1f5f9; border-top: 1px solid var(--border-subtle);">
        <div class="public-container">
            <div class="section-header">
                <div class="section-tag">Comparison</div>
                <h2 class="section-title">The Old Way vs <span>The Friendly AI Solution Way</span></h2>
                <p class="section-subtitle">See why thousands of Marg ERP businesses are upgrading to modern automation.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <!-- The Old Way -->
                <div style="background: #ffffff; border: 1px solid #fca5a5; border-radius: var(--radius-xl); padding: 2.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; color: #dc2626; font-weight: 800; font-size: 1.2rem;">
                        <i data-lucide="x-circle"></i> The Old Manual Way
                    </div>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 1rem; font-size: 0.92rem; color: var(--text-secondary); padding: 0;">
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="x" style="color: #ef4444; flex-shrink: 0;"></i> Printing physical bills on thermal/paper printers (high paper costs).</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="x" style="color: #ef4444; flex-shrink: 0;"></i> Calling customers repeatedly for overdue market payments.</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="x" style="color: #ef4444; flex-shrink: 0;"></i> High risk of WhatsApp number bans using unofficial QR scanners.</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="x" style="color: #ef4444; flex-shrink: 0;"></i> Data locked inside a single office desktop PC.</li>
                    </ul>
                </div>

                <!-- The Friendly AI Solution Way -->
                <div style="background: #ffffff; border: 2px solid var(--whatsapp); border-radius: var(--radius-xl); padding: 2.25rem; box-shadow: var(--shadow-glow-green);">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; color: var(--whatsapp); font-weight: 800; font-size: 1.2rem;">
                        <i data-lucide="check-circle-2"></i> The Friendly AI Solution Way
                    </div>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 1rem; font-size: 0.92rem; color: var(--text-primary); font-weight: 500; padding: 0;">
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="check" style="color: #10b981; flex-shrink: 0;"></i> 100% Automated WhatsApp GST Invoice PDF delivered in 2 seconds.</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="check" style="color: #10b981; flex-shrink: 0;"></i> Automated ledger summaries with 1-click dynamic UPI payment buttons.</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="check" style="color: #10b981; flex-shrink: 0;"></i> Official Meta WhatsApp Cloud API with 0% number blocking risk.</li>
                        <li style="display: flex; gap: 0.5rem;"><i data-lucide="check" style="color: #10b981; flex-shrink: 0;"></i> Access Marg ERP securely from any branch, home, or mobile device.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Shared Footer -->
    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>

    <script>
        function filterFeatures() {
            const input = document.getElementById('featureSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('featuresTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td')[1];
                if (td) {
                    const txtValue = td.textContent || td.innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        tr[i].style.display = '';
                    } else {
                        tr[i].style.display = 'none';
                    }
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>
