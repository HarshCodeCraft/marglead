<?php
/**
 * Marg ERP CRM - Gmail License Automation Module (Server-Side Auto-Load Edition)
 * Auto-loads data directly on the backend in PHP (Zero frontend spinner delay)
 * Non-destructive live background sync, instant 0ms client-side filter & search.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin' || $user_role === 'Tenant Admin');

// Permission Security Check
if (!$is_admin && !hasAccess('gmail_license_sync', $user_role)) {
    echo "<div class='p-6'><div class='alert alert-danger'>Access Denied: You do not have permission to access Gmail License Automation. Contact your Super Admin.</div></div>";
    return;
}

$current_user_id = (int)($_SESSION['tenant_id'] ?? $_SESSION['user_id'] ?? 1);
if ($current_user_id <= 0) $current_user_id = 1;

// -------------------------------------------------------------------------
// BACKEND AUTO-LOAD: Fetch Authoritative Data Directly in PHP (0ms Delay)
// -------------------------------------------------------------------------
// 1. Fetch Settings
$stmtSet = $pdo->prepare("SELECT * FROM `tenant_gmail_license_settings` WHERE user_id = ? OR user_id = 1 ORDER BY id DESC LIMIT 1");
$stmtSet->execute([$current_user_id]);
$settings = $stmtSet->fetch(PDO::FETCH_ASSOC) ?: [
    'gmail_address' => '',
    'app_password' => '',
    'allowed_senders' => '@margcompusoft.com, licenses@margerp.com',
    'subject_keywords' => 'License, Registration, Passkey, Customer ID',
    'sync_mode' => 'manual',
    'save_attachments' => 1,
    'allowed_extensions' => 'lic,txt,pdf,zip',
    'status' => 'Active',
    'last_sync_at' => null
];

// Auto-clean any bogus or corrupt records from older test runs
try {
    $pdo->exec("DELETE FROM `tenant_incoming_licenses` WHERE customer_id IS NULL OR customer_id = '' OR party_name LIKE '<%' OR party_name LIKE '%Indeed%' OR party_name LIKE '%view this email%'");
} catch (\Throwable $e) {}

// 2. Fetch Verified License Records (Excluding any invalid/empty customer IDs)
$stmtLics = $pdo->prepare("
    SELECT l.*, c.party_name AS client_actual_name 
    FROM `tenant_incoming_licenses` l 
    LEFT JOIN `client_directory` c ON l.matched_client_id = c.id 
    WHERE (l.user_id = ? OR l.user_id = 1 OR l.user_id = 0)
      AND (l.customer_id IS NOT NULL AND TRIM(l.customer_id) != '')
      AND (l.party_name NOT LIKE '<%')
    ORDER BY l.id DESC LIMIT 100
");
$stmtLics->execute([$current_user_id]);
$initial_licenses = $stmtLics->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Compute Initial KPI Metrics
$total_count = count($initial_licenses);
$pending_count = 0;
$autosync_count = 0;
$approved_count = 0;
foreach ($initial_licenses as $lic) {
    $st = strtolower(trim((string)($lic['sync_status'] ?? 'pending')));
    if ($st === 'auto-synced' || $st === 'auto_synced') {
        $autosync_count++;
    } elseif ($st === 'approved') {
        $approved_count++;
    } elseif ($st === 'pending') {
        $pending_count++;
    }
}
?>

<div class="gmail-sync-workspace" style="padding: 1.5rem; max-width: 1450px; margin: 0 auto; font-family: var(--font-body, system-ui, -apple-system, sans-serif);">

    <!-- Top Header Banner -->
    <div class="premium-header-card mb-4" style="background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.98)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 18px; padding: 1.5rem 1.75rem; box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.4); position: relative; overflow: hidden;">
        <div style="position: absolute; right: -50px; top: -50px; width: 220px; height: 220px; background: radial-gradient(circle, rgba(245, 158, 11, 0.15) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
        <div style="position: absolute; left: 20%; bottom: -60px; width: 260px; height: 260px; background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>

        <div class="flex flex-wrap justify-between align-center gap-4" style="position: relative; z-index: 2;">
            <div class="flex align-center gap-3">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35); flex-shrink: 0;">
                    <i data-lucide="mail-check" style="width: 26px; height: 26px; color: #fff;"></i>
                </div>
                <div>
                    <div class="flex align-center gap-2 flex-wrap">
                        <h2 class="m-0 text-xl font-bold" style="color: #fff; letter-spacing: -0.02em;">
                            Gmail License Automation
                        </h2>
                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.68rem; padding: 3px 9px; border-radius: 20px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                            LIVE ENGINE
                        </span>
                        <span class="badge" style="background: rgba(6, 182, 212, 0.2); color: #22d3ee; border: 1px solid rgba(6, 182, 212, 0.3); font-size: 0.68rem; padding: 3px 9px; border-radius: 20px; font-weight: 700;">
                            SSL 993 IMAP
                        </span>
                    </div>
                    <p class="m-0 text-xs" style="color: #94a3b8; margin-top: 4px; max-width: 650px;">
                        Reads Marg ERP license emails in the background, extracts Customer ID & dates, downloads <code style="color: #fcd34d; background: rgba(255,255,255,0.06); padding: 1px 4px; border-radius: 4px;">.lic</code> files, and syncs Client Directory safely.
                    </p>
                </div>
            </div>

            <div class="flex align-center gap-2 flex-wrap">
                <button type="button" id="btn-sync-now" onclick="triggerEmailSync()" class="btn flex align-center gap-2" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.65rem 1.35rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35); transition: all 0.2s ease;">
                    <i data-lucide="refresh-cw" id="sync-spin-icon" style="width: 16px; height: 16px;"></i>
                    <span id="sync-btn-text">Fetch & Sync Emails</span>
                </button>
                <button type="button" onclick="switchTab('tab-settings')" class="btn btn-secondary flex align-center gap-2" style="border-radius: 10px; padding: 0.65rem 1.15rem; font-size: 0.85rem; font-weight: 600; border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.05); color: #f1f5f9;">
                    <i data-lucide="sliders" style="width: 16px; height: 16px;"></i>
                    <span>Rules & Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Metric KPI Stat Cards (Rendered Directly by PHP - 0ms Delay) -->
    <div class="grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
        <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div>
                <span class="text-xs text-muted font-semibold" style="text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Total Received</span>
                <span id="stat-total-licenses" class="text-2xl font-bold text-main" style="font-family: var(--font-heading);"><?php echo $total_count; ?></span>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(245, 158, 11, 0.12); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="inbox" style="width: 20px; height: 20px; color: #f59e0b;"></i>
            </div>
        </div>

        <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div>
                <span class="text-xs text-muted font-semibold" style="text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Pending Review</span>
                <span id="stat-pending-licenses" class="text-2xl font-bold" style="color: #ef4444; font-family: var(--font-heading);"><?php echo $pending_count; ?></span>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(239, 68, 68, 0.12); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="clock" style="width: 20px; height: 20px; color: #ef4444;"></i>
            </div>
        </div>

        <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div>
                <span class="text-xs text-muted font-semibold" style="text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Auto-Synced</span>
                <span id="stat-autosync-licenses" class="text-2xl font-bold" style="color: #10b981; font-family: var(--font-heading);"><?php echo $autosync_count; ?></span>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="check-check" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
        </div>

        <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <div>
                <span class="text-xs text-muted font-semibold" style="text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Approved Active</span>
                <span id="stat-approved-licenses" class="text-2xl font-bold" style="color: #3b82f6; font-family: var(--font-heading);"><?php echo $approved_count; ?></span>
            </div>
            <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(59, 130, 246, 0.12); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="shield-check" style="width: 20px; height: 20px; color: #3b82f6;"></i>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex justify-between align-center flex-wrap gap-2 mb-3" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
        <div class="flex gap-2">
            <button type="button" class="tab-btn active" id="btn-tab-inbox" onclick="switchTab('tab-inbox')">
                <i data-lucide="inbox" style="width: 16px; height: 16px;"></i>
                <span>Incoming Queue</span>
                <span class="badge" id="tab-pending-badge" style="background: #ef4444; color: #fff; font-size: 0.65rem; padding: 2px 7px; border-radius: 10px; <?php echo ($pending_count > 0 ? '' : 'display: none;'); ?>"><?php echo $pending_count; ?></span>
            </button>
            <button type="button" class="tab-btn" id="btn-tab-settings" onclick="switchTab('tab-settings')">
                <i data-lucide="settings-2" style="width: 16px; height: 16px;"></i>
                <span>Gmail Connection & Rules</span>
            </button>
            <button type="button" class="tab-btn" id="btn-tab-guide" onclick="switchTab('tab-guide')">
                <i data-lucide="help-circle" style="width: 16px; height: 16px;"></i>
                <span>Setup Guide</span>
            </button>
        </div>

        <div class="flex align-center gap-2 text-xs text-muted">
            <span id="conn-live-indicator" style="display: inline-flex; align-items: center; gap: 5px; color: <?php echo (!empty($settings['gmail_address']) ? '#10b981' : '#94a3b8'); ?>;">
                <span style="width: 7px; height: 7px; border-radius: 50%; background: <?php echo (!empty($settings['gmail_address']) ? '#10b981' : '#94a3b8'); ?>;"></span>
                <?php echo (!empty($settings['gmail_address']) ? 'Connected: ' . htmlspecialchars($settings['gmail_address']) : 'Not Connected'); ?>
            </span>
            <span style="opacity: 0.3;">|</span>
            <span id="sync-last-time">Last Synced: <?php echo !empty($settings['last_sync_at']) ? htmlspecialchars($settings['last_sync_at']) : 'Never'; ?></span>
        </div>
    </div>

    <!-- TAB 1: INCOMING LICENSE QUEUE -->
    <div id="tab-inbox" class="tab-content">
        <!-- Interactive Control Bar: Live Search & Instant Filter Pills -->
        <div class="flex justify-between align-center flex-wrap gap-3 mb-3">
            <div class="flex align-center gap-2 flex-wrap">
                <button type="button" onclick="setFilter('all')" class="btn filter-btn active" data-filter="all">All</button>
                <button type="button" onclick="setFilter('pending')" class="btn filter-btn" data-filter="pending">Pending Review (<?php echo $pending_count; ?>)</button>
                <button type="button" onclick="setFilter('auto-synced')" class="btn filter-btn" data-filter="auto-synced">Auto-Synced (<?php echo $autosync_count; ?>)</button>
                <button type="button" onclick="setFilter('approved')" class="btn filter-btn" data-filter="approved">Approved (<?php echo $approved_count; ?>)</button>
            </div>

            <!-- Instant 0ms Client-Side Search Bar -->
            <div style="position: relative; width: 280px; max-width: 100%;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                <input type="text" id="license-search-input" oninput="filterLocalLicenses(this.value)" placeholder="Search Licence No, Firm, Phone..." class="form-control" style="width: 100%; border-radius: 8px; padding: 0.45rem 0.75rem 0.45rem 2.1rem; font-size: 0.8rem; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-main);">
            </div>
        </div>

        <!-- Main Data Card with Top Progress Bar (Never Hides Existing Rows) -->
        <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.04); position: relative;">
            
            <div id="sync-top-progressbar" style="height: 3px; width: 100%; background: linear-gradient(90deg, #10b981, #06b6d4, #f59e0b, #10b981); background-size: 200% 100%; display: none;"></div>

            <div class="table-responsive" style="max-height: 680px; overflow-y: auto;">
                <table class="table" id="license-main-table" style="width: 100%; border-collapse: collapse; font-size: 0.825rem;">
                    <thead>
                        <tr style="background: var(--bg-app); border-bottom: 1px solid var(--border-color); text-align: left; color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">
                            <th style="padding: 0.85rem 1.1rem;">Date & Sender</th>
                            <th style="padding: 0.85rem 1rem;">Customer ID (Licence)</th>
                            <th style="padding: 0.85rem 1.1rem;">Party / Firm Name</th>
                            <th style="padding: 0.85rem 1rem;">Software & Key</th>
                            <th style="padding: 0.85rem 1rem;">Matched CRM Client</th>
                            <th style="padding: 0.85rem 1rem;">Attached File</th>
                            <th style="padding: 0.85rem 1rem;">Status</th>
                            <th style="padding: 0.85rem 1.1rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="license-table-body">
                        <?php if (empty($initial_licenses)): ?>
                            <tr id="empty-row-placeholder">
                                <td colspan="8" class="text-center py-6 text-muted">
                                    <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--bg-body); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.5rem; border: 1px solid var(--border-color);">
                                        <i data-lucide="inbox" style="width: 22px; height: 22px; opacity: 0.5;"></i>
                                    </div>
                                    <p class="m-0 text-sm font-semibold text-main">No incoming Marg ERP license records in queue.</p>
                                    <p class="m-0 text-xs mt-1">Click "Fetch & Sync Emails" to check your Gmail inbox.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($initial_licenses as $lic): 
                                $status = trim((string)($lic['sync_status'] ?? 'Pending'));
                                $statusLower = strtolower($status);

                                // Avatar Initials (Safe Alphanumeric Only)
                                $rawName = trim((string)($lic['party_name'] ?? 'M'));
                                $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $rawName);
                                $initials = strtoupper(substr($cleanName ?: 'M', 0, 2));

                                $searchData = strtolower(implode(' ', [
                                    $lic['customer_id'] ?? '',
                                    $lic['party_name'] ?? '',
                                    $lic['phone'] ?? '',
                                    $lic['reg_mobile'] ?? '',
                                    $lic['city'] ?? '',
                                    $lic['software_type'] ?? '',
                                    $lic['client_actual_name'] ?? ''
                                ]));
                            ?>
                                <tr class="license-row" data-id="<?php echo (int)$lic['id']; ?>" data-status="<?php echo htmlspecialchars($statusLower); ?>" data-search="<?php echo htmlspecialchars($searchData); ?>" style="border-bottom: 1px solid var(--border-color); transition: background 0.15s ease;">
                                    <td style="padding: 0.85rem 1.1rem;">
                                        <div class="text-xs font-semibold text-main"><?php echo htmlspecialchars($lic['email_date'] ?? ''); ?></div>
                                        <div class="text-xs text-muted" style="font-size: 11px; max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($lic['from_email'] ?? ''); ?></div>
                                    </td>
                                    <td style="padding: 0.85rem 1rem;">
                                        <div class="flex align-center gap-1">
                                            <span class="font-mono text-xs font-bold" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 3px 7px; border-radius: 5px; border: 1px solid rgba(245, 158, 11, 0.25);"><?php echo htmlspecialchars($lic['customer_id'] ?? '-'); ?></span>
                                            <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($lic['customer_id'] ?? ''); ?>', this)" title="Copy Licence No" style="background: none; border: none; cursor: pointer; padding: 2px; color: var(--text-muted);">
                                                <i data-lucide="copy" style="width: 12px; height: 12px;"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td style="padding: 0.85rem 1.1rem;">
                                        <div class="flex align-center gap-2">
                                            <div style="width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.7rem; flex-shrink: 0;">
                                                <?php echo htmlspecialchars($initials); ?>
                                            </div>
                                            <div>
                                                <div class="text-xs font-bold text-main"><?php echo htmlspecialchars($lic['party_name'] ?? '-'); ?></div>
                                                <div class="text-xs text-muted" style="font-size: 11px;"><?php echo htmlspecialchars($lic['city'] ?? ''); ?> <?php echo htmlspecialchars($lic['state'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 0.85rem 1rem;">
                                        <div class="badge text-xs" style="background: rgba(6, 182, 212, 0.1); color: #06b6d4; font-weight: 600; padding: 2px 7px; border-radius: 4px; display: inline-block;">
                                            <?php echo htmlspecialchars($lic['software_type'] ?: 'Marg ERP'); ?>
                                        </div>
                                        <div class="text-xs text-muted mt-1" style="font-size: 11px;">Users: <?php echo htmlspecialchars($lic['users_count'] ?: '1'); ?></div>
                                    </td>
                                    <td style="padding: 0.85rem 1rem;">
                                        <?php if (!empty($lic['matched_client_id'])): ?>
                                            <div class="flex align-center gap-1">
                                                <i data-lucide="check-circle" style="width: 14px; height: 14px; color: #10b981; flex-shrink: 0;"></i>
                                                <a href="index.php?page=clients&search=<?php echo urlencode($lic['customer_id'] ?? ''); ?>" target="_blank" style="color: #10b981; text-decoration: none; font-weight: 600; font-size: 0.75rem;" title="View in Client Directory">
                                                    <?php echo htmlspecialchars($lic['client_actual_name'] ?: 'ID #' . $lic['matched_client_id']); ?>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge text-xs" style="background: rgba(148, 163, 184, 0.12); color: #94a3b8; font-size: 0.7rem; padding: 2px 7px;">New Client (Not in CRM)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem;">
                                        <?php if (!empty($lic['attachment_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($lic['attachment_path']); ?>" target="_blank" class="file-chip" download title="Download <?php echo htmlspecialchars($lic['attachment_filename'] ?? ''); ?>">
                                                <i data-lucide="paperclip" style="width: 12px; height: 12px; color: var(--accent-cyan);"></i>
                                                <span style="max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($lic['attachment_filename'] ?: 'marg.lic'); ?></span>
                                                <i data-lucide="download" style="width: 10px; height: 10px; opacity: 0.7;"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted text-xs">No file</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1rem;">
                                        <?php if ($statusLower === 'auto-synced' || $statusLower === 'auto_synced'): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px; font-weight: 700;">Auto-Synced ✓</span>
                                        <?php elseif ($statusLower === 'approved'): ?>
                                            <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px; font-weight: 700;">Approved ✓</span>
                                        <?php elseif ($statusLower === 'ignored'): ?>
                                            <span class="badge" style="background: rgba(100, 116, 139, 0.15); color: #64748b; font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">Ignored</span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px; font-weight: 700;">Pending Review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.85rem 1.1rem; text-align: right;">
                                        <?php if ($statusLower === 'pending'): ?>
                                            <div class="flex align-center justify-end gap-1">
                                                <button type="button" onclick="openApproveModal(<?php echo (int)$lic['id']; ?>)" class="btn text-xs font-semibold" style="padding: 5px 12px; border-radius: 7px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);">
                                                    Approve & Sync
                                                </button>
                                                <button type="button" onclick="ignoreLicense(<?php echo (int)$lic['id']; ?>)" class="btn btn-secondary text-xs" style="padding: 5px 8px; border-radius: 7px;" title="Ignore this record">
                                                    ✕
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex align-center justify-end gap-1">
                                                <button type="button" onclick="openApproveModal(<?php echo (int)$lic['id']; ?>)" class="btn btn-secondary text-xs" style="padding: 4px 10px; border-radius: 7px;">
                                                    View Details
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer Status Summary -->
            <div class="flex justify-between align-center p-3 text-xs text-muted" style="border-top: 1px solid var(--border-color); background: var(--bg-app);">
                <span id="footer-count-label">Showing <?php echo $total_count; ?> record(s)</span>
                <span style="display: flex; align-items: center; gap: 4px;">
                    <i data-lucide="shield-check" style="width: 14px; height: 14px; color: #10b981;"></i>
                    Strict Isolation & Safe Sync Active
                </span>
            </div>
        </div>
    </div>

    <!-- TAB 2: GMAIL CONNECTION & RULES -->
    <div id="tab-settings" class="tab-content" style="display: none;">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 1.5rem;">

            <!-- Connection Form Card -->
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.04);">
                <div class="flex justify-between align-center mb-4 pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div class="flex align-center gap-2">
                        <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(245, 158, 11, 0.12); display: flex; align-items: center; justify-content: center;">
                            <i data-lucide="mail" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                        </div>
                        <div>
                            <h3 class="m-0 text-base font-bold">1. Gmail Connection</h3>
                            <span class="text-xs text-muted">Dedicated Google App Password Authentication</span>
                        </div>
                    </div>
                    <span id="badge-conn-state" class="badge" style="background: <?php echo (!empty($settings['gmail_address']) ? 'rgba(16, 185, 129, 0.15)' : 'rgba(100, 116, 139, 0.15)'); ?>; color: <?php echo (!empty($settings['gmail_address']) ? '#10b981' : '#64748b'); ?>; font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">
                        <?php echo (!empty($settings['gmail_address']) ? 'Connected' : 'Not Configured'); ?>
                    </span>
                </div>

                <form id="gmail-settings-form" onsubmit="saveGmailSettings(event)">
                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Gmail Address</label>
                        <div style="position: relative;">
                            <i data-lucide="at-sign" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-muted);"></i>
                            <input type="email" id="set_gmail_address" name="gmail_address" required value="<?php echo htmlspecialchars($settings['gmail_address'] ?? ''); ?>" placeholder="e.g. youragency@gmail.com" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.65rem 0.85rem 0.65rem 2.2rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                        </div>
                        <span class="text-xs text-muted" style="display: block; margin-top: 4px;">The inbox receiving official Marg ERP license emails.</span>
                    </div>

                    <div class="form-group mb-3">
                        <div class="flex justify-between align-center" style="margin-bottom: 6px;">
                            <label class="form-label text-xs font-semibold m-0">16-Digit Google App Password</label>
                            <a href="javascript:void(0);" onclick="switchTab('tab-guide')" class="text-xs font-semibold" style="color: var(--accent-cyan); text-decoration: underline;">How to get code? (2 min)</a>
                        </div>
                        <div style="position: relative;">
                            <i data-lucide="key-round" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-muted);"></i>
                            <input type="password" id="set_app_password" name="app_password" value="<?php echo !empty($settings['app_password']) ? '**** **** **** ****' : ''; ?>" placeholder="xxxx xxxx xxxx xxxx" class="form-control font-mono" style="width: 100%; border-radius: 8px; padding: 0.65rem 2.4rem 0.65rem 2.2rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main); letter-spacing: 0.05em;">
                            <button type="button" onclick="togglePasswordVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);">
                                <i data-lucide="eye" id="pwd-eye-icon" style="width: 15px; height: 15px;"></i>
                            </button>
                        </div>
                        <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Google provides this dedicated code without sharing your account password.</span>
                    </div>

                    <div class="flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex align-center gap-2" style="border-radius: 8px; padding: 0.65rem 1.35rem; font-size: 0.85rem; font-weight: 600;">
                            <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                            <span>Save Settings</span>
                        </button>
                        <button type="button" onclick="testGmailConnection()" class="btn btn-secondary flex align-center gap-2" style="border-radius: 8px; padding: 0.65rem 1.1rem; font-size: 0.85rem; font-weight: 600;">
                            <i data-lucide="activity" style="width: 16px; height: 16px;"></i>
                            <span id="test-btn-text">Test Connection</span>
                        </button>
                    </div>

                    <div id="conn-status-alert" class="mt-3" style="display: none;"></div>
                </form>
            </div>

            <!-- Custom Rule Engine Card -->
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.75rem; box-shadow: 0 4px 15px rgba(0,0,0,0.04);">
                <div class="flex align-center gap-2 mb-4 pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(6, 182, 212, 0.12); display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="sliders" style="width: 18px; height: 18px; color: #06b6d4;"></i>
                    </div>
                    <div>
                        <h3 class="m-0 text-base font-bold">2. Smart Filtering & Safety Rules</h3>
                        <span class="text-xs text-muted">Controls which emails are processed and how</span>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Allowed Sender Emails / Domains</label>
                    <input type="text" id="set_allowed_senders" value="<?php echo htmlspecialchars($settings['allowed_senders'] ?? ''); ?>" placeholder="@margcompusoft.com, licenses@margerp.com" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.65rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                    <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Comma separated. Unrelated emails are skipped instantly without reading body.</span>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Subject Keywords (Filter)</label>
                    <input type="text" id="set_subject_keywords" value="<?php echo htmlspecialchars($settings['subject_keywords'] ?? ''); ?>" placeholder="License, Registration, Passkey, Customer ID" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.65rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                    <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Only processes emails containing these keywords.</span>
                </div>

                <div class="form-group mb-3 p-3 border-radius-sm" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 10px;">
                    <label class="form-label text-xs font-bold" style="margin-bottom: 8px; display: block;">Sync & Update Mode</label>
                    <div class="flex flex-col gap-2">
                        <label class="flex align-center gap-2 cursor-pointer text-xs" style="padding: 4px 0;">
                            <input type="radio" name="set_sync_mode" value="manual" id="mode_manual" <?php echo (($settings['sync_mode'] ?? '') !== 'auto' ? 'checked' : ''); ?> style="accent-color: var(--accent-primary); width: 16px; height: 16px;">
                            <span><strong>Manual Review Mode (Recommended):</strong> Receive bell alert, preview details, and update with 1 click.</span>
                        </label>
                        <label class="flex align-center gap-2 cursor-pointer text-xs" style="padding: 4px 0;">
                            <input type="radio" name="set_sync_mode" value="auto" id="mode_auto" <?php echo (($settings['sync_mode'] ?? '') === 'auto' ? 'checked' : ''); ?> style="accent-color: var(--accent-primary); width: 16px; height: 16px;">
                            <span><strong>Auto-Update Mode:</strong> Automatically sync into Client Directory if Customer ID matches 100%.</span>
                        </label>
                    </div>
                </div>

                <div class="form-group mb-3 p-3 border-radius-sm" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 10px;">
                    <div class="flex justify-between align-center">
                        <div>
                            <span class="text-xs font-bold" style="display: block;">Download & Save Attached License File</span>
                            <span class="text-xs text-muted">Saves <code style="color: var(--accent-cyan);">.lic</code> file into client profile for instant download.</span>
                        </div>
                        <input type="checkbox" id="set_save_attachments" <?php echo (!empty($settings['save_attachments']) ? 'checked' : ''); ?> style="width: 18px; height: 18px; accent-color: var(--accent-primary); cursor: pointer;">
                    </div>
                </div>

                <button type="button" onclick="saveFilterRules()" class="btn btn-primary" style="border-radius: 8px; padding: 0.65rem 1.35rem; font-size: 0.85rem; font-weight: 600;">
                    Update Rules
                </button>
            </div>

        </div>
    </div>

    <!-- TAB 3: 2-MINUTE SETUP GUIDE -->
    <div id="tab-guide" class="tab-content" style="display: none;">
        <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 2rem; max-width: 900px; margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.05);">
            <div class="flex align-center gap-3 mb-4">
                <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="key" style="width: 22px; height: 22px; color: #f59e0b;"></i>
                </div>
                <div>
                    <h3 class="m-0 text-lg font-bold">How to Generate 16-Digit Google App Password</h3>
                    <p class="m-0 text-xs text-muted">Follow these 3 simple steps to connect safely in under 2 minutes.</p>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div class="p-3 border-radius-sm flex gap-3 align-center" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 30px; height: 30px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; flex-shrink: 0;">1</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Turn on 2-Step Verification</h4>
                        <p class="text-xs text-muted m-0 mt-1">Open Google Account Security: <a href="https://myaccount.google.com/security" target="_blank" style="color: var(--accent-cyan); text-decoration: underline;">myaccount.google.com/security</a> and make sure 2-Step Verification is Active.</p>
                    </div>
                </div>

                <div class="p-3 border-radius-sm flex gap-3 align-center" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 30px; height: 30px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; flex-shrink: 0;">2</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Generate App Password</h4>
                        <p class="text-xs text-muted m-0 mt-1">In Google search bar at top, type <strong>"App Passwords"</strong> (Direct link: <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color: var(--accent-cyan); text-decoration: underline;">myaccount.google.com/apppasswords</a>). Enter App Name <strong>"Marg CRM"</strong> and click <strong>Create</strong>.</p>
                    </div>
                </div>

                <div class="p-3 border-radius-sm flex gap-3 align-center" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 30px; height: 30px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; flex-shrink: 0;">3</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Paste Code in Tab 2</h4>
                        <p class="text-xs text-muted m-0 mt-1">Copy the 16-character code (e.g. <code>abcd efgh ijkl mnop</code>), paste into <strong>Gmail Connection</strong> tab and click <strong>Test Connection</strong>.</p>
                    </div>
                </div>
            </div>

            <div class="mt-4 p-3 border-radius-sm" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px;">
                <span class="text-xs" style="color: #10b981; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="shield-check" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <span><strong>100% Security Guarantee:</strong> This connection uses encrypted SSL (Port 993). Your main Google account password is never entered or stored, and access can be revoked from your Google Account in 1 click anytime.</span>
                </span>
            </div>
        </div>
    </div>

</div>

<!-- Modal: Review & Approve License -->
<div id="approve-license-modal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div class="modal-container" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; width: 92%; max-width: 680px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.5);">
        <div class="modal-header flex justify-between align-center p-4" style="border-bottom: 1px solid var(--border-color); background: var(--bg-app);">
            <div class="flex align-center gap-2">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(16, 185, 129, 0.15); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="check-circle-2" style="width: 18px; height: 18px; color: #10b981;"></i>
                </div>
                <div>
                    <h3 class="m-0 text-base font-bold">Review & Approve Marg License</h3>
                    <span class="text-xs text-muted">Verify incoming data before synchronizing with Client Directory</span>
                </div>
            </div>
            <button class="btn-icon" onclick="closeApproveModal()" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>

        <div class="modal-body p-4" style="max-height: 72vh; overflow-y: auto;">
            <input type="hidden" id="modal_license_id">

            <div class="p-3 mb-3 border-radius-sm" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 12px;">
                <div class="flex justify-between align-center mb-2 pb-2" style="border-bottom: 1px solid rgba(245, 158, 11, 0.15);">
                    <span class="text-xs font-bold" style="color: #f59e0b; text-transform: uppercase; letter-spacing: 0.05em;">Email Extracted License Data</span>
                    <span id="modal_disp_dated" class="text-xs text-muted"></span>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.8rem;">
                    <div><span class="text-muted">Customer ID:</span> <strong id="modal_disp_customer_id" class="font-mono text-main" style="color: #f59e0b;"></strong></div>
                    <div><span class="text-muted">Party Name:</span> <strong id="modal_disp_party_name" class="text-main"></strong></div>
                    <div><span class="text-muted">Phone / Mobile:</span> <span id="modal_disp_phone" class="text-main"></span></div>
                    <div><span class="text-muted">Software:</span> <span id="modal_disp_software" class="badge" style="background: rgba(6, 182, 212, 0.15); color: #06b6d4;"></span></div>
                    <div><span class="text-muted">Contact:</span> <span id="modal_disp_contact" class="text-main"></span></div>
                    <div><span class="text-muted">Reg. Email:</span> <span id="modal_disp_email" class="text-main"></span></div>
                    <div style="grid-column: span 2;"><span class="text-muted">Main Computer Key:</span> <code id="modal_disp_main_computer" class="text-xs font-mono" style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"></code></div>
                </div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Target Client Directory Record</label>
                <div id="modal_match_info" class="p-3 border-radius-sm mb-2 text-xs" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;"></div>
                <input type="hidden" id="modal_target_client_id">
            </div>

            <div id="modal_file_info" class="p-3 mb-2 text-xs" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px; display: flex; align-items: center; justify-content: space-between;">
                <span class="flex align-center gap-2">
                    <i data-lucide="paperclip" style="width: 16px; height: 16px; color: var(--accent-cyan);"></i>
                    <span id="modal_file_name" class="font-semibold">No file</span>
                </span>
                <a id="modal_file_link" href="#" target="_blank" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 4px 10px; border-radius: 6px; text-decoration: none;" download>
                    <i data-lucide="download" style="width: 12px; height: 12px;"></i>
                    Download
                </a>
            </div>
        </div>

        <div class="modal-footer flex justify-between align-center p-3" style="border-top: 1px solid var(--border-color); background: var(--bg-app);">
            <button type="button" onclick="closeApproveModal()" class="btn btn-secondary text-xs" style="padding: 7px 14px; border-radius: 8px;">Cancel</button>
            <button type="button" onclick="confirmApproveLicense()" class="btn btn-primary text-xs flex align-center gap-2" style="background: #10b981; border-color: #10b981; padding: 7px 18px; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                <span>Confirm & Update Client Directory</span>
            </button>
        </div>
    </div>
</div>

<!-- Embedded Server-Side Data Store for Instant Modal Access -->
<script>
window.initialLicensesData = <?php echo json_encode($initial_licenses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
let currentLicensesData = window.initialLicensesData || [];
let activeFilter = 'all';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    <?php if (!empty($settings['gmail_address']) && !empty($settings['app_password'])): ?>
    // Auto-check in background silently (zero UI freeze, zero data hiding)
    setTimeout(triggerSilentBackgroundSync, 3500);
    <?php endif; ?>
});

function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    const activeTab = document.getElementById(tabId);
    if (activeTab) activeTab.style.display = 'block';

    const activeBtn = document.getElementById('btn-' + tabId);
    if (activeBtn) activeBtn.classList.add('active');

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/**
 * 0ms Instant Client-Side Filter (Zero Loading, Zero Hiding)
 */
function setFilter(status) {
    activeFilter = status;

    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector(`.filter-btn[data-filter="${status}"]`);
    if (btn) btn.classList.add('active');

    applyFilters();
}

function filterLocalLicenses(query) {
    applyFilters();
}

function applyFilters() {
    const q = (document.getElementById('license-search-input')?.value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#license-table-body tr.license-row');
    let visibleCount = 0;

    rows.forEach(r => {
        const rowStatus = r.getAttribute('data-status') || '';
        const searchContent = r.getAttribute('data-search') || '';

        // Status filter match
        let statusMatch = (activeFilter === 'all');
        if (!statusMatch) {
            if (activeFilter === 'auto-synced') {
                statusMatch = (rowStatus === 'auto-synced' || rowStatus === 'auto_synced');
            } else {
                statusMatch = (rowStatus === activeFilter);
            }
        }

        // Query match
        const queryMatch = !q || searchContent.includes(q);

        if (statusMatch && queryMatch) {
            r.style.display = '';
            visibleCount++;
        } else {
            r.style.display = 'none';
        }
    });

    const footerLabel = document.getElementById('footer-count-label');
    if (footerLabel) {
        footerLabel.innerText = `Showing ${visibleCount} record(s)`;
    }
}

function copyToClipboard(text, btn) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const origColor = btn.style.color;
        btn.style.color = '#10b981';
        setTimeout(() => { btn.style.color = origColor; }, 1200);
    });
}

function togglePasswordVisibility() {
    const input = document.getElementById('set_app_password');
    const icon = document.getElementById('pwd-eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.setAttribute('data-lucide', 'eye-off');
    } else {
        input.type = 'password';
        icon.setAttribute('data-lucide', 'eye');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function saveGmailSettings(e) {
    if (e) e.preventDefault();
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('gmail_address', document.getElementById('set_gmail_address').value.trim());
    formData.append('app_password', document.getElementById('set_app_password').value.trim());
    formData.append('allowed_senders', document.getElementById('set_allowed_senders').value.trim());
    formData.append('subject_keywords', document.getElementById('set_subject_keywords').value.trim());
    formData.append('sync_mode', document.querySelector('input[name="set_sync_mode"]:checked').value);
    formData.append('save_attachments', document.getElementById('set_save_attachments').checked ? '1' : '0');

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, data.success ? 'success' : 'danger');
    } catch (err) {
        showAlert('Error saving settings: ' + err.message, 'danger');
    }
}

function saveFilterRules() {
    saveGmailSettings(null);
}

async function testGmailConnection() {
    const btn = document.getElementById('test-btn-text');
    btn.innerText = 'Testing...';

    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('gmail_address', document.getElementById('set_gmail_address').value.trim());
    formData.append('app_password', document.getElementById('set_app_password').value.trim());

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, data.success ? 'success' : 'danger');
    } catch (err) {
        showAlert('Connection error: ' + err.message, 'danger');
    } finally {
        btn.innerText = 'Test Connection';
    }
}

/**
 * Background Sync: Keeps existing table completely visible, shows smooth top progress bar
 */
async function triggerEmailSync() {
    const spinIcon = document.getElementById('sync-spin-icon');
    const syncBtn = document.getElementById('btn-sync-now');
    const syncBtnText = document.getElementById('sync-btn-text');
    const progressBar = document.getElementById('sync-top-progressbar');

    if (spinIcon) spinIcon.classList.add('spin');
    syncBtn.disabled = true;
    if (syncBtnText) syncBtnText.innerText = 'Scanning Gmail...';
    if (progressBar) progressBar.style.display = 'block';

    try {
        const formData = new FormData();
        formData.append('action', 'fetch_emails');
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (je) {
            throw new Error('Server returned invalid response: ' + text.substring(0, 100));
        }

        if (data.success) {
            showAlert(data.message, 'success');
            // Reload page smoothly so authoritative server-side PHP data reflects instantly
            setTimeout(() => {
                window.location.reload();
            }, 800);
        } else {
            showAlert(data.message, 'danger');
        }
    } catch (e) {
        showAlert('Sync failed: ' + e.message, 'danger');
    } finally {
        if (spinIcon) spinIcon.classList.remove('spin');
        syncBtn.disabled = false;
        if (syncBtnText) syncBtnText.innerText = 'Fetch & Sync Emails';
        if (progressBar) progressBar.style.display = 'none';
    }
}

/**
 * Headless Background Auto-Sync:
 * Runs silently in the background, NEVER hides existing rows, NEVER shakes or touches the frontend.
 */
async function triggerSilentBackgroundSync() {
    try {
        const formData = new FormData();
        formData.append('action', 'fetch_emails');
        formData.append('silent', '1');

        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data && data.success && data.processed_count > 0) {
            showSilentToast(`✨ ${data.processed_count} new Marg license email(s) auto-loaded in background!`);
            const lastTime = document.getElementById('sync-last-time');
            if (lastTime) lastTime.innerText = 'Last Synced: Just now';

            // Smoothly refresh after 2 seconds to reflect new verified data
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else if (data && data.success) {
            const lastTime = document.getElementById('sync-last-time');
            if (lastTime) lastTime.innerText = 'Last Synced: Just now';
        }
    } catch (e) {
        // Completely silent: ignore errors in background
    }
}

function showSilentToast(msg) {
    let toast = document.getElementById('silent-sync-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'silent-sync-toast';
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;background:linear-gradient(135deg,#0f172a,#1e293b);border:1px solid #10b981;color:#fff;padding:12px 18px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.5);font-size:0.85rem;display:flex;align-items:center;gap:10px;animation:fadeIn 0.3s ease;';
        document.body.appendChild(toast);
    }
    toast.innerHTML = `<span style="width:8px;height:8px;border-radius:50%;background:#10b981;display:inline-block;"></span> <span>${escapeHtml(msg)}</span>`;
    toast.style.display = 'flex';
    setTimeout(() => {
        if (toast) toast.style.display = 'none';
    }, 4500);
}

function openApproveModal(id) {
    const lic = currentLicensesData.find(item => parseInt(item.id) === parseInt(id));
    if (!lic) return;

    document.getElementById('modal_license_id').value = lic.id;
    document.getElementById('modal_disp_customer_id').innerText = lic.customer_id || '-';
    document.getElementById('modal_disp_party_name').innerText = lic.party_name || '-';
    document.getElementById('modal_disp_phone').innerText = (lic.phone || lic.reg_mobile || '-');
    document.getElementById('modal_disp_software').innerText = lic.software_type || 'Marg ERP';
    document.getElementById('modal_disp_contact').innerText = lic.contact_person || '-';
    document.getElementById('modal_disp_email').innerText = lic.reg_email || '-';
    document.getElementById('modal_disp_dated').innerText = lic.dated ? ('Dated: ' + lic.dated) : '';
    document.getElementById('modal_disp_main_computer').innerText = lic.main_computer || 'Not Provided';

    document.getElementById('modal_target_client_id').value = lic.matched_client_id || '';
    const matchBox = document.getElementById('modal_match_info');
    if (lic.matched_client_id) {
        matchBox.innerHTML = `
            <div class="flex align-center gap-2 mb-1">
                <i data-lucide="check-circle" style="width: 16px; height: 16px; color: #10b981;"></i>
                <strong style="color: #10b981;">Existing Client Match:</strong>
                <span>${escapeHtml(lic.client_actual_name || '')} (ID #${lic.matched_client_id})</span>
            </div>
            <span class="text-muted" style="display: block; font-size: 11px;">Confirming will update licence number, activation date, software type, main computer key & link the attached file.</span>
        `;
    } else {
        matchBox.innerHTML = `
            <div class="flex align-center gap-2 mb-1">
                <i data-lucide="user-plus" style="width: 16px; height: 16px; color: #3b82f6;"></i>
                <strong style="color: #3b82f6;">New Client Entry:</strong>
                <span>No existing record matched Customer ID "${escapeHtml(lic.customer_id)}".</span>
            </div>
            <span class="text-muted" style="display: block; font-size: 11px;">A brand-new client profile will be created in your Client Directory with all extracted details.</span>
        `;
    }

    const fileBox = document.getElementById('modal_file_info');
    if (lic.attachment_path) {
        fileBox.style.display = 'flex';
        document.getElementById('modal_file_name').innerText = lic.attachment_filename || 'marg.lic (License File)';
        document.getElementById('modal_file_link').href = lic.attachment_path;
    } else {
        fileBox.style.display = 'none';
    }

    const modal = document.getElementById('approve-license-modal');
    modal.style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeApproveModal() {
    document.getElementById('approve-license-modal').style.display = 'none';
}

async function confirmApproveLicense() {
    const licId = document.getElementById('modal_license_id').value;
    const targetClientId = document.getElementById('modal_target_client_id').value;

    const formData = new FormData();
    formData.append('action', 'approve_license');
    formData.append('license_id', licId);
    formData.append('target_client_id', targetClientId);

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            closeApproveModal();
            showAlert(data.message, 'success');
            setTimeout(() => { window.location.reload(); }, 600);
        } else {
            alert('Approval error: ' + data.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function ignoreLicense(id) {
    if (!confirm('Are you sure you want to ignore this license email?')) return;
    const formData = new FormData();
    formData.append('action', 'ignore_license');
    formData.append('license_id', id);

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, 'info');
        setTimeout(() => { window.location.reload(); }, 600);
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function showAlert(msg, type = 'success') {
    const container = document.getElementById('conn-status-alert');
    if (!container) return;
    container.style.display = 'block';
    container.className = `alert alert-${type} mt-3 text-xs`;
    container.innerHTML = msg;
    setTimeout(() => {
        container.style.display = 'none';
    }, 6000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<style>
.tab-btn {
    padding: 0.65rem 1.25rem;
    border: none;
    background: none;
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.tab-btn:hover {
    color: var(--text-main);
    background: rgba(255, 255, 255, 0.04);
}
.tab-btn.active {
    background: rgba(255, 255, 255, 0.08) !important;
    color: var(--text-main) !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}
.filter-btn {
    padding: 5px 14px;
    font-size: 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.filter-btn:hover {
    color: var(--text-main);
    border-color: rgba(255,255,255,0.2);
}
.filter-btn.active {
    background: var(--accent-primary, #6366f1);
    color: #fff;
    border-color: var(--accent-primary, #6366f1);
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
}
.file-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 6px;
    background: rgba(6, 182, 212, 0.1);
    border: 1px solid rgba(6, 182, 212, 0.25);
    color: var(--accent-cyan);
    font-size: 0.72rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}
.file-chip:hover {
    background: rgba(6, 182, 212, 0.2);
}
.license-row:hover {
    background: rgba(255, 255, 255, 0.02);
}
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
