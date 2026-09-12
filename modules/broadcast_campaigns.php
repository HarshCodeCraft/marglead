<?php
/**
 * Marg ERP CRM - WhatsApp Broadcast Campaigns & Smart Template Hub
 * Interactive WhatsApp Template Builder, AI Copy Assistant, Real-time Live Phone Simulator,
 * Per-Customer Dynamic Personalization ({name}, {amount}, {due_date}), and Multi-Gateway Dispatcher (Meta Cloud API & WhatsApp Web API).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 1);

// Fetch active gateway configuration
$activeGateway = 'meta';
$hasMetaSetup = false;
$hasWebSetup = false;
$isGatewayConnected = false;
$activeSenderPhone = '';

try {
    $stmtWaba = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ? LIMIT 1");
    $stmtWaba->execute([$user_id]);
    $wabaCfg = $stmtWaba->fetch(PDO::FETCH_ASSOC);
    if (!$wabaCfg) {
        $stmtWabaF = $pdo->query("SELECT * FROM merchant_waba_settings ORDER BY id ASC LIMIT 1");
        $wabaCfg = $stmtWabaF ? $stmtWabaF->fetch(PDO::FETCH_ASSOC) : [];
    }

    $activeGateway = !empty($wabaCfg['gateway_type']) ? $wabaCfg['gateway_type'] : 'meta';
    $hasMetaSetup = !empty($wabaCfg['phone_number_id']) && !empty($wabaCfg['access_token']);
    $hasWebSetup = !empty($wabaCfg['web_api_session_status']) && $wabaCfg['web_api_session_status'] === 'connected';
    $isGatewayConnected = ($activeGateway === 'web_api') ? $hasWebSetup : $hasMetaSetup;
    $activeSenderPhone = !empty($wabaCfg['business_phone']) ? $wabaCfg['business_phone'] : '';
} catch (\Throwable $e) {}

// Active Sender Profile & Banking (Strictly isolated to active tenant / user database)
$senderFirm = $_SESSION['tenant_name'] ?? $_SESSION['company_name'] ?? (!empty($wabaCfg['business_name']) ? $wabaCfg['business_name'] : 'Marg ERP Merchant');
$senderHelpline = !empty($activeSenderPhone) ? $activeSenderPhone : '-';
$senderBank = null;
try {
    $stmtB = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
    $senderBank = $stmtB ? $stmtB->fetch(PDO::FETCH_ASSOC) : null;
} catch (\Throwable $e) {}

$senderUpi = !empty($senderBank['upi_id']) ? $senderBank['upi_id'] : '-';
$senderBankName = !empty($senderBank['bank_name']) ? $senderBank['bank_name'] : '-';
$senderAccNo = !empty($senderBank['account_number']) ? $senderBank['account_number'] : '-';
$senderBranch = !empty($senderBank['branch']) ? $senderBank['branch'] : '-';
$senderIfsc = !empty($senderBank['ifsc_code']) ? $senderBank['ifsc_code'] : '-';
?>

<style>
.campaigns-container {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding-bottom: 2rem;
}

/* Header & Tabs */
.campaign-header-card {
    background: var(--bg-card, #ffffff);
    padding: 1.25rem 1.5rem;
    border-radius: 12px;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.tab-nav-bar {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    padding-bottom: 2px;
    margin-bottom: 1rem;
    overflow-x: auto;
}

.nav-tab-btn {
    padding: 0.6rem 1.1rem;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 8px 8px 0 0;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.nav-tab-btn:hover {
    background: rgba(37, 99, 235, 0.05);
    color: var(--primary, #2563eb);
}

.nav-tab-btn.active {
    background: var(--bg-card, #ffffff);
    color: var(--primary, #2563eb);
    border-color: var(--border-color, #e2e8f0);
    border-bottom-color: var(--bg-card, #ffffff);
    box-shadow: 0 -2px 6px rgba(0,0,0,0.03);
}

/* Stat Cards */
.campaign-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1.1rem;
    box-shadow: 0 4px 14px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-val {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
}

/* Template Cards Grid */
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.1rem;
}

.template-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 14px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.85rem;
    position: relative;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.template-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}

.template-header-tag {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    display: inline-block;
    margin-bottom: 4px;
}

.template-body-preview {
    font-size: 0.82rem;
    line-height: 1.45;
    color: var(--text-main, #1e293b);
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    padding: 0.75rem;
    border-radius: 8px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 140px;
    overflow-y: auto;
}

.template-buttons-preview {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
}

.template-btn-pill {
    background: #ffffff;
    border: 1px solid #3b82f6;
    color: #2563eb;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 8px;
    text-align: center;
}

/* Modals & Live WhatsApp Phone Mockup */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(5px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 1rem;
    opacity: 0;
    pointer-events: none;
}

.modal-overlay.active,
.modal-overlay.open {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}

.modal-box-lg {
    background: var(--bg-card, #ffffff);
    border-radius: 20px;
    width: 100%;
    max-width: 1060px;
    padding: 1.75rem;
    box-shadow: 0 25px 60px -15px rgba(0,0,0,0.25), 0 0 0 1px var(--border-color, #e2e8f0);
    display: grid;
    grid-template-columns: 1.15fr 360px;
    gap: 1.75rem;
    max-height: 94vh;
    overflow-y: auto;
}

@media (max-width: 900px) {
    .modal-box-lg {
        grid-template-columns: 1fr;
    }
}

/* Custom styled inputs for form */
.input-styled {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1.5px solid var(--border-color, #cbd5e1);
    border-radius: 10px;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    font-size: 0.82rem;
    outline: none;
    box-sizing: border-box;
    transition: all 0.2s ease;
    line-height: 1.45;
    font-family: inherit;
}

.input-styled:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.input-styled::placeholder {
    color: #94a3b8;
}

/* AI Assistant Card */
.ai-assistant-card {
    background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border: 1.5px solid #bfdbfe;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.05);
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}

/* AI Preset Chips */
.ai-preset-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.ai-preset-chip:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
}

/* Variable Pills */
.var-pill-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #1e293b;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.var-pill-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #1d4ed8;
    transform: translateY(-1px);
}

/* Authentic WhatsApp Phone Simulator Mockup */
.wa-phone-mockup {
    background: #efeae2;
    border: 10px solid #1e293b;
    border-radius: 38px;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 520px;
    position: relative;
}

.wa-phone-notch {
    background: #1e293b;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0 10px;
}

.notch-speaker {
    width: 44px;
    height: 3.5px;
    background: #334155;
    border-radius: 4px;
}

.notch-cam {
    width: 6px;
    height: 6px;
    background: #0f172a;
    border-radius: 50%;
    border: 1px solid #334155;
}

.wa-phone-header {
    background: #008069;
    padding: 9px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wa-phone-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #059669;
    border: 1.5px solid rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    color: white;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.wa-phone-body {
    background: #efeae2 url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-835d-d93a777afe46.png');
    background-size: 320px auto;
    background-repeat: repeat;
    flex: 1;
    padding: 12px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}

.wa-date-divider {
    align-self: center;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 0.65rem;
    font-weight: 700;
    color: #54656f;
    margin-bottom: 10px;
    box-shadow: 0 1px 1px rgba(11,20,26,0.1);
    text-transform: uppercase;
}

.wa-msg-bubble {
    background: #ffffff;
    color: #111b21;
    border-radius: 8px 8px 8px 2px;
    padding: 10px 12px 6px 12px;
    font-size: 0.81rem;
    line-height: 1.48;
    box-shadow: 0 1px 2px rgba(11,20,26,0.15);
    position: relative;
    max-width: 95%;
    align-self: flex-start;
}

.wa-msg-header {
    font-weight: 700;
    color: #008069;
    margin-bottom: 6px;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.wa-msg-footer {
    font-size: 0.7rem;
    color: #667781;
    margin-top: 6px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 4px;
}

.wa-msg-time {
    font-size: 0.65rem;
    color: #667781;
    text-align: right;
    margin-top: 4px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
}

.wa-btn-container {
    display: flex;
    flex-direction: column;
    gap: 1px;
    margin-top: 8px;
    margin-left: -12px;
    margin-right: -12px;
    margin-bottom: -6px;
    border-top: 1px solid #e9edef;
    background: #ffffff;
    border-radius: 0 0 8px 2px;
    overflow: hidden;
}

.wa-interactive-btn {
    background: #ffffff;
    border: none;
    border-bottom: 1px solid #e9edef;
    color: #00a884;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 8px 10px;
    text-align: center;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.15s ease;
}

.wa-interactive-btn:last-child {
    border-bottom: none;
}

.wa-interactive-btn:hover {
    background: #f7f8fa;
}

/* Enhanced Professional Campaign Card & Toolbar Styles */
.campaign-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 0.85rem 1.15rem;
    margin-bottom: 1.15rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}

.campaign-filter-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.camp-filter-pill {
    padding: 6px 13px;
    border-radius: 20px;
    font-size: 0.76rem;
    font-weight: 600;
    border: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-app, #f8fafc);
    color: var(--text-muted, #64748b);
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.camp-filter-pill:hover {
    border-color: #2563eb;
    color: #2563eb;
    background: rgba(37,99,235,0.05);
}

.camp-filter-pill.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 8px rgba(37,99,235,0.25);
}

.camp-filter-pill .pill-badge {
    background: rgba(0,0,0,0.07);
    color: inherit;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 0.68rem;
    font-weight: 700;
}

.camp-filter-pill.active .pill-badge {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}

.campaign-search-box {
    position: relative;
    min-width: 240px;
    flex: 1;
    max-width: 360px;
}

.campaign-search-box input {
    padding-left: 32px;
    height: 36px;
    font-size: 0.8rem;
    border-radius: 8px;
    width: 100%;
}

.campaign-search-box i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 14px;
    height: 14px;
    color: #94a3b8;
    pointer-events: none;
}

.campaign-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 14px;
    padding: 1.25rem 1.4rem;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}

.campaign-card:hover {
    border-color: #cbd5e1;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}

.campaign-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.85rem;
}

.campaign-title-block {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    flex: 1;
    min-width: 260px;
}

.campaign-title {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 700;
    color: var(--text-main, #0f172a);
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.35;
}

.campaign-meta-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 0.74rem;
    color: var(--text-muted, #64748b);
}

.meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 6px;
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    color: #475569;
    font-weight: 600;
    font-size: 0.72rem;
}

.campaign-actions-bar {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.campaign-progress-box {
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    padding: 0.75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.progress-track-sleek {
    background: #e2e8f0;
    border-radius: 8px;
    height: 7px;
    overflow: hidden;
}

.progress-bar-fill-sleek {
    height: 100%;
    border-radius: 8px;
    transition: width 0.4s ease;
}

.campaign-stat-pills-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 2px;
}

.campaign-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 9px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
}

.stat-pill-sent {
    background: rgba(16, 185, 129, 0.1);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
}

.stat-pill-pending {
    background: rgba(245, 158, 11, 0.1);
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.25);
}

.stat-pill-failed {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.25);
}

.stat-pill-total {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.pulse-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulseGlow 1.8s infinite cubic-bezier(0.66, 0, 0, 1);
}

@keyframes pulseGlow {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<div class="campaigns-container">

    <!-- Top Active Gateway Status Strip -->
    <div id="gatewayStatusBanner" style="border-radius: 12px; padding: 14px 18px; margin-bottom: 0.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; <?php echo $isGatewayConnected ? 'background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3);'; ?>">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; <?php echo $isGatewayConnected ? 'background: #10b981; color: white;' : 'background: #f59e0b; color: white;'; ?>">
                <i data-lucide="<?php echo $isGatewayConnected ? 'check-circle-2' : 'alert-triangle'; ?>" style="width: 20px; height: 20px;"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <strong style="font-size: 0.92rem; color: var(--text-main);">
                        <?php if ($isGatewayConnected): ?>
                            Active Gateway: <?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web API (Paired Phone)' : 'Meta WhatsApp Cloud API (Official WABA)'; ?>
                        <?php else: ?>
                            WhatsApp Gateway Setup Required
                        <?php endif; ?>
                    </strong>
                    <span class="badge" style="background: <?php echo $isGatewayConnected ? '#10b981' : '#f59e0b'; ?>; color: white; font-size: 0.7rem; font-weight: 700;">
                        <?php echo $isGatewayConnected ? 'Connected' : 'Not Connected'; ?>
                    </span>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">
                    <?php if ($isGatewayConnected): ?>
                        Sender: <strong style="color: var(--text-main); font-family: monospace;"><?php echo htmlspecialchars($activeSenderPhone ?: 'Ready'); ?></strong> &bull;
                        <?php echo ($activeGateway === 'web_api') ? 'Instant local template save enabled (No Meta approval needed)' : 'Official Meta review &amp; template sync enabled'; ?>
                    <?php else: ?>
                        Connect Meta Cloud API or WhatsApp Web API in settings before sending broadcasts.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div>
            <a href="index.php?page=merchant_waba_settings" class="btn btn-secondary font-bold text-xs" style="padding: 6px 14px; border-radius: 8px;">
                <i data-lucide="sliders" style="width: 14px; height: 14px; margin-right: 4px;"></i> Gateway Settings
            </a>
        </div>
    </div>

    <!-- Top Header -->
    <div class="campaign-header-card">
        <div>
            <h1 style="font-size: 1.35rem; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                WhatsApp Broadcast Campaigns &amp; Smart Template Hub
            </h1>
            <p class="text-xs text-muted mb-0 mt-1">
                Compose rich WhatsApp templates with live phone simulator, AI copy assistant, dynamic customer variables ({name}, {amount}, {due_date}), and direct/bulk broadcasting.
            </p>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary text-xs font-bold" onclick="switchMainTab('individual')">
                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                Individual Quick Send
            </button>
            <button type="button" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white;" onclick="switchMainTab('bulk')">
                <i data-lucide="rocket" style="width: 14px; height: 14px;"></i>
                Launch Bulk Campaign
            </button>
            <button type="button" class="btn btn-secondary text-xs font-bold" onclick="openCreateTemplateModal()">
                <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                + Interactive Template Builder
            </button>
        </div>
    </div>

    <!-- Metrics Row -->
    <div class="campaign-stats-grid">
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">TOTAL BROADCAST MESSAGES</span>
            <span class="stat-val" id="statTotalSent">0</span>
            <span class="text-xs text-success">● Dispatched via Active Gateway</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">ACTIVE RUNNING CAMPAIGNS</span>
            <span class="stat-val" id="statActiveCount" style="color: #10b981;">0</span>
            <span class="text-xs text-muted">Real-time batch processor</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">SAVED TEMPLATES</span>
            <span class="stat-val" id="statTemplateCount" style="color: #2563eb;">0</span>
            <span class="text-xs text-muted">Ready for instant dispatch</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">ACTIVE INTEGRATION</span>
            <span class="stat-val" style="color: #059669; font-size: 1.15rem; font-weight: 700; margin-top: 4px;">
                <?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web' : 'Meta Cloud API'; ?>
            </span>
            <span class="text-xs text-muted"><?php echo $isGatewayConnected ? '<span style="display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-circle" style="width:12px;height:12px;color:#10b981;"></i> Active &amp; Ready</span>' : '<span style="display:inline-flex; align-items:center; gap:4px;"><i data-lucide="alert-circle" style="width:12px;height:12px;color:#f59e0b;"></i> Setup Required</span>'; ?></span>
        </div>
    </div>

    <!-- Navigation Tabs Bar -->
    <div class="tab-nav-bar">
        <button type="button" class="nav-tab-btn active" id="tabHead-templates" onclick="switchMainTab('templates')">
            <i data-lucide="file-text" style="width: 15px; height: 15px;"></i>
            Interactive Template Gallery
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-campaigns" onclick="switchMainTab('campaigns')">
            <i data-lucide="layers" style="width: 15px; height: 15px;"></i>
            Active &amp; Past Campaigns
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-individual" onclick="switchMainTab('individual')">
            <i data-lucide="user" style="width: 15px; height: 15px;"></i>
            Individual Quick Broadcast
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-bulk" onclick="switchMainTab('bulk')">
            <i data-lucide="users" style="width: 15px; height: 15px;"></i>
            Launch Bulk Campaign
        </button>
    </div>

    <!-- TAB 1: TEMPLATE GALLERY -->
    <div id="tabContent-templates" class="tab-content-panel">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Interactive WhatsApp Template Gallery</h3>
                <p class="text-xs text-muted mb-0">High-converting WhatsApp templates with dynamic personalization variables and interactive buttons.</p>
            </div>
            <div class="flex align-center gap-2">
                <?php if ($activeGateway === 'meta'): ?>
                    <button type="button" id="btnSyncMeta" class="btn btn-secondary text-xs font-bold flex align-center gap-1" onclick="syncMetaTemplates()" title="Fetch official approved templates directly from Meta WhatsApp Manager">
                        <i data-lucide="refresh-cw" style="width: 13px; height: 13px;"></i>
                        <span>Sync Meta Approved Templates</span>
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary text-xs font-bold" onclick="openCreateTemplateModal()">
                    <i data-lucide="plus-circle" style="width:14px;height:14px;"></i> Create Custom Template
                </button>
            </div>
        </div>

        <div class="templates-grid" id="templatesGridContainer">
            <div style="text-align: center; padding: 3rem; color: #888; grid-column: 1 / -1;">
                <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
                <div>Loading saved templates...</div>
            </div>
        </div>
    </div>

    <!-- TAB 2: CAMPAIGNS LIST -->
    <div id="tabContent-campaigns" class="tab-content-panel" style="display: none;">
        <!-- Header & Action Strip for Campaigns -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="layers" style="width: 20px; height: 20px; color: var(--primary);"></i>
                    Active &amp; Past Broadcast Campaigns
                </h3>
                <p class="text-xs text-muted mb-0 mt-1">Real-time dispatch status, queue progress monitoring, and campaign history control.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" class="btn btn-secondary text-xs font-semibold" onclick="fetchCampaigns()" title="Refresh campaign data">
                    <i data-lucide="rotate-cw" style="width: 13px; height: 13px;"></i> Refresh
                </button>
                <button type="button" class="btn btn-secondary text-xs font-semibold" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.04);" onclick="clearAllCampaigns()" title="Clear all campaigns and logs">
                    <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i> Clear History
                </button>
                <button type="button" class="btn btn-primary text-xs font-bold" onclick="switchMainTab('bulk')">
                    <i data-lucide="plus-circle" style="width: 13px; height: 13px;"></i> + New Campaign
                </button>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="campaign-toolbar">
            <div class="campaign-filter-tabs" id="campaignFilterGroup">
                <button type="button" class="camp-filter-pill active" data-filter="all" onclick="setCampaignFilter('all', this)">
                    All Campaigns <span class="pill-badge" id="pillCount-all">0</span>
                </button>
                <button type="button" class="camp-filter-pill" data-filter="running" onclick="setCampaignFilter('running', this)">
                    <span class="pulse-dot"></span> Running <span class="pill-badge" id="pillCount-running">0</span>
                </button>
                <button type="button" class="camp-filter-pill" data-filter="completed" onclick="setCampaignFilter('completed', this)">
                    <i data-lucide="check-check" style="width: 12px; height: 12px; color: #2563eb;"></i> Completed <span class="pill-badge" id="pillCount-completed">0</span>
                </button>
                <button type="button" class="camp-filter-pill" data-filter="pending_approval" onclick="setCampaignFilter('pending_approval', this)">
                    <i data-lucide="clock" style="width: 12px; height: 12px; color: #d97706;"></i> Pending Review <span class="pill-badge" id="pillCount-pending_approval">0</span>
                </button>
                <button type="button" class="camp-filter-pill" data-filter="stopped" onclick="setCampaignFilter('stopped', this)">
                    <i data-lucide="square" style="width: 12px; height: 12px; color: #ef4444;"></i> Stopped / Rejected <span class="pill-badge" id="pillCount-stopped">0</span>
                </button>
            </div>

            <div class="campaign-search-box">
                <i data-lucide="search"></i>
                <input type="text" id="campaignSearchInput" class="input-styled" placeholder="Search by name, phone or template..." oninput="handleCampaignSearch(this.value)">
            </div>
        </div>

        <!-- Campaign Cards Container -->
        <div style="display: flex; flex-direction: column; gap: 1rem;" id="campaignsListContainer">
            <div style="text-align: center; padding: 3rem; color: #888;">
                <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
                <div>Loading campaigns...</div>
            </div>
        </div>
    </div>

    <!-- TAB 3: INDIVIDUAL QUICK BROADCAST -->
    <div id="tabContent-individual" class="tab-content-panel" style="display: none;">
        <div style="background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); max-width: 800px; margin: 0 auto; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
                    Direct Individual WhatsApp Broadcast
                </h3>
                <p class="text-xs text-muted mb-0 mt-1">Send a 1-on-1 instant WhatsApp broadcast to any customer with dynamic variable substitution ({name}, {amount}, {due_date}).</p>
            </div>

            <form id="individualSendForm" onsubmit="handleIndividualSubmit(event)" style="display: flex; flex-direction: column; gap: 1rem;">
                <!-- Fast Contact Search / Picker -->
                <div style="background: rgba(37, 99, 235, 0.04); border: 1px solid rgba(37, 99, 235, 0.2); border-radius: 8px; padding: 10px 14px;">
                    <label class="form-label font-bold text-xs" style="color: var(--primary);">Search &amp; Auto-fill from Existing Client / Lead</label>
                    <select id="indContactPicker" class="input-styled text-xs" onchange="handleSelectContactIndividual(this.value)">
                        <option value="">-- Choose an existing contact or enter below --</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Recipient Phone Number *</label>
                        <input type="text" id="indPhoneInput" name="phone" class="input-styled font-bold text-xs" required placeholder="e.g. 9532620736 or +919532620736">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Recipient Customer Name *</label>
                        <input type="text" id="indNameInput" name="name" class="input-styled text-xs" required placeholder="e.g. Rajesh Medical Store" oninput="updateIndividualPreview()">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Firm / Company Name</label>
                        <input type="text" id="indCompInput" name="company" class="input-styled text-xs" placeholder="e.g. Marg Pharma Pvt Ltd" oninput="updateIndividualPreview()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Pending Amount ({amount})</label>
                        <input type="text" id="indAmountInput" name="amount" class="input-styled font-bold text-xs" value="₹3,500" placeholder="e.g. ₹3,500" oninput="updateIndividualPreview()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Due Date ({due_date})</label>
                        <input type="text" id="indDueDateInput" name="due_date" class="input-styled text-xs" value="25 Aug 2026" placeholder="e.g. 25 Aug 2026" oninput="updateIndividualPreview()">
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Select Saved Template OR Type Custom</label>
                    <select id="indTemplateSelect" name="template_slug" class="input-styled text-xs" onchange="applyTemplateToIndividual(this.value)">
                        <option value="custom">Custom Message (Type below)</option>
                    </select>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Message Text *</label>
                    <textarea id="indMessageText" name="message" class="input-styled text-xs" rows="5" required placeholder="Type broadcast message text... Use variables: {name}, {company}, {amount}, {due_date}"></textarea>
                </div>

                <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.85rem; border-radius: 8px;">
                    <div style="font-size: 0.72rem; font-weight: 700; color: #059669; text-transform: uppercase; margin-bottom: 4px;">Live Preview</div>
                    <div id="indLivePreview" style="font-size: 0.8rem; line-height: 1.4; color: #111827; white-space: pre-wrap; font-family: inherit;">Preview message will appear here...</div>
                    <div id="indLiveButtonsPreview" style="display: flex; gap: 0.5rem; margin-top: 8px; flex-wrap: wrap;"></div>
                </div>

                <button type="submit" id="btnSubmitIndividual" class="btn btn-primary text-xs font-bold" style="padding: 0.75rem 1.25rem;">
                    <i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct Instant Broadcast Now
                </button>
            </form>
        </div>
    </div>

    <!-- TAB 4: BULK CAMPAIGN CREATOR -->
    <div id="tabContent-bulk" class="tab-content-panel" style="display: none;">
        <div style="background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); max-width: 820px; margin: 0 auto; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
                    Launch New Bulk Campaign
                </h3>
                <p class="text-xs text-muted mb-0 mt-1">Broadcast mass AMC reminders, billing alerts, or promos to client segments or custom selected numbers.</p>
            </div>

            <form id="bulkCampaignForm" onsubmit="handleBulkCampaignSubmit(event)" style="display: flex; flex-direction: column; gap: 1rem;">
                <div>
                    <label class="form-label font-bold text-xs">Campaign Title / Name *</label>
                    <input type="text" name="name" class="input-styled font-bold text-xs" required placeholder="e.g. AMC Renewal Reminder - August Batch">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Target Audience Source *</label>
                        <select name="target_type" id="bulkTargetSelect" class="input-styled text-xs" onchange="toggleTargetAudienceType(this.value)">
                            <option value="clients">All Existing Clients (client_directory &amp; customers)</option>
                            <option value="leads">CRM Sales Leads (leads)</option>
                            <option value="specific">Pick Specific Contacts / Numbers</option>
                            <option value="csv">Upload Custom CSV / Excel List</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">Select Template *</label>
                        <select name="template_name" id="bulkTemplateSelect" class="input-styled text-xs" onchange="toggleCustomMessageText(this.value)">
                            <option value="">-- Choose Approved Template from Database --</option>
                            <option value="custom">Custom Text Message</option>
                        </select>
                    </div>
                </div>

                <!-- Specific Contacts Picker Section -->
                <div id="specificContactsWrapper" style="display: none; background: var(--bg-app); border: 1px solid var(--border-color); padding: 1rem; border-radius: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                        <span class="font-bold text-xs" style="color: var(--primary);">
                            Select Target Contacts (<span id="selectedContactsCount">0</span> selected)
                        </span>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 8px;" onclick="toggleAllSpecificContacts(true)">Select All</button>
                            <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 8px;" onclick="toggleAllSpecificContacts(false)">Deselect All</button>
                        </div>
                    </div>
                    <input type="text" id="contactSearchFilter" placeholder="Filter by name, phone or company..." class="input-styled text-xs mb-2" oninput="filterSpecificContactsList(this.value)">
                    <div id="specificContactsList" style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 4px;">
                        <!-- Filled by JS -->
                    </div>
                </div>

                <!-- CSV File Picker -->
                <div id="csvUploadWrapper" style="display: none; background: rgba(59,130,246,0.05); border: 1px dashed #3b82f6; padding: 1rem; border-radius: 8px;">
                    <label class="form-label font-bold text-xs text-primary">Upload CSV File (Columns: Mobile, Name, Company, Amount, DueDate)</label>
                    <input type="file" name="csv_file" accept=".csv" class="input-styled text-xs" style="background: white;">
                </div>

                <!-- Custom Text Message Box -->
                <div id="customMsgWrapper" style="display: none;">
                    <label class="form-label font-bold text-xs">Custom Broadcast Message</label>
                    <textarea name="custom_message" class="input-styled text-xs" rows="4" placeholder="Enter custom message text... Variables supported: {name}, {company}, {amount}, {due_date}"></textarea>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-app); padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div>
                        <label class="form-label font-bold text-xs mb-0">Sending Delay Speed</label>
                        <div style="font-size: 0.7rem; color: #6b7280;">Pause interval between WhatsApp dispatches</div>
                    </div>
                    <select name="delay_seconds" class="input-styled text-xs" style="width: 140px;">
                        <option value="1">1 Second</option>
                        <option value="2" selected>2 Seconds (Recommended)</option>
                        <option value="3">3 Seconds</option>
                        <option value="5">5 Seconds</option>
                    </select>
                </div>

                <button type="submit" id="btnSubmitBulk" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white; padding: 0.75rem 1.25rem;">
                    <i data-lucide="play" style="width:14px;height:14px;"></i> Create &amp; Initialize Campaign
                </button>
            </form>
        </div>
    </div>

</div>

<!-- INTERACTIVE TEMPLATE BUILDER & LIVE PHONE SIMULATOR MODAL -->
<div class="modal-overlay" id="interactiveTemplateModal">
    <div class="modal-box-lg">
        
        <!-- Left Side: Template Composer Form & AI Assistant -->
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(37, 99, 235, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="layout-template" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Interactive WhatsApp Template Builder</h3>
                        <span style="font-size: 0.72rem; color: var(--text-muted);">
                            Gateway Mode: <strong><?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web (Instant Ready)' : 'Meta Cloud API (Official Approval)'; ?></strong>
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-icon" onclick="closeCreateTemplateModal()" style="font-size: 1.4rem; background: none; border: none; cursor: pointer; color: var(--text-muted);">&times;</button>
            </div>

            <!-- AI Template Generator Box -->
            <div class="ai-assistant-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 7px; font-weight: 700; font-size: 0.82rem; color: #1d4ed8;">
                        <i data-lucide="sparkles" style="width: 15px; height: 15px; color: #2563eb;"></i>
                        <span>AI Smart Copywriter &amp; Domain Assistant</span>
                    </div>
                    <select id="aiToneSelect" class="input-styled text-xs" style="padding: 4px 8px; width: 120px; font-weight: 600;">
                        <option value="professional" selected>Professional</option>
                        <option value="urgent">Urgent Due</option>
                        <option value="friendly">Friendly</option>
                        <option value="promotional">Promotional</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="aiPromptInput" placeholder="Type prompt (e.g. 'for jewellery marketing', 'pharma stock discount', 'AMC renewal due')..." class="input-styled text-xs" style="flex: 1;">
                    <button type="button" id="btnAiGenerate" onclick="triggerAiTemplateGeneration()" class="btn btn-primary text-xs font-bold" style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; padding: 0.65rem 1.15rem;">
                        <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                        <span>Generate with AI</span>
                    </button>
                </div>

                <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 0.7rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;">Presets:</span>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('for jwellery marketing')">
                        <i data-lucide="gem" style="width: 12px; height: 12px; color: #d97706;"></i>
                        <span>Jewellery Marketing</span>
                    </button>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('Marg ERP Software AMC renewal reminder notice before due date')">
                        <i data-lucide="shield-check" style="width: 12px; height: 12px; color: #2563eb;"></i>
                        <span>AMC Renewal</span>
                    </button>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('Outstanding bill payment reminder with account details')">
                        <i data-lucide="receipt" style="width: 12px; height: 12px; color: #059669;"></i>
                        <span>Bill Due Alert</span>
                    </button>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('Special business upgrade discount offer for festive season')">
                        <i data-lucide="percent" style="width: 12px; height: 12px; color: #7c3aed;"></i>
                        <span>Festival Promo</span>
                    </button>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('Mandatory GST compliance and e-invoicing software update')">
                        <i data-lucide="file-check" style="width: 12px; height: 12px; color: #0284c7;"></i>
                        <span>GST Update</span>
                    </button>
                    <button type="button" class="ai-preset-chip" onclick="fillAiPreset('Welcome greetings for newly onboarded client')">
                        <i data-lucide="user-plus" style="width: 12px; height: 12px; color: #16a34a;"></i>
                        <span>Welcome Client</span>
                    </button>
                </div>
            </div>

            <!-- Composer Form -->
            <form id="createTemplateForm" onsubmit="handleTemplateSaveSubmit(event)" style="display: flex; flex-direction: column; gap: 0.85rem;">
                <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 0.85rem;">
                    <div>
                        <label class="form-label font-bold text-xs" style="margin-bottom: 0.35rem; color: #334155;">Template Title / Name *</label>
                        <input type="text" name="title" id="builderTitle" class="input-styled font-bold text-xs" required placeholder="e.g. AMC Renewal Notice" oninput="updateLivePhoneMockup()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs" style="margin-bottom: 0.35rem; color: #334155;">Category</label>
                        <select name="category" id="builderCategory" class="input-styled text-xs font-bold" onchange="updateLivePhoneMockup()">
                            <option value="MARKETING">MARKETING</option>
                            <option value="UTILITY" selected>UTILITY</option>
                            <option value="AUTHENTICATION">AUTHENTICATION</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs" style="margin-bottom: 0.35rem; color: #334155;">Header Title Text (Optional)</label>
                    <input type="text" name="header_text" id="builderHeaderText" class="input-styled text-xs" placeholder="e.g. Marg ERP Official Notice" oninput="updateLivePhoneMockup()">
                </div>

                <div>
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                        <label class="form-label font-bold text-xs" style="margin-bottom: 0; color: #334155;">Body Text *</label>
                        <span style="font-size: 0.7rem; color: #64748b;">WhatsApp formatting supported (*bold*, _italic_)</span>
                    </div>
                    <textarea name="body_text" id="builderBodyText" class="input-styled text-xs" rows="5" required placeholder="Type template text... Click variables below to insert dynamic values." oninput="updateLivePhoneMockup()" style="resize: vertical;"></textarea>
                    
                    <!-- 1-Click Variable Pills -->
                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                        <span class="font-bold text-slate-700" style="margin-right: 2px;">Insert Variable:</span>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{name}')">+ {name}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{company}')">+ {company}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{phone}')">+ {phone}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{amount}')">+ {amount}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{due_date}')">+ {due_date}</button>
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs" style="margin-bottom: 0.35rem; color: #334155;">Footer Text (Optional)</label>
                    <input type="text" name="footer_text" id="builderFooterText" class="input-styled text-xs" placeholder="e.g. Marg Soft Solution Support Desk" oninput="updateLivePhoneMockup()">
                </div>

                <!-- Interactive Reply Buttons Builder -->
                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; padding: 0.85rem; border-radius: 12px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.78rem; color: #1e293b;">
                            <i data-lucide="mouse-pointer-click" style="width: 14px; height: 14px; color: var(--primary);"></i>
                            <span>Interactive Quick Reply Buttons (Max 3)</span>
                        </div>
                        <button type="button" class="btn btn-secondary text-xs" style="padding: 3px 8px; display: inline-flex; align-items: center; gap: 4px;" onclick="addInteractiveButtonInput()">
                            <i data-lucide="plus" style="width: 12px; height: 12px;"></i> Add Button
                        </button>
                    </div>
                    <div id="builderButtonsList" style="display: flex; flex-direction: column; gap: 6px;">
                        <div class="btn-builder-row" style="display: flex; gap: 6px; align-items: center;">
                            <input type="text" class="input-styled text-xs builder-btn-input" value="Pay AMC Online" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                            <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.1rem; padding: 4px;">&times;</button>
                        </div>
                        <div class="btn-builder-row" style="display: flex; gap: 6px; align-items: center;">
                            <input type="text" class="input-styled text-xs builder-btn-input" value="Request Callback" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                            <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.1rem; padding: 4px;">&times;</button>
                        </div>
                    </div>
                </div>

                <!-- Dynamic Gateway Submission Notice -->
                <div id="builderGatewayNotice" style="background: rgba(16, 185, 129, 0.08); border: 1.5px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 10px 14px; font-size: 0.75rem; color: #065f46;">
                    <?php if ($activeGateway === 'web_api'): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="send" style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0;"></i>
                            <span><strong>WhatsApp Web API Active:</strong> Template is saved locally for instant reuse. No Meta review required.</span>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="shield-check" style="width: 16px; height: 16px; color: #059669; flex-shrink: 0;"></i>
                            <span><strong>Meta Cloud API Active:</strong> Template will be submitted to Meta Graph API for official approval.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.65rem; margin-top: 0.35rem;">
                    <button type="button" class="btn btn-secondary text-xs font-bold" onclick="closeCreateTemplateModal()" style="display: inline-flex; align-items: center; gap: 5px;">
                        <i data-lucide="x" style="width: 13px; height: 13px;"></i> Cancel
                    </button>
                    <button type="submit" id="btnSubmitTemplateModal" class="btn btn-primary text-xs font-bold" style="display: inline-flex; align-items: center; gap: 6px; padding: 0.65rem 1.25rem;">
                        <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                        <span><?php echo ($activeGateway === 'web_api') ? 'Save Template to Library' : 'Submit to Meta for Approval & Save'; ?></span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right Side: Real-time Live WhatsApp Phone Simulator -->
        <div style="display: flex; flex-direction: column;">
            <div style="font-size: 0.74rem; font-weight: 700; color: #64748b; margin-bottom: 8px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;">
                <i data-lucide="smartphone" style="width: 15px; height: 15px; color: #008069;"></i>
                <span>REAL-TIME WHATSAPP PREVIEW</span>
            </div>
            
            <div class="wa-phone-mockup">
                <!-- Phone Top Speaker & Notch -->
                <div class="wa-phone-notch">
                    <span class="notch-speaker"></span>
                    <span class="notch-cam"></span>
                </div>

                <!-- WhatsApp Top App Bar -->
                <div class="wa-phone-header">
                    <div style="display: flex; align-items: center; gap: 4px; cursor: pointer;">
                        <i data-lucide="chevron-left" style="width: 16px; height: 16px; color: white;"></i>
                        <div class="wa-phone-avatar">M</div>
                    </div>
                    <div style="flex: 1; min-width: 0; margin-left: 2px;">
                        <div style="font-size: 0.8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px;">
                            <span>Marg Soft Solution</span>
                            <i data-lucide="badge-check" style="width: 13px; height: 13px; color: #6ee7b7; flex-shrink: 0;"></i>
                        </div>
                        <div style="font-size: 0.65rem; color: #a7f3d0;">Official Business Account</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; color: white;">
                        <i data-lucide="video" style="width: 15px; height: 15px;"></i>
                        <i data-lucide="phone" style="width: 15px; height: 15px;"></i>
                    </div>
                </div>

                <!-- WhatsApp Chat Background Canvas -->
                <div class="wa-phone-body">
                    <div class="wa-date-divider">Today</div>

                    <!-- Message Bubble -->
                    <div class="wa-msg-bubble">
                        <div class="wa-msg-header" id="mockupHeader">Marg ERP Software AMC Notice</div>
                        <div id="mockupBody">Dear Rajesh Medical Store,<br><br>Your Marg ERP Software AMC renewal of <b>₹3,500</b> is due on <b>25 Aug 2026</b>.<br><br>To ensure uninterrupted billing &amp; GST filings, kindly renew your AMC.<br><br>Call: <b>7523830026</b></div>
                        <div class="wa-msg-footer" id="mockupFooter">Marg Soft Solution Support Desk</div>
                        <div class="wa-msg-time">
                            <span><?php echo date('h:i A'); ?></span>
                            <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb; margin-left: 2px;"></i>
                        </div>
                        
                        <!-- Interactive Buttons Container -->
                        <div class="wa-btn-container" id="mockupButtons">
                            <button type="button" class="wa-interactive-btn">
                                <i data-lucide="corner-down-left" style="width: 12px; height: 12px;"></i>
                                <span>Pay AMC Online</span>
                            </button>
                            <button type="button" class="wa-interactive-btn">
                                <i data-lucide="phone" style="width: 12px; height: 12px;"></i>
                                <span>Request Callback</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- AUDIENCE DETAILS MODAL -->
<div class="modal-overlay" id="audienceModal">
    <div class="modal-box" style="background: var(--bg-card); max-width: 750px; width: 100%; border-radius: 14px; padding: 1.25rem; border: 1px solid var(--border-color); max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 0.75rem;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);" id="audModalTitle">Campaign Contacts List</h3>
            <button type="button" class="btn-icon" onclick="closeAudienceModal()" style="font-size: 1.2rem; background: none; border: none; cursor: pointer;">&times;</button>
        </div>

        <div style="max-height: 400px; overflow-y: auto;">
            <table class="table" style="font-size: 0.8rem; width: 100%;">
                <thead>
                    <tr>
                        <th>Mobile</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Sent Time</th>
                    </tr>
                </thead>
                <tbody id="audTableBody">
                    <!-- Populated by JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const currentUserRole = "<?php echo $_SESSION['user_role'] ?? 'Executive'; ?>";
const isAdminUser = ["Super Admin", "Admin", "Regional Manager"].includes(currentUserRole);

let runningCampaignIds = new Set();
let activeLoopInterval = null;
let savedTemplatesList = [];
let loadedContactsList = [];
let selectedSpecificPhones = new Set();
let activeGatewayMode = "<?php echo $activeGateway; ?>";
let allCampaignsList = [];
let currentCampaignFilter = 'all';
let campaignSearchQuery = '';

const senderProfile = {
    firm: <?php echo json_encode($senderFirm); ?>,
    helpline: <?php echo json_encode($senderHelpline); ?>,
    upi: <?php echo json_encode($senderUpi); ?>,
    bank_name: <?php echo json_encode($senderBankName); ?>,
    account_no: <?php echo json_encode($senderAccNo); ?>,
    branch: <?php echo json_encode($senderBranch); ?>,
    ifsc: <?php echo json_encode($senderIfsc); ?>
};

document.addEventListener('DOMContentLoaded', () => {
    fetchCampaigns();
    fetchTemplates();
    fetchContactsList();
    activeLoopInterval = setInterval(runActiveCampaignsLoop, 3000);

    const indMsg = document.getElementById('indMessageText');
    if (indMsg) {
        indMsg.addEventListener('input', updateIndividualPreview);
    }
});

function switchMainTab(tabName) {
    ['templates', 'campaigns', 'individual', 'bulk'].forEach(t => {
        const btn = document.getElementById('tabHead-' + t);
        const content = document.getElementById('tabContent-' + t);
        if (btn) btn.classList.remove('active');
        if (content) content.style.display = 'none';
    });

    const activeBtn = document.getElementById('tabHead-' + tabName);
    const activeContent = document.getElementById('tabContent-' + tabName);
    if (activeBtn) activeBtn.classList.add('active');
    if (activeContent) activeContent.style.display = 'block';

    if (tabName === 'templates') fetchTemplates();
    if (tabName === 'campaigns') fetchCampaigns();
}

function fetchTemplates() {
    fetch('api/campaign-api.php?action=get_templates')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            savedTemplatesList = data.templates || [];
            renderTemplates(savedTemplatesList);
            populateTemplateDropdowns(savedTemplatesList);
        }
    })
    .catch(err => console.error(err));
}

function renderTemplates(list) {
    const container = document.getElementById('templatesGridContainer');
    const statElem = document.getElementById('statTemplateCount');
    if (statElem) statElem.innerText = list.length;

    if (!list || list.length === 0) {
        container.innerHTML = `
            <div style="background: var(--bg-card); padding: 3rem; text-align: center; border-radius: 12px; border: 1px solid var(--border-color); grid-column: 1 / -1;">
                <i data-lucide="file-text" style="width: 32px; height: 32px; color: #9ca3af; margin-bottom: 0.5rem;"></i>
                <h3 style="margin: 0; font-weight: 700;">No Saved Templates Yet</h3>
                <p class="text-xs text-muted">Click "+ Create Custom Template" to build templates with AI generator &amp; phone simulator.</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
    }

    let html = '';
    list.forEach(t => {
        let btns = [];
        if (t.buttons_json) {
            try { btns = JSON.parse(t.buttons_json); } catch(e) {}
        }

        let btnsHtml = '';
        if (btns && btns.length > 0) {
            btnsHtml += `<div class="template-buttons-preview">`;
            btns.forEach(b => {
                const bText = (typeof b === 'object') ? (b.title || 'Action') : b;
                btnsHtml += `<div class="template-btn-pill">${escapeHtml(bText)}</div>`;
            });
            btnsHtml += `</div>`;
        }

        const isApproved = (t.meta_status === 'APPROVED');
        const isPending = (t.meta_status === 'PENDING');
        const isWeb = (t.gateway_origin === 'web_api');

        let statusBadge = '';
        if (isWeb) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(59,130,246,0.15); color:#2563eb; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="send" style="width:12px;height:12px;"></i> Web API Ready</span>`;
        } else if (isApproved) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(16,185,129,0.15); color:#10b981; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-circle" style="width:12px;height:12px;"></i> Meta Approved</span>`;
        } else if (isPending) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(245,158,11,0.15); color:#d97706; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="clock" style="width:12px;height:12px;"></i> In Meta Review</span>`;
        } else if (t.meta_status === 'REJECTED' || t.meta_status === 'FAILED') {
            statusBadge = `<span class="badge text-xs" style="background:rgba(239,68,68,0.15); color:#ef4444; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="alert-circle" style="width:12px;height:12px;"></i> ${t.meta_status === 'REJECTED' ? 'Meta Rejected' : 'Submission Failed'}</span>`;
        } else {
            statusBadge = `<span class="badge text-xs" style="background:rgba(100,116,139,0.15); color:#64748b; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="file-text" style="width:12px;height:12px;"></i> Local Draft</span>`;
        }

        html += `
        <div class="template-card">
            <div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <span class="template-header-tag">${escapeHtml(t.category || 'GENERAL')}</span>
                    ${statusBadge}
                </div>
                <h4 style="margin: 2px 0 6px 0; font-size: 0.95rem; font-weight: 700; color: var(--text-main);">${escapeHtml(t.title)}</h4>
                ${t.header_text ? `<div style="font-size: 0.72rem; font-weight: 700; color: #64748b; margin-bottom: 4px;">${escapeHtml(t.header_text)}</div>` : ''}
                <div class="template-body-preview">${escapeHtml(t.body_text)}</div>
                ${t.footer_text ? `<div style="font-size: 0.68rem; color: #94a3b8; margin-top: 4px;">— ${escapeHtml(t.footer_text)}</div>` : ''}
                ${btnsHtml}
            </div>
            <div style="display: flex; gap: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.6rem; margin-top: 0.4rem; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary text-xs font-bold" style="padding: 4px 10px;" onclick="useTemplateInIndividual('${escapeHtml(t.slug)}')">
                    <i data-lucide="send" style="width:12px;height:12px;"></i> Single
                </button>
                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white; padding: 4px 10px;" onclick="useTemplateInBulk('${escapeHtml(t.slug)}')">
                    <i data-lucide="users" style="width:12px;height:12px;"></i> Bulk
                </button>
                <button type="button" class="btn btn-secondary text-xs" style="padding: 4px 8px; color:#ef4444;" onclick="deleteTemplate(${t.id})" title="Delete Template">
                    <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                </button>
            </div>
        </div>
        `;
    });

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function populateTemplateDropdowns(list) {
    const indSelect = document.getElementById('indTemplateSelect');
    const bulkSelect = document.getElementById('bulkTemplateSelect');

    let indHtml = `<option value="custom">-- Custom Message (Type below) --</option>`;
    let bulkHtml = `<option value="">-- Choose Approved Template from Database --</option>`;

    if (Array.isArray(list) && list.length > 0) {
        list.forEach(t => {
            const statusLabel = (t.meta_status === 'APPROVED') ? '[Approved]' : ((t.meta_status === 'PENDING') ? '[Pending Review]' : '');
            indHtml += `<option value="${escapeHtml(t.slug)}">${escapeHtml(t.title)} ${statusLabel}</option>`;
            bulkHtml += `<option value="${escapeHtml(t.slug)}">${escapeHtml(t.title)} ${statusLabel}</option>`;
        });
    }

    bulkHtml += `<option value="custom">Custom Text Message</option>`;

    if (indSelect) indSelect.innerHTML = indHtml;
    if (bulkSelect) bulkSelect.innerHTML = bulkHtml;
}

function applyTemplateToIndividual(slug) {
    const txtArea = document.getElementById('indMessageText');
    if (!txtArea) return;

    if (slug === 'custom') {
        txtArea.value = '';
        updateIndividualPreview();
        return;
    }

    const t = savedTemplatesList.find(x => x.slug === slug);
    if (t) {
        txtArea.value = t.body_text;
        updateIndividualPreview();
    }
}

function useTemplateInIndividual(slug) {
    switchMainTab('individual');
    const indSelect = document.getElementById('indTemplateSelect');
    if (indSelect) {
        indSelect.value = slug;
        applyTemplateToIndividual(slug);
    }
}

function useTemplateInBulk(slug) {
    switchMainTab('bulk');
    const bulkSelect = document.getElementById('bulkTemplateSelect');
    if (bulkSelect) {
        bulkSelect.value = slug;
        toggleCustomMessageText(slug);
    }
}

function toggleCustomMessageText(slug) {
    const customWrapper = document.getElementById('bulkCustomMessageWrapper');
    if (customWrapper) {
        customWrapper.style.display = (slug === 'custom' || !slug) ? 'block' : 'none';
    }
}

function updateIndividualPreview() {
    const txt = document.getElementById('indMessageText')?.value || '';
    const name = document.getElementById('indNameInput')?.value || 'Rajesh Medical Store';
    const comp = document.getElementById('indCompInput')?.value || 'Marg Pharma';
    const amount = document.getElementById('indAmountInput')?.value || '₹3,500';
    const dueDate = document.getElementById('indDueDateInput')?.value || '25 Aug 2026';
    const phone = document.getElementById('indPhoneInput')?.value || '9532620736';
    const previewBox = document.getElementById('indLivePreview');

    if (previewBox) {
        let clean = txt.replace(/{name}/g, name)
                       .replace(/{company}/g, comp)
                       .replace(/{phone}/g, phone)
                       .replace(/{amount}/g, amount)
                       .replace(/{due_date}/g, dueDate);

        if (clean.includes('{{1}}') || clean.includes('{{6}}') || clean.includes('Sale Bill Confirmation')) {
            clean = clean.replace(/{{1}}/g, senderProfile.firm || comp)
                         .replace(/{{2}}/g, name)
                         .replace(/{{3}}/g, 'INV-' + (Math.floor(1000 + Math.random() * 9000)))
                         .replace(/{{4}}/g, amount.replace(/[^\d\.,]/g, '') || '3,500')
                         .replace(/{{5}}/g, '0.00')
                         .replace(/{{6}}/g, senderProfile.upi || '-')
                         .replace(/{{7}}/g, senderProfile.bank_name || '-')
                         .replace(/{{8}}/g, senderProfile.account_no || '-')
                         .replace(/{{9}}/g, senderProfile.branch || '-')
                         .replace(/{{10}}/g, senderProfile.ifsc || '-')
                         .replace(/{{11}}/g, senderProfile.firm || comp)
                         .replace(/{{12}}/g, senderProfile.helpline || '-')
                         .replace(/{{13}}/g, 'https://friendlyaisolution.com/bill/preview');
        }

        previewBox.innerText = clean || 'Preview message will appear here...';
    }

    // Render interactive buttons in individual preview
    const tSlug = document.getElementById('indTemplateSelect')?.value;
    const btnsBox = document.getElementById('indLiveButtonsPreview');
    if (btnsBox) {
        btnsBox.innerHTML = '';
        if (tSlug && tSlug !== 'custom') {
            const t = savedTemplatesList.find(x => x.slug === tSlug);
            if (t && t.buttons_json) {
                try {
                    const bArr = JSON.parse(t.buttons_json);
                    bArr.forEach(b => {
                        const bTitle = (typeof b === 'object') ? (b.title || 'Action') : b;
                        btnsBox.innerHTML += `<span class="template-btn-pill">${escapeHtml(bTitle)}</span>`;
                    });
                } catch(e) {}
            }
        }
    }
}

function handleIndividualSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitIndividual');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Dispatched via Gateway...`;

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'send_individual');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct Instant Broadcast Now`;

        if (data.success) {
            alert(data.message);
            form.reset();
            updateIndividualPreview();
            fetchCampaigns();
            switchMainTab('campaigns');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct Instant Broadcast Now`;
        alert('Failed sending individual broadcast.');
    });
}

function setCampaignFilter(filterType, btnElem) {
    currentCampaignFilter = filterType;
    document.querySelectorAll('#campaignFilterGroup .camp-filter-pill').forEach(btn => btn.classList.remove('active'));
    if (btnElem) btnElem.classList.add('active');
    renderCampaigns(allCampaignsList);
}

function handleCampaignSearch(query) {
    campaignSearchQuery = (query || '').toLowerCase().trim();
    renderCampaigns(allCampaignsList);
}

function fetchCampaigns() {
    fetch('api/campaign-api.php?action=get_campaigns')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            allCampaignsList = data.campaigns || [];
            renderCampaigns(allCampaignsList);
        }
    })
    .catch(err => console.error(err));
}

function deleteCampaign(id) {
    if (!confirm('Are you sure you want to delete this campaign? This will remove the campaign record and its audience delivery logs.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_campaign');
    formData.append('id', id);

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetchCampaigns();
        } else {
            alert('Error: ' + (data.message || 'Failed to delete campaign.'));
        }
    })
    .catch(err => {
        alert('Network error while deleting campaign.');
    });
}

function clearAllCampaigns() {
    if (!confirm('⚠️ Are you sure you want to DELETE and CLEAR ALL Active & Past Campaigns?\n\nThis will reset the campaign count to 0 and remove all audience logs.')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'clear_all_campaigns');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetchCampaigns();
        } else {
            alert('Error: ' + (data.message || 'Failed to clear campaigns.'));
        }
    })
    .catch(err => {
        alert('Network error while clearing campaigns.');
    });
}

function renderCampaigns(list) {
    const container = document.getElementById('campaignsListContainer');
    if (!container) return;
    runningCampaignIds.clear();

    const fullList = list || [];
    let totalSentSum = 0;
    let activeCnt = 0;
    let runningCount = 0;
    let completedCount = 0;
    let pendingApprovalCount = 0;
    let stoppedCount = 0;

    fullList.forEach(c => {
        const sent = parseInt(c.sent_count || 0);
        totalSentSum += sent;
        if (c.status === 'running') {
            activeCnt++;
            runningCount++;
            runningCampaignIds.add(c.id);
        } else if (c.status === 'completed') {
            completedCount++;
        } else if (c.status === 'pending_approval') {
            pendingApprovalCount++;
        } else if (['cancelled', 'rejected', 'paused'].includes(c.status)) {
            stoppedCount++;
        }
    });

    // Update top stat cards
    const statSent = document.getElementById('statTotalSent');
    const statActive = document.getElementById('statActiveCount');
    if (statSent) statSent.innerText = totalSentSum;
    if (statActive) statActive.innerText = activeCnt;

    // Update filter badge counts
    const pAll = document.getElementById('pillCount-all');
    const pRun = document.getElementById('pillCount-running');
    const pComp = document.getElementById('pillCount-completed');
    const pPend = document.getElementById('pillCount-pending_approval');
    const pStop = document.getElementById('pillCount-stopped');

    if (pAll) pAll.innerText = fullList.length;
    if (pRun) pRun.innerText = runningCount;
    if (pComp) pComp.innerText = completedCount;
    if (pPend) pPend.innerText = pendingApprovalCount;
    if (pStop) pStop.innerText = stoppedCount;

    // Filter by tab and search
    let filtered = fullList.filter(c => {
        // Tab filter
        if (currentCampaignFilter === 'running' && c.status !== 'running') return false;
        if (currentCampaignFilter === 'completed' && c.status !== 'completed') return false;
        if (currentCampaignFilter === 'pending_approval' && c.status !== 'pending_approval') return false;
        if (currentCampaignFilter === 'stopped' && !['cancelled', 'rejected', 'paused'].includes(c.status)) return false;

        // Search filter
        if (campaignSearchQuery) {
            const str = `${c.name || ''} ${c.template_name || ''} ${c.target_type || ''} ${c.created_by || ''}`.toLowerCase();
            if (!str.includes(campaignSearchQuery)) return false;
        }
        return true;
    });

    if (fullList.length === 0) {
        container.innerHTML = `
            <div style="background: var(--bg-card, #ffffff); border: 1.5px dashed var(--border-color, #cbd5e1); border-radius: 16px; padding: 3.5rem 1.5rem; text-align: center; max-width: 680px; margin: 1rem auto; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(37, 99, 235, 0.08); border: 1px solid rgba(37, 99, 235, 0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; color: #2563eb;">
                    <i data-lucide="layers" style="width: 28px; height: 28px;"></i>
                </div>
                <h3 style="margin: 0 0 0.4rem 0; font-size: 1.18rem; font-weight: 800; color: var(--text-main, #0f172a);">
                    Zero Campaigns &mdash; Clean Slate
                </h3>
                <p style="margin: 0 auto 1.5rem auto; font-size: 0.82rem; color: var(--text-muted, #64748b); max-width: 480px; line-height: 1.5;">
                    All active and past broadcast campaigns have been deleted and cleared. You can start fresh by sending a quick 1-on-1 personalized message or launching a targeted bulk campaign.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary text-xs font-bold" style="padding: 8px 18px;" onclick="switchMainTab('individual')">
                        <i data-lucide="send" style="width: 14px; height: 14px;"></i> Individual Quick Send
                    </button>
                    <button type="button" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white; padding: 8px 18px;" onclick="switchMainTab('bulk')">
                        <i data-lucide="rocket" style="width: 14px; height: 14px;"></i> Launch Bulk Campaign
                    </button>
                    <button type="button" class="btn btn-secondary text-xs font-bold" style="padding: 8px 16px;" onclick="switchMainTab('templates')">
                        <i data-lucide="file-text" style="width: 14px; height: 14px;"></i> Template Builder
                    </button>
                </div>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
    }

    if (filtered.length === 0) {
        container.innerHTML = `
            <div style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 3rem 1.5rem; text-align: center; max-width: 500px; margin: 1rem auto;">
                <i data-lucide="search-x" style="width: 32px; height: 32px; color: #94a3b8; margin-bottom: 0.75rem;"></i>
                <h4 style="margin: 0 0 0.35rem 0; font-size: 1rem; font-weight: 700; color: var(--text-main);">No Matching Campaigns Found</h4>
                <p class="text-xs text-muted mb-3">No campaigns matched your selected filter status or search term.</p>
                <button type="button" class="btn btn-secondary text-xs font-semibold" onclick="setCampaignFilter('all', document.querySelector('[data-filter=all]')); document.getElementById('campaignSearchInput').value=''; handleCampaignSearch('');">
                    Reset Filter &amp; Search
                </button>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
    }

    let html = '';
    filtered.forEach(c => {
        const isPendingApp = (c.status === 'pending_approval');
        const isApproved   = (c.status === 'approved');
        const isRejected   = (c.status === 'rejected');
        const isRunning    = (c.status === 'running');
        const isPaused     = (c.status === 'paused');
        const isDone       = (c.status === 'completed');
        const isCancelled  = (c.status === 'cancelled');
        const isDirect     = (c.target_type === 'individual' || (c.name && c.name.includes('Direct Broadcast')));

        // Type Badge
        const typeBadge = isDirect
            ? `<span class="badge" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.25); font-weight: 700; font-size: 0.71rem; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="zap" style="width: 12px; height: 12px;"></i> Direct 1-on-1</span>`
            : `<span class="badge" style="background: rgba(14, 165, 233, 0.1); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.25); font-weight: 700; font-size: 0.71rem; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="users" style="width: 12px; height: 12px;"></i> Bulk Broadcast</span>`;

        // Status Badge
        let statusBadge = '<span class="badge text-xs" style="background:#e5e7eb; color:#374151;">Draft</span>';
        if (isPendingApp) statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="clock" style="width:12px;height:12px;"></i> Pending Review</span>';
        if (isApproved) statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-circle-2" style="width:12px;height:12px;"></i> Approved</span>';
        if (isRejected) statusBadge = '<span class="badge text-xs" style="background:rgba(239,68,68,0.12); color:#dc2626; border:1px solid rgba(239,68,68,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="x-circle" style="width:12px;height:12px;"></i> Rejected</span>';
        if (isRunning) statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:6px;"><span class="pulse-dot"></span> In Progress</span>';
        if (isPaused) statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="pause" style="width:12px;height:12px;"></i> Paused</span>';
        if (isDone) statusBadge = '<span class="badge text-xs" style="background:rgba(37,99,235,0.12); color:#2563eb; border:1px solid rgba(37,99,235,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-check" style="width:12px;height:12px;"></i> Completed</span>';
        if (isCancelled) statusBadge = '<span class="badge text-xs" style="background:rgba(100,116,139,0.12); color:#475569; border:1px solid rgba(100,116,139,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="square" style="width:12px;height:12px;"></i> Stopped</span>';

        const barGradient = isDone
            ? 'linear-gradient(90deg, #10b981, #059669)'
            : (isCancelled || isRejected ? '#ef4444' : 'linear-gradient(90deg, #3b82f6, #2563eb)');

        html += `
        <div class="campaign-card" id="campCard-${c.id}">
            <div class="campaign-card-header">
                <div class="campaign-title-block">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        ${typeBadge}
                        ${statusBadge}
                    </div>

                    <h3 class="campaign-title">
                        <span>${escapeHtml(c.name)}</span>
                    </h3>

                    <div class="campaign-meta-bar">
                        <span class="meta-chip">
                            <i data-lucide="file-text" style="width: 12px; height: 12px; color: #2563eb;"></i>
                            <strong>${escapeHtml(c.template_name)}</strong>
                        </span>
                        <span class="meta-chip">
                            <i data-lucide="target" style="width: 12px; height: 12px; color: #6366f1;"></i>
                            <span>${escapeHtml((c.target_type || 'Audience').toUpperCase())}</span>
                        </span>
                        <span class="meta-chip">
                            <i data-lucide="user" style="width: 12px; height: 12px; color: #64748b;"></i>
                            <span>${escapeHtml(c.created_by || 'Staff')}</span>
                        </span>
                        ${c.formatted_created ? `
                        <span class="meta-chip" title="Creation Date">
                            <i data-lucide="calendar" style="width: 12px; height: 12px; color: #94a3b8;"></i>
                            <span>${escapeHtml(c.formatted_created)}</span>
                        </span>
                        ` : ''}
                    </div>
                </div>

                <div class="campaign-actions-bar">
                    ${isPendingApp && isAdminUser ? `
                        <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white; padding: 6px 12px;" onclick="approveCampaign(${c.id})">
                            <i data-lucide="check" style="width:12px;height:12px;"></i> Approve
                        </button>
                        <button type="button" class="btn btn-danger text-xs font-bold" style="padding: 6px 12px;" onclick="rejectCampaign(${c.id})">
                            <i data-lucide="x" style="width:12px;height:12px;"></i> Reject
                        </button>
                    ` : ''}

                    ${!isDone && !isCancelled && !isRejected ? `
                        ${isPendingApp ? `
                            <span class="text-xs text-muted" style="padding: 4px 8px; font-style: italic;">Awaiting Review</span>
                        ` : `
                            ${isRunning ? `
                                <button type="button" class="btn btn-warning text-xs font-bold" style="padding: 6px 11px;" onclick="toggleCampaignStatus(${c.id}, 'paused')" title="Pause sending">
                                    <i data-lucide="pause" style="width:12px;height:12px;"></i> Pause
                                </button>
                            ` : `
                                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white; padding: 6px 11px;" onclick="toggleCampaignStatus(${c.id}, 'running')">
                                    <i data-lucide="play" style="width:12px;height:12px;"></i> Resume
                                </button>
                            `}
                            <button type="button" class="btn btn-danger text-xs font-bold" style="padding: 6px 11px;" onclick="toggleCampaignStatus(${c.id}, 'cancelled')" title="Stop Campaign">
                                <i data-lucide="square" style="width:12px;height:12px;"></i> Stop
                            </button>
                        `}
                    ` : ''}

                    <button type="button" class="btn btn-secondary text-xs font-semibold" style="padding: 6px 12px;" onclick="viewAudienceDetails(${c.id}, '${escapeHtml(c.name)}')">
                        <i data-lucide="users" style="width:13px;height:13px;"></i> Contacts (${c.total_contacts})
                    </button>

                    <button type="button" class="btn btn-secondary text-xs" style="color: #ef4444; border-color: rgba(239,68,68,0.3); background: rgba(239,68,68,0.03); padding: 6px 10px;" onclick="deleteCampaign(${c.id})" title="Delete Campaign Record">
                        <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                    </button>
                </div>
            </div>

            <div class="campaign-progress-box">
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.78rem;">
                    <span style="color: var(--text-muted, #64748b);">
                        Dispatch Progress: <strong style="color: var(--text-main, #0f172a); font-weight: 700;">${c.sent_count} / ${c.total_contacts} Messages Dispatched</strong>
                    </span>
                    <span style="font-weight: 800; font-size: 0.84rem; color: ${c.progress_percent === 100 ? '#10b981' : '#2563eb'};">
                        ${c.progress_percent}%
                    </span>
                </div>

                <div class="progress-track-sleek">
                    <div class="progress-bar-fill-sleek" style="width: ${c.progress_percent}%; background: ${barGradient};"></div>
                </div>

                <div class="campaign-stat-pills-row">
                    <span class="campaign-stat-pill stat-pill-sent">
                        <i data-lucide="check" style="width: 11px; height: 11px;"></i> Sent: ${c.sent_count}
                    </span>
                    <span class="campaign-stat-pill stat-pill-pending">
                        <i data-lucide="clock" style="width: 11px; height: 11px;"></i> Pending: ${c.pending_count}
                    </span>
                    <span class="campaign-stat-pill stat-pill-failed">
                        <i data-lucide="alert-triangle" style="width: 11px; height: 11px;"></i> Failed: ${c.failed_count}
                    </span>
                    <span class="campaign-stat-pill stat-pill-total">
                        <i data-lucide="users" style="width: 11px; height: 11px;"></i> Total: ${c.total_contacts}
                    </span>
                    ${c.delay_seconds ? `
                    <span class="campaign-stat-pill" style="background: transparent; color: #94a3b8; font-weight: 500; font-size: 0.7rem; padding: 0 4px;">
                        • ${c.delay_seconds}s throttle delay
                    </span>
                    ` : ''}
                </div>
            </div>
        </div>
        `;
    });

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function handleBulkCampaignSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitBulk');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Initializing Campaign...`;

    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'create_campaign');

    // Append selected specific numbers if applicable
    if (selectedSpecificPhones.size > 0) {
        selectedSpecificPhones.forEach(ph => {
            formData.append('selected_phones[]', ph);
        });
    }

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="play" style="width:14px;height:14px;"></i> Create &amp; Initialize Campaign`;

        if (data.success) {
            alert(data.message);
            form.reset();
            fetchCampaigns();
            switchMainTab('campaigns');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="play" style="width:14px;height:14px;"></i> Create &amp; Initialize Campaign`;
        alert('Failed creating bulk campaign.');
    });
}

function toggleTargetAudienceType(val) {
    const csvWrap = document.getElementById('csvUploadWrapper');
    const specificWrap = document.getElementById('specificContactsWrapper');

    if (csvWrap) csvWrap.style.display = (val === 'csv') ? 'block' : 'none';
    if (specificWrap) specificWrap.style.display = (val === 'specific') ? 'block' : 'none';
}

function toggleCustomMessageText(val) {
    const customWrap = document.getElementById('customMsgWrapper');
    if (customWrap) {
        customWrap.style.display = (val === 'custom') ? 'block' : 'none';
    }
}

// Contacts List for Specific Number Selection & Individual Auto-fill
function fetchContactsList() {
    fetch('api/campaign-api.php?action=get_contacts_list')
    .then(res => res.json())
    .then(data => {
        if (data.success && data.contacts) {
            loadedContactsList = data.contacts;
            populateIndividualContactPicker(loadedContactsList);
            renderSpecificContactsList(loadedContactsList);
        }
    })
    .catch(err => console.error(err));
}

function populateIndividualContactPicker(contacts) {
    const picker = document.getElementById('indContactPicker');
    if (!picker) return;

    let html = `<option value="">-- Choose an existing contact or enter below --</option>`;
    contacts.forEach(c => {
        html += `<option value="${escapeHtml(c.phone)}">${escapeHtml(c.name)} (${escapeHtml(c.company)}) - +${escapeHtml(c.phone)}</option>`;
    });
    picker.innerHTML = html;
}

function handleSelectContactIndividual(phone) {
    if (!phone) return;
    const c = loadedContactsList.find(x => x.phone === phone);
    if (c) {
        document.getElementById('indPhoneInput').value = c.phone;
        document.getElementById('indNameInput').value = c.name;
        document.getElementById('indCompInput').value = c.company;
        document.getElementById('indAmountInput').value = c.amount || '₹3,500';
        document.getElementById('indDueDateInput').value = c.due_date || '25 Aug 2026';
        updateIndividualPreview();
    }
}

function renderSpecificContactsList(contacts) {
    const listContainer = document.getElementById('specificContactsList');
    if (!listContainer) return;

    let html = '';
    contacts.forEach(c => {
        const isChecked = selectedSpecificPhones.has(c.phone) ? 'checked' : '';
        html += `
        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.78rem; padding: 4px 6px; background: white; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer;">
            <input type="checkbox" value="${escapeHtml(c.phone)}" ${isChecked} onchange="handleSpecificPhoneCheck('${escapeHtml(c.phone)}', this.checked)">
            <span><strong>${escapeHtml(c.name)}</strong> (${escapeHtml(c.company)}) &bull; +${escapeHtml(c.phone)}</span>
        </label>
        `;
    });
    listContainer.innerHTML = html || '<div class="text-xs text-muted">No contacts found</div>';
    updateSelectedContactsCounter();
}

function filterSpecificContactsList(term) {
    const q = term.toLowerCase();
    const filtered = loadedContactsList.filter(c => {
        return c.name.toLowerCase().includes(q) || c.company.toLowerCase().includes(q) || c.phone.includes(q);
    });
    renderSpecificContactsList(filtered);
}

function handleSpecificPhoneCheck(phone, checked) {
    if (checked) {
        selectedSpecificPhones.add(phone);
    } else {
        selectedSpecificPhones.delete(phone);
    }
    updateSelectedContactsCounter();
}

function toggleAllSpecificContacts(selectAll) {
    if (selectAll) {
        loadedContactsList.forEach(c => selectedSpecificPhones.add(c.phone));
    } else {
        selectedSpecificPhones.clear();
    }
    renderSpecificContactsList(loadedContactsList);
}

function updateSelectedContactsCounter() {
    const el = document.getElementById('selectedContactsCount');
    if (el) el.innerText = selectedSpecificPhones.size;
}

// AI Template Generation Handler
function triggerAiTemplateGeneration() {
    const promptInput = document.getElementById('aiPromptInput');
    const prompt = promptInput?.value.trim();
    if (!prompt) {
        alert('Please explain what message or template you want to generate.');
        return;
    }

    const btn = document.getElementById('btnAiGenerate');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:14px; height:14px;"></i> Generating...`;
    if (window.lucide) lucide.createIcons();

    const tone = document.getElementById('aiToneSelect')?.value || 'professional';
    const cat = document.getElementById('builderCategory')?.value || 'MARKETING';

    fetch('api/campaign-api.php?action=ai_generate_template', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ prompt: prompt, tone: tone, category: cat })
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="sparkles" style="width:14px; height:14px;"></i> Generate with AI`;
        if (window.lucide) lucide.createIcons();

        if (data.success && data.template) {
            const t = data.template;
            document.getElementById('builderTitle').value = t.title || 'Custom Business Broadcast';
            document.getElementById('builderCategory').value = t.category || 'MARKETING';
            document.getElementById('builderHeaderText').value = t.header_text || '';
            document.getElementById('builderBodyText').value = t.body_text || '';
            document.getElementById('builderFooterText').value = t.footer_text || '';

            // Populate buttons
            const list = document.getElementById('builderButtonsList');
            if (list && t.buttons && Array.isArray(t.buttons)) {
                list.innerHTML = '';
                t.buttons.forEach(bText => {
                    const div = document.createElement('div');
                    div.className = 'btn-builder-row';
                    div.style.display = 'flex';
                    div.style.gap = '6px';
                    div.style.alignItems = 'center';
                    div.innerHTML = `
                        <input type="text" class="input-styled text-xs builder-btn-input" value="${escapeHtml(bText)}" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                        <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:1.1rem; padding:4px;">&times;</button>
                    `;
                    list.appendChild(div);
                });
            }

            updateLivePhoneMockup();
        } else {
            alert(data.message || 'AI Generation failed');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="sparkles" style="width:14px; height:14px;"></i> Generate with AI`;
        if (window.lucide) lucide.createIcons();
        alert('Network error generating template.');
    });
}

function fillAiPreset(text) {
    const input = document.getElementById('aiPromptInput');
    if (input) {
        input.value = text;
        triggerAiTemplateGeneration();
    }
}

function openCreateTemplateModal() {
    const modal = document.getElementById('interactiveTemplateModal');
    if (modal) {
        modal.classList.add('active');
        modal.classList.add('open');
        modal.style.display = 'flex';
    }
    updateLivePhoneMockup();
    if (window.lucide) lucide.createIcons();
}

function closeCreateTemplateModal() {
    const modal = document.getElementById('interactiveTemplateModal');
    if (modal) {
        modal.classList.remove('active');
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function insertVarToBody(v) {
    const bodyArea = document.getElementById('builderBodyText');
    if (bodyArea) {
        bodyArea.value += ' ' + v;
        bodyArea.focus();
        updateLivePhoneMockup();
    }
}

function addInteractiveButtonInput() {
    const list = document.getElementById('builderButtonsList');
    if (list.children.length >= 3) {
        alert('Maximum 3 interactive quick reply buttons allowed per template.');
        return;
    }

    const div = document.createElement('div');
    div.className = 'btn-builder-row';
    div.style.display = 'flex';
    div.style.gap = '6px';
    div.style.alignItems = 'center';
    div.innerHTML = `
        <input type="text" class="input-styled text-xs builder-btn-input" value="Contact Support" placeholder="Button Title" oninput="updateLivePhoneMockup()">
        <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color:#ef4444; background:none; border:none; cursor:pointer; font-size:1.1rem; padding:4px;">&times;</button>
    `;
    list.appendChild(div);
    updateLivePhoneMockup();
}

function removeButtonRow(btn) {
    btn.parentElement.remove();
    updateLivePhoneMockup();
}

function updateLivePhoneMockup() {
    const headerVal = document.getElementById('builderHeaderText')?.value || 'Marg ERP Software AMC Notice';
    const bodyVal   = document.getElementById('builderBodyText')?.value || 'Dear {name},\n\nYour Marg ERP Software AMC renewal of *{amount}* is due on *{due_date}*.\n\nTo ensure uninterrupted billing & GST filings, kindly renew your AMC.\n\nHelpline: *{phone}*';
    const footerVal = document.getElementById('builderFooterText')?.value || 'Marg Soft Solution Support Desk';

    let formattedBody = escapeHtml(bodyVal)
        .replace(/{name}/g, '<span style="color:#008069; font-weight:700;">Rajesh Medical Store</span>')
        .replace(/{company}/g, '<span style="color:#008069; font-weight:700;">Marg Pharma</span>')
        .replace(/{phone}/g, '<span style="color:#008069; font-weight:700;">9532620736</span>')
        .replace(/{amount}/g, '<span style="color:#008069; font-weight:700;">₹3,500</span>')
        .replace(/{due_date}/g, '<span style="color:#008069; font-weight:700;">25 Aug 2026</span>')
        .replace(/\*([^\*]+)\*/g, '<b>$1</b>')
        .replace(/\_([^\_]+)\_/g, '<i>$1</i>')
        .replace(/\n/g, '<br>');

    const mockHead = document.getElementById('mockupHeader');
    const mockBdy  = document.getElementById('mockupBody');
    const mockFtr  = document.getElementById('mockupFooter');

    if (mockHead) mockHead.innerHTML = escapeHtml(headerVal);
    if (mockBdy)  mockBdy.innerHTML  = formattedBody;
    if (mockFtr)  mockFtr.innerHTML  = escapeHtml(footerVal);

    const btnInputs = document.querySelectorAll('.builder-btn-input');
    const mockBtnsContainer = document.getElementById('mockupButtons');
    if (mockBtnsContainer) {
        let btnsHtml = '';
        btnInputs.forEach(inp => {
            const val = inp.value.trim();
            if (val) {
                btnsHtml += `<button type="button" class="wa-interactive-btn"><i data-lucide="corner-down-left" style="width:12px; height:12px;"></i> <span>${escapeHtml(val)}</span></button>`;
            }
        });
        mockBtnsContainer.innerHTML = btnsHtml;
        if (window.lucide) lucide.createIcons();
    }
}

function handleTemplateSaveSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitTemplateModal');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:14px; height:14px;"></i> Saving...`;
    if (window.lucide) lucide.createIcons();

    const form = e.target;
    const formData = new FormData(form);

    const btnInputs = document.querySelectorAll('.builder-btn-input');
    const buttonsArr = [];
    btnInputs.forEach((inp, idx) => {
        const val = inp.value.trim();
        if (val) {
            buttonsArr.push({ id: 'btn_' + idx, title: val });
        }
    });

    formData.append('buttons_json', JSON.stringify(buttonsArr));
    formData.append('action', 'save_template');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = (activeGatewayMode === 'web_api') ? 
            `<i data-lucide="save" style="width:14px; height:14px;"></i> <span>Save Template to Library</span>` : 
            `<i data-lucide="send" style="width:14px; height:14px;"></i> <span>Submit to Meta for Approval & Save</span>`;
        if (window.lucide) lucide.createIcons();

        if (data.success) {
            alert(data.message);
            form.reset();
            closeCreateTemplateModal();
            fetchTemplates();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = (activeGatewayMode === 'web_api') ? 
            `<i data-lucide="save" style="width:14px; height:14px;"></i> <span>Save Template to Library</span>` : 
            `<i data-lucide="send" style="width:14px; height:14px;"></i> <span>Submit to Meta for Approval & Save</span>`;
        if (window.lucide) lucide.createIcons();
        alert('Error submitting template.');
    });
}

function deleteTemplate(id) {
    if (confirm('Are you sure you want to delete this template? If linked to Meta, it will also be deleted from Meta Cloud API.')) {
        const formData = new FormData();
        formData.append('action', 'delete_template');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message || 'Template deleted successfully.');
                fetchTemplates();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            alert('Failed to connect to server');
        });
    }
}

function approveCampaign(id) {
    if (confirm('Are you sure you want to Approve this campaign for broadcast?')) {
        const formData = new FormData();
        formData.append('action', 'approve_campaign');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchCampaigns();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function rejectCampaign(id) {
    if (confirm('Are you sure you want to Reject this campaign?')) {
        const formData = new FormData();
        formData.append('action', 'reject_campaign');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchCampaigns();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function toggleCampaignStatus(id, newStatus) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetchCampaigns();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function viewAudienceDetails(campaignId, name) {
    const modal = document.getElementById('audienceModal');
    const title = document.getElementById('audModalTitle');
    const body = document.getElementById('audTableBody');

    title.innerText = `Contacts List: ${name}`;
    body.innerHTML = `<tr><td colspan="5" style="text-align:center;">Loading contacts...</td></tr>`;
    if (modal) {
        modal.classList.add('active');
        modal.classList.add('open');
        modal.style.display = 'flex';
    }

    fetch(`api/campaign-api.php?action=get_campaign_details&id=${campaignId}`)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.audience) {
            let html = '';
            data.audience.forEach(a => {
                let stBadge = `<span class="badge text-xs" style="background:#e5e7eb;">Pending</span>`;
                if (a.status === 'sent') stBadge = `<span class="badge text-xs" style="background:rgba(16,185,129,0.15); color:#10b981;">Sent</span>`;
                if (a.status === 'failed') stBadge = `<span class="badge text-xs" style="background:rgba(239,68,68,0.15); color:#ef4444;" title="${escapeHtml(a.error_message || '')}">Failed</span>`;

                html += `
                <tr>
                    <td><strong>+${a.mobile}</strong></td>
                    <td>${escapeHtml(a.customer_name || 'N/A')}</td>
                    <td>${escapeHtml(a.company_name || 'N/A')}</td>
                    <td>${stBadge}</td>
                    <td>${a.sent_at || '-'}</td>
                </tr>
                `;
            });
            body.innerHTML = html || `<tr><td colspan="5" style="text-align:center;">No contacts found.</td></tr>`;
        }
    });
}

function closeAudienceModal() {
    const modal = document.getElementById('audienceModal');
    if (modal) {
        modal.classList.remove('active');
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function runActiveCampaignsLoop() {
    if (runningCampaignIds.size === 0) return;

    runningCampaignIds.forEach(id => {
        fetch(`api/campaign-api.php?action=process_batch&id=${id}&batch_size=5`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.campaign) {
                if (data.status === 'completed') {
                    runningCampaignIds.delete(id);
                    fetchCampaigns();
                }
            }
        })
        .catch(err => console.error(err));
    });
}

function syncMetaTemplates() {
    const btn = document.getElementById('btnSyncMeta');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:13px; height:13px;"></i> Syncing from Meta...`;
    }

    fetch('api/campaign-api.php?action=sync_meta_templates')
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>Sync Meta Approved Templates</span>`;
        }

        if (data.success) {
            alert(data.message);
            fetchTemplates();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>Sync Meta Approved Templates</span>`;
        }
        alert('Network error syncing templates from Meta.');
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;").replace(/\n/g, '<br>');
}
</script>
