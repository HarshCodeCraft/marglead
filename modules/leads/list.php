<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';



// Pagination variables
$page_num = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$limit = isset($_GET['limit']) ? $_GET['limit'] : 25; // default to 25 leads per page

if ($limit !== 'all') {
    $limit = max(10, intval($limit));
}

// Fetch leads dynamically from XAMPP database
$total_leads = 0;
$leads = [];

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

// Dates setup for Today, Tomorrow, Next Day summary metric cards
$today_str = date('Y-m-d');
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));
$nextday_str = date('Y-m-d', strtotime('+2 days'));

// Fetch live metrics dynamically
$liveMetrics = getLiveMetricCounts($pdo, $is_admin, $user_name);
$expired_counts = $liveMetrics['expired'];
$demo_counts = $liveMetrics['demo'];
$callback_counts = $liveMetrics['callback'];

// Active Filter URL Helpers
$active_filter = $_GET['card_filter'] ?? $_GET['filter_card'] ?? $_GET['filter'] ?? '';
$active_day = $_GET['day'] ?? '';

if (!function_exists('getFilterUrl')) {
    function getFilterUrl($filter_name, $day_name, $current_filter, $current_day) {
        if ($current_filter === $filter_name && $current_day === $day_name) {
            return 'index.php?page=leads'; // Toggle off
        }
        return 'index.php?page=leads&card_filter=' . urlencode($filter_name) . '&day=' . urlencode($day_name);
    }
}

if (!function_exists('getFilterStyle')) {
    function getFilterStyle($filter_name, $day_name, $current_filter, $current_day) {
        if ($current_filter === $filter_name && $current_day === $day_name) {
            return 'outline: 2px solid var(--primary); outline-offset: 2px; background: rgba(0, 77, 64, 0.08); border-radius: 8px; transform: scale(1.04);';
        }
        return '';
    }
}

if ($db_connected && $pdo) {
    try {
        $where_conditions = [];
        $query_params = [];

        if (!$is_admin && !empty($user_name)) {
            $where_conditions[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(assigned_to, ', ', ','))) OR assigned_to LIKE ?)";
            $query_params[] = $user_name;
            $query_params[] = $user_name;
            $query_params[] = '%' . $user_name . '%';
        }

        if (!empty($_GET['filter']) && $_GET['filter'] === 'today') {
            $where_conditions[] = "DATE(created_at) = CURRENT_DATE()";
        }

        $search_term = trim($_GET['search'] ?? $_GET['q'] ?? '');
        if (!empty($search_term)) {
            $clean_search_phone = preg_replace('/[^0-9]/', '', $search_term);
            if (!empty($clean_search_phone) && strlen($clean_search_phone) >= 4) {
                $where_conditions[] = "(id LIKE ? OR name LIKE ? OR company LIKE ? OR phone LIKE ? OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+91', '') LIKE ? OR email LIKE ? OR address LIKE ? OR source LIKE ? OR tags LIKE ? OR enq_for LIKE ? OR contact_person LIKE ? OR remarks LIKE ?)";
                $st = '%' . $search_term . '%';
                $pst = '%' . $clean_search_phone . '%';
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $pst;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
                $query_params[] = $st;
            } else {
                $where_conditions[] = "(id LIKE ? OR name LIKE ? OR company LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ? OR source LIKE ? OR tags LIKE ? OR enq_for LIKE ? OR contact_person LIKE ? OR remarks LIKE ?)";
                $st = '%' . $search_term . '%';
                for ($s = 0; $s < 12; $s++) {
                    $query_params[] = $st;
                }
            }
        }

        if (!empty($_GET['source'])) {
            $where_conditions[] = "LOWER(source) = ?";
            $query_params[] = strtolower(trim($_GET['source']));
        }

        if (!empty($_GET['assigned_to'])) {
            $req_op = trim($_GET['assigned_to']);
            $where_conditions[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(assigned_to, ', ', ','))) OR assigned_to LIKE ?)";
            $query_params[] = $req_op;
            $query_params[] = $req_op;
            $query_params[] = '%' . $req_op . '%';
        }

        if (!empty($_GET['priority'])) {
            $where_conditions[] = "LOWER(priority) = ?";
            $query_params[] = strtolower($_GET['priority']);
        }

        if (!empty($_GET['status'])) {
            $st = strtolower($_GET['status']);
            if ($st === 'won') {
                $where_conditions[] = "LOWER(status) IN ('won', 'closed_won', 'install_pending', 'payment_pending')";
            } elseif ($st === 'lost') {
                $where_conditions[] = "LOWER(status) IN ('lost', 'closed_lost')";
            } else {
                $where_conditions[] = "LOWER(status) = ?";
                $query_params[] = $st;
            }
        }

        // Apply Metric Card Filter if clicked
        if (!empty($active_filter) && !empty($active_day)) {
            if ($active_filter === 'expired') {
                if ($active_day === 'all' || $active_day === 'total') {
                    $where_conditions[] = "(id IN (SELECT lead_id FROM followups WHERE status IN ('pending', 'missed') AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%' OR status = 'missed' OR scheduled_at <= NOW())) OR id IN (SELECT lead_id FROM renewals) OR LOWER(status) IN ('expired', 'trial_expired'))";
                } elseif ($active_day === 'today') {
                    $where_conditions[] = "(id IN (SELECT lead_id FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) <= ? AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%' OR status = 'missed' OR scheduled_at <= NOW())) OR id IN (SELECT lead_id FROM renewals WHERE DATE(expiry_date) <= ?))";
                    $query_params[] = $today_str;
                    $query_params[] = $today_str;
                } else {
                    $target_date = ($active_day === 'tomorrow') ? $tomorrow_str : $nextday_str;
                    $where_conditions[] = "(id IN (SELECT lead_id FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) = ? AND (action_type LIKE '%Expiry%' OR action_type LIKE '%Renewal%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR remarks LIKE '%expir%' OR remarks LIKE '%renew%')) OR id IN (SELECT lead_id FROM renewals WHERE DATE(expiry_date) = ?))";
                    $query_params[] = $target_date;
                    $query_params[] = $target_date;
                }
            } elseif ($active_filter === 'demo_scheduled') {
                if ($active_day === 'all' || $active_day === 'total') {
                    $where_conditions[] = "(LOWER(status) = 'demo_scheduled' OR id IN (SELECT lead_id FROM demos WHERE status = 'scheduled') OR id IN (SELECT lead_id FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%')))";
                } elseif ($active_day === 'today') {
                    $where_conditions[] = "(LOWER(status) = 'demo_scheduled' OR id IN (SELECT lead_id FROM demos WHERE status = 'scheduled' AND DATE(scheduled_at) <= ?) OR id IN (SELECT lead_id FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') AND DATE(scheduled_at) <= ?))";
                    $query_params[] = $today_str;
                    $query_params[] = $today_str;
                } else {
                    $target_date = ($active_day === 'tomorrow') ? $tomorrow_str : $nextday_str;
                    $where_conditions[] = "(LOWER(status) = 'demo_scheduled' OR id IN (SELECT lead_id FROM demos WHERE status = 'scheduled' AND DATE(scheduled_at) = ?) OR id IN (SELECT lead_id FROM followups WHERE status = 'pending' AND (action_type LIKE '%Demo%' OR action_type LIKE '%Trail%' OR action_type LIKE '%Trial%' OR action_type LIKE '%Demonstration%') AND DATE(scheduled_at) = ?))";
                    $query_params[] = $target_date;
                    $query_params[] = $target_date;
                }
            } elseif ($active_filter === 'callback') {
                if ($active_day === 'all' || $active_day === 'total') {
                    $where_conditions[] = "id IN (SELECT lead_id FROM followups WHERE status = 'pending')";
                } elseif ($active_day === 'today') {
                    $where_conditions[] = "id IN (SELECT lead_id FROM followups WHERE status = 'pending' AND DATE(scheduled_at) <= ?)";
                    $query_params[] = $today_str;
                } else {
                    $target_date = ($active_day === 'tomorrow') ? $tomorrow_str : $nextday_str;
                    $where_conditions[] = "id IN (SELECT lead_id FROM followups WHERE status = 'pending' AND DATE(scheduled_at) = ?)";
                    $query_params[] = $target_date;
                }
            }
        }

        $where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

        // Count total matching leads
        $countSql = "SELECT COUNT(*) FROM leads {$where_sql}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($query_params);
        $total_leads = $stmtCount->fetchColumn();

        $total_pages = 1;
        if ($limit !== 'all') {
            $total_pages = ceil(max(1, $total_leads) / $limit);
            if ($page_num > $total_pages) {
                $page_num = max(1, $total_pages);
            }
            $offset = ($page_num - 1) * $limit;
        }

        if ($limit === 'all') {
            $fetchSql = "SELECT * FROM leads {$where_sql} ORDER BY created_at DESC";
            $stmt = $pdo->prepare($fetchSql);
            $stmt->execute($query_params);
        } else {
            $fetchSql = "SELECT * FROM leads {$where_sql} ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($fetchSql);
            $idx = 1;
            foreach ($query_params as $paramVal) {
                $stmt->bindValue($idx++, $paramVal, PDO::PARAM_STR);
            }
            $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        $db_leads = $stmt->fetchAll();
        foreach ($db_leads as $l) {
            $leads[] = [
                'id' => $l['id'],
                'name' => $l['name'],
                'company' => $l['company'],
                'city' => $l['city'],
                'phone' => $l['phone'],
                'email' => $l['email'],
                'source' => $l['source'],
                'priority' => $l['priority'],
                'status' => $l['status'],
                'assigned' => $l['assigned_to'] ?? 'Unassigned',
                'assigned_by' => $l['assigned_by'] ?? 'Admin',
                'budget' => '₹' . number_format($l['budget'], 0),
                'last_contact' => date('Y-m-d h:i A', strtotime($l['updated_at'])),
                'address' => $l['address'] ?? '',
                'tags' => $l['tags'] ?? '',
                'enq_for' => $l['enq_for'] ?? '',
                'contact_person' => $l['contact_person'] ?? '',
                'remarks' => $l['remarks'] ?? ''
            ];
        }
    } catch (PDOException $e) {
        $leads = [];
    }
}

$operators = [];
if ($db_connected && $pdo) {
    try {
        $stmtOp = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC");
        $operators = $stmtOp->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}

// Helper to build URL with paginated query parameters
function getPageUrl($p, $limit) {
    $params = $_GET;
    $params['p'] = $p;
    $params['limit'] = $limit;
    return 'index.php?' . http_build_query($params);
}

// No mock data fallback - keep leads directory clean
if (empty($leads)) {
    $total_leads = 0;
    $total_pages = 1;
    $page_num = 1;
}
?>

<div class="leads-list-container">
    <!-- Header Page Row -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 class="mb-1" style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;">Leads Directory</h2>
            <p class="text-muted text-sm">Review, screen, sort, assign, and execute conversions on incoming business opportunities.</p>
        </div>
        <div class="flex gap-2">
            <?php if (hasActionAccess('can_bulk_upload')): ?>
                <a href="index.php?page=lead_import" class="btn btn-secondary text-sm">
                    <i data-lucide="file-down" style="width: 16px; height: 16px;"></i>
                    <span>Bulk Import</span>
                </a>
            <?php endif; ?>
            <?php if (hasActionAccess('can_export')): ?>
                <a href="index.php?page=leads&action=export_csv" class="btn btn-secondary text-sm">
                    <i data-lucide="file-up" style="width: 16px; height: 16px;"></i>
                    <span>Export Directory</span>
                </a>
            <?php endif; ?>
            <?php if (hasActionAccess('can_create')): ?>
                <a href="index.php?page=lead_form" class="btn btn-primary text-sm">
                    <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                    <span>Create Lead</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Live Metric Cards: Upcoming Expired Lead, Demo Scheduled, Call Back (Clickable Filters) -->
    <div class="grid mb-6 live-metric-cards-container" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
        <!-- Card 1: Upcoming Expired Lead -->
        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.15rem;">
                <h4 style="font-family: var(--font-heading); font-weight: 700; font-size: 0.95rem; color: var(--text-main); margin: 0;">Upcoming Expired Lead</h4>
                <a href="<?php echo getFilterUrl('expired', 'all', $active_filter, $active_day); ?>"
                   style="background: rgba(229, 57, 53, 0.12); color: #e53935; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.65rem; border-radius: 12px; text-decoration: none; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'all', $active_filter, $active_day); ?>"
                   title="Click to view all Upcoming Expired Leads">
                    Total: <span id="cnt-expired-total"><?php echo $expired_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('expired', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Today">
                    <span id="cnt-expired-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Tomorrow">
                    <span id="cnt-expired-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Next Day">
                    <span id="cnt-expired-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['next_day']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Next Day</span>
                </a>
            </div>
        </div>

        <!-- Card 2: Demo Scheduled -->
        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.15rem;">
                <h4 style="font-family: var(--font-heading); font-weight: 700; font-size: 0.95rem; color: var(--text-main); margin: 0;">Demo Scheduled</h4>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'all', $active_filter, $active_day); ?>"
                   style="background: rgba(245, 124, 0, 0.12); color: #f57c00; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.65rem; border-radius: 12px; text-decoration: none; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'all', $active_filter, $active_day); ?>"
                   title="Click to view all Scheduled Demos">
                    Total: <span id="cnt-demo-total"><?php echo $demo_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Today">
                    <span id="cnt-demo-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Tomorrow">
                    <span id="cnt-demo-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Next Day">
                    <span id="cnt-demo-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['next_day']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Next Day</span>
                </a>
            </div>
        </div>

        <!-- Card 3: Call Back -->
        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.15rem;">
                <h4 style="font-family: var(--font-heading); font-weight: 700; font-size: 0.95rem; color: var(--text-main); margin: 0;">Call Back</h4>
                <a href="<?php echo getFilterUrl('callback', 'all', $active_filter, $active_day); ?>"
                   style="background: rgba(0, 150, 136, 0.12); color: #009688; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.65rem; border-radius: 12px; text-decoration: none; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'all', $active_filter, $active_day); ?>"
                   title="Click to view all Call Back follow-ups">
                    Total: <span id="cnt-callback-total"><?php echo $callback_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('callback', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Today">
                    <span id="cnt-callback-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Tomorrow">
                    <span id="cnt-callback-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Next Day">
                    <span id="cnt-callback-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['next_day']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Next Day</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Live Auto-fetch Script for Metrics -->
    <script>
    (function autoFetchLiveMetrics() {
        function fetchMetrics() {
            fetch('api/metrics.php')
                .then(res => res.json())
                .then(data => {
                    if (data && data.success && data.metrics) {
                        const m = data.metrics;
                        if (m.expired) {
                            if (document.getElementById('cnt-expired-total')) document.getElementById('cnt-expired-total').textContent = m.expired.total;
                            if (document.getElementById('cnt-expired-today')) document.getElementById('cnt-expired-today').textContent = m.expired.today;
                            if (document.getElementById('cnt-expired-tomorrow')) document.getElementById('cnt-expired-tomorrow').textContent = m.expired.tomorrow;
                            if (document.getElementById('cnt-expired-nextday')) document.getElementById('cnt-expired-nextday').textContent = m.expired.next_day;
                        }
                        if (m.demo) {
                            if (document.getElementById('cnt-demo-total')) document.getElementById('cnt-demo-total').textContent = m.demo.total;
                            if (document.getElementById('cnt-demo-today')) document.getElementById('cnt-demo-today').textContent = m.demo.today;
                            if (document.getElementById('cnt-demo-tomorrow')) document.getElementById('cnt-demo-tomorrow').textContent = m.demo.tomorrow;
                            if (document.getElementById('cnt-demo-nextday')) document.getElementById('cnt-demo-nextday').textContent = m.demo.next_day;
                        }
                        if (m.callback) {
                            if (document.getElementById('cnt-callback-total')) document.getElementById('cnt-callback-total').textContent = m.callback.total;
                            if (document.getElementById('cnt-callback-today')) document.getElementById('cnt-callback-today').textContent = m.callback.today;
                            if (document.getElementById('cnt-callback-tomorrow')) document.getElementById('cnt-callback-tomorrow').textContent = m.callback.tomorrow;
                            if (document.getElementById('cnt-callback-nextday')) document.getElementById('cnt-callback-nextday').textContent = m.callback.next_day;
                        }
                    }
                })
                .catch(err => console.debug('Metrics auto-fetch skipped:', err));
        }

        // Fetch on initial page load
        document.addEventListener('DOMContentLoaded', fetchMetrics);
        // Refresh every 30 seconds
        setInterval(fetchMetrics, 30000);
        window.refreshLiveMetrics = fetchMetrics;
    })();
    </script>

    <!-- Table Action Controls Bar -->
    <div class="card p-4 mb-6 flex flex-wrap align-center justify-between gap-4" style="border: 1px solid var(--border-color);">
        <!-- Left: Search and filters toggler -->
        <div class="flex align-center gap-3 flex-wrap">
            <div class="search-input-wrapper flex align-center gap-2" style="background-color: var(--bg-app); border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: var(--border-radius-sm); width: 300px; position: relative;">
                <i data-lucide="search" class="text-muted" style="width: 16px; height: 16px; flex-shrink: 0;"></i>
                <input type="text" id="leads-search-input" placeholder="Search customer, company, phone..." class="w-full text-xs" style="border: none; background: transparent; outline: none;" value="<?php echo htmlspecialchars($search_term ?? ''); ?>">
                <?php if (!empty($search_term)): ?>
                    <button type="button" onclick="clearLeadsSearch()" class="text-muted hover-danger" style="background: transparent; border: none; cursor: pointer; padding: 0; display: flex; align-items: center;" title="Clear Search Filter">
                        <i data-lucide="x-circle" style="width: 15px; height: 15px;"></i>
                    </button>
                <?php endif; ?>
            </div>
            <button class="btn btn-secondary text-xs" style="padding: 0.55rem 1rem;" onclick="document.getElementById('advanced-filter-drawer').classList.toggle('hidden');">
                <i data-lucide="filter" style="width: 14px; height: 14px;"></i>
                <span>Advanced Filters</span>
            </button>
        </div>

        <!-- Right: Batch updates selection -->
        <style>
            .employee-hover-tooltip-container:hover .employee-hover-card {
                display: block !important;
            }
            .employee-hover-card.hidden-tooltip {
                display: none;
            }
            .employee-item-row:hover {
                background-color: rgba(99, 102, 241, 0.12) !important;
            }
        </style>
        <div class="flex align-center gap-2 flex-wrap" style="position: relative;">
            <span class="text-xs text-muted font-semibold">Batch Actions:</span>
            <select class="form-control text-xs" style="width: auto; padding: 0.4rem 0.8rem; height: 34px;" id="batch-action-select" onchange="handleBatchActionChange()">
                <option value="">-- Choose Action --</option>
                <option value="assign">Assign to Employee</option>
                <option value="status">Change Status Stage</option>
                <option value="priority">Set Priority Level</option>
                <option value="drop">Drop Selected Leads (No Delete)</option>
                <option value="restore">Re-activate / Restore Dropped Leads</option>
            </select>

            <!-- Dynamic Sub-Select: Employee Assignment (Multi-Employee Selection) -->
            <div id="batch-employee-wrapper" class="hidden flex align-center gap-2" style="position: relative;">
                <div class="batch-emp-dropdown-container" style="position: relative; display: inline-block;">
                    <button type="button" class="btn btn-secondary text-xs flex align-center justify-between gap-2" id="batch-emp-btn" onclick="toggleBatchEmpDropdown()" style="height: 34px; padding: 0.4rem 0.8rem; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-main); font-weight: 600;">
                        <span id="batch-emp-btn-text">-- Select Employee(s) --</span>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </button>
                    <div id="batch-emp-dropdown-menu" class="hidden" style="position: absolute; top: 110%; left: 0; z-index: 1000; width: 250px; max-height: 220px; overflow-y: auto; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); box-shadow: 0 8px 24px rgba(0,0,0,0.18); padding: 0.6rem;">
                        <div class="flex justify-between align-center mb-2 pb-1" style="border-bottom: 1px solid var(--border-color);">
                            <span class="text-xs font-bold text-muted" style="text-transform: uppercase;">Select Assignees</span>
                            <span class="text-xs text-primary font-semibold pointer" onclick="toggleSelectAllBatchEmp()">Toggle All</span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <?php foreach ($operators as $op): ?>
                                <label class="flex align-center gap-2 text-xs pointer p-1 rounded hover-bg" style="cursor: pointer; display: flex; align-items: center; padding: 0.3rem 0.5rem; border-radius: 4px; user-select: none;">
                                    <input type="checkbox" class="batch-emp-cb" value="<?php echo htmlspecialchars($op); ?>" onchange="updateBatchEmpBtnText()" style="accent-color: var(--primary); width: 14px; height: 14px;">
                                    <span><?php echo htmlspecialchars($op); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <!-- Hover Employee List Badge -->
                <div class="employee-hover-tooltip-container" style="position: relative; display: inline-block;">
                    <span class="badge text-xs" style="background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid var(--primary); cursor: pointer; padding: 0.35rem 0.6rem;" title="Hover to view all available employees">
                        <i data-lucide="users" style="width: 13px; height: 13px; vertical-align: middle; margin-right: 3px;"></i>
                        <?php echo count($operators); ?> Active Staff
                    </span>
                    <div class="employee-hover-card hidden-tooltip" style="position: absolute; right: 0; top: 110%; z-index: 999; background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #cbd5e1); box-shadow: 0 10px 25px rgba(0,0,0,0.2); border-radius: 8px; padding: 0.75rem; width: 230px; font-size: 0.75rem; color: var(--text-color, #1e293b);">
                        <div class="font-bold mb-2 pb-1 text-primary" style="border-bottom: 1px solid var(--border-color, #e2e8f0); display: flex; align-items: center; justify-content: space-between;">
                            <span>Active Employees List</span>
                            <span class="badge text-xs" style="font-size: 9px; padding: 2px 6px;"><?php echo count($operators); ?> Total</span>
                        </div>
                        <div style="max-height: 190px; overflow-y: auto;">
                            <?php foreach ($operators as $op): ?>
                                <div class="employee-item-row py-1 px-2 flex align-center gap-2 rounded mb-1" style="cursor: pointer; transition: background 0.15s ease;" onclick="document.getElementById('batch-employee-select').value='<?php echo addslashes($op); ?>';">
                                    <div style="width: 20px; height: 20px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                                        <?php echo substr($op, 0, 1); ?>
                                    </div>
                                    <span style="font-weight: 600; font-size: 11px;"><?php echo htmlspecialchars($op); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dynamic Sub-Select: Status Stage -->
            <select class="form-control text-xs hidden" style="width: auto; padding: 0.4rem 0.8rem; height: 34px;" id="batch-status-select">
                <option value="">-- Select Status Stage --</option>
                <option value="new">New Lead</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="demo_scheduled">Demo Scheduled</option>
                <option value="quotation_sent">Quotation Sent</option>
                <option value="negotiation">Negotiation</option>
                <option value="won">Won / Closed</option>
                <option value="lost">Lost</option>
            </select>

            <!-- Dynamic Sub-Select: Priority Level -->
            <select class="form-control text-xs hidden" style="width: auto; padding: 0.4rem 0.8rem; height: 34px;" id="batch-priority-select">
                <option value="">-- Select Priority --</option>
                <option value="hot">Hot</option>
                <option value="warm">Warm</option>
                <option value="cold">Cold</option>
            </select>

            <button class="btn btn-primary text-xs" style="padding: 0.55rem 1rem;" onclick="executeBatchAction()">Apply</button>
        </div>
    </div>

    <!-- Advanced Filter Drawer (Collapsible) -->
    <div id="advanced-filter-drawer" class="card p-6 mb-6 hidden" style="border: 1px solid var(--border-color); animation: fadeIn 0.2s ease-in-out;">
        <div class="flex justify-between align-center mb-4">
            <h4 class="font-semibold text-sm m-0" style="text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Lead Filters Configuration</h4>
            <button class="text-xs text-primary font-semibold pointer" onclick="resetFilters()">Reset All Filters</button>
        </div>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1.25rem;">
            <!-- Source Filter -->
            <div class="form-group m-0">
                <label class="form-label text-xs">Lead Source</label>
                <select id="filter-source" class="form-control text-xs" onchange="applyAdvancedFilters(true)">
                    <option value="">All Sources</option>
                    <?php 
                    $cur_src = $_GET['source'] ?? '';
                    $src_list = ['Website', 'Google Ads', 'Cold Calls', 'Referrals', 'Exhibitions', 'HO', 'Office', 'Imported'];
                    foreach ($src_list as $srcItem): 
                    ?>
                        <option value="<?php echo htmlspecialchars($srcItem); ?>" <?php echo (strcasecmp($cur_src, $srcItem) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($srcItem); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Priority Filter -->
            <div class="form-group m-0">
                <label class="form-label text-xs">Priority</label>
                <select id="filter-priority" class="form-control text-xs" onchange="applyAdvancedFilters(true)">
                    <option value="">All Priorities</option>
                    <?php $cur_prio = strtolower($_GET['priority'] ?? ''); ?>
                    <option value="hot" <?php echo ($cur_prio === 'hot') ? 'selected' : ''; ?>>Hot</option>
                    <option value="warm" <?php echo ($cur_prio === 'warm') ? 'selected' : ''; ?>>Warm</option>
                    <option value="cold" <?php echo ($cur_prio === 'cold') ? 'selected' : ''; ?>>Cold</option>
                </select>
            </div>
            <!-- Status Filter -->
            <div class="form-group m-0">
                <label class="form-label text-xs">Pipeline Status</label>
                <select id="filter-status" class="form-control text-xs" onchange="applyAdvancedFilters(true)">
                    <option value="">All Stages</option>
                    <?php 
                    $cur_st = strtolower($_GET['status'] ?? '');
                    foreach ($PIPELINE_STAGES as $key => $stage): 
                    ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($cur_st === strtolower($key)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($stage['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Assigned Exec Filter -->
            <div class="form-group m-0">
                <label class="form-label text-xs">Assigned Employee</label>
                <select id="filter-assigned" class="form-control text-xs" onchange="applyAdvancedFilters(true)">
                    <option value="">All Employees</option>
                    <?php 
                    $cur_ass = $_GET['assigned_to'] ?? '';
                    foreach ($operators as $op): 
                    ?>
                        <option value="<?php echo htmlspecialchars($op); ?>" <?php echo (strcasecmp($cur_ass, $op) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($op); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Spreadsheet-like Leads Table Container -->
    <div class="card p-0 overflow-hidden" id="leads-table-card" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 55px; text-align: center;">
                            <div class="flex align-center justify-center gap-1">
                                <input type="checkbox" id="select-all-leads" style="accent-color: var(--primary);">
                                <button type="button" class="gear-settings-btn" onclick="toggleColumnSelectorDrawer()" title="Select Columns">
                                    <i data-lucide="settings" style="width: 14px; height: 14px;"></i>
                                </button>
                            </div>
                        </th>
                        <th class="col-lead-id">Lead ID</th>
                        <th class="col-assigned">Assigned to</th>
                        <th class="col-contact-person">Person Name</th>
                        <th class="col-name">Client Details</th>
                        <th class="col-group">Group</th>
                        <th class="col-status">Status</th>
                        <th class="col-priority">Priority</th>
                        <th class="col-source">Source</th>
                        <th class="col-enq-for" style="display: none;">Enq For</th>
                        <th class="col-tags" style="display: none;">Tags</th>
                        <th class="col-address" style="display: none;">Address</th>
                        <th class="col-remarks" style="display: none;">Remarks</th>
                        <th class="col-activity">Last Activity</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $lead): ?>
                        <tr data-source="<?php echo htmlspecialchars($lead['source']); ?>" data-priority="<?php echo htmlspecialchars($lead['priority']); ?>" data-status="<?php echo htmlspecialchars($lead['status']); ?>" data-assigned="<?php echo htmlspecialchars($lead['assigned']); ?>">
                            <td style="text-align: center; vertical-align: middle;">
                                <input type="checkbox" class="lead-checkbox" value="<?php echo $lead['id']; ?>" style="accent-color: var(--primary);">
                            </td>
                            <td class="col-lead-id font-bold text-xs" style="vertical-align: middle;">
                                <a href="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" class="text-primary hover-underline"><?php echo $lead['id']; ?></a>
                            </td>
                            <td class="col-assigned" style="vertical-align: middle;">
                                <div class="flex align-center gap-2">
                                    <div style="width: 22px; height: 22px; border-radius: var(--border-radius-full); background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                                        <?php echo substr(!empty($lead['assigned']) ? $lead['assigned'] : 'U', 0, 1); ?>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-xs font-semibold"><?php echo htmlspecialchars(!empty($lead['assigned']) ? $lead['assigned'] : 'Unassigned'); ?></span>
                                        <?php if (!empty($lead['assigned_by'])): ?>
                                            <span class="text-muted" style="font-size: 0.68rem; font-weight: 500;" title="Assigned By Operator">by <?php echo htmlspecialchars($lead['assigned_by']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="col-contact-person text-xs font-semibold" style="vertical-align: middle;">
                                <?php echo htmlspecialchars(!empty($lead['name']) ? $lead['name'] : 'NA'); ?>
                            </td>
                            <td class="col-name">
                                <div class="flex flex-col">
                                    <!-- <span class="font-semibold text-sm"><?php echo htmlspecialchars($lead['name']); ?></span> -->
                                        <?php
                                        $l_clean_phone = preg_replace('/[^0-9+]/', '', $lead['phone'] ?? '');
                                        if (!empty($l_clean_phone) && !str_starts_with($l_clean_phone, '+') && strlen($l_clean_phone) === 10) {
                                            $l_clean_phone = '+91' . $l_clean_phone;
                                        }
                                        $l_tel_payload = $l_clean_phone;
                                        ?>
                                        <a href="tel:<?php echo $lead['phone']; ?>" class="flex align-center gap-1 text-muted hover-primary">
                                            <i data-lucide="phone" style="width: 12px; height: 12px;"></i> <?php echo htmlspecialchars($lead['phone']); ?>
                                        </a>
                                        <button type="button" 
                                                onclick="openCallQrModal('<?php echo htmlspecialchars(addslashes($lead['name'])); ?>', '<?php echo htmlspecialchars(addslashes($lead['phone'])); ?>', '<?php echo urlencode($l_tel_payload); ?>')"
                                                style="background: rgba(59, 130, 246, 0.1); color: var(--primary); border: none; padding: 2px 6px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 3px; font-size: 0.7rem; font-weight: 600;" 
                                                title="Scan QR to call on smartphone dial pad">
                                            <i data-lucide="qr-code" style="width: 12px; height: 12px;"></i>
                                            <span>QR</span>
                                        </button>
                                </div>
                            </td>
                            <td class="col-group" style="vertical-align: middle;">
                                <span class="font-semibold text-xs"><?php echo htmlspecialchars(!empty($lead['group_stage']) ? $lead['group_stage'] : (!empty($lead['tags']) && $lead['tags'] !== 'NA' ? $lead['tags'] : (!empty($lead['company']) ? $lead['company'] : 'NA'))); ?></span>
                            </td>
                            <td class="col-status" style="vertical-align: middle;">
                                <?php echo getStatusBadge($lead['status']); ?>
                            </td>
                            <td class="col-priority" style="vertical-align: middle;">
                                <?php echo getPriorityBadge($lead['priority']); ?>
                            </td>
                            <td class="col-source" style="vertical-align: middle;">
                                <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted);"><?php echo htmlspecialchars(!empty($lead['source']) ? $lead['source'] : 'NA'); ?></span>
                            </td>
                            <td class="col-enq-for text-xs" style="vertical-align: middle; display: none;">
                                <?php echo htmlspecialchars(!empty($lead['enq_for']) ? $lead['enq_for'] : 'NA'); ?>
                            </td>
                            <td class="col-tags text-xs" style="vertical-align: middle; display: none;">
                                <span class="badge text-xs"><?php echo htmlspecialchars(!empty($lead['tags']) ? $lead['tags'] : 'NA'); ?></span>
                            </td>
                            <td class="col-address text-xs text-muted" style="vertical-align: middle; display: none;">
                                <?php echo htmlspecialchars(!empty($lead['address']) ? $lead['address'] : 'NA'); ?>
                            </td>
                            <td class="col-remarks text-xs text-muted" style="vertical-align: middle; display: none;">
                                <?php echo htmlspecialchars(!empty($lead['remarks']) ? $lead['remarks'] : 'NA'); ?>
                            </td>
                            <td class="col-activity text-xs text-muted" style="vertical-align: middle;">
                                <?php echo $lead['last_contact']; ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <?php if ($lead['status'] === 'dropped'): ?>
                                        <button class="btn-icon reactivate-lead-btn text-success" data-id="<?php echo $lead['id']; ?>" title="Re-activate Lead to Active Pipeline" style="background-color: rgba(16, 185, 129, 0.1); border: 1px solid #10b981;">
                                            <i data-lucide="rotate-ccw" style="width: 14px; height: 14px; color: #10b981;"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-icon quick-followup-btn text-primary" data-id="<?php echo $lead['id']; ?>" title="Quick Follow-up & Data Fill">
                                            <i data-lucide="phone-call" style="width: 14px; height: 14px; color: var(--primary);"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" class="btn-icon" title="View Details Folder">
                                        <i data-lucide="folder-open" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="index.php?page=lead_form&action=edit&id=<?php echo $lead['id']; ?>" class="btn-icon" title="Edit Lead info">
                                        <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Table Pagination Info row -->
        <?php
        $start_num = $limit === 'all' ? 1 : (($page_num - 1) * $limit + 1);
        $end_num = $limit === 'all' ? $total_leads : min($total_leads, $page_num * $limit);
        if ($total_leads == 0) {
            $start_num = 0;
            $end_num = 0;
        }
        ?>
        <div class="flex justify-between align-center p-4 border-top flex-wrap gap-4" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
            <div class="flex align-center gap-3">
                <span class="text-xs text-muted">Showing <?php echo $start_num; ?> to <?php echo $end_num; ?> of <?php echo $total_leads; ?> leads</span>
                <span class="text-xs text-muted">|</span>
                <span class="text-xs text-muted">Show:</span>
                <select class="form-control text-xs" style="width: auto; padding: 0.2rem 0.5rem; height: 28px;" onchange="fetchLeadsPartialWithoutReload(this.value, true);">
                    <option value="<?php echo getPageUrl(1, 10); ?>" <?php echo $limit == 10 ? 'selected' : ''; ?>>10 per page</option>
                    <option value="<?php echo getPageUrl(1, 25); ?>" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                    <option value="<?php echo getPageUrl(1, 50); ?>" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                    <option value="<?php echo getPageUrl(1, 100); ?>" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                    <option value="<?php echo getPageUrl(1, 'all'); ?>" <?php echo $limit === 'all' ? 'selected' : ''; ?>>View All</option>
                </select>
            </div>
            
            <?php if ($limit !== 'all' && $total_pages > 1): 
                $visible_pages = 5;
                $half = floor($visible_pages / 2);
                $start_page = max(1, $page_num - $half);
                $end_page = min($total_pages, $start_page + $visible_pages - 1);
                if ($end_page - $start_page + 1 < $visible_pages) {
                    $start_page = max(1, $end_page - $visible_pages + 1);
                }
            ?>
                <div class="flex align-center gap-1">
                    <?php if ($page_num > 1): ?>
                        <a href="<?php echo getPageUrl($page_num - 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block;">Prev</a>
                    <?php else: ?>
                        <button class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem;" disabled>Prev</button>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): 
                        $isCurrent = ($i === $page_num);
                        $btnClass = $isCurrent ? 'btn-primary' : 'btn-secondary';
                        $style = $isCurrent ? 'background-color: var(--primary);' : '';
                    ?>
                        <a href="<?php echo getPageUrl($i, $limit); ?>" class="btn <?php echo $btnClass; ?> text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block; <?php echo $style; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page_num < $total_pages): ?>
                        <a href="<?php echo getPageUrl($page_num + 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block;">Next</a>
                    <?php else: ?>
                        <button class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem;" disabled>Next</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Follow-up & Data Fill Modal -->
<div id="quick-followup-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 900px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; max-height: 90vh; color: var(--text-main);">
        <!-- Modal Header -->
        <div class="flex justify-between align-center mb-6" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h3 class="font-bold text-lg" id="qf-modal-title" style="font-family: var(--font-heading); margin: 0;">Follow-Up For Client</h3>
            <button type="button" class="btn-icon" onclick="closeQuickFollowupModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
                <i data-lucide="x" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
            </button>
        </div>
        
        <!-- Modal Form Content -->
        <form id="quick-followup-form" method="POST" style="overflow-y: auto; flex: 1; padding-right: 8px;">
            <input type="hidden" name="action" value="quick_followup_save">
            <input type="hidden" name="lead_id" id="qf-lead-id">
            
            <div class="grid" style="grid-template-columns: 1.2fr 1fr; gap: 2rem; align-items: start;">
                <!-- Left Column: Lead Data -->
                <div>
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Group</label>
                            <select name="company" id="qf-company" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;" required>
                                <option value="Fresh">Fresh</option>
                                <option value="Followup">Followup</option>
                                <option value="Demo Scheduled">Demo Scheduled</option>
                                <option value="Demo Done">Demo Done</option>
                                <option value="Installation Done">Installation Done</option>
                                <option value="Not Required">Not Required</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Lead Status</label>
                            <select name="status" id="qf-status" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                                <?php foreach ($PIPELINE_STAGES as $key => $stage): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($stage['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Assign to Employee(s) (Select Multiple)</label>
                            <div class="employee-select-grid" style="display: flex; flex-wrap: wrap; gap: 0.4rem; max-height: 100px; overflow-y: auto; padding: 0.4rem; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                                <?php foreach ($operators as $op): ?>
                                    <label class="flex align-center gap-15 text-xs pointer" style="padding: 0.25rem 0.5rem; border-radius: 4px; background: var(--bg-card); border: 1px solid var(--border-color); user-select: none;">
                                        <input type="checkbox" name="assigned_to[]" class="qf-assigned-cb" value="<?php echo htmlspecialchars($op); ?>" style="accent-color: var(--primary); width: 13px; height: 13px;">
                                        <span><?php echo htmlspecialchars($op); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px; color: var(--text-muted);">Assigned By (Read-Only)</label>
                            <input type="text" id="qf-assigned-by" class="form-control text-sm" readonly disabled style="width: 100%; height: 36px; padding: 0.5rem; background: var(--bg-hover); opacity: 0.85; cursor: not-allowed; font-weight: 600;" placeholder="Not assigned yet">
                        </div>
                    </div>
                    
                    <!-- <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <label class="form-label text-xs font-semibold" style="margin: 0;">Tags</label>
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border: 1.2px solid var(--text-muted); border-radius: 50%; cursor: pointer;" title="Add Tag"><i data-lucide="plus" style="width: 10px; height: 10px; color: var(--text-muted);"></i></span>
                            </div>
                            <input type="text" name="tags" id="qf-tags" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;" placeholder="e.g. Cold, Hot, Retail">
                        </div>
                        <div class="grid" style="grid-template-columns: 1.2fr 1fr; gap: 0.5rem; margin-bottom: 0;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Reminder</label>
                                <input type="date" name="reminder_date" id="qf-reminder-date" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Time</label>
                                <input type="time" name="reminder_time" id="qf-reminder-time" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                            </div>
                        </div>
                    </div> -->
                    
                    <div class="form-group mb-4">
                        <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Address</label>
                        <textarea name="address" id="qf-address" class="form-control text-sm" style="width: 100%; min-height: 60px; height: 60px; resize: vertical; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);"></textarea>
                    </div>
                    
                    <h4 class="text-xs font-bold text-muted mb-3" style="text-transform: uppercase; letter-spacing: 0.05em; border-top: 1px solid var(--border-color); padding-top: 1rem; margin-top: 1.5rem; margin-bottom: 1rem;">Additional Fields</h4>
                    
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1.2fr; gap: 0.75rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Source</label>
                            <select name="source" id="qf-source" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                                <option value="Website">Website</option>
                                <option value="Google Ads">Google Ads</option>
                                <option value="Cold Calls">Cold Calls</option>
                                <option value="Referrals">Referrals</option>
                                <option value="Exhibitions">Exhibitions</option>
                                <option value="HO">HO</option>
                                <option value="Office">Office</option>
                                <option value="Imported">Imported</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Enq_For</label>
                            <select name="enq_for" id="qf-enq-for" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                                <option value="">-- Choose --</option>
                                <option value="Marg Basic">Marg Basic</option>
                                <option value="Marg Silver">Marg Silver</option>
                                <option value="Marg Gold">Marg Gold</option>
                                <option value="Marg Nano">Marg Nano</option>
                                <option value="Marg Hr">Marg Hr</option>
                                <option value="Marg Colud">Marg Colud</option>
                                <option value="Marg Book Gold">Marg Book Gold</option>
                                <option value="Marg Book Silver">Marg Book Silver</option>
                                <option value="Marg Enterprises">Marg Enterprises</option>
                                <option value="Marg Mart">Marg Mart</option>
                                <option value="Marg Dimond">Marg Dimond</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Contact Person</label>
                            <input type="text" name="contact_person" id="qf-contact-person" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;" placeholder="Contact Person Name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Remarks</label>
                        <textarea name="remarks" id="qf-remarks" class="form-control text-sm" style="width: 100%; min-height: 50px; height: 50px; resize: vertical; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);"></textarea>
                    </div>
                </div>
                
                <!-- Right Column: Schedule Follow-up Reminder & History -->
                <div style="border-left: 1px solid var(--border-color); padding-left: 2rem; display: flex; flex-direction: column; justify-content: flex-start; height: 100%;">
                    <h4 class="text-sm font-bold text-main mb-3" style="font-family: var(--font-heading); margin-top: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--primary);">
                        <i data-lucide="bell-ring" style="width: 16px; height: 16px;"></i>
                        Schedule Follow-up reminder
                    </h4>

                    <!-- Date & Time & Action Type Row -->
                    <div class="grid mb-3" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; width: 100%;">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Date & Time</label>
                            <input type="datetime-local" name="scheduled_at" id="qf-scheduled-at" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.4rem 0.5rem;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Action Type</label>
                            <select name="action_type" id="qf-action-type" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                                <option value="Call">Call / Phone Call</option>
                                <option value="Trail Installed">Trail Installed</option>
                                <option value="Data Input Follow Up">Data Input Follow Up</option>
                                <option value="Payment Followup">Payment Followup</option>
                                <option value="Rest Amt Followup">Rest Amt Followup</option>
                                <option value="Product Demo">Product Demo</option>
                                <option value="On-Site Visit">On-Site Visit</option>
                                <option value="Renewal / Expiry">Renewal / Expiry</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Reminder Notes / Instructions -->
                    <div class="form-group mb-3" style="width: 100%;">
                        <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Reminder notes / Instructions</label>
                        <textarea name="fup_notes" id="qf-fup-notes" class="form-control text-sm" style="width: 100%; min-height: 75px; height: 75px; resize: vertical; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);" placeholder="Add reminder notes or instructions..."></textarea>
                    </div>

                    <!-- Upcoming & Past Follow-ups Section -->
                    <div style="width: 100%; border-top: 1px solid var(--border-color); padding-top: 0.5rem; flex: 1; display: flex; flex-direction: column;">
                        <h4 class="text-xs font-bold text-muted mb-2" style="text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.5rem 0; display: flex; align-items: center; justify-content: space-between;">
                            <span>Upcoming & Past Follow-ups</span>
                            <span class="badge text-xs" id="qf-history-count" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">0 entries</span>
                        </h4>
                        <div id="qf-history-timeline" style="max-height: 125px; overflow-y: auto; padding-right: 0.5rem; display: flex; flex-direction: column; gap: 0.4rem;">
                            <p class="text-xs text-muted" style="font-style: italic;">Loading history...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal Actions Footer -->
            <div class="flex justify-end gap-3 mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn text-sm" onclick="closeQuickFollowupModal()" style="border-radius: 50px; border: 1.5px solid #004d40; background-color: transparent; color: #004d40; padding: 0.5rem 1.7rem; cursor: pointer; font-weight: 500; font-family: var(--font-heading); transition: all 0.2s ease;">Cancel</button>
                <button type="submit" class="btn text-sm" style="border-radius: 50px; border: none; background-color: #004d40; color: #ffffff; padding: 0.5rem 2.5rem; cursor: pointer; font-weight: 600; font-family: var(--font-heading); transition: all 0.2s ease;">Save</button>
            </div>
        </form>
    </div>
</div>

<style>
    .gear-settings-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: transparent;
        border: 1px solid transparent;
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .gear-settings-btn:hover {
        background: var(--border-card);
        color: var(--primary);
        border-color: var(--border-color);
        transform: rotate(30deg);
    }
    .column-drawer-content {
        width: 360px;
        max-width: 90vw;
        background: var(--bg-card);
        height: 100%;
        display: flex;
        flex-direction: column;
        box-shadow: -10px 0 30px rgba(0,0,0,0.2);
        border-left: 1px solid var(--border-color);
    }
    .col-item-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 0.9rem;
        border-radius: 8px;
        background-color: var(--bg-app);
        border: 1px solid var(--border-color);
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .col-item-card:hover {
        border-color: var(--primary);
        background-color: var(--border-card);
    }
</style>

<!-- Select Columns Right Side-Drawer Drawer Panel -->
<div id="column-selector-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; justify-content: flex-end; z-index: 1050; backdrop-filter: blur(4px);">
    <div class="column-drawer-content">
        <div class="drawer-header" style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div class="flex align-center gap-2">
                <i data-lucide="sliders" style="width: 18px; height: 18px; color: var(--primary);"></i>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.1rem; font-weight: 700; color: var(--text-main);">Select Columns</h3>
            </div>
            <button type="button" onclick="closeColumnSelectorDrawer()" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>
        
        <div class="drawer-body" style="padding: 1.25rem 1.5rem; flex: 1; overflow-y: auto;">
            <div class="flex justify-between align-center mb-3">
                <span class="text-xs text-muted">Toggle table headers:</span>
                <div class="flex gap-3">
                    <button type="button" onclick="setAllColumnCheckboxes(true)" class="text-xs text-primary font-semibold" style="background: none; border: none; cursor: pointer; padding: 0;">Select All</button>
                    <button type="button" onclick="setAllColumnCheckboxes(false)" class="text-xs text-muted font-semibold" style="background: none; border: none; cursor: pointer; padding: 0;">Clear All</button>
                </div>
            </div>
            
            <div class="column-options-list flex flex-col gap-2">
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_lead_id')">
                    <span class="text-xs font-semibold">Lead ID</span>
                    <input type="checkbox" id="cb_col_lead_id" class="col-toggle-cb" data-target="col-lead-id" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_assigned')">
                    <span class="text-xs font-semibold">Assigned to</span>
                    <input type="checkbox" id="cb_col_assigned" class="col-toggle-cb" data-target="col-assigned" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_contact_person')">
                    <span class="text-xs font-semibold">Person Name</span>
                    <input type="checkbox" id="cb_col_contact_person" class="col-toggle-cb" data-target="col-contact-person" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_name')">
                    <span class="text-xs font-semibold">Client Details</span>
                    <input type="checkbox" id="cb_col_name" class="col-toggle-cb" data-target="col-name" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_group')">
                    <span class="text-xs font-semibold">Group</span>
                    <input type="checkbox" id="cb_col_group" class="col-toggle-cb" data-target="col-group" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_status')">
                    <span class="text-xs font-semibold">Status</span>
                    <input type="checkbox" id="cb_col_status" class="col-toggle-cb" data-target="col-status" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_priority')">
                    <span class="text-xs font-semibold">Priority</span>
                    <input type="checkbox" id="cb_col_priority" class="col-toggle-cb" data-target="col-priority" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_source')">
                    <span class="text-xs font-semibold">Source</span>
                    <input type="checkbox" id="cb_col_source" class="col-toggle-cb" data-target="col-source" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_enq_for')">
                    <span class="text-xs font-semibold">Enq For</span>
                    <input type="checkbox" id="cb_col_enq_for" class="col-toggle-cb" data-target="col-enq-for" onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_tags')">
                    <span class="text-xs font-semibold">Tags</span>
                    <input type="checkbox" id="cb_col_tags" class="col-toggle-cb" data-target="col-tags" onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_address')">
                    <span class="text-xs font-semibold">Address</span>
                    <input type="checkbox" id="cb_col_address" class="col-toggle-cb" data-target="col-address" onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_remarks')">
                    <span class="text-xs font-semibold">Remarks</span>
                    <input type="checkbox" id="cb_col_remarks" class="col-toggle-cb" data-target="col-remarks" onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
                <div class="col-item-card" onclick="toggleColumnCheckbox('cb_col_activity')">
                    <span class="text-xs font-semibold">Last Activity</span>
                    <input type="checkbox" id="cb_col_activity" class="col-toggle-cb" data-target="col-activity" checked onclick="event.stopPropagation(); toggleColumnByCheckbox(this);" style="accent-color: var(--primary); width: 16px; height: 16px; cursor: pointer;">
                </div>
            </div>
        </div>
        
        <div class="drawer-footer" style="padding: 1.25rem 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 0.75rem; background: var(--bg-card);">
            <button type="button" class="btn text-xs" onclick="closeColumnSelectorDrawer()" style="border-radius: 50px; border: 1px solid var(--border-color); background: transparent; padding: 0.55rem 1.35rem; cursor: pointer;">Cancel</button>
            <button type="button" class="btn btn-primary text-xs" onclick="applyColumnPreferences()" style="border-radius: 50px; padding: 0.55rem 1.85rem; cursor: pointer; font-weight: 600;">Apply</button>
        </div>
    </div>
</div>

<script>
    // Universal Select All leads checkbox handler using event delegation
    document.addEventListener('change', function(e) {
        if (e.target && e.target.id === 'select-all-leads') {
            const isChecked = e.target.checked;
            document.querySelectorAll('.lead-checkbox').forEach(cb => {
                cb.checked = isChecked;
            });
        } else if (e.target && e.target.classList.contains('lead-checkbox')) {
            const selectAll = document.getElementById('select-all-leads');
            if (selectAll) {
                const total = document.querySelectorAll('.lead-checkbox').length;
                const checked = document.querySelectorAll('.lead-checkbox:checked').length;
                selectAll.checked = (total > 0 && total === checked);
            }
        }
    });

    // Quick Follow-up Modal controls
    const quickFollowupModal = document.getElementById('quick-followup-modal');
    
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.quick-followup-btn');
        if (!btn) return;
        
        e.preventDefault();
        const leadId = btn.getAttribute('data-id');
        
        fetch('index.php?page=leads&action=get_lead_json&id=' + leadId)
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                const lead = res.lead;
                
                // Set lead properties in form with safety checks
                if (document.getElementById('qf-lead-id')) document.getElementById('qf-lead-id').value = lead.id || '';
                if (document.getElementById('qf-modal-title')) document.getElementById('qf-modal-title').innerHTML = `Follow-Up For <strong>${lead.name || ''}</strong> ( ${lead.phone || ''} )`;
                if (document.getElementById('qf-company')) document.getElementById('qf-company').value = lead.company || '';
                if (document.getElementById('qf-status')) document.getElementById('qf-status').value = lead.status || 'new';
                const assignedList = (lead.assigned || '').split(',').map(s => s.trim().toLowerCase());
                document.querySelectorAll('.qf-assigned-cb').forEach(cb => {
                    cb.checked = assignedList.includes(cb.value.trim().toLowerCase());
                });
                if (document.getElementById('qf-assigned-by')) document.getElementById('qf-assigned-by').value = lead.assigned_by || 'Not assigned yet';
                if (document.getElementById('qf-tags')) document.getElementById('qf-tags').value = lead.tags || '';
                if (document.getElementById('qf-address')) document.getElementById('qf-address').value = lead.address || '';
                if (document.getElementById('qf-source')) document.getElementById('qf-source').value = lead.source || 'Website';
                if (document.getElementById('qf-enq-for')) document.getElementById('qf-enq-for').value = lead.enq_for || '';
                if (document.getElementById('qf-contact-person')) document.getElementById('qf-contact-person').value = lead.contact_person || '';
                if (document.getElementById('qf-remarks')) document.getElementById('qf-remarks').value = lead.remarks || '';
                
                // Check if lead already has a pending or existing follow-up in database
                let pendingFup = null;
                if (res.followup_history && res.followup_history.length > 0) {
                    pendingFup = res.followup_history.find(f => f.status === 'pending') || res.followup_history[0];
                }

                if (pendingFup && pendingFup.scheduled_at) {
                    // Pre-fill existing scheduled follow-up date/time from database
                    const fupDateStr = pendingFup.scheduled_at.replace(' ', 'T').slice(0, 16);
                    if (document.getElementById('qf-scheduled-at')) {
                        document.getElementById('qf-scheduled-at').value = fupDateStr;
                    }
                    if (document.getElementById('qf-action-type')) {
                        document.getElementById('qf-action-type').value = pendingFup.action_type || 'Trail Installed';
                    }
                    if (document.getElementById('qf-fup-notes')) {
                        document.getElementById('qf-fup-notes').value = pendingFup.remarks || '';
                    }
                } else {
                    // Fallback to current date/time if no follow-up exists yet
                    const now = new Date();
                    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                    const defaultDateTime = now.toISOString().slice(0, 16);
                    if (document.getElementById('qf-scheduled-at')) {
                        document.getElementById('qf-scheduled-at').value = defaultDateTime;
                    }
                    if (document.getElementById('qf-action-type')) {
                        document.getElementById('qf-action-type').value = 'Trail Installed';
                    }
                    if (document.getElementById('qf-fup-notes')) {
                        document.getElementById('qf-fup-notes').value = '';
                    }
                }
                
                // Render Follow-up History Timeline
                const historyTimeline = document.getElementById('qf-history-timeline');
                const historyCount = document.getElementById('qf-history-count');

                if (res.followup_history && res.followup_history.length > 0) {
                    if (historyCount) historyCount.textContent = res.followup_history.length + ' entries';
                    let html = '';
                    res.followup_history.forEach(fup => {
                        let badgeColor = 'var(--primary)';
                        let badgeBg = 'var(--primary-light)';
                        if (fup.status === 'completed') {
                            badgeColor = 'var(--success)';
                            badgeBg = 'var(--success-light)';
                        } else if (fup.status === 'pending') {
                            badgeColor = 'var(--warning)';
                            badgeBg = 'var(--warning-light)';
                        } else if (fup.status === 'rescheduled') {
                            badgeColor = 'var(--info)';
                            badgeBg = 'var(--info-light)';
                        } else if (fup.status === 'missed' || fup.status === 'cancelled') {
                            badgeColor = 'var(--danger)';
                            badgeBg = 'var(--danger-light)';
                        }

                        const formattedDate = fup.scheduled_at ? new Date(fup.scheduled_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : 'N/A';
                        html += `
                            <div style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.5rem 0.75rem;">
                                <div class="flex justify-between align-center mb-1">
                                    <span class="badge text-xs font-semibold" style="--badge-bg: ${badgeBg}; --badge-color: ${badgeColor}; padding: 0.15rem 0.4rem; font-size: 0.7rem;">
                                        ${fup.action_type || 'Call'} (${fup.status})
                                    </span>
                                    <span class="text-xs text-muted font-semibold" style="font-size: 0.7rem;">${formattedDate}</span>
                                </div>
                                <p class="text-xs text-main m-0" style="word-break: break-word; font-size: 0.75rem;">${fup.remarks || 'No notes added.'}</p>
                                <div class="text-xs text-muted mt-1" style="font-size: 0.65rem;">Assigned: ${fup.assigned_to || 'System'}</div>
                            </div>
                        `;
                    });
                    if (historyTimeline) historyTimeline.innerHTML = html;
                } else {
                    if (historyCount) historyCount.textContent = '0 entries';
                    if (historyTimeline) historyTimeline.innerHTML = '<p class="text-xs text-muted" style="font-style: italic; font-size: 0.75rem;">No previous follow-up history logged yet for this lead.</p>';
                }


                
                // Open modal
                if (quickFollowupModal) {
                    quickFollowupModal.classList.remove('hidden');
                    quickFollowupModal.classList.add('open');
                    quickFollowupModal.style.display = 'flex';
                }
            } else {
                alert('Error loading lead data: ' + res.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Network error loading lead details.');
        });
    });

    // Single Dropped Lead Re-activation Handler
    document.addEventListener('click', function(e) {
        const reactivateBtn = e.target.closest('.reactivate-lead-btn');
        if (reactivateBtn) {
            e.preventDefault();
            const leadId = reactivateBtn.getAttribute('data-id');
            if (confirm('Re-activate lead #' + leadId + ' back into the Active Lead pipeline?')) {
                const formData = new FormData();
                formData.append('action', 'batch_update');
                formData.append('batch_action', 'restore');
                formData.append('lead_ids', JSON.stringify([leadId]));
                formData.append('target_value', 'new');

                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (typeof refreshDataWithoutReload === 'function') {
                            refreshDataWithoutReload(true);
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert('Failed to re-activate lead: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('An unexpected network error occurred while re-activating lead.');
                });
            }
        }
    });
    
    // Advanced Realtime Multi-Criteria Table Filtering
    function fetchLeadsPartialWithoutReload(targetUrl, pushState = true) {
        if (!targetUrl) return Promise.resolve(false);
        
        if (pushState && history.pushState) {
            history.pushState(null, '', targetUrl);
        }

        return fetch(targetUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
        })
        .then(res => {
            if (!res.ok) return null;
            return res.text();
        })
        .then(html => {
            if (!html) return false;
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            // 1. Swap table card container & pagination
            const newTableCard = doc.querySelector('#leads-table-card');
            const currentTableCard = document.querySelector('#leads-table-card');
            if (newTableCard && currentTableCard) {
                currentTableCard.innerHTML = newTableCard.innerHTML;
            }

            // 2. Swap search input clear button / state if input isn't active
            const newSearchInput = doc.querySelector('#leads-search-input');
            const currentSearchInput = document.querySelector('#leads-search-input');
            if (newSearchInput && currentSearchInput && currentSearchInput !== document.activeElement) {
                currentSearchInput.value = newSearchInput.value;
            }

            // 3. Sync count badges
            const cntSpans = document.querySelectorAll('[id^="cnt-"]');
            cntSpans.forEach(span => {
                const newSpan = doc.querySelector('#' + CSS.escape(span.id));
                if (newSpan) {
                    span.innerHTML = newSpan.innerHTML;
                }
            });

            // 4. Re-initialize Lucide Icons & Column preferences
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
            if (typeof loadDirColumnPreferences === 'function') {
                loadDirColumnPreferences();
            }

            applyAdvancedFilters(false);
            return true;
        })
        .catch(err => {
            console.error('AJAX partial update failed:', err);
            return false;
        });
    }

    window.fetchLeadsPartialWithoutReload = fetchLeadsPartialWithoutReload;

    function applyAdvancedFilters(triggerServer = false) {
        const searchVal = (document.getElementById('leads-search-input')?.value || '').toLowerCase().trim();
        const sourceVal = (document.getElementById('filter-source')?.value || '').toLowerCase().trim();
        const priorityVal = (document.getElementById('filter-priority')?.value || '').toLowerCase().trim();
        const statusVal = (document.getElementById('filter-status')?.value || '').toLowerCase().trim();
        const assignedVal = (document.getElementById('filter-assigned')?.value || '').toLowerCase().trim();

        if (triggerServer) {
            const url = new URL(window.location.href);
            if (sourceVal) url.searchParams.set('source', sourceVal); else url.searchParams.delete('source');
            if (priorityVal) url.searchParams.set('priority', priorityVal); else url.searchParams.delete('priority');
            if (statusVal) url.searchParams.set('status', statusVal); else url.searchParams.delete('status');
            if (assignedVal) url.searchParams.set('assigned_to', assignedVal); else url.searchParams.delete('assigned_to');
            url.searchParams.set('p', '1');
            fetchLeadsPartialWithoutReload(url.toString(), true);
            return;
        }

        const rows = document.querySelectorAll('table.table tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const rSource = (row.getAttribute('data-source') || '').toLowerCase();
            const rPriority = (row.getAttribute('data-priority') || '').toLowerCase();
            const rStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const rAssigned = (row.getAttribute('data-assigned') || '').toLowerCase();

            const matchSearch = !searchVal || text.includes(searchVal);
            const matchSource = !sourceVal || rSource.includes(sourceVal) || text.includes(sourceVal);
            const matchPriority = !priorityVal || rPriority === priorityVal;
            const matchAssigned = !assignedVal || rAssigned.includes(assignedVal);

            let matchStatus = false;
            if (statusVal === 'dropped') {
                matchStatus = (rStatus === 'dropped');
            } else if (statusVal === '') {
                matchStatus = (rStatus !== 'dropped');
            } else {
                matchStatus = (rStatus === statusVal);
            }

            if (matchSearch && matchSource && matchPriority && matchStatus && matchAssigned) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function resetFilters() {
        fetchLeadsPartialWithoutReload('index.php?page=leads', true);
    }

    const searchInput = document.getElementById('leads-search-input');
    let searchDebounceTimer = null;

    function triggerGlobalSearch(val) {
        const url = new URL(window.location.href);
        const currentSearch = (url.searchParams.get('search') || url.searchParams.get('q') || '').trim();
        const trimmed = (val || '').trim();

        if (trimmed === currentSearch) return;

        if (trimmed) {
            url.searchParams.set('search', trimmed);
        } else {
            url.searchParams.delete('search');
            url.searchParams.delete('q');
        }
        url.searchParams.set('p', '1');
        fetchLeadsPartialWithoutReload(url.toString(), true);
    }

    function clearLeadsSearch() {
        const url = new URL(window.location.href);
        url.searchParams.delete('search');
        url.searchParams.delete('q');
        url.searchParams.set('p', '1');
        if (searchInput) searchInput.value = '';
        fetchLeadsPartialWithoutReload(url.toString(), true);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            applyAdvancedFilters(false);
            
            clearTimeout(searchDebounceTimer);
            const query = this.value;
            searchDebounceTimer = setTimeout(() => {
                triggerGlobalSearch(query);
            }, 500);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchDebounceTimer);
                triggerGlobalSearch(this.value);
            }
        });
    }

    // Event delegation for pagination & metric card links to fetch via AJAX without page reload
    document.addEventListener('click', function(e) {
        const targetLink = e.target.closest('#leads-table-card a.btn, .live-metric-cards-container a');
        if (targetLink && targetLink.getAttribute('href')) {
            const href = targetLink.getAttribute('href');
            if (href.startsWith('index.php?page=leads') || href.startsWith('?page=leads')) {
                e.preventDefault();
                fetchLeadsPartialWithoutReload(href, true);
            }
        }
    });

    window.addEventListener('popstate', function() {
        fetchLeadsPartialWithoutReload(window.location.href, false);
    });

    // Apply default filtering immediately on load to hide dropped leads
    applyAdvancedFilters();

    // AJAX submit handler
    const qfForm = document.getElementById('quick-followup-form');
    if (qfForm) {
        qfForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('index.php?page=leads', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid response: ' + text.substring(0, 150));
                }
            })
            .then(data => {
                if (data.success) {
                    closeQuickFollowupModal();
                    if (typeof refreshDataWithoutReload === 'function') {
                        refreshDataWithoutReload(true);
                    } else if (typeof fetchLeadsPartialWithoutReload === 'function') {
                        fetchLeadsPartialWithoutReload(window.location.href, false);
                    } else {
                        window.location.reload();
                    }
                } else {
                    alert('Failed to save details: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Save error: ' + err.message);
            });
        });
    }

    function closeQuickFollowupModal() {
        const quickFollowupModal = document.getElementById('quick-followup-modal');
        if (quickFollowupModal) {
            quickFollowupModal.classList.add('hidden');
            quickFollowupModal.classList.remove('open');
            quickFollowupModal.style.display = 'none';
        }
    }

    function handleBatchActionChange() {
        const action = document.getElementById('batch-action-select').value;
        const empWrapper = document.getElementById('batch-employee-wrapper');
        const statusSelect = document.getElementById('batch-status-select');
        const prioritySelect = document.getElementById('batch-priority-select');

        if (empWrapper) empWrapper.classList.add('hidden');
        if (statusSelect) statusSelect.classList.add('hidden');
        if (prioritySelect) prioritySelect.classList.add('hidden');

        if (action === 'assign' && empWrapper) {
            empWrapper.classList.remove('hidden');
        } else if ((action === 'status' || action === 'restore') && statusSelect) {
            statusSelect.classList.remove('hidden');
        } else if (action === 'priority' && prioritySelect) {
            prioritySelect.classList.remove('hidden');
        }
    }

    function toggleBatchEmpDropdown() {
        const menu = document.getElementById('batch-emp-dropdown-menu');
        if (menu) {
            menu.classList.toggle('hidden');
        }
    }

    document.addEventListener('click', function(e) {
        const container = e.target.closest('.batch-emp-dropdown-container');
        if (!container) {
            const menu = document.getElementById('batch-emp-dropdown-menu');
            if (menu) menu.classList.add('hidden');
        }
    });

    function toggleSelectAllBatchEmp() {
        const cbs = document.querySelectorAll('.batch-emp-cb');
        const allChecked = Array.from(cbs).every(cb => cb.checked);
        cbs.forEach(cb => cb.checked = !allChecked);
        updateBatchEmpBtnText();
    }

    function updateBatchEmpBtnText() {
        const selected = Array.from(document.querySelectorAll('.batch-emp-cb:checked')).map(cb => cb.value);
        const btnText = document.getElementById('batch-emp-btn-text');
        if (!btnText) return;
        if (selected.length === 0) {
            btnText.textContent = '-- Select Employee(s) --';
        } else if (selected.length === 1) {
            btnText.textContent = selected[0];
        } else {
            btnText.textContent = selected.length + ' Employees Selected';
        }
    }

    function executeBatchAction() {
        const action = document.getElementById('batch-action-select').value;
        const selected = Array.from(document.querySelectorAll('.lead-checkbox:checked')).map(cb => cb.value);
        
        if (selected.length === 0) {
            alert('Please select at least one lead from the checklist using the checkboxes.');
            return;
        }
        
        if (!action) {
            alert('Please select a valid batch action command from the dropdown.');
            return;
        }

        let targetValue = '';
        if (action === 'assign') {
            const selectedEmps = Array.from(document.querySelectorAll('.batch-emp-cb:checked')).map(cb => cb.value.trim());
            if (selectedEmps.length === 0) {
                alert('Please select at least one employee from the multi-select dropdown to assign the selected ' + selected.length + ' lead(s).');
                return;
            }
            targetValue = selectedEmps.join(', ');
        } else if (action === 'status' || action === 'restore') {
            const statusSelect = document.getElementById('batch-status-select');
            targetValue = statusSelect ? statusSelect.value : '';
            if (!targetValue) {
                alert('Please select a target status stage for the selected lead(s).');
                return;
            }
        } else if (action === 'priority') {
            const prioritySelect = document.getElementById('batch-priority-select');
            targetValue = prioritySelect ? prioritySelect.value : '';
            if (!targetValue) {
                alert('Please select a priority level for the selected lead(s).');
                return;
            }
        } else if (action === 'drop') {
            if (!confirm('Are you sure you want to mark ' + selected.length + ' lead(s) as DROPPED?\n\nNote: Leads are NEVER deleted from the database. Dropped leads can be fetched in reports anytime.')) {
                return;
            }
        }

        const formData = new FormData();
        formData.append('action', 'batch_update');
        formData.append('batch_action', action);
        formData.append('lead_ids', JSON.stringify(selected));
        formData.append('target_value', targetValue);

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (typeof refreshDataWithoutReload === 'function') {
                    refreshDataWithoutReload(true);
                } else {
                    window.location.reload();
                }
            } else {
                alert('Batch Action Failed: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('An unexpected network error occurred while applying batch action.');
        });
    }

    function resetFilters() {
        const selects = document.querySelectorAll('#advanced-filter-drawer select');
        selects.forEach(select => select.selectedIndex = 0);
        if (searchInput) searchInput.value = '';
        applyAdvancedFilters();
    }

    // Dynamic Select Columns Drawer Functions
    function toggleColumnCheckbox(checkboxId) {
        const cb = document.getElementById(checkboxId);
        if (cb) {
            cb.checked = !cb.checked;
            toggleColumnByCheckbox(cb);
        }
    }

    function toggleColumnByCheckbox(cb) {
        const targetClass = cb.getAttribute('data-target');
        const isChecked = cb.checked;
        const cells = document.querySelectorAll('.' + targetClass);
        cells.forEach(cell => {
            if (isChecked) {
                cell.style.setProperty('display', 'table-cell', 'important');
            } else {
                cell.style.setProperty('display', 'none', 'important');
            }
        });
    }

    function toggleColumnSelectorDrawer() {
        const modal = document.getElementById('column-selector-modal');
        if (modal) {
            // Sync checkbox state with current visible columns
            const checkboxes = document.querySelectorAll('.col-toggle-cb');
            checkboxes.forEach(cb => {
                const targetClass = cb.getAttribute('data-target');
                const th = document.querySelector('th.' + targetClass);
                if (th) {
                    cb.checked = (window.getComputedStyle(th).display !== 'none');
                }
            });

            modal.classList.remove('hidden');
            modal.classList.add('open');
            modal.style.display = 'flex';
        }
    }

    function closeColumnSelectorDrawer() {
        const modal = document.getElementById('column-selector-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('open');
            modal.style.display = 'none';
        }
    }

    function setAllColumnCheckboxes(selectState) {
        const checkboxes = document.querySelectorAll('.col-toggle-cb');
        checkboxes.forEach(cb => {
            cb.checked = selectState;
            toggleColumnByCheckbox(cb);
        });
    }

    function applyColumnPreferences() {
        const checkboxes = document.querySelectorAll('.col-toggle-cb');
        const prefs = {};

        checkboxes.forEach(cb => {
            const targetClass = cb.getAttribute('data-target');
            const isChecked = cb.checked;
            prefs[targetClass] = isChecked;
            toggleColumnByCheckbox(cb);
        });

        localStorage.setItem('lead_table_column_prefs', JSON.stringify(prefs));
        closeColumnSelectorDrawer();
    }

    function loadColumnPreferences() {
        const saved = localStorage.getItem('lead_table_column_prefs');
        const checkboxes = document.querySelectorAll('.col-toggle-cb');

        let prefs = {};
        if (saved) {
            try {
                prefs = JSON.parse(saved);
            } catch(e) {}
        }

        checkboxes.forEach(cb => {
            const targetClass = cb.getAttribute('data-target');
            let isChecked = cb.checked;
            if (saved && prefs.hasOwnProperty(targetClass)) {
                isChecked = prefs[targetClass];
                cb.checked = isChecked;
            }
            toggleColumnByCheckbox(cb);
        });
    }

    // Load saved user column visibility preferences on initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadColumnPreferences);
    } else {
        loadColumnPreferences();
    }
</script>

<!-- Modal: Enlarged Scan-to-Call QR Code -->
<div id="call-qr-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 380px; text-align: center;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.1rem;" id="qr-modal-title">Scan to Call Client</h3>
            <button class="btn-icon" onclick="window.closeModal('call-qr-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <div class="modal-body flex flex-col align-center p-6 gap-4">
            <div style="background: #ffffff; padding: 16px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                <img id="qr-modal-img" src="" alt="Enlarged QR Code" style="width: 220px; height: 220px; border-radius: 8px; display: block;">
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-base font-bold" id="qr-modal-phone" style="color: var(--primary);"></span>
                <span class="text-xs text-muted">Point your smartphone camera at this QR code to load the phone number directly into your mobile dial pad.</span>
            </div>
        </div>
        <div class="modal-footer flex justify-between align-center p-4" style="background: var(--border-card);">
            <a id="qr-modal-tel-link" href="#" class="btn btn-primary text-xs flex align-center justify-center gap-2" style="width: 100%;">
                <i data-lucide="phone-call" style="width: 14px; height: 14px;"></i>
                <span>Direct Call Now</span>
            </a>
        </div>
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

function openCallQrModal(name, phone, telEncoded) {
    const modal = document.getElementById('call-qr-modal');
    if (!modal) return;
    
    const formattedPhone = normalizePhoneForDialing(phone);
    const telPayload = 'tel:' + formattedPhone;
    const encodedPayload = encodeURIComponent(telPayload);

    document.getElementById('qr-modal-title').textContent = 'Call ' + (name || 'Lead');
    document.getElementById('qr-modal-phone').textContent = formattedPhone || '-';
    document.getElementById('qr-modal-tel-link').href = telPayload;
    
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=4&data=' + encodedPayload;
    const fallbackUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=260x260&chl=' + encodedPayload;
    const qrImg = document.getElementById('qr-modal-img');
    qrImg.onerror = function() {
        this.onerror = null;
        this.src = fallbackUrl;
    };
    qrImg.src = qrUrl;
    
    window.openModal('call-qr-modal');
}
</script>
