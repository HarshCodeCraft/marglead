<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$fup_leads = [];
$operators = [
    'AJAY RATHOUR', 'HARSH SAINI', 'MARG SOFT SOLUTION', 'MOIN KHAN', 
    'NAITIK CHAURASIA', 'POORNIMA BAJPAI', 'SAHIL KUMAR', 'VANDANA YADAV'
];

if ($db_connected && $pdo) {
    try {
        $fup_leads = $pdo->query("SELECT id, name, company FROM leads ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $db_ops = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($db_ops)) {
            $operators = $db_ops;
        }
    } catch (PDOException $e) {}
}
// No mock fallback for leads

// Dynamic Month, Year, and Today setup
$cur_day = intval(date('j'));
$cur_month = intval(date('n'));
$cur_year = intval(date('Y'));
$today_str = date('Y-m-d');

// Read selected month and year from URL, default to actual current month & year
$m = isset($_GET['m']) ? intval($_GET['m']) : $cur_month;
$y = isset($_GET['y']) ? intval($_GET['y']) : $cur_year;

if ($m < 1) { $m = 12; $y--; }
if ($m > 12) { $m = 1; $y++; }

// Compute dynamic month calendar grid variables
$first_day_timestamp = mktime(0, 0, 0, $m, 1, $y);
$days_in_month = intval(date('t', $first_day_timestamp));
$start_weekday = intval(date('w', $first_day_timestamp)); // 0: Sun, 1: Mon, ..., 6: Sat
$total_cells = 42; // 6 rows * 7 columns grid
$month_title = date('F Y', $first_day_timestamp);

// Previous month days calculation for trailing grid cells
$prev_m = ($m == 1) ? 12 : ($m - 1);
$prev_y = ($m == 1) ? ($y - 1) : $y;
$prev_month_days = intval(date('t', mktime(0, 0, 0, $prev_m, 1, $prev_y)));

// Next month navigation links
$next_m = ($m == 12) ? 1 : ($m + 1);
$next_y = ($m == 12) ? ($y + 1) : $y;

$prev_month_url = "index.php?page=followups&m={$prev_m}&y={$prev_y}";
$next_month_url = "index.php?page=followups&m={$next_m}&y={$next_y}";
$today_month_url = "index.php?page=followups";

$calendar_events = [];
$today_agenda = [];

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

if ($db_connected && $pdo) {
    try {
        if ($is_admin) {
            $stmtFup = $pdo->query("SELECT f.*, l.name as lead_name, l.company as lead_company, l.priority as lead_priority, l.phone as lead_phone, l.email as lead_email FROM followups f JOIN leads l ON f.lead_id = l.id WHERE f.status != 'rescheduled' ORDER BY f.scheduled_at ASC");
        } else {
            $stmtFup = $pdo->prepare("SELECT f.*, l.name as lead_name, l.company as lead_company, l.priority as lead_priority, l.phone as lead_phone, l.email as lead_email FROM followups f JOIN leads l ON f.lead_id = l.id WHERE f.status != 'rescheduled' AND (LOWER(TRIM(f.assigned_to)) = LOWER(TRIM(?)) OR LOWER(TRIM(l.assigned_to)) = LOWER(TRIM(?))) ORDER BY f.scheduled_at ASC");
            $stmtFup->execute([$user_name, $user_name]);
        }
        $db_fups = $stmtFup->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($db_fups as $f) {
            $scheduled = strtotime($f['scheduled_at']);
            $f_month = intval(date('n', $scheduled));
            $f_year = intval(date('Y', $scheduled));
            $f_day = intval(date('j', $scheduled));
            $f_date_str = date('Y-m-d', $scheduled);
            
            $class = 'cold';
            if ($f['status'] === 'completed') {
                $class = 'completed';
            } elseif ($f['status'] === 'missed' || ($scheduled < time() && $f['status'] === 'pending')) {
                $class = 'hot';
            } elseif ($f['lead_priority'] === 'hot') {
                $class = 'hot';
            } elseif ($f['lead_priority'] === 'warm') {
                $class = 'warm';
            }

            $event_data = [
                'id' => $f['id'],
                'lead_id' => $f['lead_id'],
                'lead_name' => $f['lead_name'],
                'lead_company' => $f['lead_company'] ?: $f['lead_name'],
                'lead_phone' => $f['lead_phone'] ?? '',
                'lead_email' => $f['lead_email'] ?? '',
                'lead_priority' => ucfirst($f['lead_priority'] ?? 'Warm'),
                'priority' => ucfirst($f['lead_priority'] ?? 'Warm'),
                'action_type' => $f['action_type'],
                'scheduled_at' => $f['scheduled_at'],
                'date_formatted' => date('D, d M Y', $scheduled),
                'time_formatted' => date('h:i A', $scheduled),
                'time' => date('h:i A', $scheduled),
                'title' => ($f['lead_company'] ?: $f['lead_name']) . " (" . $f['action_type'] . ")",
                'company' => $f['lead_company'] ?: $f['lead_name'],
                'remarks' => $f['remarks'] ?: "Follow-up " . $f['action_type'],
                'assigned_to' => $f['assigned_to'],
                'status' => $f['status'],
                'class' => $class,
                'priority_class' => ($f['lead_priority'] === 'hot' || $f['status'] === 'missed') ? 'danger' : (($f['lead_priority'] === 'warm') ? 'warning' : 'info')
            ];

            if ($f_month === $m && $f_year === $y) {
                if (!isset($calendar_events[$f_day])) {
                    $calendar_events[$f_day] = [];
                }
                $calendar_events[$f_day][] = $event_data;
            }
            
            if ($f_date_str === $today_str) {
                $today_agenda[] = $event_data;
            }
        }
    } catch (PDOException $e) {}
}

// Dates setup for Today, Tomorrow, Next Day summary metric cards
$today_str = date('Y-m-d');
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));
$nextday_str = date('Y-m-d', strtotime('+2 days'));

// Fetch live metrics dynamically
$liveMetrics = getLiveMetricCounts($pdo, $is_admin, $user_name);
$expired_counts = $liveMetrics['expired'];
$demo_counts = $liveMetrics['demo'];
$callback_counts = $liveMetrics['callback'];

// Active URL filter params handling
$active_filter = $_GET['filter'] ?? '';
$active_day = $_GET['day'] ?? '';
$filter_label = '';

if (!empty($active_filter) && !empty($active_day)) {
    $target_date = $today_str;
    $day_name = 'Today';
    if ($active_day === 'tomorrow') {
        $target_date = $tomorrow_str;
        $day_name = 'Tomorrow';
    } elseif ($active_day === 'next_day') {
        $target_date = $nextday_str;
        $day_name = 'Next Day';
    } elseif ($active_day === 'all' || $active_day === 'total') {
        $day_name = 'All Total';
    }

    $filter_title = '';
    if ($active_filter === 'demo_scheduled') {
        $filter_title = 'Demo Scheduled';
    } elseif ($active_filter === 'expired') {
        $filter_title = 'Upcoming Expired Lead';
    } elseif ($active_filter === 'callback') {
        $filter_title = 'Call Back';
    }

    $filter_label = $filter_title . " ($day_name)";

    // Apply active filter to calendar events
    $filtered_events = [];
    foreach ($calendar_events as $day => $events) {
        $matching = array_filter($events, function($ev) use ($active_filter, $active_day, $target_date) {
            $ev_date = date('Y-m-d', strtotime($ev['scheduled_at']));
            if ($active_filter === 'demo_scheduled') {
                if ($active_day === 'all' || $active_day === 'total') return (stripos($ev['action_type'], 'Demo') !== false || stripos($ev['action_type'], 'Trail') !== false || stripos($ev['action_type'], 'Trial') !== false);
                return ($ev_date === $target_date && (stripos($ev['action_type'], 'Demo') !== false || stripos($ev['action_type'], 'Trail') !== false || stripos($ev['action_type'], 'Trial') !== false));
            } elseif ($active_filter === 'expired') {
                if ($active_day === 'all' || $active_day === 'total') return ($ev['status'] === 'missed' || strtotime($ev['scheduled_at']) < time() || stripos($ev['action_type'], 'Expiry') !== false || stripos($ev['action_type'], 'Renewal') !== false);
                return ($ev_date === $target_date && ($ev['status'] === 'missed' || strtotime($ev['scheduled_at']) < time() || stripos($ev['action_type'], 'Expiry') !== false || stripos($ev['action_type'], 'Renewal') !== false));
            } elseif ($active_filter === 'callback') {
                if ($active_day === 'all' || $active_day === 'total') return true;
                return ($ev_date === $target_date);
            }
            return true;
        });
        if (!empty($matching)) {
            $filtered_events[$day] = array_values($matching);
        }
    }
    $calendar_events = $filtered_events;
}

// Helper functions for card button URLs & active styling
if (!function_exists('getFilterUrl')) {
    function getFilterUrl($filter_name, $day_name, $current_filter, $current_day) {
        if ($current_filter === $filter_name && $current_day === $day_name) {
            return 'index.php?page=followups'; // Toggle off
        }
        return 'index.php?page=followups&filter=' . urlencode($filter_name) . '&day=' . urlencode($day_name);
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
?>

<div class="followups-planner-container">
    <!-- Header Controls -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Follow-up Calendar Planner</h2>
            <p class="text-muted text-sm">Schedule phone calls, set demo dates, track renewal reminders, and review pending customer engagements.</p>
        </div>
        <button class="btn btn-primary text-sm" onclick="window.openModal('schedule-followup-modal')">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Schedule Follow-up</span>
        </button>
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
                    Total: <span id="cnt-fup-expired-total"><?php echo $expired_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('expired', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Today">
                    <span id="cnt-fup-expired-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Tomorrow">
                    <span id="cnt-fup-expired-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Next Day">
                    <span id="cnt-fup-expired-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
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
                    Total: <span id="cnt-fup-demo-total"><?php echo $demo_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Today">
                    <span id="cnt-fup-demo-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Tomorrow">
                    <span id="cnt-fup-demo-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Next Day">
                    <span id="cnt-fup-demo-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
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
                    Total: <span id="cnt-fup-callback-total"><?php echo $callback_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('callback', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Today">
                    <span id="cnt-fup-callback-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Tomorrow">
                    <span id="cnt-fup-callback-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Next Day">
                    <span id="cnt-fup-callback-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['next_day']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Next Day</span>
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($filter_label)): ?>
        <div class="mb-4 p-3 flex justify-between align-center" style="background: rgba(0, 77, 64, 0.08); border: 1.5px dashed var(--primary); border-radius: var(--border-radius-sm); color: var(--primary);">
            <span class="text-sm font-semibold flex align-center gap-2">
                <i data-lucide="filter" style="width: 16px; height: 16px;"></i>
                <span>Showing list filtered by: <strong><?php echo htmlspecialchars($filter_label); ?></strong></span>
            </span>
            <a href="index.php?page=followups" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 0.35rem 0.85rem; background: var(--bg-card);">
                <i data-lucide="x" style="width: 12px; height: 12px;"></i>
                <span>Clear Filter</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Calendar Layout: Double Column Grid (List panel + Calendar Grid) -->
    <div class="grid" style="grid-template-columns: 280px minmax(0, 1fr); gap: 1.5rem; align-items: start;">
        
        <!-- Left: Action Lists Column -->
        <div class="flex flex-col gap-4">
            <!-- Dynamic Filters -->
            <div class="card p-4 flex flex-col gap-2" style="border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold mb-2" style="text-transform: uppercase; letter-spacing: 0.05em;">Planner Filters</h4>
                <button class="btn btn-primary active calendar-filter-btn text-xs" data-filter="all" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="layers" style="width: 14px; height: 14px;"></i>
                    <span>All Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="hot" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="alert-circle" style="width: 14px; height: 14px; color: var(--danger);"></i>
                    <span>Missed Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="warm" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="clock" style="width: 14px; height: 14px; color: var(--warning);"></i>
                    <span>Today's Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="completed" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="check-circle-2" style="width: 14px; height: 14px; color: var(--success);"></i>
                    <span>Completed Actions</span>
                </button>
            </div>

            <!-- Today's Follow-ups Panel -->
            <div class="card p-4" style="border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold mb-3" style="text-transform: uppercase; letter-spacing: 0.05em;">Today's Agenda</h4>
                <div class="flex flex-col gap-3">
                    <?php if (empty($today_agenda)): ?>
                        <div class="text-center py-4 text-xs text-muted">
                            No follow-ups scheduled for today.
                        </div>
                    <?php else: ?>
                        <?php foreach ($today_agenda as $item): 
                            $jsonItem = htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="agenda-item agenda-item-clickable p-3 pointer" data-fup-json='<?php echo $jsonItem; ?>' style="background-color: var(--<?php echo $item['priority_class'] ?? 'info'; ?>-light); border-left: 3px solid var(--<?php echo $item['priority_class'] ?? 'info'; ?>); border-radius: var(--border-radius-sm); margin-bottom: 0.5rem; cursor: pointer; transition: transform 0.15s ease;" title="Click to view follow-up details">
                                <div class="flex justify-between align-center mb-1">
                                    <span class="text-xs font-semibold"><?php echo htmlspecialchars($item['time'] ?? ''); ?></span>
                                    <span class="badge text-xs" style="--badge-bg: var(--<?php echo $item['priority_class'] ?? 'info'; ?>-light); --badge-color: var(--<?php echo $item['priority_class'] ?? 'info'; ?>);"><?php echo htmlspecialchars($item['priority'] ?? $item['lead_priority'] ?? 'Warm'); ?></span>
                                </div>
                                <h5 class="text-sm font-semibold mb-1"><?php echo htmlspecialchars($item['company'] ?? ''); ?></h5>
                                <p class="text-xs text-muted"><?php echo htmlspecialchars($item['remarks'] ?? ''); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Month Calendar Grid -->
        <div class="calendar-container">
            <!-- Calendar Header controls -->
            <div class="calendar-header-row">
                <h3 class="calendar-month-title"><?php echo htmlspecialchars($month_title); ?></h3>
                <div class="flex align-center gap-1">
                    <a class="btn btn-icon btn-sm" href="<?php echo $prev_month_url; ?>" title="Previous Month"><i data-lucide="chevron-left" style="width: 16px; height: 16px;"></i></a>
                    <a class="btn btn-secondary text-xs" href="<?php echo $today_month_url; ?>" style="padding: 0.3rem 0.65rem;" title="Go to Current Month / Today">Today</a>
                    <a class="btn btn-icon btn-sm" href="<?php echo $next_month_url; ?>" title="Next Month"><i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i></a>
                </div>
            </div>

            <!-- Days of Week Header row -->
            <div class="calendar-weekdays-grid">
                <div class="weekday-cell">Sun</div>
                <div class="weekday-cell">Mon</div>
                <div class="weekday-cell">Tue</div>
                <div class="weekday-cell">Wed</div>
                <div class="weekday-cell">Thu</div>
                <div class="weekday-cell">Fri</div>
                <div class="weekday-cell">Sat</div>
            </div>

            <!-- 42 Days Matrix Grid -->
            <div class="calendar-days-grid">
                <?php
                // 1. Previous month trailing cells
                $trail_count = $start_weekday;
                for ($t = $trail_count - 1; $t >= 0; $t--) {
                    $day_num = $prev_month_days - $t;
                    echo '<div class="day-cell inactive">
                            <span class="day-num">' . $day_num . '</span>
                          </div>';
                }

                // 2. Current Month active cells
                for ($d = 1; $d <= $days_in_month; $d++) {
                    $is_current = ($d === $cur_day && $m === $cur_month && $y === $cur_year) ? 'current-day' : '';
                    echo '<div class="day-cell ' . $is_current . '">
                            <span class="day-num">' . $d . '</span>';
                    
                    // Render events mapping to day cell
                    if (isset($calendar_events[$d])) {
                        foreach ($calendar_events[$d] as $ev) {
                            $jsonEv = htmlspecialchars(json_encode($ev), ENT_QUOTES, 'UTF-8');
                            echo '<div class="calendar-event ' . $ev['class'] . ' pointer" title="' . htmlspecialchars($ev['title']) . '" data-fup-json=\'' . $jsonEv . '\' style="cursor: pointer;">
                                    ' . htmlspecialchars($ev['time'] . ' ' . $ev['title']) . '
                                  </div>';
                        }
                    }
                    
                    echo '</div>';
                }

                // 3. Next month trailing cells
                $rendered = $start_weekday + $days_in_month;
                $remaining = $total_cells - $rendered;
                for ($n = 1; $n <= $remaining; $n++) {
                    echo '<div class="day-cell inactive">
                            <span class="day-num">' . $n . '</span>
                          </div>';
                }
                ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Schedule Follow-up (Reused across planner) -->
<div id="schedule-followup-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Schedule Follow-up reminder</h3>
            <button class="btn-icon" onclick="window.closeModal('schedule-followup-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?action=schedule_followup" method="POST">
            <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'System'); ?>">
            <div class="form-group m-0">
                <label class="form-label text-xs">Date & Time</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="<?php echo date('Y-m-d\TH:i'); ?>">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Action Type</label>
                <select name="action_type" class="form-control">
                    <option value="Trail Installed">Trail Installed</option>
                    <option value="Data Input Follow Up">Data Input Follow Up</option>
                    <option value="Payment Followup">Payment Followup</option>
                    <option value="Rest Amt Followup">Rest Amt Followup</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Target Customer Lead</label>
                <select name="lead_id" class="form-control" required>
                    <?php foreach ($fup_leads as $ld): ?>
                        <option value="<?php echo htmlspecialchars($ld['id']); ?>">
                            <?php echo htmlspecialchars($ld['company'] . ' (' . $ld['name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Reminder notes / Instructions</label>
                <textarea name="remarks" class="form-control" rows="3" placeholder="Input specific goals..."></textarea>
            </div>
            <div class="flex gap-4 mt-2">
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_email" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Email Notification</span>
                </label>
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_sms" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Free Carrier SMS</span>
                </label>
            </div>
            
            <div class="form-group mt-2">
                <label class="form-label text-xs">SMS Notification Targets</label>
                <div class="flex flex-col gap-2" style="background: var(--border-card); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" name="sms_targets[]" value="client" checked style="width: 14px; height: 14px;">
                        <span>Client Phone:</span>
                        <input type="text" name="sms_client_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="9876543210" placeholder="Client Phone">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="employee" checked style="width: 14px; height: 14px;">
                        <span>Employee Phone:</span>
                        <input type="text" name="sms_employee_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="9012345678" placeholder="Employee Phone">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="admin" checked style="width: 14px; height: 14px;">
                        <span>Admin Phone:</span>
                        <input type="text" name="sms_admin_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="7860510928">
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('schedule-followup-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Reminder</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View Particular Follow-up Details -->
<div id="view-followup-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <div class="flex align-center gap-3">
                <div id="v-fup-icon-box" style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: var(--border-radius-sm); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="calendar-clock" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.1rem; font-weight: 700;" id="v-fup-title">Follow-up Details</h3>
                    <p class="text-xs text-muted m-0" id="v-fup-subtitle">Scheduled Reminder Overview</p>
                </div>
            </div>
            <div class="flex align-center gap-2">
                <span id="v-fup-status-badge" class="badge text-xs" style="padding: 0.35rem 0.75rem; font-weight: 700; text-transform: uppercase;">PENDING</span>
                <button type="button" class="btn-icon" onclick="window.closeModal('view-followup-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
            </div>
        </div>

        <div class="modal-body flex flex-col gap-4">
            <!-- Customer / Lead Box -->
            <div style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 1rem;">
                <div class="flex justify-between align-start mb-2">
                    <div>
                        <span class="text-xs text-muted font-semibold block mb-1">TARGET CUSTOMER / LEAD</span>
                        <h4 class="font-bold text-base m-0 text-main" id="v-fup-company">Company Name</h4>
                        <p class="text-xs text-muted m-0" id="v-fup-contact-person">Contact: -</p>
                    </div>
                    <a id="v-fup-lead-link" href="#" class="btn btn-secondary text-xs" style="padding: 0.35rem 0.75rem;">
                        <i data-lucide="external-link" style="width: 13px; height: 13px;"></i> View Lead File
                    </a>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px dashed var(--border-color);">
                    <div>
                        <span class="text-xs text-muted block mb-1 font-semibold">Contact Phone:</span>
                        <div class="flex align-center gap-2">
                            <strong class="text-xs text-main" id="v-fup-phone">-</strong>
                            <a id="v-fup-wa-btn" href="#" target="_blank" class="btn text-xs" style="padding: 2px 6px; background: #25D366; color: #fff; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;" title="Chat on WhatsApp">
                                <i data-lucide="message-square" style="width: 12px; height: 12px;"></i> WA
                            </a>
                        </div>
                    </div>
                    <div>
                        <span class="text-xs text-muted block mb-1 font-semibold">Lead Priority & ID:</span>
                        <div class="flex align-center gap-2">
                            <span id="v-fup-priority-badge" class="badge text-xs" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">WARM</span>
                            <span class="text-xs font-mono font-bold text-muted" id="v-fup-lead-id">LD-0000</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule & Details Grid -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                <div style="background: var(--bg-card); padding: 0.75rem; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                    <label class="text-xs text-muted block mb-1 font-semibold">Scheduled Date & Time</label>
                    <div class="flex align-center gap-2 text-xs font-bold text-main" id="v-fup-datetime">
                        <i data-lucide="clock" style="width: 14px; height: 14px; color: var(--primary);"></i>
                        <span>22 July 2026, 10:00 AM</span>
                    </div>
                </div>
                
                <div style="background: var(--bg-card); padding: 0.75rem; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                    <label class="text-xs text-muted block mb-1 font-semibold">Action Type & Executive</label>
                    <div class="flex align-center gap-2 text-xs font-bold text-main">
                        <span id="v-fup-action-type" style="color: var(--primary);">Phone Call</span>
                        <span class="text-xs text-muted" id="v-fup-assigned">(SAHIL KUMAR)</span>
                    </div>
                </div>
            </div>

            <!-- Remarks Box -->
            <div>
                <label class="text-xs text-muted font-semibold mb-1 block">Follow-up Remarks / Instructions</label>
                <div id="v-fup-remarks" class="text-xs text-main p-3" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); min-height: 54px; white-space: pre-wrap; line-height: 1.5;">
                    Follow-up notes...
                </div>
            </div>
        </div>

        <div class="modal-footer justify-between">
            <button type="button" id="v-fup-complete-btn" class="btn btn-primary text-xs" style="padding: 0.5rem 1rem; background-color: var(--success); border-color: var(--success);">
                <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i> Mark Completed
            </button>
            
            <div class="flex align-center gap-2">
                <button type="button" id="v-fup-reschedule-btn" class="btn btn-secondary text-xs" style="padding: 0.5rem 1rem;">
                    <i data-lucide="calendar" style="width: 14px; height: 14px;"></i> Reschedule
                </button>
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('view-followup-modal')" style="padding: 0.5rem 1rem;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.openViewFollowupModal = function(data) {
        if (!data) return;
        
        const titleEl = document.getElementById('v-fup-title');
        if (titleEl) titleEl.textContent = `Follow-up #${data.id || 'N/A'}`;
        
        const companyEl = document.getElementById('v-fup-company');
        if (companyEl) companyEl.textContent = data.company || data.lead_name || 'Lead';
        
        const contactEl = document.getElementById('v-fup-contact-person');
        if (contactEl) contactEl.textContent = `Contact Person: ${data.lead_name || 'N/A'}`;

        const leadLinkEl = document.getElementById('v-fup-lead-link');
        if (leadLinkEl) leadLinkEl.href = `index.php?page=leads&search=${encodeURIComponent(data.lead_id || data.company)}`;

        const phoneEl = document.getElementById('v-fup-phone');
        if (phoneEl) phoneEl.textContent = data.lead_phone || 'No phone provided';

        const waBtn = document.getElementById('v-fup-wa-btn');
        if (waBtn) {
            if (data.lead_phone) {
                const cleanP = data.lead_phone.replace(/[^0-9]/g, '');
                waBtn.href = `https://wa.me/${cleanP}`;
                waBtn.style.display = 'inline-flex';
            } else {
                waBtn.style.display = 'none';
            }
        }

        const leadIdEl = document.getElementById('v-fup-lead-id');
        if (leadIdEl) leadIdEl.textContent = data.lead_id || '';

        const prioEl = document.getElementById('v-fup-priority-badge');
        if (prioEl) {
            prioEl.textContent = (data.lead_priority || 'WARM').toUpperCase();
        }

        const dtEl = document.getElementById('v-fup-datetime');
        if (dtEl) {
            dtEl.innerHTML = `<i data-lucide="clock" style="width: 14px; height: 14px; color: var(--primary);"></i> <span>${data.date_formatted || ''} at ${data.time_formatted || data.time || ''}</span>`;
        }

        const actEl = document.getElementById('v-fup-action-type');
        if (actEl) actEl.textContent = data.action_type || 'Follow-up Call';

        const assEl = document.getElementById('v-fup-assigned');
        if (assEl) assEl.textContent = `(${data.assigned_to || 'Unassigned'})`;

        const remEl = document.getElementById('v-fup-remarks');
        if (remEl) remEl.textContent = data.remarks || 'No remarks added for this follow-up.';

        const statusBadge = document.getElementById('v-fup-status-badge');
        const completeBtn = document.getElementById('v-fup-complete-btn');

        if (statusBadge) {
            const st = (data.status || 'pending').toLowerCase();
            if (st === 'completed') {
                statusBadge.textContent = 'COMPLETED';
                statusBadge.style.setProperty('--badge-bg', 'var(--success-light)', 'important');
                statusBadge.style.setProperty('--badge-color', 'var(--success)', 'important');
                if (completeBtn) completeBtn.style.display = 'none';
            } else if (st === 'missed' || (new Date(data.scheduled_at) < new Date() && st === 'pending')) {
                statusBadge.textContent = 'MISSED / OVERDUE';
                statusBadge.style.setProperty('--badge-bg', 'var(--danger-light)', 'important');
                statusBadge.style.setProperty('--badge-color', 'var(--danger)', 'important');
                if (completeBtn) completeBtn.style.display = 'inline-flex';
            } else {
                statusBadge.textContent = 'PENDING';
                statusBadge.style.setProperty('--badge-bg', 'var(--warning-light)', 'important');
                statusBadge.style.setProperty('--badge-color', 'var(--warning)', 'important');
                if (completeBtn) completeBtn.style.display = 'inline-flex';
            }
        }

        // Attach click handler to Mark Completed button
        if (completeBtn) {
            completeBtn.onclick = function() {
                if (confirm('Mark this follow-up as COMPLETED?')) {
                    fetch(`index.php?action=complete_followup&id=${data.id}`)
                        .then(res => res.json())
                        .then(resData => {
                            if (resData.success) {
                                if (typeof refreshDataWithoutReload === 'function') {
                                    refreshDataWithoutReload(true);
                                } else {
                                    window.location.reload();
                                }
                            } else {
                                alert(resData.message || 'Failed to update follow-up.');
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            alert('Network error completing follow-up.');
                        });
                }
            };
        }

        // Reschedule button handler -> Opens full Quick Follow-up Data Fill Modal
        const rescheduleBtn = document.getElementById('v-fup-reschedule-btn');
        if (rescheduleBtn) {
            rescheduleBtn.onclick = function() {
                window.closeModal('view-followup-modal');
                if (data && data.lead_id) {
                    window.openQuickFollowupModal(data.lead_id);
                }
            };
        }

        window.openModal('view-followup-modal');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.openQuickFollowupModal = function(leadId) {
        if (!leadId) return;

        fetch('index.php?page=leads&action=get_lead_json&id=' + encodeURIComponent(leadId))
            .then(response => response.json())
            .then(res => {
                if (res.success) {
                    const lead = res.lead;
                    
                    if (document.getElementById('qf-lead-id')) document.getElementById('qf-lead-id').value = lead.id || '';
                    if (document.getElementById('qf-modal-title')) document.getElementById('qf-modal-title').innerHTML = `Follow-Up For <strong>${lead.name || ''}</strong> ( ${lead.phone || ''} )`;
                    if (document.getElementById('qf-company')) document.getElementById('qf-company').value = lead.company || '';
                    if (document.getElementById('qf-status')) document.getElementById('qf-status').value = lead.status || 'new';
                    const assignedList = (lead.assigned || '').split(',').map(s => s.trim().toLowerCase());
                    document.querySelectorAll('.qf-assigned-cb').forEach(cb => {
                        cb.checked = assignedList.includes(cb.value.trim().toLowerCase());
                    });
                    if (document.getElementById('qf-tags')) document.getElementById('qf-tags').value = lead.tags || '';
                    if (document.getElementById('qf-address')) document.getElementById('qf-address').value = lead.address || '';
                    if (document.getElementById('qf-source')) document.getElementById('qf-source').value = lead.source || 'Website';
                    if (document.getElementById('qf-enq-for')) document.getElementById('qf-enq-for').value = lead.enq_for || '';
                    if (document.getElementById('qf-contact-person')) document.getElementById('qf-contact-person').value = lead.contact_person || '';
                    if (document.getElementById('qf-remarks')) document.getElementById('qf-remarks').value = lead.remarks || '';
                    
                    let pendingFup = null;
                    if (res.followup_history && res.followup_history.length > 0) {
                        pendingFup = res.followup_history.find(f => f.status === 'pending') || res.followup_history[0];
                    }

                    if (pendingFup && pendingFup.scheduled_at) {
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

                    const quickFollowupModal = document.getElementById('quick-followup-modal');
                    if (quickFollowupModal) {
                        quickFollowupModal.classList.remove('hidden');
                        quickFollowupModal.classList.add('open');
                        quickFollowupModal.style.display = 'flex';
                    }
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                } else {
                    alert('Error loading lead data: ' + (res.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error loading lead details.');
            });
    };

    window.closeQuickFollowupModal = function() {
        const quickFollowupModal = document.getElementById('quick-followup-modal');
        if (quickFollowupModal) {
            quickFollowupModal.classList.add('hidden');
            quickFollowupModal.classList.remove('open');
            quickFollowupModal.style.display = 'none';
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.addEventListener('click', (e) => {
            const eventItem = e.target.closest('.calendar-event, .agenda-item-clickable');
            if (eventItem) {
                e.preventDefault();
                e.stopPropagation();
                const rawData = eventItem.getAttribute('data-fup-json');
                if (rawData) {
                    try {
                        const data = JSON.parse(rawData);
                        window.openViewFollowupModal(data);
                    } catch(err) {
                        console.error('Error parsing follow-up event JSON', err);
                    }
                }
            }
        });

        // Quick follow-up form submission
        const qfForm = document.getElementById('quick-followup-form');
        if (qfForm) {
            qfForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                fetch('index.php?page=leads', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.closeQuickFollowupModal();
                        if (typeof refreshDataWithoutReload === 'function') {
                            refreshDataWithoutReload(true);
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert('Failed to save details: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('An unexpected network error occurred.');
                });
            });
        }
    });
</script>

<!-- Quick Follow-up & Data Fill Modal (Used for Reschedule) -->
<div id="quick-followup-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 900px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; max-height: 90vh; color: var(--text-main);">
        <!-- Modal Header -->
        <div class="flex justify-between align-center mb-6" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h3 class="font-bold text-lg" id="qf-modal-title" style="font-family: var(--font-heading); margin: 0;">Follow-Up For Client</h3>
            <button type="button" class="btn-icon" onclick="window.closeQuickFollowupModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
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
                    </div>
                    
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
                                <option value="Marg Cloud">Marg Cloud</option>
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
                <button type="button" class="btn text-sm" onclick="window.closeQuickFollowupModal()" style="border-radius: 50px; border: 1.5px solid #004d40; background-color: transparent; color: #004d40; padding: 0.5rem 1.7rem; cursor: pointer; font-weight: 500; font-family: var(--font-heading); transition: all 0.2s ease;">Cancel</button>
                <button type="submit" class="btn text-sm" style="border-radius: 50px; border: none; background-color: #004d40; color: #ffffff; padding: 0.5rem 2.5rem; cursor: pointer; font-weight: 600; font-family: var(--font-heading); transition: all 0.2s ease;">Save</button>
            </div>
        </form>
    </div>
</div>
