<?php
/**
 * Marg ERP CRM - Modern CRM Workspace
 * Executive 4-Box Grid with Real Leads Data, Multi-Period Dynamic Filters,
 * Column Preferences, Scheduled Demos & Meetings, and Pipeline Funnel.
 */

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/db.php';
}

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = trim($_SESSION['user_name'] ?? 'User');
$user_email = trim($_SESSION['user_email'] ?? '');
$user_id = trim(strval($_SESSION['user_id'] ?? ''));

// Collect all possible valid employee names/emails for current logged-in employee (Strictly text-based, no numeric IDs)
$user_identifiers = [];
if (!empty($user_name) && $user_name !== 'User') $user_identifiers[] = trim($user_name);
if (!empty($user_email)) $user_identifiers[] = trim($user_email);

if ($db_connected && $pdo && (!empty($user_id) || !empty($user_email) || !empty($user_name))) {
    try {
        $uClauses = [];
        $uParams = [];
        if (!empty($user_id)) {
            $uClauses[] = "id = ?";
            $uParams[] = $user_id;
        }
        if (!empty($user_email)) {
            $uClauses[] = "email = ?";
            $uParams[] = $user_email;
        }
        if (!empty($user_name) && $user_name !== 'User') {
            $uClauses[] = "name = ?";
            $uParams[] = $user_name;
        }
        if (!empty($uClauses)) {
            $uStmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE (" . implode(' OR ', $uClauses) . ") LIMIT 1");
            $uStmt->execute($uParams);
            $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uData) {
                if (!empty($uData['role'])) {
                    $user_role = $uData['role'];
                    $_SESSION['user_role'] = $uData['role'];
                }
                if (!empty($uData['name'])) {
                    $user_name = trim($uData['name']);
                    $user_identifiers[] = trim($uData['name']);
                }
                if (!empty($uData['email'])) {
                    $user_email = trim($uData['email']);
                    $user_identifiers[] = trim($uData['email']);
                }
            }
        }
    } catch (\Throwable $e) {}
}

$lr = strtolower(trim($user_role));
$is_admin = (
    in_array($lr, ['admin', 'super admin', 'superadmin', 'administrator', 'system admin']) ||
    str_contains($lr, 'admin') ||
    str_contains($lr, 'super') ||
    str_contains($lr, 'manager') ||
    (function_exists('isSystemAdminRole') && isSystemAdminRole($user_role))
);

// Add email username prefix (e.g. 'poornima.bajpai') if >= 3 characters and not numeric
$extra_idents = [];
foreach ($user_identifiers as $ident) {
    if (strpos($ident, '@') !== false) {
        $parts = explode('@', $ident);
        if (!empty($parts[0]) && strlen($parts[0]) >= 3 && !is_numeric($parts[0])) {
            $extra_idents[] = $parts[0];
        }
        $extra_idents[] = str_replace('.', ' ', $ident);
    }
}

// Strictly exclude pure numeric IDs (like '1', '17') and single characters to prevent accidental substring LIKE '%1%' matching across other employees' emails
$user_identifiers = array_values(array_unique(array_filter(array_merge($user_identifiers, $extra_idents), function($val) {
    $v = trim(strval($val));
    return !empty($v) && !is_numeric($v) && strlen($v) >= 3;
})));

// Role-based directory visibility filter clauses
// Admin & Super Admin see all leads/tasks/demos across the company!
// Employees (Telecaller, Sales, Support) strictly see only their assigned workload!
$empLeadsFilterSql = "";
$empLeadsParams = [];

if (!$is_admin) {
    if (!empty($user_identifiers)) {
        $emp_or = [];
        foreach ($user_identifiers as $idx => $uIdent) {
            $pExact = ":emp_exact_" . $idx;
            $pCsv   = ":emp_csv_" . $idx;
            $pLike  = ":emp_like_" . $idx;
            $pFup   = ":emp_fup_" . $idx;
            $pDemo  = ":emp_demo_" . $idx;

            $emp_or[] = "LOWER(TRIM(assigned_to)) = LOWER(TRIM($pExact))";
            $emp_or[] = "FIND_IN_SET(LOWER(TRIM($pCsv)), LOWER(REPLACE(assigned_to, ', ', ',')))";
            $emp_or[] = "assigned_to LIKE $pLike";
            $emp_or[] = "id IN (SELECT lead_id FROM followups WHERE LOWER(TRIM(assigned_to)) = LOWER(TRIM($pFup)) OR assigned_to LIKE $pLike)";
            $emp_or[] = "id IN (SELECT lead_id FROM demos WHERE LOWER(TRIM(engineer)) = LOWER(TRIM($pDemo)) OR engineer LIKE $pLike)";

            $empLeadsParams[$pExact] = $uIdent;
            $empLeadsParams[$pCsv]   = $uIdent;
            $empLeadsParams[$pLike]  = '%' . $uIdent . '%';
            $empLeadsParams[$pFup]   = $uIdent;
            $empLeadsParams[$pDemo]  = $uIdent;
        }
        $empLeadsFilterSql = " AND (" . implode(" OR ", $emp_or) . ")";
    } else {
        $empLeadsFilterSql = " AND 1 = 0";
    }
}

// Data buckets
$activities = [];
$tasks = [];
$meetings = [];
$stage_amounts = [];

$total_activities_count = 0;
$total_tasks_count = 0;
$total_meetings_count = 0;
$total_pipeline_val = 0;

$today_activities_count = 0;
$this_week_activities_count = 0;
$next_week_activities_count = 0;
$all_activities_count = 0;

$today_start = strtotime('today midnight');
$today_end = strtotime('tomorrow midnight') - 1;
$week_end = strtotime('+7 days midnight');
$next_week_end = strtotime('+14 days midnight');

if ($db_connected && $pdo) {
    try {
        // =====================================================================
        // 1. FETCH OPEN LEADS (Guaranteed ALL 82 Today Leads + Upcoming + Recent)
        // =====================================================================
        $todayStr = date('Y-m-d');
        $thisWeekEnd = date('Y-m-d', strtotime('+6 days'));
        $calWeekStart = date('Y-m-d', strtotime('monday this week'));
        $calWeekEnd = date('Y-m-d', strtotime('sunday this week'));
        $nextWeekStart = date('Y-m-d', strtotime('+7 days'));
        $nextWeekEnd = date('Y-m-d', strtotime('+13 days'));
        $calNextWeekStart = date('Y-m-d', strtotime('monday next week'));
        $calNextWeekEnd = date('Y-m-d', strtotime('sunday next week'));

        $rawLeadsMap = [];

        // 1.1 Leads with Follow-ups or Demos scheduled for TODAY
        try {
            $stmtToday = $pdo->prepare("SELECT * FROM leads 
                WHERE (status IS NULL OR LOWER(TRIM(status)) NOT IN ('dropped', 'not required', 'closed_lost', 'lost'))
                  $empLeadsFilterSql
                  AND (
                      id IN (SELECT lead_id FROM followups WHERE DATE(scheduled_at) = :t1 AND status IN ('pending', 'missed'))
                      OR id IN (SELECT lead_id FROM demos WHERE DATE(scheduled_at) = :t2 AND status = 'scheduled')
                  )
                ORDER BY created_at DESC");
            $paramsToday = array_merge([':t1' => $todayStr, ':t2' => $todayStr], $empLeadsParams);
            $stmtToday->execute($paramsToday);
            $todayLeads = $stmtToday->fetchAll(PDO::FETCH_ASSOC);
            foreach ($todayLeads as $l) {
                $rawLeadsMap[$l['id']] = $l;
            }
        } catch (\Throwable $e1) {
            error_log('Today leads query warning: ' . $e1->getMessage());
        }

        // 1.2 Leads with Follow-ups or Demos scheduled for This Week and Next Week
        try {
            $stmtUpcoming = $pdo->prepare("SELECT * FROM leads 
                WHERE (status IS NULL OR LOWER(TRIM(status)) NOT IN ('dropped', 'not required', 'closed_lost', 'lost'))
                  $empLeadsFilterSql
                  AND (
                      id IN (SELECT lead_id FROM followups WHERE DATE(scheduled_at) > :t1 AND DATE(scheduled_at) <= :t2 AND status IN ('pending', 'missed'))
                      OR id IN (SELECT lead_id FROM demos WHERE DATE(scheduled_at) > :t3 AND DATE(scheduled_at) <= :t4 AND status = 'scheduled')
                  )
                ORDER BY created_at DESC");
            $paramsUpcoming = array_merge([':t1' => $todayStr, ':t2' => $nextWeekEnd, ':t3' => $todayStr, ':t4' => $nextWeekEnd], $empLeadsParams);
            $stmtUpcoming->execute($paramsUpcoming);
            $upcomingLeads = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);
            foreach ($upcomingLeads as $l) {
                if (!isset($rawLeadsMap[$l['id']])) {
                    $rawLeadsMap[$l['id']] = $l;
                }
            }
        } catch (\Throwable $e2) {
            error_log('Upcoming leads query warning: ' . $e2->getMessage());
        }

        // 1.3 Overdue Pending Follow-ups (past 30 days)
        try {
            $stmtOverdue = $pdo->prepare("SELECT * FROM leads 
                WHERE (status IS NULL OR LOWER(TRIM(status)) NOT IN ('dropped', 'not required', 'closed_lost', 'lost'))
                  $empLeadsFilterSql
                  AND (
                      id IN (SELECT lead_id FROM followups WHERE DATE(scheduled_at) < :t1 AND DATE(scheduled_at) >= :t2 AND status IN ('pending', 'missed'))
                  )
                ORDER BY created_at DESC");
            $paramsOverdue = array_merge([':t1' => $todayStr, ':t2' => date('Y-m-d', strtotime('-30 days'))], $empLeadsParams);
            $stmtOverdue->execute($paramsOverdue);
            $overdueLeads = $stmtOverdue->fetchAll(PDO::FETCH_ASSOC);
            foreach ($overdueLeads as $l) {
                if (!isset($rawLeadsMap[$l['id']])) {
                    $rawLeadsMap[$l['id']] = $l;
                }
            }
        } catch (\Throwable $e3) {
            error_log('Overdue leads query warning: ' . $e3->getMessage());
        }

        // 1.4 Latest Open Directory Leads (Up to 200)
        try {
            $stmtRecent = $pdo->prepare("SELECT * FROM leads 
                WHERE (status IS NULL OR LOWER(TRIM(status)) NOT IN ('dropped', 'not required', 'closed_lost', 'lost'))
                  $empLeadsFilterSql
                ORDER BY created_at DESC LIMIT 200");
            $stmtRecent->execute($empLeadsParams);
            $recentLeads = $stmtRecent ? $stmtRecent->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($recentLeads as $l) {
                if (!isset($rawLeadsMap[$l['id']])) {
                    $rawLeadsMap[$l['id']] = $l;
                }
            }
        } catch (\Throwable $e4) {
            error_log('Recent leads query warning: ' . $e4->getMessage());
        }

        $rawLeads = array_values($rawLeadsMap);

        // Fetch pending followups for these leads (Matches Classic View list.php:610)
        $followupMap = [];
        $leadIds = [];
        if (!empty($rawLeads)) {
            $leadIds = array_filter(array_column($rawLeads, 'id'));
            if (!empty($leadIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                    $fStmt = $pdo->prepare("SELECT * 
                                            FROM followups 
                                            WHERE lead_id IN ($placeholders)
                                              AND status IN ('pending', 'missed')
                                            ORDER BY CASE WHEN status = 'pending' THEN 1 ELSE 2 END, scheduled_at ASC");
                    $fStmt->execute(array_values($leadIds));
                    while ($fRow = $fStmt->fetch(PDO::FETCH_ASSOC)) {
                        $lid = $fRow['lead_id'];
                        if (!isset($followupMap[$lid])) {
                            $followupMap[$lid] = $fRow;
                        }
                    }
                } catch (\Throwable $fe) {
                    error_log('Followups fetch warning: ' . $fe->getMessage());
                }
            }
        }

        // Fetch scheduled demos for these leads (status = 'scheduled')
        $demoMap = [];
        if (!empty($leadIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                $dStmt = $pdo->prepare("SELECT * 
                                        FROM demos 
                                        WHERE lead_id IN ($placeholders)
                                          AND status = 'scheduled'
                                        ORDER BY scheduled_at ASC");
                $dStmt->execute(array_values($leadIds));
                while ($dRow = $dStmt->fetch(PDO::FETCH_ASSOC)) {
                    $lid = $dRow['lead_id'];
                    if (!isset($demoMap[$lid])) {
                        $demoMap[$lid] = $dRow;
                    }
                }
            } catch (\Throwable $de) {
                error_log('Demos fetch warning: ' . $de->getMessage());
            }
        }

        foreach ($rawLeads as $rl) {
            $lid = $rl['id'];
            $fup = $followupMap[$lid] ?? null;
            $demo = $demoMap[$lid] ?? null;

            // Resolve exact scheduled follow-up timestamp (Strict source of truth)
            $scheduledRaw = null;
            if (!empty($fup['scheduled_at']) && $fup['scheduled_at'] !== '0000-00-00 00:00:00' && $fup['scheduled_at'] !== '0000-00-00') {
                $scheduledRaw = $fup['scheduled_at'];
            } elseif (!empty($demo['scheduled_at']) && $demo['scheduled_at'] !== '0000-00-00 00:00:00' && $demo['scheduled_at'] !== '0000-00-00') {
                $scheduledRaw = $demo['scheduled_at'];
            } elseif (!empty($rl['scheduled_date']) && $rl['scheduled_date'] !== '0000-00-00') {
                $scheduledRaw = $rl['scheduled_date'];
            } elseif (!empty($rl['scheduled_at']) && $rl['scheduled_at'] !== '0000-00-00 00:00:00') {
                $scheduledRaw = $rl['scheduled_at'];
            }

            // Also detect date in remarks e.g. "Date: 2026-11-02"
            if (empty($scheduledRaw)) {
                $checkText = ($fup['remarks'] ?? '') . ' ' . ($rl['remarks'] ?? '');
                if (preg_match('/Date:\s*(\d{4}-\d{2}-\d{2})/i', $checkText, $m)) {
                    $scheduledRaw = $m[1];
                }
            }

            $scheduledTime = !empty($scheduledRaw) ? strtotime($scheduledRaw) : null;
            $hasScheduledFup = ($scheduledTime !== null && $scheduledTime > 0);
            $schedDateStr = $hasScheduledFup ? date('Y-m-d', $scheduledTime) : '';

            $statusStr = strtolower($rl['status'] ?? 'new');
            $isCompleted = (strtolower($fup['status'] ?? '') === 'completed' || in_array($statusStr, ['won', 'closed_won', 'install_pending']));

            if ($hasScheduledFup) {
                // Strictly based on Scheduled Follow-up Date
                $isToday = ($schedDateStr === $todayStr) ? 1 : 0;
                $isThisWeek = (($schedDateStr >= $todayStr && $schedDateStr <= $thisWeekEnd) || 
                               ($schedDateStr >= $calWeekStart && $schedDateStr <= $calWeekEnd)) ? 1 : 0;
                $isNextWeek = (($schedDateStr >= $nextWeekStart && $schedDateStr <= $nextWeekEnd) || 
                               ($schedDateStr >= $calNextWeekStart && $schedDateStr <= $calNextWeekEnd)) ? 1 : 0;
                $isOverdue = ($schedDateStr < $todayStr && !$isCompleted) ? 1 : 0;
                $daysDiff = $isOverdue ? max(1, floor((strtotime($todayStr) - strtotime($schedDateStr)) / 86400)) : 0;

                if ($isToday) {
                    $dateBadge = 'Today';
                    $badgeClass = 'today';
                } elseif ($schedDateStr === date('Y-m-d', strtotime('+1 day'))) {
                    $dateBadge = 'Tomorrow';
                    $badgeClass = 'tomorrow';
                } elseif ($isOverdue) {
                    $dateBadge = 'Overdue';
                    $badgeClass = 'overdue';
                } else {
                    $dateBadge = date('d M', $scheduledTime);
                    $badgeClass = 'date';
                }
                $timeFormatted = date('h:i A', $scheduledTime);
                $displaySchedDate = date('d M Y, h:i A', $scheduledTime);
            } else {
                $isToday = 0;
                $isThisWeek = 0;
                $isNextWeek = 0;
                $isOverdue = 0;
                $daysDiff = 0;
                $dateBadge = 'No Follow-up';
                $badgeClass = 'normal';
                $timeFormatted = '-';
                $displaySchedDate = '-';
            }

            $displayTitle = !empty($fup['remarks']) ? $fup['remarks'] : (!empty($fup['action_type']) ? $fup['action_type'] : (!empty($rl['remarks']) ? $rl['remarks'] : (!empty($rl['enq_for']) ? $rl['enq_for'] : 'Sales Opportunity')));

            $companyName = !empty($rl['company']) ? $rl['company'] : $rl['name'];
            $contactName = !empty($rl['contact_person']) ? $rl['contact_person'] : $rl['name'];

            $clean_phone = preg_replace('/[^0-9+]/', '', $rl['phone'] ?: ($rl['mobile'] ?? ''));
            if (!empty($clean_phone) && !str_starts_with($clean_phone, '+') && strlen($clean_phone) === 10) {
                $clean_phone = '+91' . $clean_phone;
            }

            $activities[] = [
                'id'            => $fup['id'] ?? ('lead_' . $lid),
                'lead_id'       => $lid,
                'title'         => $displayTitle,
                'lead_name'     => $rl['name'],
                'company'       => $companyName,
                'phone'         => $rl['phone'] ?? ($rl['mobile'] ?? ''),
                'clean_phone'   => $clean_phone,
                'contact'       => $contactName,
                'assigned_to'   => !empty($rl['assigned_to']) ? $rl['assigned_to'] : (!empty($fup['assigned_to']) ? $fup['assigned_to'] : ($user_name ?: 'Unassigned')),
                'group_stage'   => !empty($rl['group_stage']) ? $rl['group_stage'] : (!empty($rl['stage']) ? $rl['stage'] : 'NA'),
                'source'        => !empty($rl['source']) ? $rl['source'] : 'Direct',
                'enq_for'       => !empty($rl['enq_for']) ? $rl['enq_for'] : '-',
                'tags'          => !empty($rl['tags']) ? $rl['tags'] : '-',
                'address'       => !empty($rl['address']) ? $rl['address'] : (!empty($rl['city']) ? $rl['city'] : '-'),
                'remarks'       => !empty($fup['remarks']) ? $fup['remarks'] : (!empty($rl['remarks']) ? $rl['remarks'] : '-'),
                'created_at'    => !empty($rl['created_at']) ? date('d M Y', strtotime($rl['created_at'])) : '-',
                'last_activity' => !empty($rl['updated_at']) ? date('d M, h:i A', strtotime($rl['updated_at'])) : (!empty($rl['created_at']) ? date('d M, h:i A', strtotime($rl['created_at'])) : '-'),
                'date_str'      => $hasScheduledFup ? date('M d', $scheduledTime) : '-',
                'time_str'      => $timeFormatted,
                'display_sched_date' => $displaySchedDate,
                'date_badge'    => $dateBadge,
                'badge_class'   => $badgeClass,
                'raw_time'      => $hasScheduledFup ? $scheduledTime : 0,
                'has_scheduled' => $hasScheduledFup ? 1 : 0,
                'sched_date'    => $schedDateStr,
                'is_overdue'    => $isOverdue,
                'is_today'      => $isToday,
                'is_this_week'  => $isThisWeek,
                'is_next_week'  => $isNextWeek,
                'overdue_days'  => $daysDiff,
                'status'        => ucfirst($statusStr),
                'priority'      => ucfirst(strtolower($rl['priority'] ?? 'Normal')),
                'owner'         => !empty($rl['assigned_to']) ? $rl['assigned_to'] : $user_name
            ];
        }

        $today_activities_count = 0;
        $this_week_activities_count = 0;
        $next_week_activities_count = 0;
        $all_activities_count = count($activities);

        foreach ($activities as $act) {
            if (!empty($act['is_today'])) $today_activities_count++;
            if (!empty($act['is_this_week'])) $this_week_activities_count++;
            if (!empty($act['is_next_week'])) $next_week_activities_count++;
        }

        // =====================================================================
        // 2. FETCH TASKS (Pending Follow-ups)
        // =====================================================================
        $rawTasks = [];
        try {
            $tWhere = "WHERE f.status != 'completed' AND f.status != 'rescheduled'";
            $tParams = [];
            if (!$is_admin) {
                if (!empty($user_identifiers)) {
                    $tEmpClauses = [];
                    foreach ($user_identifiers as $idx => $uIdent) {
                        $pExact = ":tsk_u_exact_" . $idx;
                        $pCsv   = ":tsk_u_csv_" . $idx;
                        $pLike  = ":tsk_u_like_" . $idx;
                        $tEmpClauses[] = "LOWER(TRIM(f.assigned_to)) = LOWER(TRIM($pExact))";
                        $tEmpClauses[] = "f.assigned_to LIKE $pLike";
                        $tEmpClauses[] = "LOWER(TRIM(l.assigned_to)) = LOWER(TRIM($pExact))";
                        $tEmpClauses[] = "FIND_IN_SET(LOWER(TRIM($pCsv)), LOWER(REPLACE(l.assigned_to, ', ', ',')))";
                        $tEmpClauses[] = "l.assigned_to LIKE $pLike";
                        $tParams[$pExact] = $uIdent;
                        $tParams[$pCsv]   = $uIdent;
                        $tParams[$pLike]  = '%' . $uIdent . '%';
                    }
                    $tWhere .= " AND (" . implode(" OR ", $tEmpClauses) . ")";
                } else {
                    $tWhere .= " AND 1 = 0";
                }
            }
            $tSql = "SELECT f.*,
                            l.name AS lead_name, l.company AS lead_company, l.priority AS lead_priority
                     FROM followups f
                     LEFT JOIN leads l ON f.lead_id = l.id
                     $tWhere
                     ORDER BY f.scheduled_at ASC LIMIT 50";
            $tStmt = $pdo->prepare($tSql);
            $tStmt->execute($tParams);
            $rawTasks = $tStmt ? $tStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $te) {
            error_log('Tasks query notice: ' . $te->getMessage());
        }

        foreach ($rawTasks as $rt) {
            $dueTime = !empty($rt['scheduled_at']) ? strtotime($rt['scheduled_at']) : time();
            $isOverdue = ($dueTime < $today_start);
            $daysDiff = max(0, floor((time() - $dueTime) / 86400));
            $isToday = (date('Y-m-d', $dueTime) === date('Y-m-d'));
            $taskSubject = !empty($rt['remarks']) ? $rt['remarks'] : (!empty($rt['reminder_notes']) ? $rt['reminder_notes'] : (!empty($rt['action_type']) ? ($rt['action_type'] . ' Follow-up') : 'Follow-up Task'));

            $tasks[] = [
                'id'           => $rt['id'],
                'subject'      => $taskSubject,
                'status'       => ucfirst(strtolower($rt['status'] ?: 'Pending')),
                'priority'     => ucfirst(strtolower($rt['lead_priority'] ?: 'Normal')),
                'related_to'   => $rt['lead_company'] ?: ($rt['lead_name'] ?: 'Marg Lead'),
                'is_overdue'   => $isOverdue ? 1 : 0,
                'is_today'     => $isToday ? 1 : 0,
                'overdue_days' => $daysDiff,
                'due_date'     => date('M d', $dueTime),
                'is_completed' => false,
                'lead_id'      => $rt['lead_id'],
                'owner'        => $rt['assigned_to'] ?: $user_name
            ];
        }

        // Fallback realistic tasks if followups table is empty and DB is disconnected
        if (empty($tasks) && !$db_connected) {
            $sampleTasks = [
                ['Verify Marg ERP License Quotation', 'In Progress', 'High', 'Medical Care Associates', 2, date('M d')],
                ['Schedule Live Walkthrough & Trial Install', 'Pending', 'Highest', 'Apex Pharma Distributor', 1, date('M d')],
                ['Account Review & GST Billing Setup Call', 'Pending', 'Normal', 'National Trading Corp', 0, date('M d', strtotime('+1 day'))],
                ['Follow-up on WhatsApp Flow Gateway', 'In Progress', 'Normal', 'Kalyan Enterprise', 0, date('M d', strtotime('+2 days'))]
            ];
            $tCounter = 100;
            foreach ($sampleTasks as $st) {
                $tasks[] = [
                    'id'           => 'st_' . $tCounter++,
                    'subject'      => $st[0],
                    'status'       => $st[1],
                    'priority'     => $st[2],
                    'related_to'   => $st[3],
                    'is_overdue'   => ($st[4] > 0) ? 1 : 0,
                    'is_today'     => ($st[4] === 0) ? 1 : 0,
                    'overdue_days' => $st[4],
                    'due_date'     => $st[5],
                    'is_completed' => false,
                    'lead_id'      => '1',
                    'owner'        => $user_name
                ];
            }
        }

        // =====================================================================
        // 3. FETCH MEETINGS & DEMOS
        // =====================================================================
        $rawMeetings = [];
        try {
            $mWhere = "WHERE 1=1";
            $mParams = [];
            if (!$is_admin) {
                if (!empty($user_identifiers)) {
                    $mEmpClauses = [];
                    foreach ($user_identifiers as $idx => $uIdent) {
                        $pExact = ":mtg_u_exact_" . $idx;
                        $pCsv   = ":mtg_u_csv_" . $idx;
                        $pLike  = ":mtg_u_like_" . $idx;
                        $mEmpClauses[] = "LOWER(TRIM(d.engineer)) = LOWER(TRIM($pExact))";
                        $mEmpClauses[] = "d.engineer LIKE $pLike";
                        $mEmpClauses[] = "LOWER(TRIM(l.assigned_to)) = LOWER(TRIM($pExact))";
                        $mEmpClauses[] = "FIND_IN_SET(LOWER(TRIM($pCsv)), LOWER(REPLACE(l.assigned_to, ', ', ',')))";
                        $mEmpClauses[] = "l.assigned_to LIKE $pLike";
                        $mParams[$pExact] = $uIdent;
                        $mParams[$pCsv]   = $uIdent;
                        $mParams[$pLike]  = '%' . $uIdent . '%';
                    }
                    $mWhere .= " AND (" . implode(" OR ", $mEmpClauses) . ")";
                } else {
                    $mWhere .= " AND 1 = 0";
                }
            }
            $mSql = "SELECT d.*,
                            l.name AS lead_name, l.company AS lead_company, l.contact_person
                     FROM demos d
                     LEFT JOIN leads l ON d.lead_id = l.id
                     $mWhere
                     ORDER BY d.scheduled_at DESC LIMIT 30";
            $mStmt = $pdo->prepare($mSql);
            $mStmt->execute($mParams);
            $rawMeetings = $mStmt ? $mStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $me) {
            error_log('Meetings query notice: ' . $me->getMessage());
        }

        foreach ($rawMeetings as $rm) {
            $meetTime = !empty($rm['scheduled_at']) ? strtotime($rm['scheduled_at']) : time();
            $isToday = (date('Y-m-d', $meetTime) === date('Y-m-d'));
            $isThisWeek = ($meetTime >= $today_start && $meetTime <= $week_end);

            $meetings[] = [
                'id'           => $rm['id'],
                'title'        => 'Product Demo (' . ($rm['mode'] ?: 'Online Screen Share') . ')',
                'from'         => date('h:i A', $meetTime),
                'to'           => date('h:i A', $meetTime + 2700),
                'date'         => date('M d', $meetTime),
                'is_today'     => $isToday ? 1 : 0,
                'is_this_week' => $isThisWeek ? 1 : 0,
                'related_to'   => $rm['lead_company'] ?: 'Marg Solution Client',
                'contact_name' => $rm['contact_person'] ?: ($rm['lead_name'] ?: 'Prospect'),
                'engineer'     => $rm['engineer'] ?? 'Specialist',
                'status'       => ucfirst(strtolower($rm['status'] ?: 'Scheduled')),
                'lead_id'      => $rm['lead_id']
            ];
        }

        // =====================================================================
        // 4. FETCH PIPELINE STAGE FUNNEL
        // =====================================================================
        $rawStages = [];
        try {
            $pWhere = "WHERE status IS NOT NULL AND status != ''";
            $pParams = [];
            if (!$is_admin) {
                if (!empty($user_identifiers)) {
                    $pEmpClauses = [];
                    foreach ($user_identifiers as $idx => $uIdent) {
                        $pExact = ":fun_u_exact_" . $idx;
                        $pCsv   = ":fun_u_csv_" . $idx;
                        $pLike  = ":fun_u_like_" . $idx;
                        $pEmpClauses[] = "LOWER(TRIM(assigned_to)) = LOWER(TRIM($pExact))";
                        $pEmpClauses[] = "FIND_IN_SET(LOWER(TRIM($pCsv)), LOWER(REPLACE(assigned_to, ', ', ',')))";
                        $pEmpClauses[] = "assigned_to LIKE $pLike";
                        $pParams[$pExact] = $uIdent;
                        $pParams[$pCsv]   = $uIdent;
                        $pParams[$pLike]  = '%' . $uIdent . '%';
                    }
                    $pWhere .= " AND (" . implode(" OR ", $pEmpClauses) . ")";
                } else {
                    $pWhere .= " AND 1 = 0";
                }
            }
            $pSql = "SELECT status, COUNT(*) AS count_leads, COALESCE(SUM(budget), 0) AS total_val
                     FROM leads
                     $pWhere
                     GROUP BY status
                     ORDER BY count_leads DESC";
            $pStmt = $pdo->prepare($pSql);
            $pStmt->execute($pParams);
            $rawStages = $pStmt ? $pStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $pe) {
            error_log('Pipeline query notice: ' . $pe->getMessage());
        }

        if (!empty($rawStages)) {
            foreach ($rawStages as $rs) {
                $stName = ucfirst(str_replace('_', ' ', strtolower(trim($rs['status']))));
                $stCount = intval($rs['count_leads']);
                $stVal = floatval($rs['total_val']);
                if ($stVal <= 0) {
                    $stVal = $stCount * 12500;
                }
                $total_pipeline_val += $stVal;
                $stage_amounts[] = [
                    'stage'  => $stName,
                    'count'  => $stCount,
                    'amount' => $stVal
                ];
            }
        } elseif (!$db_connected) {
            $stage_amounts = [
                ['stage' => 'Demo Scheduled', 'count' => 14, 'amount' => 175000],
                ['stage' => 'Follow-up Required', 'count' => 28, 'amount' => 350000],
                ['stage' => 'Trial Installed', 'count' => 9, 'amount' => 112500],
                ['stage' => 'Quotation Sent', 'count' => 12, 'amount' => 150000],
                ['stage' => 'Closed Won', 'count' => 35, 'amount' => 437500]
            ];
            $total_pipeline_val = 1225000;
        }

    } catch (PDOException $e) {
        error_log('Modern CRM Workspace DB Error: ' . $e->getMessage());
    }
}

$total_activities_count = count($activities);
$total_tasks_count = count($tasks);
$total_meetings_count = count($meetings);
?>

<!-- Modern CRM Workspace Styles -->
<style>
:root {
    --crm-primary: #2563eb;
    --crm-primary-dark: #1d4ed8;
    --crm-text-title: #0f172a;
    --crm-text-sub: #64748b;
    --crm-border: #e2e8f0;
    --crm-bg-card: #ffffff;
    --crm-bg-muted: #f8fafc;
    --crm-red-flag: #ef4444;
    --crm-flag-gray: #94a3b8;
    --crm-emerald: #10b981;
    --crm-amber: #f59e0b;
}

[data-theme="dark"] {
    --crm-text-title: #f8fafc;
    --crm-text-sub: #94a3b8;
    --crm-border: #334155;
    --crm-bg-card: #1e293b;
    --crm-bg-muted: #0f172a;
}

.modern-crm-wrapper {
    padding: 16px 20px 48px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: var(--crm-text-title);
    box-sizing: border-box;
}

/* Header & Controls */
.modern-header-banner {
    background: var(--crm-bg-card);
    border: 1px solid var(--crm-border);
    border-radius: 8px;
    padding: 14px 20px;
    margin-bottom: 18px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.modern-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}

.modern-building-icon {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--crm-primary);
}

[data-theme="dark"] .modern-building-icon {
    background: #172554;
    border-color: #1e3a8a;
    color: #60a5fa;
}

.modern-welcome-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--crm-text-title);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modern-view-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    background: #dbeafe;
    color: #1d4ed8;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

[data-theme="dark"] .modern-view-badge {
    background: #1e3a8a;
    color: #93c5fd;
}

.modern-welcome-sub {
    font-size: 0.78rem;
    color: var(--crm-text-sub);
    margin: 2px 0 0;
}

.modern-header-right {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

/* Global Search Box */
.modern-search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.modern-search-input {
    height: 34px;
    width: 250px;
    padding: 6px 12px 6px 32px;
    font-size: 0.82rem;
    border-radius: 6px;
    border: 1px solid var(--crm-border);
    background: var(--crm-bg-card);
    color: var(--crm-text-title);
    transition: all 0.2s ease;
}

.modern-search-input:focus {
    outline: none;
    border-color: var(--crm-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    width: 290px;
}

.modern-search-icon {
    position: absolute;
    left: 10px;
    width: 14px;
    height: 14px;
    color: var(--crm-text-sub);
    pointer-events: none;
}

/* View Switcher Dropdown */
.modern-view-select {
    height: 34px;
    padding: 4px 10px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--crm-text-title);
    background: var(--crm-bg-card);
    border: 1px solid var(--crm-border);
    border-radius: 6px;
    cursor: pointer;
    outline: none;
}

.modern-view-select:hover {
    border-color: var(--crm-primary);
}

/* Icon Buttons */
.modern-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 34px;
    padding: 0 12px;
    border-radius: 6px;
    border: 1px solid var(--crm-border);
    background: var(--crm-bg-card);
    color: var(--crm-text-sub);
    cursor: pointer;
    text-decoration: none;
    font-size: 0.8rem;
    font-weight: 600;
    gap: 6px;
    transition: all 0.15s ease;
}

.modern-btn:hover {
    background: var(--crm-bg-muted);
    color: var(--crm-primary);
    border-color: var(--crm-primary);
}

.modern-btn.primary {
    background: var(--crm-primary);
    color: #ffffff;
    border-color: var(--crm-primary);
}

.modern-btn.primary:hover {
    background: var(--crm-primary-dark);
}

/* 4-Box Flexible Responsive Grid */
.modern-widgets-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    align-items: flex-start;
}

/* Widget Card Container (Default 50% width, expandable to 100%) */
.modern-widget-card {
    background: var(--crm-bg-card);
    border: 1px solid var(--crm-border);
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    display: flex;
    flex-direction: column;
    min-height: 480px;
    box-sizing: border-box;
    transition: width 0.25s ease, flex 0.25s ease;
    flex: 0 0 calc(50% - 9px);
    width: calc(50% - 9px);
    overflow: visible !important;
    position: relative;
}

.modern-widget-card.full-width {
    flex: 0 0 100% !important;
    width: 100% !important;
}

@media (max-width: 1024px) {
    .modern-widget-card {
        flex: 0 0 100% !important;
        width: 100% !important;
    }
}

/* Widget Header */
.modern-widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    border-bottom: 1px solid var(--crm-border);
    background: var(--crm-bg-card);
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
    position: relative;
    z-index: 50;
}

.modern-title-dropdown-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--crm-text-title);
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 6px;
    transition: all 0.15s ease;
}

.modern-title-dropdown-btn:hover {
    background: var(--crm-bg-muted);
    border-color: var(--crm-border);
}

.modern-widget-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.modern-width-pill {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid var(--crm-border);
    background: var(--crm-bg-muted);
    color: var(--crm-text-sub);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}

.modern-width-pill:hover {
    border-color: var(--crm-primary);
    color: var(--crm-primary);
}

/* Table Sub-header */
.modern-table-header {
    display: grid;
    padding: 10px 18px;
    background: var(--crm-bg-muted);
    border-bottom: 1px solid var(--crm-border);
    font-size: 0.74rem;
    font-weight: 700;
    color: var(--crm-text-sub);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.cols-activities {
    grid-template-columns: 110px 1fr 160px 36px;
    align-items: center;
}

.cols-tasks {
    grid-template-columns: 110px 1fr 110px 36px;
    align-items: center;
}

.cols-meetings {
    grid-template-columns: 1fr 85px 85px 120px 100px 36px;
    align-items: center;
}

/* Modern Leads Table (Box 1 Responsive Table) */
.modern-table-responsive {
    width: 100%;
    overflow-x: auto;
    overflow-y: auto;
    background: var(--crm-bg-card);
}

.modern-leads-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    color: var(--crm-text-title);
    white-space: nowrap;
}

.modern-leads-table thead th {
    background: var(--crm-bg-muted);
    color: var(--crm-text-sub);
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 8px 12px;
    border-bottom: 1px solid var(--crm-border);
    text-align: left;
    vertical-align: middle;
    user-select: none;
}

.modern-leads-table thead th.draggable-th {
    cursor: grab;
}

.modern-leads-table thead th.draggable-th:active {
    cursor: grabbing;
}

.modern-leads-table thead th.drag-over {
    border-left: 2px solid var(--crm-primary) !important;
    background: rgba(37, 99, 235, 0.1) !important;
}

.th-sort-wrapper {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    padding: 3px 5px;
    border-radius: 4px;
    transition: all 0.15s ease;
}

.th-sort-wrapper:hover {
    background: rgba(0, 0, 0, 0.06);
    color: var(--crm-primary);
}

[data-theme="dark"] .th-sort-wrapper:hover {
    background: rgba(255, 255, 255, 0.08);
}

.th-sort-wrapper.active-sort {
    color: var(--crm-primary);
    font-weight: 700;
}

.th-sort-wrapper .sort-icon {
    width: 12px;
    height: 12px;
    opacity: 0.45;
    transition: opacity 0.15s ease, transform 0.15s ease;
}

.th-sort-wrapper:hover .sort-icon,
.th-sort-wrapper.active-sort .sort-icon {
    opacity: 1;
}

.modern-leads-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--crm-border);
    vertical-align: middle;
    background: var(--crm-bg-card);
    transition: background 0.15s ease;
}

.modern-leads-table tbody tr:hover td {
    background: var(--crm-bg-muted);
}

.col-item-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid var(--crm-border);
    background: var(--crm-bg-card);
    cursor: pointer;
    transition: all 0.15s ease;
}

.col-item-card:hover {
    background: var(--crm-bg-muted);
    border-color: var(--crm-primary);
}

@media (max-width: 650px) {
    .cols-activities {
        grid-template-columns: 90px 1fr 36px;
    }
    .cols-activities .col-related {
        display: none;
    }
    .cols-meetings {
        grid-template-columns: 1fr 75px 80px 36px;
    }
    .cols-meetings .col-to, .cols-meetings .col-contact {
        display: none;
    }
}

/* Row Styling */
.modern-row-item {
    display: grid;
    padding: 12px 18px;
    border-bottom: 1px solid var(--crm-border);
    align-items: start;
    font-size: 0.82rem;
    transition: background 0.15s ease;
}

.modern-row-item:hover {
    background: var(--crm-bg-muted);
}

/* Date Badges */
.modern-date-col {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.modern-date-badge {
    display: inline-flex;
    align-items: center;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    width: fit-content;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.modern-date-badge.today {
    background: #d1fae5;
    color: #065f46;
}

.modern-date-badge.overdue {
    background: #fee2e2;
    color: #991b1b;
}

.modern-date-badge.tomorrow {
    background: #e0e7ff;
    color: #3730a3;
}

.modern-date-badge.normal, .modern-date-badge.date {
    background: #f1f5f9;
    color: #475569;
}

.modern-activity-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-right: 12px;
}

.modern-activity-title-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.modern-activity-checkbox {
    margin-top: 3px;
    width: 15px;
    height: 15px;
    cursor: pointer;
    accent-color: var(--crm-primary);
}

.modern-activity-link {
    color: var(--crm-primary);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.86rem;
    line-height: 1.35;
}

.modern-activity-link:hover {
    text-decoration: underline;
}

.modern-activity-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-size: 0.74rem;
    color: var(--crm-text-sub);
    margin-left: 23px;
}

.modern-activity-related {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #3b82f6;
    font-weight: 600;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Flag Ribbons */
.modern-ribbon-container {
    display: flex;
    align-items: center;
    gap: 6px;
}

.modern-flag-ribbon {
    position: relative;
    background: var(--crm-red-flag);
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 3px 8px 3px 6px;
    display: inline-flex;
    align-items: center;
    line-height: 1.2;
    clip-path: polygon(0% 0%, 85% 0%, 100% 50%, 85% 100%, 0% 100%);
    min-width: 52px;
}

.modern-flag-ribbon.completed {
    background: #94a3b8;
}

.modern-flag-ribbon.in-progress {
    background: var(--crm-amber);
}

.modern-flag-ribbon.not-started {
    background: var(--crm-red-flag);
}

.modern-overdue-days-pill {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--crm-red-flag);
}

.modern-task-subject {
    font-weight: 500;
    color: var(--crm-text-title);
    text-decoration: none;
    line-height: 1.35;
    cursor: pointer;
}

.modern-task-subject:hover {
    color: var(--crm-primary);
}

.modern-task-status-badge {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 4px;
    background: #f1f5f9;
    color: var(--crm-text-sub);
}

.modern-task-status-badge.completed {
    background: #d1fae5;
    color: #065f46;
}

/* Footer */
.modern-widget-footer {
    margin-top: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
    border-top: 1px solid var(--crm-border);
    background: var(--crm-bg-muted);
    font-size: 0.76rem;
    color: var(--crm-text-sub);
}

/* Custom Dropdown Menus */
.modern-dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--crm-bg-card);
    border: 1px solid var(--crm-border);
    border-radius: 8px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
    z-index: 99999 !important;
    min-width: 270px;
    display: none;
    padding: 6px 0;
}

.modern-dropdown-menu.show {
    display: block !important;
}

.modern-dropdown-item {
    display: flex;
    align-items: center;
    padding: 9px 18px;
    font-size: 0.82rem;
    color: var(--crm-text-title);
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s ease;
}

.modern-dropdown-item:hover {
    background: var(--crm-bg-muted);
    color: var(--crm-primary);
}

.modern-dropdown-item.active {
    background: rgba(37, 99, 235, 0.08);
    color: var(--crm-primary);
    font-weight: 700;
}

.modern-dropdown-divider {
    height: 1px;
    background: var(--crm-border);
    margin: 4px 0;
}

.modern-dropdown-section-title {
    padding: 6px 18px 2px;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--crm-text-sub);
    text-transform: uppercase;
}

/* Stage Funnel Progress Bar */
.modern-stage-row {
    padding: 12px 18px;
    border-bottom: 1px solid var(--crm-border);
    display: flex;
    flex-direction: column;
    gap: 6px;
    cursor: pointer;
    transition: background 0.15s ease;
}

.modern-stage-row:hover {
    background: var(--crm-bg-muted);
}

.modern-stage-row:last-child {
    border-bottom: none;
}

.modern-stage-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.82rem;
    font-weight: 600;
}

.modern-stage-bar-bg {
    width: 100%;
    height: 7px;
    background: var(--crm-bg-muted);
    border-radius: 4px;
    overflow: hidden;
}

.modern-stage-bar-fill {
    height: 100%;
    background: #3b82f6;
    border-radius: 4px;
}

/* Centered Modal Overlay (Zoho CRM Enterprise Style) */
.modern-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}

.modern-columns-modal-dialog {
    background: var(--crm-bg-card);
    width: 440px;
    max-width: 92vw;
    border-radius: 10px;
    border: 1px solid var(--crm-border);
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modernModalZoom 0.16s ease-out;
}

@keyframes modernModalZoom {
    from { opacity: 0; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1); }
}

.modern-columns-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--crm-border);
    background: var(--crm-bg-card);
}

.modern-columns-modal-body {
    padding: 14px 18px;
    overflow-y: auto;
    max-height: 70vh;
}

.modern-columns-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 18px;
    border-top: 1px solid var(--crm-border);
    background: var(--crm-bg-muted);
}

.modern-filter-count-badge {
    background: var(--crm-bg-muted);
    color: var(--crm-text-sub);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    margin-left: auto;
}

.modern-dropdown-item.active .modern-filter-count-badge {
    background: rgba(37, 99, 235, 0.15);
    color: var(--crm-primary);
}

/* Slide-in Drawers & Overlays */
.modern-drawer-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 99999;
    justify-content: flex-end;
    backdrop-filter: blur(2px);
}

.modern-drawer-content {
    background: var(--crm-bg-card);
    width: 380px;
    max-width: 90vw;
    height: 100%;
    display: flex;
    flex-direction: column;
    box-shadow: -10px 0 30px rgba(0, 0, 0, 0.2);
}

.modern-drawer-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--crm-border);
}

.modern-drawer-body {
    padding: 16px 18px;
    flex: 1;
    overflow-y: auto;
}

.modern-drawer-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--crm-border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>

<div class="modern-crm-wrapper">

    <!-- Top Header Banner & Global Controls -->
    <div class="modern-header-banner">
        <div class="modern-header-left">
            <div class="modern-building-icon">
                <i data-lucide="layout-grid" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <h2 class="modern-welcome-title">
                    <span>Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                    <span class="modern-view-badge">Modern View</span>
                </h2>
                <p class="modern-welcome-sub">
                    <span><?php echo htmlspecialchars($user_role); ?></span>
                    <span>&bull;</span>
                    <span id="liveClockIst"><?php echo date('D, M d, Y - h:i A'); ?> IST</span>
                </p>
            </div>
        </div>

        <div class="modern-header-right">
            <!-- Global Search Box -->
            <div class="modern-search-box">
                <i data-lucide="search" class="modern-search-icon"></i>
                <input type="text" id="modernGlobalSearch" class="modern-search-input" placeholder="Search leads, activities, tasks..." onkeyup="filterModernGlobalRecords()">
            </div>

            <!-- View Switcher -->
            <select class="modern-view-select" onchange="handleViewSwitch(this.value)" title="Switch Leads Directory View">
                <option value="modern" selected>Modern View (Workspace)</option>
                <option value="classic">Classic View (Table Roster)</option>
            </select>

            <!-- Quick Create Button -->
            <button type="button" class="modern-btn primary" onclick="openModernQuickAddModal()">
                <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                <span>Quick Create</span>
            </button>

            <!-- Refresh Button -->
            <button type="button" class="modern-btn" title="Refresh View" onclick="window.location.reload()">
                <i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i>
            </button>
        </div>
    </div>

    <!-- 4-Box Flexible Responsive Grid -->
    <div class="modern-widgets-grid">

        <!-- =================================================================== -->
        <!-- BOX 1: Open Activities & Leads Data (Requested Dropdown Options)    -->
        <!-- =================================================================== -->
        <div class="modern-widget-card" id="card_activities">
            <!-- Header -->
            <div class="modern-widget-header">
                <div style="position: relative;">
                    <button type="button" class="modern-title-dropdown-btn" onclick="toggleDropdownMenu('menu_activities', event)">
                        <span id="label_activities">My open activities for today</span>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </button>
                    <!-- Exact Dropdown Filter Options Requested with Real Counts -->
                    <div class="modern-dropdown-menu" id="menu_activities">
                        <div class="modern-dropdown-item active" onclick="selectActivityFilter('My open activities for today', this)" data-filter="today">
                            <span>My open activities for today</span>
                            <span class="modern-filter-count-badge"><?php echo $today_activities_count; ?></span>
                        </div>
                        <div class="modern-dropdown-item" onclick="selectActivityFilter('My open activities for this week', this)" data-filter="this_week">
                            <span>My open activities for this week</span>
                            <span class="modern-filter-count-badge"><?php echo $this_week_activities_count; ?></span>
                        </div>
                        <div class="modern-dropdown-item" onclick="selectActivityFilter('My open activities for next week', this)" data-filter="next_week">
                            <span>My open activities for next week</span>
                            <span class="modern-filter-count-badge"><?php echo $next_week_activities_count; ?></span>
                        </div>
                        <div class="modern-dropdown-item" onclick="selectActivityFilter('All Open Activities', this)" data-filter="all">
                            <span>All Open Activities</span>
                            <span class="modern-filter-count-badge"><?php echo $all_activities_count; ?></span>
                        </div>
                    </div>
                </div>

                <div class="modern-widget-actions">
                    <!-- Manage Columns Button -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 10px; font-size: 0.74rem; gap: 5px;" onclick="openColumnDrawer('activities')" title="Manage Columns (Hide / Unhide)">
                        <i data-lucide="sliders" style="width: 13px; height: 13px;"></i>
                        <span>Manage Columns</span>
                    </button>

                    <!-- Manage Width Toggle (50% / 100%) -->
                    <button type="button" class="modern-width-pill" title="Toggle Card Width (50% / 100%)" onclick="toggleWidgetWidth('card_activities')">
                        <i data-lucide="columns-2" style="width: 12px; height: 12px;"></i>
                        <span class="width-text">50%</span>
                    </button>

                    <button type="button" class="modern-btn" style="width: 28px; height: 28px; padding: 0;" title="Refresh Activities" onclick="window.location.reload()">
                        <i data-lucide="rotate-ccw" style="width: 13px; height: 13px;"></i>
                    </button>
                </div>
            </div>

            <!-- Leads Activities Responsive Table with Manageable Columns -->
            <div class="modern-table-responsive" style="flex: 1; overflow-x: auto; overflow-y: auto;">
                <table class="modern-leads-table">
                    <thead>
                        <tr>
                            <th style="width: 36px; text-align: center;">
                                <input type="checkbox" id="selectAllActivities" onchange="toggleSelectAllRows(this.checked)" style="accent-color: var(--crm-primary); cursor: pointer;" title="Select All">
                            </th>
                            <th class="col-sno draggable-th" data-col="col-sno" style="width: 55px; text-align: center;">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-sno')">
                                    <span>S.No.</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-lead-id draggable-th" data-col="col-lead-id">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-lead-id')">
                                    <span>Lead ID</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-assigned draggable-th" data-col="col-assigned">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-assigned')">
                                    <span>Assigned to</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-contact-person draggable-th" data-col="col-contact-person">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-contact-person')">
                                    <span>Person Name</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-name draggable-th" data-col="col-name">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-name')">
                                    <span>Client Details</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-group draggable-th" data-col="col-group">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-group')">
                                    <span>Group</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-status draggable-th" data-col="col-status">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-status')">
                                    <span>Status</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-priority draggable-th" data-col="col-priority">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-priority')">
                                    <span>Priority</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-source draggable-th" data-col="col-source">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-source')">
                                    <span>Source</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-enq-for draggable-th" data-col="col-enq-for">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-enq-for')">
                                    <span>Enq For</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-tags draggable-th" data-col="col-tags">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-tags')">
                                    <span>Tags</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-address draggable-th" data-col="col-address">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-address')">
                                    <span>Address</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-remarks draggable-th" data-col="col-remarks">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-remarks')">
                                    <span>Remarks</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-scheduled-fup draggable-th" data-col="col-scheduled-fup">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-scheduled-fup')">
                                    <span>Scheduled Follow-up</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-created-at draggable-th" data-col="col-created-at">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-created-at')">
                                    <span>Created At</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th class="col-activity draggable-th" data-col="col-activity">
                                <div class="th-sort-wrapper" onclick="sortActivitiesTable('col-activity')">
                                    <span>Last Activity</span>
                                    <i data-lucide="arrow-up-down" class="sort-icon"></i>
                                </div>
                            </th>
                            <th style="text-align: right; width: 80px;">
                                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px;">
                                    <span>Actions</span>
                                    <button type="button" onclick="openColumnDrawer('activities')" title="Select Columns" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub); display: inline-flex; align-items: center; padding: 0;">
                                        <i data-lucide="sliders" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="activitiesList">
                        <?php if (!empty($activities)): ?>
                            <?php foreach ($activities as $idx => $act): ?>
                                <tr class="modern-lead-row activity-record-row <?php echo $act['is_overdue'] ? 'is-overdue-row' : ''; ?>"
                                    data-has-scheduled="<?php echo $act['has_scheduled']; ?>"
                                    data-is-today="<?php echo $act['is_today']; ?>"
                                    data-is-this-week="<?php echo $act['is_this_week']; ?>"
                                    data-is-next-week="<?php echo $act['is_next_week']; ?>"
                                    data-is-overdue="<?php echo $act['is_overdue'] ? '1' : '0'; ?>"
                                    data-sched-date="<?php echo $act['sched_date']; ?>"
                                    data-val-col-sno="<?php echo $idx + 1; ?>"
                                    data-val-col-lead-id="<?php echo intval($act['lead_id']); ?>"
                                    data-val-col-assigned="<?php echo htmlspecialchars(strtolower($act['owner'])); ?>"
                                    data-val-col-contact-person="<?php echo htmlspecialchars(strtolower($act['contact'])); ?>"
                                    data-val-col-name="<?php echo htmlspecialchars(strtolower($act['company'])); ?>"
                                    data-val-col-group="<?php echo htmlspecialchars(strtolower($act['group_stage'])); ?>"
                                    data-val-col-status="<?php echo htmlspecialchars(strtolower($act['status'])); ?>"
                                    data-val-col-priority="<?php echo htmlspecialchars(strtolower($act['priority'])); ?>"
                                    data-val-col-source="<?php echo htmlspecialchars(strtolower($act['source'])); ?>"
                                    data-val-col-enq-for="<?php echo htmlspecialchars(strtolower($act['enq_for'])); ?>"
                                    data-val-col-tags="<?php echo htmlspecialchars(strtolower($act['tags'])); ?>"
                                    data-val-col-address="<?php echo htmlspecialchars(strtolower($act['address'])); ?>"
                                    data-val-col-remarks="<?php echo htmlspecialchars(strtolower($act['remarks'])); ?>"
                                    data-val-col-scheduled-fup="<?php echo intval($act['raw_time']); ?>"
                                    data-val-col-created-at="<?php echo !empty($act['created_at']) ? strtotime($act['created_at']) : 0; ?>"
                                    data-val-col-activity="<?php echo !empty($act['last_activity']) ? strtotime($act['last_activity']) : 0; ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" class="modern-activity-checkbox" style="accent-color: var(--crm-primary); cursor: pointer;" onchange="toggleRowCompleted(this)">
                                    </td>
                                    <td class="col-sno" style="text-align: center; font-weight: 700; color: var(--crm-text-sub); font-size: 0.78rem;">
                                        <?php echo $idx + 1; ?>
                                    </td>
                                    <td class="col-lead-id" style="font-weight: 700; font-size: 0.8rem;">
                                        <a href="index.php?page=lead_details&id=<?php echo urlencode($act['lead_id']); ?>" style="color: var(--crm-primary); text-decoration: none;">
                                            #<?php echo htmlspecialchars($act['lead_id']); ?>
                                        </a>
                                    </td>
                                    <td class="col-assigned">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <div style="width: 22px; height: 22px; border-radius: 50%; background: #3b82f6; color: #fff; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?php echo strtoupper(substr($act['owner'], 0, 1)); ?>
                                            </div>
                                            <span style="font-size: 0.78rem; font-weight: 600; white-space: nowrap;"><?php echo htmlspecialchars($act['owner']); ?></span>
                                        </div>
                                    </td>
                                    <td class="col-contact-person">
                                        <div style="font-weight: 600; font-size: 0.82rem; color: var(--crm-text-title);">
                                            <?php echo htmlspecialchars($act['contact']); ?>
                                        </div>
                                        <?php if (!empty($act['lead_name']) && $act['lead_name'] !== $act['contact'] && $act['lead_name'] !== $act['company']): ?>
                                            <div style="font-size: 0.72rem; color: var(--crm-text-sub);"><?php echo htmlspecialchars($act['lead_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-name">
                                        <div style="display: flex; flex-direction: column; gap: 2px;">
                                            <a href="index.php?page=lead_details&id=<?php echo urlencode($act['lead_id']); ?>" style="font-weight: 700; color: var(--crm-text-title); text-decoration: none; font-size: 0.82rem;">
                                                <?php echo htmlspecialchars($act['company']); ?>
                                            </a>
                                            <?php if (!empty($act['phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($act['clean_phone']); ?>" style="font-size: 0.74rem; color: var(--crm-text-sub); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i data-lucide="phone" style="width: 11px; height: 11px; color: var(--crm-primary);"></i>
                                                    <span><?php echo htmlspecialchars($act['phone']); ?></span>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-group">
                                        <span class="badge" style="background: #f1f5f9; color: #334155; font-size: 0.74rem; font-weight: 600; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                            <?php echo htmlspecialchars($act['group_stage'] ?: 'NA'); ?>
                                        </span>
                                    </td>
                                    <td class="col-status">
                                        <?php echo getStatusBadge($act['status']); ?>
                                    </td>
                                    <td class="col-priority">
                                        <?php echo getPriorityBadge($act['priority']); ?>
                                    </td>
                                    <td class="col-source">
                                        <span class="badge" style="background: rgba(37, 99, 235, 0.08); color: var(--crm-primary); font-size: 0.74rem; font-weight: 600; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                            <?php echo htmlspecialchars($act['source'] ?: 'Direct'); ?>
                                        </span>
                                    </td>
                                    <td class="col-enq-for" style="font-size: 0.78rem; color: var(--crm-text-sub);">
                                        <?php echo htmlspecialchars($act['enq_for'] ?: '-'); ?>
                                    </td>
                                    <td class="col-tags">
                                        <?php if (!empty($act['tags']) && $act['tags'] !== '-'): ?>
                                            <span class="badge" style="background: #e2e8f0; color: #475569; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">
                                                <?php echo htmlspecialchars($act['tags']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--crm-text-sub); font-size: 0.78rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-address" style="font-size: 0.76rem; color: var(--crm-text-sub); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($act['address']); ?>">
                                        <?php echo htmlspecialchars($act['address'] ?: '-'); ?>
                                    </td>
                                    <td class="col-remarks" style="font-size: 0.76rem; color: var(--crm-text-sub); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($act['remarks']); ?>">
                                        <?php echo htmlspecialchars($act['remarks'] ?: '-'); ?>
                                    </td>
                                    <td class="col-scheduled-fup">
                                        <?php if ($act['has_scheduled']): ?>
                                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                                <span class="modern-date-badge <?php echo $act['badge_class']; ?>">
                                                    <?php echo htmlspecialchars($act['date_badge']); ?>
                                                </span>
                                                <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600;">
                                                    <?php echo htmlspecialchars($act['display_sched_date']); ?>
                                                </div>
                                                <?php if ($act['is_overdue']): ?>
                                                    <span class="modern-overdue-days-pill" style="font-size: 0.7rem;">+<?php echo $act['overdue_days']; ?>d overdue</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.74rem; color: var(--crm-text-sub); font-style: italic;">No Follow-up</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-created-at" style="font-size: 0.75rem; color: var(--crm-text-sub); white-space: nowrap;">
                                        <span style="display: inline-flex; align-items: center; gap: 4px;">
                                            <i data-lucide="calendar" style="width: 11px; height: 11px; color: var(--crm-text-sub);"></i>
                                            <?php echo htmlspecialchars($act['created_at']); ?>
                                        </span>
                                    </td>
                                    <td class="col-activity" style="font-size: 0.75rem; color: var(--crm-text-sub); white-space: nowrap;">
                                        <?php echo htmlspecialchars($act['last_activity']); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px;">
                                            <a href="index.php?page=lead_details&id=<?php echo urlencode($act['lead_id']); ?>" class="modern-btn" style="width: 26px; height: 26px; padding: 0;" title="View Dossier">
                                                <i data-lucide="folder-open" style="width: 12px; height: 12px;"></i>
                                            </a>
                                            <a href="index.php?page=lead_form&action=edit&id=<?php echo urlencode($act['lead_id']); ?>" class="modern-btn" style="width: 26px; height: 26px; padding: 0;" title="Edit Lead">
                                                <i data-lucide="edit-3" style="width: 12px; height: 12px;"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="18" style="padding: 40px; text-align: center; color: var(--crm-text-sub); font-size: 0.85rem;">
                                    No open leads found in the directory.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div id="filterEmptyNotice" style="display: none; padding: 40px; text-align: center; color: var(--crm-text-sub); font-size: 0.85rem;">
                    <div class="empty-filter-text">No follow-up activities scheduled for today.</div>
                    <button type="button" class="modern-btn" style="margin-top: 10px;" onclick="selectActivityFilter('All Open Activities', document.querySelectorAll('#menu_activities .modern-dropdown-item')[3])">Show All Open Leads</button>
                </div>
            </div>

            <!-- Footer with Rows Per Page & Pagination for Activities / Leads -->
            <div class="modern-widget-footer" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.74rem; color: var(--crm-text-sub);">
                        <span>Show:</span>
                        <select id="activitiesPageSize" onchange="changeActivitiesPageSize(this.value)" style="background: var(--crm-bg-card); color: var(--crm-text-title); border: 1px solid var(--crm-border); border-radius: 4px; padding: 2px 6px; font-size: 0.74rem; cursor: pointer;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div id="footerActivitiesCount" style="font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub);">Showing 0 of 0 Leads</div>
                </div>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="modern-pagination-nav" style="display: inline-flex; align-items: center; gap: 4px;">
                        <button type="button" class="modern-btn" id="btnActPrev" onclick="changeActivitiesPage(-1)" style="width: 24px; height: 24px; padding: 0;" title="Previous Page">
                            <i data-lucide="chevron-left" style="width: 13px; height: 13px;"></i>
                        </button>
                        <span id="actPageIndicator" style="font-size: 0.74rem; font-weight: 600; color: var(--crm-text-sub); padding: 0 4px;">Page 1</span>
                        <button type="button" class="modern-btn" id="btnActNext" onclick="changeActivitiesPage(1)" style="width: 24px; height: 24px; padding: 0;" title="Next Page">
                            <i data-lucide="chevron-right" style="width: 13px; height: 13px;"></i>
                        </button>
                    </div>
                    <a href="index.php?page=leads" style="color: var(--crm-primary); text-decoration: none; font-weight: 600; font-size: 0.76rem;">Full Directory &rarr;</a>
                </div>
            </div>
        </div>


        <!-- =================================================================== -->
        <!-- BOX 2: Follow-up Tasks (With Own Manage Columns & Sort)             -->
        <!-- =================================================================== -->
        <div class="modern-widget-card" id="card_tasks">
            <!-- Header -->
            <div class="modern-widget-header">
                <div style="position: relative;">
                    <button type="button" class="modern-title-dropdown-btn" onclick="toggleDropdownMenu('menu_tasks', event)">
                        <span id="label_tasks">My Tasks</span>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </button>
                    <!-- Tasks Dropdown Filter List -->
                    <div class="modern-dropdown-menu" id="menu_tasks">
                        <div class="modern-dropdown-item active" onclick="selectTaskFilter('My Tasks', this)">My Tasks</div>
                        <div class="modern-dropdown-item" onclick="selectTaskFilter('My Open Tasks', this)">My Open Tasks</div>
                        <div class="modern-dropdown-item" onclick="selectTaskFilter('My Overdue Tasks', this)">My Overdue Tasks</div>
                        <div class="modern-dropdown-item" onclick="selectTaskFilter('My Today\'s Tasks', this)">My Today's Tasks</div>
                        <div class="modern-dropdown-item" onclick="selectTaskFilter('My Closed Tasks', this)">My Closed Tasks</div>
                    </div>
                </div>

                <div class="modern-widget-actions">
                    <!-- Manage Columns Button for Tasks -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 10px; font-size: 0.74rem; gap: 5px;" onclick="openTasksColumnModal()" title="Manage Task Columns">
                        <i data-lucide="sliders" style="width: 13px; height: 13px;"></i>
                        <span>Manage Columns</span>
                    </button>

                    <!-- Sort Trigger for Tasks -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 8px; font-size: 0.74rem; gap: 4px;" onclick="sortTasksList()" title="Sort Tasks">
                        <i data-lucide="arrow-up-down" style="width: 12px; height: 12px;"></i>
                        <span id="sortTasksLabel">Sort</span>
                    </button>

                    <!-- Manage Width Toggle -->
                    <button type="button" class="modern-width-pill" title="Toggle Card Width (50% / 100%)" onclick="toggleWidgetWidth('card_tasks')">
                        <i data-lucide="columns-2" style="width: 12px; height: 12px;"></i>
                        <span class="width-text">50%</span>
                    </button>
                </div>
            </div>

            <!-- Tasks Responsive Table with Manageable Columns -->
            <div class="modern-table-responsive" style="flex: 1; overflow-x: auto; overflow-y: auto;">
                <table class="modern-leads-table" id="tasksTable">
                    <thead>
                        <tr>
                            <th class="col-tsk-sno draggable-th" onclick="sortTasksTable('col-tsk-sno')" style="width: 45px; text-align: center;">
                                <div class="th-sort-wrapper"><span>#</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-due draggable-th" onclick="sortTasksTable('col-tsk-due')">
                                <div class="th-sort-wrapper"><span>Due Date</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-subject draggable-th" onclick="sortTasksTable('col-tsk-subject')">
                                <div class="th-sort-wrapper"><span>Subject / Item</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-related draggable-th" onclick="sortTasksTable('col-tsk-related')">
                                <div class="th-sort-wrapper"><span>Related To</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-priority draggable-th" onclick="sortTasksTable('col-tsk-priority')">
                                <div class="th-sort-wrapper"><span>Priority</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-status draggable-th" onclick="sortTasksTable('col-tsk-status')">
                                <div class="th-sort-wrapper"><span>Status</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-tsk-owner draggable-th" onclick="sortTasksTable('col-tsk-owner')">
                                <div class="th-sort-wrapper"><span>Assigned To</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th style="text-align: right; width: 40px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tasksList">
                        <?php if (!empty($tasks)): ?>
                            <?php foreach ($tasks as $tIdx => $tsk): ?>
                                <tr class="task-record-row <?php echo $tsk['is_overdue'] ? 'is-overdue-row' : ''; ?>"
                                    data-status="<?php echo strtolower($tsk['status']); ?>"
                                    data-is-overdue="<?php echo $tsk['is_overdue']; ?>"
                                    data-is-today="<?php echo $tsk['is_today']; ?>"
                                    data-val-col-tsk-sno="<?php echo $tIdx + 1; ?>"
                                    data-val-col-tsk-due="<?php echo strtotime($tsk['due_date'] . ' ' . date('Y')); ?>"
                                    data-val-col-tsk-subject="<?php echo htmlspecialchars(strtolower($tsk['subject'])); ?>"
                                    data-val-col-tsk-related="<?php echo htmlspecialchars(strtolower($tsk['related_to'])); ?>"
                                    data-val-col-tsk-priority="<?php echo htmlspecialchars(strtolower($tsk['priority'])); ?>"
                                    data-val-col-tsk-status="<?php echo htmlspecialchars(strtolower($tsk['status'])); ?>"
                                    data-val-col-tsk-owner="<?php echo htmlspecialchars(strtolower($tsk['owner'])); ?>">
                                    <td class="col-tsk-sno" style="text-align: center; font-weight: 700; color: var(--crm-text-sub); font-size: 0.75rem;">
                                        <?php echo $tIdx + 1; ?>
                                    </td>
                                    <td class="col-tsk-due">
                                        <div class="modern-ribbon-container">
                                            <div class="modern-flag-ribbon <?php echo $tsk['is_completed'] ? 'completed' : ($tsk['status'] === 'In Progress' ? 'in-progress' : 'not-started'); ?>">
                                                <?php echo strtoupper(htmlspecialchars($tsk['due_date'])); ?>
                                            </div>
                                            <?php if (!$tsk['is_completed'] && $tsk['overdue_days'] > 0): ?>
                                                <span class="modern-overdue-days-pill">+<?php echo $tsk['overdue_days']; ?>d</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-tsk-subject">
                                        <a href="javascript:void(0)" class="modern-task-subject" onclick="showModernTaskModal('<?php echo htmlspecialchars(addslashes($tsk['subject'])); ?>', '<?php echo htmlspecialchars($tsk['status']); ?>', '<?php echo htmlspecialchars($tsk['priority']); ?>', '<?php echo htmlspecialchars(addslashes($tsk['related_to'])); ?>', '<?php echo htmlspecialchars($tsk['due_date']); ?>', '<?php echo urlencode($tsk['lead_id']); ?>')">
                                            <?php echo htmlspecialchars($tsk['subject']); ?>
                                        </a>
                                    </td>
                                    <td class="col-tsk-related" style="font-size: 0.76rem; color: var(--crm-text-sub);">
                                        <?php echo htmlspecialchars($tsk['related_to']); ?>
                                    </td>
                                    <td class="col-tsk-priority">
                                        <span class="modern-priority-pill prio-<?php echo strtolower($tsk['priority']); ?>">
                                            <?php echo htmlspecialchars($tsk['priority']); ?>
                                        </span>
                                    </td>
                                    <td class="col-tsk-status">
                                        <span class="modern-task-status-badge <?php echo $tsk['is_completed'] ? 'completed' : ''; ?>">
                                            <?php echo htmlspecialchars($tsk['status']); ?>
                                        </span>
                                    </td>
                                    <td class="col-tsk-owner" style="font-size: 0.76rem; color: var(--crm-text-sub);">
                                        <?php echo htmlspecialchars($tsk['owner']); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="modern-btn" style="width: 24px; height: 24px; padding: 0;" title="Task Details" onclick="showModernTaskModal('<?php echo htmlspecialchars(addslashes($tsk['subject'])); ?>', '<?php echo htmlspecialchars($tsk['status']); ?>', '<?php echo htmlspecialchars($tsk['priority']); ?>', '<?php echo htmlspecialchars(addslashes($tsk['related_to'])); ?>', '<?php echo htmlspecialchars($tsk['due_date']); ?>', '<?php echo urlencode($tsk['lead_id']); ?>')">
                                            <i data-lucide="more-vertical" style="width: 12px; height: 12px;"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="padding: 36px; text-align: center; color: var(--crm-text-sub); font-size: 0.85rem;">
                                    No tasks found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer with Page Size & Pagination for Tasks -->
            <div class="modern-widget-footer" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.74rem; color: var(--crm-text-sub);">
                        <span>Show:</span>
                        <select id="tasksPageSize" onchange="changeTasksPageSize(this.value)" style="background: var(--crm-bg-card); color: var(--crm-text-title); border: 1px solid var(--crm-border); border-radius: 4px; padding: 2px 6px; font-size: 0.74rem; cursor: pointer;">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div id="footerTasksCount" style="font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub);">Showing <?php echo count($tasks); ?> Tasks</div>
                </div>
                <div class="modern-pagination-nav" style="display: inline-flex; align-items: center; gap: 4px;">
                    <button type="button" class="modern-btn" id="btnTskPrev" onclick="changeTasksPage(-1)" style="width: 24px; height: 24px; padding: 0;" title="Previous Page">
                        <i data-lucide="chevron-left" style="width: 13px; height: 13px;"></i>
                    </button>
                    <span id="tskPageIndicator" style="font-size: 0.74rem; font-weight: 600; color: var(--crm-text-sub); padding: 0 4px;">Page 1</span>
                    <button type="button" class="modern-btn" id="btnTskNext" onclick="changeTasksPage(1)" style="width: 24px; height: 24px; padding: 0;" title="Next Page">
                        <i data-lucide="chevron-right" style="width: 13px; height: 13px;"></i>
                    </button>
                </div>
            </div>
        </div>


        <!-- =================================================================== -->
        <!-- BOX 3: Scheduled Demos & Meetings (With Own Manage Columns & Sort)   -->
        <!-- =================================================================== -->
        <div class="modern-widget-card" id="card_meetings">
            <!-- Header -->
            <div class="modern-widget-header">
                <div style="position: relative;">
                    <button type="button" class="modern-title-dropdown-btn" onclick="toggleDropdownMenu('menu_meetings', event)">
                        <span id="label_meetings">My Meetings Today</span>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </button>
                    <!-- Meetings Dropdown Filter List -->
                    <div class="modern-dropdown-menu" id="menu_meetings">
                        <div class="modern-dropdown-item active" onclick="selectMeetingFilter('My Meetings Today', this)">My Meetings Today</div>
                        <div class="modern-dropdown-item" onclick="selectMeetingFilter('My Meetings This Week', this)">My Meetings This Week</div>
                        <div class="modern-dropdown-item" onclick="selectMeetingFilter('All Meetings', this)">All Meetings</div>
                    </div>
                </div>

                <div class="modern-widget-actions">
                    <!-- Manage Columns Button for Meetings -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 10px; font-size: 0.74rem; gap: 5px;" onclick="openMeetingsColumnModal()" title="Manage Meeting Columns">
                        <i data-lucide="sliders" style="width: 13px; height: 13px;"></i>
                        <span>Manage Columns</span>
                    </button>

                    <!-- Sort Trigger for Meetings -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 8px; font-size: 0.74rem; gap: 4px;" onclick="sortMeetingsList()" title="Sort Meetings">
                        <i data-lucide="arrow-up-down" style="width: 12px; height: 12px;"></i>
                        <span id="sortMeetingsLabel">Sort</span>
                    </button>

                    <!-- Manage Width Toggle -->
                    <button type="button" class="modern-width-pill" title="Toggle Card Width (50% / 100%)" onclick="toggleWidgetWidth('card_meetings')">
                        <i data-lucide="columns-2" style="width: 12px; height: 12px;"></i>
                        <span class="width-text">50%</span>
                    </button>
                </div>
            </div>

            <!-- Meetings Responsive Table with Manageable Columns -->
            <div class="modern-table-responsive" style="flex: 1; overflow-x: auto; overflow-y: auto;">
                <table class="modern-leads-table" id="meetingsTable">
                    <thead>
                        <tr>
                            <th class="col-mtg-sno draggable-th" onclick="sortMeetingsTable('col-mtg-sno')" style="width: 45px; text-align: center;">
                                <div class="th-sort-wrapper"><span>#</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-title draggable-th" onclick="sortMeetingsTable('col-mtg-title')">
                                <div class="th-sort-wrapper"><span>Demo Session</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-from draggable-th" onclick="sortMeetingsTable('col-mtg-from')">
                                <div class="th-sort-wrapper"><span>Start</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-to draggable-th" onclick="sortMeetingsTable('col-mtg-to')">
                                <div class="th-sort-wrapper"><span>End</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-firm draggable-th" onclick="sortMeetingsTable('col-mtg-firm')">
                                <div class="th-sort-wrapper"><span>Account / Firm</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-contact draggable-th" onclick="sortMeetingsTable('col-mtg-contact')">
                                <div class="th-sort-wrapper"><span>Contact</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-engineer draggable-th" onclick="sortMeetingsTable('col-mtg-engineer')">
                                <div class="th-sort-wrapper"><span>Specialist</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th class="col-mtg-status draggable-th" onclick="sortMeetingsTable('col-mtg-status')">
                                <div class="th-sort-wrapper"><span>Status</span><i data-lucide="arrow-up-down" class="sort-icon"></i></div>
                            </th>
                            <th style="text-align: right; width: 40px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="meetingsList">
                        <?php if (!empty($meetings)): ?>
                            <?php foreach ($meetings as $mIdx => $mtg): ?>
                                <tr class="meeting-record-row"
                                    data-is-today="<?php echo $mtg['is_today']; ?>"
                                    data-is-this-week="<?php echo $mtg['is_this_week']; ?>"
                                    data-val-col-mtg-sno="<?php echo $mIdx + 1; ?>"
                                    data-val-col-mtg-title="<?php echo htmlspecialchars(strtolower($mtg['title'])); ?>"
                                    data-val-col-mtg-from="<?php echo htmlspecialchars(strtolower($mtg['from'])); ?>"
                                    data-val-col-mtg-to="<?php echo htmlspecialchars(strtolower($mtg['to'])); ?>"
                                    data-val-col-mtg-firm="<?php echo htmlspecialchars(strtolower($mtg['related_to'])); ?>"
                                    data-val-col-mtg-contact="<?php echo htmlspecialchars(strtolower($mtg['contact_name'])); ?>"
                                    data-val-col-mtg-engineer="<?php echo htmlspecialchars(strtolower($mtg['engineer'])); ?>"
                                    data-val-col-mtg-status="<?php echo htmlspecialchars(strtolower($mtg['status'])); ?>">
                                    <td class="col-mtg-sno" style="text-align: center; font-weight: 700; color: var(--crm-text-sub); font-size: 0.75rem;">
                                        <?php echo $mIdx + 1; ?>
                                    </td>
                                    <td class="col-mtg-title">
                                        <a href="index.php?page=demo" style="color: var(--crm-primary); font-weight: 600; text-decoration: none;">
                                            <?php echo htmlspecialchars($mtg['title']); ?>
                                        </a>
                                    </td>
                                    <td class="col-mtg-from" style="color: var(--crm-text-sub); font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($mtg['from']); ?>
                                    </td>
                                    <td class="col-mtg-to" style="color: var(--crm-text-sub); font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($mtg['to']); ?>
                                    </td>
                                    <td class="col-mtg-firm" style="font-weight: 500; font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($mtg['related_to']); ?>
                                    </td>
                                    <td class="col-mtg-contact" style="color: var(--crm-text-sub); font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($mtg['contact_name']); ?>
                                    </td>
                                    <td class="col-mtg-engineer" style="color: var(--crm-text-sub); font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($mtg['engineer']); ?>
                                    </td>
                                    <td class="col-mtg-status">
                                        <span class="modern-task-status-badge">
                                            <?php echo htmlspecialchars($mtg['status']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="index.php?page=demo" class="modern-btn" style="width: 24px; height: 24px; padding: 0;" title="Open Demo Manager">
                                            <i data-lucide="chevron-right" style="width: 13px; height: 13px;"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="padding: 36px; text-align: center; color: var(--crm-text-sub); font-size: 0.85rem;">
                                    No demos scheduled for today.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer with Page Size & Pagination for Meetings -->
            <div class="modern-widget-footer" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.74rem; color: var(--crm-text-sub);">
                        <span>Show:</span>
                        <select id="meetingsPageSize" onchange="changeMeetingsPageSize(this.value)" style="background: var(--crm-bg-card); color: var(--crm-text-title); border: 1px solid var(--crm-border); border-radius: 4px; padding: 2px 6px; font-size: 0.74rem; cursor: pointer;">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div id="footerMeetingsCount" style="font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub);">Showing <?php echo count($meetings); ?> Meetings</div>
                </div>
                <div class="modern-pagination-nav" style="display: inline-flex; align-items: center; gap: 4px;">
                    <button type="button" class="modern-btn" id="btnMtgPrev" onclick="changeMeetingsPage(-1)" style="width: 24px; height: 24px; padding: 0;" title="Previous Page">
                        <i data-lucide="chevron-left" style="width: 13px; height: 13px;"></i>
                    </button>
                    <span id="mtgPageIndicator" style="font-size: 0.74rem; font-weight: 600; color: var(--crm-text-sub); padding: 0 4px;">Page 1</span>
                    <button type="button" class="modern-btn" id="btnMtgNext" onclick="changeMeetingsPage(1)" style="width: 24px; height: 24px; padding: 0;" title="Next Page">
                        <i data-lucide="chevron-right" style="width: 13px; height: 13px;"></i>
                    </button>
                </div>
            </div>
        </div>


        <!-- =================================================================== -->
        <!-- BOX 4: Amount by Pipeline Stage (With Own Sort)                     -->
        <!-- =================================================================== -->
        <div class="modern-widget-card" id="card_stage">
            <!-- Header -->
            <div class="modern-widget-header">
                <div style="position: relative;">
                    <button type="button" class="modern-title-dropdown-btn" onclick="toggleDropdownMenu('menu_stage', event)">
                        <span id="label_stage">AMOUNT BY PIPELINE STAGE</span>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px;"></i>
                    </button>
                    <!-- Stage Pipeline Dropdown List -->
                    <div class="modern-dropdown-menu" id="menu_stage">
                        <div class="modern-dropdown-item active" onclick="selectStageFilter('AMOUNT BY PIPELINE STAGE', this)">AMOUNT BY PIPELINE STAGE</div>
                    </div>
                </div>

                <div class="modern-widget-actions">
                    <!-- Sort Trigger for Pipeline Stages -->
                    <button type="button" class="modern-btn" style="height: 28px; padding: 0 8px; font-size: 0.74rem; gap: 4px;" onclick="sortPipelineStages()" title="Sort Pipeline Stages">
                        <i data-lucide="arrow-up-down" style="width: 12px; height: 12px;"></i>
                        <span id="sortPipelineLabel">Sort</span>
                    </button>

                    <!-- Manage Width Toggle -->
                    <button type="button" class="modern-width-pill" title="Toggle Card Width (50% / 100%)" onclick="toggleWidgetWidth('card_stage')">
                        <i data-lucide="columns-2" style="width: 12px; height: 12px;"></i>
                        <span class="width-text">50%</span>
                    </button>
                </div>
            </div>

            <!-- Stage Funnel List -->
            <div id="pipelineStagesList" style="padding: 12px 0; flex: 1; overflow-y: auto;">
                <?php if (!empty($stage_amounts)): ?>
                    <?php foreach ($stage_amounts as $stg): 
                        $pct = ($total_pipeline_val > 0) ? round(($stg['amount'] / $total_pipeline_val) * 100) : 10;
                    ?>
                        <div class="modern-stage-row" onclick="window.location.href='index.php?page=pipeline'"
                             data-val-amount="<?php echo floatval($stg['amount']); ?>"
                             data-val-count="<?php echo intval($stg['count']); ?>"
                             data-val-stage="<?php echo htmlspecialchars(strtolower($stg['stage'])); ?>">
                            <div class="modern-stage-meta">
                                <span style="color: var(--crm-text-title);"><?php echo htmlspecialchars($stg['stage']); ?> (<?php echo $stg['count']; ?>)</span>
                                <span style="color: var(--crm-primary); font-weight: 700;">₹<?php echo number_format($stg['amount']); ?></span>
                            </div>
                            <div class="modern-stage-bar-bg">
                                <div class="modern-stage-bar-fill" style="width: <?php echo max(6, min(100, $pct)); ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: var(--crm-text-sub); font-size: 0.85rem;">
                        No active pipeline deals found.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Total -->
            <div class="modern-widget-footer">
                <div>Total Pipeline Value: <strong style="color: var(--crm-primary);">₹<?php echo number_format($total_pipeline_val); ?></strong></div>
                <a href="index.php?page=pipeline" style="color: var(--crm-primary); text-decoration: none; font-weight: 600;">Visual Pipeline &rarr;</a>
            </div>
        </div>

    </div>

</div>

<!-- Manage Columns Centered Modal Dialog (Zoho CRM Enterprise Style) -->
<div id="modernColumnDrawer" class="modern-modal-backdrop">
    <div class="modern-columns-modal-dialog">
        <div class="modern-columns-modal-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="sliders" style="width: 17px; height: 17px; color: var(--crm-primary);"></i>
                <h3 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--crm-text-title);">Manage Columns</h3>
            </div>
            <button type="button" onclick="closeColumnDrawer()" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub); display: inline-flex; align-items: center; padding: 2px;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <div class="modern-columns-modal-body">
            <!-- Search Filter -->
            <div style="margin-bottom: 12px; position: relative;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--crm-text-sub);"></i>
                <input type="text" id="colSearchInput" class="modern-search-input" style="width: 100%; box-sizing: border-box; padding-left: 32px; height: 32px; font-size: 0.8rem;" placeholder="Search column..." onkeyup="filterColumnCheckboxes(this.value)">
            </div>

            <!-- Select All / Clear All & Drag Notice -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 0 2px; font-size: 0.76rem;">
                <span style="color: var(--crm-text-sub); font-weight: 600;">Reorder / Toggle visibility:</span>
                <div style="display: flex; gap: 12px;">
                    <button type="button" onclick="setAllColumnCheckboxes(true)" style="background: none; border: none; color: var(--crm-primary); font-weight: 700; cursor: pointer; padding: 0; font-size: 0.76rem;">Select All</button>
                    <button type="button" onclick="setAllColumnCheckboxes(false)" style="background: none; border: none; color: var(--crm-text-sub); font-weight: 600; cursor: pointer; padding: 0; font-size: 0.76rem;">Clear All</button>
                </div>
            </div>

            <!-- 16 Requested Columns Checkbox List (Reorderable / Draggable) -->
            <div id="columnCheckboxesList" style="display: flex; flex-direction: column; gap: 5px; max-height: 380px; overflow-y: auto; padding-right: 4px;">
                <?php 
                $columnList = [
                    'col-sno'           => ['id' => 'cb_col_sno',           'label' => 'S.No.',                 'default' => true],
                    'col-lead-id'       => ['id' => 'cb_col_lead_id',       'label' => 'Lead ID',               'default' => true],
                    'col-assigned'      => ['id' => 'cb_col_assigned',      'label' => 'Assigned to',           'default' => true],
                    'col-contact-person'=> ['id' => 'cb_col_contact_person','label' => 'Person Name',           'default' => true],
                    'col-name'          => ['id' => 'cb_col_name',          'label' => 'Client Details',        'default' => true],
                    'col-group'         => ['id' => 'cb_col_group',         'label' => 'Group',                 'default' => true],
                    'col-status'        => ['id' => 'cb_col_status',        'label' => 'Status',                'default' => true],
                    'col-priority'      => ['id' => 'cb_col_priority',      'label' => 'Priority',              'default' => true],
                    'col-source'        => ['id' => 'cb_col_source',        'label' => 'Source',                'default' => true],
                    'col-enq-for'       => ['id' => 'cb_col_enq_for',       'label' => 'Enq For',               'default' => false],
                    'col-tags'          => ['id' => 'cb_col_tags',          'label' => 'Tags',                  'default' => false],
                    'col-address'       => ['id' => 'cb_col_address',       'label' => 'Address',               'default' => false],
                    'col-remarks'       => ['id' => 'cb_col_remarks',       'label' => 'Remarks',               'default' => true],
                    'col-scheduled-fup' => ['id' => 'cb_col_scheduled_fup', 'label' => 'Scheduled Follow-up',   'default' => true],
                    'col-created-at'    => ['id' => 'cb_col_created_at',    'label' => 'Created At',            'default' => true],
                    'col-activity'      => ['id' => 'cb_col_activity',      'label' => 'Last Activity',         'default' => false]
                ];
                foreach ($columnList as $targetClass => $colMeta): 
                ?>
                    <div class="col-item-card" draggable="true" data-col="<?php echo $targetClass; ?>" ondragstart="handleColDragStart(event)" ondragover="handleColDragOver(event)" ondrop="handleColDrop(event)" ondragend="handleColDragEnd(event)">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="grip-vertical" style="width: 14px; height: 14px; color: var(--crm-text-sub); cursor: grab;"></i>
                            <input type="checkbox" id="<?php echo $colMeta['id']; ?>" class="modern-col-toggle-cb" data-target="<?php echo $targetClass; ?>" <?php echo $colMeta['default'] ? 'checked' : ''; ?> onclick="event.stopPropagation(); applyModernColumnStyles();" style="accent-color: var(--crm-primary); width: 16px; height: 16px; cursor: pointer;">
                            <span style="font-size: 0.82rem; font-weight: 600; color: var(--crm-text-title);"><?php echo htmlspecialchars($colMeta['label']); ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <button type="button" class="modern-btn" style="width: 22px; height: 22px; padding: 0;" title="Move Column Up" onclick="moveColumnItem('<?php echo $targetClass; ?>', -1, event)">
                                <i data-lucide="chevron-up" style="width: 12px; height: 12px;"></i>
                            </button>
                            <button type="button" class="modern-btn" style="width: 22px; height: 22px; padding: 0;" title="Move Column Down" onclick="moveColumnItem('<?php echo $targetClass; ?>', 1, event)">
                                <i data-lucide="chevron-down" style="width: 12px; height: 12px;"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="modern-columns-modal-footer">
            <button type="button" onclick="closeColumnDrawer()" class="modern-btn">Cancel</button>
            <button type="button" onclick="saveColumnPreferences()" class="modern-btn primary">Save</button>
        </div>
    </div>
</div>

<!-- Manage Tasks Columns Modal (Box 2) -->
<div id="modernTasksColumnModal" class="modern-modal-backdrop">
    <div class="modern-columns-modal-dialog">
        <div class="modern-columns-modal-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="sliders" style="width: 17px; height: 17px; color: var(--crm-primary);"></i>
                <h3 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--crm-text-title);">Manage Task Columns</h3>
            </div>
            <button type="button" onclick="closeTasksColumnModal()" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub); display: inline-flex; align-items: center; padding: 2px;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div class="modern-columns-modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 0.76rem;">
                <span style="color: var(--crm-text-sub); font-weight: 600;">Toggle task columns:</span>
                <div style="display: flex; gap: 12px;">
                    <button type="button" onclick="setAllTasksCheckboxes(true)" style="background: none; border: none; color: var(--crm-primary); font-weight: 700; cursor: pointer; padding: 0; font-size: 0.76rem;">Select All</button>
                    <button type="button" onclick="setAllTasksCheckboxes(false)" style="background: none; border: none; color: var(--crm-text-sub); font-weight: 600; cursor: pointer; padding: 0; font-size: 0.76rem;">Clear All</button>
                </div>
            </div>
            <div id="tasksCheckboxesList" style="display: flex; flex-direction: column; gap: 5px; max-height: 380px; overflow-y: auto;">
                <?php 
                $taskColumns = [
                    'col-tsk-sno'      => ['label' => 'S.No. (#)',             'default' => true],
                    'col-tsk-due'      => ['label' => 'Due Date',              'default' => true],
                    'col-tsk-subject'  => ['label' => 'Subject / Task Item',   'default' => true],
                    'col-tsk-related'  => ['label' => 'Related Account / Firm','default' => true],
                    'col-tsk-priority' => ['label' => 'Priority',              'default' => true],
                    'col-tsk-status'   => ['label' => 'Status',                'default' => true],
                    'col-tsk-owner'    => ['label' => 'Assigned To',           'default' => true]
                ];
                foreach ($taskColumns as $tColClass => $tMeta): ?>
                    <div class="col-item-card" data-col="<?php echo $tColClass; ?>">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" class="tasks-col-cb" data-target="<?php echo $tColClass; ?>" <?php echo $tMeta['default'] ? 'checked' : ''; ?> onclick="applyTasksColumnStyles()" style="accent-color: var(--crm-primary); width: 16px; height: 16px; cursor: pointer;">
                            <span style="font-size: 0.82rem; font-weight: 600; color: var(--crm-text-title);"><?php echo htmlspecialchars($tMeta['label']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modern-columns-modal-footer">
            <button type="button" onclick="closeTasksColumnModal()" class="modern-btn">Cancel</button>
            <button type="button" onclick="saveTasksColumnPreferences()" class="modern-btn primary">Save</button>
        </div>
    </div>
</div>

<!-- Manage Meetings Columns Modal (Box 3) -->
<div id="modernMeetingsColumnModal" class="modern-modal-backdrop">
    <div class="modern-columns-modal-dialog">
        <div class="modern-columns-modal-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="sliders" style="width: 17px; height: 17px; color: var(--crm-primary);"></i>
                <h3 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--crm-text-title);">Manage Meeting Columns</h3>
            </div>
            <button type="button" onclick="closeMeetingsColumnModal()" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub); display: inline-flex; align-items: center; padding: 2px;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div class="modern-columns-modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; font-size: 0.76rem;">
                <span style="color: var(--crm-text-sub); font-weight: 600;">Toggle meeting columns:</span>
                <div style="display: flex; gap: 12px;">
                    <button type="button" onclick="setAllMeetingsCheckboxes(true)" style="background: none; border: none; color: var(--crm-primary); font-weight: 700; cursor: pointer; padding: 0; font-size: 0.76rem;">Select All</button>
                    <button type="button" onclick="setAllMeetingsCheckboxes(false)" style="background: none; border: none; color: var(--crm-text-sub); font-weight: 600; cursor: pointer; padding: 0; font-size: 0.76rem;">Clear All</button>
                </div>
            </div>
            <div id="meetingsCheckboxesList" style="display: flex; flex-direction: column; gap: 5px; max-height: 380px; overflow-y: auto;">
                <?php 
                $mtgColumns = [
                    'col-mtg-sno'      => ['label' => 'S.No. (#)',             'default' => true],
                    'col-mtg-title'    => ['label' => 'Demo Session & Mode',   'default' => true],
                    'col-mtg-from'     => ['label' => 'Start Time',            'default' => true],
                    'col-mtg-to'       => ['label' => 'End Time',              'default' => true],
                    'col-mtg-firm'     => ['label' => 'Account / Firm',        'default' => true],
                    'col-mtg-contact'  => ['label' => 'Contact Person',        'default' => true],
                    'col-mtg-engineer' => ['label' => 'Specialist Engineer',   'default' => true],
                    'col-mtg-status'   => ['label' => 'Status',                'default' => true]
                ];
                foreach ($mtgColumns as $mColClass => $mMeta): ?>
                    <div class="col-item-card" data-col="<?php echo $mColClass; ?>">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" class="meetings-col-cb" data-target="<?php echo $mColClass; ?>" <?php echo $mMeta['default'] ? 'checked' : ''; ?> onclick="applyMeetingsColumnStyles()" style="accent-color: var(--crm-primary); width: 16px; height: 16px; cursor: pointer;">
                            <span style="font-size: 0.82rem; font-weight: 600; color: var(--crm-text-title);"><?php echo htmlspecialchars($mMeta['label']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modern-columns-modal-footer">
            <button type="button" onclick="closeMeetingsColumnModal()" class="modern-btn">Cancel</button>
            <button type="button" onclick="saveMeetingsColumnPreferences()" class="modern-btn primary">Save</button>
        </div>
    </div>
</div>

<!-- Task Details Slide-in Modal -->
<div id="modernTaskModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(2px);">
    <div style="background: var(--crm-bg-card); border: 1px solid var(--crm-border); border-radius: 10px; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--crm-border);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="check-circle-2" style="width: 18px; height: 18px; color: var(--crm-primary);"></i>
                <h3 style="margin: 0; font-size: 1rem; font-weight: 700;">Task Overview</h3>
            </div>
            <button type="button" onclick="closeModernTaskModal()" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div style="padding: 18px; font-size: 0.85rem;">
            <div style="margin-bottom: 14px;">
                <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600; text-transform: uppercase;">Subject</div>
                <div id="taskModalSubject" style="font-size: 1rem; font-weight: 700; color: var(--crm-text-title); margin-top: 2px;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <div>
                    <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600; text-transform: uppercase;">Status</div>
                    <div id="taskModalStatus" style="font-weight: 600; color: var(--crm-text-title); margin-top: 2px;"></div>
                </div>
                <div>
                    <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600; text-transform: uppercase;">Priority</div>
                    <div id="taskModalPriority" style="font-weight: 600; color: var(--crm-text-title); margin-top: 2px;"></div>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div>
                    <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600; text-transform: uppercase;">Related Account</div>
                    <div id="taskModalRelated" style="font-weight: 600; color: #2563eb; margin-top: 2px;"></div>
                </div>
                <div>
                    <div style="font-size: 0.72rem; color: var(--crm-text-sub); font-weight: 600; text-transform: uppercase;">Due Date</div>
                    <div id="taskModalDueDate" style="font-weight: 600; color: var(--crm-text-title); margin-top: 2px;"></div>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; border-top: 1px solid var(--crm-border); padding-top: 14px;">
                <button type="button" onclick="closeModernTaskModal()" class="modern-btn">Close</button>
                <a id="taskModalLeadLink" href="#" class="modern-btn primary">
                    <span>View Lead Dossier</span>
                    <i data-lucide="arrow-right" style="width: 14px; height: 14px;"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Quick Create Modal (Add Lead or Task) -->
<div id="modernQuickAddModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(2px);">
    <div style="background: var(--crm-bg-card); border: 1px solid var(--crm-border); border-radius: 10px; width: 100%; max-width: 460px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--crm-border);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i data-lucide="plus-circle" style="width: 18px; height: 18px; color: var(--crm-primary);"></i>
                <h3 style="margin: 0; font-size: 0.95rem; font-weight: 700;">Quick Create Item</h3>
            </div>
            <button type="button" onclick="closeModernQuickAddModal()" style="background: none; border: none; cursor: pointer; color: var(--crm-text-sub);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <form id="modernQuickAddForm" onsubmit="handleModernQuickAdd(event)" style="padding: 18px;">
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Record Type</label>
                <select id="quickRecordType" class="modern-view-select" style="width: 100%;">
                    <option value="lead">New Sales Opportunity / Lead</option>
                    <option value="task">Follow-up Task</option>
                </select>
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Lead / Contact Name *</label>
                <input type="text" id="quickSubject" required class="modern-search-input" style="width: 100%; padding-left: 12px;" placeholder="e.g. Dr. Rajesh Sharma">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Company / Firm Name *</label>
                <input type="text" id="quickCompany" required class="modern-search-input" style="width: 100%; padding-left: 12px;" placeholder="e.g. Apex Health & Chemist Ltd">
            </div>
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Phone Number</label>
                <input type="tel" id="quickPhone" class="modern-search-input" style="width: 100%; padding-left: 12px;" placeholder="e.g. 9876543210">
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Target Date</label>
                    <input type="date" id="quickDueDate" class="modern-search-input" style="width: 100%; padding-left: 10px;" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.76rem; font-weight: 600; color: var(--crm-text-sub); margin-bottom: 4px;">Priority</label>
                    <select id="quickPriority" class="modern-view-select" style="width: 100%;">
                        <option value="High">High</option>
                        <option value="Normal" selected>Normal</option>
                        <option value="Low">Low</option>
                    </select>
                </div>
            </div>
            <div id="quickAddNotice" style="display: none; padding: 8px 12px; border-radius: 6px; font-size: 0.78rem; margin-bottom: 12px;"></div>
            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" onclick="closeModernQuickAddModal()" class="modern-btn">Cancel</button>
                <button type="submit" id="btnSaveQuickRecord" class="modern-btn primary">Save Record</button>
            </div>
        </form>
    </div>
</div>

<script>
// =========================================================================
// 1. DYNAMIC WIDTH MANAGEMENT (50% vs 100%)
// =========================================================================
const WIDGET_CARDS = ['card_activities', 'card_tasks', 'card_meetings', 'card_stage'];

function initWidgetWidths() {
    WIDGET_CARDS.forEach(cardId => {
        const el = document.getElementById(cardId);
        if (!el) return;
        const saved = localStorage.getItem('width_' + cardId);
        if (saved === '100') {
            el.classList.add('full-width');
            const pillText = el.querySelector('.width-text');
            if (pillText) pillText.textContent = '100%';
        }
    });
}

function toggleWidgetWidth(cardId) {
    const el = document.getElementById(cardId);
    if (!el) return;
    el.classList.toggle('full-width');
    const isFull = el.classList.contains('full-width');
    const pillText = el.querySelector('.width-text');
    if (pillText) pillText.textContent = isFull ? '100%' : '50%';
    localStorage.setItem('width_' + cardId, isFull ? '100' : '50');
}

// =========================================================================
// 2. DROPDOWN FILTERS (RELIABLE CLICK & EXPANSION)
// =========================================================================
function toggleDropdownMenu(menuId, event) {
    if (event) {
        event.stopPropagation();
    }
    const target = document.getElementById(menuId);
    if (!target) return;
    const isShown = target.classList.contains('show');
    document.querySelectorAll('.modern-dropdown-menu').forEach(m => m.classList.remove('show'));
    if (!isShown) {
        target.classList.add('show');
    }
}

// Global click dismisses open menus
document.addEventListener('click', function(e) {
    if (!e.target.closest('.modern-dropdown-menu') && 
        !e.target.closest('.modern-title-dropdown-btn') && 
        !e.target.closest('[data-lucide="sliders-horizontal"]')) {
        document.querySelectorAll('.modern-dropdown-menu').forEach(m => m.classList.remove('show'));
    }
});

function updateActiveDropdownItem(menuId, itemEl) {
    const menu = document.getElementById(menuId);
    if (menu) {
        menu.querySelectorAll('.modern-dropdown-item').forEach(i => i.classList.remove('active'));
        if (itemEl) itemEl.classList.add('active');
        menu.classList.remove('show');
    }
}

/**
 * Box 1: Leads & Activities Pagination & Date Filter
 */
let activitiesCurrentPage = 1;
let activitiesPageSize = 25;

function changeActivitiesPageSize(size) {
    activitiesPageSize = size;
    activitiesCurrentPage = 1;
    localStorage.setItem('modern_act_pagesize', size);
    renderActivitiesPagination();
}

function changeActivitiesPage(dir) {
    activitiesCurrentPage += dir;
    renderActivitiesPagination();
}

function selectActivityFilter(filterName, itemEl) {
    document.getElementById('label_activities').textContent = filterName;
    updateActiveDropdownItem('menu_activities', itemEl);
    activitiesCurrentPage = 1;
    localStorage.setItem('modern_act_filter', filterName);
    renderActivitiesPagination();
}

function renderActivitiesPagination() {
    const list = document.getElementById('activitiesList');
    if (!list) return;

    const labelEl = document.getElementById('label_activities');
    const labelText = labelEl ? labelEl.textContent.trim() : '';
    const allRows = Array.from(list.querySelectorAll('tr.activity-record-row'));

    const matched = allRows.filter(row => {
        const isToday = row.dataset.isToday === '1';
        const isThisWeek = row.dataset.isThisWeek === '1';
        const isNextWeek = row.dataset.isNextWeek === '1';
        const isOverdue = row.dataset.isOverdue === '1';
        const hasScheduled = row.dataset.hasScheduled === '1';

        if (labelText === 'My open activities for today') return (hasScheduled && isToday);
        if (labelText === 'My open activities for this week') return (hasScheduled && isThisWeek);
        if (labelText === 'My open activities for next week') return (hasScheduled && isNextWeek);
        return true;
    });

    const totalMatched = matched.length;
    const pageSize = (activitiesPageSize === 'all') ? totalMatched : parseInt(activitiesPageSize, 10);
    const totalPages = pageSize > 0 ? Math.ceil(totalMatched / pageSize) : 1;

    if (activitiesCurrentPage > totalPages) activitiesCurrentPage = totalPages || 1;
    if (activitiesCurrentPage < 1) activitiesCurrentPage = 1;

    const startIdx = (activitiesCurrentPage - 1) * pageSize;
    const endIdx = (activitiesPageSize === 'all') ? totalMatched : Math.min(startIdx + pageSize, totalMatched);

    allRows.forEach(r => r.style.display = 'none');
    matched.slice(startIdx, endIdx).forEach((r, idx) => {
        r.style.display = '';
        const snoEl = r.querySelector('.col-sno');
        if (snoEl) snoEl.textContent = (startIdx + idx + 1);
    });

    const footerCount = document.getElementById('footerActivitiesCount');
    if (footerCount) {
        if (totalMatched === 0) {
            footerCount.textContent = 'Showing 0 of 0 Leads';
        } else {
            footerCount.textContent = 'Showing ' + (startIdx + 1) + ' - ' + endIdx + ' of ' + totalMatched + ' Leads';
        }
    }

    const pageIndicator = document.getElementById('actPageIndicator');
    if (pageIndicator) {
        pageIndicator.textContent = 'Page ' + (totalPages === 0 ? 0 : activitiesCurrentPage) + ' of ' + totalPages;
    }

    const prevBtn = document.getElementById('btnActPrev');
    if (prevBtn) prevBtn.disabled = (activitiesCurrentPage <= 1);
    const nextBtn = document.getElementById('btnActNext');
    if (nextBtn) nextBtn.disabled = (activitiesCurrentPage >= totalPages || totalPages === 0);

    const emptyNotice = document.getElementById('filterEmptyNotice');
    if (emptyNotice) {
        emptyNotice.style.display = (totalMatched === 0 && allRows.length > 0) ? 'block' : 'none';
        if (totalMatched === 0) {
            let desc = 'today';
            if (labelText.includes('this week')) desc = 'this week';
            else if (labelText.includes('next week')) desc = 'next week';
            const textEl = emptyNotice.querySelector('.empty-filter-text');
            if (textEl) textEl.textContent = 'No follow-up activities scheduled for ' + desc + '.';
        }
    }
}

/**
 * Box 2: Tasks Pagination & Filters
 */
let tasksCurrentPage = 1;
let tasksPageSize = 10;
let currentTaskFilter = 'My Tasks';

function changeTasksPageSize(size) {
    tasksPageSize = size;
    tasksCurrentPage = 1;
    localStorage.setItem('modern_task_pagesize', size);
    renderTasksPagination();
}

function changeTasksPage(dir) {
    tasksCurrentPage += dir;
    renderTasksPagination();
}

function selectTaskFilter(filterName, itemEl) {
    currentTaskFilter = filterName;
    document.getElementById('label_tasks').textContent = filterName;
    updateActiveDropdownItem('menu_tasks', itemEl);
    tasksCurrentPage = 1;
    localStorage.setItem('modern_task_filter', filterName);
    renderTasksPagination();
}

function renderTasksPagination() {
    const list = document.getElementById('tasksList');
    if (!list) return;

    const allRows = Array.from(list.querySelectorAll('tr.task-record-row'));
    const matched = allRows.filter(row => {
        const status = (row.dataset.status || '').toLowerCase();
        const isOverdue = row.dataset.isOverdue === '1';
        const isToday = row.dataset.isToday === '1';

        if (currentTaskFilter.includes('Closed')) return (status === 'completed');
        if (currentTaskFilter.includes('Open')) return (status !== 'completed');
        if (currentTaskFilter.includes('Overdue')) return (isOverdue && status !== 'completed');
        if (currentTaskFilter.includes('Today\'s')) return isToday;
        return true;
    });

    const totalMatched = matched.length;
    const pageSize = (tasksPageSize === 'all') ? totalMatched : parseInt(tasksPageSize, 10);
    const totalPages = pageSize > 0 ? Math.ceil(totalMatched / pageSize) : 1;

    if (tasksCurrentPage > totalPages) tasksCurrentPage = totalPages || 1;
    if (tasksCurrentPage < 1) tasksCurrentPage = 1;

    const startIdx = (tasksCurrentPage - 1) * pageSize;
    const endIdx = (tasksPageSize === 'all') ? totalMatched : Math.min(startIdx + pageSize, totalMatched);

    allRows.forEach(r => r.style.display = 'none');
    matched.slice(startIdx, endIdx).forEach((r, idx) => {
        r.style.display = '';
        const snoEl = r.querySelector('.col-tsk-sno');
        if (snoEl) snoEl.textContent = (startIdx + idx + 1);
    });

    const footerCount = document.getElementById('footerTasksCount');
    if (footerCount) {
        if (totalMatched === 0) {
            footerCount.textContent = 'Showing 0 Tasks';
        } else {
            footerCount.textContent = 'Showing ' + (startIdx + 1) + ' - ' + endIdx + ' of ' + totalMatched + ' Tasks';
        }
    }

    const pageIndicator = document.getElementById('tskPageIndicator');
    if (pageIndicator) {
        pageIndicator.textContent = 'Page ' + (totalPages === 0 ? 0 : tasksCurrentPage) + ' of ' + totalPages;
    }

    const prevBtn = document.getElementById('btnTskPrev');
    if (prevBtn) prevBtn.disabled = (tasksCurrentPage <= 1);
    const nextBtn = document.getElementById('btnTskNext');
    if (nextBtn) nextBtn.disabled = (tasksCurrentPage >= totalPages || totalPages === 0);
}

/**
 * Box 3: Meetings Pagination & Filters
 */
let meetingsCurrentPage = 1;
let meetingsPageSize = 10;
let currentMeetingFilter = 'My Meetings Today';

function changeMeetingsPageSize(size) {
    meetingsPageSize = size;
    meetingsCurrentPage = 1;
    localStorage.setItem('modern_meeting_pagesize', size);
    renderMeetingsPagination();
}

function changeMeetingsPage(dir) {
    meetingsCurrentPage += dir;
    renderMeetingsPagination();
}

function selectMeetingFilter(filterName, itemEl) {
    currentMeetingFilter = filterName;
    document.getElementById('label_meetings').textContent = filterName;
    updateActiveDropdownItem('menu_meetings', itemEl);
    meetingsCurrentPage = 1;
    localStorage.setItem('modern_meeting_filter', filterName);
    renderMeetingsPagination();
}

function renderMeetingsPagination() {
    const list = document.getElementById('meetingsList');
    if (!list) return;

    const allRows = Array.from(list.querySelectorAll('tr.meeting-record-row'));
    const matched = allRows.filter(row => {
        const isToday = row.dataset.isToday === '1';
        const isThisWeek = row.dataset.isThisWeek === '1';

        if (currentMeetingFilter.includes('Today')) return isToday;
        if (currentMeetingFilter.includes('This Week')) return (isToday || isThisWeek);
        return true;
    });

    const totalMatched = matched.length;
    const pageSize = (meetingsPageSize === 'all') ? totalMatched : parseInt(meetingsPageSize, 10);
    const totalPages = pageSize > 0 ? Math.ceil(totalMatched / pageSize) : 1;

    if (meetingsCurrentPage > totalPages) meetingsCurrentPage = totalPages || 1;
    if (meetingsCurrentPage < 1) meetingsCurrentPage = 1;

    const startIdx = (meetingsCurrentPage - 1) * pageSize;
    const endIdx = (meetingsPageSize === 'all') ? totalMatched : Math.min(startIdx + pageSize, totalMatched);

    allRows.forEach(r => r.style.display = 'none');
    matched.slice(startIdx, endIdx).forEach((r, idx) => {
        r.style.display = '';
        const snoEl = r.querySelector('.col-mtg-sno');
        if (snoEl) snoEl.textContent = (startIdx + idx + 1);
    });

    const footerCount = document.getElementById('footerMeetingsCount');
    if (footerCount) {
        if (totalMatched === 0) {
            footerCount.textContent = 'Showing 0 Meetings';
        } else {
            footerCount.textContent = 'Showing ' + (startIdx + 1) + ' - ' + endIdx + ' of ' + totalMatched + ' Meetings';
        }
    }

    const pageIndicator = document.getElementById('mtgPageIndicator');
    if (pageIndicator) {
        pageIndicator.textContent = 'Page ' + (totalPages === 0 ? 0 : meetingsCurrentPage) + ' of ' + totalPages;
    }

    const prevBtn = document.getElementById('btnMtgPrev');
    if (prevBtn) prevBtn.disabled = (meetingsCurrentPage <= 1);
    const nextBtn = document.getElementById('btnMtgNext');
    if (nextBtn) nextBtn.disabled = (meetingsCurrentPage >= totalPages || totalPages === 0);
}

function selectStageFilter(filterName, itemEl) {
    document.getElementById('label_stage').textContent = filterName;
    updateActiveDropdownItem('menu_stage', itemEl);
}

function toggleOverdueVisibility(shouldHide) {
    document.querySelectorAll('.is-overdue-row').forEach(row => {
        row.style.display = shouldHide ? 'none' : '';
    });
}

// =========================================================================
// 3. GLOBAL LIVE SEARCH & SORTING
// =========================================================================
function filterModernGlobalRecords() {
    const q = (document.getElementById('modernGlobalSearch').value || '').toLowerCase().trim();
    document.querySelectorAll('.activity-record-row, .task-record-row, .meeting-record-row').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}

let sortDirections = {};
function sortWidgetList(listContainerId) {
    const list = document.getElementById(listContainerId);
    if (!list) return;
    const rows = Array.from(list.children);
    if (rows.length <= 1) return;

    sortDirections[listContainerId] = !sortDirections[listContainerId];
    const isAsc = sortDirections[listContainerId];

    rows.sort((a, b) => {
        const textA = a.innerText.toLowerCase();
        const textB = b.innerText.toLowerCase();
        return isAsc ? textA.localeCompare(textB) : textB.localeCompare(textA);
    });

    rows.forEach(r => list.appendChild(r));
}

function toggleRowCompleted(chk) {
    const row = chk.closest('tr') || chk.closest('.modern-row-item');
    if (row) {
        row.style.opacity = chk.checked ? '0.45' : '1';
        row.style.textDecoration = chk.checked ? 'line-through' : 'none';
    }
}

function toggleSelectAllRows(isChecked) {
    document.querySelectorAll('#activitiesList .modern-activity-checkbox').forEach(cb => {
        cb.checked = isChecked;
        toggleRowCompleted(cb);
    });
}

// =========================================================================
// 4. TASK OVERVIEW MODAL
// =========================================================================
function showModernTaskModal(subject, status, priority, related, dueDate, leadId) {
    document.getElementById('taskModalSubject').textContent = subject;
    document.getElementById('taskModalStatus').textContent = status;
    document.getElementById('taskModalPriority').textContent = priority;
    document.getElementById('taskModalRelated').textContent = related;
    document.getElementById('taskModalDueDate').textContent = dueDate;
    document.getElementById('taskModalLeadLink').href = 'index.php?page=lead_details&id=' + leadId;

    const modal = document.getElementById('modernTaskModal');
    if (modal) modal.style.display = 'flex';
}

function closeModernTaskModal() {
    const modal = document.getElementById('modernTaskModal');
    if (modal) modal.style.display = 'none';
}

// =========================================================================
// 5. COLUMN PREFERENCES, REORDERING & SORTING (16 MANAGED HEADERS)
// =========================================================================
let activeColumnTarget = 'activities';
let currentSortCol = null;
let currentSortDir = 'asc';

const STORAGE_COL_ORDER = 'modern_box1_col_order_v5';
const STORAGE_COL_PREFS = 'modern_box1_col_prefs_v5';

const DEFAULT_COLUMN_ORDER = [
    'col-sno',
    'col-lead-id',
    'col-assigned',
    'col-contact-person',
    'col-name',
    'col-group',
    'col-status',
    'col-priority',
    'col-source',
    'col-enq-for',
    'col-tags',
    'col-address',
    'col-remarks',
    'col-scheduled-fup',
    'col-created-at',
    'col-activity'
];

function getSavedColumnOrder() {
    const saved = localStorage.getItem(STORAGE_COL_ORDER);
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed) && parsed.length > 0) {
                DEFAULT_COLUMN_ORDER.forEach(c => {
                    if (!parsed.includes(c)) parsed.push(c);
                });
                return parsed;
            }
        } catch (e) {}
    }
    return [...DEFAULT_COLUMN_ORDER];
}

function applyColumnOrder(order) {
    if (!order || !order.length) return;

    // 1. Reorder TH headers in THEAD (Strictly scoped to #card_activities)
    const theadTr = document.querySelector('#card_activities .modern-leads-table thead tr');
    if (theadTr) {
        const thCheckbox = theadTr.querySelector('th:first-child');
        const thActions = theadTr.querySelector('th:last-child');
        const thMap = {};
        Array.from(theadTr.querySelectorAll('th.draggable-th')).forEach(th => {
            const col = th.dataset.col;
            if (col) thMap[col] = th;
        });

        theadTr.innerHTML = '';
        if (thCheckbox) theadTr.appendChild(thCheckbox);
        order.forEach(col => {
            if (thMap[col]) {
                theadTr.appendChild(thMap[col]);
                delete thMap[col];
            }
        });
        Object.keys(thMap).forEach(col => {
            theadTr.appendChild(thMap[col]);
        });
        if (thActions) theadTr.appendChild(thActions);
    }

    // 2. Reorder TD data cells in every TBODY row (Strictly scoped to #card_activities)
    const tbodyRows = document.querySelectorAll('#card_activities #activitiesList tr.activity-record-row');
    tbodyRows.forEach(tr => {
        const tdCheckbox = tr.querySelector('td:first-child');
        const tdActions = tr.querySelector('td:last-child');
        const tdMap = {};
        Array.from(tr.querySelectorAll('td')).forEach(td => {
            for (let i = 0; i < td.classList.length; i++) {
                const cls = td.classList[i];
                if (cls.startsWith('col-')) {
                    tdMap[cls] = td;
                    break;
                }
            }
        });

        tr.innerHTML = '';
        if (tdCheckbox) tr.appendChild(tdCheckbox);
        order.forEach(col => {
            if (tdMap[col]) {
                tr.appendChild(tdMap[col]);
                delete tdMap[col];
            }
        });
        Object.keys(tdMap).forEach(col => {
            tr.appendChild(tdMap[col]);
        });
        if (tdActions) tr.appendChild(tdActions);
    });

    // 3. Reorder Cards inside Manage Columns Modal
    const cardList = document.getElementById('columnCheckboxesList');
    if (cardList) {
        const cardMap = {};
        Array.from(cardList.children).forEach(card => {
            const col = card.dataset.col;
            if (col) cardMap[col] = card;
        });
        cardList.innerHTML = '';
        order.forEach(col => {
            if (cardMap[col]) {
                cardList.appendChild(cardMap[col]);
                delete cardMap[col];
            }
        });
        Object.keys(cardMap).forEach(col => {
            cardList.appendChild(cardMap[col]);
        });
    }

    initTableDragAndDrop();
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

// Move Column Item Up or Down in Modal
function moveColumnItem(colClass, direction, event) {
    if (event) event.stopPropagation();
    const currentOrder = getSavedColumnOrder();
    const idx = currentOrder.indexOf(colClass);
    if (idx === -1) return;

    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= currentOrder.length) return;

    const temp = currentOrder[idx];
    currentOrder[idx] = currentOrder[newIdx];
    currentOrder[newIdx] = temp;

    localStorage.setItem(STORAGE_COL_ORDER, JSON.stringify(currentOrder));
    applyColumnOrder(currentOrder);
    applyModernColumnStyles();
}

// Drag & Drop for Modal Column Cards
let draggedCardCol = null;

function handleColDragStart(e) {
    draggedCardCol = e.currentTarget.dataset.col;
    e.dataTransfer.effectAllowed = 'move';
    e.currentTarget.style.opacity = '0.5';
}

function handleColDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('card-drag-over');
}

function handleColDragEnd(e) {
    e.currentTarget.style.opacity = '1';
    document.querySelectorAll('.col-item-card').forEach(c => c.classList.remove('card-drag-over'));
}

function handleColDrop(e) {
    e.preventDefault();
    document.querySelectorAll('.col-item-card').forEach(c => c.classList.remove('card-drag-over'));
    const targetCard = e.currentTarget.closest('.col-item-card');
    if (!targetCard || !draggedCardCol) return;

    const targetCol = targetCard.dataset.col;
    if (targetCol === draggedCardCol) return;

    const currentOrder = getSavedColumnOrder();
    const fromIdx = currentOrder.indexOf(draggedCardCol);
    const toIdx = currentOrder.indexOf(targetCol);

    if (fromIdx !== -1 && toIdx !== -1) {
        currentOrder.splice(fromIdx, 1);
        currentOrder.splice(toIdx, 0, draggedCardCol);
        localStorage.setItem(STORAGE_COL_ORDER, JSON.stringify(currentOrder));
        applyColumnOrder(currentOrder);
        applyModernColumnStyles();
    }
    draggedCardCol = null;
}

// Direct Table Header Drag & Drop
let draggedThCol = null;

function initTableDragAndDrop() {
    const ths = document.querySelectorAll('#card_activities .modern-leads-table thead th.draggable-th');
    ths.forEach(th => {
        th.setAttribute('draggable', 'true');
        th.ondragstart = function(e) {
            draggedThCol = this.dataset.col;
            e.dataTransfer.effectAllowed = 'move';
            this.style.opacity = '0.5';
        };

        th.ondragend = function() {
            this.style.opacity = '1';
            document.querySelectorAll('#card_activities .modern-leads-table thead th').forEach(el => el.classList.remove('drag-over'));
        };

        th.ondragover = function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        };

        th.ondragleave = function() {
            this.classList.remove('drag-over');
        };

        th.ondrop = function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            const targetCol = this.dataset.col;
            if (!draggedThCol || !targetCol || draggedThCol === targetCol) return;

            const currentOrder = getSavedColumnOrder();
            const fromIdx = currentOrder.indexOf(draggedThCol);
            const toIdx = currentOrder.indexOf(targetCol);

            if (fromIdx !== -1 && toIdx !== -1) {
                currentOrder.splice(fromIdx, 1);
                currentOrder.splice(toIdx, 0, draggedThCol);
                localStorage.setItem(STORAGE_COL_ORDER, JSON.stringify(currentOrder));
                applyColumnOrder(currentOrder);
                applyModernColumnStyles();
            }
            draggedThCol = null;
        };
    });
}

// Table Sorting per Column Header
function sortActivitiesTable(colClass) {
    const list = document.getElementById('activitiesList');
    if (!list) return;

    if (currentSortCol === colClass) {
        currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
    } else {
        currentSortCol = colClass;
        currentSortDir = 'asc';
    }

    const rows = Array.from(list.querySelectorAll('tr.activity-record-row'));
    if (rows.length <= 1) return;

    const attrName = 'val' + colClass.split('-').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join('');

    rows.sort((a, b) => {
        let valA = a.dataset[attrName];
        let valB = b.dataset[attrName];

        if (valA === undefined) valA = a.querySelector('.' + colClass)?.innerText.trim() || '';
        if (valB === undefined) valB = b.querySelector('.' + colClass)?.innerText.trim() || '';

        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const isNum = !isNaN(numA) && !isNaN(numB) && String(numA) === String(valA) && String(numB) === String(valB);

        let res = 0;
        if (isNum) {
            res = numA - numB;
        } else {
            res = String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' });
        }

        return (currentSortDir === 'asc') ? res : -res;
    });

    rows.forEach(r => list.appendChild(r));

    // Update header sort indicator
    document.querySelectorAll('#card_activities .modern-leads-table thead th').forEach(th => {
        const wrapper = th.querySelector('.th-sort-wrapper');
        const icon = th.querySelector('.sort-icon');
        if (!wrapper || !icon) return;

        if (th.classList.contains(colClass)) {
            wrapper.classList.add('active-sort');
            icon.setAttribute('data-lucide', currentSortDir === 'asc' ? 'arrow-up' : 'arrow-down');
        } else {
            wrapper.classList.remove('active-sort');
            icon.setAttribute('data-lucide', 'arrow-up-down');
        }
    });

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function openColumnDrawer(targetType) {
    activeColumnTarget = targetType || 'activities';
    const drawer = document.getElementById('modernColumnDrawer');
    if (drawer) {
        drawer.style.display = 'flex';
        loadColumnPreferences();
    }
    document.querySelectorAll('.modern-dropdown-menu').forEach(m => m.classList.remove('show'));
}

function closeColumnDrawer() {
    const drawer = document.getElementById('modernColumnDrawer');
    if (drawer) drawer.style.display = 'none';
}

function toggleColumnCheckbox(checkboxId) {
    const cb = document.getElementById(checkboxId);
    if (cb) {
        cb.checked = !cb.checked;
        applyModernColumnStyles();
    }
}

function setAllColumnCheckboxes(selectState) {
    const checkboxes = document.querySelectorAll('#columnCheckboxesList .modern-col-toggle-cb');
    checkboxes.forEach(cb => {
        cb.checked = selectState;
    });
    applyModernColumnStyles();
}

function filterColumnCheckboxes(query) {
    const q = (query || '').toLowerCase().trim();
    document.querySelectorAll('#columnCheckboxesList .col-item-card').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? 'flex' : 'none';
    });
}

function applyModernColumnStyles() {
    const checkboxes = document.querySelectorAll('#columnCheckboxesList .modern-col-toggle-cb');
    let hideRules = [];
    checkboxes.forEach(cb => {
        const targetClass = cb.getAttribute('data-target');
        if (!cb.checked && targetClass) {
            // STRICTLY SCOPED to #card_activities so Box 2, 3, 4 are NEVER affected
            hideRules.push('#card_activities th.' + targetClass + ', #card_activities td.' + targetClass + ' { display: none !important; }');
        }
    });

    let styleEl = document.getElementById('modern-columns-dynamic-style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'modern-columns-dynamic-style';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = hideRules.join('\n');
}

function saveColumnPreferences() {
    // 1. Save Visibility Preferences
    const checkboxes = document.querySelectorAll('#columnCheckboxesList .modern-col-toggle-cb');
    const prefs = {};
    checkboxes.forEach(cb => {
        const targetClass = cb.getAttribute('data-target');
        if (targetClass) {
            prefs[targetClass] = cb.checked;
        }
    });
    localStorage.setItem(STORAGE_COL_PREFS, JSON.stringify(prefs));

    // 2. Save Column Order
    const cards = document.querySelectorAll('#columnCheckboxesList .col-item-card');
    const newOrder = [];
    cards.forEach(c => {
        const col = c.dataset.col;
        if (col) newOrder.push(col);
    });
    if (newOrder.length > 0) {
        localStorage.setItem(STORAGE_COL_ORDER, JSON.stringify(newOrder));
        applyColumnOrder(newOrder);
    }

    applyModernColumnStyles();
    closeColumnDrawer();
}

function loadColumnPreferences() {
    // Purge legacy global column settings
    try {
        localStorage.removeItem('modern_table_column_prefs');
        localStorage.removeItem('modern_table_column_order');
        localStorage.removeItem('modern_box1_leads_columns_v2');
        localStorage.removeItem('modern_box1_leads_order_v2');
    } catch(e) {}

    // 1. Load and Apply Column Order first
    const savedOrder = getSavedColumnOrder();
    applyColumnOrder(savedOrder);

    // 2. Load and Apply Visibility Preferences
    const saved = localStorage.getItem(STORAGE_COL_PREFS);
    let prefs = null;
    if (saved) {
        try {
            prefs = JSON.parse(saved);
        } catch (e) {
            prefs = null;
        }
    }

    const defaultVisible = {
        'col-sno': true,
        'col-lead-id': true,
        'col-assigned': true,
        'col-contact-person': true,
        'col-name': true,
        'col-group': true,
        'col-status': true,
        'col-priority': true,
        'col-source': true,
        'col-enq-for': true,
        'col-tags': false,
        'col-address': false,
        'col-remarks': true,
        'col-scheduled-fup': true,
        'col-created-at': true,
        'col-activity': true
    };

    const checkboxes = document.querySelectorAll('#columnCheckboxesList .modern-col-toggle-cb');
    checkboxes.forEach(cb => {
        const targetClass = cb.getAttribute('data-target');
        if (targetClass) {
            if (prefs && prefs.hasOwnProperty(targetClass)) {
                cb.checked = !!prefs[targetClass];
            } else if (defaultVisible.hasOwnProperty(targetClass)) {
                cb.checked = defaultVisible[targetClass];
            }
        }
    });
    applyModernColumnStyles();
}

// =========================================================================
// TASKS (BOX 2) COLUMN MANAGEMENT & SORTING
// =========================================================================
function openTasksColumnModal() {
    const modal = document.getElementById('modernTasksColumnModal');
    if (modal) {
        modal.style.display = 'flex';
        loadTasksColumnPreferences();
    }
}

function closeTasksColumnModal() {
    const modal = document.getElementById('modernTasksColumnModal');
    if (modal) modal.style.display = 'none';
}

function setAllTasksCheckboxes(state) {
    document.querySelectorAll('#tasksCheckboxesList .tasks-col-cb').forEach(cb => {
        cb.checked = state;
    });
    applyTasksColumnStyles();
}

function applyTasksColumnStyles() {
    const cbs = document.querySelectorAll('#tasksCheckboxesList .tasks-col-cb');
    let rules = [];
    cbs.forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (!cb.checked && target) {
            rules.push('#card_tasks th.' + target + ', #card_tasks td.' + target + ' { display: none !important; }');
        }
    });

    let styleEl = document.getElementById('tasks-columns-dynamic-style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'tasks-columns-dynamic-style';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = rules.join('\n');
}

function saveTasksColumnPreferences() {
    const cbs = document.querySelectorAll('#tasksCheckboxesList .tasks-col-cb');
    const prefs = {};
    cbs.forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (target) prefs[target] = cb.checked;
    });
    localStorage.setItem('modern_box2_tasks_prefs_v1', JSON.stringify(prefs));
    applyTasksColumnStyles();
    closeTasksColumnModal();
}

function loadTasksColumnPreferences() {
    const saved = localStorage.getItem('modern_box2_tasks_prefs_v1');
    let prefs = null;
    if (saved) {
        try { prefs = JSON.parse(saved); } catch(e) {}
    }
    document.querySelectorAll('#tasksCheckboxesList .tasks-col-cb').forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (target && prefs && prefs.hasOwnProperty(target)) {
            cb.checked = !!prefs[target];
        }
    });
    applyTasksColumnStyles();
}

let currentTaskSortCol = null;
let currentTaskSortDir = 'asc';

function sortTasksTable(colClass) {
    const list = document.getElementById('tasksList');
    if (!list) return;

    if (currentTaskSortCol === colClass) {
        currentTaskSortDir = (currentTaskSortDir === 'asc') ? 'desc' : 'asc';
    } else {
        currentTaskSortCol = colClass;
        currentTaskSortDir = 'asc';
    }

    const rows = Array.from(list.querySelectorAll('tr.task-record-row'));
    if (rows.length <= 1) return;

    const attrName = 'val' + colClass.split('-').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join('');

    rows.sort((a, b) => {
        let valA = a.dataset[attrName] || '';
        let valB = b.dataset[attrName] || '';
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const isNum = !isNaN(numA) && !isNaN(numB) && String(numA) === String(valA) && String(numB) === String(valB);

        let res = isNum ? (numA - numB) : String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' });
        return (currentTaskSortDir === 'asc') ? res : -res;
    });

    rows.forEach(r => list.appendChild(r));
    renderTasksPagination();

    document.querySelectorAll('#tasksTable thead th').forEach(th => {
        const wrapper = th.querySelector('.th-sort-wrapper');
        const icon = th.querySelector('.sort-icon');
        if (!wrapper || !icon) return;
        if (th.classList.contains(colClass)) {
            wrapper.classList.add('active-sort');
            icon.setAttribute('data-lucide', currentTaskSortDir === 'asc' ? 'arrow-up' : 'arrow-down');
        } else {
            wrapper.classList.remove('active-sort');
            icon.setAttribute('data-lucide', 'arrow-up-down');
        }
    });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function sortTasksList() {
    sortTasksTable('col-tsk-due');
}

// =========================================================================
// MEETINGS (BOX 3) COLUMN MANAGEMENT & SORTING
// =========================================================================
function openMeetingsColumnModal() {
    const modal = document.getElementById('modernMeetingsColumnModal');
    if (modal) {
        modal.style.display = 'flex';
        loadMeetingsColumnPreferences();
    }
}

function closeMeetingsColumnModal() {
    const modal = document.getElementById('modernMeetingsColumnModal');
    if (modal) modal.style.display = 'none';
}

function setAllMeetingsCheckboxes(state) {
    document.querySelectorAll('#meetingsCheckboxesList .meetings-col-cb').forEach(cb => {
        cb.checked = state;
    });
    applyMeetingsColumnStyles();
}

function applyMeetingsColumnStyles() {
    const cbs = document.querySelectorAll('#meetingsCheckboxesList .meetings-col-cb');
    let rules = [];
    cbs.forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (!cb.checked && target) {
            rules.push('#card_meetings th.' + target + ', #card_meetings td.' + target + ' { display: none !important; }');
        }
    });

    let styleEl = document.getElementById('meetings-columns-dynamic-style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'meetings-columns-dynamic-style';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = rules.join('\n');
}

function saveMeetingsColumnPreferences() {
    const cbs = document.querySelectorAll('#meetingsCheckboxesList .meetings-col-cb');
    const prefs = {};
    cbs.forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (target) prefs[target] = cb.checked;
    });
    localStorage.setItem('modern_box3_meetings_prefs_v1', JSON.stringify(prefs));
    applyMeetingsColumnStyles();
    closeMeetingsColumnModal();
}

function loadMeetingsColumnPreferences() {
    const saved = localStorage.getItem('modern_box3_meetings_prefs_v1');
    let prefs = null;
    if (saved) {
        try { prefs = JSON.parse(saved); } catch(e) {}
    }
    document.querySelectorAll('#meetingsCheckboxesList .meetings-col-cb').forEach(cb => {
        const target = cb.getAttribute('data-target');
        if (target && prefs && prefs.hasOwnProperty(target)) {
            cb.checked = !!prefs[target];
        }
    });
    applyMeetingsColumnStyles();
}

let currentMtgSortCol = null;
let currentMtgSortDir = 'asc';

function sortMeetingsTable(colClass) {
    const list = document.getElementById('meetingsList');
    if (!list) return;

    if (currentMtgSortCol === colClass) {
        currentMtgSortDir = (currentMtgSortDir === 'asc') ? 'desc' : 'asc';
    } else {
        currentMtgSortCol = colClass;
        currentMtgSortDir = 'asc';
    }

    const rows = Array.from(list.querySelectorAll('tr.meeting-record-row'));
    if (rows.length <= 1) return;

    const attrName = 'val' + colClass.split('-').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join('');

    rows.sort((a, b) => {
        let valA = a.dataset[attrName] || '';
        let valB = b.dataset[attrName] || '';
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const isNum = !isNaN(numA) && !isNaN(numB) && String(numA) === String(valA) && String(numB) === String(valB);

        let res = isNum ? (numA - numB) : String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' });
        return (currentMtgSortDir === 'asc') ? res : -res;
    });

    rows.forEach(r => list.appendChild(r));
    renderMeetingsPagination();

    document.querySelectorAll('#meetingsTable thead th').forEach(th => {
        const wrapper = th.querySelector('.th-sort-wrapper');
        const icon = th.querySelector('.sort-icon');
        if (!wrapper || !icon) return;
        if (th.classList.contains(colClass)) {
            wrapper.classList.add('active-sort');
            icon.setAttribute('data-lucide', currentMtgSortDir === 'asc' ? 'arrow-up' : 'arrow-down');
        } else {
            wrapper.classList.remove('active-sort');
            icon.setAttribute('data-lucide', 'arrow-up-down');
        }
    });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function sortMeetingsList() {
    sortMeetingsTable('col-mtg-from');
}

// =========================================================================
// PIPELINE (BOX 4) SORTING
// =========================================================================
let pipelineSortState = 0; // 0 = Amount Desc, 1 = Amount Asc, 2 = Count Desc
function sortPipelineStages() {
    const list = document.getElementById('pipelineStagesList');
    if (!list) return;
    const rows = Array.from(list.querySelectorAll('.modern-stage-row'));
    if (rows.length <= 1) return;

    pipelineSortState = (pipelineSortState + 1) % 3;
    const label = document.getElementById('sortPipelineLabel');

    rows.sort((a, b) => {
        const amtA = parseFloat(a.dataset.valAmount || 0);
        const amtB = parseFloat(b.dataset.valAmount || 0);
        const cntA = parseInt(a.dataset.valCount || 0, 10);
        const cntB = parseInt(b.dataset.valCount || 0, 10);

        if (pipelineSortState === 1) {
            return amtA - amtB;
        } else if (pipelineSortState === 2) {
            return cntB - cntA;
        } else {
            return amtB - amtA;
        }
    });

    if (label) {
        if (pipelineSortState === 1) label.textContent = 'Amount ↑';
        else if (pipelineSortState === 2) label.textContent = 'Count ↓';
        else label.textContent = 'Amount ↓';
    }

    rows.forEach(r => list.appendChild(r));
}

// =========================================================================
// 6. VIEW SWITCHER & QUICK CREATE
// =========================================================================
function handleViewSwitch(viewVal) {
    if (viewVal === 'classic') {
        window.location.href = 'index.php?page=leads';
    } else {
        window.location.href = 'index.php?page=leads&view=modern';
    }
}

function openModernQuickAddModal() {
    const modal = document.getElementById('modernQuickAddModal');
    if (modal) modal.style.display = 'flex';
}

function closeModernQuickAddModal() {
    const modal = document.getElementById('modernQuickAddModal');
    if (modal) modal.style.display = 'none';
}

async function handleModernQuickAdd(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveQuickRecord');
    const notice = document.getElementById('quickAddNotice');
    const type = document.getElementById('quickRecordType').value;
    const subj = document.getElementById('quickSubject').value.trim();
    const comp = document.getElementById('quickCompany').value.trim();
    const phone = document.getElementById('quickPhone').value.trim();
    const prio = document.getElementById('quickPriority').value;

    if (!subj) return;

    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
        const payload = {
            name: subj,
            company: comp || subj,
            phone: phone,
            priority: prio.toLowerCase(),
            status: 'New'
        };

        const res = await fetch('api/leads.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.success || data.id) {
            notice.style.display = 'block';
            notice.style.background = '#d1fae5';
            notice.style.color = '#065f46';
            notice.textContent = (type === 'lead' ? 'Lead Opportunity created successfully!' : 'Task record saved successfully!');
            setTimeout(() => {
                closeModernQuickAddModal();
                window.location.reload();
            }, 800);
        } else {
            throw new Error(data.message || 'Failed to save record.');
        }
    } catch (err) {
        notice.style.display = 'block';
        notice.style.background = '#fee2e2';
        notice.style.color = '#991b1b';
        notice.textContent = 'Error: ' + err.message;
        btn.disabled = false;
        btn.textContent = 'Save Record';
    }
}

// Live Clock IST
function updateLiveClock() {
    const el = document.getElementById('liveClockIst');
    if (!el) return;
    const now = new Date();
    const options = { weekday: 'short', month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' };
    el.textContent = now.toLocaleString('en-IN', options) + ' IST';
}
setInterval(updateLiveClock, 30000);

// Initialize on Load
document.addEventListener('DOMContentLoaded', function () {
    initWidgetWidths();
    loadColumnPreferences();
    loadTasksColumnPreferences();
    loadMeetingsColumnPreferences();

    // 1. Restore Box 1 Page Size
    const savedActPageSize = localStorage.getItem('modern_act_pagesize');
    if (savedActPageSize) {
        activitiesPageSize = savedActPageSize;
        const actSizeEl = document.getElementById('activitiesPageSize');
        if (actSizeEl) actSizeEl.value = savedActPageSize;
    }

    // 2. Restore Box 1 Filter
    const savedActFilter = localStorage.getItem('modern_act_filter');
    const actMenuItems = document.querySelectorAll('#menu_activities .modern-dropdown-menu .modern-dropdown-item, #menu_activities .modern-dropdown-item');
    let matchedActItem = null;
    if (savedActFilter) {
        actMenuItems.forEach(item => {
            if (item.textContent.trim().startsWith(savedActFilter)) {
                matchedActItem = item;
            }
        });
    }

    if (matchedActItem && savedActFilter) {
        selectActivityFilter(savedActFilter, matchedActItem);
    } else {
        const todayRows = document.querySelectorAll('#card_activities #activitiesList tr[data-is-today="1"]');
        if (todayRows.length > 0 && actMenuItems[0]) {
            selectActivityFilter('My open activities for today', actMenuItems[0]);
        } else if (actMenuItems[3]) {
            selectActivityFilter('All Open Activities', actMenuItems[3]);
        } else {
            renderActivitiesPagination();
        }
    }

    // 3. Restore Box 2 (Tasks) Filter & Page Size
    const savedTskPageSize = localStorage.getItem('modern_task_pagesize');
    if (savedTskPageSize) {
        tasksPageSize = savedTskPageSize;
        const tskSizeEl = document.getElementById('tasksPageSize');
        if (tskSizeEl) tskSizeEl.value = savedTskPageSize;
    }
    const savedTskFilter = localStorage.getItem('modern_task_filter');
    if (savedTskFilter) {
        const tskItems = document.querySelectorAll('#menu_tasks .modern-dropdown-item');
        let tskMatched = null;
        tskItems.forEach(item => {
            if (item.textContent.trim().startsWith(savedTskFilter)) tskMatched = item;
        });
        if (tskMatched) {
            selectTaskFilter(savedTskFilter, tskMatched);
        } else {
            renderTasksPagination();
        }
    } else {
        renderTasksPagination();
    }

    // 4. Restore Box 3 (Meetings) Filter & Page Size
    const savedMtgPageSize = localStorage.getItem('modern_meeting_pagesize');
    if (savedMtgPageSize) {
        meetingsPageSize = savedMtgPageSize;
        const mtgSizeEl = document.getElementById('meetingsPageSize');
        if (mtgSizeEl) mtgSizeEl.value = savedMtgPageSize;
    }
    const savedMtgFilter = localStorage.getItem('modern_meeting_filter');
    if (savedMtgFilter) {
        const mtgItems = document.querySelectorAll('#menu_meetings .modern-dropdown-item');
        let mtgMatched = null;
        mtgItems.forEach(item => {
            if (item.textContent.trim().startsWith(savedMtgFilter)) mtgMatched = item;
        });
        if (mtgMatched) {
            selectMeetingFilter(savedMtgFilter, mtgMatched);
        } else {
            renderMeetingsPagination();
        }
    } else {
        renderMeetingsPagination();
    }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>
