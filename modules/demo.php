<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$message = '';
$message_type = 'success';

// Handle POST actions for Demo Scheduling, Rescheduling, Cancelling, and Feedback Logging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    if ($post_action === 'schedule_demo') {
        $lead_id = trim($_POST['lead_id'] ?? '');
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $mode = trim($_POST['mode'] ?? 'Online (Google Meet)');
        $engineer = trim($_POST['engineer'] ?? 'Amit Sen');
        
        if (!empty($lead_id) && !empty($scheduled_at)) {
            try {
                $demo_id = 'DM-' . rand(100, 999);
                
                // 1. Insert into demos table
                $stmt = $pdo->prepare("INSERT INTO demos (id, lead_id, scheduled_at, mode, engineer, status) VALUES (?, ?, ?, ?, ?, 'scheduled')");
                $stmt->execute([$demo_id, $lead_id, $scheduled_at, $mode, $engineer]);
                
                // 2. Also insert into followups table so it appears in Upcoming & Past Follow-ups on Lead Details page
                $stmtFup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, 'Demo', ?, ?, ?, 'pending')");
                $stmtFup->execute([$lead_id, $scheduled_at, "Scheduled Demo Walkthrough ($mode)", $engineer]);

                // 3. Update Lead Status to demo_scheduled
                $stmtLead = $pdo->prepare("UPDATE leads SET status = 'demo_scheduled' WHERE id = ?");
                $stmtLead->execute([$lead_id]);
                
                // 4. Add timeline entry
                $stmtTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                $stmtTL->execute([$lead_id, $_SESSION['user_name'] ?? 'System', "Scheduled software demo ($demo_id) for $scheduled_at with $engineer."]);
                
                $message = "Demo ($demo_id) scheduled successfully!";
            } catch (PDOException $e) {
                $message = "Error scheduling demo: " . $e->getMessage();
                $message_type = 'danger';
            }
        } else {
            $message = "Please select a lead and date/time for the demo.";
            $message_type = 'danger';
        }
    } elseif ($post_action === 'reschedule_demo') {
        $demo_id = trim($_POST['demo_id'] ?? '');
        $lead_id = trim($_POST['lead_id'] ?? '');
        $new_date = trim($_POST['scheduled_at'] ?? '');
        $action_type = trim($_POST['action_type'] ?? 'Demo');
        $engineer = trim($_POST['engineer'] ?? '');
        $fup_notes = trim($_POST['fup_notes'] ?? '');
        
        if (!empty($demo_id) && !empty($new_date)) {
            try {
                if (strpos($demo_id, 'FUP-') === 0) {
                    $real_fup_id = intval(substr($demo_id, 4));
                    $stmt = $pdo->prepare("UPDATE followups SET scheduled_at = ?, action_type = ?, remarks = ?, assigned_to = ? WHERE id = ?");
                    $stmt->execute([$new_date, $action_type, $fup_notes, $engineer, $real_fup_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE demos SET scheduled_at = ?, mode = ?, engineer = ?, feedback = ? WHERE id = ?");
                    $stmt->execute([$new_date, $action_type, $engineer, $fup_notes, $demo_id]);
                    
                    // Update/Insert corresponding pending follow-up record for this lead
                    if (!empty($lead_id)) {
                        $chkFup = $pdo->prepare("SELECT id FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                        $chkFup->execute([$lead_id]);
                        $existFup = $chkFup->fetchColumn();
                        
                        if ($existFup) {
                            $updF = $pdo->prepare("UPDATE followups SET scheduled_at = ?, action_type = ?, remarks = ?, assigned_to = ? WHERE id = ?");
                            $updF->execute([$new_date, $action_type, $fup_notes ?: 'Rescheduled software demo', $engineer, $existFup]);
                        } else {
                            $insF = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                            $insF->execute([$lead_id, $action_type, $new_date, $fup_notes ?: 'Rescheduled software demo', $engineer]);
                        }
                    }
                }
                
                if (!empty($lead_id)) {
                    $stmtTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                    $stmtTL->execute([$lead_id, $_SESSION['user_name'] ?? 'System', "Rescheduled demo ($demo_id) to " . date('Y-m-d H:i', strtotime($new_date)) . ". Action: $action_type. Assigned: $engineer."]);
                }
                
                $message = "Demo ($demo_id) rescheduled successfully to " . date('M d, Y h:i A', strtotime($new_date)) . ".";
            } catch (PDOException $e) {
                $message = "Error rescheduling demo: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    } elseif ($post_action === 'cancel_demo') {
        $demo_id = trim($_POST['demo_id'] ?? '');
        $lead_id = trim($_POST['lead_id'] ?? '');
        $reason = trim($_POST['cancel_reason'] ?? 'Client requested cancellation.');
        
        if (!empty($demo_id)) {
            try {
                if (strpos($demo_id, 'FUP-') === 0) {
                    $real_fup_id = intval(substr($demo_id, 4));
                    $stmt = $pdo->prepare("UPDATE followups SET status = 'cancelled', remarks = CONCAT(IFNULL(remarks,''), ' [Cancelled: ', ?, ']') WHERE id = ?");
                    $stmt->execute([$reason, $real_fup_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE demos SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
                    $stmt->execute([$reason, $demo_id]);
                }
                
                if (!empty($lead_id)) {
                    $stmtTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                    $stmtTL->execute([$lead_id, $_SESSION['user_name'] ?? 'System', "Cancelled demo ($demo_id). Reason: $reason"]);
                }
                
                $message = "Demo ($demo_id) marked as cancelled.";
            } catch (PDOException $e) {
                $message = "Error cancelling demo: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    } elseif ($post_action === 'log_feedback') {
        $demo_id = trim($_POST['demo_id'] ?? '');
        $lead_id = trim($_POST['lead_id'] ?? '');
        $rating = intval($_POST['rating'] ?? 5);
        $feedback = trim($_POST['feedback'] ?? '');
        $new_lead_status = trim($_POST['lead_status'] ?? 'demo_completed');
        
        if (!empty($demo_id)) {
            try {
                if (strpos($demo_id, 'FUP-') === 0) {
                    $real_fup_id = intval(substr($demo_id, 4));
                    $stmt = $pdo->prepare("UPDATE followups SET status = 'completed', remarks = ? WHERE id = ?");
                    $stmt->execute(["Rating: $rating/5. Notes: $feedback", $real_fup_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE demos SET status = 'completed', rating = ?, feedback = ? WHERE id = ?");
                    $stmt->execute([$rating, $feedback, $demo_id]);
                }
                
                if (!empty($lead_id)) {
                    $stmtLead = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
                    $stmtLead->execute([$new_lead_status, $lead_id]);
                    
                    $stmtTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                    $stmtTL->execute([$lead_id, $_SESSION['user_name'] ?? 'System', "Completed product demo ($demo_id). Rating: $rating/5. Notes: $feedback"]);
                }
                
                $message = "Demo feedback saved successfully! Lead status updated.";
            } catch (PDOException $e) {
                $message = "Error saving feedback: " . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

$user_role = $_SESSION['user_role'] ?? 'Admin';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = (in_array($user_role, ['Super Admin', 'Admin', 'Regional Manager', 'Team Leader']));

// Fetch live Demos list from Database (merging demos table + followups table)
$demos = [];
if ($db_connected && $pdo) {
    try {
        $where_demos = [];
        $params_demos = [];
        $where_fups = [];
        $params_fups = [];

        if (!$is_admin && !empty($user_name)) {
            $where_demos[] = "(LOWER(TRIM(d.engineer)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(d.engineer, ', ', ','))) OR d.engineer LIKE ? OR LOWER(TRIM(l.assigned_to)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(l.assigned_to, ', ', ','))) OR l.assigned_to LIKE ?)";
            $params_demos = [$user_name, $user_name, '%' . $user_name . '%', $user_name, $user_name, '%' . $user_name . '%'];

            $where_fups[] = "(LOWER(TRIM(f.assigned_to)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(f.assigned_to, ', ', ','))) OR f.assigned_to LIKE ? OR LOWER(TRIM(l.assigned_to)) = LOWER(TRIM(?)) OR FIND_IN_SET(LOWER(TRIM(?)), LOWER(REPLACE(l.assigned_to, ', ', ','))) OR l.assigned_to LIKE ?)";
            $params_fups = [$user_name, $user_name, '%' . $user_name . '%', $user_name, $user_name, '%' . $user_name . '%'];
        }

        $sql_where_demos = !empty($where_demos) ? "WHERE " . implode(" AND ", $where_demos) : "";
        $sql_where_fups = !empty($where_fups) ? "WHERE " . implode(" AND ", $where_fups) : "";

        // Query 1: Direct demos table
        $stmt1 = $pdo->prepare("
            SELECT d.id, d.lead_id, d.scheduled_at, d.mode, d.engineer, d.status, d.rating, d.feedback, d.cancel_reason, l.name as lead_name, l.company, l.phone, l.email 
            FROM demos d 
            LEFT JOIN leads l ON d.lead_id = l.id 
            {$sql_where_demos}
        ");
        $stmt1->execute($params_demos);
        $list1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // Query 2: All followups table records
        $stmt2 = $pdo->prepare("
            SELECT CONCAT('FUP-', f.id) as id, f.lead_id, f.scheduled_at, COALESCE(NULLIF(f.action_type, ''), 'Follow-up') as mode, f.assigned_to as engineer,
                CASE 
                    WHEN f.status = 'pending' THEN 'scheduled'
                    WHEN f.status = 'completed' THEN 'completed'
                    ELSE 'cancelled'
                END as status,
                5 as rating, f.remarks as feedback, f.remarks as cancel_reason,
                l.name as lead_name, l.company, l.phone, l.email
            FROM followups f
            LEFT JOIN leads l ON f.lead_id = l.id
            {$sql_where_fups}
        ");
        $stmt2->execute($params_fups);
        $list2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Merge deduplicating by lead_id + scheduled_at
        $seen = [];
        foreach ($list1 as $item) {
            $key = $item['lead_id'] . '_' . date('Y-m-d H:i', strtotime($item['scheduled_at']));
            $seen[$key] = true;
            $demos[] = $item;
        }
        foreach ($list2 as $item) {
            $key = $item['lead_id'] . '_' . date('Y-m-d H:i', strtotime($item['scheduled_at']));
            if (!isset($seen[$key])) {
                $demos[] = $item;
            }
        }

        // Sort by scheduled_at DESC
        usort($demos, function($a, $b) {
            return strtotime($b['scheduled_at']) - strtotime($a['scheduled_at']);
        });
    } catch (PDOException $e) {
        $demos = [];
    }
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

// Preserve original list for metric calculations
$all_demos = $demos;

// Active URL filter params handling
$active_filter = $_GET['filter'] ?? '';
$active_day = $_GET['day'] ?? '';
$active_status = $_GET['status'] ?? '';
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

    $filtered_demos = [];
    foreach ($all_demos as $dm) {
        $dm_timestamp = strtotime($dm['scheduled_at']);
        $dm_date = date('Y-m-d', $dm_timestamp);
        $is_demo_record = (strpos($dm['id'], 'DM-') === 0) || (preg_match('/demo|trail|trial|demonstration/i', ($dm['mode'] ?? '') . ' ' . ($dm['feedback'] ?? '')));

        if ($active_filter === 'demo_scheduled') {
            if ($is_demo_record && ($dm['status'] ?? '') === 'scheduled') {
                if ($active_day === 'all' || $active_day === 'total') {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'today' && $dm_date <= $today_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'tomorrow' && $dm_date === $tomorrow_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'next_day' && $dm_date === $nextday_str) {
                    $filtered_demos[] = $dm;
                }
            }
        } elseif ($active_filter === 'expired') {
            $is_expiry_type = (preg_match('/expiry|renewal|trail|trial|expir|renew/i', ($dm['mode'] ?? '') . ' ' . ($dm['feedback'] ?? '')));
            $is_overdue_type = ($dm_date < $today_str) || (($dm['status'] ?? '') === 'missed') || (($dm['status'] ?? '') === 'cancelled');
            $is_expired_item = $is_expiry_type || $is_overdue_type;

            if ($is_expired_item) {
                if ($active_day === 'all' || $active_day === 'total') {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'today' && $dm_date <= $today_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'tomorrow' && $dm_date === $tomorrow_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'next_day' && $dm_date === $nextday_str) {
                    $filtered_demos[] = $dm;
                }
            }
        } elseif ($active_filter === 'callback') {
            if (($dm['status'] ?? '') === 'scheduled') {
                if ($active_day === 'all' || $active_day === 'total') {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'today' && $dm_date <= $today_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'tomorrow' && $dm_date === $tomorrow_str) {
                    $filtered_demos[] = $dm;
                } elseif ($active_day === 'next_day' && $dm_date === $nextday_str) {
                    $filtered_demos[] = $dm;
                }
            }
        }
    }
    $demos = $filtered_demos;
} elseif (!empty($active_status)) {
    $filter_label = ucfirst(str_replace('_', ' ', $active_status)) . ' Demos';
    $filtered_demos = [];
    foreach ($all_demos as $dm) {
        if (($dm['status'] ?? '') === $active_status) {
            if ($active_status === 'scheduled') {
                if (strtotime($dm['scheduled_at']) >= time()) {
                    $filtered_demos[] = $dm;
                }
            } else {
                $filtered_demos[] = $dm;
            }
        }
    }
    $demos = $filtered_demos;
}

// Pagination handling for Demos list
$total_demos = count($demos);
$limit_raw = $_GET['limit'] ?? '25';
$limit = ($limit_raw === 'all') ? 'all' : intval($limit_raw);
if ($limit !== 'all' && $limit <= 0) {
    $limit = 25;
}

$page_num = max(1, intval($_GET['p'] ?? 1));
$total_pages = 1;
$offset = 0;

if ($limit !== 'all') {
    $total_pages = ceil(max(1, $total_demos) / $limit);
    if ($page_num > $total_pages) {
        $page_num = max(1, $total_pages);
    }
    $offset = ($page_num - 1) * $limit;
    $paginated_demos = array_slice($demos, $offset, $limit);
} else {
    $paginated_demos = $demos;
}

function getDemoPageUrl($p, $limitVal) {
    $params = $_GET;
    $params['p'] = $p;
    $params['limit'] = $limitVal;
    return 'index.php?' . http_build_query($params);
}

// Helper functions for card button URLs & active styling
function getFilterUrl($filter_name, $day_name, $current_filter, $current_day) {
    if ($current_filter === $filter_name && $current_day === $day_name) {
        return 'index.php?page=demo'; // Toggle off
    }
    return 'index.php?page=demo&filter=' . urlencode($filter_name) . '&day=' . urlencode($day_name);
}

function getFilterStyle($filter_name, $day_name, $current_filter, $current_day) {
    if ($current_filter === $filter_name && $current_day === $day_name) {
        return 'outline: 2px solid var(--primary); outline-offset: 2px; background: rgba(0, 77, 64, 0.08); border-radius: 8px; transform: scale(1.04);';
    }
    return '';
}

// Fetch leads list for "Schedule New Demo" dropdown
$leads = [];
if ($db_connected && $pdo) {
    try {
        $stmtLeads = $pdo->query("SELECT id, name, company, phone FROM leads WHERE status != 'dropped' ORDER BY name ASC");
        $leads = $stmtLeads->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $leads = [];
    }
}

// Fetch system users for Host Engineer selection
$engineers = [];
if ($db_connected && $pdo) {
    try {
        $stmtEng = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC");
        $engineers = $stmtEng->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $engineers = ['Harsh Vardhan', 'Amit Sen', 'Vikas Patel', 'Sonal Mehta', 'MOIN KHAN'];
    }
}
if (empty($engineers)) {
    $engineers = ['Harsh Vardhan', 'Amit Sen', 'Vikas Patel', 'Sonal Mehta', 'MOIN KHAN'];
}
?>

<div class="demos-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Product Demos & Feedback</h2>
            <p class="text-muted text-sm">Schedule software walkthroughs, capture technical assessments, rate customer interest levels, and log feedback notes.</p>
        </div>
        <button type="button" class="btn btn-primary text-sm flex align-center gap-2" style="padding: 0.65rem 1.25rem;" onclick="openScheduleModal()">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Schedule New Demo</span>
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
                    Total: <span id="cnt-demo-expired-total"><?php echo $expired_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('expired', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Today">
                    <span id="cnt-demo-expired-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Tomorrow">
                    <span id="cnt-demo-expired-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $expired_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('expired', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('expired', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Upcoming Expired Lead for Next Day">
                    <span id="cnt-demo-expired-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
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
                    Total: <span id="cnt-demo-dm-total"><?php echo $demo_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Today">
                    <span id="cnt-demo-dm-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Tomorrow">
                    <span id="cnt-demo-dm-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $demo_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('demo_scheduled', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Demo Scheduled for Next Day">
                    <span id="cnt-demo-dm-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
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
                    Total: <span id="cnt-demo-cb-total"><?php echo $callback_counts['total']; ?></span>
                </a>
            </div>
            <div style="display: flex; justify-content: space-around; text-align: center; align-items: center;">
                <a href="<?php echo getFilterUrl('callback', 'today', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'today', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Today">
                    <span id="cnt-demo-cb-today" style="background-color: #e53935; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['today']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Today</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'tomorrow', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Tomorrow">
                    <span id="cnt-demo-cb-tomorrow" style="background-color: #f57c00; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
                        <?php echo $callback_counts['tomorrow']; ?>
                    </span>
                    <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Tomorrow</span>
                </a>
                <a href="<?php echo getFilterUrl('callback', 'next_day', $active_filter, $active_day); ?>"
                   style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.35rem 0.6rem; border-radius: 6px; transition: all 0.2s ease; <?php echo getFilterStyle('callback', 'next_day', $active_filter, $active_day); ?>"
                   title="Click to filter Call Back for Next Day">
                    <span id="cnt-demo-cb-nextday" style="background-color: #ffb300; color: #ffffff; font-weight: 700; font-size: 0.9rem; padding: 0.25rem 0.85rem; border-radius: 4px; min-width: 44px; display: inline-block; text-align: center;">
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
            <a href="index.php?page=demo" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 0.35rem 0.85rem; background: var(--bg-card);">
                <i data-lucide="x" style="width: 12px; height: 12px;"></i>
                <span>Clear Filter</span>
            </a>
        </div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.85rem;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Demos table listing -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Demo ID</th>
                        <th>Client / Lead File</th>
                        <th>Scheduled Time</th>
                        <th>Mode / Action</th>
                        <th>Host Engineer</th>
                        <th>Rating / Notes</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($demos)): ?>
                        <tr>
                            <td colspan="8" class="text-center p-6 text-muted">
                                No software product demos recorded in the database yet. Click "Schedule New Demo" to add one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paginated_demos as $dm): ?>
                            <tr>
                                <td class="font-bold text-xs" style="vertical-align: middle;"><?php echo htmlspecialchars($dm['id']); ?></td>
                                <td style="vertical-align: middle;">
                                    <div class="flex flex-col">
                                        <span class="font-semibold text-sm"><?php echo htmlspecialchars($dm['lead_name'] ?? $dm['company'] ?? 'Unknown Lead'); ?></span>
                                        <a href="index.php?page=lead_details&id=<?php echo htmlspecialchars($dm['lead_id']); ?>" class="text-xs text-primary hover-underline">
                                            View Lead File (<?php echo htmlspecialchars($dm['lead_id']); ?>)
                                        </a>
                                    </div>
                                </td>
                                <td class="text-sm font-semibold" style="vertical-align: middle;">
                                    <?php echo date('M d, Y h:i A', strtotime($dm['scheduled_at'])); ?>
                                </td>
                                <td class="text-xs" style="vertical-align: middle;"><?php echo htmlspecialchars($dm['mode']); ?></td>
                                <td class="text-sm" style="vertical-align: middle;"><?php echo htmlspecialchars($dm['engineer']); ?></td>
                                <td class="text-xs text-muted" style="vertical-align: middle;">
                                    <?php if ($dm['status'] === 'completed'): ?>
                                        <div class="flex align-center gap-1 font-semibold text-warning mb-0.5">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i data-lucide="star" style="width: 12px; height: 12px; <?php echo ($i <= ($dm['rating'] ?? 5)) ? 'fill: var(--warning);' : 'opacity: 0.3;'; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="ml-1 text-xs text-muted">(<?php echo $dm['rating'] ?? 5; ?>/5)</span>
                                        </div>
                                        <span class="text-xs text-muted" title="<?php echo htmlspecialchars($dm['feedback'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(substr($dm['feedback'] ?? 'Demo completed', 0, 45)); ?><?php echo (strlen($dm['feedback'] ?? '') > 45) ? '...' : ''; ?>
                                        </span>
                                    <?php elseif ($dm['status'] === 'cancelled'): ?>
                                        <span class="text-xs text-danger">Reason: <?php echo htmlspecialchars($dm['cancel_reason'] ?? 'Cancelled'); ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">Upcoming Walkthrough</span>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align: middle;">
                                    <?php 
                                    if ($dm['status'] === 'scheduled') {
                                        echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Scheduled</span>';
                                    } elseif ($dm['status'] === 'completed') {
                                        echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Completed</span>';
                                    } else {
                                        echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Cancelled</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right; vertical-align: middle;">
                                    <div class="flex justify-end gap-1">
                                        <?php if ($dm['status'] === 'scheduled'): ?>
                                            <button type="button" class="btn btn-secondary text-xs reschedule-btn" style="padding: 0.35rem 0.75rem; cursor: pointer;" 
                                                data-id="<?php echo htmlspecialchars($dm['id']); ?>" 
                                                data-lead-id="<?php echo htmlspecialchars($dm['lead_id'] ?? ''); ?>" 
                                                data-scheduled="<?php echo date('Y-m-d\TH:i', strtotime($dm['scheduled_at'])); ?>" 
                                                data-mode="<?php echo htmlspecialchars($dm['mode'] ?? 'Trail Installed'); ?>" 
                                                data-engineer="<?php echo htmlspecialchars($dm['engineer'] ?? ''); ?>" 
                                                data-notes="<?php echo htmlspecialchars($dm['feedback'] ?? ''); ?>" 
                                                data-lead-name="<?php echo htmlspecialchars($dm['lead_name'] ?? $dm['company'] ?? 'Lead'); ?>">
                                                Reschedule
                                            </button>
                                            <button type="button" class="btn btn-danger text-xs cancel-btn" style="padding: 0.35rem 0.75rem; cursor: pointer;" 
                                                data-id="<?php echo htmlspecialchars($dm['id']); ?>" 
                                                data-lead-id="<?php echo htmlspecialchars($dm['lead_id'] ?? ''); ?>" 
                                                data-lead-name="<?php echo htmlspecialchars($dm['lead_name'] ?? $dm['company'] ?? 'Lead'); ?>">
                                                Cancel
                                            </button>
                                            <button type="button" class="btn btn-primary text-xs feedback-btn" style="padding: 0.35rem 0.75rem; cursor: pointer;" 
                                                data-id="<?php echo htmlspecialchars($dm['id']); ?>" 
                                                data-lead-id="<?php echo htmlspecialchars($dm['lead_id'] ?? ''); ?>" 
                                                data-lead-name="<?php echo htmlspecialchars($dm['lead_name'] ?? $dm['company'] ?? 'Lead'); ?>">
                                                Log Feedback
                                            </button>
                                        <?php elseif ($dm['status'] === 'completed'): ?>
                                            <button type="button" class="btn btn-secondary text-xs review-notes-btn" style="padding: 0.35rem 0.75rem; cursor: pointer;" 
                                                data-rating="<?php echo htmlspecialchars($dm['rating'] ?? '5'); ?>" 
                                                data-notes="<?php echo htmlspecialchars($dm['feedback'] ?? 'None'); ?>">
                                                Review Notes
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-muted">Cancelled</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Pagination Info row -->
        <?php
        $start_num = $limit === 'all' ? 1 : (($page_num - 1) * $limit + 1);
        $end_num = $limit === 'all' ? $total_demos : min($total_demos, $page_num * $limit);
        if ($total_demos == 0) {
            $start_num = 0;
            $end_num = 0;
        }
        ?>
        <div class="flex justify-between align-center p-4 border-top flex-wrap gap-4" style="border-top: 1px solid var(--border-color); background-color: var(--border-card);">
            <div class="flex align-center gap-3">
                <span class="text-xs text-muted">Showing <?php echo $start_num; ?> to <?php echo $end_num; ?> of <?php echo $total_demos; ?> demos</span>
                <span class="text-xs text-muted">|</span>
                <span class="text-xs text-muted">Show:</span>
                <select class="form-control text-xs" style="width: auto; padding: 0.2rem 0.5rem; height: 28px;" onchange="window.location.href = this.value;">
                    <option value="<?php echo getDemoPageUrl(1, 25); ?>" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                    <option value="<?php echo getDemoPageUrl(1, 50); ?>" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                    <option value="<?php echo getDemoPageUrl(1, 100); ?>" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                    <option value="<?php echo getDemoPageUrl(1, 250); ?>" <?php echo $limit == 250 ? 'selected' : ''; ?>>250 per page</option>
                    <option value="<?php echo getDemoPageUrl(1, 500); ?>" <?php echo $limit == 500 ? 'selected' : ''; ?>>500 per page</option>
                    <option value="<?php echo getDemoPageUrl(1, 'all'); ?>" <?php echo $limit === 'all' ? 'selected' : ''; ?>>View All</option>
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
                        <a href="<?php echo getDemoPageUrl($page_num - 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block;">Prev</a>
                    <?php else: ?>
                        <button class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem;" disabled>Prev</button>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): 
                        $isCurrent = ($i === $page_num);
                        $btnClass = $isCurrent ? 'btn-primary' : 'btn-secondary';
                        $style = $isCurrent ? 'background-color: var(--primary);' : '';
                    ?>
                        <a href="<?php echo getDemoPageUrl($i, $limit); ?>" class="btn <?php echo $btnClass; ?> text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block; <?php echo $style; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page_num < $total_pages): ?>
                        <a href="<?php echo getDemoPageUrl($page_num + 1, $limit); ?>" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem; text-decoration: none; display: inline-block;">Next</a>
                    <?php else: ?>
                        <button class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem;" disabled>Next</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL: Schedule New Demo -->
<div id="schedule-demo-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 550px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; color: var(--text-main);">
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h3 class="font-bold text-lg" style="font-family: var(--font-heading); margin: 0;">Schedule Software Demo</h3>
            <button type="button" class="btn-icon" onclick="closeScheduleModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
                <i data-lucide="x" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
            </button>
        </div>
        <form action="index.php?page=demo" method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="action" value="schedule_demo">
            
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold mb-1" style="display: block;">Select Target Lead <span class="text-danger">*</span></label>
                <select name="lead_id" class="form-control text-sm" required style="width: 100%; height: 38px; padding: 0.5rem;">
                    <option value="">-- Choose Lead --</option>
                    <?php foreach ($leads as $l): ?>
                        <option value="<?php echo htmlspecialchars($l['id']); ?>">
                            <?php echo htmlspecialchars($l['name']); ?> (<?php echo htmlspecialchars($l['company']); ?> - <?php echo htmlspecialchars($l['id']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1" style="display: block;">Demo Mode</label>
                    <select name="mode" class="form-control text-sm" style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="Online (Google Meet)">Online (Google Meet)</option>
                        <option value="Online (Zoom)">Online (Zoom)</option>
                        <option value="On-Site Visit">On-Site Visit</option>
                        <option value="Phone Call Walkthrough">Phone Call Walkthrough</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1" style="display: block;">Host Engineer / Executive</label>
                    <select name="engineer" class="form-control text-sm" style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="">-- Choose Host Engineer --</option>
                        <?php foreach ($engineers as $eng): 
                            $isSelected = (isset($_SESSION['user_name']) && $_SESSION['user_name'] === $eng);
                        ?>
                            <option value="<?php echo htmlspecialchars($eng); ?>" <?php echo $isSelected ? 'selected' : ''; ?>><?php echo htmlspecialchars($eng); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold mb-1" style="display: block;">Scheduled Date & Time <span class="text-danger">*</span></label>
                <input type="datetime-local" name="scheduled_at" class="form-control text-sm" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day 15:00')); ?>" style="width: 100%; height: 38px; padding: 0.5rem;">
            </div>

            <div class="flex justify-end gap-2 mt-4 pt-3" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" style="padding: 0.6rem 1.5rem;">Schedule Demo</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reschedule Demo & Follow-up -->
<div id="reschedule-demo-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 850px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; max-height: 90vh; color: var(--text-main);">
        <!-- Modal Header -->
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <h3 class="font-bold text-lg" id="reschedule-modal-title" style="font-family: var(--font-heading); margin: 0;">Reschedule Demo & Follow-up</h3>
                <p class="text-xs text-muted m-0" id="reschedule-modal-subtitle">Update schedule details and view history for client</p>
            </div>
            <button type="button" class="btn-icon" onclick="closeRescheduleModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
                <i data-lucide="x" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
            </button>
        </div>
        
        <!-- Modal Form Content -->
        <form action="index.php?page=demo" method="POST" style="overflow-y: auto; flex: 1; padding-right: 8px;">
            <input type="hidden" name="action" value="reschedule_demo">
            <input type="hidden" name="demo_id" id="reschedule-demo-id">
            <input type="hidden" name="lead_id" id="reschedule-lead-id">
            
            <div class="grid" style="grid-template-columns: 1.2fr 1fr; gap: 1.75rem; align-items: start;">
                <!-- Left Column: Schedule Follow-up Reminder Details -->
                <div>
                    <h4 class="text-sm font-bold text-main mb-3" style="font-family: var(--font-heading); margin-top: 0; display: flex; align-items: center; gap: 0.5rem; color: var(--primary);">
                        <i data-lucide="bell-ring" style="width: 16px; height: 16px;"></i>
                        Schedule Follow-up reminder
                    </h4>

                    <div class="grid mb-3" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; width: 100%;">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Date & Time <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="scheduled_at" id="reschedule-scheduled-at" class="form-control text-sm" required style="width: 100%; height: 36px; padding: 0.4rem 0.5rem;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Action Type</label>
                            <select name="action_type" id="reschedule-action-type" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                                <option value="Trail Installed">Trail Installed</option>
                                <option value="Demo">Demo</option>
                                <option value="Data Input Follow Up">Data Input Follow Up</option>
                                <option value="Payment Followup">Payment Followup</option>
                                <option value="Rest Amt Followup">Rest Amt Followup</option>
                                <option value="Phone Call Walkthrough">Phone Call Walkthrough</option>
                                <option value="On-Site Visit">On-Site Visit</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3" style="width: 100%;">
                        <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Assigned to / Host Engineer</label>
                        <select name="engineer" id="reschedule-engineer" class="form-control text-sm" style="width: 100%; height: 36px; padding: 0.5rem;">
                            <option value="">-- Choose Host Engineer / Unassigned --</option>
                            <?php foreach ($engineers as $eng): ?>
                                <option value="<?php echo htmlspecialchars($eng); ?>"><?php echo htmlspecialchars($eng); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3" style="width: 100%;">
                        <label class="form-label text-xs font-semibold" style="display: block; margin-bottom: 4px;">Reminder notes / Instructions</label>
                        <textarea name="fup_notes" id="reschedule-notes" class="form-control text-sm" style="width: 100%; min-height: 80px; height: 80px; resize: vertical; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);" placeholder="Add reminder notes or instructions..."></textarea>
                    </div>
                </div>

                <!-- Right Column: Upcoming & Past Follow-ups -->
                <div style="border-left: 1px solid var(--border-color); padding-left: 1.5rem; display: flex; flex-direction: column; height: 100%;">
                    <h4 class="text-xs font-bold text-muted mb-2" style="text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.5rem 0; display: flex; align-items: center; justify-content: space-between;">
                        <span>Upcoming & Past Follow-ups</span>
                        <span class="badge text-xs" id="reschedule-history-count" style="--badge-bg: var(--primary-light); --badge-color: var(--primary);">0 entries</span>
                    </h4>
                    <div id="reschedule-history-timeline" style="max-height: 230px; overflow-y: auto; padding-right: 0.5rem; display: flex; flex-direction: column; gap: 0.4rem;">
                        <p class="text-xs text-muted" style="font-style: italic;">Loading history...</p>
                    </div>
                </div>
            </div>
            
            <!-- Modal Actions Footer -->
            <div class="flex justify-end gap-3 mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn text-sm" onclick="closeRescheduleModal()" style="border-radius: 50px; border: 1.5px solid #004d40; background-color: transparent; color: #004d40; padding: 0.5rem 1.7rem; cursor: pointer; font-weight: 500; font-family: var(--font-heading); transition: all 0.2s ease;">Cancel</button>
                <button type="submit" class="btn text-sm" style="border-radius: 50px; border: none; background-color: #004d40; color: #ffffff; padding: 0.5rem 2.5rem; cursor: pointer; font-weight: 600; font-family: var(--font-heading); transition: all 0.2s ease;">Save Reschedule</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Cancel Demo -->
<div id="cancel-demo-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 480px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; color: var(--text-main);">
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h3 class="font-bold text-lg text-danger" style="font-family: var(--font-heading); margin: 0;">Cancel Software Demo</h3>
            <button type="button" class="btn-icon" onclick="closeCancelModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
                <i data-lucide="x" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
            </button>
        </div>
        <form action="index.php?page=demo" method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="action" value="cancel_demo">
            <input type="hidden" name="demo_id" id="cancel-demo-id">
            <input type="hidden" name="lead_id" id="cancel-lead-id">
            
            <p class="text-sm text-muted m-0" id="cancel-modal-subtitle">Are you sure you want to cancel the scheduled demo?</p>
            
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold mb-1" style="display: block;">Reason for Cancellation <span class="text-danger">*</span></label>
                <textarea name="cancel_reason" id="cancel-demo-reason" class="form-control text-sm" rows="3" required placeholder="Enter reason for demo cancellation..." style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);"></textarea>
            </div>
            
            <div class="flex justify-end gap-2 mt-4 pt-3" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="closeCancelModal()">Dismiss</button>
                <button type="submit" class="btn btn-danger text-sm" style="padding: 0.5rem 1.5rem;">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Log Demo Feedback -->
<div id="log-feedback-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(4px); transition: all 0.3s ease;">
    <div class="card p-6" style="width: 100%; max-width: 580px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); animation: scaleUp 0.3s ease-out; background: var(--bg-card); display: flex; flex-direction: column; color: var(--text-main);">
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h3 class="font-bold text-lg" style="font-family: var(--font-heading); margin: 0;">Record Demo Feedback</h3>
            <button type="button" class="btn-icon" onclick="closeFeedbackModal()" style="border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--border-radius-full);">
                <i data-lucide="x" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
            </button>
        </div>
        <form action="index.php?page=demo" method="POST" class="flex flex-col gap-4">
            <input type="hidden" name="action" value="log_feedback">
            <input type="hidden" name="demo_id" id="feedback-demo-id">
            <input type="hidden" name="lead_id" id="feedback-lead-id">
            
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold mb-1" style="display: block;">Target Client</label>
                <input type="text" id="feedback-client-name" class="form-control text-sm" readonly style="width: 100%; height: 38px; padding: 0.5rem; background-color: var(--bg-app);">
            </div>
            
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1" style="display: block;">Customer Rating (1 to 5 Stars)</label>
                    <select name="rating" class="form-control text-sm" style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="5">⭐⭐⭐⭐⭐ 5 Stars (Ready to Buy)</option>
                        <option value="4" selected>⭐⭐⭐⭐ 4 Stars (High Interest)</option>
                        <option value="3">⭐⭐⭐ 3 Stars (Needs Follow-up)</option>
                        <option value="2">⭐⭐ 2 Stars (Feature Gap)</option>
                        <option value="1">⭐ 1 Star (Not Interested)</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold mb-1" style="display: block;">Update Lead Status</label>
                    <select name="lead_status" class="form-control text-sm" style="width: 100%; height: 38px; padding: 0.5rem;">
                        <option value="demo_completed" selected>Demo Completed</option>
                        <option value="interested">Interested</option>
                        <option value="quotation_sent">Quotation Sent</option>
                        <option value="negotiation">Negotiation</option>
                        <option value="payment_pending">Payment Pending</option>
                        <option value="won">Closed Won</option>
                        <option value="lost">Closed Lost</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold mb-1" style="display: block;">Detailed Feedback Notes & Requirements <span class="text-danger">*</span></label>
                <textarea name="feedback" class="form-control text-sm" rows="4" required placeholder="Describe user count requirements, pharmacy/billing modules needed, customization notes..." style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); outline: none; background-color: var(--bg-app);"></textarea>
            </div>
            
            <div class="flex justify-end gap-2 mt-3 pt-3" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="closeFeedbackModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" style="padding: 0.6rem 1.75rem;">Save Feedback Logs</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('click', function(e) {
        const reschedBtn = e.target.closest('.reschedule-btn');
        if (reschedBtn) {
            e.preventDefault();
            const id = reschedBtn.getAttribute('data-id');
            const leadId = reschedBtn.getAttribute('data-lead-id');
            const scheduled = reschedBtn.getAttribute('data-scheduled');
            const mode = reschedBtn.getAttribute('data-mode');
            const engineer = reschedBtn.getAttribute('data-engineer');
            const notes = reschedBtn.getAttribute('data-notes');
            const leadName = reschedBtn.getAttribute('data-lead-name');
            
            openRescheduleModal(id, scheduled, leadId, mode, engineer, notes, leadName);
            return;
        }

        const cancelBtn = e.target.closest('.cancel-btn');
        if (cancelBtn) {
            e.preventDefault();
            const id = cancelBtn.getAttribute('data-id');
            const leadId = cancelBtn.getAttribute('data-lead-id');
            const leadName = cancelBtn.getAttribute('data-lead-name');
            
            openCancelModal(id, leadId, leadName);
            return;
        }

        const feedbackBtn = e.target.closest('.feedback-btn');
        if (feedbackBtn) {
            e.preventDefault();
            const id = feedbackBtn.getAttribute('data-id');
            const leadId = feedbackBtn.getAttribute('data-lead-id');
            const leadName = feedbackBtn.getAttribute('data-lead-name');
            
            openFeedbackModal(id, leadId, leadName);
            return;
        }

        const reviewBtn = e.target.closest('.review-notes-btn');
        if (reviewBtn) {
            e.preventDefault();
            const rating = reviewBtn.getAttribute('data-rating') || '5';
            const notes = reviewBtn.getAttribute('data-notes') || 'None';
            alert('Feedback Notes:\n\nRating: ' + rating + '/5\nNotes: ' + notes);
            return;
        }
    });

    function openScheduleModal() {
        const modal = document.getElementById('schedule-demo-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('open');
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeScheduleModal() {
        const modal = document.getElementById('schedule-demo-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('open');
            modal.style.display = 'none';
            modal.style.opacity = '0';
            modal.style.pointerEvents = 'none';
        }
    }

    function openRescheduleModal(id, datetime, leadId, actionType, engineer, remarks, leadName) {
        document.getElementById('reschedule-demo-id').value = id || '';
        document.getElementById('reschedule-lead-id').value = leadId || '';
        if (document.getElementById('reschedule-scheduled-at')) {
            document.getElementById('reschedule-scheduled-at').value = datetime || '';
        }
        if (document.getElementById('reschedule-action-type')) {
            document.getElementById('reschedule-action-type').value = actionType || 'Trail Installed';
        }
        if (document.getElementById('reschedule-engineer')) {
            document.getElementById('reschedule-engineer').value = engineer || '';
        }
        if (document.getElementById('reschedule-notes')) {
            document.getElementById('reschedule-notes').value = remarks || '';
        }
        if (document.getElementById('reschedule-modal-title')) {
            document.getElementById('reschedule-modal-title').innerHTML = `Reschedule Follow-up For <strong>${leadName || 'Client'}</strong>`;
        }

        // Render Follow-up History Timeline
        const historyTimeline = document.getElementById('reschedule-history-timeline');
        const historyCount = document.getElementById('reschedule-history-count');

        if (leadId) {
            if (historyTimeline) historyTimeline.innerHTML = '<p class="text-xs text-muted" style="font-style: italic;">Loading history...</p>';

            fetch('index.php?page=leads&action=get_lead_json&id=' + leadId)
            .then(response => response.json())
            .then(res => {
                if (res.success && res.followup_history && res.followup_history.length > 0) {
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
                                        ${fup.action_type || 'Follow-up'} (${fup.status})
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
            })
            .catch(err => {
                console.error(err);
                if (historyTimeline) historyTimeline.innerHTML = '<p class="text-xs text-muted" style="font-style: italic; font-size: 0.75rem;">Could not load history.</p>';
            });
        } else {
            if (historyCount) historyCount.textContent = '0 entries';
            if (historyTimeline) historyTimeline.innerHTML = '<p class="text-xs text-muted" style="font-style: italic; font-size: 0.75rem;">No lead record attached.</p>';
        }

        const modal = document.getElementById('reschedule-demo-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('open');
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeRescheduleModal() {
        const modal = document.getElementById('reschedule-demo-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('open');
            modal.style.display = 'none';
            modal.style.opacity = '0';
            modal.style.pointerEvents = 'none';
        }
    }

    function openCancelModal(id, leadId, leadName) {
        document.getElementById('cancel-demo-id').value = id || '';
        document.getElementById('cancel-lead-id').value = leadId || '';
        if (document.getElementById('cancel-modal-subtitle')) {
            document.getElementById('cancel-modal-subtitle').innerHTML = `Are you sure you want to cancel the demo for <strong>${leadName || 'Client'}</strong> (${id})?`;
        }
        const modal = document.getElementById('cancel-demo-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('open');
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeCancelModal() {
        const modal = document.getElementById('cancel-demo-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('open');
            modal.style.display = 'none';
            modal.style.opacity = '0';
            modal.style.pointerEvents = 'none';
        }
    }

    function openFeedbackModal(id, leadId, name) {
        document.getElementById('feedback-demo-id').value = id || '';
        document.getElementById('feedback-lead-id').value = leadId || '';
        document.getElementById('feedback-client-name').value = (name || 'Client') + ' (' + (leadId || 'N/A') + ')';
        const modal = document.getElementById('log-feedback-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('open');
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.pointerEvents = 'auto';
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeFeedbackModal() {
        const modal = document.getElementById('log-feedback-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('open');
            modal.style.display = 'none';
            modal.style.opacity = '0';
            modal.style.pointerEvents = 'none';
        }
    }
</script>
