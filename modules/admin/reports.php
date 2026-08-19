<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Check Admin view access
if ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'Super Admin') {
    $_GET['requested'] = 'reports';
    include_once __DIR__ . '/../access_denied.php';
    return;
}

// 1. Fetch system users and distinct assigned operators for filter dropdowns
$system_users = [];
if ($db_connected && $pdo) {
    try {
        // Active registered users
        $uStmt = $pdo->query("SELECT name, role FROM users ORDER BY name ASC");
        while ($u = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            $system_users[$u['name']] = $u['role'];
        }
        
        // Also collect assigned executive names from leads, support, demos
        $extra_names = [];
        $res1 = $pdo->query("SELECT DISTINCT assigned_to FROM leads WHERE assigned_to IS NOT NULL AND assigned_to != ''");
        while ($r = $res1->fetch(PDO::FETCH_COLUMN)) { $extra_names[$r] = 'Sales Exec'; }
        
        $res2 = $pdo->query("SELECT DISTINCT assigned_to FROM support_tickets WHERE assigned_to IS NOT NULL AND assigned_to != ''");
        while ($r = $res2->fetch(PDO::FETCH_COLUMN)) { $extra_names[$r] = 'Support'; }
        
        $res3 = $pdo->query("SELECT DISTINCT engineer FROM demos WHERE engineer IS NOT NULL AND engineer != ''");
        while ($r = $res3->fetch(PDO::FETCH_COLUMN)) { $extra_names[$r] = 'Engineer'; }

        foreach ($extra_names as $name => $role) {
            if (!isset($system_users[$name])) {
                $system_users[$name] = $role;
            }
        }
        ksort($system_users);
    } catch (PDOException $e) {}
}

// 2. Process CSV Download Trigger
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $report_type = isset($_GET['type']) ? $_GET['type'] : 'user_leads';
    $selected_user = isset($_GET['user']) ? trim($_GET['user']) : 'all';
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
    $from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
    $to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $report_type . '_' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Output BOM for Excel UTF-8 compatibility
    fputs($output, "\xEF\xBB\xBF");
    
    if ($db_connected && $pdo) {
        try {
            if ($report_type === 'user_leads') {
                fputcsv($output, ['Lead ID', 'Customer Name', 'Company', 'Phone', 'Email', 'Assigned Exec', 'Priority', 'Status', 'Budget (INR)', 'Source', 'Created Date']);
                $sql = "SELECT id, name, company, phone, IFNULL(email, ''), IFNULL(assigned_to, 'Unassigned'), priority, status, budget, source, created_at FROM leads WHERE DATE(created_at) BETWEEN ? AND ?";
                $params = [$from_date, $to_date];
                if ($selected_user !== 'all') {
                    $sql .= " AND (assigned_to = ? OR assigned_to LIKE ?)";
                    $params[] = $selected_user;
                    $params[] = '%' . $selected_user . '%';
                }
                if ($status_filter !== 'all') {
                    $sql .= " AND status = ?";
                    $params[] = $status_filter;
                }
                $sql .= " ORDER BY created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'user_tickets') {
                fputcsv($output, ['Ticket ID', 'Customer Name', 'Subject', 'Priority', 'Status', 'Assigned Tech', 'Date Created']);
                $sql = "SELECT id, customer_name, subject, priority, status, IFNULL(assigned_to, 'Unassigned'), date_created FROM support_tickets WHERE DATE(date_created) BETWEEN ? AND ?";
                $params = [$from_date, $to_date];
                if ($selected_user !== 'all') {
                    $sql .= " AND (assigned_to = ? OR assigned_to LIKE ?)";
                    $params[] = $selected_user;
                    $params[] = '%' . $selected_user . '%';
                }
                if ($status_filter !== 'all') {
                    $sql .= " AND status = ?";
                    $params[] = $status_filter;
                }
                $sql .= " ORDER BY date_created DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'user_demos') {
                fputcsv($output, ['Demo ID', 'Lead ID', 'Company Name', 'Scheduled Date', 'Mode', 'Engineer', 'Status', 'Rating (1-5)', 'Feedback']);
                $sql = "SELECT d.id, d.lead_id, IFNULL(l.company, 'N/A'), d.scheduled_at, d.mode, d.engineer, d.status, IFNULL(d.rating, '-'), IFNULL(d.feedback, '') FROM demos d LEFT JOIN leads l ON d.lead_id = l.id WHERE DATE(d.scheduled_at) BETWEEN ? AND ?";
                $params = [$from_date, $to_date];
                if ($selected_user !== 'all') {
                    $sql .= " AND (d.engineer = ? OR d.engineer LIKE ?)";
                    $params[] = $selected_user;
                    $params[] = '%' . $selected_user . '%';
                }
                if ($status_filter !== 'all') {
                    $sql .= " AND d.status = ?";
                    $params[] = $status_filter;
                }
                $sql .= " ORDER BY d.scheduled_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'user_followups') {
                fputcsv($output, ['Followup ID', 'Lead ID', 'Company Name', 'Action Type', 'Scheduled Date', 'Assigned Exec', 'Status', 'Remarks']);
                $sql = "SELECT f.id, f.lead_id, IFNULL(l.company, 'N/A'), f.action_type, f.scheduled_at, f.assigned_to, f.status, IFNULL(f.remarks, '') FROM followups f LEFT JOIN leads l ON f.lead_id = l.id WHERE DATE(f.scheduled_at) BETWEEN ? AND ?";
                $params = [$from_date, $to_date];
                if ($selected_user !== 'all') {
                    $sql .= " AND (f.assigned_to = ? OR f.assigned_to LIKE ?)";
                    $params[] = $selected_user;
                    $params[] = '%' . $selected_user . '%';
                }
                if ($status_filter !== 'all') {
                    $sql .= " AND f.status = ?";
                    $params[] = $status_filter;
                }
                $sql .= " ORDER BY f.scheduled_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'user_installations') {
                fputcsv($output, ['Installation ID', 'Lead ID', 'Customer Name', 'City', 'Engineer', 'Scheduled Date', 'Checklist Progress', 'Status']);
                $sql = "SELECT id, lead_id, customer_name, city, engineer, scheduled_date, CONCAT(checklist_done, '/', checklist_total), status FROM installations WHERE DATE(scheduled_date) BETWEEN ? AND ?";
                $params = [$from_date, $to_date];
                if ($selected_user !== 'all') {
                    $sql .= " AND (engineer = ? OR engineer LIKE ?)";
                    $params[] = $selected_user;
                    $params[] = '%' . $selected_user . '%';
                }
                if ($status_filter !== 'all') {
                    $sql .= " AND status = ?";
                    $params[] = $status_filter;
                }
                $sql .= " ORDER BY scheduled_date DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'conversions') {
                fputcsv($output, ['Lead Acquisition Channel', 'Leads Registered', 'Closed Won', 'Closed Lost', 'Conversion Rate %']);
                $stmt = $pdo->prepare("SELECT IFNULL(NULLIF(source, ''), 'Unassigned'), COUNT(*), SUM(CASE WHEN LOWER(status) IN ('won', 'closed_won', 'payment_pending', 'install_pending') THEN 1 ELSE 0 END), SUM(CASE WHEN LOWER(status) IN ('lost', 'closed_lost') THEN 1 ELSE 0 END) FROM leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY source");
                $stmt->execute([$from_date, $to_date]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $total = max(1, $row[1]);
                    $rate = round(($row[2] / $total) * 100, 1) . '%';
                    $row[] = $rate;
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'sales') {
                fputcsv($output, ['Week Starting', 'Week Ending', 'Invoices Count', 'Total Revenue (INR)', 'Total Received (INR)', 'Total Due (INR)']);
                $stmt = $pdo->prepare("SELECT MIN(date_issued), MAX(date_issued), COUNT(*), SUM(total_amount), SUM(paid_amount), SUM(balance_amount) FROM invoices WHERE date_issued BETWEEN ? AND ? GROUP BY YEARWEEK(date_issued, 1) ORDER BY MIN(date_issued) DESC");
                $stmt->execute([$from_date, $to_date]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'performance') {
                fputcsv($output, ['Sales Operator', 'Total Leads Assigned', 'Closed Won Conversions', 'Total Pipeline Value (INR)', 'Conversion %']);
                $stmt = $pdo->prepare("SELECT IFNULL(NULLIF(assigned_to, ''), 'Unassigned'), COUNT(*), SUM(CASE WHEN LOWER(status) IN ('won', 'closed_won', 'payment_pending', 'install_pending') THEN 1 ELSE 0 END), SUM(budget) FROM leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY assigned_to ORDER BY SUM(CASE WHEN LOWER(status) IN ('won', 'closed_won', 'payment_pending', 'install_pending') THEN 1 ELSE 0 END) DESC");
                $stmt->execute([$from_date, $to_date]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $total = max(1, $row[1]);
                    $rate = round(($row[2] / $total) * 100, 1) . '%';
                    $row[] = $rate;
                    fputcsv($output, $row);
                }
            } elseif ($report_type === 'renewals') {
                fputcsv($output, ['Client Customer', 'Product Licensed', 'Expiry Date', 'Days Remaining', 'Renewal Fee (INR)', 'Status']);
                $stmt = $pdo->prepare("SELECT customer_name, product_name, expiry_date, days_remaining, renewal_fee, status FROM renewals WHERE expiry_date BETWEEN ? AND ? ORDER BY expiry_date ASC");
                $stmt->execute([$from_date, $to_date]);
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    fputcsv($output, $row);
                }
            }
        } catch (PDOException $e) {}
    }
    fclose($output);
    exit;
}

// 3. Resolve Request Query Parameters
$report_type = isset($_GET['type']) ? htmlspecialchars($_GET['type']) : 'user_leads';
$selected_user = isset($_GET['user']) ? htmlspecialchars($_GET['user']) : 'all';
$status_filter = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : 'all';
$from_date = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : date('Y-m-01');
$to_date = isset($_GET['to']) ? htmlspecialchars($_GET['to']) : date('Y-m-d');

$report_data = [];
$kpi = [
    'total_count' => 0,
    'positive_count' => 0,
    'pending_count' => 0,
    'financial_sum' => 0.00,
    'rate_pct' => 0.0
];

// 4. Load Report Data & Compute Analytics KPIs based on Report Category
if ($db_connected && $pdo) {
    try {
        if ($report_type === 'user_leads') {
            $sql = "SELECT l.*, IFNULL(NULLIF(l.assigned_to, ''), 'Unassigned') as assigned_name 
                    FROM leads l 
                    WHERE DATE(l.created_at) BETWEEN ? AND ?";
            $params = [$from_date, $to_date];

            if ($selected_user !== 'all') {
                $sql .= " AND (l.assigned_to = ? OR l.assigned_to LIKE ?)";
                $params[] = $selected_user;
                $params[] = '%' . $selected_user . '%';
            }
            if ($status_filter !== 'all') {
                $sql .= " AND l.status = ?";
                $params[] = $status_filter;
            }
            $sql .= " ORDER BY l.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $kpi['total_count'] = count($report_data);
            foreach ($report_data as $r) {
                $st = strtolower($r['status'] ?? '');
                if (in_array($st, ['won', 'closed_won', 'payment_pending', 'install_pending'])) {
                    $kpi['positive_count']++;
                } elseif (in_array($st, ['new', 'contacted', 'interested', 'quotation_sent', 'followup_scheduled'])) {
                    $kpi['pending_count']++;
                }
                $kpi['financial_sum'] += floatval($r['budget'] ?? 0);
            }
            if ($kpi['total_count'] > 0) {
                $kpi['rate_pct'] = round(($kpi['positive_count'] / $kpi['total_count']) * 100, 1);
            }

        } elseif ($report_type === 'user_tickets') {
            $sql = "SELECT t.*, IFNULL(NULLIF(t.assigned_to, ''), 'Unassigned') as tech_name 
                    FROM support_tickets t 
                    WHERE DATE(t.date_created) BETWEEN ? AND ?";
            $params = [$from_date, $to_date];

            if ($selected_user !== 'all') {
                $sql .= " AND (t.assigned_to = ? OR t.assigned_to LIKE ?)";
                $params[] = $selected_user;
                $params[] = '%' . $selected_user . '%';
            }
            if ($status_filter !== 'all') {
                $sql .= " AND t.status = ?";
                $params[] = $status_filter;
            }
            $sql .= " ORDER BY t.date_created DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $kpi['total_count'] = count($report_data);
            foreach ($report_data as $r) {
                $st = strtolower($r['status'] ?? '');
                if (in_array($st, ['resolved', 'closed'])) {
                    $kpi['positive_count']++;
                } else {
                    $kpi['pending_count']++;
                }
            }
            if ($kpi['total_count'] > 0) {
                $kpi['rate_pct'] = round(($kpi['positive_count'] / $kpi['total_count']) * 100, 1);
            }

        } elseif ($report_type === 'user_demos') {
            $sql = "SELECT d.*, l.company, l.name as client_name, IFNULL(NULLIF(d.engineer, ''), 'Unassigned') as eng_name 
                    FROM demos d 
                    LEFT JOIN leads l ON d.lead_id = l.id 
                    WHERE DATE(d.scheduled_at) BETWEEN ? AND ?";
            $params = [$from_date, $to_date];

            if ($selected_user !== 'all') {
                $sql .= " AND (d.engineer = ? OR d.engineer LIKE ?)";
                $params[] = $selected_user;
                $params[] = '%' . $selected_user . '%';
            }
            if ($status_filter !== 'all') {
                $sql .= " AND d.status = ?";
                $params[] = $status_filter;
            }
            $sql .= " ORDER BY d.scheduled_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $kpi['total_count'] = count($report_data);
            $total_rating = 0;
            $rated_count = 0;
            foreach ($report_data as $r) {
                $st = strtolower($r['status'] ?? '');
                if ($st === 'completed') {
                    $kpi['positive_count']++;
                } elseif ($st === 'scheduled') {
                    $kpi['pending_count']++;
                }
                if (!empty($r['rating'])) {
                    $total_rating += intval($r['rating']);
                    $rated_count++;
                }
            }
            if ($kpi['total_count'] > 0) {
                $kpi['rate_pct'] = round(($kpi['positive_count'] / $kpi['total_count']) * 100, 1);
            }
            if ($rated_count > 0) {
                $kpi['financial_sum'] = round($total_rating / $rated_count, 1);
            }

        } elseif ($report_type === 'user_followups') {
            $sql = "SELECT f.*, l.company, l.name as client_name, IFNULL(NULLIF(f.assigned_to, ''), 'Unassigned') as exec_name 
                    FROM followups f 
                    LEFT JOIN leads l ON f.lead_id = l.id 
                    WHERE DATE(f.scheduled_at) BETWEEN ? AND ?";
            $params = [$from_date, $to_date];

            if ($selected_user !== 'all') {
                $sql .= " AND (f.assigned_to = ? OR f.assigned_to LIKE ?)";
                $params[] = $selected_user;
                $params[] = '%' . $selected_user . '%';
            }
            if ($status_filter !== 'all') {
                $sql .= " AND f.status = ?";
                $params[] = $status_filter;
            }
            $sql .= " ORDER BY f.scheduled_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $kpi['total_count'] = count($report_data);
            foreach ($report_data as $r) {
                $st = strtolower($r['status'] ?? '');
                if ($st === 'completed') {
                    $kpi['positive_count']++;
                } elseif ($st === 'pending') {
                    $kpi['pending_count']++;
                }
            }
            if ($kpi['total_count'] > 0) {
                $kpi['rate_pct'] = round(($kpi['positive_count'] / $kpi['total_count']) * 100, 1);
            }

        } elseif ($report_type === 'user_installations') {
            $sql = "SELECT i.*, IFNULL(NULLIF(i.engineer, ''), 'Unassigned') as eng_name 
                    FROM installations i 
                    WHERE DATE(i.scheduled_date) BETWEEN ? AND ?";
            $params = [$from_date, $to_date];

            if ($selected_user !== 'all') {
                $sql .= " AND (i.engineer = ? OR i.engineer LIKE ?)";
                $params[] = $selected_user;
                $params[] = '%' . $selected_user . '%';
            }
            if ($status_filter !== 'all') {
                $sql .= " AND i.status = ?";
                $params[] = $status_filter;
            }
            $sql .= " ORDER BY i.scheduled_date DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $kpi['total_count'] = count($report_data);
            foreach ($report_data as $r) {
                $st = strtolower($r['status'] ?? '');
                if ($st === 'completed') {
                    $kpi['positive_count']++;
                } else {
                    $kpi['pending_count']++;
                }
            }
            if ($kpi['total_count'] > 0) {
                $kpi['rate_pct'] = round(($kpi['positive_count'] / $kpi['total_count']) * 100, 1);
            }

        } elseif ($report_type === 'conversions') {
            $stmt = $pdo->prepare("SELECT 
                IFNULL(NULLIF(source, ''), 'Unassigned') as channel, 
                COUNT(*) as total_leads,
                SUM(CASE WHEN LOWER(status) IN ('won', 'closed_won', 'payment_pending', 'install_pending') THEN 1 ELSE 0 END) as won_leads,
                SUM(CASE WHEN LOWER(status) IN ('lost', 'closed_lost') THEN 1 ELSE 0 END) as lost_leads
            FROM leads
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY source");
            $stmt->execute([$from_date, $to_date]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($report_type === 'sales') {
            $stmt = $pdo->prepare("SELECT 
                YEARWEEK(date_issued, 1) as week_num,
                MIN(date_issued) as week_start,
                MAX(date_issued) as week_end,
                COUNT(*) as invoices_count,
                SUM(total_amount) as total_revenue,
                SUM(paid_amount) as total_received,
                SUM(balance_amount) as total_due
            FROM invoices
            WHERE date_issued BETWEEN ? AND ?
            GROUP BY YEARWEEK(date_issued, 1)
            ORDER BY week_num DESC");
            $stmt->execute([$from_date, $to_date]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($report_type === 'performance') {
            $stmt = $pdo->prepare("SELECT 
                IFNULL(NULLIF(assigned_to, ''), 'Unassigned') as operator,
                COUNT(*) as total_leads,
                SUM(CASE WHEN LOWER(status) IN ('won', 'closed_won', 'payment_pending', 'install_pending') THEN 1 ELSE 0 END) as won_leads,
                SUM(budget) as total_pipeline_value
            FROM leads
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY assigned_to
            ORDER BY won_leads DESC");
            $stmt->execute([$from_date, $to_date]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($report_type === 'renewals') {
            $stmt = $pdo->prepare("SELECT 
                customer_name,
                product_name,
                expiry_date,
                days_remaining,
                renewal_fee,
                status
            FROM renewals
            WHERE expiry_date BETWEEN ? AND ?
            ORDER BY expiry_date ASC");
            $stmt->execute([$from_date, $to_date]);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $report_data = [];
    }
}
?>

<div class="reports-container">
    <!-- Top Header -->
    <div class="flex justify-between align-center mb-6 print-hidden">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Business Intelligence & Operational Reports</h2>
            <p class="text-muted text-sm">Audit user-wise date-wise activities for leads, support tickets, product demos, follow-ups, and sales conversions.</p>
        </div>
        <div class="flex gap-2">
            <a href="modules/admin/reports.php?export=csv&type=<?php echo $report_type; ?>&user=<?php echo urlencode($selected_user); ?>&status=<?php echo urlencode($status_filter); ?>&from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>" class="btn btn-secondary text-sm" style="text-decoration: none; font-weight: 600;">
                <i data-lucide="file-text" style="width: 16px; height: 16px; margin-right: 0.25rem; display: inline; vertical-align: middle;"></i>
                <span>Export CSV</span>
            </a>
            <button class="btn btn-primary text-sm" onclick="window.print();" style="font-weight: 600;">
                <i data-lucide="printer" style="width: 16px; height: 16px; margin-right: 0.25rem; display: inline; vertical-align: middle;"></i>
                <span>Print Report</span>
            </button>
        </div>
    </div>

    <!-- Analytical KPI Summary Cards (for User-wise reports) -->
    <?php if (in_array($report_type, ['user_leads', 'user_tickets', 'user_demos', 'user_followups', 'user_installations'])): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;" class="print-hidden">
        
        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-xs text-muted font-bold uppercase mb-1">Total Records</div>
                <div class="text-2xl font-bold text-main" style="font-size: 1.65rem; font-family: var(--font-heading);"><?php echo number_format($kpi['total_count']); ?></div>
                <div class="text-xs text-muted mt-1">In selected timeframe</div>
            </div>
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(99,102,241,0.12); color: #6366f1; display: flex; align-items: center; justify-content: justify-content: center; align-items: center;">
                <i data-lucide="layers" style="width: 22px; height: 22px; margin: auto;"></i>
            </div>
        </div>

        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-xs text-muted font-bold uppercase mb-1">
                    <?php 
                    if ($report_type === 'user_tickets') echo 'Resolved Tickets';
                    elseif ($report_type === 'user_leads') echo 'Closed Won Leads';
                    elseif ($report_type === 'user_demos') echo 'Completed Demos';
                    elseif ($report_type === 'user_followups') echo 'Completed Follow-ups';
                    elseif ($report_type === 'user_installations') echo 'Completed Deployments';
                    ?>
                </div>
                <div class="text-2xl font-bold text-success" style="font-size: 1.65rem; font-family: var(--font-heading);"><?php echo number_format($kpi['positive_count']); ?></div>
                <div class="text-xs text-success mt-1">Success / Done count</div>
            </div>
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16,185,129,0.12); color: #10b981; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="check-circle-2" style="width: 22px; height: 22px; margin: auto;"></i>
            </div>
        </div>

        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-xs text-muted font-bold uppercase mb-1">
                    <?php 
                    if ($report_type === 'user_tickets') echo 'Open / In-Progress';
                    elseif ($report_type === 'user_leads') echo 'Active Pipeline Leads';
                    elseif ($report_type === 'user_demos') echo 'Scheduled Demos';
                    elseif ($report_type === 'user_followups') echo 'Pending Follow-ups';
                    elseif ($report_type === 'user_installations') echo 'In-Progress / Scheduled';
                    ?>
                </div>
                <div class="text-2xl font-bold text-warning" style="font-size: 1.65rem; font-family: var(--font-heading);"><?php echo number_format($kpi['pending_count']); ?></div>
                <div class="text-xs text-warning mt-1">Action required</div>
            </div>
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(245,158,11,0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="clock" style="width: 22px; height: 22px; margin: auto;"></i>
            </div>
        </div>

        <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-xs text-muted font-bold uppercase mb-1">
                    <?php 
                    if ($report_type === 'user_leads') echo 'Pipeline Value / Conv.';
                    elseif ($report_type === 'user_demos') echo 'Avg Client Rating';
                    else echo 'Completion Rate';
                    ?>
                </div>
                <div class="text-2xl font-bold text-primary" style="font-size: 1.5rem; font-family: var(--font-heading);">
                    <?php 
                    if ($report_type === 'user_leads') {
                        echo '₹' . number_format($kpi['financial_sum'], 0);
                    } elseif ($report_type === 'user_demos' && $kpi['financial_sum'] > 0) {
                        echo $kpi['financial_sum'] . ' / 5.0 ⭐';
                    } else {
                        echo $kpi['rate_pct'] . '%';
                    }
                    ?>
                </div>
                <div class="text-xs text-muted mt-1">
                    <?php echo ($report_type === 'user_leads') ? 'Success Rate: ' . $kpi['rate_pct'] . '%' : 'Overall Efficiency'; ?>
                </div>
            </div>
            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(6,182,212,0.12); color: #06b6d4; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="trending-up" style="width: 22px; height: 22px; margin: auto;"></i>
            </div>
        </div>

    </div>
    <?php endif; ?>

<style>
.reports-container .quick-dt-presets-container,
.reports-container .quick-dt-label,
.reports-container .quick-dt-chip {
    display: none !important;
}
.report-query-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}
.report-query-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1fr 0.9fr 0.9fr 1.1fr;
    gap: 0.85rem;
    align-items: end;
}
@media (max-width: 1200px) {
    .report-query-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 640px) {
    .report-query-grid {
        grid-template-columns: 1fr;
    }
}
</style>

    <!-- Dynamic Query Filters Control Card -->
    <div class="report-query-card print-hidden">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
            <h3 class="text-xs text-muted font-bold uppercase m-0 flex align-center gap-2" style="color: var(--primary); letter-spacing: 0.05em;">
                <i data-lucide="filter" style="width: 14px; height: 14px;"></i>
                <span>Report Query Parameters</span>
            </h3>
            <!-- Quick Date Range Shortcuts -->
            <div style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; flex-wrap: wrap;">
                <span class="text-muted mr-1 font-semibold">Quick Range:</span>
                <button type="button" onclick="setDateRange('today')" class="btn-pill btn-pill-outline text-xs" style="padding: 0.25rem 0.65rem;">Today</button>
                <button type="button" onclick="setDateRange('yesterday')" class="btn-pill btn-pill-outline text-xs" style="padding: 0.25rem 0.65rem;">Yesterday</button>
                <button type="button" onclick="setDateRange('this_week')" class="btn-pill btn-pill-outline text-xs" style="padding: 0.25rem 0.65rem;">This Week</button>
                <button type="button" onclick="setDateRange('this_month')" class="btn-pill btn-pill-outline text-xs" style="padding: 0.25rem 0.65rem;">This Month</button>
                <button type="button" onclick="setDateRange('last_30')" class="btn-pill btn-pill-outline text-xs" style="padding: 0.25rem 0.65rem;">Last 30 Days</button>
            </div>
        </div>

        <form id="reportFilterForm" action="index.php" method="GET">
            <input type="hidden" name="page" value="admin_reports">
            
            <div class="report-query-grid">
                <!-- Category -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1">Report Category</label>
                    <select name="type" class="form-control text-xs font-semibold" onchange="this.form.submit()" style="height: 38px;">
                        <optgroup label="User & Employee Activity Reports">
                            <option value="user_leads" <?php echo $report_type === 'user_leads' ? 'selected' : ''; ?>>👤 User-Wise Leads</option>
                            <option value="user_tickets" <?php echo $report_type === 'user_tickets' ? 'selected' : ''; ?>>🎫 User-Wise Support Tickets</option>
                            <option value="user_demos" <?php echo $report_type === 'user_demos' ? 'selected' : ''; ?>>💻 User-Wise Scheduled Demos</option>
                            <option value="user_followups" <?php echo $report_type === 'user_followups' ? 'selected' : ''; ?>>📞 User-Wise Follow-Up Logs</option>
                            <option value="user_installations" <?php echo $report_type === 'user_installations' ? 'selected' : ''; ?>>🛠️ User-Wise Deployments</option>
                        </optgroup>
                        <optgroup label="Business Intelligence & Analytics">
                            <option value="conversions" <?php echo $report_type === 'conversions' ? 'selected' : ''; ?>>Lead Conversions by Source</option>
                            <option value="performance" <?php echo $report_type === 'performance' ? 'selected' : ''; ?>>Employee Conversion Matrix</option>
                            <option value="sales" <?php echo $report_type === 'sales' ? 'selected' : ''; ?>>Weekly Sales & Collections</option>
                            <option value="renewals" <?php echo $report_type === 'renewals' ? 'selected' : ''; ?>>Renewals & Grace Forecasts</option>
                        </optgroup>
                    </select>
                </div>

                <!-- User Selector -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1">Target User / Staff</label>
                    <select name="user" class="form-control text-xs font-semibold" style="height: 38px;">
                        <option value="all" <?php echo $selected_user === 'all' ? 'selected' : ''; ?>>-- All Users & Staff --</option>
                        <?php foreach ($system_users as $uname => $urole): ?>
                            <option value="<?php echo htmlspecialchars($uname); ?>" <?php echo $selected_user === $uname ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($uname) . ' (' . htmlspecialchars($urole) . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Selector -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1">Status Filter</label>
                    <select name="status" class="form-control text-xs" style="height: 38px;">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>-- All Statuses --</option>
                        <?php if ($report_type === 'user_tickets'): ?>
                            <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <?php elseif ($report_type === 'user_demos'): ?>
                            <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <?php elseif ($report_type === 'user_followups'): ?>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="missed" <?php echo $status_filter === 'missed' ? 'selected' : ''; ?>>Missed</option>
                        <?php elseif ($report_type === 'user_installations'): ?>
                            <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <?php else: ?>
                            <option value="new" <?php echo $status_filter === 'new' ? 'selected' : ''; ?>>New Lead</option>
                            <option value="contacted" <?php echo $status_filter === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                            <option value="interested" <?php echo $status_filter === 'interested' ? 'selected' : ''; ?>>Interested</option>
                            <option value="quotation_sent" <?php echo $status_filter === 'quotation_sent' ? 'selected' : ''; ?>>Quotation Sent</option>
                            <option value="payment_pending" <?php echo $status_filter === 'payment_pending' ? 'selected' : ''; ?>>Payment Pending</option>
                            <option value="install_pending" <?php echo $status_filter === 'install_pending' ? 'selected' : ''; ?>>Install Pending</option>
                            <option value="won" <?php echo $status_filter === 'won' ? 'selected' : ''; ?>>Closed Won</option>
                            <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>>Closed Lost</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Date Pickers -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1">From Date</label>
                    <input type="date" id="from_date_input" name="from" class="form-control text-xs no-quick" data-no-quick="true" value="<?php echo $from_date; ?>" style="height: 38px;">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1">To Date</label>
                    <input type="date" id="to_date_input" name="to" class="form-control text-xs no-quick" data-no-quick="true" value="<?php echo $to_date; ?>" style="height: 38px;">
                </div>

                <!-- Submit Button Inline -->
                <div class="form-group m-0">
                    <button type="submit" class="btn btn-primary text-xs font-bold w-full" style="height: 38px; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; border-radius: 8px;">
                        <i data-lucide="filter" style="width: 14px; height: 14px;"></i>
                        <span>Apply Filter</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Printable Header (Visible on print only) -->
    <div class="print-only mb-6" style="display: none; border-bottom: 2px solid var(--primary); padding-bottom: 1rem;">
        <h1 style="font-family: var(--font-heading); color: var(--primary); margin: 0; font-size: 2rem;">MARG SOFT SOLUTIONS</h1>
        <h2 style="margin: 0.25rem 0; font-size: 1.25rem; font-weight: 600;">CRM Business & Operational Intelligence Audit Report</h2>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b;">
            Report Category: <strong><?php echo ucwords(str_replace('_', ' ', $report_type)); ?></strong> | 
            User Filter: <strong><?php echo htmlspecialchars($selected_user); ?></strong> | 
            Period: <strong><?php echo $from_date; ?></strong> to <strong><?php echo $to_date; ?></strong>
        </p>
    </div>

    <!-- Main Report Data Table Card -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
        <div class="p-4 border-bottom flex justify-between align-center" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
            <h3 class="text-sm font-semibold m-0" style="font-family: var(--font-heading);">
                Generated Audit View: 
                <span class="text-primary font-bold">
                    <?php 
                    if ($report_type === 'user_leads') echo 'User-Wise & Date-Wise Lead Activity Directory';
                    elseif ($report_type === 'user_tickets') echo 'User-Wise Support Ticket Resolution Log';
                    elseif ($report_type === 'user_demos') echo 'User-Wise Scheduled Product Demos & Feedback';
                    elseif ($report_type === 'user_followups') echo 'User-Wise Follow-Up Communication Planner';
                    elseif ($report_type === 'user_installations') echo 'User-Wise Software Deployment & Installation Log';
                    elseif ($report_type === 'conversions') echo 'Lead Conversions by Channel';
                    elseif ($report_type === 'sales') echo 'Weekly Sales & Collections Report';
                    elseif ($report_type === 'performance') echo 'Employee Conversion Success Matrix';
                    elseif ($report_type === 'renewals') echo 'Renewals and Grace Forecasts';
                    ?>
                </span>
            </h3>
            <span class="text-xs text-muted font-semibold">Total Records: <?php echo count($report_data); ?></span>
        </div>
        
        <div class="table-responsive">
            <table class="table" style="vertical-align: middle;">
                <!-- 1. USER-WISE LEADS REPORT -->
                <?php if ($report_type === 'user_leads'): ?>
                    <thead>
                        <tr>
                            <th>Lead ID</th>
                            <th>Customer & Company</th>
                            <th>Phone & Email</th>
                            <th>Assigned Executive</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Budget Value</th>
                            <th>Created Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-6">No leads found matching the selected user and date parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-bold text-xs"><a href="index.php?page=lead_details&id=<?php echo $row['id']; ?>" class="text-primary"><?php echo htmlspecialchars($row['id']); ?></a></td>
                                    <td>
                                        <div class="font-semibold text-sm"><?php echo htmlspecialchars($row['company']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['name']); ?></div>
                                    </td>
                                    <td class="text-xs">
                                        <div><?php echo htmlspecialchars($row['phone']); ?></div>
                                        <div class="text-muted"><?php echo htmlspecialchars($row['email'] ?? '-'); ?></div>
                                    </td>
                                    <td class="text-xs font-semibold text-main">
                                        <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--primary);"><?php echo htmlspecialchars($row['assigned_name']); ?></span>
                                    </td>
                                    <td>
                                        <?php if (strtolower($row['priority']) === 'hot'): ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">🔥 Hot</span>
                                        <?php elseif (strtolower($row['priority']) === 'warm'): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">⚡ Warm</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted);">Cold</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">
                                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($row['status']))); ?>
                                        </span>
                                    </td>
                                    <td class="text-sm font-bold text-main">₹<?php echo number_format($row['budget'], 2); ?></td>
                                    <td class="text-xs text-muted"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 2. USER-WISE SUPPORT TICKETS REPORT -->
                <?php elseif ($report_type === 'user_tickets'): ?>
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Customer Name</th>
                            <th>Subject & Issue</th>
                            <th>Priority</th>
                            <th>Assigned Tech</th>
                            <th>Status</th>
                            <th>Date Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-6">No support tickets found for the selected user and date parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-bold text-xs text-primary"><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td class="font-semibold text-sm"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                    <td>
                                        <div class="text-sm font-semibold"><?php echo htmlspecialchars($row['subject']); ?></div>
                                        <?php if (!empty($row['problem'])): ?>
                                            <div class="text-xs text-muted"><?php echo htmlspecialchars(substr($row['problem'], 0, 60)) . '...'; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['priority'] === 'critical'): ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Critical</span>
                                        <?php elseif ($row['priority'] === 'high'): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">High</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--text-muted);"><?php echo ucfirst($row['priority']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-xs font-semibold">
                                        <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--primary);"><?php echo htmlspecialchars($row['tech_name']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'resolved'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Resolved</span>
                                        <?php elseif ($row['status'] === 'in_progress'): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In Progress</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-xs text-muted"><?php echo date('d M Y, h:i A', strtotime($row['date_created'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 3. USER-WISE SCHEDULED DEMOS REPORT -->
                <?php elseif ($report_type === 'user_demos'): ?>
                    <thead>
                        <tr>
                            <th>Demo ID</th>
                            <th>Lead ID & Company</th>
                            <th>Scheduled Date & Time</th>
                            <th>Mode</th>
                            <th>Assigned Engineer</th>
                            <th>Rating & Feedback</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-6">No scheduled demos found matching the specified parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-bold text-xs text-primary"><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td>
                                        <div class="font-semibold text-sm"><?php echo htmlspecialchars($row['company'] ?? 'Client Lead'); ?></div>
                                        <div class="text-xs text-muted">Ref: <?php echo htmlspecialchars($row['lead_id']); ?></div>
                                    </td>
                                    <td class="text-xs font-semibold"><?php echo date('d M Y, h:i A', strtotime($row['scheduled_at'])); ?></td>
                                    <td class="text-xs"><?php echo htmlspecialchars($row['mode']); ?></td>
                                    <td class="text-xs font-semibold">
                                        <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--primary);"><?php echo htmlspecialchars($row['eng_name']); ?></span>
                                    </td>
                                    <td class="text-xs">
                                        <?php if (!empty($row['rating'])): ?>
                                            <div class="font-bold text-warning"><?php echo str_repeat('⭐', intval($row['rating'])); ?> (<?php echo $row['rating']; ?>/5)</div>
                                        <?php else: ?>
                                            <div class="text-muted">Not Rated Yet</div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['feedback'])): ?>
                                            <div class="text-muted" style="font-style: italic;"><?php echo htmlspecialchars(substr($row['feedback'], 0, 50)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Completed</span>
                                        <?php elseif ($row['status'] === 'cancelled'): ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Cancelled</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Scheduled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 4. USER-WISE FOLLOW-UPS REPORT -->
                <?php elseif ($report_type === 'user_followups'): ?>
                    <thead>
                        <tr>
                            <th>FUP ID</th>
                            <th>Lead ID & Company</th>
                            <th>Action Type</th>
                            <th>Scheduled Date & Time</th>
                            <th>Assigned Exec</th>
                            <th>Remarks / Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-6">No follow-ups recorded matching the specified parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-bold text-xs text-primary">#<?php echo $row['id']; ?></td>
                                    <td>
                                        <div class="font-semibold text-sm"><?php echo htmlspecialchars($row['company'] ?? 'Client Lead'); ?></div>
                                        <div class="text-xs text-muted">Ref: <?php echo htmlspecialchars($row['lead_id']); ?></div>
                                    </td>
                                    <td class="text-xs font-bold text-main"><?php echo ucfirst(htmlspecialchars($row['action_type'])); ?></td>
                                    <td class="text-xs font-semibold"><?php echo date('d M Y, h:i A', strtotime($row['scheduled_at'])); ?></td>
                                    <td class="text-xs font-semibold">
                                        <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--primary);"><?php echo htmlspecialchars($row['exec_name']); ?></span>
                                    </td>
                                    <td class="text-xs text-muted"><?php echo htmlspecialchars($row['remarks'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Completed</span>
                                        <?php elseif ($row['status'] === 'missed'): ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Missed</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 5. USER-WISE INSTALLATIONS REPORT -->
                <?php elseif ($report_type === 'user_installations'): ?>
                    <thead>
                        <tr>
                            <th>Installation ID</th>
                            <th>Customer & City</th>
                            <th>Assigned Engineer</th>
                            <th>Scheduled Date</th>
                            <th>Checklist Progress</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-6">No software installations found for the selected parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-bold text-xs text-primary"><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td>
                                        <div class="font-semibold text-sm"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['city']); ?></div>
                                    </td>
                                    <td class="text-xs font-semibold">
                                        <span class="badge" style="--badge-bg: var(--bg-body); --badge-color: var(--primary);"><?php echo htmlspecialchars($row['eng_name']); ?></span>
                                    </td>
                                    <td class="text-xs font-semibold"><?php echo date('d M Y, h:i A', strtotime($row['scheduled_date'])); ?></td>
                                    <td class="text-xs font-bold">
                                        <?php echo $row['checklist_done'] . ' / ' . $row['checklist_total']; ?> Steps Done
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'completed'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Completed</span>
                                        <?php elseif ($row['status'] === 'in_progress'): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In Progress</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 6. CONVERSIONS BY SOURCE -->
                <?php elseif ($report_type === 'conversions'): ?>
                    <thead>
                        <tr>
                            <th>Lead Acquisition Channel</th>
                            <th>Leads Registered</th>
                            <th>Closed Won Conversions</th>
                            <th>Closed Lost Count</th>
                            <th>Conversion Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-6">No lead conversions recorded inside the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-semibold text-sm"><?php echo htmlspecialchars($row['channel']); ?></td>
                                    <td class="text-sm font-semibold"><?php echo number_format($row['total_leads']); ?></td>
                                    <td class="font-bold text-sm text-success"><?php echo number_format($row['won_leads']); ?></td>
                                    <td class="text-sm text-danger"><?php echo number_format($row['lost_leads']); ?></td>
                                    <td class="font-bold text-sm text-primary">
                                        <?php 
                                        $total = max(1, $row['total_leads']);
                                        echo round(($row['won_leads'] / $total) * 100, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 7. WEEKLY SALES & COLLECTIONS -->
                <?php elseif ($report_type === 'sales'): ?>
                    <thead>
                        <tr>
                            <th>Week Range</th>
                            <th>Invoices Raised</th>
                            <th>Total Revenue (INR)</th>
                            <th>Total Received (INR)</th>
                            <th>Outstanding Balance (INR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-6">No sales collections recorded inside the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-semibold text-xs"><?php echo $row['week_start'] . " to " . $row['week_end']; ?></td>
                                    <td class="text-sm font-bold"><?php echo number_format($row['invoices_count']); ?></td>
                                    <td class="text-sm text-main font-semibold">₹<?php echo number_format($row['total_revenue'], 2); ?></td>
                                    <td class="text-sm text-success font-semibold">₹<?php echo number_format($row['total_received'], 2); ?></td>
                                    <td class="text-sm text-warning font-semibold">₹<?php echo number_format($row['total_due'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 8. EMPLOYEE CONVERSION MATRIX -->
                <?php elseif ($report_type === 'performance'): ?>
                    <thead>
                        <tr>
                            <th>Sales Operator Name</th>
                            <th>Assigned Lead Count</th>
                            <th>Closed Won Conversions</th>
                            <th>Pipeline Value (INR)</th>
                            <th>Conversion Ratio %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-6">No operator performances mapped inside the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-semibold text-sm"><?php echo htmlspecialchars($row['operator']); ?></td>
                                    <td class="text-sm font-semibold"><?php echo number_format($row['total_leads']); ?></td>
                                    <td class="font-bold text-sm text-success"><?php echo number_format($row['won_leads']); ?></td>
                                    <td class="text-sm font-semibold">₹<?php echo number_format($row['total_pipeline_value'], 2); ?></td>
                                    <td class="font-bold text-sm text-primary">
                                        <?php 
                                        $total = max(1, $row['total_leads']);
                                        echo round(($row['won_leads'] / $total) * 100, 1) . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>

                <!-- 9. RENEWALS FORECAST -->
                <?php elseif ($report_type === 'renewals'): ?>
                    <thead>
                        <tr>
                            <th>Client Customer</th>
                            <th>Product Licensed</th>
                            <th>Expiry Date</th>
                            <th>Days Left</th>
                            <th>Renewal Fee (INR)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-6">No expiring product renewals found inside the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td class="font-semibold text-sm"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                    <td class="text-xs font-bold text-muted"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                    <td class="text-xs"><?php echo $row['expiry_date']; ?></td>
                                    <td class="text-sm font-bold <?php echo ($row['days_remaining'] < 5) ? 'text-danger' : 'text-main'; ?>">
                                        <?php echo $row['days_remaining']; ?> Days
                                    </td>
                                    <td class="text-sm font-semibold">₹<?php echo number_format($row['renewal_fee'], 2); ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'active'): ?>
                                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Active</span>
                                        <?php elseif ($row['status'] === 'grace'): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Grace Period</span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Expired</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
function setDateRange(rangeType) {
    const today = new Date();
    const formatDate = (d) => d.toISOString().split('T')[0];
    
    let fromDate = new Date();
    let toDate = new Date();

    if (rangeType === 'today') {
        fromDate = today;
        toDate = today;
    } else if (rangeType === 'yesterday') {
        fromDate.setDate(today.getDate() - 1);
        toDate.setDate(today.getDate() - 1);
    } else if (rangeType === 'this_week') {
        const day = today.getDay();
        const diff = today.getDate() - day + (day === 0 ? -6 : 1); // Monday
        fromDate = new Date(today.setDate(diff));
        toDate = new Date();
    } else if (rangeType === 'this_month') {
        fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
        toDate = new Date();
    } else if (rangeType === 'last_30') {
        fromDate.setDate(today.getDate() - 30);
        toDate = new Date();
    }

    document.getElementById('from_date_input').value = formatDate(fromDate);
    document.getElementById('to_date_input').value = formatDate(toDate);
    document.getElementById('reportFilterForm').submit();
}
</script>

<style>
@media print {
    .print-hidden {
        display: none !important;
    }
    .print-only {
        display: block !important;
    }
    .sidebar {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .app-wrapper {
        display: block !important;
    }
    .header {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
    }
}
</style>
