<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Default values (fallbacks)
$totalLeads = 0;
$leadTrend = '0 this month';

$todaysLeads = 0;
$leadsDiffPercent = '0 vs yesterday';

$todaysFollowups = 0;
$todaysFollowupsCompleted = 0;

$missedFollowups = 0;

$hotLeads = 0;
$hotLeadsPercent = '0% of directory';

$pendingDemos = 0;
$todaysDemos = 0;

$pendingQuotes = 0;
$pendingQuotesValue = 0;

$pendingPayments = 0;
$pendingPaymentsValue = 0;

$pendingInstalls = 0;
$engineersAssigned = 0;

$closedWon = 0;
$closedLost = 0;
$renewalsDue = 0;

$totalTickets = 0;
$openTickets = 0;
$pendingTickets = 0;
$resolvedTickets = 0;

// Default chart baselines (fallbacks)
$leads_baseline = array_fill(0, 12, 0);
$sales_baseline = array_fill(0, 12, 0);
$source_counts = [];
$exec_performance = [];
$funnel_data = [0, 0, 0, 0, 0, 0];

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

// Load real data if connected
if ($db_connected && $pdo) {
    try {
        if ($is_admin) {
            $totalLeads = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
            
            $lastMonthLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)")->fetchColumn();
            $thisMonthLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)")->fetchColumn();

            $todaysLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = CURRENT_DATE()")->fetchColumn();
            $yesterdayLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)")->fetchColumn();

            $todaysFollowups = $pdo->query("SELECT COUNT(*) FROM followups WHERE DATE(scheduled_at) = CURRENT_DATE()")->fetchColumn();
            $todaysFollowupsCompleted = $pdo->query("SELECT COUNT(*) FROM followups WHERE DATE(scheduled_at) = CURRENT_DATE() AND status = 'completed'")->fetchColumn();

            $missedFollowups = $pdo->query("SELECT COUNT(*) FROM followups WHERE status = 'pending' AND scheduled_at < NOW()")->fetchColumn();

            $hotLeads = $pdo->query("SELECT COUNT(*) FROM leads WHERE LOWER(priority) = 'hot'")->fetchColumn();

            $pendingDemos = $pdo->query("SELECT (SELECT COUNT(*) FROM demos WHERE status = 'scheduled' AND scheduled_at >= NOW()) + (SELECT COUNT(*) FROM followups WHERE status = 'pending' AND scheduled_at >= NOW())")->fetchColumn();
            $todaysDemos = $pdo->query("SELECT (SELECT COUNT(*) FROM demos WHERE DATE(scheduled_at) = CURRENT_DATE() AND status = 'scheduled' AND scheduled_at >= NOW()) + (SELECT COUNT(*) FROM followups WHERE DATE(scheduled_at) = CURRENT_DATE() AND status = 'pending' AND scheduled_at >= NOW())")->fetchColumn();

            $pendingQuotes = $pdo->query("SELECT COUNT(*) FROM quotations WHERE status = 'pending'")->fetchColumn();
            $pendingQuotesVal = $pdo->query("SELECT SUM(grand_total) FROM quotations WHERE status = 'pending'")->fetchColumn();

            $pendingPayments = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'pending'")->fetchColumn();
            $pendingPaymentsVal = $pdo->query("SELECT SUM(balance_amount) FROM invoices WHERE status = 'pending'")->fetchColumn();

            $pendingInstalls = $pdo->query("SELECT COUNT(*) FROM installations WHERE status IN ('assigned', 'in_progress')")->fetchColumn();
            $engineersAssigned = $pdo->query("SELECT COUNT(DISTINCT engineer) FROM installations WHERE status IN ('assigned', 'in_progress')")->fetchColumn();

            $closedWon = $pdo->query("SELECT COUNT(*) FROM leads WHERE LOWER(status) IN ('won', 'closed_won', 'install_pending', 'payment_pending')")->fetchColumn();
            $closedLost = $pdo->query("SELECT COUNT(*) FROM leads WHERE LOWER(status) IN ('lost', 'closed_lost')")->fetchColumn();
            $renewalsDue = $pdo->query("SELECT COUNT(*) FROM renewals WHERE expiry_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)")->fetchColumn();

            $totalTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets")->fetchColumn();
            $openTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE LOWER(status) = 'open'")->fetchColumn();
            $pendingTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE LOWER(status) IN ('in_progress', 'pending')")->fetchColumn();
            $resolvedTickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE LOWER(status) IN ('resolved', 'closed')")->fetchColumn();
        } else {
            // Filter queries for employee users (assigned leads only)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ?");
            $stmt->execute([$user_name]);
            $totalLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 2 MONTH) AND created_at < DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)");
            $stmt->execute([$user_name]);
            $lastMonthLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)");
            $stmt->execute([$user_name]);
            $thisMonthLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND DATE(created_at) = CURRENT_DATE()");
            $stmt->execute([$user_name]);
            $todaysLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)");
            $stmt->execute([$user_name]);
            $yesterdayLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE (assigned_to = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND DATE(scheduled_at) = CURRENT_DATE()");
            $stmt->execute([$user_name, $user_name]);
            $todaysFollowups = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE (assigned_to = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND DATE(scheduled_at) = CURRENT_DATE() AND status = 'completed'");
            $stmt->execute([$user_name, $user_name]);
            $todaysFollowupsCompleted = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE (assigned_to = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status = 'pending' AND scheduled_at < NOW()");
            $stmt->execute([$user_name, $user_name]);
            $missedFollowups = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND LOWER(priority) = 'hot'");
            $stmt->execute([$user_name]);
            $hotLeads = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM demos WHERE (engineer = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status = 'scheduled' AND scheduled_at >= NOW()) + (SELECT COUNT(*) FROM followups WHERE (assigned_to = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status = 'pending' AND scheduled_at >= NOW())");
            $stmt->execute([$user_name, $user_name, $user_name, $user_name]);
            $pendingDemos = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM demos WHERE (engineer = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND DATE(scheduled_at) = CURRENT_DATE() AND status = 'scheduled' AND scheduled_at >= NOW()) + (SELECT COUNT(*) FROM followups WHERE (assigned_to = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND DATE(scheduled_at) = CURRENT_DATE() AND status = 'pending' AND scheduled_at >= NOW())");
            $stmt->execute([$user_name, $user_name, $user_name, $user_name]);
            $todaysDemos = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE (created_by = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status = 'pending'");
            $stmt->execute([$user_name, $user_name]);
            $pendingQuotes = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT SUM(grand_total) FROM quotations WHERE (created_by = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status = 'pending'");
            $stmt->execute([$user_name, $user_name]);
            $pendingQuotesVal = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE lead_id IN (SELECT id FROM leads WHERE assigned_to = ?) AND status = 'pending'");
            $stmt->execute([$user_name]);
            $pendingPayments = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT SUM(balance_amount) FROM invoices WHERE lead_id IN (SELECT id FROM leads WHERE assigned_to = ?) AND status = 'pending'");
            $stmt->execute([$user_name]);
            $pendingPaymentsVal = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM installations WHERE (engineer = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status IN ('assigned', 'in_progress')");
            $stmt->execute([$user_name, $user_name]);
            $pendingInstalls = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT engineer) FROM installations WHERE (engineer = ? OR lead_id IN (SELECT id FROM leads WHERE assigned_to = ?)) AND status IN ('assigned', 'in_progress')");
            $stmt->execute([$user_name, $user_name]);
            $engineersAssigned = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND LOWER(status) IN ('won', 'closed_won', 'install_pending', 'payment_pending')");
            $stmt->execute([$user_name]);
            $closedWon = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND LOWER(status) IN ('lost', 'closed_lost')");
            $stmt->execute([$user_name]);
            $closedLost = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM renewals WHERE lead_id IN (SELECT id FROM leads WHERE assigned_to = ?) AND expiry_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)");
            $stmt->execute([$user_name]);
            $renewalsDue = $stmt->fetchColumn();

            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(?))");
            $stmtT->execute([$user_name]);
            $totalTickets = $stmtT->fetchColumn();

            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) AND LOWER(status) = 'open'");
            $stmtT->execute([$user_name]);
            $openTickets = $stmtT->fetchColumn();

            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) AND LOWER(status) IN ('in_progress', 'pending')");
            $stmtT->execute([$user_name]);
            $pendingTickets = $stmtT->fetchColumn();

            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) AND LOWER(status) IN ('resolved', 'closed')");
            $stmtT->execute([$user_name]);
            $resolvedTickets = $stmtT->fetchColumn();
        }

        $leadTrend = ($lastMonthLeads > 0) ? '+' . round((($thisMonthLeads - $lastMonthLeads) / $lastMonthLeads) * 100, 1) . '% this month' : '+10% this month';
        $leadsDiffPercent = ($yesterdayLeads > 0) ? round((($todaysLeads - $yesterdayLeads) / $yesterdayLeads) * 100, 1) . '% vs yesterday' : '+0% vs yesterday';
        $hotLeadsPercent = round(($hotLeads / max(1, $totalLeads)) * 100, 1) . '% of directory';
        if (isset($pendingQuotesVal) && $pendingQuotesVal) $pendingQuotesValue = $pendingQuotesVal;
        if (isset($pendingPaymentsVal) && $pendingPaymentsVal) $pendingPaymentsValue = $pendingPaymentsVal;

        // 1. Leads created per month
        $current_month = intval(date('n'));
        $monthly_leads = array_fill(1, 12, 0);
        if ($is_admin) {
            $resLeads = $pdo->query("SELECT MONTH(created_at) as m, COUNT(*) as c FROM leads GROUP BY MONTH(created_at)")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtM = $pdo->prepare("SELECT MONTH(created_at) as m, COUNT(*) as c FROM leads WHERE assigned_to = ? GROUP BY MONTH(created_at)");
            $stmtM->execute([$user_name]);
            $resLeads = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($resLeads as $row) {
            $monthly_leads[intval($row['m'])] = intval($row['c']);
        }
        $leads_baseline[$current_month - 1] += array_sum($monthly_leads);

        // 2. Sales volume per month
        $monthly_sales = array_fill(1, 12, 0);
        if ($is_admin) {
            $resSales = $pdo->query("SELECT MONTH(date_issued) as m, SUM(total_amount) as s FROM invoices GROUP BY MONTH(date_issued)")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtS = $pdo->prepare("SELECT MONTH(date_issued) as m, SUM(total_amount) as s FROM invoices WHERE lead_id IN (SELECT id FROM leads WHERE assigned_to = ?) GROUP BY MONTH(date_issued)");
            $stmtS->execute([$user_name]);
            $resSales = $stmtS->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($resSales as $row) {
            $monthly_sales[intval($row['m'])] = floatval($row['s']) / 100000;
        }
        $sales_baseline[$current_month - 1] += array_sum($monthly_sales);

        // 3. Lead sources
        if ($is_admin) {
            $db_source_counts = $pdo->query("SELECT source, COUNT(*) as c FROM leads GROUP BY source")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtSrc = $pdo->prepare("SELECT source, COUNT(*) as c FROM leads WHERE assigned_to = ? GROUP BY source");
            $stmtSrc->execute([$user_name]);
            $db_source_counts = $stmtSrc->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($db_source_counts as $row) {
            $src = $row['source'];
            if (array_key_exists($src, $source_counts)) {
                $source_counts[$src] = intval($row['c']);
            }
        }

        // 4. Exec performance
        if ($is_admin) {
            $resExec = $pdo->query("SELECT assigned_to, COUNT(*) as won_count FROM leads WHERE LOWER(status) IN ('won', 'closed_won', 'install_pending', 'payment_pending') GROUP BY assigned_to")->fetchAll(PDO::FETCH_ASSOC);
            $resDemos = $pdo->query("SELECT engineer, COUNT(*) as demo_count FROM demos GROUP BY engineer")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtExec = $pdo->prepare("SELECT assigned_to, COUNT(*) as won_count FROM leads WHERE assigned_to = ? AND LOWER(status) IN ('won', 'closed_won', 'install_pending', 'payment_pending') GROUP BY assigned_to");
            $stmtExec->execute([$user_name]);
            $resExec = $stmtExec->fetchAll(PDO::FETCH_ASSOC);

            $stmtD = $pdo->prepare("SELECT engineer, COUNT(*) as demo_count FROM demos WHERE engineer = ? GROUP BY engineer");
            $stmtD->execute([$user_name]);
            $resDemos = $stmtD->fetchAll(PDO::FETCH_ASSOC);
        }
        $user_stats = [];
        foreach ($resExec as $row) {
            if (empty($row['assigned_to'])) continue;
            $user_stats[$row['assigned_to']] = ['won' => intval($row['won_count']), 'demos' => 0];
        }
        foreach ($resDemos as $row) {
            if (empty($row['engineer'])) continue;
            if (!isset($user_stats[$row['engineer']])) {
                $user_stats[$row['engineer']] = ['won' => 0, 'demos' => 0];
            }
            $user_stats[$row['engineer']]['demos'] = intval($row['demo_count']);
        }
        uasort($user_stats, function($a, $b) { return $b['won'] - $a['won']; });
        $user_stats = array_slice($user_stats, 0, 5, true);
        if (!empty($user_stats)) {
            $exec_performance = [];
            foreach ($user_stats as $name => $stat) {
                $exec_performance[] = [
                    'name' => $name,
                    'won' => $stat['won'],
                    'demos' => $stat['demos']
                ];
            }
        }

        // 5. Conversion funnel
        $funnel_stages = ['new' => 0, 'contacted' => 0, 'interested' => 0, 'quotation_sent' => 0, 'won' => 0];
        if ($is_admin) {
            $resFunnel = $pdo->query("SELECT status, COUNT(*) as c FROM leads GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtFn = $pdo->prepare("SELECT status, COUNT(*) as c FROM leads WHERE assigned_to = ? GROUP BY status");
            $stmtFn->execute([$user_name]);
            $resFunnel = $stmtFn->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($resFunnel as $row) {
            $st = strtolower($row['status']);
            if (array_key_exists($st, $funnel_stages)) {
                $funnel_stages[$st] = intval($row['c']);
            } elseif ($st === 'payment_pending' || $st === 'install_pending' || $st === 'closed_won') {
                $funnel_stages['won'] += intval($row['c']);
            }
        }
        $leads_total_count = max(1, $totalLeads);
        $funnel_data = [
            100,
            round((max(1, $funnel_stages['contacted'] + $funnel_stages['interested'] + $funnel_stages['quotation_sent'] + $funnel_stages['won']) / $leads_total_count) * 100),
            round((max(1, $funnel_stages['interested'] + $funnel_stages['quotation_sent'] + $funnel_stages['won']) / $leads_total_count) * 100),
            round((max(1, $funnel_stages['quotation_sent'] + $funnel_stages['won']) / $leads_total_count) * 100),
            round((max(1, $funnel_stages['won']) / $leads_total_count) * 100)
        ];
        $funnel_data[1] = min(100, $funnel_data[1]);
        $funnel_data[2] = min($funnel_data[1], $funnel_data[2]);
        $funnel_data[3] = min($funnel_data[2], $funnel_data[3]);
        $funnel_data[4] = min($funnel_data[3], $funnel_data[4]);

    } catch (PDOException $e) {
        // Fallback silently
    }
}

$kpi_cards = [
    [
        'title' => 'Total Leads',
        'value' => number_format($totalLeads),
        'icon' => 'users',
        'trend' => $leadTrend,
        'trend_class' => 'positive',
        'border' => 'var(--primary)',
        'bg' => 'var(--primary-light)',
        'color' => 'var(--primary)',
        'link' => 'index.php?page=leads'
    ],
    [
        'title' => "Today's Leads",
        'value' => number_format($todaysLeads),
        'icon' => 'user-plus',
        'trend' => $leadsDiffPercent,
        'trend_class' => 'positive',
        'border' => 'var(--accent)',
        'bg' => 'var(--accent-light)',
        'color' => 'var(--accent)',
        'link' => 'index.php?page=leads&filter=today'
    ],
    [
        'title' => "Today's Follow-ups",
        'value' => number_format($todaysFollowups),
        'icon' => 'calendar-check',
        'trend' => $todaysFollowupsCompleted . ' completed',
        'trend_class' => 'neutral',
        'border' => 'var(--info)',
        'bg' => 'var(--info-light)',
        'color' => 'var(--info)',
        'link' => 'index.php?page=followups&filter=today'
    ],
    [
        'title' => 'Missed Follow-ups',
        'value' => number_format($missedFollowups),
        'icon' => 'calendar-x',
        'trend' => 'Requires rescheduling',
        'trend_class' => 'negative',
        'border' => 'var(--danger)',
        'bg' => 'var(--danger-light)',
        'color' => 'var(--danger)',
        'link' => 'index.php?page=followups&filter=missed'
    ],
    [
        'title' => 'Hot Leads',
        'value' => number_format($hotLeads),
        'icon' => 'flame',
        'trend' => $hotLeadsPercent,
        'trend_class' => 'positive',
        'border' => 'var(--danger)',
        'bg' => 'var(--danger-light)',
        'color' => 'var(--danger)',
        'link' => 'index.php?page=leads&priority=hot'
    ],
    [
        'title' => 'Demo Pending',
        'value' => number_format($pendingDemos),
        'icon' => 'monitor-play',
        'trend' => $todaysDemos . ' scheduled today',
        'trend_class' => 'neutral',
        'border' => 'var(--warning)',
        'bg' => 'var(--warning-light)',
        'color' => 'var(--warning)',
        'link' => 'index.php?page=demo&status=scheduled'
    ],
    [
        'title' => 'Quotation Pending',
        'value' => number_format($pendingQuotes),
        'icon' => 'file-spreadsheet',
        'trend' => 'Total value: ₹' . round($pendingQuotesValue / 100000, 1) . 'L',
        'trend_class' => 'neutral',
        'border' => 'var(--accent)',
        'bg' => 'var(--accent-light)',
        'color' => 'var(--accent)',
        'link' => 'index.php?page=quotation&status=pending'
    ],
    [
        'title' => 'Payment Pending',
        'value' => number_format($pendingPayments),
        'icon' => 'banknote',
        'trend' => 'Total due: ₹' . round($pendingPaymentsValue / 100000, 1) . 'L',
        'trend_class' => 'negative',
        'border' => 'var(--warning)',
        'bg' => 'var(--warning-light)',
        'color' => 'var(--warning)',
        'link' => 'index.php?page=payments&status=pending'
    ],
    [
        'title' => 'Installation Pending',
        'value' => number_format($pendingInstalls),
        'icon' => 'wrench',
        'trend' => $engineersAssigned . ' active engineers',
        'trend_class' => 'neutral',
        'border' => 'var(--info)',
        'bg' => 'var(--info-light)',
        'color' => 'var(--info)',
        'link' => 'index.php?page=installation&status=assigned'
    ],
    [
        'title' => 'Closed Won',
        'value' => number_format($closedWon),
        'icon' => 'award',
        'trend' => 'Active conversions',
        'trend_class' => 'positive',
        'border' => 'var(--success)',
        'bg' => 'var(--success-light)',
        'color' => 'var(--success)',
        'link' => 'index.php?page=leads&status=won'
    ],
    [
        'title' => 'Closed Lost',
        'value' => number_format($closedLost),
        'icon' => 'thumbs-down',
        'trend' => round(($closedLost / max(1, $totalLeads)) * 100, 1) . '% lost ratio',
        'trend_class' => 'neutral',
        'border' => 'var(--text-muted)',
        'bg' => 'var(--border-card)',
        'color' => 'var(--text-muted)',
        'link' => 'index.php?page=leads&status=lost'
    ],
    [
        'title' => 'Renewals Due',
        'value' => number_format($renewalsDue),
        'icon' => 'history',
        'trend' => 'Expiring within 30 days',
        'trend_class' => 'negative',
        'border' => 'var(--warning)',
        'bg' => 'var(--warning-light)',
        'color' => 'var(--warning)',
        'link' => 'index.php?page=renewals'
    ],
    [
        'title' => 'Total Tickets',
        'value' => number_format($totalTickets),
        'icon' => 'life-buoy',
        'trend' => 'All helpdesk tickets',
        'trend_class' => 'neutral',
        'border' => 'var(--primary)',
        'bg' => 'var(--primary-light)',
        'color' => 'var(--primary)',
        'link' => 'index.php?page=support'
    ],
    [
        'title' => 'Open Tickets',
        'value' => number_format($openTickets),
        'icon' => 'ticket',
        'trend' => 'Awaiting technician',
        'trend_class' => 'negative',
        'border' => 'var(--warning)',
        'bg' => 'var(--warning-light)',
        'color' => 'var(--warning)',
        'link' => 'index.php?page=support&status=open'
    ],
    [
        'title' => 'Pending Tickets',
        'value' => number_format($pendingTickets),
        'icon' => 'clock',
        'trend' => 'In-progress resolution',
        'trend_class' => 'neutral',
        'border' => 'var(--info)',
        'bg' => 'var(--info-light)',
        'color' => 'var(--info)',
        'link' => 'index.php?page=support&status=in_progress'
    ],
    [
        'title' => 'Closed Tickets',
        'value' => number_format($resolvedTickets),
        'icon' => 'check-circle-2',
        'trend' => 'Resolved client issues',
        'trend_class' => 'positive',
        'border' => 'var(--success)',
        'bg' => 'var(--success-light)',
        'color' => 'var(--success)',
        'link' => 'index.php?page=support&status=resolved'
    ]
];
?>

<script>
window.dashboardChartData = {
    leadsCreated: <?php echo json_encode(array_values($leads_baseline)); ?>,
    salesVolume: <?php echo json_encode(array_values($sales_baseline)); ?>,
    sourcesLabels: <?php echo json_encode(array_keys($source_counts)); ?>,
    sourcesData: <?php echo json_encode(array_values($source_counts)); ?>,
    execPerformance: <?php echo json_encode($exec_performance); ?>,
    funnelData: <?php echo json_encode($funnel_data); ?>
};
</script>

<div class="dashboard-container">
    <!-- Top Greeting Row -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 class="mb-1" style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;">Workspace Overview</h2>
            <p class="text-muted text-sm">Hello, <?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Harsh Vardhan'; ?> (<?php echo htmlspecialchars($user_role); ?>). <?php echo $is_admin ? "Here is what is happening across all system leads today." : "Here is what is happening across your assigned leads today."; ?></p>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-secondary text-sm" onclick="if(typeof refreshDataWithoutReload==='function') refreshDataWithoutReload(true); else window.location.reload();">
                <i data-lucide="refresh-cw" style="width: 16px; height: 16px;"></i>
                <span>Refresh Data</span>
            </button>
            <a href="index.php?page=lead_form" class="btn btn-primary text-sm">
                <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                <span>Create Lead</span>
            </a>
        </div>
    </div>

    <!-- 12 KPI Grid Layout -->
    <div class="kpi-grid">
        <?php foreach ($kpi_cards as $card): ?>
            <a href="<?php echo htmlspecialchars($card['link']); ?>" class="kpi-card text-decoration-none" style="--kpi-border: <?php echo $card['border']; ?>; --kpi-bg: <?php echo $card['bg']; ?>; --kpi-color: <?php echo $card['color']; ?>; display: flex; text-decoration: none; color: inherit; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                <div class="kpi-icon-box">
                    <i data-lucide="<?php echo $card['icon']; ?>"></i>
                </div>
                <div class="kpi-info">
                    <span class="kpi-title"><?php echo $card['title']; ?></span>
                    <span class="kpi-value"><?php echo $card['value']; ?></span>
                    <span class="kpi-trend <?php echo $card['trend_class']; ?>">
                        <?php 
                        if ($card['trend_class'] === 'positive') echo '↑';
                        elseif ($card['trend_class'] === 'negative') echo '↓';
                        ?>
                        <?php echo $card['trend']; ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Charts Grid Layout -->
    <div class="charts-grid">
        <!-- Monthly Leads (Full Width) -->
        <div class="chart-card full-width">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Monthly Leads Trend (Jan - Dec)</h3>
                <div class="flex align-center gap-2">
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">Year 2026</span>
                </div>
            </div>
            <div class="chart-canvas-container">
                <canvas id="monthlyLeadsChart"></canvas>
            </div>
        </div>

        <!-- Monthly Sales Volume -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Monthly Sales Volume (INR Lakhs)</h3>
                <span class="badge" style="--badge-bg: var(--accent-light); --badge-color: var(--accent);">Target Achieved: 92%</span>
            </div>
            <div class="chart-canvas-container">
                <canvas id="monthlySalesChart"></canvas>
            </div>
        </div>

        <!-- Lead Distribution by Sources -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Lead Acquisition Sources</h3>
            </div>
            <div class="chart-canvas-container">
                <canvas id="leadSourcesChart"></canvas>
            </div>
        </div>

        <!-- Employee Performance conversions -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Top Sales Execs Performance</h3>
            </div>
            <div class="chart-canvas-container">
                <canvas id="employeePerformanceChart"></canvas>
            </div>
        </div>

        <!-- Lead Conversion Funnel -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Lead Conversion Funnel Stages</h3>
            </div>
            <div class="chart-canvas-container">
                <canvas id="conversionFunnelChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php
// Check if current user WABA settings are unconfigured (Super Admin only)
$showWabaModal = false;
$isSuperAdminForModal = isSystemAdminRole($_SESSION['user_role'] ?? '');
if ($isSuperAdminForModal && $db_connected && $pdo) {
    $uid = $_SESSION['user_id'] ?? 1;
    $stmtWabaChk = $pdo->prepare("SELECT phone_number_id, access_token, web_api_session_status FROM merchant_waba_settings WHERE user_id = ?");
    $stmtWabaChk->execute([$uid]);
    $wabaRow = $stmtWabaChk->fetch(PDO::FETCH_ASSOC);
    if (!$wabaRow || ((empty($wabaRow['phone_number_id']) || empty($wabaRow['access_token'])) && ($wabaRow['web_api_session_status'] ?? '') !== 'connected')) {
        $showWabaModal = true;
    }
}
?>

<?php if ($showWabaModal): ?>
<!-- Post-Login Meta Embedded Signup Onboarding Modal -->
<div id="meta-waba-onboarding-modal" class="modal-overlay active" style="display: flex; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(8px);">
    <div class="modal-container" style="max-width: 580px; background: #0f172a; border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); padding: 28px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="width: 64px; height: 64px; border-radius: 20px; background: rgba(59, 130, 246, 0.15); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; border: 1px solid rgba(59, 130, 246, 0.3);">
                <i data-lucide="qr-code" style="width: 34px; height: 34px; color: #3b82f6;"></i>
            </div>
            <h2 style="font-size: 1.4rem; font-weight: 700; color: #ffffff; margin-bottom: 6px;">Connect Your WhatsApp Business Account</h2>
            <p style="color: #94a3b8; font-size: 0.875rem; margin: 0;">Connect Meta WABA in 1-Click to dispatch Marg ERP Bills & Marketing Broadcasts from your OWN WhatsApp Number.</p>
        </div>

        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 16px; margin-bottom: 24px; font-size: 0.85rem; color: #cbd5e1; line-height: 1.6;">
            💡 <strong>No Meta App Creation Required!</strong><br>
            Aapko Meta Developer portal me App banane ki zaroorat nahi hai. Humare <strong>Official Meta Tech Provider Solution</strong> dwara bas Meta account se log in karein — aapka WhatsApp number 30 second me connect ho jayega!
        </div>

        <div style="display: flex; flex-direction: column; gap: 12px;">
            <a href="index.php?page=merchant_waba_settings" class="btn btn-primary" style="padding: 12px; border-radius: 12px; font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 8px; background: #3b82f6; border: none; text-decoration: none; color: white;">
                <i data-lucide="zap" style="width: 20px; height: 20px;"></i>
                Connect WhatsApp WABA Now
            </a>
            <button type="button" onclick="document.getElementById('meta-waba-onboarding-modal').style.display='none'" class="btn btn-secondary text-xs" style="background: transparent; border: none; color: #64748b; padding: 6px;">
                Remind Me Later
            </button>
        </div>
    </div>
</div>
<?php endif; ?>
