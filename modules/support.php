<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Check view access
if (!hasAccess('support', $_SESSION['user_role'])) {
    echo "<div class='alert alert-danger' style='margin: 20px; padding: 15px; border-radius: 8px; font-weight: bold;'>Access Denied: You do not have permissions to view Support Tickets.</div>";
    return;
}

$canCreate = hasAccess('support_create', $_SESSION['user_role']);
$canEdit = hasAccess('support_edit', $_SESSION['user_role']);
$canAssign = hasAccess('support_assign', $_SESSION['user_role']);
$canClose = hasAccess('support_close', $_SESSION['user_role']);

// 1. Process support ticket updates/creations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'whatsapp_create_ticket') {
        header('Content-Type: application/json');
        $license_no = trim($_POST['license_no'] ?? '');
        $subject = trim($_POST['subject'] ?? 'General Technical Support');
        $problem = trim($_POST['problem'] ?? '');
        $callback_number = trim($_POST['callback_number'] ?? '');

        if (empty($license_no) || empty($problem) || empty($callback_number)) {
            echo json_encode(['status' => 'error', 'message' => 'Please fill in License Number, Problem, and Call Back Number.']);
            exit;
        }

        try {
            $ticketId = 'TCK-' . rand(1000, 9999);
            $customer_name = 'WhatsApp Client (' . $license_no . ')';
            $custStmt = $pdo->prepare("SELECT party_name FROM client_directory WHERE party_name LIKE ? OR mobile LIKE ? LIMIT 1");
            $custStmt->execute(['%' . $license_no . '%', '%' . $callback_number . '%']);
            if ($foundName = $custStmt->fetchColumn()) {
                $customer_name = $foundName;
            } else {
                $leadStmt = $pdo->prepare("SELECT name FROM leads WHERE name LIKE ? OR phone LIKE ? LIMIT 1");
                $leadStmt->execute(['%' . $license_no . '%', '%' . $callback_number . '%']);
                if ($leadName = $leadStmt->fetchColumn()) {
                    $customer_name = $leadName;
                }
            }

            $assigned_to = 'Harsh Vardhan';
            $userStmt = $pdo->query("SELECT name FROM users WHERE status = 'Active' AND (role LIKE '%Support%' OR role LIKE '%Engineer%') ORDER BY RAND() LIMIT 1");
            if ($userStmt && $uName = $userStmt->fetchColumn()) {
                $assigned_to = $uName;
            }

            $stmt = $pdo->prepare("INSERT INTO support_tickets 
                (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, address, problem, due_date, callback_number) 
                VALUES (?, ?, ?, 'high', 'open', ?, ?, ?, 'whatsapp@marglead.com', 'Marg ERP 9+', 'WhatsApp Automated Flow', ?, ?, ?)");
            
            $stmt->execute([
                $ticketId,
                $customer_name,
                $subject,
                $assigned_to,
                $license_no,
                $callback_number,
                $problem,
                date('Y-m-d', strtotime('+2 days')),
                $callback_number
            ]);

            // Notifications
            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'New Ticket Assigned', ?, 'index.php?page=support', 'warning')");
            $notifStmt->execute([$assigned_to, "WhatsApp Ticket {$ticketId} ({$license_no}) assigned to you."]);

            $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New Support Ticket Raised', ?, 'index.php?page=support', 'danger')");
            $adminNotifStmt->execute(["New WhatsApp Ticket {$ticketId} raised for {$customer_name}"]);

            echo json_encode([
                'status' => 'success',
                'ticket_id' => $ticketId,
                'customer_name' => $customer_name,
                'assigned_to' => $assigned_to,
                'license_no' => $license_no,
                'subject' => $subject,
                'problem' => $problem,
                'callback_number' => $callback_number,
                'date_created' => date('Y-m-d H:i:s'),
                'message' => "Dear Customer, 👋\n\nThank you for contacting us. Your ticket has been successfully created. 🎟️\n\nOur support team will review your issue and get back to you shortly.\n\nWe appreciate your patience and support. 😊\n\nRegards,\nSupport Team"
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($act === 'create_ticket') {
        if (!$canCreate) {
            $_SESSION['flash_error'] = "Access Denied: You do not have permissions to generate support tickets.";
            header("Location: index.php?page=support");
            exit;
        }
        
        $customer_name = trim($_POST['customer_name'] ?? '');
        $lead_id = trim($_POST['lead_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $product = trim($_POST['product'] ?? '');
        $renewal_date = empty($_POST['renewal_date']) ? null : $_POST['renewal_date'];
        $address = trim($_POST['address'] ?? '');
        
        $priority = trim($_POST['priority'] ?? 'high');
        $status = trim($_POST['status'] ?? 'open');
        $subject = trim($_POST['subject'] ?? '');
        $problem = trim($_POST['problem'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        $due_date = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $callback_number = trim($_POST['callback_number'] ?? '');
        
        $ticketId = 'TCK-' . rand(1000, 9999);
        
        if ($db_connected && $pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, renewal_date, address, problem, due_date, callback_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ticketId, $customer_name, $subject, $priority, $status, $assigned_to, $lead_id, $phone, $email, $product, $renewal_date, $address, $problem, $due_date, $callback_number]);
                
                // Write activity log if lead_id exists in leads table
                if (!empty($lead_id)) {
                    try {
                        $checkLead = $pdo->prepare("SELECT id FROM leads WHERE id = ?");
                        $checkLead->execute([$lead_id]);
                        if ($checkLead->fetch()) {
                            $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                            $actionMsg = "Created support ticket: " . $ticketId . " (\"" . $subject . "\")";
                            $logStmt->execute([$lead_id, $_SESSION['user_name'] ?? 'System User', $actionMsg]);
                        }
                    } catch (Exception $ex) {
                        // Ignore timeline FK error for client_directory records
                    }
                }
                
                // Insert notification for the assigned technician
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'New Ticket Assigned', ?, 'index.php?page=support', 'warning')");
                $notifMsg = "Ticket " . $ticketId . " has been assigned to you: " . $subject;
                $notifStmt->execute([$assigned_to, $notifMsg]);
                
                // Insert notification for the admin
                $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New Support Ticket Raised', ?, 'index.php?page=support', 'danger')");
                $adminNotifMsg = "New support ticket " . $ticketId . " raised and assigned to " . $assigned_to;
                $adminNotifStmt->execute([$adminNotifMsg]);
                
                $_SESSION['flash_success'] = "Support ticket " . $ticketId . " created successfully.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Failed to create ticket: " . $e->getMessage();
            }
        }
        header("Location: index.php?page=support");
        exit;
        
    } elseif ($act === 'update_ticket') {
        if (!$canEdit) {
            $_SESSION['flash_error'] = "Access Denied: You do not have permissions to edit support tickets.";
            header("Location: index.php?page=support");
            exit;
        }
        
        $ticketId = trim($_POST['ticket_id']);
        $priority = trim($_POST['priority']);
        $status = trim($_POST['status']);
        $subject = trim($_POST['subject']);
        $problem = trim($_POST['problem']);
        $assigned_to = trim($_POST['assigned_to']);
        $due_date = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $callback_number = trim($_POST['callback_number']);
        $lead_id = trim($_POST['lead_id'] ?? '');
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $product = trim($_POST['product'] ?? '');
        $renewal_date = empty($_POST['renewal_date']) ? null : $_POST['renewal_date'];
        $address = trim($_POST['address'] ?? '');
        
        if ($db_connected && $pdo) {
            try {
                // Fetch original ticket details for validation checks
                $origStmt = $pdo->prepare("SELECT lead_id, assigned_to, status, phone, callback_number FROM support_tickets WHERE id = ?");
                $origStmt->execute([$ticketId]);
                $orig = $origStmt->fetch();
                
                if ($orig) {
                    // Security enforcement: Non-admin users cannot edit/update tickets assigned to another employee
                    if (!$is_admin && !empty($orig['assigned_to']) && $orig['assigned_to'] !== 'Unassigned' && strtolower($orig['assigned_to']) !== strtolower($user_name)) {
                        $_SESSION['flash_error'] = "Access Denied: You can only edit or update tickets assigned to you.";
                        header("Location: index.php?page=support");
                        exit;
                    }

                    // Check Assign/Transfer permission
                    if ($orig['assigned_to'] !== $assigned_to && !$canAssign) {
                        $_SESSION['flash_error'] = "Access Denied: You do not have permissions to assign/transfer tickets.";
                        header("Location: index.php?page=support");
                        exit;
                    }
                    
                    // Check Closure permission
                    if ($orig['status'] !== $status && ($status === 'resolved' || $status === 'closed') && !$canClose) {
                        $_SESSION['flash_error'] = "Access Denied: You do not have permissions to close support tickets.";
                        header("Location: index.php?page=support");
                        exit;
                    }
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ?, status = ?, subject = ?, problem = ?, assigned_to = ?, due_date = ?, callback_number = ?, lead_id = ?, customer_name = ?, phone = ?, email = ?, product = ?, renewal_date = ?, address = ? WHERE id = ?");
                    $stmt->execute([$priority, $status, $subject, $problem, $assigned_to, $due_date, $callback_number, $lead_id, $customer_name, $phone, $email, $product, $renewal_date, $address, $ticketId]);

                    // Also sync update to raw `tickets` table if exists
                    try {
                        $stmtT = $pdo->prepare("UPDATE tickets SET license_number = ?, status = ?, customer_name = ?, mobile = ?, email = ? WHERE ticket_number = ?");
                        $stmtT->execute([$lead_id, ucfirst($status), $customer_name, $phone, $email, $ticketId]);
                    } catch (Throwable $eT) {}
                    
                    // Log status logs on timeline if lead_id exists in leads table
                    if (!empty($lead_id)) {
                        try {
                            $checkLead = $pdo->prepare("SELECT id FROM leads WHERE id = ?");
                            $checkLead->execute([$lead_id]);
                            if ($checkLead->fetch()) {
                                $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                                $actionMsg = "Updated support ticket: " . $ticketId . " (Status: " . ucfirst($status) . ", Assignee: " . $assigned_to . ")";
                                $logStmt->execute([$lead_id, $_SESSION['user_name'] ?? 'System User', $actionMsg]);
                            }
                        } catch (Exception $ex) {
                            // Ignore timeline FK error for client_directory records
                        }
                    }
                    
                    // If the assignee changed, insert transfer notifications
                    if ($orig['assigned_to'] !== $assigned_to) {
                        // Notify new technician
                        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'Ticket Transferred to You', ?, 'warning')");
                        $notifMsg = "Ticket " . $ticketId . " was transferred/assigned to you: " . $subject;
                        $notifStmt->execute([$assigned_to, $notifMsg]);
                        
                        // Notify admin of transfer
                        $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Ticket Transferred', ?, 'info')");
                        $adminNotifMsg = "Ticket " . $ticketId . " was transferred from " . $orig['assigned_to'] . " to " . $assigned_to;
                        $adminNotifStmt->execute([$adminNotifMsg]);
                    }
                    
                    // If ticket is resolved or closed, send automated WhatsApp resolution notification
                    if ($orig['status'] !== $status && ($status === 'resolved' || $status === 'closed')) {
                        $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Ticket Resolved/Closed', ?, 'success')");
                        $adminNotifMsg = "Ticket " . $ticketId . " has been marked as Resolved by " . ($_SESSION['user_name'] ?? 'System User');
                        $adminNotifStmt->execute([$adminNotifMsg]);

                        // Send WhatsApp notification to customer
                        try {
                            require_once __DIR__ . '/../api/whatsapp-api.php';
                            $whatsappObj = new WhatsAppAPI($pdo);
                            $custPhone = !empty($callback_number) ? $callback_number : (!empty($orig['phone']) ? $orig['phone'] : ($orig['callback_number'] ?? null));
                            if (!empty($custPhone)) {
                                $resMsg = "✅ *Issue Resolved*\n\n" .
                                          "Dear Customer, your support ticket *{$ticketId}* has been resolved.\n\n" .
                                          "Thank you for contacting Marg Soft Solution! 🙏\n\n" .
                                          "If you face any issues in the future, simply send *'Hi'* or *'Help'* on WhatsApp for instant support.";
                                $whatsappObj->sendText($custPhone, $resMsg);
                            }
                        } catch (Throwable $eWa) {
                            write_log('error', "Failed sending resolution WhatsApp message: " . $eWa->getMessage());
                        }
                    }
                    
                    $_SESSION['flash_success'] = "Support ticket " . $ticketId . " updated successfully.";
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Failed to update ticket: " . $e->getMessage();
            }
        }
        header("Location: index.php?page=support");
        exit;
    }
}

// 2. Fetch Client Directory, CRM Leads, and Operators for Support Tickets
$db_client_directory = [];
$db_leads = [];
$db_operators = [];

if ($db_connected && $pdo) {
    try {
        // Fetch Client Directory Records (Old Client Database)
        $stmt = $pdo->query("SELECT id, sno, customer_id, party_name, mobile, email, address, software_type, user_type, due_on, act_on, party_status, software_trade, total_amount, contact_person, city, state, online_zip_code FROM client_directory ORDER BY party_name ASC");
        $db_client_directory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch CRM Leads/Clients
        $stmt = $pdo->query("SELECT id, name, company, phone, email, address, gst, products, date(created_at) as created_date FROM leads ORDER BY company ASC");
        $db_leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Active Technicians / Operators
        $stmt = $pdo->query("SELECT name, role FROM users WHERE status = 'Active' ORDER BY name ASC");
        $db_operators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignore fetch errors
    }
}

// Build consolidated master clients array for frontend live search
$master_clients_list = [];

// 1. Add records from client_directory table
foreach ($db_client_directory as $cd) {
    $cust_id = !empty($cd['customer_id']) ? $cd['customer_id'] : ('CUST-' . $cd['id']);
    $p_name = !empty($cd['party_name']) ? $cd['party_name'] : 'Unknown Client';
    $mob = !empty($cd['mobile']) ? $cd['mobile'] : '';
    
    $master_clients_list[] = [
        'source' => 'client_directory',
        'id' => $cust_id,
        'customer_id' => $cust_id,
        'party_name' => $p_name,
        'mobile' => $mob,
        'email' => $cd['email'] ?? '',
        'address' => $cd['address'] ?? '',
        'software_type' => !empty($cd['software_type']) ? $cd['software_type'] : 'Marg Silver Edition',
        'due_on' => $cd['due_on'] ?? ($cd['act_on'] ?? ''),
        'party_status' => $cd['party_status'] ?? 'Running',
        'software_trade' => $cd['software_trade'] ?? '',
        'total_amount' => $cd['total_amount'] ?? 0,
        'display_label' => $p_name . ($mob ? " ( {$mob} )" : "")
    ];
}

// 2. Add records from leads table
foreach ($db_leads as $l) {
    $cust_id = $l['id'];
    $p_name = !empty($l['company']) ? $l['company'] : $l['name'];
    $mob = !empty($l['phone']) ? $l['phone'] : '';
    
    // Auto renewal date logic: +1 year after lead creation
    $ren_date = '';
    if (!empty($l['created_date'])) {
        $tDate = strtotime($l['created_date']);
        if ($tDate) $ren_date = date('Y-m-d', strtotime('+1 year', $tDate));
    }

    $master_clients_list[] = [
        'source' => 'leads',
        'id' => $cust_id,
        'customer_id' => $cust_id,
        'party_name' => $p_name,
        'mobile' => $mob,
        'email' => $l['email'] ?? '',
        'address' => $l['address'] ?? '',
        'software_type' => !empty($l['products']) ? $l['products'] : 'Marg ERP Pro',
        'due_on' => $ren_date,
        'party_status' => 'Active',
        'software_trade' => 'General',
        'total_amount' => 0,
        'display_label' => $p_name . ($mob ? " ( {$mob} )" : "")
    ];
}

// 3. Resolve User Role & Search/Filter Parameters for Support Tickets
$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$product_filter = trim($_GET['product'] ?? '');
$operator_filter = trim($_GET['operator'] ?? '');
$priority_filter = trim($_GET['priority'] ?? '');

$where_conditions = [];
$query_params = [];

// Non-admin employees only see tickets assigned to them
if (!$is_admin) {
    $where_conditions[] = "assigned_to = ?";
    $query_params[] = $user_name;
} elseif (!empty($operator_filter)) {
    $where_conditions[] = "assigned_to = ?";
    $query_params[] = $operator_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(id LIKE ? OR customer_name LIKE ? OR lead_id LIKE ? OR phone LIKE ? OR email LIKE ? OR subject LIKE ? OR problem LIKE ? OR address LIKE ?)";
    $st = '%' . $search_query . '%';
    for ($i = 0; $i < 8; $i++) {
        $query_params[] = $st;
    }
}

if (!empty($status_filter)) {
    $where_conditions[] = "LOWER(status) = ?";
    $query_params[] = strtolower($status_filter);
}

if (!empty($product_filter)) {
    $where_conditions[] = "(LOWER(product) = ? OR LOWER(product) LIKE ?)";
    $query_params[] = strtolower($product_filter);
    $query_params[] = '%' . strtolower($product_filter) . '%';
}

if (!empty($priority_filter)) {
    $where_conditions[] = "LOWER(priority) = ?";
    $query_params[] = strtolower($priority_filter);
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$tickets = [];
if ($db_connected && $pdo) {
    // Auto-sync incoming WhatsApp Flow tickets from `tickets` table into `support_tickets`
    try {
        $stmtSync = $pdo->query("SELECT * FROM tickets");
        if ($stmtSync) {
            $rawFlowTickets = $stmtSync->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawFlowTickets as $rt) {
                $tId = $rt['ticket_number'] ?? ('TK-' . $rt['id']);
                $cName = (!empty($rt['customer_name']) && $rt['customer_name'] !== 'Valued Customer') ? $rt['customer_name'] : ('Client (' . ($rt['mobile'] ?? 'WhatsApp') . ')');
                $subj = (!empty($rt['category']) ? $rt['category'] : 'Support') . ($rt['firm_name'] !== 'N/A' && !empty($rt['firm_name']) ? ' - ' . $rt['firm_name'] : '');
                $prio = !empty($rt['priority']) ? strtolower($rt['priority']) : 'medium';
                $stat = !empty($rt['status']) ? strtolower($rt['status']) : 'open';
                $phone = $rt['mobile'] ?? '';
                $email = ($rt['email'] !== 'N/A') ? ($rt['email'] ?? '') : '';
                $prob = $rt['description'] ?? '';
                $dateCreated = $rt['created_at'] ?? date('Y-m-d H:i:s');

                $stmtCheck = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ?");
                $stmtCheck->execute([$tId]);
                if (!$stmtCheck->fetch()) {
                    $stmtInsSync = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, phone, email, problem, callback_number, date_created) VALUES (?, ?, ?, ?, ?, 'Unassigned', ?, ?, ?, ?, ?)");
                    $stmtInsSync->execute([$tId, $cName, $subj, $prio, $stat, $phone, $email, $prob, $phone, $dateCreated]);
                }
            }
        }
    } catch (Throwable $eSync) {}

    try {
        $stmt = $pdo->prepare("SELECT * FROM support_tickets {$where_sql} ORDER BY date_created DESC");
        $stmt->execute($query_params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tickets = [];
    }
}

// Seed mock tickets if table is empty and no filters applied
if (empty($tickets) && empty($where_conditions) && $db_connected && $pdo) {
    try {
        $pdo->exec("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, renewal_date, address, problem, due_date, callback_number) VALUES
        ('TCK-8902', 'A TO Z MEDICAL STORE', 'Printer configuration issues with receipt bills', 'high', 'open', 'Vikas Patel', '177912', '7275243844', 'margsoftsolutionknp@gmail.com', 'Marg Silver Edition', '2016-02-13', 'TIRWA ROAD,KANNAUJ KANNAUJ', 'Receipt printer paper feed jams on printing daily invoices.', '2026-07-25', '7275243844'),
        ('TCK-8789', 'Metro Chemicals & Co.', 'GST return filing API mismatch error code 400', 'critical', 'in_progress', 'Harsh Vardhan', 'LD-6512', '+91 91234 56789', 'rgupta@metrochem.org', 'Marg ERP Gold', '2027-08-20', 'Industrial Area Zone 1', 'GST API throws mismatch error code 400 when generating monthly returns.', '2026-07-23', '+91 91234 56789')");
        
        $stmt = $pdo->query("SELECT * FROM support_tickets ORDER BY date_created DESC");
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ignore seeder errors
    }
}

// Calculate counters
$criticalCount = 0;
$openCount = 0;
$inProgressCount = 0;
$resolvedCount = 0;

foreach ($tickets as $t) {
    if ($t['status'] === 'resolved') {
        $resolvedCount++;
    } elseif ($t['status'] === 'in_progress') {
        $inProgressCount++;
    } else {
        $openCount++;
    }
    if ($t['priority'] === 'critical' && $t['status'] !== 'resolved') {
        $criticalCount++;
    }
}
?>

<div class="support-container" style="max-width: 1400px; margin: 0 auto;">
    
    <!-- Top Header & Action Controls -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <div class="flex align-center gap-2 text-xs text-muted mb-1">
                <span>Customer Helpdesk</span>
                <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
                <span class="font-semibold text-main">Support Ticket Desk</span>
            </div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--text-main);" class="m-0">
                Support Tickets Operations
            </h2>
            <p class="text-muted text-sm m-0">Raise tickets, track service SLA status, and assign technician handlers to client issues.</p>
        </div>

        <div class="flex gap-2 flex-wrap">
            <button class="btn text-sm flex align-center gap-2" style="background: #25D366; color: #fff; border: none; font-weight: 700; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);" onclick="window.openModal('whatsapp-simulator-modal'); startWhatsAppFlow();">
                <i data-lucide="message-square" style="width: 16px; height: 16px;"></i>
                <span>WhatsApp Bot Simulator</span>
            </button>
            <?php if ($canCreate): ?>
                <button class="btn btn-primary text-sm flex align-center gap-2" onclick="window.openModal('create-ticket-modal');">
                    <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                    <span>Generate New Ticket</span>
                </button>
            <?php endif; ?>
            <button class="btn btn-secondary text-sm" onclick="window.print();">
                <i data-lucide="printer" style="width: 16px; height: 16px;"></i>
                <span>Print Tickets Log</span>
            </button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="grid grid-4 gap-4 mb-6">
        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="ticket" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Open Queue</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo number_format($openCount); ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="clock" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">In Progress</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo number_format($inProgressCount); ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--danger-light); color: var(--danger); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="alert-triangle" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Critical Priority</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--danger);"><?php echo number_format($criticalCount); ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Resolved / Closed</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success);"><?php echo number_format($resolvedCount); ?></span>
            </div>
        </div>
    </div>

    <!-- Search & Filters Bar -->
    <div class="card p-5 mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg);">
        <form action="index.php" method="GET" class="flex flex-col gap-4">
            <input type="hidden" name="page" value="support">

            <div class="flex justify-between align-center border-bottom pb-3" style="border-bottom: 1px solid var(--border-color);">
                <div class="flex align-center gap-2">
                    <i data-lucide="filter" style="width: 18px; height: 18px; color: var(--primary);"></i>
                    <h3 class="m-0 text-sm font-bold" style="font-family: var(--font-heading);">Filter Support Tickets</h3>
                </div>
                <?php if (!empty($search_query) || !empty($status_filter) || !empty($product_filter) || !empty($operator_filter) || !empty($priority_filter)): ?>
                    <a href="index.php?page=support" class="btn btn-secondary text-xs text-danger" style="padding: 0.3rem 0.75rem;">
                        <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                        <span>Clear All Filters</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 0.75rem; align-items: end;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Search Tickets</label>
                    <div style="position: relative;">
                        <input type="text" name="search" class="form-control form-control-focus text-sm" placeholder="Ticket ID, Client, Subject, Problem..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 2.25rem;">
                        <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted);"></i>
                    </div>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Ticket Status</label>
                    <select name="status" class="form-control form-control-focus text-sm">
                        <option value="">All Statuses</option>
                        <option value="open" <?php echo ($status_filter === 'open') ? 'selected' : ''; ?>>Open</option>
                        <option value="in_progress" <?php echo ($status_filter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                        <option value="resolved" <?php echo ($status_filter === 'resolved') ? 'selected' : ''; ?>>Resolved / Closed</option>
                    </select>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Priority Level</label>
                    <select name="priority" class="form-control form-control-focus text-sm">
                        <option value="">All Priorities</option>
                        <option value="low" <?php echo ($priority_filter === 'low') ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo ($priority_filter === 'medium') ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo ($priority_filter === 'high') ? 'selected' : ''; ?>>High</option>
                        <option value="critical" <?php echo ($priority_filter === 'critical') ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Product</label>
                    <select name="product" class="form-control form-control-focus text-sm">
                        <option value="">All Products</option>
                        <option value="Marg ERP Basic" <?php echo ($product_filter === 'Marg ERP Basic') ? 'selected' : ''; ?>>Marg ERP Basic</option>
                        <option value="Marg ERP Pro" <?php echo ($product_filter === 'Marg ERP Pro') ? 'selected' : ''; ?>>Marg ERP Pro</option>
                        <option value="Marg ERP Gold" <?php echo ($product_filter === 'Marg ERP Gold') ? 'selected' : ''; ?>>Marg ERP Gold</option>
                        <option value="Marg Silver Edition" <?php echo ($product_filter === 'Marg Silver Edition') ? 'selected' : ''; ?>>Marg Silver Edition</option>
                    </select>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Assigned Technician</label>
                    <select name="operator" class="form-control form-control-focus text-sm">
                        <option value="">All Techs</option>
                        <?php foreach ($db_operators as $op): ?>
                            <option value="<?php echo htmlspecialchars($op['name']); ?>" <?php echo ($operator_filter === $op['name']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($op['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary text-sm" style="width: 100%; padding: 0.65rem 0.5rem;">
                        <i data-lucide="filter" style="width: 16px; height: 16px;"></i>
                        <span>Apply</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Support Tickets Log Table Card -->
    <div class="card p-0" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-lg); overflow: hidden;">
        <div class="p-4 flex justify-between align-center" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
            <div class="flex align-center gap-2">
                <span class="text-sm font-bold text-main">Support Service Tickets Log:</span>
                <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700; font-size: 0.8rem;">
                    <?php echo count($tickets); ?> Tickets
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" style="font-size: 0.85rem;">
                <thead>
                    <tr style="text-align: left; background-color: var(--bg-app);">
                        <th style="padding: 0.85rem 1rem;">Ticket ID</th>
                        <th>Client / Company</th>
                        <th>Mobile</th>
                        <th>Subject & Issue</th>
                        <th>Product</th>
                        <th>Priority</th>
                        <th>Assigned Tech</th>
                        <th>Status</th>
                        <th>Due Date</th>
                        <th style="text-align: right; padding-right: 1.25rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-8">
                                <i data-lucide="inbox" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; color: var(--text-muted);"></i>
                                <p class="text-sm font-semibold mb-1">No support tickets found matching your query.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): 
                            $tJson = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td style="padding: 0.85rem 1rem;">
                                    <span class="font-bold text-primary font-mono text-xs"><?php echo htmlspecialchars($t['id']); ?></span>
                                </td>
                                <td>
                                    <strong class="text-main block text-sm"><?php echo htmlspecialchars($t['customer_name']); ?></strong>
                                    <span class="text-xs text-muted font-mono">ID: <?php echo htmlspecialchars($t['lead_id'] ?? 'NA'); ?></span>
                                </td>
                                <td class="font-mono text-xs text-muted"><?php echo htmlspecialchars($t['phone'] ?? '-'); ?></td>
                                <td style="max-width: 250px;">
                                    <strong class="text-xs text-main block"><?php echo htmlspecialchars($t['subject']); ?></strong>
                                    <span class="text-xs text-muted" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo htmlspecialchars($t['problem']); ?>
                                    </span>
                                </td>
                                <td><span class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent);"><?php echo htmlspecialchars($t['product'] ?? 'Marg ERP'); ?></span></td>
                                <td>
                                    <?php 
                                        $p = strtolower($t['priority']);
                                        if ($p === 'critical') echo '<span class="badge badge-danger text-xs font-bold">CRITICAL</span>';
                                        elseif ($p === 'high') echo '<span class="badge text-xs" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">High</span>';
                                        else echo '<span class="badge text-xs text-muted">' . ucfirst($p) . '</span>';
                                    ?>
                                </td>
                                <td><span class="text-xs font-semibold text-main"><?php echo htmlspecialchars($t['assigned_to'] ?? 'Unassigned'); ?></span></td>
                                <td>
                                    <?php 
                                        $s = strtolower($t['status']);
                                        if ($s === 'resolved') echo '<span class="badge badge-success text-xs">Resolved</span>';
                                        elseif ($s === 'in_progress') echo '<span class="badge text-xs" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In Progress</span>';
                                        else echo '<span class="badge text-xs text-primary">Open</span>';
                                    ?>
                                </td>
                                <td class="font-mono text-xs text-muted"><?php echo htmlspecialchars($t['due_date'] ?? '-'); ?></td>
                                <td style="text-align: right; padding-right: 1.25rem;">
                                    <div class="flex align-center justify-end gap-1">
                                        <?php 
                                            $canUserEditThisTicket = $is_admin || empty($t['assigned_to']) || $t['assigned_to'] === 'Unassigned' || (strtolower($t['assigned_to']) === strtolower($user_name));
                                        ?>
                                        <?php if ($canEdit && $canUserEditThisTicket): ?>
                                            <button type="button" class="btn-icon" title="Edit / Transfer Ticket" onclick='openEditTicketModal(<?php echo $tJson; ?>)'>
                                                <i data-lucide="edit-3" style="width: 15px; height: 15px;"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-muted" title="Locked: Assigned to another technician"><i data-lucide="lock" style="width: 14px; height: 14px; opacity: 0.5;"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Create New Support Ticket (With Client Directory Search & Auto-Fill) -->
<div id="create-ticket-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 650px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="plus-circle" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                        Create New Support Ticket
                    </h3>
                    <span class="text-xs text-muted">Fetch client details from Client Directory database or CRM to raise a ticket.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('create-ticket-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-6 flex flex-col gap-4" action="index.php?page=support" method="POST" style="max-height: 520px; overflow-y: auto;">
            <input type="hidden" name="action" value="create_ticket">

            <!-- Client Info Cards Section (Matching User Screenshot Layout & Header) -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <div class="flex align-center justify-between mb-3 border-bottom pb-2" style="border-bottom: 1px solid var(--border-color);">
                    <h4 class="text-sm text-main font-bold m-0" style="font-family: var(--font-heading);">Client Info</h4>
                    <button type="button" id="client-details-link-btn" class="btn-link text-xs font-bold text-success flex align-center gap-1" style="display: none; background: none; border: none; cursor: pointer;" onclick="openSelectedClientDetailsModal()">
                        <i data-lucide="external-link" style="width: 13px; height: 13px;"></i>
                        <span>Client Details</span>
                    </button>
                </div>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    
                    <!-- Search / Select Client Dropdown (Searchable Auto-complete Input matching Screenshot 1) -->
                    <div class="form-group m-0" style="grid-column: span 1; position: relative;">
                        <label class="form-label text-xs font-bold text-main">Client Name</label>
                        <div style="position: relative;">
                            <input type="text" id="client-search-input" class="form-control text-xs font-semibold" placeholder="Select client" autocomplete="off" oninput="filterClientSearchDropdown()" onfocus="showClientSearchDropdown()" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main); padding-right: 2rem;">
                            <i data-lucide="chevron-down" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                        </div>
                        
                        <!-- Live Search Autocomplete Popup Menu -->
                        <div id="client-search-dropdown-menu" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 999; max-height: 220px; overflow-y: auto; background-color: var(--bg-card); border: 2px solid var(--primary); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.6); margin-top: 4px;">
                            <!-- Populate via JS -->
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Client id</label>
                        <input type="text" name="lead_id" id="new-ticket-client-id" class="form-control text-xs font-mono" readonly style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main); opacity: 0.9;">
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Mobile no.</label>
                        <input type="text" name="phone" id="new-ticket-phone" class="form-control text-xs font-mono" required style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Email ID</label>
                        <input type="email" name="email" id="new-ticket-email" class="form-control text-xs font-mono" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-main">Ticket for (Product)*</label>
                        <select name="product" id="new-ticket-product" class="form-control text-xs font-semibold" required style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                            <option value="Marg Silver Edition">Marg Silver Edition</option>
                            <option value="Marg Basic Edition">Marg Basic Edition</option>
                            <option value="Marg Gold Edition">Marg Gold Edition</option>
                            <option value="Marg ERP Basic">Marg ERP Basic</option>
                            <option value="Marg ERP Pro">Marg ERP Pro</option>
                            <option value="Marg ERP Gold">Marg ERP Gold</option>
                            <option value="Marg Books">Marg Books</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Renewal date</label>
                        <input type="date" name="renewal_date" id="new-ticket-renewal" class="form-control text-xs font-mono" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main">Address</label>
                        <textarea name="address" id="new-ticket-address" class="form-control text-xs" rows="2" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);"></textarea>
                        <input type="hidden" name="customer_name" id="new-ticket-client-name" required>
                    </div>
                </div>
            </div>

            <!-- Ticket Info Settings -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold uppercase m-0 mb-3" style="letter-spacing: 0.05em;">Ticket Parameters</h4>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Priority*</label>
                        <select name="priority" class="form-control text-xs" required>
                            <option value="low">Low - Minor issue</option>
                            <option value="medium">Medium - Normal setup</option>
                            <option value="high" selected>High - Core mismatch</option>
                            <option value="critical">Critical - System crash</option>
                        </select>
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Stage / Initial Status*</label>
                        <select name="status" class="form-control text-xs" required>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Closed/Resolved</option>
                        </select>
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Ticket Subject Summary</label>
                        <input type="text" name="subject" class="form-control text-xs" placeholder="E.g. Thermal barcode alignments failure" required>
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Problem Description*</label>
                        <textarea name="problem" class="form-control text-xs" rows="3" placeholder="Outline specific errors client is encountering..." required></textarea>
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Assign to Technician</label>
                        <select name="assigned_to" class="form-control text-xs" required>
                            <?php foreach ($db_operators as $op): ?>
                                <option value="<?php echo htmlspecialchars($op['name']); ?>"><?php echo htmlspecialchars($op['name']) . " (" . htmlspecialchars($op['role']) . ")"; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Target Due Date</label>
                        <input type="date" name="due_date" class="form-control text-xs">
                    </div>
                </div>
            </div>

            <!-- Custom parameters section -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold uppercase m-0 mb-3" style="letter-spacing: 0.05em;">Custom Operations Fields</h4>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Call Back Number</label>
                        <input type="text" name="callback_number" class="form-control text-xs" placeholder="Contact number for update calls">
                    </div>
                    <div class="form-group m-0 flex align-end">
                        <button type="button" class="btn btn-secondary text-xs w-full flex align-center justify-center gap-1" style="height: 38px;" onclick="window.openModal('custom-attributes-modal');">
                            <i data-lucide="sliders" style="width: 14px; height: 14px; color: var(--primary);"></i>
                            <span>Manage Custom Fields</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('create-ticket-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm font-bold">Create Ticket</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit / Transfer Support Ticket -->
<div id="edit-ticket-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 600px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);">
                Edit / Transfer Ticket: <span id="edit-ticket-id-display" class="text-primary font-bold"></span>
            </h3>
            <button class="btn-icon" onclick="window.closeModal('edit-ticket-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body p-6 flex flex-col gap-4" action="index.php?page=support" method="POST" style="max-height: 500px; overflow-y: auto;">
            <input type="hidden" name="action" value="update_ticket">
            <input type="hidden" name="ticket_id" id="edit-ticket-id-hidden">

            <!-- Client Info Cards Section (Editable) -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <div class="flex justify-between align-center mb-3">
                    <h4 class="text-xs text-muted font-bold uppercase m-0" style="letter-spacing: 0.05em;">Client Details (Editable)</h4>
                    <button type="button" class="btn text-xs" style="background: var(--primary); color: #fff; border: none; padding: 2px 8px; font-weight: 600;" onclick="autoFetchClientDetails()">
                        🔍 Auto-Fetch Client Info
                    </button>
                </div>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Client Name</label>
                        <input type="text" name="customer_name" id="edit-ticket-client-name" class="form-control text-xs" style="background-color: var(--bg-card);">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-primary">Client ID / License No.</label>
                        <input type="text" name="lead_id" id="edit-ticket-client-id" class="form-control text-xs font-mono" style="background-color: var(--bg-card); border-color: var(--primary);" onblur="autoFetchClientDetails()">
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Mobile No.</label>
                        <input type="text" name="phone" id="edit-ticket-phone" class="form-control text-xs font-mono" style="background-color: var(--bg-card);">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Email ID</label>
                        <input type="email" name="email" id="edit-ticket-email" class="form-control text-xs font-mono" style="background-color: var(--bg-card);">
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Product</label>
                        <input type="text" name="product" id="edit-ticket-product" class="form-control text-xs" style="background-color: var(--bg-card);">
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Renewal Date</label>
                        <input type="date" name="renewal_date" id="edit-ticket-renewal" class="form-control text-xs font-mono" style="background-color: var(--bg-card);">
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Address</label>
                        <textarea name="address" id="edit-ticket-address" class="form-control text-xs" rows="2" style="background-color: var(--bg-card);"></textarea>
                    </div>
                </div>
            </div>

            <!-- Ticket Info Settings (Editable) -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold uppercase m-0 mb-3" style="letter-spacing: 0.05em;">Edit Parameters & Assignee</h4>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Priority*</label>
                        <select name="priority" id="edit-ticket-priority" class="form-control text-xs" required>
                            <option value="low">Low - Minor issue</option>
                            <option value="medium">Medium - Normal setup</option>
                            <option value="high">High - Core mismatch</option>
                            <option value="critical">Critical - System crash</option>
                        </select>
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Status / Stage*</label>
                        <select name="status" id="edit-ticket-status" class="form-control text-xs" required <?php echo !$canClose ? 'disabled' : ''; ?>>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Closed/Resolved</option>
                        </select>
                        <?php if (!$canClose): ?>
                            <input type="hidden" name="status" id="edit-ticket-status-hidden">
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Ticket Subject Summary</label>
                        <input type="text" name="subject" id="edit-ticket-subject" class="form-control text-xs" required>
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Problem Description*</label>
                        <textarea name="problem" id="edit-ticket-problem" class="form-control text-xs" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Assign / Transfer to technician</label>
                        <select name="assigned_to" id="edit-ticket-assigned" class="form-control text-xs" required <?php echo !$canAssign ? 'disabled' : ''; ?>>
                            <?php foreach ($db_operators as $op): ?>
                                <option value="<?php echo htmlspecialchars($op['name']); ?>"><?php echo htmlspecialchars($op['name']) . " (" . htmlspecialchars($op['role']) . ")"; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$canAssign): ?>
                            <input type="hidden" name="assigned_to" id="edit-ticket-assigned-hidden">
                        <?php endif; ?>
                    </div>
                    <div class="form-group m-0">
                        <label class="form-label text-xs">Target Due Date</label>
                        <input type="date" name="due_date" id="edit-ticket-due-date" class="form-control text-xs">
                    </div>
                    
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs">Call Back Number</label>
                        <input type="text" name="callback_number" id="edit-ticket-callback" class="form-control text-xs">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('edit-ticket-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm font-bold">Save Ticket Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: View Selected Client Details Drawer -->
<div id="view-client-info-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 580px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--success-light); color: var(--success); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="building-2" style="width: 22px; height: 22px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.2rem; font-weight: 700; color: var(--text-main);" id="v-client-name-title">
                        Client Details Overview
                    </h3>
                    <span class="text-xs text-muted">Complete license & AMC information for selected client.</span>
                </div>
            </div>
            <button class="btn-icon" onclick="window.closeModal('view-client-info-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <div class="modal-body p-6 flex flex-col gap-4">
            <div class="p-4" style="background-color: var(--bg-app); border-radius: 8px; border: 1px solid var(--border-color);">
                <div class="grid grid-2 gap-3 text-xs mb-3 border-bottom pb-3">
                    <div><span class="text-muted font-semibold">Client ID:</span> <strong id="v-client-id" class="font-mono text-primary font-bold">-</strong></div>
                    <div><span class="text-muted font-semibold">Party Status:</span> <span id="v-client-status" class="badge text-xs" style="--badge-bg: var(--success-light); --badge-color: var(--success);">-</span></div>
                </div>
                
                <div class="flex flex-col gap-2 text-xs">
                    <div><span class="text-muted font-semibold">Reg Mobile:</span> <span id="v-client-mobile" class="font-mono text-main">-</span></div>
                    <div><span class="text-muted font-semibold">Reg Email:</span> <span id="v-client-email" class="font-mono text-main">-</span></div>
                    <div><span class="text-muted font-semibold">Software Type:</span> <span id="v-client-sw" class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent);">-</span></div>
                    <div><span class="text-muted font-semibold">Software Trade:</span> <span id="v-client-trade" class="text-main">-</span></div>
                    <div><span class="text-muted font-semibold">Renewal Date:</span> <span id="v-client-renewal" class="font-mono text-warning font-bold">-</span></div>
                    <div><span class="text-muted font-semibold">Total Amount / Value:</span> <strong id="v-client-amount" class="text-success font-mono font-bold">-</strong></div>
                    <div><span class="text-muted font-semibold">Registered Address:</span> <p id="v-client-address" class="text-main m-0 mt-1">-</p></div>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('view-client-info-modal')">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Consolidated master clients data from client_directory & leads tables
const masterClientsData = <?php echo json_encode($master_clients_list); ?>;
let selectedClientObj = null;

function filterClientSearchDropdown() {
    const searchInput = document.getElementById('client-search-input');
    const menu = document.getElementById('client-search-dropdown-menu');
    const query = searchInput.value.trim().toLowerCase();

    // If input is empty (user just clicked or nothing typed), do not show dropdown
    if (!query) {
        menu.style.display = 'none';
        menu.innerHTML = '';
        return;
    }

    if (!masterClientsData || masterClientsData.length === 0) {
        menu.innerHTML = '<div class="p-3 text-xs text-muted">No client directory records found in system.</div>';
        menu.style.display = 'block';
        return;
    }

    let filtered = masterClientsData.filter(c => {
        const cid = (c.customer_id || '').toLowerCase();
        const pName = (c.party_name || '').toLowerCase();
        const mob = (c.mobile || '').toLowerCase();
        const em = (c.email || '').toLowerCase();
        return cid.includes(query) || pName.includes(query) || mob.includes(query) || em.includes(query);
    });

    if (filtered.length === 0) {
        menu.innerHTML = '<div class="p-3 text-xs text-muted">No matching client found for query "' + query + '".</div>';
    } else {
        let html = '';
        filtered.forEach((client, idx) => {
            const isMatchHighlight = query && ((client.customer_id || '').toLowerCase().includes(query) || (client.mobile || '').toLowerCase().includes(query));
            const activeBgClass = isMatchHighlight ? 'background-color: var(--primary-light); color: var(--primary); font-weight: 700;' : '';
            
            html += `
                <div class="p-3 pointer text-xs border-bottom flex justify-between align-center client-dropdown-item" 
                     style="border-bottom: 1px solid var(--border-color); ${activeBgClass}"
                     onclick="selectClientRecord(${idx}, event)">
                    <div>
                        <strong class="block text-main">${client.party_name}</strong>
                        <span class="text-muted font-mono" style="font-size: 0.75rem;">Mob: ${client.mobile || 'N/A'} • ID: ${client.customer_id}</span>
                    </div>
                    <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-main);">${client.software_type}</span>
                </div>
            `;
        });
        menu.innerHTML = html;
    }
    menu.style.display = 'block';
}

function showClientSearchDropdown() {
    filterClientSearchDropdown();
}

function selectClientRecord(filteredIdx, event) {
    if (event) event.stopPropagation();

    const searchInput = document.getElementById('client-search-input');
    const query = searchInput.value.trim().toLowerCase();

    let filtered = masterClientsData.filter(c => {
        if (!query) return true;
        const cid = (c.customer_id || '').toLowerCase();
        const pName = (c.party_name || '').toLowerCase();
        const mob = (c.mobile || '').toLowerCase();
        const em = (c.email || '').toLowerCase();
        return cid.includes(query) || pName.includes(query) || mob.includes(query) || em.includes(query);
    });

    const client = filtered[filteredIdx] || masterClientsData[filteredIdx];
    if (!client) return;

    selectedClientObj = client;

    // Set input display: A TO Z MEDICAL STORE ( 7275243844 )
    searchInput.value = client.display_label;
    
    // Auto fill form input values exactly as shown in Screenshot 2
    document.getElementById('new-ticket-client-name').value = client.party_name || '';
    document.getElementById('new-ticket-client-id').value = client.customer_id || '';
    document.getElementById('new-ticket-phone').value = client.mobile || '';
    document.getElementById('new-ticket-email').value = client.email || '';
    document.getElementById('new-ticket-renewal').value = client.due_on || '';
    document.getElementById('new-ticket-address').value = client.address || '';

    // Match software type in product select
    const prodSelect = document.getElementById('new-ticket-product');
    if (client.software_type) {
        let found = false;
        for (let i = 0; i < prodSelect.options.length; i++) {
            if (prodSelect.options[i].value.toLowerCase().includes(client.software_type.toLowerCase()) || client.software_type.toLowerCase().includes(prodSelect.options[i].value.toLowerCase())) {
                prodSelect.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) {
            // Add custom option if not present
            let opt = new Option(client.software_type, client.software_type, true, true);
            prodSelect.add(opt);
        }
    }

    // Display "Client Details" link button in card header
    document.getElementById('client-details-link-btn').style.display = 'inline-flex';

    // Hide dropdown menu
    document.getElementById('client-search-dropdown-menu').style.display = 'none';
}

function openSelectedClientDetailsModal() {
    if (!selectedClientObj) {
        alert('Please select a client record first.');
        return;
    }
    
    document.getElementById('v-client-name-title').innerText = selectedClientObj.party_name;
    document.getElementById('v-client-id').innerText = selectedClientObj.customer_id;
    document.getElementById('v-client-status').innerText = selectedClientObj.party_status || 'Running';
    document.getElementById('v-client-mobile').innerText = selectedClientObj.mobile || '-';
    document.getElementById('v-client-email').innerText = selectedClientObj.email || '-';
    document.getElementById('v-client-sw').innerText = selectedClientObj.software_type || '-';
    document.getElementById('v-client-trade').innerText = selectedClientObj.software_trade || 'General';
    document.getElementById('v-client-renewal').innerText = selectedClientObj.due_on || '-';
    document.getElementById('v-client-amount').innerText = '₹' + parseFloat(selectedClientObj.total_amount || 0).toFixed(2);
    document.getElementById('v-client-address').innerText = selectedClientObj.address || '-';

    window.openModal('view-client-info-modal');
}

// Close search dropdown menu when clicking outside
document.addEventListener('click', function(e) {
    const input = document.getElementById('client-search-input');
    const menu = document.getElementById('client-search-dropdown-menu');
    if (input && menu && !input.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
    }
});

function openEditTicketModal(ticket) {
    document.getElementById('edit-ticket-id-hidden').value = ticket.id;
    document.getElementById('edit-ticket-id-display').innerText = ticket.id;
    
    // Readonly values
    document.getElementById('edit-ticket-client-name').value = ticket.customer_name;
    document.getElementById('edit-ticket-client-id').value = ticket.lead_id || "";
    document.getElementById('edit-ticket-phone').value = ticket.phone || "";
    document.getElementById('edit-ticket-email').value = ticket.email || "";
    document.getElementById('edit-ticket-product').value = ticket.product || "";
    document.getElementById('edit-ticket-renewal').value = ticket.renewal_date || "";
    document.getElementById('edit-ticket-address').value = ticket.address || "";
    
    // Editable values
    document.getElementById('edit-ticket-priority').value = ticket.priority;
    
    const statusSelect = document.getElementById('edit-ticket-status');
    if (statusSelect) {
        statusSelect.value = ticket.status;
    } else {
        const hiddenStatus = document.getElementById('edit-ticket-status-hidden');
        if (hiddenStatus) hiddenStatus.value = ticket.status;
    }
    
    document.getElementById('edit-ticket-due-date').value = ticket.due_date || "";
    document.getElementById('edit-ticket-callback').value = ticket.callback_number || "";
    
    window.openModal('edit-ticket-modal');
}

function autoFetchClientDetails() {
    const licInput = document.getElementById('edit-ticket-client-id');
    if (!licInput || !licInput.value.trim()) return;

    const query = licInput.value.trim();
    fetch('api/lookup-client.php?query=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(res => {
            if (res.success && res.found && res.data) {
                const d = res.data;
                if (d.customer_name) document.getElementById('edit-ticket-client-name').value = d.customer_name;
                if (d.phone) document.getElementById('edit-ticket-phone').value = d.phone;
                if (d.email) document.getElementById('edit-ticket-email').value = d.email;
                if (d.product) document.getElementById('edit-ticket-product').value = d.product;
                if (d.renewal_date) document.getElementById('edit-ticket-renewal').value = d.renewal_date;
                if (d.address) document.getElementById('edit-ticket-address').value = d.address;
            }
        })
        .catch(err => console.error('Client lookup error:', err));
}

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const targetTicketId = urlParams.get('open_ticket') || urlParams.get('ticket_id');
    if (targetTicketId) {
        const allTickets = <?php echo json_encode($tickets); ?>;
        const matchingTicket = allTickets.find(t => String(t.id).toLowerCase() === String(targetTicketId).toLowerCase());
        if (matchingTicket) {
            openEditTicketModal(matchingTicket);
        }
    }
});
</script>

<!-- CSS Styles for WhatsApp Bot Simulator & Flow Form -->
<style>
    .wa-container {
        width: 100%;
        max-width: 440px;
        background: #0b141a;
        color: #e9edef;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 12px 32px rgba(0,0,0,0.6);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        position: relative;
    }
    .wa-header {
        background: #111b21;
        padding: 10px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid #222d34;
    }
    .wa-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #6b21a8;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 16px;
    }
    .wa-chat-body {
        height: 440px;
        overflow-y: auto;
        padding: 14px;
        background: #0b141a url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-83c6-dcdb39b60970.png');
        background-blend-mode: overlay;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .wa-msg {
        max-width: 85%;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 13px;
        line-height: 1.45;
        position: relative;
        word-wrap: break-word;
        white-space: pre-line;
    }
    .wa-msg-in {
        background: #202c33;
        color: #e9edef;
        align-self: flex-start;
        border-top-left-radius: 2px;
    }
    .wa-msg-out {
        background: #005c4b;
        color: #e9edef;
        align-self: flex-end;
        border-top-right-radius: 2px;
    }
    .wa-time {
        font-size: 10px;
        color: #8696a0;
        float: right;
        margin-top: 4px;
        margin-left: 8px;
    }
    .wa-card {
        background: #111b21;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #222d34;
        margin-bottom: 4px;
    }
    .wa-card-img {
        width: 100%;
        height: 130px;
        object-fit: cover;
        background: #1f2c34;
    }
    .wa-card-body {
        padding: 10px;
        font-size: 12.5px;
    }
    .wa-card-title {
        font-weight: 700;
        font-size: 13.5px;
        margin-bottom: 4px;
        color: #e9edef;
    }
    .wa-card-desc {
        color: #8696a0;
        font-size: 11.5px;
        line-height: 1.35;
    }
    .wa-btn-group {
        display: flex;
        border-top: 1px solid #222d34;
        margin-top: 6px;
    }
    .wa-btn-action {
        flex: 1;
        padding: 10px;
        text-align: center;
        background: #233138;
        color: #00a884;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        border: none;
        transition: background 0.15s;
    }
    .wa-btn-action:hover {
        background: #182229;
    }
    .wa-btn-action + .wa-btn-action {
        border-left: 1px solid #222d34;
    }
    /* Flow Overlay Modal */
    .wa-flow-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: #111b21;
        z-index: 100;
        display: flex;
        flex-direction: column;
        color: #e9edef;
    }
    .wa-flow-header {
        padding: 14px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid #222d34;
    }
    .wa-flow-body {
        padding: 16px;
        flex: 1;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .wa-form-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .wa-form-label {
        font-size: 11px;
        color: #8696a0;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
    }
    .wa-form-control {
        background: #202c33;
        border: 1px solid #2a3942;
        border-radius: 8px;
        padding: 10px 12px;
        color: #e9edef;
        font-size: 13px;
        outline: none;
        transition: border 0.15s;
    }
    .wa-form-control:focus {
        border-color: #00a884;
    }
    .wa-submit-btn {
        background: #00a884;
        color: #111b21;
        border: none;
        border-radius: 20px;
        padding: 12px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        margin-top: 10px;
        transition: opacity 0.15s;
    }
    .wa-submit-btn:hover {
        opacity: 0.9;
    }
</style>

<!-- Modal: WhatsApp Bot Simulator & WhatsApp Flow Form -->
<div id="whatsapp-simulator-modal" class="modal-overlay" style="background: rgba(0,0,0,0.85); backdrop-filter: blur(4px);">
    <div class="wa-container" style="max-width: 440px; margin: 0 auto; border: 1px solid #222d34;">
        <!-- Header -->
        <div class="wa-header">
            <div class="flex align-center gap-3">
                <div class="wa-avatar">M</div>
                <div class="flex flex-col">
                    <span class="font-bold text-sm" style="color: #e9edef; line-height: 1.2;">Marg Help soft solution</span>
                    <span style="font-size: 11px; color: #00a884;">online</span>
                </div>
            </div>
            <div class="flex align-center gap-3" style="color: #aebac1;">
                <i data-lucide="video" style="width: 18px; height: 18px; cursor: pointer;"></i>
                <i data-lucide="phone" style="width: 18px; height: 18px; cursor: pointer;"></i>
                <button class="btn-icon" style="color: #aebac1; padding: 0;" onclick="window.closeModal('whatsapp-simulator-modal');"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
            </div>
        </div>

        <!-- Chat Container -->
        <div class="wa-chat-body" id="wa-chat-container">
            <!-- Messages populated via JS -->
        </div>

        <!-- Chat Input Bar -->
        <div style="background: #111b21; padding: 8px 12px; display: flex; align-items: center; gap: 8px; border-top: 1px solid #222d34;">
            <i data-lucide="smile" style="width: 22px; height: 22px; color: #8696a0; cursor: pointer;"></i>
            <i data-lucide="paperclip" style="width: 22px; height: 22px; color: #8696a0; cursor: pointer;"></i>
            <input type="text" id="wa-input-msg" placeholder="Type a message..." style="flex: 1; background: #2a3942; border: none; border-radius: 8px; padding: 8px 12px; color: #e9edef; font-size: 13px; outline: none;" onkeydown="if(event.key==='Enter') sendUserWAMessage();">
            <div style="width: 32px; height: 32px; border-radius: 50%; background: #00a884; color: #111b21; display: flex; align-items: center; justify-content: center; cursor: pointer;" onclick="sendUserWAMessage();">
                <i data-lucide="send" style="width: 15px; height: 15px;"></i>
            </div>
        </div>

        <!-- WhatsApp Flow Overlay Form Modal (Matching Screenshot 2) -->
        <div id="wa-flow-overlay" class="wa-flow-overlay hidden">
            <div class="wa-flow-header">
                <div class="flex align-center gap-2">
                    <span class="font-bold text-sm" style="color: #e9edef;">Welcome to Marg Soft</span>
                </div>
                <div class="flex align-center gap-3">
                    <i data-lucide="more-vertical" style="width: 18px; height: 18px; color: #8696a0;"></i>
                    <i data-lucide="x" style="width: 18px; height: 18px; color: #8696a0; cursor: pointer;" onclick="closeWAFlowOverlay();"></i>
                </div>
            </div>
            
            <div class="wa-flow-body">
                <div class="font-bold text-sm" style="color: #e9edef; font-size: 14px;">Please Provide Your Info and Problem Here..</div>
                
                <form id="wa-flow-form" onsubmit="submitWAFlowForm(event);" style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="wa-form-group">
                        <label class="wa-form-label">License Number</label>
                        <input type="text" id="wa-license-no" required placeholder="Client Id (E.g. LIC-8821 or 177912)" class="wa-form-control" value="LIC-8821">
                        <span style="font-size: 10px; color: #8696a0;">Client Id</span>
                    </div>

                    <div class="wa-form-group">
                        <label class="wa-form-label">Subject</label>
                        <select id="wa-subject" class="wa-form-control">
                            <option value="Billing & Printing Paper Feed Issue">Billing & Printing Paper Feed Issue</option>
                            <option value="GST API Return Mismatch Error Code 400">GST API Return Mismatch Error Code 400</option>
                            <option value="License Key Renewal & Registration">License Key Renewal & Registration</option>
                            <option value="Database Backup & Restore Request">Database Backup & Restore Request</option>
                            <option value="Multi-user Server Connection Error">Multi-user Server Connection Error</option>
                        </select>
                    </div>

                    <div class="wa-form-group">
                        <label class="wa-form-label">Problem</label>
                        <textarea id="wa-problem" required rows="4" maxlength="600" placeholder="Describe the issue in detail..." class="wa-form-control" oninput="updateWACharCount(this)">Receipt printer paper feed jams on printing daily invoices, and GST API throws mismatch code 400.</textarea>
                        <div class="flex justify-between align-center" style="font-size: 10px; color: #8696a0;">
                            <span>Problem</span>
                            <span id="wa-char-counter">96 / 600</span>
                        </div>
                    </div>

                    <div class="wa-form-group">
                        <label class="wa-form-label">Call Back Number</label>
                        <input type="text" id="wa-callback-no" required placeholder="Call Back Number (E.g. 7275243844)" class="wa-form-control" value="7275243844">
                        <span style="font-size: 10px; color: #8696a0;">Call Back Number</span>
                    </div>

                    <button type="submit" id="wa-submit-btn" class="wa-submit-btn" style="background: #233138; color: #8696a0;">Submit</button>
                    
                    <div class="text-center text-xs mt-2" style="color: #8696a0; font-size: 11px;">
                        Managed by Marg soft solution. <a href="#" style="color: #00a884; text-decoration: none;">Learn more</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    let waStep = 0;

    function startWhatsAppFlow() {
        waStep = 1;
        const container = document.getElementById('wa-chat-container');
        if (!container) return;
        
        container.innerHTML = `
            <div class="wa-msg wa-msg-out">
                <span>hi</span>
                <span class="wa-time">11:45 pm ✓✓</span>
            </div>

            <div class="wa-msg wa-msg-in" style="max-width: 90%;">
                <div class="wa-card">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?q=80&w=600&auto=format&fit=crop" class="wa-card-img" alt="Marg ERP 9+">
                    <div class="wa-card-body">
                        <div class="wa-card-title">Welcome To Marg Soft Solution</div>
                        <div class="wa-card-desc">Indian business management and accounting software designed for small and medium businesses. It helps companies manage daily operations such as billing, accounting, inventory, GST compliance, sales, purchases, and reporting from a single platform.</div>
                    </div>
                    <div class="wa-btn-group">
                        <button type="button" class="wa-btn-action" onclick="handleWABtnClick('Sales')">Sales</button>
                        <button type="button" class="wa-btn-action" onclick="handleWABtnClick('Support')">Support</button>
                    </div>
                </div>
                <span class="wa-time">11:45 pm</span>
            </div>
        `;
        container.scrollTop = container.scrollHeight;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function sendUserWAMessage() {
        const input = document.getElementById('wa-input-msg');
        if (!input || !input.value.trim()) return;
        const text = input.value.trim();
        input.value = '';

        appendWAMessage(text, 'out');
        
        if (text.toLowerCase() === 'hi' || text.toLowerCase() === 'hello') {
            startWhatsAppFlow();
        } else {
            setTimeout(() => {
                appendWAMessage("Thanks for reaching out! Click 'Support' to raise a ticket directly.", 'in');
            }, 600);
        }
    }

    function handleWABtnClick(choice) {
        appendWAMessage(choice, 'out');

        if (choice === 'Support') {
            setTimeout(() => {
                const container = document.getElementById('wa-chat-container');
                const msgHtml = `
                    <div class="wa-msg wa-msg-in" style="max-width: 90%;">
                        <div class="wa-card">
                            <div class="wa-card-body">
                                <div class="wa-card-title">Provide info and problem here</div>
                            </div>
                            <div class="wa-btn-group">
                                <button type="button" class="wa-btn-action" style="color: #00a884; width: 100%; text-align: center; font-weight: 700;" onclick="openWAFlowOverlay()">Create ticket</button>
                            </div>
                        </div>
                        <span class="wa-time">11:45 pm</span>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', msgHtml);
                container.scrollTop = container.scrollHeight;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 500);
        } else if (choice === 'Sales') {
            setTimeout(() => {
                appendWAMessage("For Sales inquiries, please call our Sales Hotline at +91 91234 56789 or email sales@margsoft.com.", 'in');
            }, 500);
        }
    }

    function appendWAMessage(text, type) {
        const container = document.getElementById('wa-chat-container');
        if (!container) return;
        const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }).toLowerCase();
        const html = `
            <div class="wa-msg wa-msg-${type}">
                <span>${escapeHtml(text)}</span>
                <span class="wa-time">${timeStr} ${type === 'out' ? '✓✓' : ''}</span>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
        container.scrollTop = container.scrollHeight;
    }

    function openWAFlowOverlay() {
        const overlay = document.getElementById('wa-flow-overlay');
        if (overlay) {
            overlay.classList.remove('hidden');
            validateWAFlowForm();
        }
    }

    function closeWAFlowOverlay() {
        const overlay = document.getElementById('wa-flow-overlay');
        if (overlay) overlay.classList.add('hidden');
    }

    function updateWACharCount(textarea) {
        const counter = document.getElementById('wa-char-counter');
        if (counter && textarea) {
            counter.textContent = `${textarea.value.length} / 600`;
        }
        validateWAFlowForm();
    }

    function validateWAFlowForm() {
        const lic = document.getElementById('wa-license-no').value.trim();
        const prob = document.getElementById('wa-problem').value.trim();
        const phone = document.getElementById('wa-callback-no').value.trim();
        const submitBtn = document.getElementById('wa-submit-btn');

        if (submitBtn) {
            if (lic && prob && phone) {
                submitBtn.style.background = '#00a884';
                submitBtn.style.color = '#111b21';
                submitBtn.disabled = false;
            } else {
                submitBtn.style.background = '#233138';
                submitBtn.style.color = '#8696a0';
                submitBtn.disabled = true;
            }
        }
    }

    document.querySelectorAll('#wa-license-no, #wa-problem, #wa-callback-no').forEach(el => {
        if (el) el.addEventListener('input', validateWAFlowForm);
    });

    function submitWAFlowForm(e) {
        e.preventDefault();
        const license_no = document.getElementById('wa-license-no').value.trim();
        const subject = document.getElementById('wa-subject').value.trim();
        const problem = document.getElementById('wa-problem').value.trim();
        const callback_number = document.getElementById('wa-callback-no').value.trim();

        if (!license_no || !problem || !callback_number) return;

        closeWAFlowOverlay();
        
        // Show Response Sent outgoing bubble
        appendWAMessage("Create ticket\nResponse sent", 'out');

        // Show Unread Divider
        const container = document.getElementById('wa-chat-container');
        const dividerHtml = `<div class="text-center my-2" style="font-size: 11px; color: #8696a0; background: #182229; padding: 3px 12px; border-radius: 10px; width: max-content; margin: 8px auto;">1 unread message</div>`;
        container.insertAdjacentHTML('beforeend', dividerHtml);

        // Dispatch AJAX to save ticket in DB
        const formData = new FormData();
        formData.append('action', 'whatsapp_create_ticket');
        formData.append('license_no', license_no);
        formData.append('subject', subject);
        formData.append('problem', problem);
        formData.append('callback_number', callback_number);

        fetch('index.php?page=support', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const confMsg = `Dear Customer, 👋\n\nThank you for contacting us. Your ticket has been successfully created. 🎟️\n\nOur support team will review your issue and get back to you shortly.\n\nWe appreciate your patience and support. 😊\n\nRegards,\nSupport Team`;
                
                setTimeout(() => {
                    appendWAMessage(confMsg, 'in');
                }, 500);

                // Auto-refresh support page table after 2.5 seconds to show live created ticket
                setTimeout(() => {
                    if (typeof refreshDataWithoutReload === 'function') {
                        refreshDataWithoutReload(true);
                    } else {
                        window.location.reload();
                    }
                }, 1000);
            } else {
                appendWAMessage("Error creating ticket: " + (data.message || 'Server error'), 'in');
            }
        })
        .catch(err => {
            appendWAMessage("Error creating ticket. Please check connection.", 'in');
        });
    }

    function escapeHtml(text) {
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
</script>
