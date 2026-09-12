<?php
/**
 * Marg ERP & WhatsApp Web API - Tenant Client Dedicated Dashboard
 * Ultra-modern, responsive SaaS WhatsApp Hub tailored for clients.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$active_tenant_db = $_SESSION['impersonate_tenant_db'] ?? $_SESSION['tenant_db'] ?? '';
$tenant_code = $_SESSION['tenant_code'] ?? '';
$tenant_name = $_SESSION['tenant_name'] ?? $_SESSION['company_name'] ?? 'Client Workspace';
$user_name = $_SESSION['user_name'] ?? 'Account Owner';
$user_email = $_SESSION['user_email'] ?? '';

// Fetch tenant company profile safely
$owner_phone = '';
$plan_name = 'WhatsApp Web API Active';
$registered_date = '';

global $pdo_master, $pdo;
$db_master = $pdo_master ?? $pdo;

if ($db_master) {
    try {
        $stmtT = $db_master->prepare("SELECT * FROM tenant_companies WHERE (db_name = ? OR company_code = ? OR owner_email = ?) AND LOWER(company_code) != 'master' ORDER BY id DESC LIMIT 1");
        $stmtT->execute([$active_tenant_db, $tenant_code, $user_email]);
        $tData = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($tData) {
            $tenant_name = !empty($tData['company_name']) ? $tData['company_name'] : $tenant_name;
            $tenant_code = !empty($tData['company_code']) ? $tData['company_code'] : $tenant_code;
            $owner_phone = $tData['owner_phone'] ?? '';
            $plan_name = $tData['plan_name'] ?? ($tData['plan'] ?? 'WhatsApp Web API Active');
            $registered_date = !empty($tData['created_at']) ? date('d M Y', strtotime($tData['created_at'])) : '';
            
            $_SESSION['tenant_name'] = $tenant_name;
            $_SESSION['tenant_code'] = $tenant_code;
        }
    } catch (\PDOException $ex) {}
}

// Fetch WhatsApp Gateway Connection Status from tenant's isolated settings
$is_waba_connected = false;
$connected_phone = '';
$gateway_mode = 'Self-Hosted WhatsApp Web Engine';
$last_synced = 'Never';

if ($pdo) {
    try {
        $stmtW = $pdo->query("SELECT * FROM merchant_waba_settings ORDER BY id DESC LIMIT 1");
        $wabaSettings = $stmtW ? $stmtW->fetch(PDO::FETCH_ASSOC) : null;
        if ($wabaSettings) {
            $gt = strtolower($wabaSettings['gateway_type'] ?? 'web_api');
            $gateway_mode = ($gt === 'meta_cloud') ? 'Official Meta Cloud WABA' : 'WhatsApp Web Engine';
            if (!empty($wabaSettings['phone_number'])) {
                $connected_phone = $wabaSettings['phone_number'];
            }
            $wStatus = strtolower($wabaSettings['whatsapp_status'] ?? '');
            if (in_array($wStatus, ['connected', 'authenticated', 'open', 'active'])) {
                $is_waba_connected = true;
            } elseif (!empty($connected_phone)) {
                $is_waba_connected = true;
            }
            $last_synced = !empty($wabaSettings['updated_at']) ? date('d M, h:i A', strtotime($wabaSettings['updated_at'])) : 'Recently';
        }
    } catch (\PDOException $ex) {}
}

// Fetch Metrics strictly from tenant's isolated message logs
$messages_today = 0;
$messages_total = 0;
$messages_inbound = 0;
$messages_failed = 0;
$recent_logs = [];

if ($pdo) {
    try {
        $stmtToday = $pdo->query("SELECT COUNT(*) FROM message_logs WHERE DATE(created_at) = CURRENT_DATE() AND direction = 'OUTBOUND'");
        $messages_today = $stmtToday ? (int)$stmtToday->fetchColumn() : 0;

        $stmtTotal = $pdo->query("SELECT COUNT(*) FROM message_logs WHERE direction = 'OUTBOUND'");
        $messages_total = $stmtTotal ? (int)$stmtTotal->fetchColumn() : 0;

        $stmtInbound = $pdo->query("SELECT COUNT(*) FROM message_logs WHERE direction = 'INBOUND'");
        $messages_inbound = $stmtInbound ? (int)$stmtInbound->fetchColumn() : 0;

        $stmtFail = $pdo->query("SELECT COUNT(*) FROM message_logs WHERE status IN ('failed', 'error', 'undelivered')");
        $messages_failed = $stmtFail ? (int)$stmtFail->fetchColumn() : 0;

        $stmtLogs = $pdo->query("SELECT * FROM message_logs ORDER BY id DESC LIMIT 15");
        if ($stmtLogs) {
            $recent_logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\PDOException $ex) {}
}

$delivery_rate = ($messages_total > 0) ? round((($messages_total - $messages_failed) / $messages_total) * 100, 1) : 100;
?>

<!-- Custom Embedded CSS for Pixel-Perfect Multi-Column Layout -->
<style>
.tenant-dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding-bottom: 3rem;
}

/* Hero Banner */
.tenant-hero-banner {
    background: linear-gradient(135deg, #0b132b 0%, #1c2541 60%, #1e293b 100%);
    border-radius: 20px;
    padding: 2.25rem 2.5rem;
    color: #ffffff;
    position: relative;
    overflow: hidden;
    margin-bottom: 2rem;
    box-shadow: 0 10px 25px -5px rgba(11, 19, 43, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.08);
}
.tenant-hero-banner::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(37, 211, 102, 0.15) 0%, transparent 65%);
    border-radius: 50%;
    pointer-events: none;
}
.tenant-hero-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2rem;
    position: relative;
    z-index: 2;
}
.tenant-brand-info {
    flex: 1;
    min-width: 280px;
}
.tenant-title {
    font-size: 2.1rem;
    font-weight: 800;
    margin: 0.5rem 0 0.4rem 0;
    color: #ffffff;
    font-family: var(--font-heading, 'Outfit', sans-serif);
    letter-spacing: -0.02em;
    line-height: 1.15;
}
.tenant-meta {
    margin: 0;
    font-size: 0.95rem;
    color: #94a3b8;
}

/* Gateway Status Card in Hero */
.gateway-status-capsule {
    background: rgba(255, 255, 255, 0.07);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    min-width: 380px;
}
.gateway-icon-wrap {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.gateway-icon-wrap.connected {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.4);
}
.gateway-icon-wrap.disconnected {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.4);
}
.gateway-info-wrap {
    flex: 1;
}
.gateway-sub-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    margin-bottom: 2px;
}
.gateway-main-status {
    font-size: 1.15rem;
    font-weight: 700;
    color: #ffffff;
    font-family: var(--font-heading, sans-serif);
    line-height: 1.2;
}
.gateway-live-text {
    font-size: 0.8rem;
    margin-top: 3px;
    font-weight: 500;
}
.gateway-action-btn {
    padding: 0.65rem 1.25rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.85rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.btn-emerald {
    background: #25D366;
    color: #0b132b;
    box-shadow: 0 4px 14px rgba(37, 211, 102, 0.3);
}
.btn-emerald:hover {
    background: #20bd5a;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
}
.btn-glass {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.2);
}
.btn-glass:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
}

/* 4-Column KPI Grid */
.tenant-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.tenant-kpi-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 16px;
    padding: 1.35rem 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.tenant-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(15, 23, 42, 0.08);
}
.kpi-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}
.kpi-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--text-muted, #64748b);
}
.kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kpi-num {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
    font-family: var(--font-heading, sans-serif);
    line-height: 1.1;
}
.kpi-foot {
    margin-top: 0.75rem;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

/* 4-Column Action Pillars Grid */
.tenant-actions-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.action-pillar-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 16px;
    padding: 1.25rem 1.35rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 1.15rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.action-pillar-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.1);
    border-color: var(--primary, #3b82f6);
}
.pillar-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.pillar-content {
    flex: 1;
    min-width: 0;
}
.pillar-title {
    font-size: 0.98rem;
    font-weight: 700;
    color: var(--text-main, #0f172a);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pillar-sub {
    font-size: 0.8rem;
    color: var(--text-muted, #64748b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Table Section Card */
.activity-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 18px;
    padding: 1.75rem 2rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
}
.activity-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
}
.activity-title-group {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}
.activity-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
    margin: 0;
    font-family: var(--font-heading, sans-serif);
}
.activity-sub {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-muted, #64748b);
}

/* Modern Empty State */
.empty-activity-state {
    text-align: center;
    padding: 3.5rem 1rem;
}
.empty-pulse-circle {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: rgba(37, 211, 102, 0.12);
    color: #25D366;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.25rem auto;
    box-shadow: 0 0 0 10px rgba(37, 211, 102, 0.05);
}
.empty-heading {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
    margin-bottom: 0.4rem;
}
.empty-desc {
    font-size: 0.92rem;
    color: var(--text-muted, #64748b);
    max-width: 460px;
    margin: 0 auto 1.5rem auto;
    line-height: 1.5;
}

/* Responsive Breakpoints */
@media (max-width: 1100px) {
    .tenant-kpi-grid, .tenant-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .tenant-hero-flex {
        flex-direction: column;
        align-items: flex-start;
    }
    .gateway-status-capsule {
        width: 100%;
        min-width: 100%;
    }
}
@media (max-width: 640px) {
    .tenant-kpi-grid, .tenant-actions-grid {
        grid-template-columns: 1fr;
    }
    .tenant-hero-banner {
        padding: 1.5rem;
    }
    .gateway-status-capsule {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<div class="tenant-dashboard-container">
    <?php if (hasAccess('workspace_dashboard', $_SESSION['user_role'] ?? '')): ?>
        <div class="flex align-center gap-2 mb-4 p-1 border-radius-md" style="background: var(--bg-card); border: 1px solid var(--border-color); width: fit-content; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <a href="index.php?page=dashboard&tab=whatsapp" class="btn btn-sm text-xs flex align-center gap-2" style="font-weight: 700; background: #10b981; border: none; color: white; border-radius: 8px; padding: 0.5rem 1.1rem; box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);">
                <i data-lucide="message-square-dashed" style="width: 15px; height: 15px;"></i>
                <span>WhatsApp Hub & Gateway</span>
            </a>
            <a href="index.php?page=dashboard&tab=workspace" class="btn btn-sm btn-secondary text-xs flex align-center gap-2" style="font-weight: 600; border-radius: 8px; padding: 0.5rem 1.1rem;">
                <i data-lucide="layout-dashboard" style="width: 15px; height: 15px;"></i>
                <span>Workspace CRM Dashboard</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Notice Banner for CRM URL redirection -->
    <?php if (isset($_GET['restricted'])): ?>
        <div class="alert alert-info" style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 12px; padding: 1rem 1.25rem; color: #1e40af; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.92rem;">
                <i data-lucide="shield-alert" style="width: 20px; height: 20px; color: #2563eb; flex-shrink: 0;"></i>
                <span><strong>Access Restricted:</strong> You do not currently have power permission enabled for that workspace module. Contact your administrator to enable access.</span>
            </div>
            <button type="button" onclick="this.parentElement.remove();" style="background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #6b7280; line-height: 1;">&times;</button>
        </div>
    <?php endif; ?>

    <!-- 1. Hero Header Banner -->
    <div class="tenant-hero-banner">
        <div class="tenant-hero-flex">
            <div class="tenant-brand-info">
                <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                    <span style="background: #25D366; color: #0b132b; font-weight: 800; font-size: 0.72rem; letter-spacing: 0.06em; padding: 3px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                        <i data-lucide="zap" style="width: 12px; height: 12px;"></i> WHATSAPP WEB API
                    </span>
                    <span style="background: rgba(255, 255, 255, 0.12); color: #e2e8f0; font-size: 0.72rem; font-weight: 600; padding: 3px 10px; border-radius: 6px;">
                        CLIENT ID: <?php echo htmlspecialchars(strtoupper($tenant_code)); ?>
                    </span>
                </div>
                
                <h1 class="tenant-title">
                    <?php echo htmlspecialchars($tenant_name); ?>
                </h1>
                
                <p class="tenant-meta">
                    Owner: <strong style="color: #ffffff;"><?php echo htmlspecialchars($user_name); ?></strong>
                    <?php if ($owner_phone): ?>
                        &bull; <i data-lucide="phone" style="width: 13px; height: 13px; vertical-align: -2px;"></i> <?php echo htmlspecialchars($owner_phone); ?>
                    <?php endif; ?>
                    &bull; Status: <span style="color: #6ee7b7; font-weight: 600;">Active Account</span>
                </p>
            </div>

            <!-- WhatsApp Gateway Quick Status Capsule -->
            <div class="gateway-status-capsule">
                <div class="gateway-icon-wrap <?php echo $is_waba_connected ? 'connected' : 'disconnected'; ?>">
                    <i data-lucide="<?php echo $is_waba_connected ? 'check-circle' : 'qr-code'; ?>" style="width: 26px; height: 26px;"></i>
                </div>
                <div class="gateway-info-wrap">
                    <div class="gateway-sub-label">WhatsApp Number</div>
                    <div class="gateway-main-status">
                        <?php echo $is_waba_connected ? htmlspecialchars($connected_phone ?: 'Connected & Ready') : 'Pairing Required'; ?>
                    </div>
                    <div class="gateway-live-text" style="color: <?php echo $is_waba_connected ? '#34d399' : '#fbbf24'; ?>;">
                        <?php echo $is_waba_connected ? '🟢 Gateway Online & Dispatched' : '⚠️ Scan QR Code to connect'; ?>
                    </div>
                </div>
                <div>
                    <a href="index.php?page=merchant_waba_settings" class="gateway-action-btn <?php echo $is_waba_connected ? 'btn-glass' : 'btn-emerald'; ?>">
                        <i data-lucide="<?php echo $is_waba_connected ? 'sliders' : 'scan'; ?>" style="width: 15px; height: 15px;"></i>
                        <span><?php echo $is_waba_connected ? 'Manage' : 'Scan QR'; ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. 4-Column KPI Metrics Grid -->
    <div class="tenant-kpi-grid">
        <!-- Card 1: Today's Messages -->
        <div class="tenant-kpi-card">
            <div class="kpi-head">
                <span class="kpi-label">Messages Today</span>
                <div class="kpi-icon" style="background: rgba(37, 211, 102, 0.12); color: #25D366;">
                    <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                </div>
            </div>
            <div class="kpi-num"><?php echo number_format($messages_today); ?></div>
            <div class="kpi-foot" style="color: #10b981;">
                <i data-lucide="activity" style="width: 13px; height: 13px;"></i>
                <span>Real-time Dispatches</span>
            </div>
        </div>

        <!-- Card 2: Total Dispatches -->
        <div class="tenant-kpi-card">
            <div class="kpi-head">
                <span class="kpi-label">Total Dispatches</span>
                <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.12); color: #3b82f6;">
                    <i data-lucide="message-square" style="width: 18px; height: 18px;"></i>
                </div>
            </div>
            <div class="kpi-num"><?php echo number_format($messages_total); ?></div>
            <div class="kpi-foot" style="color: var(--text-muted, #64748b);">
                <i data-lucide="history" style="width: 13px; height: 13px; color: #3b82f6;"></i>
                <span>All-time Outbound</span>
            </div>
        </div>

        <!-- Card 3: Replies Received -->
        <div class="tenant-kpi-card">
            <div class="kpi-head">
                <span class="kpi-label">Replies Received</span>
                <div class="kpi-icon" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6;">
                    <i data-lucide="corner-down-left" style="width: 18px; height: 18px;"></i>
                </div>
            </div>
            <div class="kpi-num"><?php echo number_format($messages_inbound); ?></div>
            <div class="kpi-foot" style="color: var(--text-muted, #64748b);">
                <i data-lucide="users" style="width: 13px; height: 13px; color: #8b5cf6;"></i>
                <span>Customer Inbound Chats</span>
            </div>
        </div>

        <!-- Card 4: Gateway Status -->
        <div class="tenant-kpi-card">
            <div class="kpi-head">
                <span class="kpi-label">Gateway Engine</span>
                <div class="kpi-icon" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                    <i data-lucide="server" style="width: 18px; height: 18px;"></i>
                </div>
            </div>
            <div class="kpi-num" style="font-size: 1.45rem;">
                <?php echo $is_waba_connected ? '🟢 Online' : '⚠️ Offline'; ?>
            </div>
            <div class="kpi-foot" style="color: #10b981;">
                <i data-lucide="clock" style="width: 13px; height: 13px;"></i>
                <span>Sync: <?php echo htmlspecialchars($last_synced); ?></span>
            </div>
        </div>
    </div>

    <!-- 3. 4-Column Quick Action Navigation Pillars -->
    <div class="tenant-actions-grid">
        <?php if (hasAccess('merchant_waba_settings', $_SESSION['user_role'] ?? '')): ?>
        <a href="index.php?page=merchant_waba_settings" class="action-pillar-card">
            <div class="pillar-icon-box" style="background: rgba(37, 211, 102, 0.12); color: #25D366;">
                <i data-lucide="qr-code" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="pillar-content">
                <div class="pillar-title">WhatsApp QR & Setup</div>
                <div class="pillar-sub">Scan QR & pair your number</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (hasAccess('team_inbox', $_SESSION['user_role'] ?? '')): ?>
        <a href="index.php?page=team_inbox" class="action-pillar-card">
            <div class="pillar-icon-box" style="background: rgba(59, 130, 246, 0.12); color: #3b82f6;">
                <i data-lucide="message-square" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="pillar-content">
                <div class="pillar-title">Live Chat & Inbox</div>
                <div class="pillar-sub">Reply to customer messages</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (hasAccess('broadcast_campaigns', $_SESSION['user_role'] ?? '')): ?>
        <a href="index.php?page=broadcast_campaigns" class="action-pillar-card">
            <div class="pillar-icon-box" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6;">
                <i data-lucide="send" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="pillar-content">
                <div class="pillar-title">WhatsApp Campaigns</div>
                <div class="pillar-sub">Create marketing broadcasts</div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (hasAccess('bot_flows', $_SESSION['user_role'] ?? '')): ?>
        <a href="index.php?page=bot_flows" class="action-pillar-card">
            <div class="pillar-icon-box" style="background: rgba(6, 182, 212, 0.12); color: #06b6d4;">
                <i data-lucide="bot" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="pillar-content">
                <div class="pillar-title">Bots & Auto-Reply</div>
                <div class="pillar-sub">Automate keyword responses</div>
            </div>
        </a>
        <?php endif; ?>
    </div>

    <!-- 4. Recent WhatsApp Messages Table Section -->
    <div class="activity-card">
        <div class="activity-header">
            <div class="activity-title-group">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(59, 130, 246, 0.1); color: #3b82f6; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="file-text" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="activity-title">Recent WhatsApp Messages</h3>
                    <p class="activity-sub">Live activity log of all messages dispatched from your account</p>
                </div>
            </div>
            <a href="index.php?page=team_inbox" class="btn btn-secondary" style="font-size: 0.85rem; font-weight: 600; text-decoration: none;">
                <span>Open Team Inbox</span>
                <i data-lucide="arrow-right" style="width: 14px; height: 14px;"></i>
            </a>
        </div>

        <?php if (empty($recent_logs)): ?>
            <!-- Ultra Clean Empty State -->
            <div class="empty-activity-state">
                <div class="empty-pulse-circle">
                    <i data-lucide="message-square" style="width: 30px; height: 30px;"></i>
                </div>
                <h4 class="empty-heading">No WhatsApp Messages Yet</h4>
                <p class="empty-desc">
                    <?php if (!$is_waba_connected): ?>
                        Your WhatsApp number is not yet paired. Scan the QR code with your WhatsApp app to start sending messages.
                    <?php else: ?>
                        Your WhatsApp number is connected and ready. Dispatched messages and customer chats will appear here in real time.
                    <?php endif; ?>
                </p>
                <?php if (!$is_waba_connected): ?>
                    <a href="index.php?page=merchant_waba_settings" class="btn-emerald" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.75rem 1.75rem; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 0.95rem;">
                        <i data-lucide="qr-code" style="width: 18px; height: 18px;"></i>
                        <span>Scan QR & Connect WhatsApp</span>
                    </a>
                <?php else: ?>
                    <a href="index.php?page=broadcast_campaigns" class="btn btn-primary" style="font-weight: 600; padding: 0.75rem 1.5rem; border-radius: 10px; text-decoration: none;">
                        <span>Create First Broadcast Campaign</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color, #e2e8f0); text-align: left; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted, #64748b);">
                            <th style="padding: 12px 14px;">Date & Time</th>
                            <th style="padding: 12px 14px;">Recipient / Sender</th>
                            <th style="padding: 12px 14px;">Message</th>
                            <th style="padding: 12px 14px;">Direction</th>
                            <th style="padding: 12px 14px;">Status</th>
                            <th style="padding: 12px 14px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                            <?php
                            $status = strtolower($log['status'] ?? 'sent');
                            $badgeStyle = 'background: #3b82f6; color: #fff;';
                            if (in_array($status, ['delivered', 'read', 'success', 'received'])) {
                                $badgeStyle = 'background: #10b981; color: #fff;';
                            } elseif (in_array($status, ['failed', 'error', 'undelivered'])) {
                                $badgeStyle = 'background: #ef4444; color: #fff;';
                            }
                            $phone = $log['recipient_or_sender'] ?? $log['recipient_phone'] ?? 'N/A';
                            $direction = strtoupper($log['direction'] ?? 'OUTBOUND');
                            $msgBody = !empty($log['message_body']) ? mb_strimwidth(strip_tags($log['message_body']), 0, 55, '...') : '-';
                            $timeStr = !empty($log['created_at']) ? date('d M Y, h:i A', strtotime($log['created_at'])) : 'Recent';
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color, #f1f5f9); transition: background 0.15s ease;">
                                <td style="padding: 12px 14px; color: var(--text-muted, #64748b); font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($timeStr); ?>
                                </td>
                                <td style="padding: 12px 14px; font-weight: 700; font-family: monospace; color: var(--text-main, #0f172a);">
                                    <?php echo htmlspecialchars($phone); ?>
                                </td>
                                <td style="padding: 12px 14px; color: var(--text-main, #0f172a); max-width: 300px;">
                                    <?php echo htmlspecialchars($msgBody); ?>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <span style="font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; <?php echo ($direction === 'INBOUND') ? 'background: rgba(59, 130, 246, 0.1); color: #2563eb;' : 'background: rgba(100, 116, 139, 0.1); color: #475569;'; ?>">
                                        <?php echo $direction; ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 14px;">
                                    <span style="padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; <?php echo $badgeStyle; ?>">
                                        <?php echo htmlspecialchars($log['status'] ?? 'Sent'); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <a href="index.php?page=team_inbox&chat=<?php echo urlencode($phone); ?>" class="btn-icon" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; text-decoration: none;" title="Open Chat">
                                        <i data-lucide="message-square" style="width: 15px; height: 15px;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    function initIcons() {
        if (window.lucide) {
            lucide.createIcons();
        }
    }
    initIcons();
    document.addEventListener('DOMContentLoaded', initIcons);
    setTimeout(initIcons, 100);
})();
</script>
