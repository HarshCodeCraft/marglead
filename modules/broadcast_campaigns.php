<?php

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
    $wabaCfg = $stmtWaba->fetch(PDO::FETCH_ASSOC) ?: [];

    $activeGateway = !empty($wabaCfg['gateway_type']) ? $wabaCfg['gateway_type'] : 'web_api';
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

// Employee Privacy & Role Access
$userRole = $_SESSION['user_role'] ?? 'Executive';
$userName = trim($_SESSION['user_name'] ?? 'Staff');
$isTenantAdmin = (
    strcasecmp($userRole, 'Tenant Admin') === 0 || 
    !empty($_SESSION['is_tenant']) || 
    (!empty($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== (defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata'))
);
$isAdminUser = in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager', 'Tenant Admin']) || $isTenantAdmin;
$canManageTemplates = true; // Any authenticated user or tenant can create & manage their own private templates
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
    width: 96vw;
    max-width: 1180px;
    height: 90vh;
    max-height: 900px;
    box-shadow: 0 25px 65px -15px rgba(15, 23, 42, 0.35), 0 0 0 1px var(--border-color, #e2e8f0);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    animation: modalScaleIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalScaleIn {
    from { opacity: 0; transform: scale(0.96) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

@media (max-width: 960px) {
    .modal-box-lg {
        height: 96vh;
        max-height: 96vh;
        border-radius: 14px;
        width: 98vw;
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

/* Recipient Mode Tab Buttons */
.btn-xs-tab {
    padding: 4px 10px;
    font-size: 0.73rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    color: #475569;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.btn-xs-tab:hover {
    color: #0f172a;
    background: rgba(255,255,255,0.6);
}
.btn-xs-tab.active {
    background: #ffffff;
    color: #059669;
    font-weight: 700;
    border-color: rgba(16,185,129,0.3);
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
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

/* =============================================================
   ENHANCED INTERACTIVE TEMPLATE BUILDER & WHATSAPP SIMULATOR
   ============================================================= */

.builder-modal-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 1rem;
}

.builder-modal-title-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

.builder-modal-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.24));
    color: var(--primary, #2563eb);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.builder-gateway-badge {
    font-size: 0.70rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    letter-spacing: 0.02em;
}

.builder-gateway-badge.badge-web {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
}

.builder-gateway-badge.badge-meta {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid #a7f3d0;
}

.builder-modal-content {
    display: grid;
    grid-template-columns: 1fr 390px;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    background: var(--bg-card, #ffffff);
}

@media (max-width: 960px) {
    .builder-modal-content {
        grid-template-columns: 1fr;
        overflow-y: auto;
    }
}

.builder-col-form {
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.15rem;
}

.builder-col-form::-webkit-scrollbar {
    width: 6px;
}
.builder-col-form::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 6px;
}

.builder-col-preview {
    background: #f8fafc;
    border-left: 1px solid var(--border-color, #e2e8f0);
    padding: 1.25rem 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow-y: auto;
    position: relative;
}

.preview-top-badge {
    font-size: 0.72rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 12px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #ffffff;
    padding: 5px 14px;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.preview-pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulseDot 1.8s infinite;
}

@keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Micro-interactions & Human-crafted Polish */
.btn-xs-tab {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-xs-tab:hover {
    transform: translateY(-1px);
}
.ai-preset-chip {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.ai-preset-chip:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
}
.ind-broadcast-layout {
    transition: box-shadow 0.3s ease;
}
.ind-preview-col .wa-phone-frame {
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
}
.ind-preview-col .wa-phone-frame:hover {
    transform: translateY(-3px);
    box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(0,0,0,0.06);
}
.wa-speech-bubble {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.wa-var-tag {
    transition: background-color 0.2s ease, color 0.2s ease;
}

/* Authentic WhatsApp Smartphone Simulator Frame */
.wa-phone-mockup {
    width: 100%;
    max-width: 350px;
    height: 590px;
    background: #efeae2;
    border: 12px solid #0f172a;
    border-radius: 44px;
    box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.45), 0 0 0 2px #334155;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
}

.wa-phone-notch {
    background: #0f172a;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px;
    color: #94a3b8;
    font-size: 0.65rem;
    font-weight: 700;
    flex-shrink: 0;
}

.notch-island-center {
    display: flex;
    align-items: center;
    gap: 8px;
}

.notch-speaker {
    width: 38px;
    height: 4px;
    background: #334155;
    border-radius: 4px;
}

.notch-cam {
    width: 7px;
    height: 7px;
    background: #020617;
    border-radius: 50%;
    border: 1.5px solid #1e293b;
}

.wa-phone-header {
    background: #008069;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    flex-shrink: 0;
}

.wa-phone-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #059669;
    border: 1.5px solid rgba(255,255,255,0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    color: white;
    font-size: 0.9rem;
    flex-shrink: 0;
}

/* FIX: Chat Body with native top-to-bottom flow and smooth scroll */
.wa-phone-body {
    background: #efeae2 url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-835d-d93a777afe46.png');
    background-size: 320px auto;
    background-repeat: repeat;
    flex: 1;
    min-height: 0;
    padding: 12px 10px;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    justify-content: flex-start; /* CRITICAL FIX: No flex-end clipping! Allows full scroll from top to bottom */
    gap: 8px;
    scroll-behavior: smooth;
}

.wa-phone-body::-webkit-scrollbar {
    width: 4px;
}
.wa-phone-body::-webkit-scrollbar-track {
    background: transparent;
}
.wa-phone-body::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.2);
    border-radius: 4px;
}

.wa-date-divider {
    align-self: center;
    background: rgba(255, 255, 255, 0.92);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 0.65rem;
    font-weight: 700;
    color: #54656f;
    margin-bottom: 6px;
    box-shadow: 0 1px 1.5px rgba(11,20,26,0.12);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    flex-shrink: 0;
}

/* Authentic WhatsApp Incoming Message Bubble */
.wa-msg-bubble {
    background: #ffffff;
    color: #111b21;
    border-radius: 0px 8px 8px 8px;
    padding: 8px 10px 6px 10px;
    font-size: 0.81rem;
    line-height: 1.48;
    box-shadow: 0 1px 2px rgba(11,20,26,0.15);
    position: relative;
    max-width: 94%;
    align-self: flex-start;
    margin-left: 6px;
    word-break: break-word;
    overflow-wrap: anywhere;
}

/* WhatsApp speech bubble corner tail */
.wa-msg-bubble::before {
    content: "";
    position: absolute;
    top: 0;
    left: -7px;
    width: 0;
    height: 0;
    border-top: 0px solid transparent;
    border-bottom: 10px solid transparent;
    border-right: 8px solid #ffffff;
}

.wa-msg-header {
    font-weight: 700;
    color: #008069;
    margin-bottom: 5px;
    font-size: 0.84rem;
    line-height: 1.35;
    letter-spacing: -0.01em;
}

.wa-msg-body-content {
    color: #111b21;
    white-space: pre-wrap;
    word-break: break-word;
}

.wa-var-tag {
    background: rgba(0, 128, 105, 0.09);
    color: #008069;
    padding: 1px 5px;
    border-radius: 4px;
    font-weight: 700;
    border: 1px dashed rgba(0, 128, 105, 0.35);
    display: inline;
}

.wa-var-tag.wa-var-missing {
    background: rgba(239, 68, 68, 0.08);
    color: #dc2626;
    border-color: rgba(239, 68, 68, 0.35);
}

.wa-msg-footer {
    font-size: 0.70rem;
    color: #667781;
    margin-top: 6px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 4px;
    font-style: normal;
}

.wa-msg-meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    margin-top: 4px;
    margin-bottom: 2px;
}

.wa-msg-time {
    font-size: 0.65rem;
    color: #667781;
    font-weight: 500;
}

.wa-msg-status-ticks {
    display: inline-flex;
    align-items: center;
}

/* WhatsApp Interactive CTA Buttons Container */
.wa-btn-container {
    display: flex;
    flex-direction: column;
    gap: 1px;
    margin-top: 6px;
    margin-left: -10px;
    margin-right: -10px;
    margin-bottom: -6px;
    border-top: 1px solid #e9edef;
    background: #ffffff;
    border-radius: 0 0 8px 8px;
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

/* WhatsApp Bottom Input Bar */
.wa-phone-bottom-bar {
    background: #efeae2;
    padding: 6px 8px 8px 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.wa-input-pill {
    background: #ffffff;
    border-radius: 24px;
    flex: 1;
    display: flex;
    align-items: center;
    padding: 6px 10px;
    gap: 8px;
    box-shadow: 0 1px 2px rgba(11,20,26,0.1);
}

.wa-input-placeholder {
    font-size: 0.76rem;
    color: #8696a0;
    flex: 1;
    user-select: none;
}

.wa-input-icons {
    display: flex;
    align-items: center;
    gap: 8px;
}

.wa-mic-btn {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #00a884;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(0, 168, 132, 0.4);
}

/* Builder Modal Sticky Footer */
.builder-modal-footer {
    padding: 0.85rem 1.5rem;
    background: var(--bg-card, #ffffff);
    border-top: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-shrink: 0;
    flex-wrap: wrap;
}

.builder-footer-info {
    flex: 1;
    min-width: 250px;
}

.builder-footer-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
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

/* Full Width 2-Column Layout for Individual Quick Broadcast */
.ind-broadcast-layout {
    display: grid;
    grid-template-columns: 1fr 370px;
    gap: 1.5rem;
    align-items: start;
    width: 100%;
}

@media (max-width: 1060px) {
    .ind-broadcast-layout {
        grid-template-columns: 1fr;
    }
}

.ind-composer-card {
    background: var(--bg-card, #ffffff);
    padding: 1.75rem;
    border-radius: 16px;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 4px 20px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.ind-preview-col {
    position: sticky;
    top: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.ind-ai-panel {
    background: linear-gradient(135deg, #f0fdf4 0%, #eff6ff 100%);
    border: 1.5px solid #86efac;
    border-radius: 12px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08);
    transition: all 0.2s ease;
}

.btn-position-pill {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    transition: all 0.15s ease;
    color: #475569;
    user-select: none;
    background: transparent;
}
.btn-position-pill.active {
    background: #2563eb !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
}
.wa-preview-img-top {
    width: 100%;
    max-height: 180px;
    object-fit: cover;
    border-radius: 8px 8px 0 0;
    margin-bottom: 6px;
    display: block;
}
.wa-preview-img-bottom {
    width: 100%;
    max-height: 180px;
    object-fit: cover;
    border-radius: 8px;
    display: block;
}
.wa-doc-preview-card {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(0,0,0,0.05);
    padding: 8px 10px;
    border-radius: 8px;
    margin-bottom: 6px;
    border: 1px solid rgba(0,0,0,0.08);
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
                WhatsApp Broadcast
            </button>
            <?php if ($canManageTemplates): ?>
            <button type="button" class="btn btn-secondary text-xs font-bold" onclick="openCreateTemplateModal()">
                <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                + Interactive Template Builder
            </button>
            <?php endif; ?>
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
            Broadcast &amp; Campaign History
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-individual" onclick="switchMainTab('individual')">
            <i data-lucide="send" style="width: 15px; height: 15px;"></i>
            WhatsApp Broadcast
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
                <?php if ($isAdminUser): ?>
                <button type="button" class="btn btn-secondary text-xs font-semibold" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.04);" onclick="clearAllCampaigns()" title="Clear all campaigns and logs">
                    <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i> Clear History
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary text-xs font-bold" onclick="switchMainTab('individual')">
                    <i data-lucide="plus-circle" style="width: 13px; height: 13px;"></i> + New Broadcast
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
        
        <div class="ind-broadcast-layout">
            
            <!-- LEFT COLUMN: Composer, Recipient & AI Assistant -->
            <div class="ind-composer-card">
                
                <!-- Card Header -->
                <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                            <i data-lucide="send" style="width: 20px; height: 20px; color: #2563eb;"></i>
                            <span>Direct WhatsApp Broadcast</span>
                        </h3>
                        <p class="text-xs text-muted mb-0 mt-1">Send 1-on-1 personalized WhatsApp broadcasts to any customer, select from directory, or upload an Excel list with dynamic variables and anti-ban timer protection.</p>
                    </div>
                    <span class="builder-gateway-badge <?php echo ($activeGateway === 'web_api') ? 'badge-web' : 'badge-meta'; ?>">
                        <i data-lucide="<?php echo ($activeGateway === 'web_api') ? 'zap' : 'shield-check'; ?>" style="width: 12px; height: 12px;"></i>
                        <?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web Gateway' : 'Meta Official WABA Cloud'; ?>
                    </span>
                </div>

                <form id="individualSendForm" onsubmit="handleIndividualSubmit(event)" style="display: flex; flex-direction: column; gap: 1.15rem;">

                    <!-- STEP 1: Template Selection OR AI Generator Mode -->
                    <div style="background: rgba(37,99,235,0.04); border: 1.5px solid rgba(37,99,235,0.2); border-radius: 12px; padding: 16px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 8px; flex-wrap:wrap; gap:8px;">
                            <label class="form-label font-bold text-xs" style="color: var(--primary); display:flex; align-items:center; gap:6px; margin:0;">
                                <i data-lucide="layout-template" style="width:14px;height:14px;"></i> Step 1: Select Message Type / Template *
                            </label>
                            
                            <!-- Quick Mode Toggles: Approved Template vs AI Assistant vs Manual -->
                            <div style="display: inline-flex; background: #e2e8f0; padding: 2px; border-radius: 8px; gap: 2px;">
                                <button type="button" id="indModeBtn-template" class="btn-xs-tab active" onclick="switchIndividualMessageMode('template')">
                                    <i data-lucide="layout-template" style="width:12px;height:12px;"></i> Templates
                                </button>
                                <button type="button" id="indModeBtn-ai" class="btn-xs-tab" onclick="switchIndividualMessageMode('ai')">
                                    <i data-lucide="sparkles" style="width:12px;height:12px; color:#d97706;"></i> Compose with AI
                                </button>
                                <button type="button" id="indModeBtn-custom" class="btn-xs-tab" onclick="switchIndividualMessageMode('custom')">
                                    <i data-lucide="edit-3" style="width:12px;height:12px;"></i> Custom Text
                                </button>
                            </div>
                        </div>

                        <!-- Template Dropdown (visible in template mode) -->
                        <div id="indTemplateSelectWrapper">
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <select id="indTemplateSelect" name="template_slug" class="input-styled text-xs font-semibold" onchange="applyTemplateToIndividual(this.value)">
                                    <option value="">-- Choose Approved Template --</option>
                                    <option value="custom">Custom Message (Type manually)</option>
                                    <option value="ai_compose">Compose with AI Assistant (Gemini)</option>
                                </select>
                                <button type="button" id="indBtnDownloadSample" class="btn btn-secondary text-xs" style="padding: 6px 10px; font-weight:700; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; display: none; align-items:center; gap:5px; white-space:nowrap;" onclick="downloadSampleFileForCurrentTemplate('ind')">
                                    <i data-lucide="file-spreadsheet" style="width:13px;height:13px;"></i> <span>Sample Excel</span>
                                </button>
                            </div>
                            <div id="indTemplateInfoTag" style="margin-top:6px; font-size:0.72rem; color:#059669; display:none; font-weight:600;"></div>
                        </div>

                        <!-- AI SMART PROMPT BOX (Google Gemini AI Assistant) -->
                        <div id="indAiPromptBox" class="ind-ai-panel" style="display: none; margin-top: 10px;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 0.8rem; color: #065f46;">
                                    <i data-lucide="sparkles" style="width: 15px; height: 15px; color: #059669;"></i>
                                    <span>AI Copywriter (Google Gemini)</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <span style="font-size: 0.68rem; color: #475569; font-weight: 600;">Tone:</span>
                                    <select id="indAiToneSelect" class="input-styled text-xs" style="padding: 2px 6px; width: 110px; font-weight: 600; background: white;">
                                        <option value="professional" selected>Professional</option>
                                        <option value="urgent">Urgent Due</option>
                                        <option value="friendly">Friendly</option>
                                        <option value="promotional">Promotional</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="indAiPromptInput" placeholder="Explain message (e.g. 'Payment reminder for outstanding ₹4,500 due on Monday', 'Diwali offer 20% off', 'Marg ERP AMC due notice')..." class="input-styled text-xs" style="flex: 1; background: white;" onkeydown="if(event.key==='Enter'){event.preventDefault();generateIndividualAiMessage();}">
                                <button type="button" id="btnIndAiGenerate" onclick="generateIndividualAiMessage()" class="btn btn-primary text-xs font-bold" style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; padding: 0.65rem 1.15rem; background: #059669; border-color: #059669;">
                                    <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                                    <span>Generate with AI</span>
                                </button>
                            </div>

                            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                <span style="font-size: 0.68rem; color: #065f46; font-weight: 700; text-transform: uppercase;">Presets:</span>
                                <button type="button" class="ai-preset-chip" onclick="fillIndAiPrompt('Outstanding bill payment reminder of Rs 4500 with bank details')">
                                    <i data-lucide="receipt" style="width: 12px; height: 12px; color: #059669;"></i>
                                    <span>Bill Due Alert</span>
                                </button>
                                <button type="button" class="ai-preset-chip" onclick="fillIndAiPrompt('Marg ERP Software AMC renewal reminder before due date')">
                                    <i data-lucide="shield-check" style="width: 12px; height: 12px; color: #2563eb;"></i>
                                    <span>AMC Renewal</span>
                                </button>
                                <button type="button" class="ai-preset-chip" onclick="fillIndAiPrompt('Special festival discount promo of 20 percent on inventory billing renewal')">
                                    <i data-lucide="percent" style="width: 12px; height: 12px; color: #7c3aed;"></i>
                                    <span>Festival Promo</span>
                                </button>
                                <button type="button" class="ai-preset-chip" onclick="fillIndAiPrompt('Warm welcome greetings to newly registered client with helpline number')">
                                    <i data-lucide="user-plus" style="width: 12px; height: 12px; color: #16a34a;"></i>
                                    <span>Welcome Greeting</span>
                                </button>
                                <button type="button" class="ai-preset-chip" onclick="fillIndAiPrompt('Important GST compliance and e-invoicing upgrade notice')">
                                    <i data-lucide="file-check" style="width: 12px; height: 12px; color: #0284c7;"></i>
                                    <span>GST Update</span>
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- STEP 2: Choose Recipient -->
                    <div style="background: rgba(16,185,129,0.04); border: 1px solid rgba(16,185,129,0.25); border-radius: 12px; padding: 16px;">
                        <?php if (!$isAdminUser): ?>
                        <div style="margin-bottom: 10px; padding: 6px 12px; background: rgba(37,99,235,0.08); border: 1px solid rgba(37,99,235,0.2); border-radius: 8px; font-size: 0.74rem; color: #1d4ed8; display: flex; align-items: center; gap: 6px;">
                            <i data-lucide="shield-check" style="width: 14px; height: 14px; flex-shrink: 0; color: #2563eb;"></i>
                            <span><strong>Employee Privacy Active:</strong> Showing only leads assigned to you (<strong><?php echo htmlspecialchars($userName); ?></strong>).</span>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 10px; flex-wrap:wrap; gap:8px;">
                            <label class="form-label font-bold text-xs" style="color:#059669; display:flex; align-items:center; gap:5px; margin:0;">
                                <i data-lucide="user-check" style="width:14px;height:14px;"></i> Step 2: Choose Recipient
                            </label>
                            
                            <!-- Mode Switcher Tabs -->
                            <div style="display: inline-flex; background: #e2e8f0; padding: 2px; border-radius: 8px; gap: 2px;">
                                <button type="button" id="indRecipTab-directory" class="btn-xs-tab active" onclick="switchRecipientSource('directory')">
                                    <i data-lucide="book-user" style="width:12px;height:12px;"></i> Directory / Contacts
                                </button>
                                <button type="button" id="indRecipTab-excel" class="btn-xs-tab" onclick="switchRecipientSource('excel')">
                                    <i data-lucide="file-spreadsheet" style="width:12px;height:12px;"></i> Upload Excel / CSV
                                </button>
                                <button type="button" id="indRecipTab-manual" class="btn-xs-tab" onclick="switchRecipientSource('manual')">
                                    <i data-lucide="edit-3" style="width:12px;height:12px;"></i> Manual Entry
                                </button>
                            </div>
                        </div>

                        <!-- Mode 1: Directory Selection & Contact Picker -->
                        <div id="indRecipientSec-directory" style="display: block;">
                            <div style="display: grid; grid-template-columns: 210px 1fr; gap: 0.75rem; margin-bottom: 8px;">
                                <div>
                                    <label class="form-label font-bold text-xs" style="color:#334155; margin-bottom: 3px;">Select Directory / Source</label>
                                    <select id="indDirectoryFilter" class="input-styled text-xs font-semibold" onchange="handleDirectoryFilterChange(this.value)">
                                        <option value="all">All Directories (Clients &amp; Leads)</option>
                                        <option value="clients">Client Directory (client_directory)</option>
                                        <option value="leads">CRM Sales Leads (leads)</option>
                                        <option value="tenants">Tenant Accounts (tenant_companies)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label font-bold text-xs" style="color:#334155; margin-bottom: 3px;">Select Recipient Contact *</label>
                                    <select id="indContactPicker" class="input-styled text-xs font-semibold" onchange="handleSelectContactIndividual(this.value)">
                                        <option value="">-- Select a contact to load details --</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap: 8px;">
                                <div style="position: relative; flex: 1;">
                                    <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; color: #94a3b8; pointer-events: none;"></i>
                                    <input type="text" id="indContactFilterInput" placeholder="Quick search contact by name, phone, or firm..." class="input-styled text-xs" style="padding: 7px 10px 7px 30px; width: 100%;" oninput="handleContactFilterInput(this.value)">
                                </div>
                                <span id="indContactsBadgeCount" class="badge text-xs" style="background:#e0f2fe; color:#0369a1; font-weight:700; white-space:nowrap; padding: 6px 10px;">0 contacts</span>
                            </div>
                            <!-- Active Selected Contact Card -->
                            <div id="indSelectedContactCard" style="display:none; margin-top: 8px; padding: 7px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; font-size: 0.76rem; color: #065f46; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <i data-lucide="check-circle-2" style="width: 14px; height: 14px; color: #059669;"></i>
                                    <span style="font-weight: 700; color: #047857;">Selected:</span>
                                    <span id="indSelectedContactNameText" style="font-weight: 600; color: #1e293b;"></span>
                                    <span style="color: #94a3b8;">•</span>
                                    <span id="indSelectedContactPhoneText" style="font-family: monospace; font-weight: 700; color: #0f766e;"></span>
                                </div>
                                <span class="badge" style="background: #10b981; color: white; font-size: 0.68rem; font-weight: 700; padding: 2px 7px;">Auto-Filled</span>
                            </div>
                        </div>

                        <!-- Mode 2: Excel / CSV File Upload -->
                        <div id="indRecipientSec-excel" style="display: none;">
                            <div style="background: white; border: 1.5px dashed #059669; border-radius: 8px; padding: 14px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                    <div style="text-align: left;">
                                        <div style="font-weight: 700; font-size: 0.82rem; color: #065f46;">Upload Excel (.xlsx, .xls) or CSV Sheet</div>
                                        <div style="font-size: 0.72rem; color: #64748b;">Columns matching template variables (Phone, Name, Amount, Due Date etc.) will be auto-fetched!</div>
                                    </div>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <button type="button" class="btn btn-secondary text-xs font-bold" style="background:#ecfdf5; color:#059669; border-color:#a7f3d0;" onclick="downloadSampleFileForCurrentTemplate('ind')">
                                            <i data-lucide="download" style="width:12px;height:12px;"></i> Download Sample Excel
                                        </button>
                                        <label class="btn btn-primary text-xs font-bold" style="cursor: pointer; margin:0; background:#059669; border-color:#059669; display:inline-flex; align-items:center; gap:5px;">
                                            <i data-lucide="upload" style="width:12px;height:12px;"></i> Browse File
                                            <input type="file" id="indRecipientFileInput" accept=".xlsx, .xls, .csv" style="display: none;" onchange="handleRecipientSheetUpload(event)">
                                        </label>
                                    </div>
                                </div>

                                <!-- Uploaded Rows Navigator -->
                                <div id="indFileRowsNavigator" style="display: none; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #d1fae5;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                        <div style="font-size: 0.76rem; font-weight: 700; color: #065f46;">
                                            <span id="indFileTotalRowsText">0 contacts loaded from spreadsheet</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 8px;" onclick="navigateSheetRow(-1)">◀ Prev</button>
                                            <select id="indSheetRowPicker" class="input-styled text-xs font-semibold" style="width: auto; max-width: 280px; padding: 3px 6px;" onchange="selectSheetRow(parseInt(this.value))">
                                                <!-- Populated with rows -->
                                            </select>
                                            <button type="button" class="btn btn-secondary text-xs font-bold" style="padding: 2px 8px;" onclick="navigateSheetRow(1)">Next ▶</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mode 3: Manual Direct Entry Info -->
                        <div id="indRecipientSec-manual" style="display: none; padding: 8px 12px; background: rgba(59,130,246,0.06); border-radius: 8px; font-size: 0.76rem; color: #1e40af; border: 1px solid rgba(59,130,246,0.15);">
                            <i data-lucide="info" style="width:14px;height:14px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> Direct Manual Entry: Fill in the recipient's phone number, name, and variable values in the fields below.
                        </div>
                    </div>

                    <!-- Phone + Name fields with live sync to preview -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="form-label font-bold text-xs" style="color: #334155;">Recipient Phone Number *</label>
                            <input type="text" id="indPhoneInput" name="phone" class="input-styled font-bold text-xs" required placeholder="e.g. 9532620736 or +919532620736" oninput="updateIndividualPreview()">
                        </div>
                        <div>
                            <label class="form-label font-bold text-xs" style="color: #334155;">Recipient Name *</label>
                            <input type="text" id="indNameInput" name="name" class="input-styled text-xs" required placeholder="e.g. Rajesh Medical Store" oninput="updateIndividualPreview()">
                        </div>
                    </div>

                    <!-- STEP 3: DYNAMIC VARIABLE INPUTS based on template -->
                    <div id="indDynamicVarsContainer" style="display:none;"></div>

                    <!-- Message Textarea -->
                    <div id="indMessageWrapper">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px;">
                            <label class="form-label font-bold text-xs" style="margin: 0; color: #334155;">Message Text *</label>
                            <span style="font-size: 0.69rem; color: #64748b;">WhatsApp formatting supported (*bold*, _italic_)</span>
                        </div>
                        <textarea id="indMessageText" name="message" class="input-styled text-xs" rows="6" required placeholder="Select a template above, generate with AI, or type custom message..." oninput="updateIndividualPreview()" style="resize: vertical; line-height: 1.5;"></textarea>
                        
                        <!-- 1-Click Variable Pills (inserts at cursor) -->
                        <div style="font-size: 0.72rem; color: #64748b; margin-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                            <span class="font-bold text-slate-700" style="margin-right: 2px;">Insert Variable:</span>
                            <button type="button" class="var-pill-btn" onclick="insertVarToIndBody('{name}')">+ {name}</button>
                            <button type="button" class="var-pill-btn" onclick="insertVarToIndBody('{company}')">+ {company}</button>
                            <button type="button" class="var-pill-btn" onclick="insertVarToIndBody('{phone}')">+ {phone}</button>
                            <button type="button" class="var-pill-btn" onclick="insertVarToIndBody('{amount}')">+ {amount}</button>
                            <button type="button" class="var-pill-btn" onclick="insertVarToIndBody('{due_date}')">+ {due_date}</button>
                        </div>
                    </div>

                    <!-- Media / Document Attachment (Optional) with Image Position Selector -->
                    <div style="background: rgba(14, 165, 233, 0.04); border: 1.5px dashed #0284c7; border-radius: 10px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                            <label class="form-label font-bold text-xs" style="color: #0369a1; margin: 0; display: flex; align-items: center; gap: 6px;">
                                <i data-lucide="paperclip" style="width: 14px; height: 14px;"></i> Attach Media / Document (Optional)
                            </label>
                            <span style="font-size: 0.69rem; color: #64748b;">Image (JPG, PNG, WebP) or Document (PDF, Invoice, Catalog)</span>
                        </div>

                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <input type="file" id="indMediaFileInput" name="media_file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="input-styled text-xs" style="background: white; padding: 6px 10px; flex: 1; min-width: 220px;" onchange="handleMediaFileChange(event)">
                            <button type="button" id="btnRemoveIndMedia" class="btn btn-secondary text-xs font-bold" style="display: none; padding: 6px 12px; color: #ef4444; border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.05);" onclick="clearIndMedia()">
                                <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i> Remove
                            </button>
                        </div>

                        <!-- Image Position Option: Top vs Bottom -->
                        <div id="indMediaPositionWrapper" style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed rgba(2, 132, 199, 0.3); display: none;">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span style="font-size: 0.76rem; font-weight: 700; color: #0369a1; display: flex; align-items: center; gap: 5px;">
                                        <i data-lucide="layout" style="width: 13px; height: 13px;"></i> Image Position in Message:
                                    </span>
                                    <span style="font-size: 0.69rem; color: #64748b;">Choose where the image appears relative to your message text</span>
                                </div>
                                <div style="display: inline-flex; background: #e2e8f0; padding: 2px; border-radius: 8px; gap: 2px;">
                                    <label class="btn-position-pill active" id="pillMediaPosTop" onclick="setMediaPosition('top')">
                                        <input type="radio" name="media_position" id="mediaPosTop" value="top" checked style="display: none;">
                                        <i data-lucide="arrow-up" style="width: 12px; height: 12px;"></i>
                                        <span>Top (Image Header + Text Caption)</span>
                                    </label>
                                    <label class="btn-position-pill" id="pillMediaPosBottom" onclick="setMediaPosition('bottom')">
                                        <input type="radio" name="media_position" id="mediaPosBottom" value="bottom" style="display: none;">
                                        <i data-lucide="arrow-down" style="width: 12px; height: 12px;"></i>
                                        <span>Bottom (Text First, Image Below)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dispatch Timing & Anti-Ban Protection (Timer Option) -->
                    <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <label class="form-label font-bold text-xs" style="color: #334155; margin: 0; display: flex; align-items: center; gap: 6px;">
                                    <i data-lucide="timer" style="width: 15px; height: 15px; color: #2563eb;"></i>
                                    <span>Broadcast Timer / Dispatch Delay</span>
                                </label>
                                <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px;">
                                    Set dispatch countdown timer or safe throttle spacing
                                </div>
                            </div>
                            <select name="delay_seconds" id="indDelaySelect" class="input-styled text-xs font-bold" style="width: auto; min-width: 220px;">
                                <option value="0" selected>⚡ Instant Send (0s Delay)</option>
                                <option value="3">⏱️ 3 Seconds Delay (Quick Check)</option>
                                <option value="5">⏱️ 5 Seconds Delay (Recommended)</option>
                                <option value="10">🛡️ 10 Seconds Delay (Safe Anti-Ban)</option>
                                <option value="15">🛡️ 15 Seconds Delay (Strict Spacing)</option>
                                <option value="30">🛡️ 30 Seconds Delay (Maximum Safe)</option>
                                <option value="60">⏳ 1 Minute Delay (60s)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Anti-Ban Protection: Daily Send Limit / Batch Quota -->
                    <div id="indDailyQuotaCard" style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 12px 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <label class="form-label font-bold text-xs" style="color: #065f46; margin: 0; display: flex; align-items: center; gap: 6px;">
                                    <i data-lucide="shield-alert" style="width: 15px; height: 15px; color: #10b981;"></i>
                                    <span>Daily Send Limit / Batch Quota (Anti-Ban Protection)</span>
                                </label>
                                <div style="font-size: 0.7rem; color: #047857; margin-top: 2px;">
                                    Send fixed quota today (e.g. 50/day), system will auto-pause to protect number from WhatsApp ban!
                                </div>
                            </div>
                            <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                <select name="daily_limit" id="indDailyLimitSelect" class="input-styled text-xs font-bold" style="width: auto; min-width: 230px;" onchange="handleDailyLimitSelectChange(this.value)">
                                    <option value="0" selected>🚀 Send All Contacts (No Daily Limit)</option>
                                    <option value="25">🛡️ Safe Quota: 25 Messages / Day</option>
                                    <option value="50">🛡️ Safe Quota: 50 Messages (Recommended)</option>
                                    <option value="100">🛡️ Safe Quota: 100 Messages / Day</option>
                                    <option value="250">🛡️ Safe Quota: 250 Messages / Day</option>
                                    <option value="500">🛡️ Safe Quota: 500 Messages / Day</option>
                                    <option value="custom">✏️ Custom Limit (Enter Number)...</option>
                                </select>
                                <input type="number" id="indCustomDailyLimitInput" placeholder="Limit" min="1" max="10000" class="input-styled text-xs font-bold" style="width: 90px; display: none;" oninput="handleCustomDailyLimitInput(this.value)">
                            </div>
                        </div>
                        <div id="indQuotaSummaryNote" style="display: none; margin-top: 8px; font-size: 0.72rem; color: #065f46; background: rgba(16, 185, 129, 0.1); padding: 5px 10px; border-radius: 6px; font-weight: 600;"></div>
                    </div>

                    <!-- Send Action Button -->
                    <button type="submit" id="btnSubmitIndividual" class="btn btn-primary text-xs font-bold" style="padding: 0.85rem 1.35rem; background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                        <i data-lucide="send" style="width:15px;height:15px;"></i>
                        <span>Send Direct WhatsApp Broadcast Now</span>
                    </button>
                </form>
            </div>

            <!-- RIGHT COLUMN: Sticky Real-time WhatsApp Phone Simulator -->
            <div class="ind-preview-col">
                <div class="preview-top-badge">
                    <span class="preview-pulse-dot"></span>
                    <span>LIVE WHATSAPP PREVIEW</span>
                </div>

                <div class="wa-phone-mockup" style="height: 560px;">
                    <!-- Phone Top Status Bar & Notch -->
                    <div class="wa-phone-notch">
                        <span><?php echo date('h:i'); ?></span>
                        <div class="notch-island-center">
                            <span class="notch-speaker"></span>
                            <span class="notch-cam"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <i data-lucide="wifi" style="width: 11px; height: 11px;"></i>
                            <i data-lucide="battery" style="width: 13px; height: 13px;"></i>
                        </div>
                    </div>

                    <!-- WhatsApp Top App Bar with Recipient Name -->
                    <div class="wa-phone-header">
                        <div style="display: flex; align-items: center; gap: 4px; cursor: pointer;">
                            <i data-lucide="chevron-left" style="width: 16px; height: 16px; color: white;"></i>
                            <div class="wa-phone-avatar" id="indPreviewAvatar">R</div>
                        </div>
                        <div style="flex: 1; min-width: 0; margin-left: 2px;">
                            <div id="indPreviewRecipientTitle" style="font-size: 0.8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px; color: white;">
                                <span>Rajesh Medical Store</span>
                            </div>
                            <div id="indPreviewRecipientSub" style="font-size: 0.65rem; color: #a7f3d0;">+91 95326 20736 &bull; Online</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; color: white;">
                            <i data-lucide="video" style="width: 15px; height: 15px;"></i>
                            <i data-lucide="phone" style="width: 14px; height: 14px;"></i>
                        </div>
                    </div>

                    <!-- WhatsApp Chat Canvas (Native top-to-bottom scroll) -->
                    <div class="wa-phone-body" id="indPhoneChatBody">
                        <div class="wa-date-divider">Today</div>

                        <div id="indChatBubblesContainer">
                            <!-- Populated dynamically by updateIndividualPreview() -->
                            <div class="wa-msg-bubble" id="indMsgBubblePrimary">
                                <div id="indLiveMediaPreviewTop" style="display: none;"></div>
                                <div class="wa-msg-body-content" id="indLivePreview">Select a template above, generate with AI, or type custom message...</div>
                                <div class="wa-msg-meta">
                                    <span class="wa-msg-time"><?php echo date('h:i A'); ?></span>
                                    <span class="wa-msg-status-ticks">
                                        <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb;"></i>
                                    </span>
                                </div>
                                <!-- Buttons if any -->
                                <div class="wa-btn-container" id="indLiveButtonsPreview" style="display: none;"></div>
                            </div>
                            <div class="wa-msg-bubble" id="indMsgBubbleBottomMedia" style="display: none; margin-top: 6px;">
                                <div id="indLiveMediaPreviewBottom"></div>
                                <div class="wa-msg-meta">
                                    <span class="wa-msg-time"><?php echo date('h:i A'); ?></span>
                                    <span class="wa-msg-status-ticks">
                                        <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb;"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- WhatsApp Bottom Input Bar -->
                    <div class="wa-phone-bottom-bar">
                        <div class="wa-input-pill">
                            <i data-lucide="smile" style="width: 16px; height: 16px; color: #8696a0;"></i>
                            <span class="wa-input-placeholder">Message</span>
                            <div class="wa-input-icons">
                                <i data-lucide="paperclip" style="width: 14px; height: 14px; color: #8696a0;"></i>
                                <i data-lucide="camera" style="width: 14px; height: 14px; color: #8696a0;"></i>
                            </div>
                        </div>
                        <div class="wa-mic-btn">
                            <i data-lucide="mic" style="width: 15px; height: 15px; color: white;"></i>
                        </div>
                    </div>
                </div>

                <!-- Recipient Info Card -->
                <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 12px; font-size: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span style="font-weight: 700; color: #475569;">Target Destination:</span>
                        <span id="indSummaryPhone" style="font-weight: 700; color: #0284c7;">+91 9532620736</span>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; color: #64748b; font-size: 0.72rem;">
                        <span>Sending Engine:</span>
                        <span style="font-weight: 600; color: #059669;"><?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web Gateway' : 'Meta Official WABA Cloud'; ?></span>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- TAB 4: BULK CAMPAIGN CREATOR -->
    <div id="tabContent-bulk" class="tab-content-panel" style="display: none;">
        <div style="display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start;">
            
            <!-- LEFT COLUMN: Bulk Campaign Form -->
            <div style="background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
                <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                    <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="send" style="width: 18px; height: 18px; color: var(--primary);"></i>
                        <span>Launch New Bulk Campaign</span>
                    </h3>
                    <p class="text-xs text-muted mb-0 mt-1">Broadcast mass AMC reminders, billing alerts, or promos to client segments or custom uploaded Excel/CSV lists.</p>
                </div>

                <form id="bulkCampaignForm" onsubmit="handleBulkCampaignSubmit(event)" style="display: flex; flex-direction: column; gap: 1.1rem;">
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
                                <option value="csv">Upload Custom Excel / CSV List</option>
                            </select>
                        </div>

                        <div>
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                                <label class="form-label font-bold text-xs" style="margin:0;">Select Template *</label>
                                <button type="button" id="bulkBtnDownloadSample" class="btn btn-secondary text-xs" style="padding: 2px 8px; font-weight:700; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; display: none; align-items:center; gap:4px;" onclick="downloadSampleFileForCurrentTemplate('bulk')">
                                    <i data-lucide="file-spreadsheet" style="width:12px;height:12px;"></i> <span>Sample Excel</span>
                                </button>
                            </div>
                            <select name="template_name" id="bulkTemplateSelect" class="input-styled text-xs font-semibold" onchange="applyTemplateToBulk(this.value)">
                                <option value="">-- Choose Approved Template from Database --</option>
                                <option value="custom">Custom Text Message</option>
                            </select>
                            <div id="bulkTemplateInfoTag" style="margin-top:6px; font-size:0.72rem; color:#059669; display:none; font-weight:600;"></div>
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

                    <!-- CSV / Excel File Picker with In-Browser SheetJS Parser -->
                    <div id="csvUploadWrapper" style="display: none; background: rgba(59,130,246,0.04); border: 1.5px dashed #3b82f6; padding: 1.25rem; border-radius: 10px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <div style="font-weight: 700; font-size: 0.85rem; color: #1d4ed8; display:flex; align-items:center; gap:6px;">
                                    <i data-lucide="file-spreadsheet" style="width:16px;height:16px;"></i> Upload Excel (.xlsx, .xls) or CSV Sheet
                                </div>
                                <div style="font-size: 0.72rem; color: #64748b; margin-top:2px;">
                                    Columns matching template variables (<span style="font-family:monospace; font-weight:600;">phone, name, var_1, var_2...</span>) will be auto-mapped accurately per row!
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary text-xs font-bold" style="padding: 5px 12px; background:#eff6ff; color:#2563eb; border-color:#bfdbfe; display:inline-flex; align-items:center; gap:5px;" onclick="downloadSampleFileForCurrentTemplate('bulk')">
                                <i data-lucide="file-spreadsheet" style="width:14px;height:14px;"></i> <span>Download Sample Excel</span>
                            </button>
                        </div>

                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="file" id="bulkRecipientFileInput" accept=".xlsx, .xls, .csv" class="input-styled text-xs" style="background: white;" onchange="handleBulkSheetUpload(event)">
                        </div>

                        <input type="hidden" name="parsed_contacts_json" id="bulkParsedContactsJson">

                        <!-- Loaded Spreadsheet Status & Row Preview Table -->
                        <div id="bulkFileStatusCard" style="display:none; margin-top:10px; background:white; border:1px solid #bfdbfe; border-radius:8px; padding:10px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
                                <div style="display:flex; align-items:center; gap:6px; font-weight:700; font-size:0.78rem; color:#1e40af;">
                                    <i data-lucide="check-circle-2" style="width:14px;height:14px; color:#2563eb;"></i>
                                    <span id="bulkFileNameText">filename.xlsx</span>
                                </div>
                                <span id="bulkFileCountBadge" class="badge" style="background:#dbeafe; color:#1d4ed8; font-weight:700; font-size:0.7rem; padding:3px 8px;">0 contacts</span>
                            </div>
                            <div id="bulkFilePreviewSnippet"></div>
                        </div>
                    </div>

                    <!-- Dynamic Variable Inputs for Bulk (populated by JS after template selection) -->
                    <div id="bulkDynamicVarsContainer" style="display:none;">
                        <!-- Filled by JS based on selected template variables -->
                    </div>

                    <!-- Custom Text Message Box -->
                    <div id="customMsgWrapper" style="display: none;">
                        <label class="form-label font-bold text-xs">Custom Broadcast Message</label>
                        <textarea name="custom_message" id="bulkCustomMsgTextarea" class="input-styled text-xs" rows="4" placeholder="Enter custom message text... Variables supported: {name}, {company}, {amount}, {due_date}" oninput="updateBulkPreview()"></textarea>
                    </div>

                    <!-- Media / Document Attachment for Bulk Campaign (Optional) -->
                    <div style="background: rgba(14, 165, 233, 0.04); border: 1.5px dashed #0284c7; border-radius: 8px; padding: 10px 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 5px;">
                            <label class="form-label font-bold text-xs" style="color: #0369a1; margin: 0; display: flex; align-items: center; gap: 5px;">
                                <i data-lucide="paperclip" style="width: 13px; height: 13px;"></i> Attach Campaign Media / Brochure / Document (Optional)
                            </label>
                            <span style="font-size: 0.69rem; color: #64748b;">Image (JPG, PNG), PDF, or Document</span>
                        </div>
                        <input type="file" name="media_file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" class="input-styled text-xs" style="background: white; padding: 5px 8px;">
                    </div>

                    <!-- Sending Delay Speed & Gateway Indicator -->
                    <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-app); padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--border-color); flex-wrap:wrap; gap:10px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <label class="form-label font-bold text-xs mb-0">Sending Delay Speed (Time Gap)</label>
                                <?php if ($activeGateway === 'web_api'): ?>
                                    <span class="badge" style="background:#fef3c7; color:#b45309; font-size:0.65rem; font-weight:700; border:1px solid #fde68a;">
                                        <i data-lucide="shield-alert" style="width:10px;height:10px;display:inline;"></i> Anti-Ban Spacing Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#ecfdf5; color:#047857; font-size:0.65rem; font-weight:700; border:1px solid #a7f3d0;">
                                        <i data-lucide="check-circle" style="width:10px;height:10px;display:inline;"></i> Meta Cloud Direct
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 0.7rem; color: #6b7280; margin-top:2px;">Pause interval between each WhatsApp message dispatch</div>
                        </div>

                        <select name="delay_seconds" id="bulkDelaySelect" class="input-styled text-xs font-semibold" style="width: 220px;" onchange="updateBulkCampaignSummary()">
                            <?php if ($activeGateway === 'web_api'): ?>
                                <option value="5" selected>5 Seconds (Recommended - Safe)</option>
                                <option value="10">10 Seconds (Safe Spacing - Anti-Ban)</option>
                                <option value="15">15 Seconds (High Volume Safe)</option>
                                <option value="20">20 Seconds (Strict Spacing)</option>
                                <option value="30">30 Seconds (Max Safe)</option>
                                <option value="60">60 Seconds (1 Minute Gap)</option>
                            <?php else: ?>
                                <option value="1">1 Second (Ultra Fast Direct)</option>
                                <option value="2">2 Seconds (Fast Pacing)</option>
                                <option value="5" selected>5 Seconds (Recommended Pacing)</option>
                                <option value="10">10 Seconds (Support Queue Friendly)</option>
                                <option value="30">30 Seconds (Conservative)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <button type="submit" id="btnSubmitBulk" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white; padding: 0.75rem 1.25rem;">
                        <i data-lucide="play" style="width:14px;height:14px;"></i> Create &amp; Initialize Campaign
                    </button>
                </form>
            </div>

            <!-- RIGHT COLUMN: Sticky Real-time WhatsApp Phone Simulator for Bulk -->
            <div class="ind-preview-col">
                <div class="preview-top-badge">
                    <span class="preview-pulse-dot"></span>
                    <span>LIVE WHATSAPP PREVIEW</span>
                </div>

                <div class="wa-phone-mockup" style="height: 560px;">
                    <!-- Phone Top Status Bar & Notch -->
                    <div class="wa-phone-notch">
                        <span><?php echo date('h:i'); ?></span>
                        <div class="notch-island-center">
                            <span class="notch-speaker"></span>
                            <span class="notch-cam"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <i data-lucide="wifi" style="width: 11px; height: 11px;"></i>
                            <i data-lucide="battery" style="width: 13px; height: 13px;"></i>
                        </div>
                    </div>

                    <!-- WhatsApp Top App Bar with Audience Sample Name -->
                    <div class="wa-phone-header">
                        <div style="display: flex; align-items: center; gap: 4px; cursor: pointer;">
                            <i data-lucide="chevron-left" style="width: 16px; height: 16px; color: white;"></i>
                            <div class="wa-phone-avatar" id="bulkPreviewAvatar">R</div>
                        </div>
                        <div style="flex: 1; min-width: 0; margin-left: 2px;">
                            <div id="bulkPreviewRecipientTitle" style="font-size: 0.8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px; color: white;">
                                <span>Rajesh Medical Store (Sample)</span>
                            </div>
                            <div id="bulkPreviewRecipientSub" style="font-size: 0.65rem; color: #a7f3d0;">+91 95326 20736 &bull; Online</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; color: white;">
                            <i data-lucide="video" style="width: 15px; height: 15px;"></i>
                            <i data-lucide="phone" style="width: 14px; height: 14px;"></i>
                        </div>
                    </div>

                    <!-- WhatsApp Chat Canvas -->
                    <div class="wa-phone-body" id="bulkPhoneChatBody">
                        <div class="wa-date-divider">Today</div>

                        <!-- Message Bubble -->
                        <div class="wa-msg-bubble">
                            <div class="wa-msg-body-content" id="bulkLivePreview">Select an approved template or custom message to preview...</div>
                            <div class="wa-msg-meta">
                                <span class="wa-msg-time"><?php echo date('h:i A'); ?></span>
                                <span class="wa-msg-status-ticks">
                                    <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb;"></i>
                                </span>
                            </div>
                            <div class="wa-btn-container" id="bulkLiveButtonsPreview" style="display: none;"></div>
                        </div>
                    </div>

                    <!-- WhatsApp Bottom Input Bar -->
                    <div class="wa-phone-bottom-bar">
                        <div class="wa-input-pill">
                            <i data-lucide="smile" style="width: 16px; height: 16px; color: #8696a0;"></i>
                            <span class="wa-input-placeholder">Broadcast Message</span>
                            <div class="wa-input-icons">
                                <i data-lucide="paperclip" style="width: 14px; height: 14px; color: #8696a0;"></i>
                            </div>
                        </div>
                        <div class="wa-mic-btn">
                            <i data-lucide="mic" style="width: 15px; height: 15px; color: white;"></i>
                        </div>
                    </div>
                </div>

                <!-- Bulk Campaign Summary Card -->
                <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; font-size: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02); display: flex; flex-direction: column; gap: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 700; color: #475569;">Target Audience:</span>
                        <span id="bulkSummaryAudience" style="font-weight: 700; color: #0284c7;">All Existing Clients (499 Contacts)</span>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="color: #64748b;">Sending Engine:</span>
                        <span style="font-weight: 600; color: <?php echo ($activeGateway === 'web_api') ? '#d97706' : '#059669'; ?>;">
                            <?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web Gateway' : 'Meta Official WABA Cloud'; ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px dashed #e2e8f0; padding-top: 6px;">
                        <span style="color: #64748b;">Estimated Duration:</span>
                        <span id="bulkSummaryEstTime" style="font-weight: 700; color: #1e293b;">~41.5 mins</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- INTERACTIVE TEMPLATE BUILDER & LIVE PHONE SIMULATOR MODAL -->
<div class="modal-overlay" id="interactiveTemplateModal">
    <div class="modal-box-lg">
        
        <!-- TOP STICKY HEADER -->
        <div class="builder-modal-header">
            <div class="builder-modal-title-group">
                <div class="builder-modal-icon">
                    <i data-lucide="layout-template" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-main);" id="builderModalHeaderTitle">Interactive WhatsApp Template Builder</h3>
                        <span class="builder-gateway-badge <?php echo ($activeGateway === 'web_api') ? 'badge-web' : 'badge-meta'; ?>">
                            <i data-lucide="<?php echo ($activeGateway === 'web_api') ? 'zap' : 'shield-check'; ?>" style="width: 12px; height: 12px;"></i>
                            <?php echo ($activeGateway === 'web_api') ? 'WhatsApp Web (Instant Ready)' : 'Meta Cloud API (Official Approval)'; ?>
                        </span>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">
                        Design custom templates, generate copy with Google Gemini AI &amp; preview on real WhatsApp screen.
                    </div>
                </div>
            </div>
            <button type="button" class="btn-icon" onclick="closeCreateTemplateModal()" style="font-size: 1.4rem; background: none; border: none; cursor: pointer; color: var(--text-muted); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.15s ease;" title="Close Modal">&times;</button>
        </div>

        <!-- MAIN 2-COLUMN CONTENT -->
        <div class="builder-modal-content">
            
            <!-- Left Side: Template Composer Form & AI Assistant -->
            <div class="builder-col-form">
                
                <!-- AI Smart Copywriter Card (Google Gemini AI Brain) -->
                <div class="ai-assistant-card">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 7px; font-weight: 700; font-size: 0.82rem; color: #1d4ed8;">
                            <i data-lucide="sparkles" style="width: 16px; height: 16px; color: #2563eb;"></i>
                            <span>AI Smart Copywriter (Google Gemini 3.5 Flash)</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.7rem; color: #64748b; font-weight: 600;">Tone:</span>
                            <select id="aiToneSelect" class="input-styled text-xs" style="padding: 3px 8px; width: 115px; font-weight: 600;">
                                <option value="professional" selected>Professional</option>
                                <option value="urgent">Urgent Due</option>
                                <option value="friendly">Friendly</option>
                                <option value="promotional">Promotional</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="aiPromptInput" placeholder="Type prompt (e.g. 'for jewellery marketing', 'pharma stock discount', 'AMC renewal due')..." class="input-styled text-xs" style="flex: 1;" onkeydown="if(event.key==='Enter'){event.preventDefault();triggerAiTemplateGeneration();}">
                        <button type="button" id="btnAiGenerate" onclick="triggerAiTemplateGeneration()" class="btn btn-primary text-xs font-bold" style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; padding: 0.65rem 1.15rem; background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 2px 6px rgba(37,99,235,0.25);">
                            <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                            <span>Generate with AI</span>
                        </button>
                    </div>

                    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                        <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;">Presets:</span>
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
                <form id="createTemplateForm" onsubmit="handleTemplateSaveSubmit(event)" style="display: flex; flex-direction: column; gap: 0.95rem;">
                    <input type="hidden" name="template_id" id="builderTemplateId" value="0">
                    <!-- Target Channel / Gateway Type Selector -->
                    <div style="background: var(--bg-card, #ffffff); border: 1.5px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 0.85rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.55rem;">
                            <label class="form-label font-bold text-xs" style="margin-bottom: 0; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                <i data-lucide="radio" style="width: 14px; height: 14px; color: #2563eb;"></i>
                                <span>Target Gateway / Channel Type *</span>
                            </label>
                            <span style="font-size: 0.68rem; color: #64748b; font-weight: 600;">Choose where this template will be deployed</span>
                        </div>

                        <!-- 3-Way Segmented Radio Card Grid -->
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem;">
                            <!-- Option 1: WhatsApp Web API -->
                            <div id="card-gateway-web_api" class="template-gateway-card" onclick="selectTemplateGateway('web_api')" style="position: relative; border: 1.5px solid #2563eb; background: rgba(37,99,235,0.05); border-radius: 10px; padding: 0.65rem 0.75rem; cursor: pointer; transition: all 0.2s ease; display: flex; flex-direction: column; gap: 4px;">
                                <input type="radio" name="gateway_target" value="web_api" id="radioGatewayWeb" checked style="position: absolute; opacity: 0; pointer-events: none;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(37,99,235,0.15); display: flex; align-items: center; justify-content: center; color: #2563eb;">
                                            <i data-lucide="smartphone" style="width: 13px; height: 13px;"></i>
                                        </div>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #1e293b;">Web API</span>
                                    </div>
                                    <span class="badge" style="font-size: 0.6rem; padding: 2px 5px; background: #dbeafe; color: #1d4ed8; font-weight: 700; border-radius: 4px;">Instant</span>
                                </div>
                                <p style="margin: 0; font-size: 0.67rem; color: #64748b; line-height: 1.35;">No Meta approval needed. Broadcast directly via paired WhatsApp.</p>
                            </div>

                            <!-- Option 2: Meta Cloud API (Official WABA) -->
                            <div id="card-gateway-meta" class="template-gateway-card" onclick="selectTemplateGateway('meta')" style="position: relative; border: 1.5px solid var(--border-color, #e2e8f0); background: var(--bg-card, #ffffff); border-radius: 10px; padding: 0.65rem 0.75rem; cursor: pointer; transition: all 0.2s ease; display: flex; flex-direction: column; gap: 4px;">
                                <input type="radio" name="gateway_target" value="meta" id="radioGatewayMeta" style="position: absolute; opacity: 0; pointer-events: none;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(16,185,129,0.15); display: flex; align-items: center; justify-content: center; color: #059669;">
                                            <i data-lucide="shield-check" style="width: 13px; height: 13px;"></i>
                                        </div>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #1e293b;">Meta WABA</span>
                                    </div>
                                    <span class="badge" style="font-size: 0.6rem; padding: 2px 5px; background: #d1fae5; color: #065f46; font-weight: 700; border-radius: 4px;">Review</span>
                                </div>
                                <p style="margin: 0; font-size: 0.67rem; color: #64748b; line-height: 1.35;">Official Meta Cloud approval with WhatsApp verified green tick.</p>
                            </div>

                            <!-- Option 3: Universal (Both) -->
                            <div id="card-gateway-universal" class="template-gateway-card" onclick="selectTemplateGateway('universal')" style="position: relative; border: 1.5px solid var(--border-color, #e2e8f0); background: var(--bg-card, #ffffff); border-radius: 10px; padding: 0.65rem 0.75rem; cursor: pointer; transition: all 0.2s ease; display: flex; flex-direction: column; gap: 4px;">
                                <input type="radio" name="gateway_target" value="universal" id="radioGatewayUniversal" style="position: absolute; opacity: 0; pointer-events: none;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <div style="width: 24px; height: 24px; border-radius: 6px; background: rgba(147,51,234,0.15); display: flex; align-items: center; justify-content: center; color: #9333ea;">
                                            <i data-lucide="layers" style="width: 13px; height: 13px;"></i>
                                        </div>
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #1e293b;">Universal</span>
                                    </div>
                                    <span class="badge" style="font-size: 0.6rem; padding: 2px 5px; background: #f3e8ff; color: #7e22ce; font-weight: 700; border-radius: 4px;">Dual</span>
                                </div>
                                <p style="margin: 0; font-size: 0.67rem; color: #64748b; line-height: 1.35;">Instant on Web API + simultaneously submitted to Meta Cloud.</p>
                            </div>
                        </div>
                    </div>

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
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <label class="form-label font-bold text-xs" style="margin-bottom: 0; color: #334155;">Header Title Text (Optional)</label>
                            <span style="font-size: 0.68rem; color: #94a3b8;">Bold title header at top of message</span>
                        </div>
                        <input type="text" name="header_text" id="builderHeaderText" class="input-styled text-xs" placeholder="e.g. Marg ERP Official Notice" oninput="updateLivePhoneMockup()">
                    </div>

                    <div>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <label class="form-label font-bold text-xs" style="margin-bottom: 0; color: #334155;">Body Text *</label>
                            <span style="font-size: 0.7rem; color: #64748b;">WhatsApp formatting supported (*bold*, _italic_)</span>
                        </div>
                        <textarea name="body_text" id="builderBodyText" class="input-styled text-xs" rows="6" required placeholder="Type template text... Click variables below to insert dynamic values." oninput="updateLivePhoneMockup()" style="resize: vertical; line-height: 1.5;"></textarea>
                        
                        <!-- 1-Click Variable Pills (inserts at cursor) -->
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
                </form>
            </div>

            <!-- Right Side: Real-time Live WhatsApp Phone Simulator -->
            <div class="builder-col-preview">
                <div class="preview-top-badge">
                    <span class="preview-pulse-dot"></span>
                    <span>REAL-TIME WHATSAPP PREVIEW</span>
                </div>
                
                <div class="wa-phone-mockup">
                    <!-- Phone Top Status Bar & Notch -->
                    <div class="wa-phone-notch">
                        <span><?php echo date('h:i'); ?></span>
                        <div class="notch-island-center">
                            <span class="notch-speaker"></span>
                            <span class="notch-cam"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <i data-lucide="wifi" style="width: 11px; height: 11px;"></i>
                            <i data-lucide="battery" style="width: 13px; height: 13px;"></i>
                        </div>
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
                                <i data-lucide="badge-check" style="width: 14px; height: 14px; color: #6ee7b7; flex-shrink: 0;"></i>
                            </div>
                            <div style="font-size: 0.65rem; color: #a7f3d0;">Official Business Account</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; color: white;">
                            <i data-lucide="video" style="width: 15px; height: 15px;"></i>
                            <i data-lucide="phone" style="width: 14px; height: 14px;"></i>
                        </div>
                    </div>

                    <!-- WhatsApp Chat Background Canvas (Native top-to-bottom scroll, NO clipping!) -->
                    <div class="wa-phone-body" id="phoneChatBody">
                        <div class="wa-date-divider">Today</div>

                        <!-- Message Bubble -->
                        <div class="wa-msg-bubble">
                            <div class="wa-msg-header" id="mockupHeader">Marg ERP Software AMC Notice</div>
                            <div class="wa-msg-body-content" id="mockupBody">Dear Rajesh Medical Store,<br><br>Your Marg ERP Software AMC renewal of <b>₹3,500</b> is due on <b>25 Aug 2026</b>.<br><br>To ensure uninterrupted billing &amp; GST filings, kindly renew your AMC.<br><br>Helpline: <b>9532620736</b></div>
                            <div class="wa-msg-footer" id="mockupFooter">Marg Soft Solution Support Desk</div>
                            <div class="wa-msg-meta">
                                <span class="wa-msg-time"><?php echo date('h:i A'); ?></span>
                                <span class="wa-msg-status-ticks">
                                    <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb;"></i>
                                </span>
                            </div>
                            
                            <!-- Interactive Buttons Container -->
                            <div class="wa-btn-container" id="mockupButtons">
                                <button type="button" class="wa-interactive-btn">
                                    <i data-lucide="corner-down-left" style="width: 12px; height: 12px;"></i>
                                    <span>Pay AMC Online</span>
                                </button>
                                <button type="button" class="wa-interactive-btn">
                                    <i data-lucide="corner-down-left" style="width: 12px; height: 12px;"></i>
                                    <span>Request Callback</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- WhatsApp Bottom Chat Input Bar -->
                    <div class="wa-phone-bottom-bar">
                        <div class="wa-input-pill">
                            <i data-lucide="smile" style="width: 16px; height: 16px; color: #8696a0;"></i>
                            <span class="wa-input-placeholder">Message</span>
                            <div class="wa-input-icons">
                                <i data-lucide="paperclip" style="width: 14px; height: 14px; color: #8696a0;"></i>
                                <i data-lucide="camera" style="width: 14px; height: 14px; color: #8696a0;"></i>
                            </div>
                        </div>
                        <div class="wa-mic-btn">
                            <i data-lucide="mic" style="width: 15px; height: 15px; color: white;"></i>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- BOTTOM STICKY FOOTER -->
        <div class="builder-modal-footer">
            <div class="builder-footer-info" id="builderGatewayInfo">
                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.74rem; color: #1e40af;">
                    <i data-lucide="check-circle-2" style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0;"></i>
                    <span><strong>WhatsApp Web API Active:</strong> Template is saved locally for immediate reuse. No Meta approval delay.</span>
                </div>
            </div>

            <div class="builder-footer-actions">
                <button type="button" class="btn btn-secondary text-xs font-bold" onclick="closeCreateTemplateModal()" style="display: inline-flex; align-items: center; gap: 5px; padding: 0.65rem 1.15rem;">
                    <i data-lucide="x" style="width: 13px; height: 13px;"></i> Cancel
                </button>
                <button type="button" id="btnSubmitTemplateModal" onclick="document.getElementById('createTemplateForm').requestSubmit()" class="btn btn-primary text-xs font-bold" style="display: inline-flex; align-items: center; gap: 6px; padding: 0.65rem 1.35rem; background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                    <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                    <span id="btnSubmitTemplateModalText">Save Template to Library</span>
                </button>
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

<!-- SheetJS (XLSX) Library for In-Browser Instant Excel / CSV Parsing and Generation -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<!-- GSAP Animation Engine for High-Performance UI Micro-Interactions -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>

<script>
const currentUserRole = "<?php echo $_SESSION['user_role'] ?? 'Executive'; ?>";
const isAdminUser = <?php echo ($isAdminUser ? 'true' : 'false'); ?>;
const canManageTemplates = true;

let runningCampaignIds = new Set();
let activeLoopInterval = null;
let aiStreamInterval = null;
let savedTemplatesList = [];
let loadedContactsList = [];
let currentDirectoryFilter = 'all';
let currentRecipientMode = 'directory';
let sheetParsedRows = [];
let bulkParsedRows = [];
let currentSheetRowIndex = 0;
let activeSelectedContact = null;
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
    activeLoopInterval = setInterval(runActiveCampaignsLoop, 1000);

    const indMsg = document.getElementById('indMessageText');
    if (indMsg) {
        indMsg.addEventListener('input', updateIndividualPreview);
    }

    // GSAP Initial Page Entrance Animation
    if (window.gsap) {
        gsap.from('.campaign-header-card', { y: -16, opacity: 0, duration: 0.45, ease: 'power2.out' });
        gsap.from('.stat-card', { y: 16, opacity: 0, duration: 0.4, stagger: 0.08, ease: 'power2.out', delay: 0.1 });
        gsap.from('#tabContent-templates', { y: 12, opacity: 0, duration: 0.4, ease: 'power2.out', delay: 0.2 });
    }
});

function switchMainTab(tabName) {
    if (tabName === 'bulk') tabName = 'individual';
    ['templates', 'campaigns', 'individual'].forEach(t => {
        const btn = document.getElementById('tabHead-' + t);
        const content = document.getElementById('tabContent-' + t);
        if (btn) btn.classList.remove('active');
        if (content) content.style.display = 'none';
    });
    const bulkContent = document.getElementById('tabContent-bulk');
    if (bulkContent) bulkContent.style.display = 'none';

    const activeBtn = document.getElementById('tabHead-' + tabName);
    const activeContent = document.getElementById('tabContent-' + tabName);
    if (activeBtn) activeBtn.classList.add('active');
    if (activeContent) {
        activeContent.style.display = 'block';
        if (window.gsap) {
            gsap.fromTo(activeContent, 
                { opacity: 0, y: 14 }, 
                { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out' }
            );
        }
    }

    if (tabName === 'templates') fetchTemplates();
    if (tabName === 'campaigns') fetchCampaigns();
    if (tabName === 'individual') {
        updateIndividualPreview();
        if (window.lucide) lucide.createIcons();
    }
}

// Media upload state & helpers for WhatsApp Broadcast
let currentIndMedia = {
    type: null, // 'image' | 'pdf' | 'document'
    url: null,
    name: null,
    sizeText: null
};

function handleMediaFileChange(e) {
    const file = e.target.files?.[0];
    const posWrapper = document.getElementById('indMediaPositionWrapper');
    const removeBtn = document.getElementById('btnRemoveIndMedia');

    if (!file) {
        clearIndMedia();
        return;
    }

    const fName = file.name;
    const fExt = fName.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(fExt) || (file.type && file.type.startsWith('image/'));
    const isPdf = fExt === 'pdf' || file.type === 'application/pdf';

    const sizeKb = Math.round(file.size / 1024);
    const sizeStr = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) + ' MB' : sizeKb + ' KB';

    if (removeBtn) removeBtn.style.display = 'inline-flex';

    if (isImage) {
        currentIndMedia.type = 'image';
        currentIndMedia.name = fName;
        currentIndMedia.sizeText = sizeStr;
        if (posWrapper) posWrapper.style.display = 'block';

        const reader = new FileReader();
        reader.onload = function(evt) {
            currentIndMedia.url = evt.target.result;
            updateIndividualPreview();
        };
        reader.readAsDataURL(file);
    } else {
        currentIndMedia.type = isPdf ? 'pdf' : 'document';
        currentIndMedia.name = fName;
        currentIndMedia.sizeText = sizeStr;
        currentIndMedia.url = null;
        if (posWrapper) posWrapper.style.display = 'none';
        updateIndividualPreview();
    }
}

function clearIndMedia() {
    const input = document.getElementById('indMediaFileInput');
    if (input) input.value = '';
    const posWrapper = document.getElementById('indMediaPositionWrapper');
    if (posWrapper) posWrapper.style.display = 'none';
    const removeBtn = document.getElementById('btnRemoveIndMedia');
    if (removeBtn) removeBtn.style.display = 'none';

    currentIndMedia = { type: null, url: null, name: null, sizeText: null };
    updateIndividualPreview();
}

function setMediaPosition(pos) {
    const rTop = document.getElementById('mediaPosTop');
    const rBottom = document.getElementById('mediaPosBottom');
    const pillTop = document.getElementById('pillMediaPosTop');
    const pillBottom = document.getElementById('pillMediaPosBottom');

    if (pos === 'bottom') {
        if (rBottom) rBottom.checked = true;
        if (pillBottom) pillBottom.classList.add('active');
        if (pillTop) pillTop.classList.remove('active');
    } else {
        if (rTop) rTop.checked = true;
        if (pillTop) pillTop.classList.add('active');
        if (pillBottom) pillBottom.classList.remove('active');
    }
    updateIndividualPreview();
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
        const isUniversal = (t.gateway_origin === 'universal');

        let statusBadge = '';
        if (isUniversal) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(147,51,234,0.15); color:#9333ea; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="layers" style="width:12px;height:12px;"></i> Universal (Web + Meta)</span>`;
        } else if (isWeb) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(59,130,246,0.15); color:#2563eb; font-weight:700; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="smartphone" style="width:12px;height:12px;"></i> Web API Ready</span>`;
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
            <div class="template-footer-actions">
                <button type="button" class="btn btn-primary text-xs font-bold" style="padding: 4px 10px;" onclick="useTemplateInSingle('${escapeHtml(t.slug)}')">
                    <i data-lucide="send" style="width:12px;height:12px;"></i> Single
                </button>
                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white; padding: 4px 10px;" onclick="useTemplateInBulk('${escapeHtml(t.slug)}')">
                    <i data-lucide="users" style="width:12px;height:12px;"></i> Bulk
                </button>
                <button type="button" class="btn btn-secondary text-xs font-bold" style="padding: 4px 8px; color:#2563eb; display:inline-flex; align-items:center; gap:4px; border:1px solid #bfdbfe; background:rgba(37,99,235,0.06);" onclick="editTemplate(${t.id})" title="Edit / Modify Template">
                    <i data-lucide="pencil" style="width:12px;height:12px;"></i> Edit
                </button>
                ${isAdminUser ? `
                <button type="button" class="btn btn-secondary text-xs" style="padding: 4px 8px; color:#ef4444;" onclick="deleteTemplate(${t.id})" title="Delete Template">
                    <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                </button>
                ` : ''}
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

    let indHtml = `<option value="">-- Choose Approved Template --</option>
<option value="custom">Custom Message (Type manually)</option>
<option value="ai_compose">Compose with AI Assistant (Gemini)</option>`;
    let bulkHtml = `<option value="">-- Choose Approved Template from Database --</option>`;

    if (Array.isArray(list) && list.length > 0) {
        list.forEach(t => {
            let originLabel = '';
            if (t.gateway_origin === 'web_api') originLabel = ' (Web API)';
            else if (t.gateway_origin === 'universal') originLabel = ' (Universal)';
            else if (t.gateway_origin === 'meta') originLabel = ' (Meta WABA)';

            const statusLabel = (t.meta_status === 'APPROVED') ? ' [Approved]' : ((t.meta_status === 'PENDING') ? ' [In Review]' : '');
            indHtml += `<option value="${escapeHtml(t.slug)}">${escapeHtml(t.title)}${originLabel}${statusLabel}</option>`;
            bulkHtml += `<option value="${escapeHtml(t.slug)}">${escapeHtml(t.title)}${originLabel}${statusLabel}</option>`;
        });
    }

    bulkHtml += `<option value="custom">  Custom Text Message</option>`;

    if (indSelect) indSelect.innerHTML = indHtml;
    if (bulkSelect) bulkSelect.innerHTML = bulkHtml;
}

// Extract unique variable names from a template body string
function extractTemplateVars(bodyText) {
    if (!bodyText) return { named: [], positional: [] };
    // Positional variables: strictly double braces {{1}}, {{2}}, {{3}}...
    const positionalMatches = [...bodyText.matchAll(/\{\{(\d+)\}\}/g)].map(m => m[1]);
    const positional = [...new Set(positionalMatches)].sort((a, b) => parseInt(a) - parseInt(b));
    
    // Strip all {{...}} first so they can NEVER be falsely matched as single-brace named variables
    const withoutDoubleBraces = bodyText.replace(/\{\{\d+\}\}/g, '');
    
    // Named variables: single-brace words like {name}, {company}, {amount}, {due_date}
    const namedMatches = [...withoutDoubleBraces.matchAll(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)].map(m => m[1]);
    const named = [...new Set(namedMatches)];
    
    return { named, positional };
}

// Friendly label map for named variables
const VAR_LABELS = {
    company:  'Firm / Company Name',
    amount:   'Amount (e.g. ₹3,500)',
    due_date: 'Due Date (e.g. 25 Aug 2026)',
    product:  'Product Name',
    date:     'Date',
    city:     'City',
    helpline: 'Helpline Number',
    invoice:  'Invoice Number',
    balance:  'Balance Amount',
    expiry:   'Expiry Date'
};

function getPositionalVarLabel(n) {
    const map = {
        '1': 'Merchant / Sender Firm',
        '2': 'Customer Name',
        '3': 'Invoice Number',
        '4': 'Bill Amount (₹)',
        '5': 'Ledger Balance (₹)',
        '6': 'Bank UPI ID',
        '7': 'Bank Name',
        '8': 'Bank Account No',
        '9': 'Bank Branch',
        '10': 'Bank IFSC Code',
        '11': 'Sign-off / Firm Name',
        '12': 'Support Helpline',
        '13': 'Invoice Web / PDF Link'
    };
    return map[n] ? `Variable {{${n}}} (${map[n]})` : `Variable {{${n}}}`;
}

// Render dynamic variable input fields and return HTML string
function buildVarInputsHTML(named, positional, prefix) {
    const skipVars = ['name', 'phone']; // handled separately
    const customNamed = named.filter(v => !skipVars.includes(v));
    if (customNamed.length === 0 && positional.length === 0) return '';

    let html = `<div style="background:rgba(245,158,11,0.05); border:1px solid rgba(245,158,11,0.3); border-radius:10px; padding:14px;">
        <label class="form-label font-bold text-xs" style="color:#d97706; display:flex; align-items:center; gap:5px; margin-bottom:10px;">
            <i data-lucide="edit-3" style="width:13px;height:13px;"></i> Step 3: Fill Template Variables
        </label>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:0.75rem;">`;

    customNamed.forEach(v => {
        const lbl = VAR_LABELS[v] || `{${v}}`;
        html += `<div>
            <label class="form-label font-bold text-xs" style="color:#334155; margin-bottom:3px;">${escapeHtml(lbl)}</label>
            <input type="text" id="${prefix}Var_${v}" name="var_${v}" class="input-styled text-xs"
                   placeholder="${lbl}" oninput="${prefix === 'ind' ? 'updateIndividualPreview()' : 'updateBulkPreview()'}">
        </div>`;
    });

    positional.forEach(n => {
        const lbl = getPositionalVarLabel(n);
        html += `<div>
            <label class="form-label font-bold text-xs" style="color:#334155; margin-bottom:3px;">${escapeHtml(lbl)}</label>
            <input type="text" id="${prefix}PosVar_${n}" name="posvar_${n}" class="input-styled text-xs"
                   placeholder="Value for {{${n}}}" oninput="${prefix === 'ind' ? 'updateIndividualPreview()' : 'updateBulkPreview()'}">
        </div>`;
    });

    html += `</div></div>`;
    return html;
}

function switchIndividualMessageMode(mode) {
    if (aiStreamInterval) {
        clearInterval(aiStreamInterval);
        aiStreamInterval = null;
    }

    const btnTpl = document.getElementById('indModeBtn-template');
    const btnAi  = document.getElementById('indModeBtn-ai');
    const btnCst = document.getElementById('indModeBtn-custom');
    const aiBox  = document.getElementById('indAiPromptBox');
    const txtArea = document.getElementById('indMessageText');
    const sel = document.getElementById('indTemplateSelect');
    const dynContainer = document.getElementById('indDynamicVarsContainer');

    [btnTpl, btnAi, btnCst].forEach(b => b && b.classList.remove('active'));

    if (mode === 'ai') {
        if (btnAi) btnAi.classList.add('active');
        if (aiBox) {
            aiBox.style.display = 'block';
            if (window.gsap) {
                gsap.fromTo(aiBox, 
                    { opacity: 0, y: -10, scale: 0.98 }, 
                    { opacity: 1, y: 0, scale: 1, duration: 0.3, ease: 'back.out(1.4)' }
                );
            }
        }
        if (sel) sel.value = 'ai_compose';
        if (dynContainer) dynContainer.style.display = 'none';
        if (txtArea) {
            txtArea.readOnly = false;
            txtArea.style.background = 'white';
        }
        const promptInp = document.getElementById('indAiPromptInput');
        if (promptInp) promptInp.focus();
    } else if (mode === 'custom') {
        if (btnCst) btnCst.classList.add('active');
        if (aiBox) aiBox.style.display = 'none';
        if (dynContainer) dynContainer.style.display = 'none';
        if (sel) sel.value = 'custom';
        if (txtArea) {
            txtArea.readOnly = false;
            txtArea.style.background = 'white';
            txtArea.focus();
        }
        updateIndividualPreview();
    } else {
        // Template mode
        if (btnTpl) btnTpl.classList.add('active');
        if (aiBox) aiBox.style.display = 'none';
        
        let targetSlug = sel ? sel.value : '';
        // If current selection is empty, 'custom', or 'ai_compose', pick the first approved template
        if (!targetSlug || targetSlug === 'custom' || targetSlug === 'ai_compose') {
            const firstValidTpl = savedTemplatesList.find(x => x.slug && x.slug !== 'custom' && x.slug !== 'ai_compose');
            if (firstValidTpl) {
                targetSlug = firstValidTpl.slug;
                if (sel) sel.value = targetSlug;
            }
        }

        if (targetSlug && targetSlug !== 'custom' && targetSlug !== 'ai_compose') {
            applyTemplateToIndividual(targetSlug);
        } else {
            if (dynContainer && dynContainer.children.length > 0) {
                dynContainer.style.display = 'block';
            }
            updateIndividualPreview();
        }
    }

    if (window.lucide) lucide.createIcons();
}

function fillIndAiPrompt(text) {
    const inp = document.getElementById('indAiPromptInput');
    if (inp) {
        inp.value = text;
        generateIndividualAiMessage();
    }
}

function generateIndividualAiMessage() {
    const promptInp = document.getElementById('indAiPromptInput');
    const prompt = promptInp ? promptInp.value.trim() : '';
    if (!prompt) {
        alert('Please explain what message you want to generate for this customer.');
        return;
    }

    const btn = document.getElementById('btnIndAiGenerate');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:14px;height:14px;"></i> Generating...`;
        if (window.lucide) lucide.createIcons();
    }

    const tone = document.getElementById('indAiToneSelect')?.value || 'professional';
    const recipName = document.getElementById('indNameInput')?.value.trim() || '';

    fetch('api/campaign-api.php?action=ai_generate_template', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ prompt: prompt, recipient: recipName, tone: tone, category: 'MARKETING' })
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="sparkles" style="width:14px;height:14px;"></i> Generate with AI`;
            if (window.lucide) lucide.createIcons();
        }

        if (data.success && data.template) {
            const t = data.template;
            const txtArea = document.getElementById('indMessageText');
            let generatedText = t.body_text || '';

            // Also set template dropdown to custom
            const sel = document.getElementById('indTemplateSelect');
            if (sel) sel.value = 'custom';

            if (txtArea) {
                txtArea.readOnly = false;
                txtArea.style.background = 'white';

                // Human-like typewriter streaming effect
                let charIndex = 0;
                txtArea.value = '';
                const step = Math.max(3, Math.floor(generatedText.length / 35));
                
                if (window.gsap) {
                    gsap.fromTo('#indPreviewBubble', 
                        { scale: 0.95, opacity: 0.75 }, 
                        { scale: 1, opacity: 1, duration: 0.4, ease: 'back.out(1.8)' }
                    );
                }

                if (aiStreamInterval) {
                    clearInterval(aiStreamInterval);
                    aiStreamInterval = null;
                }

                aiStreamInterval = setInterval(() => {
                    charIndex += step;
                    if (charIndex >= generatedText.length) {
                        txtArea.value = generatedText;
                        clearInterval(aiStreamInterval);
                        aiStreamInterval = null;
                        updateIndividualPreview();
                        const phoneBody = document.getElementById('indPhoneChatBody');
                        if (phoneBody) phoneBody.scrollTop = phoneBody.scrollHeight;
                    } else {
                        txtArea.value = generatedText.slice(0, charIndex);
                        updateIndividualPreview();
                    }
                }, 20);
            } else {
                updateIndividualPreview();
            }
        } else {
            alert(data.message || 'AI Generation failed.');
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="sparkles" style="width:14px;height:14px;"></i> Generate with AI`;
            if (window.lucide) lucide.createIcons();
        }
        alert('Network error while generating AI copy.');
    });
}

function insertVarToIndBody(v) {
    const txtArea = document.getElementById('indMessageText');
    if (txtArea) {
        const start = txtArea.selectionStart;
        const end = txtArea.selectionEnd;
        const text = txtArea.value;
        if (typeof start === 'number') {
            const before = text.substring(0, start);
            const after  = text.substring(end, text.length);
            txtArea.value = before + v + after;
            txtArea.selectionStart = txtArea.selectionEnd = start + v.length;
        } else {
            txtArea.value += ' ' + v;
        }
        txtArea.focus();
        updateIndividualPreview();
    }
}

function applyTemplateToIndividual(slug) {
    if (aiStreamInterval) {
        clearInterval(aiStreamInterval);
        aiStreamInterval = null;
    }

    const txtArea = document.getElementById('indMessageText');
    const dynContainer = document.getElementById('indDynamicVarsContainer');
    const infoTag = document.getElementById('indTemplateInfoTag');
    const dlBtn = document.getElementById('indBtnDownloadSample');
    const sel = document.getElementById('indTemplateSelect');
    if (!txtArea) return;

    if (sel && sel.value !== slug) {
        sel.value = slug;
    }

    // Reset dynamic container
    if (dynContainer) { dynContainer.innerHTML = ''; dynContainer.style.display = 'none'; }
    if (infoTag) infoTag.style.display = 'none';

    if (dlBtn) {
        dlBtn.style.display = (slug && slug !== 'custom' && slug !== 'ai_compose') ? 'inline-flex' : 'none';
    }

    if (slug === 'ai_compose') {
        switchIndividualMessageMode('ai');
        return;
    }

    if (!slug || slug === 'custom') {
        switchIndividualMessageMode('custom');
        txtArea.value = '';
        txtArea.readOnly = false;
        updateIndividualPreview();
        return;
    }

    // Switch visual buttons to template mode directly
    const btnTpl = document.getElementById('indModeBtn-template');
    const btnAi  = document.getElementById('indModeBtn-ai');
    const btnCst = document.getElementById('indModeBtn-custom');
    const aiBox  = document.getElementById('indAiPromptBox');
    [btnTpl, btnAi, btnCst].forEach(b => b && b.classList.remove('active'));
    if (btnTpl) btnTpl.classList.add('active');
    if (aiBox) aiBox.style.display = 'none';

    const t = savedTemplatesList.find(x => x.slug === slug);
    if (t) {
        txtArea.value = t.body_text;
        txtArea.readOnly = true;
        txtArea.style.background = 'rgba(37,99,235,0.03)';
        txtArea.style.color = '#374151';

        const { named, positional } = extractTemplateVars(t.body_text);
        const skipVars = ['name', 'phone'];
        const customNamed = named.filter(v => !skipVars.includes(v));

        if (customNamed.length > 0 || positional.length > 0) {
            const varsHtml = buildVarInputsHTML(named, positional, 'ind');
            if (dynContainer && varsHtml) {
                dynContainer.innerHTML = varsHtml;
                dynContainer.style.display = 'block';
                if (window.lucide) lucide.createIcons();

                // Auto-populate variables immediately if contact or spreadsheet is active, or use sender defaults
                if (activeSelectedContact) {
                    autoFillDynamicVarsFromObject(activeSelectedContact);
                } else if (sheetParsedRows.length > 0 && sheetParsedRows[currentSheetRowIndex]) {
                    autoFillDynamicVarsFromObject(sheetParsedRows[currentSheetRowIndex]);
                } else {
                    autoFillDynamicVarsFromObject({});
                }
            }
        }

        if (infoTag) {
            const varCount = customNamed.length + positional.length;
            infoTag.innerHTML = `<span style="display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check" style="width:12px;height:12px;"></i> "${escapeHtml(t.title)}" selected${varCount > 0 ? ` — ${varCount} personalization variable(s) active below` : ' — no extra variables needed'}</span>`;
            infoTag.style.display = 'block';
            if (window.lucide) lucide.createIcons();
        }

        updateIndividualPreview();
    }
}

function applyTemplateToBulk(slug) {
    const customWrapper = document.getElementById('customMsgWrapper');
    const dynContainer = document.getElementById('bulkDynamicVarsContainer');
    const infoTag = document.getElementById('bulkTemplateInfoTag');
    const sampleBtn = document.getElementById('bulkBtnDownloadSample');

    if (dynContainer) { dynContainer.innerHTML = ''; dynContainer.style.display = 'none'; }
    if (customWrapper) customWrapper.style.display = 'none';
    if (infoTag) infoTag.style.display = 'none';
    if (sampleBtn) sampleBtn.style.display = (slug && slug !== 'custom') ? 'inline-flex' : 'none';

    if (!slug) {
        updateBulkPreview();
        return;
    }

    if (slug === 'custom') {
        if (customWrapper) customWrapper.style.display = 'block';
        updateBulkPreview();
        return;
    }

    const t = savedTemplatesList.find(x => x.slug === slug);
    if (t) {
        const { named, positional } = extractTemplateVars(t.body_text);
        const skipVars = ['name', 'phone'];
        const customNamed = named.filter(v => !skipVars.includes(v));

        if (customNamed.length > 0 || positional.length > 0) {
            const varsHtml = buildVarInputsHTML(named, positional, 'bulk');
            if (dynContainer && varsHtml) {
                dynContainer.innerHTML = varsHtml;
                dynContainer.style.display = 'block';
                if (window.lucide) lucide.createIcons();

                // Auto-fill fallback default variables
                autoFillDynamicVarsFromObjectBulk({});
            }
        }

        if (infoTag) {
            const varCount = customNamed.length + positional.length;
            infoTag.innerHTML = `<span style="display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check" style="width:12px;height:12px;"></i> "${escapeHtml(t.title)}" selected${varCount > 0 ? ` — ${varCount} template variable(s) active` : ''}</span>`;
            infoTag.style.display = 'block';
            if (window.lucide) lucide.createIcons();
        }

        updateBulkPreview();
    }
}

function autoFillDynamicVarsFromObjectBulk(c) {
    const dynContainer = document.getElementById('bulkDynamicVarsContainer');
    if (!dynContainer) return;
    c = c || {};

    const posDefaults = {
        '1': senderProfile.firm || 'Marg ERP Merchant',
        '2': 'Valued Customer',
        '3': 'INV-' + Math.floor(1000 + Math.random() * 9000),
        '4': '4,500',
        '5': '12,400',
        '6': senderProfile.upi || 'deepakawasthi587@okaxis',
        '7': senderProfile.bank_name || 'HDFC Bank Ltd',
        '8': senderProfile.account_no || '50200012345678',
        '9': senderProfile.branch || 'Connaught Place Branch',
        '10': senderProfile.ifsc || 'HDFC0000123',
        '11': senderProfile.firm || 'Marg Support Team',
        '12': senderProfile.helpline || '9532620736',
        '13': 'https://margerp.com/invoice'
    };

    dynContainer.querySelectorAll('input[id^="bulkPosVar_"]').forEach(inp => {
        const n = inp.id.replace('bulkPosVar_', '');
        const rowVal = c['var_' + n] !== undefined ? c['var_' + n] : (c[n] !== undefined ? c[n] : (c['posvar_' + n] !== undefined ? c['posvar_' + n] : null));
        if (rowVal !== null && rowVal !== undefined && rowVal !== '') {
            inp.value = rowVal;
        } else if (!inp.value && posDefaults[n]) {
            inp.value = posDefaults[n];
        }
    });

    dynContainer.querySelectorAll('input[id^="bulkVar_"]').forEach(inp => {
        const v = inp.id.replace('bulkVar_', '').toLowerCase();
        const rowVal = c[v] !== undefined ? c[v] : (c['var_' + v] !== undefined ? c['var_' + v] : null);
        if (rowVal !== null && rowVal !== undefined && rowVal !== '') {
            inp.value = rowVal;
        } else if (!inp.value) {
            if (v === 'company') inp.value = c.company || senderProfile.firm || 'Marg ERP';
            else if (v === 'amount') inp.value = '4,500';
            else if (v === 'due_date') inp.value = '25 Aug 2026';
            else if (v === 'helpline') inp.value = senderProfile.helpline || '9532620736';
            else if (v === 'invoice') inp.value = 'INV-1569';
            else if (v === 'balance') inp.value = '12,400';
        }
    });
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
        applyTemplateToBulk(slug);
    }
}

function toggleCustomMessageText(slug) {
    const customWrapper = document.getElementById('customMsgWrapper');
    if (customWrapper) {
        customWrapper.style.display = (slug === 'custom' || !slug) ? 'block' : 'none';
    }
}

function updateIndividualPreview() {
    const txt = document.getElementById('indMessageText')?.value || '';
    const rawName = document.getElementById('indNameInput')?.value.trim();
    const name = rawName || (activeSelectedContact ? formatContactNameDisplay(activeSelectedContact) : 'Rajesh Medical Store');
    const rawPhone = document.getElementById('indPhoneInput')?.value.trim();
    const phone = rawPhone || (activeSelectedContact ? activeSelectedContact.phone : '9532620736');
    const previewBox = document.getElementById('indLivePreview');
    const tSlug = document.getElementById('indTemplateSelect')?.value;

    // Update phone header title & subtitle
    const headTitle = document.getElementById('indPreviewRecipientTitle');
    const headSub   = document.getElementById('indPreviewRecipientSub');
    const avatar    = document.getElementById('indPreviewAvatar');
    const summaryPhone = document.getElementById('indSummaryPhone');

    if (headTitle) headTitle.innerHTML = `<span>${escapeHtml(name)}</span>`;
    if (headSub)   headSub.innerHTML = `+${escapeHtml(phone)} &bull; Online`;
    if (avatar)    avatar.innerText = name.charAt(0).toUpperCase() || 'R';
    if (summaryPhone) summaryPhone.innerText = '+' + phone;

    if (previewBox) {
        let clean = escapeHtml(txt);

        // WhatsApp markdown formatting
        clean = clean
            .replace(/\*([^\*]+)\*/g, '<b>$1</b>')
            .replace(/\_([^\_]+)\_/g, '<i>$1</i>')
            .replace(/\~([^\~]+)\~/g, '<del>$1</del>')
            .replace(/\n/g, '<br>');

        // Replace standard named vars with styled tags
        clean = clean.replaceAll('{name}', `<span class="wa-var-tag">${escapeHtml(name)}</span>`);
        clean = clean.replaceAll('{phone}', `<span class="wa-var-tag">${escapeHtml(phone)}</span>`);

        // Replace dynamic named vars from inputs
        const dynContainer = document.getElementById('indDynamicVarsContainer');
        if (dynContainer) {
            dynContainer.querySelectorAll('input[id^="indVar_"]').forEach(inp => {
                const varName = inp.id.replace('indVar_', '');
                const val = inp.value.trim();
                const replacement = val ? `<span class="wa-var-tag">${escapeHtml(val)}</span>` : `<span class="wa-var-tag wa-var-missing">{${escapeHtml(varName)}}</span>`;
                clean = clean.replaceAll(`{${varName}}`, replacement);
            });
            // Replace positional vars
            dynContainer.querySelectorAll('input[id^="indPosVar_"]').forEach(inp => {
                const n = inp.id.replace('indPosVar_', '');
                const val = inp.value.trim();
                const replacement = val ? `<span class="wa-var-tag">${escapeHtml(val)}</span>` : `<span class="wa-var-tag wa-var-missing">{{${escapeHtml(n)}}}</span>`;
                clean = clean.replaceAll(`{{${n}}}`, replacement);
            });
        }

        // Fallback for {company} if present
        const comp = (activeSelectedContact ? (activeSelectedContact.company || activeSelectedContact.company_name || activeSelectedContact.firm) : '') || senderProfile.firm || 'Marg ERP';
        clean = clean.replaceAll('{company}', `<span class="wa-var-tag">${escapeHtml(comp)}</span>`);

        // Highlight any remaining unplaced placeholders in missing style
        clean = clean.replace(/\{\{(\d+)\}\}/g, '<span class="wa-var-tag wa-var-missing">{{$1}}</span>');
        clean = clean.replace(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g, '<span class="wa-var-tag wa-var-missing">{$1}</span>');

        previewBox.innerHTML = clean.trim() ? clean : 'Select a template above, generate with AI, or type custom message...';
    }

    // Media rendering in WhatsApp Live Phone Simulator
    const mediaTopEl = document.getElementById('indLiveMediaPreviewTop');
    const mediaBottomEl = document.getElementById('indLiveMediaPreviewBottom');
    const bottomBubbleEl = document.getElementById('indMsgBubbleBottomMedia');
    const pos = document.querySelector('input[name="media_position"]:checked')?.value || 'top';

    if (currentIndMedia.type === 'image' && currentIndMedia.url) {
        if (pos === 'bottom') {
            // Position Bottom: text first, image bubble below
            if (mediaTopEl) {
                mediaTopEl.innerHTML = '';
                mediaTopEl.style.display = 'none';
            }
            if (mediaBottomEl && bottomBubbleEl) {
                mediaBottomEl.innerHTML = `<img src="${currentIndMedia.url}" class="wa-preview-img-bottom" alt="Attachment">`;
                bottomBubbleEl.style.display = 'block';
            }
        } else {
            // Position Top: image header with text caption
            if (mediaTopEl) {
                mediaTopEl.innerHTML = `<img src="${currentIndMedia.url}" class="wa-preview-img-top" alt="Attachment">`;
                mediaTopEl.style.display = 'block';
            }
            if (bottomBubbleEl) {
                bottomBubbleEl.style.display = 'none';
            }
        }
    } else if (currentIndMedia.type === 'pdf' || currentIndMedia.type === 'document') {
        if (bottomBubbleEl) bottomBubbleEl.style.display = 'none';
        if (mediaTopEl) {
            const isPdf = currentIndMedia.type === 'pdf';
            mediaTopEl.innerHTML = `
            <div class="wa-doc-preview-card">
                <div style="background: ${isPdf ? '#ef4444' : '#2563eb'}; color: white; border-radius: 4px; padding: 5px 7px; font-weight: 800; font-size: 0.65rem; text-transform: uppercase;">
                    ${isPdf ? 'PDF' : 'DOC'}
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 700; font-size: 0.76rem; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(currentIndMedia.name)}</div>
                    <div style="font-size: 0.66rem; color: #64748b;">${escapeHtml(currentIndMedia.sizeText || 'Document')}</div>
                </div>
                <i data-lucide="arrow-down-to-line" style="width: 14px; height: 14px; color: #64748b;"></i>
            </div>`;
            mediaTopEl.style.display = 'block';
        }
    } else {
        if (mediaTopEl) {
            mediaTopEl.innerHTML = '';
            mediaTopEl.style.display = 'none';
        }
        if (bottomBubbleEl) {
            bottomBubbleEl.style.display = 'none';
        }
    }

    // Render interactive buttons in individual preview
    const btnsBox = document.getElementById('indLiveButtonsPreview');
    if (btnsBox) {
        btnsBox.innerHTML = '';
        let hasButtons = false;
        if (tSlug && tSlug !== 'custom' && tSlug !== '' && tSlug !== 'ai_compose') {
            const t = savedTemplatesList.find(x => x.slug === tSlug);
            if (t && t.buttons_json) {
                try {
                    const bArr = JSON.parse(t.buttons_json);
                    if (Array.isArray(bArr) && bArr.length > 0) {
                        hasButtons = true;
                        bArr.forEach(b => {
                            const bTitle = (typeof b === 'object') ? (b.title || 'Action') : b;
                            btnsBox.innerHTML += `
                            <button type="button" class="wa-interactive-btn">
                                <i data-lucide="corner-down-left" style="width:12px; height:12px;"></i>
                                <span>${escapeHtml(bTitle)}</span>
                            </button>`;
                        });
                    }
                } catch(e) {}
            }
        }
        btnsBox.style.display = hasButtons ? 'flex' : 'none';
        if (window.lucide) lucide.createIcons();
    }
}

function updateBulkPreview() {
    const previewBox = document.getElementById('bulkLivePreview');
    const slug = document.getElementById('bulkTemplateSelect')?.value;
    const isCustom = slug === 'custom';
    const dynContainer = document.getElementById('bulkDynamicVarsContainer');
    const t = savedTemplatesList.find(x => x.slug === slug);
    let rawText = '';

    if (isCustom) {
        rawText = document.getElementById('bulkCustomMsgTextarea')?.value || 'Type your custom broadcast message on the left to see live preview...';
    } else if (t) {
        rawText = t.body_text || '';
    } else {
        rawText = 'Select an approved template from the dropdown to see live WhatsApp preview...';
    }

    // Sample contact: from uploaded Excel first row, or default sample
    const sample = (bulkParsedRows && bulkParsedRows.length > 0) ? bulkParsedRows[0] : { name: 'Rajesh Medical Store', phone: '9532620736', company: 'Rajesh Medical Store' };
    const samplePhone = findPhoneInRow(sample) || '9532620736';
    const sampleName = findNameInRow(sample) || sample.name || 'Rajesh Medical Store';

    // Update phone simulator header
    const headTitle = document.getElementById('bulkPreviewRecipientTitle');
    const headSub   = document.getElementById('bulkPreviewRecipientSub');
    const avatar    = document.getElementById('bulkPreviewAvatar');
    if (headTitle) headTitle.innerText = `${sampleName} (Sample)`;
    if (headSub)   headSub.innerHTML = `+${escapeHtml(samplePhone)} &bull; Online`;
    if (avatar)    avatar.innerText = sampleName.charAt(0).toUpperCase() || 'R';

    if (previewBox) {
        let clean = escapeHtml(rawText);

        // WhatsApp Markdown
        clean = clean
            .replace(/\*([^\*]+)\*/g, '<b>$1</b>')
            .replace(/\_([^\_]+)\_/g, '<i>$1</i>')
            .replace(/\~([^\~]+)\~/g, '<del>$1</del>')
            .replace(/\n/g, '<br>');

        clean = clean.replaceAll('{name}', `<span class="wa-var-tag">${escapeHtml(sampleName)}</span>`);
        clean = clean.replaceAll('{phone}', `<span class="wa-var-tag">${escapeHtml(samplePhone)}</span>`);

        if (dynContainer) {
            dynContainer.querySelectorAll('input[id^="bulkVar_"]').forEach(inp => {
                const v = inp.id.replace('bulkVar_', '');
                const val = inp.value.trim();
                const rep = val ? `<span class="wa-var-tag">${escapeHtml(val)}</span>` : `<span class="wa-var-tag wa-var-missing">{${escapeHtml(v)}}</span>`;
                clean = clean.replaceAll(`{${v}}`, rep);
            });
            dynContainer.querySelectorAll('input[id^="bulkPosVar_"]').forEach(inp => {
                const n = inp.id.replace('bulkPosVar_', '');
                const val = inp.value.trim();
                const rep = val ? `<span class="wa-var-tag">${escapeHtml(val)}</span>` : `<span class="wa-var-tag wa-var-missing">{{${escapeHtml(n)}}}</span>`;
                clean = clean.replaceAll(`{{${n}}}`, rep);
            });
        }

        const comp = sample.company || senderProfile.firm || 'Marg ERP';
        clean = clean.replaceAll('{company}', `<span class="wa-var-tag">${escapeHtml(comp)}</span>`);

        clean = clean.replace(/\{\{(\d+)\}\}/g, '<span class="wa-var-tag wa-var-missing">{{$1}}</span>');
        clean = clean.replace(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g, '<span class="wa-var-tag wa-var-missing">{$1}</span>');

        previewBox.innerHTML = clean;
    }

    // Render interactive buttons preview
    const btnsBox = document.getElementById('bulkLiveButtonsPreview');
    if (btnsBox) {
        btnsBox.innerHTML = '';
        let hasButtons = false;
        if (t && t.buttons_json) {
            try {
                const bArr = JSON.parse(t.buttons_json);
                if (Array.isArray(bArr) && bArr.length > 0) {
                    hasButtons = true;
                    bArr.forEach(b => {
                        const bTitle = (typeof b === 'object') ? (b.title || 'Action') : b;
                        btnsBox.innerHTML += `
                        <button type="button" class="wa-interactive-btn">
                            <i data-lucide="corner-down-left" style="width:12px; height:12px;"></i>
                            <span>${escapeHtml(bTitle)}</span>
                        </button>`;
                    });
                }
            } catch(e) {}
        }
        btnsBox.style.display = hasButtons ? 'flex' : 'none';
        if (window.lucide) lucide.createIcons();
    }

    updateBulkCampaignSummary();
}

function updateBulkCampaignSummary() {
    const targetType = document.getElementById('bulkTargetSelect')?.value || 'clients';
    const delaySec = parseInt(document.getElementById('bulkDelaySelect')?.value || 5);
    const summaryAud = document.getElementById('bulkSummaryAudience');
    const summaryEst = document.getElementById('bulkSummaryEstTime');

    let count = 0;
    let label = '';

    if (targetType === 'clients') {
        count = loadedContactsList ? loadedContactsList.length : 499;
        label = `All Existing Clients (${count} Contacts)`;
    } else if (targetType === 'leads') {
        const leadCount = loadedContactsList ? loadedContactsList.filter(x => (x.type || '').toLowerCase() === 'lead').length : 150;
        count = leadCount || 150;
        label = `CRM Sales Leads (${count} Contacts)`;
    } else if (targetType === 'specific') {
        count = selectedSpecificPhones ? selectedSpecificPhones.size : 0;
        label = `Selected Contacts (${count} Chosen)`;
    } else if (targetType === 'csv') {
        count = (bulkParsedRows && bulkParsedRows.length > 0) ? bulkParsedRows.length : 0;
        label = count > 0 ? `Excel Sheet (${count} Contacts)` : `Excel / CSV (No file selected)`;
    }

    if (summaryAud) summaryAud.innerText = label;

    if (summaryEst) {
        if (count === 0) {
            summaryEst.innerText = '0 seconds';
        } else {
            const totalSec = count * delaySec;
            if (totalSec < 60) {
                summaryEst.innerText = `~${totalSec} seconds`;
            } else {
                const mins = Math.floor(totalSec / 60);
                const secs = totalSec % 60;
                summaryEst.innerText = `~${mins}m ${secs > 0 ? secs + 's' : ''}`;
            }
        }
    }
}

let indSendTimer = null;

function handleIndividualSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitIndividual');
    const delaySec = parseInt(document.getElementById('indDelaySelect')?.value || 0);

    // If Excel / CSV upload mode is active
    if (currentRecipientMode === 'excel') {
        if (!sheetParsedRows || sheetParsedRows.length === 0) {
            alert('Please browse and upload an Excel (.xlsx, .xls) or CSV spreadsheet with recipient contacts first.');
            return;
        }

        const msgText = document.getElementById('indMessageText')?.value.trim();
        if (!msgText) {
            alert('Please enter or select message text for your WhatsApp broadcast.');
            return;
        }

        const form = e.target;
        const formData = new FormData(form);
        formData.append('action', 'launch_excel_broadcast');
        formData.append('contacts_json', JSON.stringify(sheetParsedRows));
        formData.append('delay_seconds', delaySec);

        const dailyLimitSel = document.getElementById('indDailyLimitSelect')?.value;
        let dailyLimit = parseInt(dailyLimitSel) || 0;
        if (dailyLimitSel === 'custom') {
            dailyLimit = parseInt(document.getElementById('indCustomDailyLimitInput')?.value) || 0;
        }
        formData.append('daily_limit', dailyLimit);

        btn.disabled = true;
        btn.style.background = '#059669';
        const quotaNoteText = (dailyLimit > 0) ? `[Quota: ${dailyLimit} Today]` : '';
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Initializing Excel Broadcast (${sheetParsedRows.length} Contacts ${quotaNoteText})...`;
        if (window.lucide) lucide.createIcons();

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.style.background = '';
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
            if (window.lucide) lucide.createIcons();

            if (data.success) {
                alert(data.message);
                if (data.campaign_id) {
                    runningCampaignIds.add(data.campaign_id);
                    // Kick off background dispatch immediately
                    setTimeout(runActiveCampaignsLoop, 500);
                }
                form.reset();
                sheetParsedRows = [];
                currentSheetRowIndex = 0;
                const navWrapper = document.getElementById('indFileRowsNavigator');
                if (navWrapper) navWrapper.style.display = 'none';
                clearIndMedia();
                updateIndividualPreview();
                updateQuotaSummaryNote();
                fetchCampaigns();
                switchMainTab('campaigns');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.style.background = '';
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
            if (window.lucide) lucide.createIcons();
            alert('Failed starting Excel broadcast: ' + err.message);
        });
        return;
    }

    // Otherwise standard 1-on-1 direct message (directory or manual entry)
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'send_individual');
    formData.append('client_countdown', '1');

    const executeDispatch = () => {
        btn.disabled = true;
        btn.style.background = '';
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Dispatched via Gateway...`;
        if (window.lucide) lucide.createIcons();

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
            if (window.lucide) lucide.createIcons();

            if (data.success) {
                alert(data.message);
                form.reset();
                clearIndMedia();
                updateIndividualPreview();
                fetchCampaigns();
                switchMainTab('campaigns');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
            if (window.lucide) lucide.createIcons();
            alert('Failed sending WhatsApp broadcast.');
        });
    };

    if (delaySec > 0) {
        let remaining = delaySec;
        btn.disabled = false;
        btn.style.background = '#d97706';
        btn.innerHTML = `⏱️ Sending in ${remaining}s... <span style="background: rgba(0,0,0,0.25); padding: 2px 8px; border-radius: 4px; margin-left: 6px; cursor: pointer;" onclick="cancelIndCountdown(event)">Cancel</span>`;

        indSendTimer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(indSendTimer);
                indSendTimer = null;
                btn.style.background = '';
                executeDispatch();
            } else {
                btn.innerHTML = `⏱️ Sending in ${remaining}s... <span style="background: rgba(0,0,0,0.25); padding: 2px 8px; border-radius: 4px; margin-left: 6px; cursor: pointer;" onclick="cancelIndCountdown(event)">Cancel</span>`;
            }
        }, 1000);
    } else {
        executeDispatch();
    }
}

function cancelIndCountdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    if (indSendTimer) {
        clearInterval(indSendTimer);
        indSendTimer = null;
    }
    const btn = document.getElementById('btnSubmitIndividual');
    if (btn) {
        btn.disabled = false;
        btn.style.background = '';
        btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
        if (window.lucide) lucide.createIcons();
    }
}

function handleDailyLimitSelectChange(val) {
    const customInp = document.getElementById('indCustomDailyLimitInput');
    if (customInp) {
        customInp.style.display = (val === 'custom') ? 'inline-block' : 'none';
        if (val === 'custom') customInp.focus();
    }
    updateQuotaSummaryNote();
}

function handleCustomDailyLimitInput(val) {
    updateQuotaSummaryNote();
}

function updateQuotaSummaryNote() {
    const note = document.getElementById('indQuotaSummaryNote');
    const sel = document.getElementById('indDailyLimitSelect')?.value;
    let limit = parseInt(sel) || 0;
    if (sel === 'custom') {
        limit = parseInt(document.getElementById('indCustomDailyLimitInput')?.value) || 0;
    }

    const totalInSheet = (sheetParsedRows && sheetParsedRows.length > 0) ? sheetParsedRows.length : 0;
    const btn = document.getElementById('btnSubmitIndividual');

    if (!note) return;

    if (limit > 0) {
        let msg = `🛡️ <strong>Anti-Ban Quota Active:</strong> Exactly <strong>${limit} messages</strong> will be dispatched with safe delay, then paused automatically.`;
        if (totalInSheet > 0) {
            const rem = Math.max(0, totalInSheet - limit);
            msg += ` (Spreadsheet has ${totalInSheet} contacts; remaining <strong>${rem}</strong> will wait safely for next batch).`;
        }
        note.innerHTML = msg;
        note.style.display = 'block';

        if (btn && currentRecipientMode === 'excel' && totalInSheet > 0) {
            const sendCount = Math.min(limit, totalInSheet);
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Launch Excel Broadcast (${sendCount} of ${totalInSheet} Contacts Today)`;
            if (window.lucide) lucide.createIcons();
        }
    } else {
        note.style.display = 'none';
        note.innerHTML = '';
        if (btn && currentRecipientMode === 'excel' && totalInSheet > 0) {
            btn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Launch Direct Broadcast (${totalInSheet} Contacts)`;
            if (window.lucide) lucide.createIcons();
        }
    }
}

function promptResumeNextBatch(campaignId, currentDailyLimit, pendingCount) {
    const defaultQty = (currentDailyLimit > 0 && currentDailyLimit <= pendingCount) ? currentDailyLimit : Math.min(50, pendingCount);
    const userInput = prompt(
        `🛡️ Anti-Ban Quota Control\n\nRemaining Contacts: ${pendingCount}\n\nEnter how many contacts to dispatch in this batch (e.g. 25, 50, 100, or 0 for all remaining):`,
        defaultQty
    );
    if (userInput === null) return; // User pressed Cancel

    const nextLimit = parseInt(userInput);
    if (isNaN(nextLimit) || nextLimit < 0) {
        alert('Please enter a valid number (e.g. 50, 100, or 0).');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'resume_next_batch');
    formData.append('id', campaignId);
    formData.append('next_limit', nextLimit);

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            runningCampaignIds.add(campaignId);
            fetchCampaigns();
            setTimeout(runActiveCampaignsLoop, 500);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        alert('Failed resuming next batch: ' + err.message);
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
    if (!confirm('Are you sure you want to delete and clear all active and past campaigns?\n\nThis will reset the campaign count to 0 and remove all audience logs.')) {
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
        const isExcel      = (c.target_type === 'excel' || (c.name && c.name.includes('Excel Broadcast')));
        const isDirect     = (!isExcel && (c.target_type === 'individual' || (c.name && c.name.includes('Direct Broadcast'))));
        const dailyLimit   = parseInt(c.daily_limit || 0);
        const todaySent    = parseInt(c.today_sent_count || 0);
        const isQuotaReached = (dailyLimit > 0 && todaySent >= dailyLimit && (isPaused || c.status === 'paused_quota'));

        // Type Badge
        let typeBadge = '';
        if (isExcel) {
            typeBadge = `<span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 700; font-size: 0.71rem; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="file-spreadsheet" style="width: 12px; height: 12px;"></i> Excel Broadcast</span>`;
        } else if (isDirect) {
            typeBadge = `<span class="badge" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.25); font-weight: 700; font-size: 0.71rem; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="zap" style="width: 12px; height: 12px;"></i> Direct 1-on-1</span>`;
        } else {
            typeBadge = `<span class="badge" style="background: rgba(14, 165, 233, 0.1); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.25); font-weight: 700; font-size: 0.71rem; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="users" style="width: 12px; height: 12px;"></i> Bulk Broadcast</span>`;
        }

        // Status Badge
        let statusBadge = '<span class="badge text-xs" style="background:#e5e7eb; color:#374151;">Draft</span>';
        if (isQuotaReached) {
            statusBadge = `<span class="badge text-xs" style="background:rgba(245,158,11,0.14); color:#b45309; border:1px solid rgba(245,158,11,0.4); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px;"><i data-lucide="shield-alert" style="width:13px;height:13px;"></i> Quota Paused (${todaySent}/${dailyLimit} Today)</span>`;
        } else if (isPendingApp) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="clock" style="width:12px;height:12px;"></i> Pending Review</span>';
        } else if (isApproved) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-circle-2" style="width:12px;height:12px;"></i> Approved</span>';
        } else if (isRejected) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(239,68,68,0.12); color:#dc2626; border:1px solid rgba(239,68,68,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="x-circle" style="width:12px;height:12px;"></i> Rejected</span>';
        } else if (isRunning) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:6px;"><span class="pulse-dot"></span> In Progress</span>';
        } else if (isPaused) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="pause" style="width:12px;height:12px;"></i> Paused</span>';
        } else if (isDone) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(37,99,235,0.12); color:#2563eb; border:1px solid rgba(37,99,235,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="check-check" style="width:12px;height:12px;"></i> Completed</span>';
        } else if (isCancelled) {
            statusBadge = '<span class="badge text-xs" style="background:rgba(100,116,139,0.12); color:#475569; border:1px solid rgba(100,116,139,0.3); font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px;"><i data-lucide="square" style="width:12px;height:12px;"></i> Stopped</span>';
        }

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
                        ${dailyLimit > 0 ? `
                        <span class="meta-chip" style="background: rgba(16, 185, 129, 0.08); color: #047857; font-weight: 700;" title="Anti-Ban Quota">
                            <i data-lucide="shield-check" style="width: 12px; height: 12px; color: #10b981;"></i>
                            <span>Quota: ${todaySent} / ${dailyLimit} Today</span>
                        </span>
                        ` : ''}
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
                            ${isQuotaReached ? `
                                <button type="button" class="btn btn-success text-xs font-bold" style="background: #059669; color: white; padding: 6px 13px; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 8px rgba(5,150,105,0.25);" onclick="promptResumeNextBatch(${c.id}, ${dailyLimit}, ${c.pending_count})" title="Send Next Daily Quota Batch">
                                    <i data-lucide="play-circle" style="width:13px;height:13px;"></i> Resume Next Batch
                                </button>
                            ` : (isRunning ? `
                                <button type="button" class="btn btn-warning text-xs font-bold" style="padding: 6px 11px;" onclick="toggleCampaignStatus(${c.id}, 'paused')" title="Pause sending">
                                    <i data-lucide="pause" style="width:12px;height:12px;"></i> Pause
                                </button>
                            ` : `
                                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white; padding: 6px 11px;" onclick="toggleCampaignStatus(${c.id}, 'running')">
                                    <i data-lucide="play" style="width:12px;height:12px;"></i> Resume
                                </button>
                            `)}
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
                    ${dailyLimit > 0 ? `
                    <span class="campaign-stat-pill stat-pill-quota" style="background: rgba(16, 185, 129, 0.1); color: #047857; font-weight: 700;">
                        <i data-lucide="shield" style="width: 11px; height: 11px;"></i> Today's Batch: ${todaySent} / ${dailyLimit}
                    </span>
                    ` : ''}
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
    const form = e.target;
    const formData = new FormData(form);
    const targetType = formData.get('target_type');

    if (targetType === 'csv') {
        if (!bulkParsedRows || bulkParsedRows.length === 0) {
            const rawJson = document.getElementById('bulkParsedContactsJson')?.value;
            if (!rawJson) {
                alert('Please upload an Excel (.xlsx, .xls) or CSV file with recipient contacts first.');
                return;
            }
        } else {
            formData.set('parsed_contacts_json', JSON.stringify(bulkParsedRows));
        }
    } else if (targetType === 'specific') {
        if (!selectedSpecificPhones || selectedSpecificPhones.size === 0) {
            alert('Please select at least one contact from the list.');
            return;
        }
        selectedSpecificPhones.forEach(ph => {
            formData.append('selected_phones[]', ph);
        });
    }

    const btn = document.getElementById('btnSubmitBulk');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Initializing Campaign...`;
    formData.append('action', 'create_campaign');

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
            bulkParsedRows = [];
            const statusCard = document.getElementById('bulkFileStatusCard');
            if (statusCard) statusCard.style.display = 'none';
            fetchCampaigns();
            switchMainTab('campaigns');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="play" style="width:14px;height:14px;"></i> Create &amp; Initialize Campaign`;
        alert('Failed creating bulk campaign: ' + err.message);
    });
}

function toggleTargetAudienceType(val) {
    const csvWrap = document.getElementById('csvUploadWrapper');
    const specificWrap = document.getElementById('specificContactsWrapper');

    if (csvWrap) csvWrap.style.display = (val === 'csv') ? 'block' : 'none';
    if (specificWrap) specificWrap.style.display = (val === 'specific') ? 'block' : 'none';
    updateBulkCampaignSummary();
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

// --- STEP 2 RECIPIENT MANAGEMENT & SMART SPREADSHEET PARSER ---

function switchRecipientSource(mode) {
    currentRecipientMode = mode;
    ['directory', 'excel', 'manual'].forEach(m => {
        const tab = document.getElementById('indRecipTab-' + m);
        const sec = document.getElementById('indRecipientSec-' + m);
        if (tab) {
            if (m === mode) tab.classList.add('active');
            else tab.classList.remove('active');
        }
        if (sec) {
            sec.style.display = (m === mode) ? 'block' : 'none';
        }
    });

    const submitBtn = document.getElementById('btnSubmitIndividual');
    if (submitBtn) {
        if (mode === 'excel' && sheetParsedRows && sheetParsedRows.length > 0) {
            submitBtn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Launch Direct Broadcast (${sheetParsedRows.length} Contacts)`;
        } else {
            submitBtn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Send Direct WhatsApp Broadcast Now`;
        }
    }

    if (window.lucide) lucide.createIcons();
}

function handleDirectoryFilterChange(dir) {
    currentDirectoryFilter = dir;
    const filterInput = document.getElementById('indContactFilterInput');
    const term = filterInput ? filterInput.value : '';
    applyDirectoryAndSearchFilters(dir, term);
}

function handleContactFilterInput(query) {
    applyDirectoryAndSearchFilters(currentDirectoryFilter, query);
}

function applyDirectoryAndSearchFilters(dir, term) {
    const q = (term || '').toLowerCase().trim();
    let filtered = loadedContactsList.filter(c => {
        // Directory filter
        if (dir !== 'all') {
            const cDir = (c.directory_id || c.type || '').toLowerCase();
            if (cDir !== dir.toLowerCase()) return false;
        }
        // Query filter
        if (q) {
            const matchName = (c.name || '').toLowerCase().includes(q);
            const matchPhone = (c.phone || '').includes(q);
            const matchComp = (c.company || '').toLowerCase().includes(q);
            if (!matchName && !matchPhone && !matchComp) return false;
        }
        return true;
    });

    populateIndividualContactPicker(filtered);
}

function formatContactPhoneDisplay(phone) {
    if (!phone) return '';
    const clean = String(phone).replace(/[^0-9]/g, '');
    if (clean.startsWith('91') && clean.length === 12) {
        return `+91 ${clean.slice(2, 7)} ${clean.slice(7)}`;
    } else if (clean.length === 10) {
        return `+91 ${clean.slice(0, 5)} ${clean.slice(5)}`;
    }
    return `+${clean}`;
}

function formatContactNameDisplay(c) {
    if (!c) return 'Valued Customer';
    let name = (c.name || '').trim();
    let comp = (c.company || '').trim();

    // Check if name is dummy/empty/phone number
    const cleanPhone = String(c.phone || '').replace(/[^0-9]/g, '');
    const isDummyName = !name || name.toLowerCase() === 'na' || name.toLowerCase().startsWith('lead (') || name.replace(/[^0-9]/g, '') === cleanPhone;

    if (isDummyName) {
        if (comp && comp.toLowerCase() !== 'na' && comp.toLowerCase() !== 'followup' && !comp.toLowerCase().startsWith('lead (')) {
            return comp;
        }
        return 'Valued Customer';
    }

    // If company is same as name or generic
    if (!comp || comp.toLowerCase() === name.toLowerCase() || comp.toLowerCase() === 'prospect' || comp.toLowerCase() === 'client' || comp.toLowerCase() === 'lead' || comp.toLowerCase() === 'followup' || comp.toLowerCase() === 'na') {
        return name;
    }

    // Both name and distinct company exist
    return `${name} (${comp})`;
}

function populateIndividualContactPicker(contacts) {
    const picker = document.getElementById('indContactPicker');
    const badge = document.getElementById('indContactsBadgeCount');
    if (!picker) return;

    const list = contacts || loadedContactsList || [];
    if (badge) badge.innerText = `${list.length} contact${list.length === 1 ? '' : 's'}`;

    let html = `<option value="">-- Choose Contact (${list.length} Available) --</option>`;
    list.forEach(c => {
        const phoneFormatted = formatContactPhoneDisplay(c.phone);
        const nameFormatted = formatContactNameDisplay(c);
        const tag = (currentDirectoryFilter === 'all') ? `[${c.type || 'Contact'}] ` : '';
        html += `<option value="${escapeHtml(c.phone)}">${tag}${escapeHtml(nameFormatted)}  •  ${phoneFormatted}</option>`;
    });

    picker.innerHTML = html;
}

// Auto-fills dynamic variable inputs based on object keys (without overwriting unmatched inputs)
function autoFillDynamicVarsFromObject(c) {
    const dynContainer = document.getElementById('indDynamicVarsContainer');
    if (!dynContainer) return;
    c = c || {};

    // Normalize keys of object to lowercase without spaces or underscores
    const normMap = {};
    for (const [k, v] of Object.entries(c)) {
        const cleanK = String(k).toLowerCase().replace(/[\s_\-]/g, '');
        normMap[cleanK] = v;
    }

    const contactName = c.name || c.contact_name || c.client_name || c.person_name || '';
    const contactCompany = c.company || c.company_name || c.firm || c.party_name || '';

    // Friendly fallback mapping
    const standardAliases = {
        'company': contactCompany || normMap['company'] || normMap['companyname'] || normMap['firm'] || senderProfile.firm || 'Marg ERP',
        'amount': normMap['amount'] || normMap['balance'] || normMap['dueamount'] || normMap['billamount'] || '4,500',
        'due_date': normMap['duedate'] || normMap['due'] || normMap['date'] || 'Next Monday',
        'product': normMap['product'] || normMap['item'] || normMap['service'] || 'Marg ERP Gold Edition',
        'invoice': normMap['invoice'] || normMap['invoiceno'] || normMap['billno'] || ('INV-' + Math.floor(1000 + Math.random() * 9000)),
        'city': normMap['city'] || normMap['location'] || 'Delhi',
        'helpline': normMap['helpline'] || senderProfile.helpline || '9532620736',
        'balance': normMap['balance'] || normMap['ledgerbalance'] || '12,400'
    };

    // Named variables
    dynContainer.querySelectorAll('input[id^="indVar_"]').forEach(inp => {
        const vKey = inp.id.replace('indVar_', '').toLowerCase();
        const cleanVKey = vKey.replace(/[\s_\-]/g, '');

        if (normMap[cleanVKey] !== undefined && normMap[cleanVKey] !== '') {
            inp.value = normMap[cleanVKey];
        } else if (standardAliases[vKey] !== undefined && standardAliases[vKey] !== '') {
            inp.value = standardAliases[vKey];
        }
    });

    // Smart defaults for Positional variables {{1}} through {{13}}
    const posDefaults = {
        '1': senderProfile.firm || 'Marg ERP Pvt Ltd',
        '2': contactName || contactCompany || 'Valued Customer',
        '3': normMap['invoiceno'] || normMap['invoice'] || normMap['billno'] || ('INV-' + Math.floor(1000 + Math.random() * 9000)),
        '4': normMap['amount'] || normMap['billamount'] || '4,500',
        '5': normMap['balance'] || normMap['ledgerbalance'] || '12,400',
        '6': senderProfile.upi || 'margerp@upi',
        '7': senderProfile.bank_name || 'HDFC Bank Ltd',
        '8': senderProfile.account_no || '50200012345678',
        '9': senderProfile.branch || 'Connaught Place Branch',
        '10': senderProfile.ifsc || 'HDFC0000123',
        '11': senderProfile.firm || 'Marg Support Team',
        '12': senderProfile.helpline || '9532620736',
        '13': 'https://margerp.com/invoice'
    };

    const posAliases = {
        '1': ['var1', 'firm', 'merchant', 'senderfirm', 'sendername', 'company', 'merchantfirm'],
        '2': ['var2', 'customer', 'customername', 'party', 'partyname', 'client', 'name'],
        '3': ['var3', 'invoice', 'invoiceno', 'billno', 'bill', 'invoicenumber'],
        '4': ['var4', 'amount', 'billamount', 'totalamount', 'total'],
        '5': ['var5', 'balance', 'ledgerbalance', 'dueamount', 'balanceamount'],
        '6': ['var6', 'upi', 'upiid', 'bankupi', 'bankupiid'],
        '7': ['var7', 'bank', 'bankname'],
        '8': ['var8', 'account', 'accountno', 'accountnumber', 'bankaccount', 'accno'],
        '9': ['var9', 'branch', 'bankbranch'],
        '10': ['var10', 'ifsc', 'ifsccode', 'bankifsc'],
        '11': ['var11', 'regards', 'signoff', 'sender', 'firmname'],
        '12': ['var12', 'helpline', 'support', 'help', 'contactno'],
        '13': ['var13', 'link', 'previewlink', 'pdflink', 'url', 'invoicelink']
    };

    dynContainer.querySelectorAll('input[id^="indPosVar_"]').forEach(inp => {
        const n = inp.id.replace('indPosVar_', '');
        let matchedVal = '';

        if (posAliases[n]) {
            for (const alias of posAliases[n]) {
                if (normMap[alias] !== undefined && normMap[alias] !== '') {
                    matchedVal = normMap[alias];
                    break;
                }
            }
        }

        if (matchedVal !== '') {
            inp.value = matchedVal;
        } else if (normMap['var' + n] !== undefined && normMap['var' + n] !== '') {
            inp.value = normMap['var' + n];
        } else if (normMap[n] !== undefined && normMap[n] !== '') {
            inp.value = normMap[n];
        } else if (normMap['variable' + n] !== undefined && normMap['variable' + n] !== '') {
            inp.value = normMap['variable' + n];
        } else if (!inp.value && posDefaults[n]) {
            inp.value = posDefaults[n];
        }
    });
}

function handleSelectContactIndividual(phone) {
    const card = document.getElementById('indSelectedContactCard');
    if (!phone) {
        activeSelectedContact = null;
        if (card) card.style.display = 'none';
        updateIndividualPreview();
        return;
    }
    const c = loadedContactsList.find(x => x.phone === phone);
    if (c) {
        activeSelectedContact = c;
        const phoneInp = document.getElementById('indPhoneInput');
        const nameInp = document.getElementById('indNameInput');

        const cleanName = formatContactNameDisplay(c);
        const prettyPhone = formatContactPhoneDisplay(c.phone);

        if (phoneInp) phoneInp.value = c.phone;
        if (nameInp) nameInp.value = cleanName;

        const nameSpan = document.getElementById('indSelectedContactNameText');
        const phoneSpan = document.getElementById('indSelectedContactPhoneText');
        if (card && nameSpan && phoneSpan) {
            card.style.display = 'flex';
            nameSpan.innerText = cleanName;
            phoneSpan.innerText = prettyPhone;
            if (window.gsap) {
                gsap.fromTo(card, 
                    { opacity: 0, y: -6, scale: 0.98 }, 
                    { opacity: 1, y: 0, scale: 1, duration: 0.25, ease: 'power2.out' }
                );
            }
            if (window.lucide) lucide.createIcons();
        }

        autoFillDynamicVarsFromObject(c);
        updateIndividualPreview();
    } else {
        activeSelectedContact = null;
        if (card) card.style.display = 'none';
        updateIndividualPreview();
    }
}

// Download dynamic sample Excel (.xlsx) or CSV matching the current template's exact variable headers
function downloadSampleFileForCurrentTemplate(mode = 'ind') {
    const slugSelectId = (mode === 'bulk') ? 'bulkTemplateSelect' : 'indTemplateSelect';
    const slug = document.getElementById(slugSelectId)?.value || '';

    let headers = ['phone', 'name'];
    let row1 = ['919876543210', 'Rajesh Medical Store'];
    let row2 = ['919532620736', 'Verma Diagnostic Labs'];
    let templateTitle = 'WhatsApp_Broadcast';

    if (slug && slug !== 'custom') {
        const t = savedTemplatesList.find(x => x.slug === slug);
        if (t) {
            templateTitle = (t.title || slug).replace(/[^a-zA-Z0-9_-]/g, '_');
            const { named, positional } = extractTemplateVars(t.body_text);
            const skipVars = ['name', 'phone'];
            const customNamed = named.filter(v => !skipVars.includes(v));

            customNamed.forEach(v => {
                headers.push(v);
                if (v === 'company') { row1.push('Marg Pharma Ltd'); row2.push('Health Care Diagnostics'); }
                else if (v === 'amount') { row1.push('3500'); row2.push('5200'); }
                else if (v === 'due_date') { row1.push('25 Aug 2026'); row2.push('30 Aug 2026'); }
                else if (v === 'balance') { row1.push('1200'); row2.push('2400'); }
                else if (v === 'invoice') { row1.push('INV-2026-001'); row2.push('INV-2026-002'); }
                else if (v === 'product') { row1.push('Marg ERP 9+ Silver'); row2.push('Marg ERP 9+ Gold'); }
                else { row1.push(`Sample_${v}_1`); row2.push(`Sample_${v}_2`); }
            });

            const samplePosValues1 = {
                '1': senderProfile.firm || 'Marg ERP Merchant',
                '2': 'Rajesh Medical Store',
                '3': 'INV-1569',
                '4': '4500',
                '5': '12400',
                '6': senderProfile.upi || 'deepakawasthi587@okaxis',
                '7': senderProfile.bank_name || 'HDFC Bank Ltd',
                '8': senderProfile.account_no || '50200012345678',
                '9': senderProfile.branch || 'Connaught Place Branch',
                '10': senderProfile.ifsc || 'HDFC0000123',
                '11': senderProfile.firm || 'Marg Support Team',
                '12': senderProfile.helpline || '9532620736',
                '13': 'https://margerp.com/invoice'
            };

            const samplePosValues2 = {
                '1': senderProfile.firm || 'Marg ERP Merchant',
                '2': 'Verma Diagnostic Labs',
                '3': 'INV-1570',
                '4': '6200',
                '5': '18500',
                '6': senderProfile.upi || 'deepakawasthi587@okaxis',
                '7': senderProfile.bank_name || 'HDFC Bank Ltd',
                '8': senderProfile.account_no || '50200012345678',
                '9': senderProfile.branch || 'Connaught Place Branch',
                '10': senderProfile.ifsc || 'HDFC0000123',
                '11': senderProfile.firm || 'Marg Support Team',
                '12': senderProfile.helpline || '9532620736',
                '13': 'https://margerp.com/invoice'
            };

            positional.forEach(n => {
                headers.push(`var_${n}`);
                row1.push(samplePosValues1[n] || `Value_${n}`);
                row2.push(samplePosValues2[n] || `Value_${n}`);
            });
        }
    } else {
        headers.push('company', 'amount', 'due_date');
        row1.push('Marg Pharma Ltd', '3500', '25 Aug 2026');
        row2.push('Health Care Diagnostics', '5200', '30 Aug 2026');
    }

    const filename = `${templateTitle}_Sample_Recipient_List`;

    // Try Excel (.xlsx) download via SheetJS if available
    if (window.XLSX && XLSX.utils && XLSX.writeFile) {
        try {
            const ws = XLSX.utils.aoa_to_sheet([headers, row1, row2]);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Recipients");
            XLSX.writeFile(wb, `${filename}.xlsx`);
            return;
        } catch (e) {
            console.warn('XLSX export fallback to CSV:', e);
        }
    }

    // CSV fallback with UTF-8 BOM
    let csvContent = '\uFEFF' + headers.join(',') + '\n';
    csvContent += row1.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',') + '\n';
    csvContent += row2.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',') + '\n';

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `${filename}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// In-Browser Spreadsheet (.xlsx / .xls / .csv) Upload & Auto-Fetch
function handleRecipientSheetUpload(e) {
    const file = e.target.files ? e.target.files[0] : null;
    if (!file) return;

    const fileName = file.name.toLowerCase();
    const reader = new FileReader();

    reader.onload = function(evt) {
        try {
            const data = evt.target.result;
            let rows = [];

            if (fileName.endsWith('.xlsx') || fileName.endsWith('.xls')) {
                if (!window.XLSX) {
                    alert('Spreadsheet engine loading, please try again in a moment.');
                    return;
                }
                const workbook = XLSX.read(data, { type: 'binary' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                rows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });
            } else {
                // CSV parsing
                const text = (typeof data === 'string') ? data : new TextDecoder().decode(data);
                rows = parseCsvToObjects(text);
            }

            if (!rows || rows.length === 0) {
                alert('No data rows found in the uploaded file.');
                return;
            }

            sheetParsedRows = rows;
            currentSheetRowIndex = 0;

            // Populate the Row Navigator
            const rowPicker = document.getElementById('indSheetRowPicker');
            const navWrapper = document.getElementById('indFileRowsNavigator');
            const countText = document.getElementById('indFileTotalRowsText');

            if (rowPicker) {
                let optionsHtml = '';
                sheetParsedRows.forEach((r, idx) => {
                    const ph = findPhoneInRow(r) || `Row ${idx + 1}`;
                    const nm = findNameInRow(r) || 'Recipient';
                    optionsHtml += `<option value="${idx}">Row ${idx + 1}: ${escapeHtml(nm)} (${escapeHtml(ph)})</option>`;
                });
                rowPicker.innerHTML = optionsHtml;
            }

            if (navWrapper) navWrapper.style.display = 'block';
            if (countText) countText.innerText = `${sheetParsedRows.length} contacts found in "${file.name}"`;

            // Select and auto-fill row 0
            selectSheetRow(0);

            const submitBtn = document.getElementById('btnSubmitIndividual');
            if (submitBtn) {
                submitBtn.innerHTML = `<i data-lucide="send" style="width:14px;height:14px;"></i> Launch Direct Broadcast (${sheetParsedRows.length} Contacts)`;
                if (window.lucide) lucide.createIcons();
            }

            alert(`Successfully loaded ${sheetParsedRows.length} contacts from "${file.name}"! Row 1 data has been auto-filled.`);
        } catch (err) {
            console.error(err);
            alert('Failed to parse spreadsheet file: ' + err.message);
        }
    };

    if (fileName.endsWith('.xlsx') || fileName.endsWith('.xls')) {
        reader.readAsBinaryString(file);
    } else {
        reader.readAsText(file);
    }
}

// In-Browser Spreadsheet (.xlsx / .xls / .csv) Upload for Bulk Campaigns
function handleBulkSheetUpload(e) {
    const file = e.target.files ? e.target.files[0] : null;
    if (!file) return;

    const fileName = file.name.toLowerCase();
    const reader = new FileReader();

    reader.onload = function(evt) {
        try {
            const data = evt.target.result;
            let rows = [];

            if (fileName.endsWith('.xlsx') || fileName.endsWith('.xls')) {
                if (!window.XLSX) {
                    alert('Spreadsheet engine loading, please try again in a moment.');
                    return;
                }
                const workbook = XLSX.read(data, { type: 'binary' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                rows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });
            } else {
                // CSV parsing
                const text = (typeof data === 'string') ? data : new TextDecoder().decode(data);
                rows = parseCsvToObjects(text);
            }

            if (!rows || rows.length === 0) {
                alert('No data rows found in the uploaded file.');
                return;
            }

            bulkParsedRows = rows;

            // Set hidden JSON input for form submission
            const hiddenInp = document.getElementById('bulkParsedContactsJson');
            if (hiddenInp) hiddenInp.value = JSON.stringify(rows);

            // Update status card
            const statusCard = document.getElementById('bulkFileStatusCard');
            const nameText = document.getElementById('bulkFileNameText');
            const countBadge = document.getElementById('bulkFileCountBadge');
            const snippetBox = document.getElementById('bulkFilePreviewSnippet');

            if (nameText) nameText.innerText = file.name;
            if (countBadge) countBadge.innerText = `${rows.length} contacts found`;

            // Build snippet table for first 3 rows
            if (snippetBox) {
                const sampleRows = rows.slice(0, 3);
                const colKeys = Object.keys(rows[0] || {}).slice(0, 7);
                let tableHtml = '<div style="margin-top:8px; overflow-x:auto; max-height:130px; font-size:0.7rem; border-top:1px dashed #cbd5e1; padding-top:6px;"><table style="width:100%; border-collapse:collapse; text-align:left;">';
                tableHtml += '<thead><tr style="background:#f1f5f9; color:#475569;">' + colKeys.map(k => `<th style="padding:4px 6px; border:1px solid #e2e8f0; font-weight:700;">${escapeHtml(k)}</th>`).join('') + '</tr></thead>';
                tableHtml += '<tbody>';
                sampleRows.forEach((r, idx) => {
                    tableHtml += `<tr>` + colKeys.map(k => `<td style="padding:4px 6px; border:1px solid #e2e8f0; white-space:nowrap;">${escapeHtml(String(r[k] !== undefined ? r[k] : ''))}</td>`).join('') + `</tr>`;
                });
                tableHtml += '</tbody></table></div>';
                snippetBox.innerHTML = tableHtml;
            }

            if (statusCard) statusCard.style.display = 'block';

            // Auto-fill fallback inputs from Row 0
            if (rows.length > 0) {
                autoFillDynamicVarsFromObjectBulk(rows[0]);
            }

            updateBulkPreview();
            updateBulkCampaignSummary();
            if (window.lucide) lucide.createIcons();

            alert(`Successfully loaded ${rows.length} contacts from "${file.name}"! Ready to broadcast.`);
        } catch (err) {
            console.error(err);
            alert('Failed to parse spreadsheet file: ' + err.message);
        }
    };

    if (fileName.endsWith('.xlsx') || fileName.endsWith('.xls')) {
        reader.readAsBinaryString(file);
    } else {
        reader.readAsText(file);
    }
}

function parseCsvToObjects(csvText) {
    const lines = csvText.split(/\r\n|\n/).map(l => l.trim()).filter(l => l.length > 0);
    if (lines.length < 2) return [];

    const headers = lines[0].split(',').map(h => h.replace(/^["']|["']$/g, '').trim());
    const result = [];

    for (let i = 1; i < lines.length; i++) {
        const cells = lines[i].split(',').map(c => c.replace(/^["']|["']$/g, '').trim());
        const obj = {};
        headers.forEach((h, hIdx) => {
            obj[h] = cells[hIdx] || '';
        });
        result.push(obj);
    }
    return result;
}

function findPhoneInRow(row) {
    for (const [k, v] of Object.entries(row)) {
        const cleanK = String(k).toLowerCase().replace(/[\s_\-]/g, '');
        if (['phone', 'mobile', 'contact', 'number', 'whatsapp', 'mob', 'phonenumber'].includes(cleanK)) {
            return String(v).trim();
        }
    }
    return '';
}

function findNameInRow(row) {
    for (const [k, v] of Object.entries(row)) {
        const cleanK = String(k).toLowerCase().replace(/[\s_\-]/g, '');
        if (['name', 'customername', 'clientname', 'partyname', 'party', 'customer', 'client'].includes(cleanK)) {
            return String(v).trim();
        }
    }
    return '';
}

function selectSheetRow(idx) {
    if (!sheetParsedRows || idx < 0 || idx >= sheetParsedRows.length) return;
    currentSheetRowIndex = idx;
    const row = sheetParsedRows[idx];

    const phone = findPhoneInRow(row);
    const name = findNameInRow(row);

    const phoneInp = document.getElementById('indPhoneInput');
    const nameInp = document.getElementById('indNameInput');

    if (phoneInp && phone) phoneInp.value = phone;
    if (nameInp && name) nameInp.value = name;

    // Auto-fill template variables from this row
    autoFillDynamicVarsFromObject(row);

    const rowPicker = document.getElementById('indSheetRowPicker');
    if (rowPicker) rowPicker.value = idx;

    const countText = document.getElementById('indFileTotalRowsText');
    if (countText) {
        countText.innerText = `Row ${idx + 1} of ${sheetParsedRows.length}: ${name || 'Contact'} (${phone || 'No phone'})`;
    }

    updateIndividualPreview();
}

function navigateSheetRow(delta) {
    const newIdx = currentSheetRowIndex + delta;
    if (newIdx >= 0 && newIdx < sheetParsedRows.length) {
        selectSheetRow(newIdx);
    }
}

function switchSheetDataToBulk() {
    if (!sheetParsedRows || sheetParsedRows.length === 0) {
        alert('Please upload a spreadsheet first.');
        return;
    }

    switchMainTab('bulk');
    const targetSelect = document.getElementById('bulkTargetSelect');
    if (targetSelect) {
        targetSelect.value = 'csv';
        toggleTargetAudienceType('csv');
    }

    alert(`Transferred to Launch Bulk Campaign. Please attach the same spreadsheet to send all ${sheetParsedRows.length} contacts at once!`);
}

function renderSpecificContactsList(contacts) {
    const listContainer = document.getElementById('specificContactsList');
    if (!listContainer) return;

    let html = '';
    contacts.forEach(c => {
        const isChecked = selectedSpecificPhones.has(c.phone) ? 'checked' : '';
        const nameFormatted = formatContactNameDisplay(c);
        const phoneFormatted = formatContactPhoneDisplay(c.phone);
        html += `
        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.78rem; padding: 5px 8px; background: white; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer;">
            <input type="checkbox" value="${escapeHtml(c.phone)}" ${isChecked} onchange="handleSpecificPhoneCheck('${escapeHtml(c.phone)}', this.checked)">
            <span><strong>${escapeHtml(nameFormatted)}</strong> &bull; <span style="font-family:monospace; color:#0f766e; font-weight:600;">${phoneFormatted}</span></span>
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
            const phoneBody = document.getElementById('phoneChatBody');
            if (phoneBody) phoneBody.scrollTop = 0;
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

let currentTemplateGatewayMode = 'web_api';

function selectTemplateGateway(mode) {
    currentTemplateGatewayMode = mode || 'web_api';
    const cards = {
        'web_api': document.getElementById('card-gateway-web_api'),
        'meta': document.getElementById('card-gateway-meta'),
        'universal': document.getElementById('card-gateway-universal')
    };
    const radios = {
        'web_api': document.getElementById('radioGatewayWeb'),
        'meta': document.getElementById('radioGatewayMeta'),
        'universal': document.getElementById('radioGatewayUniversal')
    };

    Object.keys(cards).forEach(key => {
        if (cards[key]) {
            if (key === mode) {
                cards[key].style.borderColor = (key === 'meta') ? '#059669' : (key === 'universal' ? '#9333ea' : '#2563eb');
                cards[key].style.background = (key === 'meta') ? 'rgba(16,185,129,0.06)' : (key === 'universal' ? 'rgba(147,51,234,0.06)' : 'rgba(37,99,235,0.06)');
                cards[key].style.boxShadow = '0 2px 8px rgba(0,0,0,0.06)';
                if (radios[key]) radios[key].checked = true;
            } else {
                cards[key].style.borderColor = 'var(--border-color, #e2e8f0)';
                cards[key].style.background = 'var(--bg-card, #ffffff)';
                cards[key].style.boxShadow = 'none';
                if (radios[key]) radios[key].checked = false;
            }
        }
    });

    const infoElem = document.getElementById('builderGatewayInfo');
    const submitBtn = document.getElementById('btnSubmitTemplateModal');
    const btnText = document.getElementById('btnSubmitTemplateModalText');
    const isEditing = (document.getElementById('builderTemplateId')?.value > 0);

    if (mode === 'meta') {
        if (infoElem) {
            infoElem.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.74rem; color: #065f46;">
                    <i data-lucide="shield-check" style="width: 16px; height: 16px; color: #059669; flex-shrink: 0;"></i>
                    <span><strong>Meta Cloud API (WABA) Selected:</strong> Submits to Meta Graph API for official review. High volume verified broadcasts.</span>
                </div>
            `;
        }
        if (btnText) btnText.innerText = isEditing ? 'Update & Resubmit to Meta' : 'Submit to Meta for Approval & Save';
        if (submitBtn) {
            submitBtn.style.background = 'linear-gradient(135deg, #059669, #047857)';
            submitBtn.style.boxShadow = '0 4px 12px rgba(5, 150, 105, 0.25)';
        }
    } else if (mode === 'universal') {
        if (infoElem) {
            infoElem.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.74rem; color: #6b21a8;">
                    <i data-lucide="layers" style="width: 16px; height: 16px; color: #9333ea; flex-shrink: 0;"></i>
                    <span><strong>Universal Mode Selected:</strong> Instant ready on WhatsApp Web API + simultaneously submitted to Meta Cloud API.</span>
                </div>
            `;
        }
        if (btnText) btnText.innerText = isEditing ? 'Update Template (Universal)' : 'Save & Submit to Meta (Universal)';
        if (submitBtn) {
            submitBtn.style.background = 'linear-gradient(135deg, #9333ea, #7e22ce)';
            submitBtn.style.boxShadow = '0 4px 12px rgba(147, 51, 234, 0.25)';
        }
    } else {
        // web_api
        if (infoElem) {
            infoElem.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px; font-size: 0.74rem; color: #1e40af;">
                    <i data-lucide="check-circle-2" style="width: 16px; height: 16px; color: #2563eb; flex-shrink: 0;"></i>
                    <span><strong>WhatsApp Web API Selected:</strong> Saved locally for immediate reuse. No Meta approval required, 0% ban/rejection delays.</span>
                </div>
            `;
        }
        if (btnText) btnText.innerText = isEditing ? 'Update Template (Web API)' : 'Save Template for Web API';
        if (submitBtn) {
            submitBtn.style.background = 'linear-gradient(135deg, #2563eb, #1d4ed8)';
            submitBtn.style.boxShadow = '0 4px 12px rgba(37, 99, 235, 0.25)';
        }
    }

    if (window.lucide) lucide.createIcons();
}

function openCreateTemplateModal() {
    const modal = document.getElementById('interactiveTemplateModal');
    if (modal) {
        modal.classList.add('active');
        modal.classList.add('open');
        modal.style.display = 'flex';
    }

    // Reset to Create Mode
    const tIdInput = document.getElementById('builderTemplateId');
    if (tIdInput) tIdInput.value = '0';

    const headerTitle = document.getElementById('builderModalHeaderTitle');
    if (headerTitle) headerTitle.innerText = 'Interactive WhatsApp Template Builder';

    const form = document.getElementById('createTemplateForm');
    if (form) form.reset();

    const list = document.getElementById('builderButtonsList');
    if (list) {
        list.innerHTML = `
            <div class="btn-builder-row" style="display: flex; gap: 6px; align-items: center;">
                <input type="text" class="input-styled text-xs builder-btn-input" value="Pay AMC Online" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.1rem; padding: 4px;">&times;</button>
            </div>
            <div class="btn-builder-row" style="display: flex; gap: 6px; align-items: center;">
                <input type="text" class="input-styled text-xs builder-btn-input" value="Request Callback" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.1rem; padding: 4px;">&times;</button>
            </div>
        `;
    }

    const defaultMode = (activeGatewayMode === 'web_api') ? 'web_api' : (activeGatewayMode === 'meta' ? 'meta' : 'web_api');
    selectTemplateGateway(defaultMode);
    updateLivePhoneMockup();
    if (window.lucide) lucide.createIcons();
}

function editTemplate(id) {
    const t = savedTemplatesList.find(x => Number(x.id) === Number(id));
    if (!t) {
        alert('Template not found in library.');
        return;
    }

    const modal = document.getElementById('interactiveTemplateModal');
    if (modal) {
        modal.classList.add('active');
        modal.classList.add('open');
        modal.style.display = 'flex';
    }

    // Set Edit Mode
    const tIdInput = document.getElementById('builderTemplateId');
    if (tIdInput) tIdInput.value = t.id;

    const headerTitle = document.getElementById('builderModalHeaderTitle');
    if (headerTitle) headerTitle.innerText = 'Edit Template: ' + (t.title || '');

    // Populate inputs
    const titleInput = document.getElementById('builderTitle');
    if (titleInput) titleInput.value = t.title || '';

    const catSelect = document.getElementById('builderCategory');
    if (catSelect) catSelect.value = t.category || 'MARKETING';

    const headerInput = document.getElementById('builderHeaderText');
    if (headerInput) headerInput.value = t.header_text || '';

    const bodyInput = document.getElementById('builderBodyText');
    if (bodyInput) bodyInput.value = t.body_text || '';

    const footerInput = document.getElementById('builderFooterText');
    if (footerInput) footerInput.value = t.footer_text || '';

    // Populate buttons
    const list = document.getElementById('builderButtonsList');
    if (list) {
        list.innerHTML = '';
        let btns = [];
        if (t.buttons_json) {
            try { btns = JSON.parse(t.buttons_json); } catch(e) {}
        }
        if (Array.isArray(btns) && btns.length > 0) {
            btns.forEach(b => {
                const val = (typeof b === 'object') ? (b.title || '') : b;
                if (val) {
                    const div = document.createElement('div');
                    div.className = 'btn-builder-row';
                    div.style.display = 'flex';
                    div.style.gap = '6px';
                    div.style.alignItems = 'center';
                    div.innerHTML = `
                        <input type="text" class="input-styled text-xs builder-btn-input" value="${escapeHtml(val)}" placeholder="Button Title" oninput="updateLivePhoneMockup()">
                        <button type="button" class="btn-icon" onclick="removeButtonRow(this)" title="Remove" style="color: #ef4444; background: none; border: none; cursor: pointer; font-size: 1.1rem; padding: 4px;">&times;</button>
                    `;
                    list.appendChild(div);
                }
            });
        }
    }

    // Gateway
    const targetGateway = t.gateway_origin || 'web_api';
    selectTemplateGateway(targetGateway);

    // Update submit button text to reflect Edit
    const btnText = document.getElementById('btnSubmitTemplateModalText');
    if (btnText) {
        if (targetGateway === 'meta') {
            btnText.innerText = 'Update & Resubmit to Meta';
        } else if (targetGateway === 'universal') {
            btnText.innerText = 'Update Template (Universal)';
        } else {
            btnText.innerText = 'Update Template (Web API)';
        }
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

function useTemplateInSingle(slug) {
    useTemplateInIndividual(slug);
}

// Smart Cursor-Position-Aware Variable Insertion
function insertVarToBody(v) {
    const bodyArea = document.getElementById('builderBodyText');
    if (bodyArea) {
        const start = bodyArea.selectionStart;
        const end = bodyArea.selectionEnd;
        const text = bodyArea.value;
        if (typeof start === 'number') {
            const before = text.substring(0, start);
            const after  = text.substring(end, text.length);
            bodyArea.value = before + v + after;
            bodyArea.selectionStart = bodyArea.selectionEnd = start + v.length;
        } else {
            bodyArea.value += ' ' + v;
        }
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
    const headerInput = document.getElementById('builderHeaderText');
    const bodyInput   = document.getElementById('builderBodyText');
    const footerInput = document.getElementById('builderFooterText');

    const headerVal = headerInput ? headerInput.value.trim() : '';
    const bodyVal   = bodyInput ? bodyInput.value : '';
    const footerVal = footerInput ? footerInput.value.trim() : '';

    const defaultBody = 'Dear {name},\n\nYour Marg ERP Software AMC renewal of *{amount}* is due on *{due_date}*.\n\nTo ensure uninterrupted billing & GST filings, kindly renew your AMC.\n\nHelpline: *{phone}*';
    const textToFormat = bodyVal.trim() ? bodyVal : defaultBody;

    let formattedBody = escapeHtml(textToFormat)
        .replace(/{name}/g, '<span class="wa-var-tag">Rajesh Medical Store</span>')
        .replace(/{company}/g, '<span class="wa-var-tag">Marg Pharma</span>')
        .replace(/{phone}/g, '<span class="wa-var-tag">9532620736</span>')
        .replace(/{amount}/g, '<span class="wa-var-tag">₹3,500</span>')
        .replace(/{due_date}/g, '<span class="wa-var-tag">25 Aug 2026</span>')
        .replace(/\*([^\*]+)\*/g, '<b>$1</b>')
        .replace(/\_([^\_]+)\_/g, '<i>$1</i>')
        .replace(/\~([^\~]+)\~/g, '<del>$1</del>')
        .replace(/\n/g, '<br>');

    const mockHead = document.getElementById('mockupHeader');
    const mockBdy  = document.getElementById('mockupBody');
    const mockFtr  = document.getElementById('mockupFooter');

    if (mockHead) {
        if (headerVal) {
            mockHead.innerHTML = escapeHtml(headerVal);
            mockHead.style.display = 'block';
        } else if (!bodyVal.trim()) {
            mockHead.innerHTML = 'Marg ERP Software AMC Notice';
            mockHead.style.display = 'block';
        } else {
            mockHead.style.display = 'none';
        }
    }

    if (mockBdy) {
        mockBdy.innerHTML = formattedBody;
    }

    if (mockFtr) {
        if (footerVal) {
            mockFtr.innerHTML = escapeHtml(footerVal);
            mockFtr.style.display = 'block';
        } else if (!bodyVal.trim()) {
            mockFtr.innerHTML = 'Marg Soft Solution Support Desk';
            mockFtr.style.display = 'block';
        } else {
            mockFtr.style.display = 'none';
        }
    }

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
        mockBtnsContainer.style.display = btnsHtml ? 'flex' : 'none';
        if (window.lucide) lucide.createIcons();
    }
}

function handleTemplateSaveSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitTemplateModal');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:14px; height:14px;"></i> Saving...`;
    if (window.lucide) lucide.createIcons();

    function resetSubmitBtn() {
        btn.disabled = false;
        const isEditing = (document.getElementById('builderTemplateId')?.value > 0);
        if (currentTemplateGatewayMode === 'meta') {
            btn.innerHTML = `<i data-lucide="send" style="width:14px; height:14px;"></i> <span id="btnSubmitTemplateModalText">${isEditing ? 'Update & Resubmit to Meta' : 'Submit to Meta for Approval & Save'}</span>`;
        } else if (currentTemplateGatewayMode === 'universal') {
            btn.innerHTML = `<i data-lucide="layers" style="width:14px; height:14px;"></i> <span id="btnSubmitTemplateModalText">${isEditing ? 'Update Template (Universal)' : 'Save & Submit to Meta (Universal)'}</span>`;
        } else {
            btn.innerHTML = `<i data-lucide="save" style="width:14px; height:14px;"></i> <span id="btnSubmitTemplateModalText">${isEditing ? 'Update Template (Web API)' : 'Save Template for Web API'}</span>`;
        }
        if (window.lucide) lucide.createIcons();
    }

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
        resetSubmitBtn();

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
        resetSubmitBtn();
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

const campaignCooldowns = {};
let isProcessingCampaignLoop = false;

function runActiveCampaignsLoop() {
    if (runningCampaignIds.size === 0) return;

    const now = Date.now();

    runningCampaignIds.forEach(id => {
        const nextAllowed = campaignCooldowns[id] || 0;
        const remainingSec = Math.max(0, Math.ceil((nextAllowed - now) / 1000));

        // Update live countdown badge on campaign card
        const card = document.getElementById(`campCard-${id}`);
        if (card) {
            let badge = card.querySelector('.anti-ban-cooldown-badge');
            if (remainingSec > 0) {
                if (!badge) {
                    const progBox = card.querySelector('.campaign-progress-box');
                    if (progBox) {
                        badge = document.createElement('div');
                        badge.className = 'anti-ban-cooldown-badge';
                        badge.style.cssText = 'display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 3px 10px; border-radius: 9999px; margin-top: 8px;';
                        progBox.appendChild(badge);
                    }
                }
                if (badge) {
                    badge.style.display = 'inline-flex';
                    badge.innerHTML = `<i data-lucide="shield-check" style="width:12px;height:12px;color:#d97706;"></i> 🛡️ Anti-Ban Safe Delay: Next message in ${remainingSec}s...`;
                    if (window.lucide) lucide.createIcons();
                }
            } else if (badge) {
                badge.style.display = 'none';
            }
        }

        // Do not call API if cooldown has not elapsed yet
        if (now < nextAllowed) {
            return;
        }

        // Lock to prevent overlapping concurrent calls for this campaign
        campaignCooldowns[id] = now + 999999;

        fetch(`api/campaign-api.php?action=process_batch&id=${id}&batch_size=1`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.campaign) {
                    updateCampaignCardLive(data.campaign);
                    if (data.status === 'completed' || data.campaign.status === 'completed' || data.quota_reached || data.campaign.status === 'paused' || data.campaign.status === 'cancelled') {
                        runningCampaignIds.delete(id);
                        delete campaignCooldowns[id];
                        const cCard = document.getElementById(`campCard-${id}`);
                        const cBadge = cCard ? cCard.querySelector('.anti-ban-cooldown-badge') : null;
                        if (cBadge) cBadge.remove();
                        fetchCampaigns();
                        return;
                    }

                    // Respect Anti-ban delay (e.g. 60s for 1 min)
                    const delaySec = parseInt(data.delay_seconds !== undefined ? data.delay_seconds : (data.campaign.delay_seconds || 0));
                    const safeCooldownSec = Math.max(1, delaySec);
                    campaignCooldowns[id] = Date.now() + (safeCooldownSec * 1000);
                } else {
                    if (data.status === 'paused' || data.quota_reached) {
                        runningCampaignIds.delete(id);
                        delete campaignCooldowns[id];
                        fetchCampaigns();
                    } else {
                        // Safe retry delay
                        campaignCooldowns[id] = Date.now() + 10000;
                    }
                }
            })
            .catch(err => {
                console.error(err);
                // On temporary network lag or server hiccup, wait 15s before next attempt
                campaignCooldowns[id] = Date.now() + 15000;
            });
    });
}

function updateCampaignCardLive(c) {
    if (!c || !c.id) return;
    const card = document.getElementById(`campCard-${c.id}`);
    if (!card) {
        fetchCampaigns();
        return;
    }
    const total = Math.max(1, parseInt(c.total_contacts || 1));
    const sent = parseInt(c.sent_count || 0);
    const pending = parseInt(c.pending_count || 0);
    const failed = parseInt(c.failed_count || 0);
    const pct = Math.min(100, Math.round((sent / total) * 100));

    // Update dispatch text
    const progText = card.querySelector('.campaign-progress-box strong');
    if (progText) progText.innerText = `${sent} / ${total} Messages Dispatched`;

    // Update percentage text
    const pctText = card.querySelector('.campaign-progress-box span[style*="font-weight: 800"]');
    if (pctText) {
        pctText.innerText = `${pct}%`;
        pctText.style.color = (pct === 100) ? '#10b981' : '#2563eb';
    }

    // Update progress bar fill
    const fill = card.querySelector('.progress-bar-fill-sleek');
    if (fill) {
        fill.style.width = `${pct}%`;
        if (pct === 100) {
            fill.style.background = 'linear-gradient(90deg, #10b981, #059669)';
        }
    }

    // Update pills
    const pillSent = card.querySelector('.stat-pill-sent');
    if (pillSent) pillSent.innerHTML = `<i data-lucide="check" style="width: 11px; height: 11px;"></i> Sent: ${sent}`;

    const pillPend = card.querySelector('.stat-pill-pending');
    if (pillPend) pillPend.innerHTML = `<i data-lucide="clock" style="width: 11px; height: 11px;"></i> Pending: ${pending}`;

    const pillFail = card.querySelector('.stat-pill-failed');
    if (pillFail) pillFail.innerHTML = `<i data-lucide="alert-triangle" style="width: 11px; height: 11px;"></i> Failed: ${failed}`;

    const dailyLimit = parseInt(c.daily_limit || 0);
    const todaySent = parseInt(c.today_sent_count || 0);
    const pillQuota = card.querySelector('.stat-pill-quota');
    if (pillQuota && dailyLimit > 0) {
        pillQuota.innerHTML = `<i data-lucide="shield" style="width: 11px; height: 11px;"></i> Today's Batch: ${todaySent} / ${dailyLimit}`;
    }

    if (window.lucide) lucide.createIcons();
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
