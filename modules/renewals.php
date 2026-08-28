<?php
/**
 * Marg CRM - Renewals Management & Expiry Tracker Module
 * Powered by live client_directory database records.
 * Supports rich multi-criteria filtering and Scan-to-Call QR Code generation.
 */

require_once __DIR__ . '/../includes/config.php';

// -------------------------------------------------------------
// 1. CSV EXPORT HANDLER FOR FILTERED RENEWALS
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_renewals_csv') {
    if (!$db_connected || !$pdo) {
        die("Database connection offline.");
    }

    $search   = trim($_GET['search'] ?? '');
    $status   = trim($_GET['status'] ?? 'all');
    $sw_type  = trim($_GET['sw_type'] ?? '');
    $sw_trade = trim($_GET['sw_trade'] ?? '');
    $city     = trim($_GET['city'] ?? '');
    $date_from= trim($_GET['date_from'] ?? '');
    $date_to  = trim($_GET['date_to'] ?? '');

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $cleanSearch = preg_replace('/[^a-zA-Z0-9]/', '', $search);
        $where[] = "(
            party_name LIKE :search 
            OR customer_id LIKE :search 
            OR REPLACE(customer_id, ' ', '') LIKE :search
            OR CAST(id AS CHAR) LIKE :search
            OR CONCAT('CLIENT-', id) LIKE :search
            OR CONCAT('RNW-', id) LIKE :search
            OR mobile LIKE :search
            OR email LIKE :search
            OR contact_person LIKE :search
            OR address LIKE :search
            OR city LIKE :search
            OR state LIKE :search
            OR subpartner_code LIKE :search
            OR wallet_id LIKE :search"
            . (!empty($cleanSearch) ? " OR customer_id LIKE :cleanSearch OR REPLACE(customer_id, ' ', '') LIKE :cleanSearch OR REPLACE(mobile, ' ', '') LIKE :cleanSearch" : "") . ")";
        
        $params[':search'] = "%$search%";
        if (!empty($cleanSearch)) {
            $params[':cleanSearch'] = "%$cleanSearch%";
        }
    }

    if ($status === 'expiring_soon') {
        $where[] = "(due_on IS NOT NULL AND due_on >= CURRENT_DATE() AND due_on <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))";
    } elseif ($status === 'expired') {
        $where[] = "(due_on IS NOT NULL AND due_on < CURRENT_DATE())";
    } elseif ($status === 'grace') {
        $where[] = "(LOWER(party_status) = 'grace' OR (due_on IS NOT NULL AND due_on < CURRENT_DATE() AND due_on >= DATE_SUB(CURRENT_DATE(), INTERVAL 15 DAY)))";
    } elseif ($status === 'active') {
        $where[] = "(due_on IS NOT NULL AND due_on > DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))";
    }

    if (!empty($sw_type)) {
        $where[] = "software_type = :sw_type";
        $params[':sw_type'] = $sw_type;
    }
    if (!empty($sw_trade)) {
        $where[] = "software_trade = :sw_trade";
        $params[':sw_trade'] = $sw_trade;
    }
    if (!empty($city)) {
        $where[] = "city = :city";
        $params[':city'] = $city;
    }
    if (!empty($date_from) && !empty($date_to)) {
        $where[] = "due_on BETWEEN :date_from AND :date_to";
        $params[':date_from'] = $date_from;
        $params[':date_to']   = $date_to;
    }

    $where_sql = implode(" AND ", $where);
    $exportStmt = $pdo->prepare("SELECT customer_id, party_name, contact_person, mobile, email, software_type, software_trade, due_on, total_amount, party_status, city, state FROM client_directory WHERE {$where_sql} ORDER BY due_on ASC");
    $exportStmt->execute($params);
    $rows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Renewals_Export_' . date('Y-m-d_H-i') . '.csv"');
    
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Customer ID', 'Party Name', 'Contact Person', 'Mobile', 'Email', 'Software License', 'Trade', 'Expiry Date (Due On)', 'Renewal Amount (INR)', 'Status', 'City', 'State']);
    
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['customer_id'],
            $r['party_name'],
            $r['contact_person'],
            $r['mobile'],
            $r['email'],
            $r['software_type'],
            $r['software_trade'],
            $r['due_on'],
            $r['total_amount'],
            $r['party_status'],
            $r['city'],
            $r['state']
        ]);
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// 2. PARSE FILTER PARAMETERS FOR PAGE VIEW
// -------------------------------------------------------------
$search    = trim($_GET['search'] ?? '');
$status    = strtolower(trim($_GET['status'] ?? 'all'));
$sw_type   = trim($_GET['sw_type'] ?? '');
$sw_trade  = trim($_GET['sw_trade'] ?? '');
$city      = trim($_GET['city'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$page      = max(1, (int)($_GET['p'] ?? 1));
$limit     = 25;
$offset    = ($page - 1) * $limit;

// Base WHERE conditions (Without status filter)
$whereBase = ["1=1"];
$paramsBase = [];

if (!empty($search)) {
    $cleanSearch = preg_replace('/[^a-zA-Z0-9]/', '', $search);
    $whereBase[] = "(
        party_name LIKE :search 
        OR customer_id LIKE :search 
        OR REPLACE(customer_id, ' ', '') LIKE :search
        OR CAST(id AS CHAR) LIKE :search
        OR CONCAT('CLIENT-', id) LIKE :search
        OR CONCAT('RNW-', id) LIKE :search
        OR mobile LIKE :search
        OR email LIKE :search
        OR contact_person LIKE :search
        OR address LIKE :search
        OR city LIKE :search
        OR state LIKE :search
        OR subpartner_code LIKE :search
        OR wallet_id LIKE :search"
        . (!empty($cleanSearch) ? " OR customer_id LIKE :cleanSearch OR REPLACE(customer_id, ' ', '') LIKE :cleanSearch OR REPLACE(mobile, ' ', '') LIKE :cleanSearch" : "") . ")";
    
    $paramsBase[':search'] = "%$search%";
    if (!empty($cleanSearch)) {
        $paramsBase[':cleanSearch'] = "%$cleanSearch%";
    }
}

if (!empty($sw_type)) {
    $whereBase[] = "software_type = :sw_type";
    $paramsBase[':sw_type'] = $sw_type;
}
if (!empty($sw_trade)) {
    $whereBase[] = "software_trade = :sw_trade";
    $paramsBase[':sw_trade'] = $sw_trade;
}
if (!empty($city)) {
    $whereBase[] = "city = :city";
    $paramsBase[':city'] = $city;
}
if (!empty($date_from) && !empty($date_to)) {
    $whereBase[] = "due_on BETWEEN :date_from AND :date_to";
    $paramsBase[':date_from'] = $date_from;
    $paramsBase[':date_to']   = $date_to;
}

$where_base_sql = implode(" AND ", $whereBase);

// Full WHERE conditions (including active status tab filter)
$where = $whereBase;
$params = $paramsBase;

if ($status === 'expiring_soon') {
    $where[] = "(due_on IS NOT NULL AND due_on >= CURRENT_DATE() AND due_on <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))";
} elseif ($status === 'expired') {
    $where[] = "(due_on IS NOT NULL AND due_on < CURRENT_DATE())";
} elseif ($status === 'grace') {
    $where[] = "(LOWER(party_status) = 'grace' OR (due_on IS NOT NULL AND due_on < CURRENT_DATE() AND due_on >= DATE_SUB(CURRENT_DATE(), INTERVAL 15 DAY)))";
} elseif ($status === 'active') {
    $where[] = "(due_on IS NOT NULL AND due_on > DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))";
}

$where_sql = implode(" AND ", $where);

// Summary Statistics Cards Queries
$totalClientsCount = 0;
$activeCount = 0; $activeSum = 0;
$expiringCount = 0; $expiringSum = 0;
$expiredCount = 0; $expiredSum = 0;
$totalForecastSum = 0;

$tabCntAll = 0;
$tabCntExpiring = 0;
$tabCntExpired = 0;
$tabCntGrace = 0;
$tabCntActive = 0;

$globalSearchMatches = 0;

$renewalsList = [];
$total_pages = 1;
$filtered_total = 0;
$swTypes = [];
$swTrades = [];
$cities = [];

if ($db_connected && $pdo) {
    try {
        // KPI Stats Calculations across all clients
        $totalClientsCount = $pdo->query("SELECT COUNT(*) FROM client_directory")->fetchColumn();
        
        $activeRow = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as val FROM client_directory WHERE due_on IS NOT NULL AND due_on > DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
        $activeCount = $activeRow['cnt'] ?? 0;
        $activeSum   = $activeRow['val'] ?? 0;

        $expiringRow = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as val FROM client_directory WHERE due_on IS NOT NULL AND due_on >= CURRENT_DATE() AND due_on <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
        $expiringCount = $expiringRow['cnt'] ?? 0;
        $expiringSum   = $expiringRow['val'] ?? 0;

        $expiredRow = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as val FROM client_directory WHERE due_on IS NOT NULL AND due_on < CURRENT_DATE()")->fetch(PDO::FETCH_ASSOC);
        $expiredCount = $expiredRow['cnt'] ?? 0;
        $expiredSum   = $expiredRow['val'] ?? 0;

        $totalForecastSum = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM client_directory")->fetchColumn();

        // Calculate Tab Badges for current search / filter criteria
        $stmtTabAll = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_base_sql}");
        $stmtTabAll->execute($paramsBase);
        $tabCntAll = (int)$stmtTabAll->fetchColumn();

        $stmtTabExp = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_base_sql} AND (due_on IS NOT NULL AND due_on >= CURRENT_DATE() AND due_on <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))");
        $stmtTabExp->execute($paramsBase);
        $tabCntExpiring = (int)$stmtTabExp->fetchColumn();

        $stmtTabExd = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_base_sql} AND (due_on IS NOT NULL AND due_on < CURRENT_DATE())");
        $stmtTabExd->execute($paramsBase);
        $tabCntExpired = (int)$stmtTabExd->fetchColumn();

        $stmtTabGrc = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_base_sql} AND (LOWER(party_status) = 'grace' OR (due_on IS NOT NULL AND due_on < CURRENT_DATE() AND due_on >= DATE_SUB(CURRENT_DATE(), INTERVAL 15 DAY)))");
        $stmtTabGrc->execute($paramsBase);
        $tabCntGrace = (int)$stmtTabGrc->fetchColumn();

        $stmtTabAct = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_base_sql} AND (due_on IS NOT NULL AND due_on > DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))");
        $stmtTabAct->execute($paramsBase);
        $tabCntActive = (int)$stmtTabAct->fetchColumn();

        // Fetch Total Filtered Count for Pagination
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM client_directory WHERE {$where_sql}");
        $countStmt->execute($params);
        $filtered_total = (int)$countStmt->fetchColumn();

        $total_pages = ceil($filtered_total / $limit);
        if ($total_pages < 1) $total_pages = 1;

        // Fetch Filtered Renewals Records (Order by due_on ASC so earliest expiries appear first)
        $fetchSql = "SELECT * FROM client_directory WHERE {$where_sql} ORDER BY CASE WHEN due_on IS NULL THEN 1 ELSE 0 END, due_on ASC LIMIT :limit OFFSET :offset";
        $fetchStmt = $pdo->prepare($fetchSql);
        
        foreach ($params as $key => $val) {
            $fetchStmt->bindValue($key, $val);
        }
        $fetchStmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $fetchStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $fetchStmt->execute();

        $renewalsList = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Dropdown Filter Options
        $swTypes = $pdo->query("SELECT DISTINCT software_type FROM client_directory WHERE software_type IS NOT NULL AND software_type != '' ORDER BY software_type ASC")->fetchAll(PDO::FETCH_COLUMN);
        $swTrades = $pdo->query("SELECT DISTINCT software_trade FROM client_directory WHERE software_trade IS NOT NULL AND software_trade != '' ORDER BY software_trade ASC")->fetchAll(PDO::FETCH_COLUMN);
        $cities = $pdo->query("SELECT DISTINCT city FROM client_directory WHERE city IS NOT NULL AND city != '' ORDER BY city ASC")->fetchAll(PDO::FETCH_COLUMN);

    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}
?>

<style>
.renewals-container {
    padding: 1.25rem;
}

.renewals-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.renewals-title {
    font-family: var(--font-heading);
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.02em;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.kpi-card {
    background: var(--bg-card, #ffffff);
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    position: relative;
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.06);
}

.kpi-card.card-active { border-left: 4px solid #10b981; }
.kpi-card.card-warning { border-left: 4px solid #f59e0b; }
.kpi-card.card-danger { border-left: 4px solid #ef4444; }
.kpi-card.card-primary { border-left: 4px solid #2563eb; }

.kpi-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, #64748b);
    margin-bottom: 0.4rem;
    display: block;
}

.kpi-value {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1.2;
}

.kpi-sub {
    font-size: 0.76rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

/* Filter Toolbar Box */
.filter-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
}

.filter-status-tabs {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    padding-bottom: 0.75rem;
}

.status-tab-btn {
    padding: 0.4rem 0.9rem;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 700;
    border: 1px solid var(--border-color, #cbd5e1);
    background: var(--bg-app, #f8fafc);
    color: var(--text-main, #334155);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.status-tab-btn:hover {
    background: var(--border-color, #e2e8f0);
}

.status-tab-btn.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
}

.filter-controls-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-input, .filter-select {
    padding: 0.45rem 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.8rem;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    outline: none;
}

.filter-input:focus, .filter-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.12);
}

/* Scan-to-Call Micro QR Code Button */
.qr-call-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border-radius: 6px;
    background: rgba(37, 99, 235, 0.08);
    border: 1px solid rgba(37, 99, 235, 0.25);
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}

.qr-call-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.qr-mini-icon {
    width: 14px;
    height: 14px;
}

/* Call QR Code Lightbox Modal */
.qr-modal-overlay {
    display: none;
    position: fixed;
    z-index: 99999;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}

.qr-modal-card {
    background: var(--bg-card, #ffffff);
    border-radius: 16px;
    padding: 1.75rem;
    max-width: 360px;
    width: 90%;
    text-align: center;
    position: relative;
    box-shadow: 0 20px 30px rgba(0,0,0,0.25);
    animation: popIn 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes popIn {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}

.qr-modal-close {
    position: absolute;
    top: 12px;
    right: 16px;
    font-size: 22px;
    font-weight: bold;
    color: var(--text-muted);
    cursor: pointer;
}

.qr-img-large {
    width: 180px;
    height: 180px;
    margin: 1rem auto;
    border-radius: 12px;
    border: 2px solid var(--border-color, #e2e8f0);
    padding: 8px;
    background: #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.06);
}
</style>

<div class="renewals-container">
    <!-- Header -->
    <div class="renewals-header">
        <div>
            <h2 class="renewals-title">Renewals & AMC Expiry Manager</h2>
            <p class="text-muted text-sm" style="margin-top: 2px;">Track client software license expiries, issue AMC renewal notices, scan QR codes to dial clients directly, and manage grace periods.</p>
        </div>
        <div class="flex gap-2">
            <a href="index.php?page=renewals&action=export_renewals_csv<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($status) ? '&status='.urlencode($status) : ''; ?><?php echo !empty($sw_type) ? '&sw_type='.urlencode($sw_type) : ''; ?><?php echo !empty($city) ? '&city='.urlencode($city) : ''; ?>" class="btn-pill btn-pill-outline text-xs" style="text-decoration: none; padding: 6px 14px; font-weight: 700;">
                <i data-lucide="download" style="width: 14px; height: 14px; display: inline-block; vertical-align: middle;"></i>
                Export Renewals CSV
            </a>
            <button type="button" class="btn-pill btn-pill-primary text-xs" onclick="window.location.reload()" style="padding: 6px 14px; font-weight: 700;">
                <i data-lucide="rotate-cw" style="width: 14px; height: 14px; display: inline-block; vertical-align: middle;"></i>
                Refresh Live Data
            </button>
        </div>
    </div>

    <!-- Renewal Expiries KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card card-active">
            <span class="kpi-label">Active Subscriptions</span>
            <div class="kpi-value" style="color: #10b981;"><?php echo number_format($activeCount); ?></div>
            <div class="kpi-sub">Total Value: <strong>₹<?php echo number_format($activeSum, 2); ?></strong></div>
        </div>
        <div class="kpi-card card-warning">
            <span class="kpi-label">Expiring Soon (< 30 Days)</span>
            <div class="kpi-value" style="color: #d97706;"><?php echo number_format($expiringCount); ?></div>
            <div class="kpi-sub">Forecast Value: <strong>₹<?php echo number_format($expiringSum, 2); ?></strong></div>
        </div>
        <div class="kpi-card card-danger">
            <span class="kpi-label">Overdue / Expired Subscriptions</span>
            <div class="kpi-value" style="color: #ef4444;"><?php echo number_format($expiredCount); ?></div>
            <div class="kpi-sub">Pending AMC Amount: <strong>₹<?php echo number_format($expiredSum, 2); ?></strong></div>
        </div>
        <div class="kpi-card card-primary">
            <span class="kpi-label">Total Renewal Forecast</span>
            <div class="kpi-value" style="color: #2563eb;">₹<?php echo number_format($totalForecastSum, 2); ?></div>
            <div class="kpi-sub">Total Registered Clients: <strong><?php echo number_format($totalClientsCount); ?></strong></div>
        </div>
    </div>

    <!-- Filter Toolbar Box ("yaha hume filter chaie") -->
    <div class="filter-card">
        <!-- Status Pill Tabs -->
        <div class="filter-status-tabs">
            <a href="index.php?page=renewals&status=all<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="status-tab-btn <?php echo ($status==='all')?'active':''; ?>">
                All Clients (<?php echo $tabCntAll; ?>)
            </a>
            <a href="index.php?page=renewals&status=expiring_soon<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="status-tab-btn <?php echo ($status==='expiring_soon')?'active':''; ?>" style="<?php echo ($status==='expiring_soon')?'':'color: #d97706;'; ?>">
                ⚡ Expiring Soon (<?php echo $tabCntExpiring; ?>)
            </a>
            <a href="index.php?page=renewals&status=expired<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="status-tab-btn <?php echo ($status==='expired')?'active':''; ?>" style="<?php echo ($status==='expired')?'':'color: #ef4444;'; ?>">
                🚨 Overdue / Expired (<?php echo $tabCntExpired; ?>)
            </a>
            <a href="index.php?page=renewals&status=grace<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="status-tab-btn <?php echo ($status==='grace')?'active':''; ?>" style="<?php echo ($status==='grace')?'':'color: #8b5cf6;'; ?>">
                ⌛ Grace Period (<?php echo $tabCntGrace; ?>)
            </a>
            <a href="index.php?page=renewals&status=active<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="status-tab-btn <?php echo ($status==='active')?'active':''; ?>" style="<?php echo ($status==='active')?'':'color: #10b981;'; ?>">
                🟢 Active (<?php echo $tabCntActive; ?>)
            </a>
        </div>

        <!-- Filter Form Controls -->
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="renewals">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">

            <div class="filter-controls-row">
                <!-- Search Box -->
                <div style="flex: 1; min-width: 220px;">
                    <input type="text" name="search" class="filter-input w-full" placeholder="🔍 Search Customer ID, License No, Party Name, Mobile..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <!-- Software License Filter -->
                <div>
                    <select name="sw_type" class="filter-select" onchange="this.form.submit()">
                        <option value="">-- All Software Types --</option>
                        <?php if (!empty($swTypes)): foreach ($swTypes as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($sw_type === $t) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <!-- Software Trade Filter -->
                <div>
                    <select name="sw_trade" class="filter-select" onchange="this.form.submit()">
                        <option value="">-- All Trades --</option>
                        <?php if (!empty($swTrades)): foreach ($swTrades as $tr): ?>
                            <option value="<?php echo htmlspecialchars($tr); ?>" <?php echo ($sw_trade === $tr) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tr); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <!-- City Filter -->
                <div>
                    <select name="city" class="filter-select" onchange="this.form.submit()">
                        <option value="">-- All Cities --</option>
                        <?php if (!empty($cities)): foreach ($cities as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo ($city === $c) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <!-- Date Range Pickers -->
                <div class="flex align-center gap-1">
                    <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>" title="Expiry Date From">
                    <span class="text-xs text-muted">to</span>
                    <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>" title="Expiry Date To">
                </div>

                <button type="submit" class="btn-pill btn-pill-dark text-xs" style="padding: 6px 14px; font-weight: 700;">Filter</button>
                <a href="index.php?page=renewals" class="btn-pill btn-pill-outline text-xs" style="padding: 6px 12px; text-decoration: none;">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($filtered_total === 0 && !empty($search) && $status !== 'all' && $tabCntAll > 0): ?>
        <!-- Search Fallback Alert Banner -->
        <div style="background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.25); color: #1d4ed8; padding: 12px 16px; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.84rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
            <div>
                <i data-lucide="info" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle; margin-right: 4px; color: #2563eb;"></i>
                Found <strong><?php echo $tabCntAll; ?></strong> client match(es) for "<strong><?php echo htmlspecialchars($search); ?></strong>" under the <strong>All Clients</strong> tab.
            </div>
            <a href="index.php?page=renewals&status=all&search=<?php echo urlencode($search); ?>" class="btn-pill btn-pill-dark text-xs" style="background: #2563eb; color: white; padding: 6px 14px; font-weight: 700; text-decoration: none;">
                View All Status Results (<?php echo $tabCntAll; ?>)
            </a>
        </div>
    <?php endif; ?>

    <!-- Renewals Data Table -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 110px;">License ID</th>
                        <th>Client / Party Name</th>
                        <th>Mobile Number & Scan-Call</th>
                        <th>Software License</th>
                        <th>City / State</th>
                        <th>Expiry Date (Due On)</th>
                        <th>Timeline Status</th>
                        <th>Renewal Fee</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($renewalsList)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-8 text-muted">
                                <i data-lucide="info" style="width: 28px; height: 28px; display: block; margin: 0 auto 0.5rem auto; color: var(--primary);"></i>
                                No client renewals found matching your selected filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($renewalsList as $rn): ?>
                            <?php
                            $cleanMobile = preg_replace('/[^0-9]/', '', $rn['mobile'] ?? '');
                            $customerID  = $rn['customer_id'] ?? ('CLIENT-' . $rn['id']);
                            $partyName   = $rn['party_name'] ?? 'Valued Customer';
                            $contactP    = $rn['contact_person'] ?? '';
                            $dueOn       = $rn['due_on'] ?? null;
                            $swType      = $rn['software_type'] ?? 'Marg ERP';
                            $fee         = (float)($rn['total_amount'] ?? 0);
                            $cityState   = trim(($rn['city'] ?? '') . ', ' . ($rn['state'] ?? ''), ', ');

                            // Compute Days Remaining & Expiry Category
                            $daysRemaining = null;
                            if (!empty($dueOn)) {
                                $daysRemaining = (int)floor((strtotime($dueOn) - strtotime(date('Y-m-d'))) / 86400);
                            } else {
                                $daysRemaining = (int)($rn['days'] ?? 0);
                            }

                            // Determine Expiry Badge & Status
                            $badgeHtml = '';
                            $timelineHtml = '';

                            if ($daysRemaining < 0) {
                                $overdueDays = abs($daysRemaining);
                                $timelineHtml = "<span class='text-xs font-bold' style='color: #ef4444;'>🚨 Expired {$overdueDays} days ago</span>";
                                $badgeHtml = "<span class='badge' style='background: rgba(239, 68, 68, 0.12); color: #ef4444; font-weight: 700;'>Expired</span>";
                            } elseif ($daysRemaining <= 30) {
                                $timelineHtml = "<span class='text-xs font-bold' style='color: #d97706;'>⚡ Expires in {$daysRemaining} days!</span>";
                                $badgeHtml = "<span class='badge' style='background: rgba(245, 158, 11, 0.12); color: #d97706; font-weight: 700;'>Expiring Soon</span>";
                            } else {
                                $timelineHtml = "<span class='text-xs text-muted'>{$daysRemaining} days remaining</span>";
                                $badgeHtml = "<span class='badge' style='background: rgba(16, 185, 129, 0.12); color: #10b981; font-weight: 700;'>Active</span>";
                            }

                            // Generate Scan-to-Call QR Code URL (tel:+91XXXXXXXXXX)
                            $qrData = "tel:" . (!empty($cleanMobile) ? ((strlen($cleanMobile) == 10) ? "+91" . $cleanMobile : "+" . $cleanMobile) : "0000000000");
                            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" . urlencode($qrData);
                            
                            // WhatsApp Reminder Pre-filled Message
                            $waMsg = "Dear " . $partyName . ", your Marg ERP License (" . $customerID . ") renewal due date is " . (!empty($dueOn) ? date('d M, Y', strtotime($dueOn)) : 'N/A') . ". Kindly renew your subscription/AMC to ensure uninterrupted services. - Marg Soft Solution (7523830026)";
                            $waUrl = "https://wa.me/" . ((strlen($cleanMobile) == 10) ? "91" . $cleanMobile : $cleanMobile) . "?text=" . urlencode($waMsg);
                            ?>
                            <tr>
                                <!-- License / Customer ID -->
                                <td>
                                    <span class="font-bold text-xs" style="color: #2563eb; background: rgba(37,99,235,0.08); padding: 3px 6px; border-radius: 6px; display: inline-block;">
                                        <?php echo htmlspecialchars($customerID); ?>
                                    </span>
                                </td>

                                <!-- Party Name & Contact Person -->
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-bold text-sm" style="color: var(--text-main);"><?php echo htmlspecialchars($partyName); ?></span>
                                        <?php if (!empty($contactP)): ?>
                                            <span class="text-xs text-muted"><i data-lucide="user" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle;"></i> <?php echo htmlspecialchars($contactP); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Mobile Number & Scan-to-Call QR ("no. k samne qr taki sacn krke call ho jaye") -->
                                <td>
                                    <div class="flex align-center gap-2">
                                        <a href="tel:<?php echo htmlspecialchars($cleanMobile); ?>" class="font-semibold text-xs text-main" style="text-decoration: none; font-family: monospace;">
                                            +91 <?php echo htmlspecialchars($cleanMobile); ?>
                                        </a>

                                        <!-- Scan-to-Call QR Button -->
                                        <button type="button" class="qr-call-btn" onclick="openCallQRModal('<?php echo htmlspecialchars(addslashes($cleanMobile)); ?>', '<?php echo htmlspecialchars(addslashes($partyName)); ?>', '<?php echo htmlspecialchars($qrUrl); ?>')" title="Scan QR Code to Call Mobile directly">
                                            <i data-lucide="qr-code" class="qr-mini-icon"></i>
                                            <span>Scan Call</span>
                                        </button>
                                    </div>
                                </td>

                                <!-- Software License -->
                                <td>
                                    <span class="text-xs font-semibold" style="color: var(--text-main);"><?php echo htmlspecialchars($swType); ?></span>
                                    <?php if (!empty($rn['software_trade'])): ?>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($rn['software_trade']); ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- City / State -->
                                <td class="text-xs text-muted">
                                    <?php echo !empty($cityState) ? htmlspecialchars($cityState) : 'N/A'; ?>
                                </td>

                                <!-- Expiry Date (Due On) -->
                                <td class="text-sm font-semibold">
                                    <?php echo !empty($dueOn) ? date('d M, Y', strtotime($dueOn)) : '<span class="text-muted text-xs">Not Set</span>'; ?>
                                </td>

                                <!-- Timeline Status -->
                                <td>
                                    <?php echo $timelineHtml; ?>
                                </td>

                                <!-- Renewal Fee -->
                                <td class="font-bold text-sm" style="color: #10b981;">
                                    ₹<?php echo number_format($fee, 2); ?>
                                </td>

                                <!-- Status Badge -->
                                <td>
                                    <?php echo $badgeHtml; ?>
                                </td>

                                <!-- Actions -->
                                <td style="text-align: right; vertical-align: middle;">
                                    <div class="flex justify-end gap-1">
                                        <!-- WhatsApp Remind Button -->
                                        <a href="<?php echo $waUrl; ?>" target="_blank" class="btn-pill btn-pill-outline text-xs" style="color: #10b981; border-color: rgba(16,185,129,0.3); padding: 3px 8px; font-weight: 700;" title="Send WhatsApp Renewal Reminder">
                                            <i data-lucide="message-square" style="width: 12px; height: 12px;"></i>
                                            WhatsApp
                                        </a>

                                        <!-- Create Renewal Quotation Button -->
                                        <a href="index.php?page=quotation_create&party=<?php echo urlencode($partyName); ?>&phone=<?php echo urlencode($cleanMobile); ?>" class="btn-pill btn-pill-dark text-xs" style="background: #2563eb; color: white; border: none; padding: 3px 8px; font-weight: 700; text-decoration: none;" title="Generate AMC Renewal Invoice / Quotation">
                                            Quote
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <?php if ($total_pages > 1): ?>
            <div class="flex justify-between align-center p-3" style="border-top: 1px solid var(--border-color); background: var(--bg-app);">
                <div class="text-xs text-muted">
                    Showing Page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (Total: <?php echo $filtered_total; ?> records)
                </div>
                <div class="flex gap-1">
                    <?php if ($page > 1): ?>
                        <a href="index.php?page=renewals&p=<?php echo ($page - 1); ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>&sw_type=<?php echo urlencode($sw_type); ?>&city=<?php echo urlencode($city); ?>" class="btn-pill btn-pill-outline text-xs" style="padding: 4px 10px;">Previous</a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="index.php?page=renewals&p=<?php echo ($page + 1); ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>&sw_type=<?php echo urlencode($sw_type); ?>&city=<?php echo urlencode($city); ?>" class="btn-pill btn-pill-outline text-xs" style="padding: 4px 10px;">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- SCAN-TO-CALL QR CODE LIGHTBOX MODAL ("no. k samne qr taki scan krke call ho jaye") -->
<div class="qr-modal-overlay" id="call-qr-modal" onclick="closeCallQRModal(event)">
    <div class="qr-modal-card" onclick="event.stopPropagation()">
        <span class="qr-modal-close" onclick="closeCallQRModal()">&times;</span>
        <div style="font-size: 0.72rem; font-weight: 800; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em;">Scan to Phone Call</div>
        <h3 id="qr-client-name" style="font-size: 1.1rem; font-weight: 800; color: var(--text-main); margin-top: 4px; margin-bottom: 2px;">Client Call QR</h3>
        <div id="qr-client-phone" style="font-weight: 700; font-size: 0.9rem; color: var(--text-muted); font-family: monospace;">+91 0000000000</div>

        <!-- Generated QR Code Image -->
        <img id="qr-code-img" src="" alt="Scan Call QR Code" class="qr-img-large">

        <p class="text-xs text-muted mb-4" style="line-height: 1.4;">
            Point your mobile camera or WhatsApp QR scanner at this code to automatically dial this client.
        </p>

        <a id="qr-direct-call-btn" href="tel:" class="btn-pill btn-pill-dark w-full block text-center" style="background: #10b981; color: white; border: none; padding: 10px; font-weight: 700; text-decoration: none;">
            <i data-lucide="phone-call" style="width: 15px; height: 15px; display: inline-block; vertical-align: middle; margin-right: 4px;"></i>
            Direct Call Mobile
        </a>
    </div>
</div>

<script>
function normalizePhoneForDialing(phone) {
    if (!phone) return '';
    let digits = String(phone).replace(/[^0-9+]/g, '');
    if (!digits) return '';
    if (digits.startsWith('+')) return digits;
    if (digits.length === 10) return '+91' + digits;
    if (digits.length === 12 && digits.startsWith('91')) return '+' + digits;
    if (digits.length === 11 && digits.startsWith('0')) return '+91' + digits.substring(1);
    return '+91' + digits;
}

function openCallQRModal(phone, clientName, qrUrl) {
    const modal = document.getElementById('call-qr-modal');
    const nameElem = document.getElementById('qr-client-name');
    const phoneElem = document.getElementById('qr-client-phone');
    const imgElem = document.getElementById('qr-code-img');
    const callBtn = document.getElementById('qr-direct-call-btn');

    const formattedPhone = normalizePhoneForDialing(phone);
    const telPayload = 'tel:' + formattedPhone;
    const dynamicQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=4&data=' + encodeURIComponent(telPayload);

    if (modal && imgElem) {
        if (nameElem) nameElem.innerText = clientName || 'Client';
        if (phoneElem) phoneElem.innerText = formattedPhone || '-';
        imgElem.src = dynamicQrUrl;
        if (callBtn) callBtn.href = telPayload;

        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeCallQRModal(e) {
    const modal = document.getElementById('call-qr-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}
</script>
