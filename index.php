<?php
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

// Check authentication and routing for public landing vs authenticated dashboard
$requested_page = $_GET['page'] ?? '';
$is_authenticated = isset($_SESSION['user_id']);

// Public Standalone Pages routing
if ($requested_page === 'privacy' || (!$is_authenticated && in_array($requested_page, ['privacy_policy']))) {
    require_once __DIR__ . '/privacy.php';
    exit;
}
if ($requested_page === 'terms' || (!$is_authenticated && in_array($requested_page, ['terms_of_service', 'terms_conditions']))) {
    require_once __DIR__ . '/terms.php';
    exit;
}
if ($requested_page === 'refund' || (!$is_authenticated && in_array($requested_page, ['refund_policy', 'cancellation']))) {
    require_once __DIR__ . '/refund.php';
    exit;
}
if ($requested_page === 'contact') {
    require_once __DIR__ . '/contact.php';
    exit;
}
if (in_array($requested_page, ['pricing', 'plans'])) {
    require_once __DIR__ . '/pricing.php';
    exit;
}
if (in_array($requested_page, ['features', 'solutions'])) {
    require_once __DIR__ . '/features.php';
    exit;
}
if (in_array($requested_page, ['whatsapp', 'whatsapp_api', 'waba'])) {
    require_once __DIR__ . '/whatsapp.php';
    exit;
}
if (in_array($requested_page, ['kyc', 'customer_kyc_form'])) {
    require_once __DIR__ . '/customer_kyc_form.php';
    exit;
}

// Unauthenticated users see the Public Landing / Home page first
if (!isset($_SESSION['user_id'])) {
    if ($requested_page === 'login') {
        header("Location: auth/login.php");
        exit;
    }
    if ($requested_page === 'register') {
        header("Location: auth/register.php");
        exit;
    }
    // Render stunning Public Landing Page
    require_once __DIR__ . '/landing.php';
    exit;
}

// Logged-in users visiting home / landing page explicitly
if (in_array($requested_page, ['home', 'landing', 'public'])) {
    require_once __DIR__ . '/landing.php';
    exit;
}

// Super Admin Impersonation Action Receiver
if (isset($_GET['action']) && $_GET['action'] === 'impersonate_client') {
    $target_db = trim($_GET['db'] ?? '');
    $target_company = trim($_GET['company'] ?? '');
    $user_role = $_SESSION['user_role'] ?? '';
    if (!empty($target_db) && ($user_role === 'Super Admin' || $user_role === 'Admin')) {
        $_SESSION['impersonate_tenant_db'] = $target_db;
        $_SESSION['impersonate_company_name'] = $target_company;
    }
    header("Location: index.php?page=dashboard");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'stop_impersonation') {
    unset($_SESSION['impersonate_tenant_db']);
    unset($_SESSION['impersonate_company_name']);
    header("Location: index.php?page=crm_clients");
    exit;
}

// Export Leads CSV File Receiver (Legacy - all leads)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if ($db_connected && $pdo) {
        try {
            $stmt = $pdo->query("SELECT id, name, contact_person, company, phone, email, address, source, priority, status, assigned_to, budget, enq_for, remarks, tags, created_at FROM leads ORDER BY created_at DESC");
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Leads_Directory_Export_' . date('Y-m-d_H-i') . '.csv');
            
            $output = fopen('php://output', 'w');
            
            // Output BOM for Excel UTF-8 compatibility
            fputs($output, "\xEF\xBB\xBF");
            
            // CSV Header Row
            fputcsv($output, ['Lead ID', 'Customer Name', 'Contact Person', 'Company / Group', 'Phone', 'Email', 'Address', 'Source', 'Priority', 'Status', 'Assigned Exec', 'Budget', 'Enq For', 'Remarks', 'Tags', 'Created Date']);
            
            foreach ($leads as $l) {
                fputcsv($output, [
                    $l['id'],
                    $l['name'],
                    $l['contact_person'] ?? '',
                    $l['company'],
                    $l['phone'],
                    $l['email'] ?? '',
                    $l['address'] ?? '',
                    $l['source'] ?? '',
                    $l['priority'] ?? '',
                    $l['status'] ?? '',
                    $l['assigned_to'] ?? '',
                    $l['budget'] ?? 0,
                    $l['enq_for'] ?? '',
                    $l['remarks'] ?? '',
                    $l['tags'] ?? '',
                    $l['created_at']
                ]);
            }
            
            fclose($output);
            exit;
        } catch (PDOException $e) {
            die("Export error: " . $e->getMessage());
        }
    } else {
        die("Database offline. Cannot export.");
    }
}

// ===== ADVANCED EXPORT CSV HANDLER =====
if (isset($_GET['action']) && $_GET['action'] === 'export_csv_advanced') {
    if (!$db_connected || !$pdo) die("Database offline. Cannot export.");

    $exp_scope   = $_GET['exp_scope'] ?? 'all';
    $exp_cols    = !empty($_GET['exp_cols']) ? explode(',', $_GET['exp_cols']) : ['id','name','phone','company','group_stage','assigned_to','created_at'];
    $exp_fup     = $_GET['exp_fup'] ?? '0'; // '0','1','2','3','all'

    // Allowed column map: key => SQL column or alias
    $allowed_cols = [
        'id'             => ['label' => 'Lead ID',         'col' => 'leads.id'],
        'name'           => ['label' => 'Customer Name',   'col' => 'leads.name'],
        'contact_person' => ['label' => 'Contact Person',  'col' => 'leads.contact_person'],
        'company'        => ['label' => 'Company',         'col' => 'leads.company'],
        'phone'          => ['label' => 'Phone',           'col' => 'leads.phone'],
        'email'          => ['label' => 'Email',           'col' => 'leads.email'],
        'address'        => ['label' => 'Address',         'col' => 'leads.address'],
        'source'         => ['label' => 'Source',          'col' => 'leads.source'],
        'priority'       => ['label' => 'Priority',        'col' => 'leads.priority'],
        'status'         => ['label' => 'Status',          'col' => 'leads.status'],
        'group_stage'    => ['label' => 'Group / Stage',   'col' => 'leads.group_stage'],
        'assigned_to'    => ['label' => 'Assigned To',     'col' => 'leads.assigned_to'],
        'budget'         => ['label' => 'Budget',          'col' => 'leads.budget'],
        'enq_for'        => ['label' => 'Enq For',         'col' => 'leads.enq_for'],
        'tags'           => ['label' => 'Tags',            'col' => 'leads.tags'],
        'remarks'        => ['label' => 'Remarks',         'col' => 'leads.remarks'],
        'created_at'     => ['label' => 'Created Date',    'col' => 'leads.created_at'],
        'updated_at'     => ['label' => 'Updated Date',    'col' => 'leads.updated_at'],
    ];

    // Validate requested columns
    $selected_cols = array_filter($exp_cols, fn($c) => isset($allowed_cols[$c]));
    if (empty($selected_cols)) $selected_cols = ['id','name','phone','company','created_at'];

    $select_parts = array_map(fn($c) => $allowed_cols[$c]['col'] . ' AS ' . $c, $selected_cols);
    $sql_select = implode(', ', $select_parts);

    // Build WHERE conditions based on scope
    $where = [];
    $params = [];

    if ($exp_scope === 'current') {
        // Replay URL filter params (prefixed with exp_url_)
        foreach ($_GET as $k => $v) {
            if (strpos($k, 'exp_url_') !== 0 || empty($v)) continue;
            $orig = substr($k, 8); // strip 'exp_url_'
            if ($orig === 'search' || $orig === 'q') {
                $where[] = "(leads.name LIKE ? OR leads.company LIKE ? OR leads.phone LIKE ? OR leads.email LIKE ?)";
                $like = '%' . $v . '%';
                $params = array_merge($params, [$like, $like, $like, $like]);
            } elseif ($orig === 'group_stage') {
                $where[] = "leads.group_stage LIKE ?";
                $params[] = '%' . $v . '%';
            } elseif ($orig === 'filter_priority' || $orig === 'priority') {
                $where[] = "leads.priority = ?"; $params[] = $v;
            } elseif ($orig === 'filter_source' || $orig === 'source') {
                $where[] = "leads.source = ?"; $params[] = $v;
            } elseif ($orig === 'filter_status' || $orig === 'status') {
                $where[] = "leads.status = ?"; $params[] = $v;
            } elseif ($orig === 'filter_assigned' || $orig === 'assigned') {
                $where[] = "leads.assigned_to LIKE ?"; $params[] = '%' . $v . '%';
            }
        }
    } elseif ($exp_scope === 'custom') {
        if (!empty($_GET['exp_date_from'])) {
            $where[] = "DATE(leads.created_at) >= ?"; $params[] = $_GET['exp_date_from'];
        }
        if (!empty($_GET['exp_date_to'])) {
            $where[] = "DATE(leads.created_at) <= ?"; $params[] = $_GET['exp_date_to'];
        }
        if (!empty($_GET['exp_group'])) {
            $where[] = "leads.group_stage LIKE ?"; $params[] = '%' . $_GET['exp_group'] . '%';
        }
        if (!empty($_GET['exp_priority'])) {
            $where[] = "LOWER(leads.priority) = ?"; $params[] = strtolower($_GET['exp_priority']);
        }
        if (!empty($_GET['exp_source'])) {
            $where[] = "leads.source = ?"; $params[] = $_GET['exp_source'];
        }
        if (!empty($_GET['exp_assigned'])) {
            $where[] = "leads.assigned_to LIKE ?"; $params[] = '%' . $_GET['exp_assigned'] . '%';
        }
        if (!empty($_GET['exp_status'])) {
            $where[] = "leads.status = ?"; $params[] = $_GET['exp_status'];
        }
    }
    // scope='all' -> no extra WHERE

    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    $final_sql = "SELECT {$sql_select} FROM leads {$where_sql} ORDER BY leads.created_at DESC";

    try {
        $stmt = $pdo->prepare($final_sql);
        $stmt->execute($params);
        $leads_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Export query error: " . $e->getMessage());
    }

    // Fetch followup data if needed
    $fup_data = []; // lead_id => [ [date, remarks, action_type], ... ]
    $max_fup_cols = 0;
    if ($exp_fup !== '0' && $exp_fup !== 0) {
        $lead_ids = array_column($leads_data, 'id');
        if (!empty($lead_ids)) {
            $in_clause = implode(',', array_fill(0, count($lead_ids), '?'));
            try {
                $fstmt = $pdo->prepare("SELECT lead_id, action_type, remarks, DATE_FORMAT(scheduled_at, '%d-%m-%Y %H:%i') as fup_dt FROM followups WHERE lead_id IN ($in_clause) ORDER BY lead_id ASC, scheduled_at DESC");
                $fstmt->execute($lead_ids);
                $all_fups = $fstmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_fups as $f) {
                    $fup_data[$f['lead_id']][] = $f;
                }
            } catch (PDOException $e) {}
        }

        if ($exp_fup !== 'all') {
            $max_fup_cols = (int)$exp_fup;
        }
    }

    // Build header row
    $header_row = array_map(fn($c) => $allowed_cols[$c]['label'], $selected_cols);
    if ($exp_fup !== '0' && $exp_fup !== 0 && $exp_fup !== 'all') {
        for ($i = 1; $i <= $max_fup_cols; $i++) {
            $header_row[] = "Followup {$i} Date";
            $header_row[] = "Followup {$i} Type";
            $header_row[] = "Followup {$i} Remarks";
        }
    } elseif ($exp_fup === 'all') {
        $header_row[] = 'Followup Date';
        $header_row[] = 'Followup Type';
        $header_row[] = 'Followup Remarks';
    }

    // Send headers and write CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Leads_Export_' . strtoupper($exp_scope) . '_' . date('Y-m-d_H-i') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel

    fputcsv($output, $header_row);

    foreach ($leads_data as $lead) {
        $row = array_map(fn($c) => $lead[$c] ?? '', $selected_cols);
        $lead_fups = $fup_data[$lead['id']] ?? [];

        if ($exp_fup === '0' || $exp_fup === 0) {
            fputcsv($output, $row);
        } elseif ($exp_fup === 'all') {
            if (empty($lead_fups)) {
                $row[] = ''; $row[] = ''; $row[] = '';
                fputcsv($output, $row);
            } else {
                foreach ($lead_fups as $fup) {
                    $fup_row = array_merge($row, [$fup['fup_dt'] ?? '', $fup['action_type'] ?? '', $fup['remarks'] ?? '']);
                    fputcsv($output, $fup_row);
                }
            }
        } else {
            // Last N followups as extra columns on same row
            for ($i = 0; $i < $max_fup_cols; $i++) {
                $fup = $lead_fups[$i] ?? null;
                $row[] = $fup ? ($fup['fup_dt'] ?? '') : '';
                $row[] = $fup ? ($fup['action_type'] ?? '') : '';
                $row[] = $fup ? ($fup['remarks'] ?? '') : '';
            }
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}


// Batch Actions AJAX Receiver
if (isset($_POST['action']) && $_POST['action'] === 'batch_update') {
    header('Content-Type: application/json');
    $ids = isset($_POST['lead_ids']) ? json_decode($_POST['lead_ids'], true) : [];
    $batch_action = $_POST['batch_action'] ?? '';
    $val = $_POST['target_value'] ?? '';
    $user_role = $_SESSION['user_role'] ?? 'Admin';

    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['success' => false, 'message' => 'No valid leads selected for batch action.']);
        exit;
    }

    if ($batch_action === 'drop') {
        $allowed_roles = ['Super Admin', 'Admin', 'Regional Manager', 'Team Leader'];
        if (!in_array($user_role, $allowed_roles)) {
            echo json_encode(['success' => false, 'message' => 'Permission Denied: Only Administrators or Managers can authorize dropping leads.']);
            exit;
        }
    }

    if ($db_connected && $pdo) {
        try {
            $in_clause = implode(',', array_fill(0, count($ids), '?'));
            
            if ($batch_action === 'assign') {
                $assigned_by = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                $stmt = $pdo->prepare("UPDATE leads SET assigned_to = ?, assigned_by = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val, $assigned_by], $ids);
                $stmt->execute($params);
                $msg = "Successfully assigned " . count($ids) . " lead(s) to " . htmlspecialchars($val) . ".";
            } elseif ($batch_action === 'status') {
                $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val], $ids);
                $stmt->execute($params);
                $msg = "Successfully updated status of " . count($ids) . " lead(s).";
            } elseif ($batch_action === 'priority') {
                $stmt = $pdo->prepare("UPDATE leads SET priority = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val], $ids);
                $stmt->execute($params);
                $msg = "Successfully updated priority of " . count($ids) . " lead(s) to " . ucfirst($val) . ".";
            } elseif ($batch_action === 'drop') {
                $stmt = $pdo->prepare("UPDATE leads SET status = 'dropped' WHERE id IN ($in_clause)");
                $stmt->execute($ids);
                $msg = "Successfully moved " . count($ids) . " lead(s) to DROPPED status. No leads were deleted.";
            } elseif ($batch_action === 'restore' || $batch_action === 'reactivate') {
                $target_stage = !empty($val) ? $val : 'new';
                $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id IN ($in_clause)");
                $params = array_merge([$target_stage], $ids);
                $stmt->execute($params);
                $msg = "Successfully re-activated " . count($ids) . " lead(s) back to Active Lead status.";
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid batch action specified.']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => $msg]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database offline.']);
        exit;
    }
}

// Global Search AJAX receiver
if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($db_connected && $pdo && !empty($q)) {
        try {
            $stmt = $pdo->prepare("SELECT id, name, company, phone, source, priority FROM leads WHERE name LIKE ? OR company LIKE ? OR phone LIKE ? OR id LIKE ? ORDER BY created_at DESC LIMIT 10");
            $like = '%' . $q . '%';
            $stmt->execute([$like, $like, $like, $like]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }
}

// Phone Duplication Check AJAX receiver
if (isset($_GET['action']) && $_GET['action'] === 'check_phone') {
    header('Content-Type: application/json');
    $phone = trim($_GET['phone'] ?? '');
    $exclude_id = trim($_GET['exclude_id'] ?? '');

    if ($db_connected && $pdo && !empty($phone)) {
        try {
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            
            $sql = "SELECT id, name, company, phone, status, assigned_to, created_at FROM leads WHERE (REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ? OR phone = ?)";
            $params = ['%' . $clean_phone . '%', $phone];

            if (!empty($exclude_id)) {
                $sql .= " AND id != ?";
                $params[] = $exclude_id;
            }

            $sql .= " ORDER BY id DESC LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode([
                    'exists' => true,
                    'lead' => $existing
                ]);
            } else {
                echo json_encode(['exists' => false]);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['exists' => false]);
        exit;
    }
}

// Mark Followup Complete AJAX receiver
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'complete_followup') {
    header('Content-Type: application/json');
    $fup_id = (int)($_REQUEST['id'] ?? 0);
    if ($db_connected && $pdo && $fup_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE followups SET status = 'completed' WHERE id = ?");
            $stmt->execute([$fup_id]);
            
            // Add activity timeline record
            $getLead = $pdo->prepare("SELECT lead_id FROM followups WHERE id = ?");
            $getLead->execute([$fup_id]);
            $leadId = $getLead->fetchColumn();
            if ($leadId) {
                $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Follow-up marked as COMPLETED')");
                $log->execute([$leadId, $_SESSION['user_name'] ?? 'System User']);
            }

            echo json_encode(['success' => true, 'message' => 'Follow-up marked as completed!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters or DB offline.']);
        exit;
    }
}

// Quick Follow-up Data Fetch AJAX receiver
if (isset($_GET['action']) && $_GET['action'] === 'get_lead_json') {
    header('Content-Type: application/json');
    if ($db_connected && $pdo && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
            $stmt->execute([$_GET['id']]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($l) {
                // Fetch followup history for this lead
                $stmtFup = $pdo->prepare("SELECT * FROM followups WHERE lead_id = ? ORDER BY scheduled_at DESC LIMIT 15");
                $stmtFup->execute([$_GET['id']]);
                $fupHistory = $stmtFup->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'lead' => [
                        'id' => $l['id'],
                        'name' => $l['name'],
                        'company' => $l['company'],
                        'group_stage' => $l['group_stage'] ?? '',
                        'city' => $l['city'] ?? '',
                        'phone' => $l['phone'],
                        'email' => $l['email'] ?? '',
                        'source' => $l['source'] ?? 'Website',
                        'priority' => $l['priority'] ?? 'warm',
                        'status' => $l['status'] ?? 'new',
                        'assigned' => $l['assigned_to'] ?? '',
                        'assigned_by' => !empty($l['assigned_by']) ? $l['assigned_by'] : (!empty($_SESSION['user_name']) ? $_SESSION['user_name'] : ''),
                        'address' => $l['address'] ?? '',
                        'tags' => $l['tags'] ?? '',
                        'enq_for' => $l['enq_for'] ?? '',
                        'contact_person' => $l['contact_person'] ?? '',
                        'remarks' => $l['remarks'] ?? ''
                    ],
                    'followup_history' => $fupHistory
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lead not found.']);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
}

// Quick Follow-up Data Save AJAX receiver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_followup_save') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    if ($db_connected && $pdo) {
        try {
            $lead_id = $_POST['lead_id'] ?? '';
            $group_stage = trim($_POST['group_stage'] ?? ($_POST['company'] ?? ''));
            $status = $_POST['status'] ?? '';
            $assigned_to_raw = $_POST['assigned_to'] ?? '';
            $assigned_to = is_array($assigned_to_raw) ? implode(', ', array_filter(array_map('trim', $assigned_to_raw))) : trim($assigned_to_raw);
            if (strlen($assigned_to) > 250) {
                $assigned_to = substr($assigned_to, 0, 247) . '...';
            }
            $tags = $_POST['tags'] ?? '';
            $address = $_POST['address'] ?? '';
            $source = $_POST['source'] ?? '';
            $enq_for = $_POST['enq_for'] ?? '';
            $contact_person = $_POST['contact_person'] ?? '';
            $remarks = $_POST['remarks'] ?? '';
            
            // 1. Update leads table (Properly update group_stage)
            if (!empty($status)) {
                $upd = $pdo->prepare("UPDATE leads SET group_stage = ?, status = ?, assigned_to = ?, tags = ?, address = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $upd->execute([$group_stage, $status, $assigned_to, $tags, $address, $source, $enq_for, $contact_person, $remarks, $lead_id]);
            } else {
                $upd = $pdo->prepare("UPDATE leads SET group_stage = ?, assigned_to = ?, tags = ?, address = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $upd->execute([$group_stage, $assigned_to, $tags, $address, $source, $enq_for, $contact_person, $remarks, $lead_id]);
            }
            
            // 2. Insert or update follow-up reminder
            $action_type = $_POST['action_type'] ?? ($_POST['fup_type'] ?? 'Call');
            $fup_assigned_to = !empty($_POST['fup_assigned_to']) ? $_POST['fup_assigned_to'] : ($assigned_to ?: ($_SESSION['user_name'] ?? 'Unassigned'));
            $fup_notes = $_POST['fup_notes'] ?? ($_POST['fup_comment'] ?? '');
            
            $scheduled_at = $_POST['scheduled_at'] ?? '';
            if (empty($scheduled_at)) {
                $reminder_date = $_POST['reminder_date'] ?? '';
                $reminder_time = $_POST['reminder_time'] ?? '';
                if (!empty($reminder_date)) {
                    $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                } else {
                    $scheduled_at = date('Y-m-d H:i:s');
                }
            }
            $scheduled_at = str_replace('T', ' ', $scheduled_at);
            if (strlen($scheduled_at) === 16) {
                $scheduled_at .= ':00';
            }

            $send_email = isset($_POST['send_email']) ? 1 : 0;
            $send_sms = isset($_POST['send_sms']) ? 1 : 0;

            if (!empty($action_type) || !empty($scheduled_at) || !empty($fup_notes)) {
                // Check if a pending follow-up already exists for this lead
                $chkFup = $pdo->prepare("SELECT id, action_type, scheduled_at, remarks FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $chkFup->execute([$lead_id]);
                $existFup = $chkFup->fetch(PDO::FETCH_ASSOC);
                
                if ($existFup) {
                    $old_time = date('Y-m-d H:i', strtotime($existFup['scheduled_at']));
                    $new_time = date('Y-m-d H:i', strtotime($scheduled_at));
                    $old_action = $existFup['action_type'] ?? '';

                    if ($old_time !== $new_time || $action_type !== $old_action) {
                        // Mark previous follow-up as 'rescheduled' keeping its original action_type intact
                        $clean_remarks = preg_replace('/\s*\[Rescheduled to.*?\]/i', '', $existFup['remarks'] ?? '');
                        $archived_notes = trim($clean_remarks) . ($old_time !== $new_time ? " [Rescheduled to $new_time]" : "");

                        $updOld = $pdo->prepare("UPDATE followups SET status = 'rescheduled', remarks = ? WHERE id = ?");
                        $updOld->execute([$archived_notes, $existFup['id']]);

                        // Insert new follow-up record with the new action_type and new scheduled_at
                        $insFup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, status, assigned_to, send_email, send_sms) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
                        $insFup->execute([$lead_id, $action_type, $scheduled_at, $fup_notes ?: 'Follow-up reminder set', $fup_assigned_to, $send_email, $send_sms]);
                    } else {
                        // Update current follow-up details if date and action_type are unchanged
                        $updFup = $pdo->prepare("UPDATE followups SET action_type = ?, remarks = ?, assigned_to = ?, send_email = ?, send_sms = ? WHERE id = ?");
                        $updFup->execute([$action_type, $fup_notes ?: 'Follow-up reminder updated', $fup_assigned_to, $send_email, $send_sms, $existFup['id']]);
                    }
                } else {
                    $insFup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, status, assigned_to, send_email, send_sms) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
                    $insFup->execute([$lead_id, $action_type, $scheduled_at, $fup_notes ?: 'Follow-up reminder set', $fup_assigned_to, $send_email, $send_sms]);
                }
            }
            
            // 3. Log timeline event
            $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead details and follow-up quick filled from Leads Directory')");
            $log->execute([$lead_id, $_SESSION['user_name'] ?? 'System Operator']);
            
            echo json_encode(['success' => true, 'message' => 'Lead details and follow-up saved successfully!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database is not connected.']);
        exit;
    }
}

// Handle profile updates
if (isset($_GET['action']) && $_GET['action'] === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $_SESSION['flash_error'] = "Security Violation: Invalid or missing CSRF token. Request denied.";
        header('Location: index.php?page=settings');
        exit;
    }

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    
    $user_id = $_SESSION['user_id'] ?? 1;
    
    // Handle avatar upload securely
    $photo_path = $_SESSION['user_photo'] ?? null;
    if ($db_connected && $pdo) {
        try {
            $stmtPhoto = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmtPhoto->execute([$user_id]);
            $dbPhoto = $stmtPhoto->fetchColumn();
            if (!empty($dbPhoto)) {
                $photo_path = $dbPhoto;
            }
        } catch (PDOException $e) {}
    }
    
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = secureFileUpload($_FILES['profile_photo'], 'avatars', ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        if ($uploadResult['success']) {
            $photo_path = $uploadResult['file_path'];
        } else {
            $_SESSION['flash_error'] = $uploadResult['error'];
        }
    }
    
    if ($db_connected && $pdo) {
        try {
            // Check if email already used by another user
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, $user_id]);
            if ($check->fetch()) {
                $_SESSION['flash_error'] = "The email address is already in use by another user.";
            } else {
                if (!empty($new_password)) {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, profile_photo = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $hash, $photo_path, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, profile_photo = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $photo_path, $user_id]);
                }
                
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_photo'] = $photo_path;
                $_SESSION['flash_success'] = "Your profile details have been successfully updated.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to update profile: " . $e->getMessage();
        }
    } else {
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_photo'] = $photo_path;
        $_SESSION['flash_success'] = "Database offline. Profile updates simulated successfully.";
    }
    
    header("Location: index.php?page=settings");
    exit;
}

// Handle scheduling followups/reminders
if (isset($_GET['action']) && $_GET['action'] === 'schedule_followup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = isset($_POST['lead_id']) ? trim($_POST['lead_id']) : '';
    $scheduled_at = isset($_POST['scheduled_at']) ? trim($_POST['scheduled_at']) : '';
    $action_type = isset($_POST['action_type']) ? trim($_POST['action_type']) : 'Call';
    $assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    
    $send_email = isset($_POST['send_email']) ? 1 : 0;
    $send_sms = isset($_POST['send_sms']) ? 1 : 0;
    
    if ($db_connected && $pdo) {
        try {
            // Find lead details
            $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
            $stmtLead->execute([$lead_id]);
            $lead = $stmtLead->fetch();
            
            if ($lead) {
                // Insert into database with trigger flags
                $targets_list = [];
                $sms_targets = isset($_POST['sms_targets']) ? $_POST['sms_targets'] : [];
                if (in_array('client', $sms_targets) && !empty($_POST['sms_client_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_client_phone']), 'role' => 'Client'];
                }
                if (in_array('employee', $sms_targets) && !empty($_POST['sms_employee_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_employee_phone']), 'role' => 'Employee'];
                }
                if (in_array('admin', $sms_targets) && !empty($_POST['sms_admin_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_admin_phone']), 'role' => 'Admin'];
                }
                $sms_targets_json = json_encode($targets_list);

                // Check if a pending follow-up already exists for this lead
                $chkFup = $pdo->prepare("SELECT id FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $chkFup->execute([$lead_id]);
                $existFup = $chkFup->fetchColumn();

                if ($existFup) {
                    $stmt = $pdo->prepare("UPDATE followups SET action_type = ?, scheduled_at = ?, remarks = ?, assigned_to = ?, send_email = ?, send_sms = ?, sms_targets = ? WHERE id = ?");
                    $stmt->execute([$action_type, $scheduled_at, $remarks, $assigned_to, $send_email, $send_sms, $sms_targets_json, $existFup]);
                    $fup_id = $existFup;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, send_email, send_sms, email_sent, sms_sent, sms_targets, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'pending')");
                    $stmt->execute([$lead_id, $action_type, $scheduled_at, $remarks, $assigned_to, $send_email, $send_sms, $sms_targets_json]);
                    $fup_id = $pdo->lastInsertId();
                }
                
                // Write to activity timeline
                $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                $action_desc = "Scheduled a follow-up " . htmlspecialchars($action_type) . " for " . htmlspecialchars($scheduled_at);
                $stmtTime->execute([$lead_id, $_SESSION['user_name'] ?? 'System', $action_desc]);
                
                // Determine if we should fire immediately (if time is now or in the past)
                $is_immediate = (strtotime($scheduled_at) <= time());
                
                // Send Email Notification if checked and immediate
                if ($is_immediate && $send_email && !empty($lead['email'])) {
                    $subject = "Marg Soft Solution: Scheduled Follow-up Notification";
                    $title = "Follow-up Scheduled Reminder";
                    $header = "Scheduled " . htmlspecialchars($action_type);
                    $subtitle = "Reminder for " . htmlspecialchars($lead['name']) . " on " . htmlspecialchars($scheduled_at);
                    
                    $body = "<p>Dear " . htmlspecialchars($lead['name']) . ",</p>";
                    $body .= "<p>This is a reminder that we have scheduled a follow-up <strong>" . htmlspecialchars($action_type) . "</strong> with you.</p>";
                    $body .= "<p><strong>Date & Time:</strong> " . htmlspecialchars($scheduled_at) . "</p>";
                    if (!empty($remarks)) {
                        $body .= "<p><strong>Notes:</strong> " . htmlspecialchars($remarks) . "</p>";
                    }
                    $body .= "<p>If you need to reschedule or have any queries, please let us know.</p>";
                    
                    $compiledMail = Mailer::wrapHTMLTemplate($title, $header, $subtitle, $body, "Launch CRM Dashboard", "http://localhost/marglead/auth/login.php");
                    if (Mailer::send($lead['email'], $subject, $compiledMail)) {
                        $pdo->prepare("UPDATE followups SET email_sent = 1 WHERE id = ?")->execute([$fup_id]);
                    }
                }
                
                // Process Free SMS (Carrier email-to-sms or mock simulator) if immediate
                if ($is_immediate && $send_sms && !empty($targets_list)) {
                    $sms_msg = "MARG SOFT SOLUTION: Scheduled " . $action_type . " for " . $lead['name'] . " on " . $scheduled_at . ". Notes: " . $remarks;
                    
                    $_SESSION['sms_simulation_batch'] = [
                        'message' => $sms_msg,
                        'targets' => $targets_list
                    ];
                    
                    foreach ($targets_list as $tgt) {
                        $stmtTime->execute([$lead_id, 'SMS Gateway', "Free Carrier SMS reminder dispatched to " . $tgt['role'] . " (" . htmlspecialchars($tgt['phone']) . ")"]);
                    }
                    
                    $pdo->prepare("UPDATE followups SET sms_sent = 1 WHERE id = ?")->execute([$fup_id]);
                }
                
                $_SESSION['flash_success'] = "Follow-up scheduled successfully. Alerts configured.";
            } else {
                $_SESSION['flash_error'] = "Invalid customer lead ID selected.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to schedule follow-up: " . $e->getMessage();
        }
    }
    
    // Redirect back
    $ref = $_SERVER['HTTP_REFERER'] ?? 'index.php?page=dashboard';
    header("Location: " . $ref);
    exit;
}

// Resolve URL query parameters, default to dashboard
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Enforce role-based access security check
if (!hasAccess($page, $_SESSION['user_role'])) {
    // Find first accessible page for this user and redirect silently
    $role = $_SESSION['user_role'] ?? '';
    $candidate_pages = ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
    $redirect_to = null;
    foreach ($candidate_pages as $candidate) {
        // Skip the current page to avoid infinite redirect loop
        if ($candidate === $page) continue;
        if (hasAccess($candidate, $role)) {
            $redirect_to = $candidate;
            break;
        }
    }
    if ($redirect_to !== null) {
        header("Location: index.php?page=" . urlencode($redirect_to));
    } else {
        // No accessible page found - session may be corrupt, force re-login
        session_destroy();
        header("Location: auth/login.php");
    }
    exit;
}

$module_path = '';

switch ($page) {
    case 'access_denied':
        $module_path = __DIR__ . '/modules/access_denied.php';
        break;
    case 'dashboard':
        $module_path = __DIR__ . '/modules/dashboard.php';
        break;
    case 'leads':
        $module_path = __DIR__ . '/modules/leads/list.php';
        break;
    case 'lead_details':
        $module_path = __DIR__ . '/modules/leads/details.php';
        break;
    case 'lead_form':
        $module_path = __DIR__ . '/modules/leads/form.php';
        break;
    case 'lead_import':
        $module_path = __DIR__ . '/modules/leads/import.php';
        break;
    case 'pipeline':
        $module_path = __DIR__ . '/modules/pipeline.php';
        break;
    case 'followups':
        $module_path = __DIR__ . '/modules/followups.php';
        break;
    case 'demo':
        $module_path = __DIR__ . '/modules/demo.php';
        break;
    case 'quotation':
        $module_path = __DIR__ . '/modules/quotation/list.php';
        break;
    case 'quotation_create':
        $module_path = __DIR__ . '/modules/quotation/create.php';
        break;
    case 'quotation_view':
        $module_path = __DIR__ . '/modules/quotation/view.php';
        break;
    case 'payments':
        $module_path = __DIR__ . '/modules/payments.php';
        break;
    case 'bank_accounts':
        $module_path = __DIR__ . '/modules/bank_accounts.php';
        break;
    case 'installation':
        $module_path = __DIR__ . '/modules/installation.php';
        break;
    case 'training':
        $module_path = __DIR__ . '/modules/training.php';
        break;
    case 'support':
        $module_path = __DIR__ . '/modules/support.php';
        break;
    case 'renewals':
        $module_path = __DIR__ . '/modules/renewals.php';
        break;
    case 'clients':
        $module_path = __DIR__ . '/modules/clients.php';
        break;
    case 'crm_clients':
        $module_path = __DIR__ . '/modules/admin/crm_clients.php';
        break;
    case 'admin_users':
        $module_path = __DIR__ . '/modules/admin/users.php';
        break;
    case 'admin_permissions':
        $module_path = __DIR__ . '/modules/admin/permissions.php';
        break;
    case 'admin_reports':
        $module_path = __DIR__ . '/modules/admin/reports.php';
        break;
    case 'admin_reviews':
        $module_path = __DIR__ . '/modules/admin/reviews.php';
        break;
    case 'bot_flows':
        $module_path = __DIR__ . '/modules/bot_flows.php';
        break;
    case 'bot_flow_builder':
        $module_path = __DIR__ . '/modules/bot_flow_builder.php';
        break;
    case 'team_inbox':
        $module_path = __DIR__ . '/modules/team_inbox.php';
        break;
    case 'broadcast_campaigns':
        $module_path = __DIR__ . '/modules/broadcast_campaigns.php';
        break;
    case 'whatsapp_settings':
        $module_path = __DIR__ . '/modules/whatsapp_settings.php';
        break;
    case 'merchant_waba_settings':
        $module_path = __DIR__ . '/modules/merchant_waba_settings.php';
        break;
    case 'bulk_broadcast':
        $module_path = __DIR__ . '/modules/bulk_broadcast.php';
        break;
    case 'settings':
        $module_path = __DIR__ . '/modules/admin/settings.php';
        break;
    case 'privacy_policy':
        $module_path = __DIR__ . '/modules/privacy_policy.php';
        break;
    case 'terms_conditions':
        $module_path = __DIR__ . '/modules/terms_conditions.php';
        break;
    case 'refund_policy':
        $module_path = __DIR__ . '/modules/refund_policy.php';
        break;
    case 'policy_manager':
        $module_path = __DIR__ . '/modules/admin/policy_manager.php';
        break;
    case 'customer_kyc':
    case 'customer_kyc_admin':
        $module_path = __DIR__ . '/modules/customer_kyc_admin.php';
        break;
    default:
        $module_path = __DIR__ . '/modules/dashboard.php';
        break;
}

// Tenant Power Permissions & Module Access Guard
$is_tenant_session = (!empty($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== (defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata')) || !empty($_SESSION['impersonate_tenant_db']);
if ($is_tenant_session) {
    $active_tenant_db_name = $_SESSION['impersonate_tenant_db'] ?? $_SESSION['tenant_db'];
    
    // Normalize page keys to module keys
    $page_mod_map = [
        'dashboard' => 'dashboard',
        'leads' => 'leads',
        'pipeline' => 'pipeline',
        'followups' => 'followups',
        'demo' => 'demo',
        'quotation' => 'quotation',
        'quotation_create' => 'quotation',
        'quotation_view' => 'quotation',
        'payments' => 'payments',
        'installation' => 'installation',
        'training' => 'training',
        'support' => 'support',
        'renewals' => 'renewals',
        'whatsapp_settings' => 'whatsapp_settings',
        'merchant_waba_settings' => 'whatsapp_settings',
        'broadcast_campaigns' => 'whatsapp_settings',
        'bulk_broadcast' => 'whatsapp_settings',
        'team_inbox' => 'whatsapp_settings',
        'bot_flows' => 'whatsapp_flows',
        'bot_flow_builder' => 'whatsapp_flows',
        'admin_reports' => 'reports',
        'settings' => 'settings'
    ];

    $check_mod_key = $page_mod_map[$page] ?? null;

    if ($check_mod_key && isset($pdo_master)) {
        try {
            $stmtGuard = $pdo_master->prepare("SELECT allowed_modules FROM tenant_companies WHERE db_name = ?");
            $stmtGuard->execute([$active_tenant_db_name]);
            $tenant_allowed_json = $stmtGuard->fetchColumn();

            if (!empty($tenant_allowed_json)) {
                $tenant_allowed_arr = json_decode($tenant_allowed_json, true);
                if (is_array($tenant_allowed_arr)) {
                    $is_allowed = in_array($check_mod_key, $tenant_allowed_arr);
                    if (!$is_allowed && $check_mod_key === 'whatsapp_settings') {
                        $is_allowed = in_array('merchant_waba_settings', $tenant_allowed_arr);
                    }
                    if (!$is_allowed && $check_mod_key === 'whatsapp_flows') {
                        $is_allowed = in_array('bot_flows', $tenant_allowed_arr);
                    }

                    if (!$is_allowed) {
                        // Redirect to first accessible module instead of showing restriction message
                        $role = $_SESSION['user_role'] ?? '';
                        $tenant_mods = $_SESSION['tenant_allowed_modules'] ?? [];
                        $candidate_pages = ['dashboard', 'leads', 'pipeline', 'followups', 'demo', 'quotation', 'payments', 'bank_accounts', 'installation', 'training', 'support', 'renewals', 'reports', 'settings'];
                        $redirect_to = null;
                        foreach ($candidate_pages as $candidate) {
                            // Skip current page to avoid infinite redirect loop
                            if ($candidate === $page) continue;
                            if (!empty($tenant_mods) && in_array($candidate, $tenant_mods) && hasAccess($candidate, $role)) {
                                $redirect_to = $candidate;
                                break;
                            }
                        }
                        if ($redirect_to !== null) {
                            header("Location: index.php?page=" . urlencode($redirect_to));
                        } else {
                            // Tenant has no accessible modules - force re-login
                            session_destroy();
                            header("Location: auth/login.php");
                        }
                        exit;
                    }
                }
            }
        } catch (\PDOException $gEx) {
            // Ignore guard errors
        }
    }
}

if (file_exists($module_path)) {
    include_once __DIR__ . '/includes/header.php';
    include_once $module_path;
    include_once __DIR__ . '/includes/footer.php';
} else {
    // Visual Fallback for uncreated panels
    include_once __DIR__ . '/includes/header.php';
    echo '
    <div class="card p-6 text-center" style="max-width: 500px; margin: 4rem auto; border: 1px solid var(--border-color);">
        <i data-lucide="alert-octagon" style="width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1.5rem auto;"></i>
        <h2 class="mb-2" style="font-family: var(--font-heading);">Module Workspace Pending</h2>
        <p class="text-muted mb-4">The module [modules/' . htmlspecialchars($page) . '.php] has not been compiled or is currently under development.</p>
        <a href="index.php?page=dashboard" class="btn btn-primary">Return to Dashboard</a>
    </div>';
    include_once __DIR__ . '/includes/footer.php';
}
