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

// Ajax handler to fetch ticket history
if (isset($_GET['action']) && $_GET['action'] === 'get_ticket_history') {
    header('Content-Type: application/json');
    $tId = trim($_GET['ticket_id'] ?? '');
    try {
        $stmtH = $pdo->prepare("SELECT * FROM support_ticket_history WHERE ticket_id = ? ORDER BY created_at ASC");
        $stmtH->execute([$tId]);
        $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'history' => $history]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 1. Process support ticket updates/creations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    if ($act === 'take_ticket') {
        $ticketId = trim($_POST['ticket_id'] ?? '');
        $currentUserName = $_SESSION['user_name'] ?? 'Support Engineer';
        $currentUserRole = $_SESSION['user_role'] ?? 'Technical Support';

        if (empty($ticketId)) {
            $_SESSION['flash_error'] = "Invalid ticket ID provided.";
            header("Location: index.php?page=support");
            exit;
        }

        if ($db_connected && $pdo) {
            try {
                $checkStmt = $pdo->prepare("SELECT id, assigned_to, status, dropped_by_emp_phone, dropped_by_emp_name, phone, callback_number, customer_name FROM support_tickets WHERE id = ?");
                $checkStmt->execute([$ticketId]);
                $origTicket = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$origTicket) {
                    $_SESSION['flash_error'] = "Ticket not found.";
                    header("Location: index.php?page=support");
                    exit;
                }

                $curAssigned = trim($origTicket['assigned_to'] ?? '');
                $isUnassigned = (empty($curAssigned) || strtolower($curAssigned) === 'unassigned');

                if (!$isUnassigned && strtolower($curAssigned) !== strtolower($currentUserName) && !$is_admin) {
                    $_SESSION['flash_error'] = "Ticket #{$ticketId} has already been taken by {$curAssigned}.";
                    header("Location: index.php?page=support");
                    exit;
                }

                $newStatus = (strtolower($origTicket['status'] ?? '') === 'open') ? 'in_progress' : $origTicket['status'];
                $updStmt = $pdo->prepare("UPDATE support_tickets SET assigned_to = ?, status = ? WHERE id = ?");
                $updStmt->execute([$currentUserName, $newStatus, $ticketId]);

                try {
                    $stmtT = $pdo->prepare("UPDATE tickets SET status = ? WHERE ticket_number = ?");
                    $stmtT->execute([ucfirst($newStatus), $ticketId]);
                } catch (Throwable $eT) {}

                // Log into support_ticket_history
                try {
                    $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details) VALUES (?, 'taken', ?, ?, ?)");
                    $stmtH->execute([
                        $ticketId,
                        $currentUserName,
                        $currentUserRole,
                        "Ticket claimed / taken by {$currentUserName} ({$currentUserRole})"
                    ]);
                } catch (Throwable $eH) {}

                // Admin Notification
                try {
                    $adminNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'Ticket Claimed', ?, 'index.php?page=support', 'info')");
                    $adminNotif->execute(["{$currentUserName} took ticket #{$ticketId}"]);
                } catch (Throwable $eN) {}

                // WhatsApp Notification to dropped_by employee
                $empDropPhone = $origTicket['dropped_by_emp_phone'] ?? '';
                if (!empty($empDropPhone)) {
                    try {
                        require_once __DIR__ . '/../api/whatsapp-api.php';
                        $whatsappObj = new WhatsAppAPI($pdo);
                        $clientDisplayPhone = !empty($origTicket['callback_number']) ? $origTicket['callback_number'] : ($origTicket['phone'] ?? '');
                        $clientDisplayName = trim($origTicket['customer_name'] ?? '');
                        $clientInfo = $clientDisplayPhone;
                        if (!empty($clientDisplayName) && $clientDisplayName !== 'Client' && $clientDisplayName !== '-' && strpos($clientDisplayName, 'Client (') !== 0) {
                            $clientInfo .= " (" . $clientDisplayName . ")";
                        }

                        $nowStr = date('d M Y, h:i A');
                        $takeMsg = "*SUPPORT TICKET ACCEPTED*\n" .
                                   "──────────────────────────\n" .
                                   "*Ticket ID:* #{$ticketId}\n" .
                                   "*Client:* {$clientInfo}\n" .
                                   "*Status:* In Progress\n" .
                                   "*Assigned Engineer:* {$currentUserName}\n" .
                                   "*Timestamp:* {$nowStr}\n" .
                                   "──────────────────────────\n" .
                                   "_{$currentUserName} has accepted this ticket and initiated technical support._";
                        $whatsappObj->sendText($empDropPhone, $takeMsg);
                    } catch (Throwable $eWa) {
                        write_log('error', "Failed sending team agent take update: " . $eWa->getMessage());
                    }
                }

                $_SESSION['flash_success'] = "Ticket #{$ticketId} successfully assigned to you! You can now call the client and update details.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "Failed to take ticket: " . $e->getMessage();
            }
        }

        header("Location: index.php?page=support");
        exit;
    }

    if ($act === 'whatsapp_create_ticket') {
        header('Content-Type: application/json');
        $license_no = trim($_POST['license_no'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $problem = trim($_POST['problem'] ?? '');
        $callback_number = trim($_POST['callback_number'] ?? '');

        if (empty($license_no) || empty($problem) || empty($callback_number)) {
            echo json_encode(['status' => 'error', 'message' => 'Please fill in License Number, Problem, and Call Back Number.']);
            exit;
        }

        // Auto-fill subject from problem text if subject is empty or generic
        if (empty($subject) || $subject === 'General Technical Support') {
            $subject = mb_strimwidth($problem, 0, 70, '...');
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

            $date_created = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO support_tickets 
                (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, address, problem, due_date, callback_number, date_created) 
                VALUES (?, ?, ?, 'high', 'open', ?, ?, ?, 'whatsapp@marglead.com', 'Marg ERP 9+', 'WhatsApp Automated Flow', ?, ?, ?, ?)");
            
            $stmt->execute([
                $ticketId,
                $customer_name,
                $subject,
                $assigned_to,
                $license_no,
                $callback_number,
                $problem,
                date('Y-m-d', strtotime('+2 days')),
                $callback_number,
                $date_created
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
                'date_created' => $date_created,
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
        
        if (empty($subject) && !empty($problem)) {
            $subject = mb_strimwidth($problem, 0, 70, '...');
        }

        $ticketId = 'TCK-' . rand(1000, 9999);
        
        if ($db_connected && $pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, renewal_date, address, problem, due_date, callback_number, date_created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
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
        $ticketId = trim($_POST['ticket_id']);
        $priority = trim($_POST['priority']);
        $status = trim($_POST['status']);
        $subject = trim($_POST['subject']);
        $problem = trim($_POST['problem']);
        $resolution = trim($_POST['resolution'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
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
                $origStmt = $pdo->prepare("SELECT lead_id, assigned_to, status, phone, callback_number, resolution, problem, dropped_by_emp_phone, dropped_by_emp_name, source, customer_name FROM support_tickets WHERE id = ?");
                $origStmt->execute([$ticketId]);
                $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($orig) {
                    $origAssigned = strtolower(trim($orig['assigned_to'] ?? ''));
                    $currentUser = strtolower(trim($user_name ?? ''));
                    $isAssignedToMe = !empty($origAssigned) && $origAssigned !== 'unassigned' && ($origAssigned === $currentUser);

                    // Super Admin, Admin, assigned employee, or user with edit permission can edit/update
                    if (!$is_admin && !$isAssignedToMe && !$canEdit) {
                        $_SESSION['flash_error'] = "Access Denied: You can only edit or update tickets assigned to you.";
                        header("Location: index.php?page=support");
                        exit;
                    }

                    // Check Assign/Transfer permission - user can transfer if Admin, has canAssign, or ticket is assigned to them
                    $canTransfer = $is_admin || $canAssign || $isAssignedToMe;
                    if (!$canTransfer && $orig['assigned_to'] !== $assigned_to) {
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
                    
                    $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ?, status = ?, subject = ?, problem = ?, resolution = ?, assigned_to = ?, due_date = ?, callback_number = ?, lead_id = ?, customer_name = ?, phone = ?, email = ?, product = ?, renewal_date = ?, address = ? WHERE id = ?");
                    $stmt->execute([$priority, $status, $subject, $problem, $resolution, $assigned_to, $due_date, $callback_number, $lead_id, $customer_name, $phone, $email, $product, $renewal_date, $address, $ticketId]);

                    // Log transfer in support_ticket_history
                    if ($orig['assigned_to'] !== $assigned_to) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details) VALUES (?, 'transferred', ?, ?, ?)");
                            $stmtH->execute([
                                $ticketId,
                                $user_name,
                                $user_role,
                                "Ticket transferred from '{$orig['assigned_to']}' to '{$assigned_to}'"
                            ]);
                        } catch (Throwable $eH) {}
                    }

                    // Log status change in support_ticket_history
                    if ($orig['status'] !== $status) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details) VALUES (?, 'status_change', ?, ?, ?)");
                            $stmtH->execute([
                                $ticketId,
                                $user_name,
                                $user_role,
                                "Status updated from '" . ucfirst($orig['status']) . "' to '" . ucfirst($status) . "'"
                            ]);
                        } catch (Throwable $eH) {}
                    }

                    // Log resolution in support_ticket_history
                    if (!empty($resolution) && ($orig['resolution'] ?? '') !== $resolution) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details) VALUES (?, 'resolution', ?, ?, ?)");
                            $stmtH->execute([
                                $ticketId,
                                $user_name,
                                $user_role,
                                "Resolution notes: {$resolution}"
                            ]);
                        } catch (Throwable $eH) {}
                    }

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
                    
                    // =========================================================
                    // 1. WhatsApp Notifications for Team Member (Reverse Status Loop)
                    // =========================================================
                    $empDropPhone = $orig['dropped_by_emp_phone'] ?? '';
                    $techAgentName = $_SESSION['user_name'] ?? 'Technical Support Engineer';
                    $clientDisplayPhone = !empty($phone) ? $phone : ($orig['phone'] ?? $callback_number ?? '');
                    $clientDisplayName = trim(!empty($customer_name) ? $customer_name : ($orig['customer_name'] ?? ''));
                    $clientInfo = $clientDisplayPhone;
                    if (!empty($clientDisplayName) && $clientDisplayName !== 'Client' && $clientDisplayName !== '-' && strpos($clientDisplayName, 'Client (') !== 0) {
                        $clientInfo .= " (" . $clientDisplayName . ")";
                    }
                    $nowStr = date('d M Y, h:i A');

                    if (!empty($empDropPhone)) {
                        try {
                            require_once __DIR__ . '/../api/whatsapp-api.php';
                            $whatsappObj = new WhatsAppAPI($pdo);

                            if ($status === 'resolved' || $status === 'closed') {
                                if ($orig['status'] !== $status) {
                                    $resNote = !empty($resolution) ? $resolution : "Issue marked as resolved.";
                                    $empClosureMsg = "*SUPPORT TICKET RESOLVED*\n" .
                                                     "──────────────────────────\n" .
                                                     "*Ticket ID:* #{$ticketId}\n" .
                                                     "*Client:* {$clientInfo}\n" .
                                                     "*Status:* Resolved & Closed\n" .
                                                     "*Resolved By:* {$techAgentName} (Technical Support)\n" .
                                                     "*Resolution Details:* {$resNote}\n" .
                                                     "*Closed At:* {$nowStr}\n" .
                                                     "──────────────────────────\n" .
                                                     "_The service request has been successfully resolved and closed in the CRM._";
                                    $whatsappObj->sendText($empDropPhone, $empClosureMsg);
                                }
                            } else {
                                // Status changed (e.g. Call Back, In Progress, Follow-up) OR new remarks/resolution updated
                                $statusChanged = ($orig['status'] !== $status);
                                $remarksChanged = (!empty($resolution) && ($orig['resolution'] ?? '') !== $resolution);
                                
                                if ($statusChanged || $remarksChanged) {
                                    $statusLabel = ucwords(str_replace('_', ' ', $status));
                                    $resNote = !empty($resolution) ? $resolution : (!empty($problem) ? $problem : "Status updated by technician.");
                                    $empUpdateMsg = "*SUPPORT TICKET UPDATE*\n" .
                                                    "──────────────────────────\n" .
                                                    "*Ticket ID:* #{$ticketId}\n" .
                                                    "*Client:* {$clientInfo}\n" .
                                                    "*Current Status:* {$statusLabel}\n" .
                                                    "*Handled By:* {$techAgentName} (Technical Support)\n" .
                                                    "*Remarks:* {$resNote}\n" .
                                                    "*Timestamp:* {$nowStr}\n" .
                                                    "──────────────────────────\n" .
                                                    "_Technical support team has updated the status for this ticket._";
                                    $whatsappObj->sendText($empDropPhone, $empUpdateMsg);
                                }
                            }
                        } catch (Throwable $eWaEmp) {
                            write_log('error', "Failed sending team agent WhatsApp update: " . $eWaEmp->getMessage());
                        }
                    }

                    // =========================================================
                    // 2. Customer Notification (ONLY for Customer-Generated Flow Tickets!)
                    // RULE: NEVER send automated bot messages to clients dropped by team members!
                    // =========================================================
                    if (($orig['source'] ?? '') !== 'team_whatsapp_drop') {
                        if ($orig['status'] !== $status && ($status === 'resolved' || $status === 'closed')) {
                            $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Ticket Resolved/Closed', ?, 'success')");
                            $adminNotifMsg = "Ticket " . $ticketId . " has been marked as Resolved by " . $techAgentName;
                            $adminNotifStmt->execute([$adminNotifMsg]);

                            // Send WhatsApp notification to customer
                            try {
                                if (!isset($whatsappObj)) {
                                    require_once __DIR__ . '/../api/whatsapp-api.php';
                                    $whatsappObj = new WhatsAppAPI($pdo);
                                }
                                $custPhone = !empty($callback_number) ? $callback_number : (!empty($orig['phone']) ? $orig['phone'] : ($orig['callback_number'] ?? null));
                                if (!empty($custPhone)) {
                                    $resMsg = "✅ *Issue Resolved*\n\n" .
                                              "Dear Customer, your support ticket *{$ticketId}* has been resolved.\n\n" .
                                              "Thank you for contacting Marg Soft Solution! 🙏\n\n" .
                                              "If you face any issues in the future, simply send *'Hi'* or *'Help'* on WhatsApp for instant support.";
                                    $whatsappObj->sendText($custPhone, $resMsg);
                                }
                            } catch (Throwable $eWa) {
                                write_log('error', "Failed sending customer resolution WhatsApp message: " . $eWa->getMessage());
                            }
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
        $stmt = $pdo->query("SELECT * FROM client_directory ORDER BY party_name ASC");
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
    
    $master_clients_list[] = array_merge($cd, [
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
    ]);
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

// Non-admin employees see tickets assigned to them OR unassigned pool tickets
if (!$is_admin) {
    $where_conditions[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) OR assigned_to IS NULL OR TRIM(assigned_to) = '' OR LOWER(TRIM(assigned_to)) = 'unassigned')";
    $query_params[] = $user_name;
} elseif (!empty($operator_filter)) {
    $where_conditions[] = "LOWER(TRIM(assigned_to)) = LOWER(TRIM(?))";
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
    $stVal = strtolower($status_filter);
    if ($stVal === 'all') {
        // Show all tickets including resolved/closed
    } elseif ($stVal === 'resolved' || $stVal === 'closed') {
        $where_conditions[] = "LOWER(status) IN ('resolved', 'closed')";
    } elseif ($stVal === 'pending' || $stVal === 'in_progress') {
        $where_conditions[] = "LOWER(status) IN ('in_progress', 'pending')";
    } else {
        $where_conditions[] = "LOWER(status) = ?";
        $query_params[] = $stVal;
    }
} else {
    // Default view: Hide resolved/closed tickets, show only Open & Pending/In-Progress tickets
    $where_conditions[] = "LOWER(status) NOT IN ('resolved', 'closed')";
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

// Clean up old demo tickets from database if present
if ($db_connected && $pdo) {
    try {
        $pdo->exec("DELETE FROM support_tickets WHERE id IN ('TCK-8902', 'TCK-8789')");
        $pdo->exec("DELETE FROM tickets WHERE ticket_number IN ('TCK-8902', 'TCK-8789')");
    } catch (Throwable $eClean) {}
}

// Calculate counters directly from database for user scope
$criticalCount = 0;
$openCount = 0;
$inProgressCount = 0;
$resolvedCount = 0;

if ($db_connected && $pdo) {
    try {
        $cConds = [];
        $cParams = [];
        if (!$is_admin) {
            $cConds[] = "(LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) OR assigned_to IS NULL OR TRIM(assigned_to) = '' OR LOWER(TRIM(assigned_to)) = 'unassigned')";
            $cParams[] = $user_name;
        }
        $cSql = !empty($cConds) ? "WHERE " . implode(" AND ", $cConds) : "";
        $cStmt = $pdo->prepare("SELECT status, priority FROM support_tickets {$cSql}");
        $cStmt->execute($cParams);
        $allT = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allT as $ct) {
            $cStat = strtolower($ct['status'] ?? '');
            if ($cStat === 'resolved' || $cStat === 'closed') {
                $resolvedCount++;
            } elseif ($cStat === 'in_progress' || $cStat === 'pending') {
                $inProgressCount++;
            } else {
                $openCount++;
            }
            if (strtolower($ct['priority'] ?? '') === 'critical' && $cStat !== 'resolved' && $cStat !== 'closed') {
                $criticalCount++;
            }
        }
    } catch (Throwable $eC) {}
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
            <!-- <button class="btn text-sm flex align-center gap-2" style="background: #25D366; color: #fff; border: none; font-weight: 700; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);" onclick="window.openModal('whatsapp-simulator-modal'); startWhatsAppFlow();">
                <i data-lucide="message-square" style="width: 16px; height: 16px;"></i>
                <span>WhatsApp Bot Simulator</span>
            </button> -->
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

    <!-- KPI Summary Row (4 Columns Side-by-Side) -->
    <div class="mb-6" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
        <a href="index.php?page=support&status=open" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'open') ? 'var(--primary)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'open') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="ticket" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Open Queue</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo number_format($openCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&status=in_progress" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'in_progress' || $status_filter === 'pending') ? 'var(--warning)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'in_progress' || $status_filter === 'pending') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="clock" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">In Progress / Pending</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo number_format($inProgressCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&priority=critical" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($priority_filter === 'critical') ? 'var(--danger)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($priority_filter === 'critical') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--danger-light); color: var(--danger); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="alert-triangle" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Critical Priority</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--danger);"><?php echo number_format($criticalCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&status=resolved" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'var(--success)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Resolved / Closed</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success);"><?php echo number_format($resolvedCount); ?></span>
            </div>
        </a>
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
                        <option value="" <?php echo (empty($status_filter)) ? 'selected' : ''; ?>>Active (Open &amp; Pending)</option>
                        <option value="open" <?php echo ($status_filter === 'open') ? 'selected' : ''; ?>>Open Only</option>
                        <option value="in_progress" <?php echo ($status_filter === 'in_progress' || $status_filter === 'pending') ? 'selected' : ''; ?>>In Progress / Pending</option>
                        <option value="resolved" <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'selected' : ''; ?>>Resolved / Closed</option>
                        <option value="all" <?php echo ($status_filter === 'all') ? 'selected' : ''; ?>>All Tickets (Include Closed)</option>
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
                        <th>Call Back No.</th>
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
                                    <span class="font-bold text-primary font-mono text-xs block"><?php echo htmlspecialchars($t['id']); ?></span>
                                    <span class="text-xs text-muted font-mono block mt-1" style="font-size: 0.7rem;" title="Ticket Creation Date">
                                        <i data-lucide="calendar" style="width: 10px; height: 10px; display: inline-block; vertical-align: middle; margin-right: 2px;"></i>
                                        <?php echo !empty($t['date_created']) ? date('d M Y, h:i A', strtotime($t['date_created'])) : date('d M Y'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $cNameDisplay = trim($t['customer_name'] ?? '');
                                        if (stripos($cNameDisplay, 'Client (') === 0) {
                                            $cNameDisplay = '';
                                        }
                                    ?>
                                    <strong class="text-main block text-sm"><?php echo htmlspecialchars(!empty($cNameDisplay) ? $cNameDisplay : '-'); ?></strong>
                                    <span class="text-xs text-muted font-mono">ID: <?php echo htmlspecialchars(!empty($t['lead_id']) ? $t['lead_id'] : 'NA'); ?></span>
                                </td>
                                <td>
                                    <?php 
                                        $phoneNum = trim(!empty($t['callback_number']) ? $t['callback_number'] : ($t['phone'] ?? ''));
                                        $cleanPhone = preg_replace('/[^0-9+]/', '', $phoneNum);
                                        $displayPhone = preg_replace('/^\+?91/', '', $cleanPhone);
                                        if (strlen($displayPhone) !== 10) {
                                            $displayPhone = $cleanPhone;
                                        }
                                        $telPayload = 'tel:' . $cleanPhone;
                                        $cNameEsc = htmlspecialchars(addslashes(!empty($cNameDisplay) ? $cNameDisplay : 'Client'), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <div class="flex align-center gap-1.5">
                                        <span class="font-mono text-xs text-main font-semibold"><?php echo htmlspecialchars($displayPhone ?: '-'); ?></span>
                                        <?php if (!empty($cleanPhone)): ?>
                                            <button type="button" class="btn text-xs p-1" style="background: rgba(37,99,235,0.1); color: var(--primary); border: none; border-radius: 6px; padding: 2px 6px; cursor: pointer;" title="Scan QR to call on smartphone dial pad" onclick="openCallQrModal('<?php echo $cNameEsc; ?>', '<?php echo $cleanPhone; ?>', '<?php echo urlencode($telPayload); ?>')">
                                                <i data-lucide="qr-code" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                                <span style="font-size: 0.68rem; font-weight: 700;">QR</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="max-width: 250px;">
                                    <?php 
                                        $dispProb = trim($t['problem'] ?? '');
                                        if (stripos($dispProb, 'Client lead forwarded by') === 0) {
                                            $dispProb = '';
                                        }
                                        $dispSubj = trim($t['subject'] ?? '');
                                        if (stripos($dispSubj, 'Support Lead: Client') === 0) {
                                            $dispSubj = 'Technical Support';
                                        }
                                        $primaryText = !empty($dispProb) ? $dispProb : (!empty($dispSubj) ? $dispSubj : 'Technical Support');
                                        $secondaryText = !empty($t['resolution']) ? ('Solution: ' . $t['resolution']) : ((!empty($dispProb) && !empty($dispSubj) && $dispProb !== $dispSubj) ? $dispSubj : '');
                                    ?>
                                    <strong class="text-xs text-main block"><?php echo htmlspecialchars($primaryText); ?></strong>
                                    <?php if (!empty($secondaryText)): ?>
                                        <span class="text-xs text-muted" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                            <?php echo htmlspecialchars($secondaryText); ?>
                                        </span>
                                    <?php endif; ?>
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
                                <td>
                                    <span class="badge text-xs" style="--badge-bg: rgba(59, 130, 246, 0.15); --badge-color: #3b82f6; font-weight: 700;">
                                        <i data-lucide="user-check" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i>
                                        <?php echo htmlspecialchars(!empty($t['assigned_to']) ? $t['assigned_to'] : 'Unassigned'); ?>
                                    </span>
                                </td>
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
                                    <div class="flex align-center justify-end gap-1.5">
                                        <?php 
                                            $tAssigned = strtolower(trim($t['assigned_to'] ?? ''));
                                            $currUser = strtolower(trim($user_name ?? ''));
                                            $isAssignedToMe = !empty($tAssigned) && $tAssigned !== 'unassigned' && ($tAssigned === $currUser);
                                            $isUnassigned = empty($tAssigned) || $tAssigned === 'unassigned';
                                            $canUserEditThisTicket = $is_admin || $isAssignedToMe || ($canEdit && $isUnassigned);
                                        ?>
                                        <?php if ($isUnassigned): ?>
                                            <button type="button" class="btn btn-xs flex align-center gap-1 font-bold" style="background: #10b981; color: #ffffff; border: none; border-radius: 6px; padding: 3px 8px; font-size: 0.72rem; cursor: pointer; box-shadow: 0 1px 4px rgba(16,185,129,0.3);" title="Take / Claim this ticket" onclick="takeTicket('<?php echo htmlspecialchars($t['id']); ?>')">
                                                <i data-lucide="hand" style="width: 12px; height: 12px;"></i>
                                                <span>Take</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canUserEditThisTicket): ?>
                                            <button type="button" class="btn-icon" title="Edit / Update Ticket" onclick='openEditTicketModal(<?php echo $tJson; ?>)'>
                                                <i data-lucide="edit-3" style="width: 15px; height: 15px; color: var(--primary);"></i>
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
                            <option value="Unassigned">Unassigned</option>
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

<!-- Modal 2: Edit / Transfer Support Ticket — Modern Professional Redesign -->
<div id="edit-ticket-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 900px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); color: var(--text-main); border-radius: 20px; border: 1px solid var(--border-color); box-shadow: 0 32px 64px -12px rgba(0,0,0,0.6); overflow: hidden;">
        
        <!-- HEADER (Fixed Top) -->
        <div style="flex-shrink: 0; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1e40af) 100%); padding: 1rem 1.5rem;" class="flex align-center justify-between">
            <div class="flex align-center gap-3">
                <div style="background: rgba(255,255,255,0.18); backdrop-filter: blur(8px); padding: 0.55rem; border-radius: 12px; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="wrench" style="width:20px; height:20px; color:#fff;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; color: #fff; letter-spacing: -0.01em;">
                        Edit / Transfer Ticket: <span id="edit-ticket-id-display" style="color: #93c5fd; font-family: monospace;"></span>
                    </h3>
                    <span style="font-size: 0.72rem; color: rgba(255,255,255,0.75);">Auto-fetch client directory details, set technician assignment, and update resolution notes.</span>
                </div>
            </div>
            <button type="button" onclick="window.closeModal('edit-ticket-modal')" style="background: rgba(255,255,255,0.15); border: none; border-radius: 10px; width: 34px; height: 34px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.28)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                <i data-lucide="x" style="width:18px; height:18px; color:#fff;"></i>
            </button>
        </div>

        <form action="index.php?page=support" method="POST" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
            <input type="hidden" name="action" value="update_ticket">
            <input type="hidden" name="ticket_id" id="edit-ticket-id-hidden">

            <div class="modal-body p-6 flex flex-col gap-5" style="flex: 1; min-height: 0; overflow-y: auto; background: var(--bg-app);">
                
                <!-- SECTION 1: CLIENT DETAILS (COMPACT VIEW-ONLY SUMMARY CARD) -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1rem 1.15rem;">
                    <div class="flex justify-between align-center mb-3">
                        <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); display:flex; align-items:center; gap: 6px;">
                            <i data-lucide="id-card" style="width:14px; height:14px;"></i> Client Directory Profile (View-Only)
                        </div>
                        <div class="flex align-center gap-2">
                            <span style="font-size: 0.68rem; color: var(--text-muted); font-weight: 600;">🔒 Non-Editable Profile</span>
                            <button type="button" class="btn text-xs flex align-center gap-1 font-bold" style="background: var(--primary); color: #fff; border: none; padding: 0.25rem 0.65rem; border-radius: 6px;" onclick="autoFetchClientDetails()">
                                <i data-lucide="search" style="width: 11px; height: 11px;"></i>
                                <span>Auto-Fetch</span>
                            </button>
                        </div>
                    </div>

                    <!-- Hidden Form Inputs for POST data -->
                    <input type="hidden" name="customer_name" id="edit-ticket-client-name">
                    <input type="hidden" name="phone" id="edit-ticket-phone">
                    <input type="hidden" name="email" id="edit-ticket-email">
                    <input type="hidden" name="product" id="edit-ticket-product">
                    <input type="hidden" name="renewal_date" id="edit-ticket-renewal">
                    <input type="hidden" name="address" id="edit-ticket-address">

                    <!-- Top Lookup Row: Client ID / License No -->
                    <div class="flex align-center gap-3 mb-3 pb-2.5" style="border-bottom: 1px solid var(--border-color);">
                        <label class="text-xs font-bold text-primary flex align-center gap-1" style="white-space: nowrap;">
                            <i data-lucide="key" style="width: 13px; height: 13px;"></i> Client ID / License No.*:
                        </label>
                        <input type="text" name="lead_id" id="edit-ticket-client-id" class="form-control text-xs font-mono font-bold" style="max-width: 220px; height: 32px; border-radius: 7px; border-color: var(--primary);" onblur="autoFetchClientDetails()" placeholder="Enter License No.">
                    </div>

                    <!-- High-Legibility Organized 3-Column Field Cards Grid -->
                    <div style="background: var(--bg-app); border-radius: 12px; border: 1px solid var(--border-color); padding: 0.85rem; display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem;">
                        
                        <!-- Item 1: Company Name -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Company Name</span>
                            <strong id="edit-v-company" style="font-size: 0.82rem; color: var(--text-main); font-weight: 700; word-break: break-word;">-</strong>
                        </div>

                        <!-- Item 2: Contact Person -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Contact Person</span>
                            <span id="edit-v-contact" style="font-size: 0.82rem; color: var(--text-main); font-weight: 700; word-break: break-word;">-</span>
                        </div>

                        <!-- Item 3: Reg Mobile -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <div class="flex justify-between align-center mb-1">
                                <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.04em;">Reg Mobile</span>
                                <button type="button" id="edit-v-mobile-qr-btn" class="btn text-xs" style="background: rgba(37,99,235,0.1); color: var(--primary); border: none; padding: 1px 5px; border-radius: 4px; display: none; cursor: pointer;" title="Scan QR to call Reg Mobile" onclick="event.stopPropagation();">
                                    <i data-lucide="qr-code" style="width: 10px; height: 10px;"></i>
                                    <span style="font-size: 0.65rem; font-weight: 700;">QR</span>
                                </button>
                            </div>
                            <span id="edit-v-mobile" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 700;">-</span>
                        </div>

                        <!-- Item 4: Call Back No. (Separate Box) -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <div class="flex justify-between align-center mb-1">
                                <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: #0284c7; letter-spacing: 0.04em;">Call Back No.</span>
                                <button type="button" id="edit-v-callback-qr-btn" class="btn text-xs" style="background: rgba(16,185,129,0.1); color: #10b981; border: none; padding: 1px 5px; border-radius: 4px; display: none; cursor: pointer;" title="Scan QR to call Callback Number" onclick="event.stopPropagation();">
                                    <i data-lucide="qr-code" style="width: 10px; height: 10px;"></i>
                                    <span style="font-size: 0.65rem; font-weight: 700;">QR</span>
                                </button>
                            </div>
                            <span id="edit-v-callback" class="font-mono font-bold" style="font-size: 0.82rem; color: #0284c7;">-</span>
                        </div>

                        <!-- Item 5: Reg Email ID (Compact 1 Column) -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Reg Email ID</span>
                            <span id="edit-v-email" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600; word-break: break-all;">-</span>
                        </div>

                        <!-- Item 6: Party Status -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Party Status</span>
                            <span id="edit-v-status" class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted); width: fit-content;">-</span>
                        </div>

                        <!-- Item 7: Software Type -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Software Type</span>
                            <span id="edit-v-product" class="badge text-xs" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); width: fit-content;">-</span>
                        </div>

                        <!-- Item 8: S/W Edition -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">S/W Edition</span>
                            <span id="edit-v-swtype" class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-main); width: fit-content;">-</span>
                        </div>

                        <!-- Item 9: User Type / Users -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">User Type / Users</span>
                            <span id="edit-v-usertype" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 10: No of Companies -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">No of Companies</span>
                            <span id="edit-v-companies" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 11: Software Trade -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Software Trade</span>
                            <span id="edit-v-trade" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 12: Home User -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Home User</span>
                            <span id="edit-v-homeuser" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 13: Renewal Date -->
                        <div id="edit-v-renewal-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column; transition: all 0.2s;">
                            <div class="flex justify-between align-center mb-1">
                                <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.04em;">Renewal Date</span>
                                <span id="edit-v-renewal-badge" style="display: none; font-size: 0.62rem; font-weight: 800; padding: 1px 6px; border-radius: 4px;"></span>
                            </div>
                            <span id="edit-v-renewal" class="font-mono font-bold" style="font-size: 0.82rem; color: var(--text-main);">-</span>
                            <span id="edit-v-renewal-sub" style="display: none; font-size: 0.66rem; font-weight: 700; margin-top: 2px;"></span>
                        </div>

                        <!-- Item 14: Act On Date -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Act On Date</span>
                            <span id="edit-v-acton" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 15: Last Hit Date -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Last Hit Date</span>
                            <span id="edit-v-lasthit" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 16: Total Contract Value -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Total Contract Value</span>
                            <strong id="edit-v-amount" class="text-success font-mono font-bold" style="font-size: 0.85rem;">-</strong>
                        </div>

                        <!-- Item 17: Sub Partner (span 2) -->
                        <div style="grid-column: span 2; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Sub Partner Code / Name</span>
                            <span id="edit-v-subpartner" class="font-mono" style="font-size: 0.82rem; color: var(--text-main); font-weight: 600;">-</span>
                        </div>

                        <!-- Item 18: Registered Address (span 3 / Full Width) -->
                        <div style="grid-column: span 3; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.55rem 0.75rem; display: flex; flex-direction: column;">
                            <span style="font-size: 0.63rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 2px; letter-spacing: 0.04em;">Registered Address</span>
                            <span id="edit-v-fulladdress" style="font-size: 0.8rem; color: var(--text-main); font-weight: 600; line-height: 1.4;">-</span>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: TICKET ISSUE & SOLUTION DETAILS -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="file-text" style="width:14px; height:14px;"></i> Issue Summary &amp; Employee Resolution
                    </div>

                    <!-- Hidden input for subject preservation -->
                    <input type="hidden" name="subject" id="edit-ticket-subject">

                    <div class="grid grid-2 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Problem Description (Client Reported)</label>
                            <textarea name="problem" id="edit-ticket-problem" rows="3" class="form-control text-xs" style="border-radius: 8px; resize: vertical;" placeholder="Client's query or issue notes (fill when calling client)..."></textarea>
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: #10b981;">Resolved Description / Solution (Employee Fill)</label>
                            <textarea name="resolution" id="edit-ticket-resolution" rows="3" class="form-control text-xs" style="border-radius: 8px; resize: vertical; border-color: rgba(16,185,129,0.4);" placeholder="Enter solution details, technical steps taken, or resolution provided for client..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: EDIT PARAMETERS & ASSIGNEE -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); margin-bottom: 0.85rem; display:flex; align-items:center; gap: 6px;">
                        <i data-lucide="settings" style="width:14px; height:14px;"></i> Ticket Stage &amp; Technician Assignment
                    </div>

                    <div class="grid grid-3 gap-3 mb-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Priority *</label>
                            <select name="priority" id="edit-ticket-priority" class="form-control text-xs" style="border-radius: 8px;" required>
                                <option value="low">Low - Minor issue</option>
                                <option value="medium">Medium - Normal setup</option>
                                <option value="high">High - Core mismatch</option>
                                <option value="critical">Critical - System crash</option>
                            </select>
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Status / Stage *</label>
                            <select name="status" id="edit-ticket-status" class="form-control text-xs" style="border-radius: 8px;" required <?php echo !$canClose ? 'disabled' : ''; ?>>
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Closed/Resolved</option>
                            </select>
                            <?php if (!$canClose): ?>
                                <input type="hidden" name="status" id="edit-ticket-status-hidden">
                            <?php endif; ?>
                        </div>

                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Assign / Transfer Technician</label>
                            <select name="assigned_to" id="edit-ticket-assigned" class="form-control text-xs" style="border-radius: 8px;" required>
                                <option value="Unassigned">Unassigned</option>
                                <?php foreach ($db_operators as $op): ?>
                                    <option value="<?php echo htmlspecialchars($op['name']); ?>"><?php echo htmlspecialchars($op['name']) . " (" . htmlspecialchars($op['role']) . ")"; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-2 gap-3">
                        <div class="form-group m-0">
                            <label class="form-label text-xs font-bold" style="color: var(--text-main);">Target Due Date</label>
                            <input type="date" name="due_date" id="edit-ticket-due-date" class="form-control text-xs font-mono" style="border-radius: 8px;">
                        </div>

                        <div class="form-group m-0">
                            <div class="flex justify-between align-center mb-1">
                                <label class="form-label text-xs font-bold" style="color: var(--text-main);">Call Back Number</label>
                                <button type="button" class="btn text-xs flex align-center gap-1 font-bold" style="background: rgba(37,99,235,0.1); color: var(--primary); border: none; padding: 2px 6px; border-radius: 5px; cursor: pointer;" title="Scan QR to call on smartphone dial pad" onclick="triggerCallbackQrCall()">
                                    <i data-lucide="qr-code" style="width: 11px; height: 11px;"></i>
                                    <span>Scan QR Call</span>
                                </button>
                            </div>
                            <input type="text" name="callback_number" id="edit-ticket-callback" class="form-control text-xs font-mono" style="border-radius: 8px;" placeholder="Contact number for update calls">
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: TICKET LIFECYCLE & ASSIGNMENT HISTORY -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div class="flex justify-between align-center mb-2">
                        <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--primary); display:flex; align-items:center; gap: 6px;">
                            <i data-lucide="history" style="width:14px; height:14px;"></i> Assignment &amp; Activity History
                        </div>
                        <span id="edit-history-count" class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted); font-size: 0.68rem; font-weight: 700;">0 logs</span>
                    </div>

                    <div id="edit-ticket-history-container" style="max-height: 160px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.5rem; padding-right: 4px;">
                        <p class="text-xs text-muted m-0" style="font-style: italic;">Loading ticket history...</p>
                    </div>
                </div>

            </div>

            <!-- FOOTER (ALWAYS FIXED AT BOTTOM) -->
            <div style="flex-shrink: 0; padding: 0.85rem 1.5rem; background: var(--border-card); border-top: 1px solid var(--border-color); display:flex; justify-content:flex-end; gap: 0.75rem; align-items:center;">
                <button type="button" onclick="window.closeModal('edit-ticket-modal')" class="btn btn-secondary flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.25rem; font-size: 0.8rem;">
                    <i data-lucide="x" style="width:14px; height:14px;"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary font-bold flex align-center gap-2" style="border-radius: 9px; padding: 0.5rem 1.5rem; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                    <i data-lucide="check-circle-2" style="width:15px; height:15px;"></i>
                    <span>Save Ticket Changes</span>
                </button>
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

<!-- Modal 4: Enlarged Scan-to-Call QR Code -->
<div id="call-qr-modal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(8px); z-index: 99999; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.25s ease;">
    <div class="modal-container" style="max-width: 380px; width: 90%; text-align: center; background: var(--bg-card); border-radius: 18px; border: 1px solid var(--border-color); box-shadow: 0 20px 50px rgba(0,0,0,0.4); overflow: hidden; transform: scale(0.95); transition: transform 0.25s ease;">
        <div class="modal-header" style="background: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 0.85rem 1.25rem; display: flex; align-items: center; justify-content: space-between;">
            <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.05rem; font-weight: 700; color: var(--text-main);" id="qr-modal-title">Scan to Call Client</h3>
            <button type="button" class="btn-icon" onclick="closeCallQrModal()" style="border: none; background: transparent; cursor: pointer;"><i data-lucide="x" style="width: 18px; height: 18px; color: var(--text-muted);"></i></button>
        </div>
        <div class="modal-body flex flex-col align-center p-6 gap-4" style="background: var(--bg-app); padding: 1.5rem; display: flex; flex-direction: column; align-items: center;">
            <div style="background: #ffffff; padding: 16px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
                <img id="qr-modal-img" src="" alt="Scan QR Code to Dial" style="width: 220px; height: 220px; border-radius: 8px; display: block; object-fit: contain;">
            </div>
            <div class="flex flex-col gap-1" style="margin-top: 0.75rem; text-align: center;">
                <span class="text-base font-bold font-mono" id="qr-modal-phone" style="color: var(--primary); font-size: 1.1rem;"></span>
                <span class="text-xs text-muted" style="line-height: 1.4; display: block; margin-top: 4px;">Point your smartphone camera at this QR code to load the phone number directly into your mobile dial pad.</span>
            </div>
        </div>
        <div class="modal-footer p-4" style="background: var(--border-card); border-top: 1px solid var(--border-color); padding: 1rem;">
            <a id="qr-modal-tel-link" href="#" class="btn btn-primary text-xs flex align-center justify-center gap-2" style="width: 100%; border-radius: 8px; padding: 0.65rem; font-weight: 700; display: flex; align-items: center; justify-content: center; text-decoration: none;">
                <i data-lucide="phone-call" style="width: 15px; height: 15px;"></i>
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
    if (typeof event !== 'undefined' && event && event.stopPropagation) {
        event.stopPropagation();
    }
    const modal = document.getElementById('call-qr-modal');
    if (!modal) {
        alert('QR Modal element not found');
        return;
    }
    
    const formattedPhone = normalizePhoneForDialing(phone);
    const telPayload = 'tel:' + formattedPhone;
    const encodedPayload = encodeURIComponent(telPayload);

    const titleEl = document.getElementById('qr-modal-title'); if (titleEl) titleEl.textContent = 'Call ' + (name || 'Client');
    const phoneEl = document.getElementById('qr-modal-phone'); if (phoneEl) phoneEl.textContent = formattedPhone || '-';
    const linkEl = document.getElementById('qr-modal-tel-link'); if (linkEl) linkEl.href = telPayload;
    
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=4&data=' + encodedPayload;
    const fallbackUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=260x260&chl=' + encodedPayload;
    
    const qrImg = document.getElementById('qr-modal-img');
    if (qrImg) {
        qrImg.onerror = function() {
            this.onerror = null;
            this.src = fallbackUrl;
        };
        qrImg.src = qrUrl;
    }
    
    modal.classList.add('open');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';

    const container = modal.querySelector('.modal-container');
    if (container) container.style.transform = 'scale(1)';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}
window.openCallQrModal = openCallQrModal;

function closeCallQrModal() {
    const modal = document.getElementById('call-qr-modal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.opacity = '0';
        modal.style.pointerEvents = 'none';
        const container = modal.querySelector('.modal-container');
        if (container) container.style.transform = 'scale(0.95)';
    }
}
window.closeCallQrModal = closeCallQrModal;

function triggerCallbackQrCall() {
    const phoneInput = document.getElementById('edit-ticket-callback');
    const nameInput = document.getElementById('edit-ticket-client-name');
    if (!phoneInput || !phoneInput.value.trim()) {
        alert('Please enter or fetch a call back number first.');
        return;
    }
    const phone = phoneInput.value.trim();
    const cleanPhone = phone.replace(/[^0-9+]/g, '');
    const name = (nameInput && nameInput.value.trim()) ? nameInput.value.trim() : 'Client';
    openCallQrModal(name, cleanPhone, encodeURIComponent('tel:' + cleanPhone));
}
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

function updateClientCompactView(data) {
    let rawName = (data.customer_name || data.party_name || '').trim();
    if (rawName.startsWith('Client (') && rawName.endsWith(')')) {
        rawName = '';
    }
    const hasParty = !!(rawName && rawName !== '-');
    const pName = hasParty ? rawName : '-';
    const contact = (data.contact_person && data.contact_person.trim() !== '') ? data.contact_person.trim() : (hasParty ? pName : '-');
    
    let mob = data.phone || data.mobile || '-';
    if (mob && mob !== '-') {
        const cleanDigits = String(mob).replace(/[^0-9]/g, '');
        if (cleanDigits.length === 12 && cleanDigits.startsWith('91')) {
            mob = cleanDigits.substring(2);
        } else if (cleanDigits.length === 10) {
            mob = cleanDigits;
        }
    }
    const em = (data.email && data.email !== 'N/A') ? data.email : '-';
    const prod = data.product || data.software_type || (hasParty ? 'Marg ERP' : '-');
    const swType = data.sw_type || (hasParty ? 'Marg' : '-');
    const uType = (data.user_type || data.no_of_users) ? ((data.user_type || 'Multi User') + (data.no_of_users ? ' (' + data.no_of_users + ')' : '')) : (hasParty ? 'Multi User (1)' : '-');
    const numComp = data.no_of_companies || (hasParty ? 250 : '-');
    const stat = data.party_status || (hasParty ? 'Running' : '-');
    const trade = data.software_trade || (hasParty ? 'Business Services' : '-');
    const homeUser = data.home_user || (hasParty ? 'No' : '-');
    const amt = (data.total_amount && parseFloat(data.total_amount) > 0) ? ('₹' + parseFloat(data.total_amount).toFixed(2)) : (hasParty ? '₹0.00' : '-');
    const ren = data.renewal_date || data.due_on || '-';
    const actOn = data.act_on || '-';
    const lastHit = data.last_hit_date || '-';
    const subPartner = (data.sub_partner_code || data.sub_partner_name) ? ((data.sub_partner_code || '-') + ' / ' + (data.sub_partner_name || '-')) : '-';
    
    // Full Address combination
    let fullAddr = data.address || '';
    if (data.address1) fullAddr = data.address1;
    if (data.address2) fullAddr += (fullAddr ? ', ' : '') + data.address2;
    if (data.address3) fullAddr += (fullAddr ? ', ' : '') + data.address3;
    if (data.city) fullAddr += (fullAddr ? ', ' : '') + data.city;
    if (data.state) fullAddr += (fullAddr ? ', ' : '') + data.state;
    if (data.online_zip_code) fullAddr += ' - ' + data.online_zip_code;
    if (!fullAddr) fullAddr = '-';

    // Hidden inputs for POST form submit
    const elName = document.getElementById('edit-ticket-client-name'); if (elName) elName.value = pName !== '-' ? pName : '';
    const elPhone = document.getElementById('edit-ticket-phone'); if (elPhone) elPhone.value = mob !== '-' ? mob : '';
    const elEmail = document.getElementById('edit-ticket-email'); if (elEmail) elEmail.value = em !== '-' ? em : '';
    const elProd = document.getElementById('edit-ticket-product'); if (elProd) elProd.value = prod !== '-' ? prod : '';
    const elRen = document.getElementById('edit-ticket-renewal'); if (elRen) elRen.value = ren !== '-' ? ren : '';
    const elAddr = document.getElementById('edit-ticket-address'); if (elAddr) elAddr.value = fullAddr !== '-' ? fullAddr : '';

    // Compact summary view labels
    const vComp = document.getElementById('edit-v-company'); if (vComp) vComp.innerText = pName;
    const vContact = document.getElementById('edit-v-contact'); if (vContact) vContact.innerText = contact;
    const vMob = document.getElementById('edit-v-mobile'); if (vMob) vMob.innerText = mob;
    
    // Show QR call button on Reg Mobile card
    const vMobQrBtn = document.getElementById('edit-v-mobile-qr-btn');
    if (vMobQrBtn) {
        if (mob && mob !== '-') {
            vMobQrBtn.onclick = function(e) {
                if (e) e.stopPropagation();
                window.openCallQrModal(pName, mob, '');
            };
            vMobQrBtn.style.display = 'inline-flex';
        } else {
            vMobQrBtn.style.display = 'none';
        }
    }

    // Populate Call Back No. (Never mistakenly fallback to Reg Mobile)
    const currentCbInput = document.getElementById('edit-ticket-callback');
    const existingCbInputVal = currentCbInput ? currentCbInput.value.trim() : '';
    let cbNum = (data.callback_number && data.callback_number.trim() !== '') ? data.callback_number.trim() 
                : ((data.callback_no && data.callback_no.trim() !== '') ? data.callback_no.trim() 
                : ((data.call_back_number && data.call_back_number.trim() !== '') ? data.call_back_number.trim() 
                : (existingCbInputVal !== '' ? existingCbInputVal : '-')));

    if (cbNum && cbNum !== '-') {
        const cleanCb = String(cbNum).replace(/[^0-9]/g, '');
        if (cleanCb.length === 12 && cleanCb.startsWith('91')) {
            cbNum = cleanCb.substring(2);
        } else if (cleanCb.length === 10) {
            cbNum = cleanCb;
        }
    }

    const vCb = document.getElementById('edit-v-callback'); if (vCb) vCb.innerText = cbNum;
    const vCbQrBtn = document.getElementById('edit-v-callback-qr-btn');
    if (vCbQrBtn) {
        if (cbNum && cbNum !== '-') {
            vCbQrBtn.onclick = function(e) {
                if (e) e.stopPropagation();
                window.openCallQrModal(pName, cbNum, '');
            };
            vCbQrBtn.style.display = 'inline-flex';
        } else {
            vCbQrBtn.style.display = 'none';
        }
    }

    const vEm = document.getElementById('edit-v-email'); if (vEm) vEm.innerText = em;
    const vProd = document.getElementById('edit-v-product'); if (vProd) vProd.innerText = prod;
    const vSwType = document.getElementById('edit-v-swtype'); if (vSwType) vSwType.innerText = swType;
    const vUType = document.getElementById('edit-v-usertype'); if (vUType) vUType.innerText = uType;
    const vCompNum = document.getElementById('edit-v-companies'); if (vCompNum) vCompNum.innerText = numComp;
    const vStat = document.getElementById('edit-v-status');
    if (vStat) {
        vStat.innerText = stat;
        if (stat === 'Running' || stat === 'Active') {
            vStat.style.setProperty('--badge-bg', 'rgba(16,185,129,0.12)');
            vStat.style.setProperty('--badge-color', '#10b981');
        } else {
            vStat.style.setProperty('--badge-bg', 'var(--border-card)');
            vStat.style.setProperty('--badge-color', 'var(--text-muted)');
        }
    }
    const vTrade = document.getElementById('edit-v-trade'); if (vTrade) vTrade.innerText = trade;
    const vHome = document.getElementById('edit-v-homeuser'); if (vHome) vHome.innerText = homeUser;
    const vAmt = document.getElementById('edit-v-amount'); if (vAmt) vAmt.innerText = amt;

    // Dynamic Renewal Date Styling & Overdue / Days-Left Calculation
    const renCard = document.getElementById('edit-v-renewal-card');
    const renBadge = document.getElementById('edit-v-renewal-badge');
    const renSub = document.getElementById('edit-v-renewal-sub');
    const renElem = document.getElementById('edit-v-renewal');

    if (renElem) renElem.innerText = ren;

    if (ren && ren !== '-' && ren !== '0000-00-00') {
        let renDate = null;
        const s = String(ren).trim().split(' ')[0];
        if (/^\d{4}[-/]\d{1,2}[-/]\d{1,2}$/.test(s)) {
            const p = s.split(/[-/]/);
            renDate = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        } else if (/^\d{1,2}[-/]\d{1,2}[-/]\d{4}$/.test(s)) {
            const p = s.split(/[-/]/);
            renDate = new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
        } else {
            const parsed = new Date(s);
            if (!isNaN(parsed.getTime())) renDate = parsed;
        }

        if (renDate && !isNaN(renDate.getTime())) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            renDate.setHours(0, 0, 0, 0);

            const diffTime = renDate.getTime() - today.getTime();
            const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

            if (diffDays < 0) {
                // Overdue
                const overdueDays = Math.abs(diffDays);
                if (overdueDays > 7) {
                    // Severe Overdue (> 7 days): Red Highlight
                    if (renCard) {
                        renCard.style.background = 'rgba(239, 68, 68, 0.08)';
                        renCard.style.borderColor = 'rgba(239, 68, 68, 0.5)';
                    }
                    if (renElem) renElem.style.color = '#ef4444';
                    if (renBadge) {
                        renBadge.style.display = 'inline-block';
                        renBadge.style.background = '#ef4444';
                        renBadge.style.color = '#ffffff';
                        renBadge.innerText = overdueDays + ' Days Overdue';
                    }
                    if (renSub) {
                        renSub.style.display = 'block';
                        renSub.style.color = '#ef4444';
                        renSub.innerText = '⚠️ Overdue by ' + overdueDays + ' days';
                    }
                } else {
                    // Moderate Overdue (1-7 days): Amber/Orange Alert
                    if (renCard) {
                        renCard.style.background = 'rgba(249, 115, 22, 0.08)';
                        renCard.style.borderColor = 'rgba(249, 115, 22, 0.45)';
                    }
                    if (renElem) renElem.style.color = '#f97316';
                    if (renBadge) {
                        renBadge.style.display = 'inline-block';
                        renBadge.style.background = '#f97316';
                        renBadge.style.color = '#ffffff';
                        renBadge.innerText = overdueDays + (overdueDays === 1 ? ' Day Overdue' : ' Days Overdue');
                    }
                    if (renSub) {
                        renSub.style.display = 'block';
                        renSub.style.color = '#f97316';
                        renSub.innerText = '⚠️ Overdue by ' + overdueDays + ' days';
                    }
                }
            } else if (diffDays === 0) {
                // Expires Today
                if (renCard) {
                    renCard.style.background = 'rgba(234, 179, 8, 0.12)';
                    renCard.style.borderColor = 'rgba(234, 179, 8, 0.55)';
                }
                if (renElem) renElem.style.color = '#ca8a04';
                if (renBadge) {
                    renBadge.style.display = 'inline-block';
                    renBadge.style.background = '#eab308';
                    renBadge.style.color = '#000000';
                    renBadge.innerText = 'Expires Today';
                }
                if (renSub) {
                    renSub.style.display = 'block';
                    renSub.style.color = '#ca8a04';
                    renSub.innerText = '⚡ Renewal is due today';
                }
            } else if (diffDays <= 7) {
                // 1-7 days left
                if (renCard) {
                    renCard.style.background = 'rgba(245, 158, 11, 0.08)';
                    renCard.style.borderColor = 'rgba(245, 158, 11, 0.4)';
                }
                if (renElem) renElem.style.color = '#d97706';
                if (renBadge) {
                    renBadge.style.display = 'inline-block';
                    renBadge.style.background = 'rgba(245, 158, 11, 0.18)';
                    renBadge.style.color = '#d97706';
                    renBadge.innerText = diffDays + (diffDays === 1 ? ' day left' : ' days left');
                }
                if (renSub) {
                    renSub.style.display = 'block';
                    renSub.style.color = '#d97706';
                    renSub.innerText = '⏳ ' + diffDays + (diffDays === 1 ? ' day' : ' days') + ' left';
                }
            } else if (diffDays <= 30) {
                // 8-30 days left
                if (renCard) {
                    renCard.style.background = 'rgba(59, 130, 246, 0.06)';
                    renCard.style.borderColor = 'rgba(59, 130, 246, 0.3)';
                }
                if (renElem) renElem.style.color = '#2563eb';
                if (renBadge) {
                    renBadge.style.display = 'inline-block';
                    renBadge.style.background = 'rgba(59, 130, 246, 0.12)';
                    renBadge.style.color = '#2563eb';
                    renBadge.innerText = diffDays + ' days left';
                }
                if (renSub) {
                    renSub.style.display = 'block';
                    renSub.style.color = '#2563eb';
                    renSub.innerText = '📅 ' + diffDays + ' days left';
                }
            } else {
                // Active (> 30 days)
                if (renCard) {
                    renCard.style.background = 'var(--bg-card)';
                    renCard.style.borderColor = 'var(--border-color)';
                }
                if (renElem) renElem.style.color = 'var(--text-main)';
                if (renBadge) {
                    renBadge.style.display = 'inline-block';
                    renBadge.style.background = 'rgba(16, 185, 129, 0.12)';
                    renBadge.style.color = '#10b981';
                    renBadge.innerText = 'Active';
                }
                if (renSub) renSub.style.display = 'none';
            }
        } else {
            if (renCard) { renCard.style.background = 'var(--bg-card)'; renCard.style.borderColor = 'var(--border-color)'; }
            if (renElem) renElem.style.color = 'var(--text-main)';
            if (renBadge) renBadge.style.display = 'none';
            if (renSub) renSub.style.display = 'none';
        }
    } else {
        if (renCard) { renCard.style.background = 'var(--bg-card)'; renCard.style.borderColor = 'var(--border-color)'; }
        if (renElem) renElem.style.color = 'var(--text-main)';
        if (renBadge) renBadge.style.display = 'none';
        if (renSub) renSub.style.display = 'none';
    }
    const vActOn = document.getElementById('edit-v-acton'); if (vActOn) vActOn.innerText = actOn;
    const vLastHit = document.getElementById('edit-v-lasthit'); if (vLastHit) vLastHit.innerText = lastHit;
    const vSubPartner = document.getElementById('edit-v-subpartner'); if (vSubPartner) vSubPartner.innerText = subPartner;
    const vAddr = document.getElementById('edit-v-fulladdress'); if (vAddr) vAddr.innerText = fullAddr;
}

function openEditTicketModal(ticket) {
    document.getElementById('edit-ticket-id-hidden').value = ticket.id;
    document.getElementById('edit-ticket-id-display').innerText = ticket.id;
    document.getElementById('edit-ticket-client-id').value = ticket.lead_id || "";

    // Set callback input right away before view update
    const cbInput = document.getElementById('edit-ticket-callback');
    if (cbInput) {
        let cleanCb = (ticket.callback_number || "").trim();
        const digits = cleanCb.replace(/[^0-9]/g, '');
        if (digits.length === 12 && digits.startsWith('91')) {
            cleanCb = digits.substring(2);
        }
        cbInput.value = cleanCb;
    }

    // Set initial compact view from ticket fields
    updateClientCompactView(ticket);

    // Auto-fetch fresh Client Directory data if lead_id exists
    if (ticket.lead_id) {
        autoFetchClientDetails();
    }
    
    // Editable Ticket Parameters
    document.getElementById('edit-ticket-priority').value = ticket.priority || "medium";
    
    // Populate Subject, Problem Summary & Employee Resolution/Solution
    const subjElem = document.getElementById('edit-ticket-subject');
    let curSubj = ticket.subject || "";
    if (curSubj.startsWith('Support Lead: Client')) {
        curSubj = "";
    }
    if (subjElem) subjElem.value = curSubj;
    
    const probElem = document.getElementById('edit-ticket-problem');
    let curProb = ticket.problem || "";
    if (curProb.startsWith('Client lead forwarded by')) {
        curProb = "";
    }
    if (probElem) probElem.value = curProb;

    const resElem = document.getElementById('edit-ticket-resolution');
    if (resElem) resElem.value = ticket.resolution || "";
    
    const statusSelect = document.getElementById('edit-ticket-status');
    if (statusSelect) {
        statusSelect.value = ticket.status;
    } else {
        const hiddenStatus = document.getElementById('edit-ticket-status-hidden');
        if (hiddenStatus) hiddenStatus.value = ticket.status;
    }
    
    // Populate Technician Assignment
    const assignVal = ticket.assigned_to || "Unassigned";
    const assignedSelect = document.getElementById('edit-ticket-assigned');
    const currentUserName = <?php echo json_encode($user_name); ?>;
    const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
    const canAssignPerm = <?php echo $canAssign ? 'true' : 'false'; ?>;
    const isAssignedToMe = (assignVal.toLowerCase() === currentUserName.toLowerCase());

    if (assignedSelect) {
        let hasOption = Array.from(assignedSelect.options).some(opt => opt.value.toLowerCase() === assignVal.toLowerCase());
        if (!hasOption && assignVal) {
            const newOpt = document.createElement('option');
            newOpt.value = assignVal;
            newOpt.textContent = assignVal;
            assignedSelect.appendChild(newOpt);
        }
        assignedSelect.value = assignVal;

        // Allow transfer if Admin, has canAssign permission, or ticket is assigned to current user
        if (isAdmin || canAssignPerm || isAssignedToMe) {
            assignedSelect.removeAttribute('disabled');
        } else {
            assignedSelect.setAttribute('disabled', 'disabled');
        }
    }
    
    document.getElementById('edit-ticket-due-date').value = ticket.due_date || "";
    if (cbInput) {
        cbInput.value = ticket.callback_number || "";
    }
    
    // Load ticket activity & assignment history
    loadTicketHistory(ticket.id);

    window.openModal('edit-ticket-modal');
}

function takeTicket(ticketId) {
    if (!confirm('Are you sure you want to take ticket #' + ticketId + '? It will be assigned to you.')) {
        return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?page=support';
    
    const actInput = document.createElement('input');
    actInput.type = 'hidden';
    actInput.name = 'action';
    actInput.value = 'take_ticket';
    form.appendChild(actInput);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'ticket_id';
    idInput.value = ticketId;
    form.appendChild(idInput);

    document.body.appendChild(form);
    form.submit();
}

function loadTicketHistory(ticketId) {
    const container = document.getElementById('edit-ticket-history-container');
    const countElem = document.getElementById('edit-history-count');
    if (!container) return;
    container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Loading ticket history...</p>';

    fetch('index.php?page=support&action=get_ticket_history&ticket_id=' + encodeURIComponent(ticketId))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success' && Array.isArray(data.history) && data.history.length > 0) {
                if (countElem) countElem.innerText = data.history.length + (data.history.length === 1 ? ' log' : ' logs');
                let html = '';
                data.history.forEach(item => {
                    let badgeBg = 'rgba(59, 130, 246, 0.12)';
                    let badgeColor = '#2563eb';
                    if (item.action === 'taken') {
                        badgeBg = 'rgba(16, 185, 129, 0.15)';
                        badgeColor = '#059669';
                    } else if (item.action === 'transferred') {
                        badgeBg = 'rgba(245, 158, 11, 0.15)';
                        badgeColor = '#d97706';
                    } else if (item.action === 'status_change') {
                        badgeBg = 'rgba(139, 92, 246, 0.15)';
                        badgeColor = '#7c3aed';
                    } else if (item.action === 'resolution') {
                        badgeBg = 'rgba(16, 185, 129, 0.2)';
                        badgeColor = '#10b981';
                    }
                    const dt = new Date(item.created_at);
                    const timeStr = isNaN(dt.getTime()) ? item.created_at : dt.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });

                    const actorEsc = String(item.actor_name || 'System').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const roleEsc = item.actor_role ? String(item.actor_role).replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
                    const detailsEsc = String(item.details || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                    html += `
                        <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.45rem 0.65rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;">
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="badge text-xs" style="--badge-bg: ${badgeBg}; --badge-color: ${badgeColor}; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;">${item.action}</span>
                                    <strong style="font-size: 0.75rem; color: var(--text-main);">${actorEsc}</strong>
                                    ${roleEsc ? `<span class="text-muted" style="font-size: 0.68rem;">(${roleEsc})</span>` : ''}
                                </div>
                                <span style="font-size: 0.74rem; color: var(--text-main); line-height: 1.3;">${detailsEsc}</span>
                            </div>
                            <span class="font-mono text-muted" style="font-size: 0.65rem; white-space: nowrap; flex-shrink: 0;">${timeStr}</span>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                if (countElem) countElem.innerText = '0 logs';
                container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">No specific lifecycle history logged yet for this ticket.</p>';
            }
        })
        .catch(() => {
            container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Could not load history.</p>';
        });
}

function autoFetchClientDetails() {
    const licInput = document.getElementById('edit-ticket-client-id');
    if (!licInput || !licInput.value.trim()) return;

    const query = licInput.value.trim().toLowerCase();

    // 1. Search local masterClientsData first
    if (typeof masterClientsData !== 'undefined' && masterClientsData && masterClientsData.length > 0) {
        let match = masterClientsData.find(c => {
            const cid = String(c.customer_id || '').toLowerCase();
            const mob = String(c.mobile || '').toLowerCase();
            const pName = String(c.party_name || '').toLowerCase();
            return cid === query || mob === query || pName.includes(query);
        });

        if (match) {
            updateClientCompactView(match);
            return;
        }
    }

    // 2. Fallback to API lookup
    fetch('api/lookup-client.php?query=' + encodeURIComponent(licInput.value.trim()))
        .then(res => res.json())
        .then(res => {
            if (res.success && res.found && res.data) {
                updateClientCompactView(res.data);
            }
        })
        .catch(err => console.error('Client lookup error:', err));
}

document.addEventListener('DOMContentLoaded', () => {
    // Add real-time sync between edit-ticket-callback input and the client directory Call Back No. card
    const editCbInput = document.getElementById('edit-ticket-callback');
    if (editCbInput) {
        editCbInput.addEventListener('input', function() {
            const val = this.value.trim();
            const vCb = document.getElementById('edit-v-callback');
            if (vCb) vCb.innerText = val || '-';
            const vCbQrBtn = document.getElementById('edit-v-callback-qr-btn');
            if (vCbQrBtn) {
                const compName = document.getElementById('edit-v-company')?.innerText || 'Client';
                if (val) {
                    vCbQrBtn.onclick = function(e) {
                        if (e) e.stopPropagation();
                        window.openCallQrModal(compName, val, '');
                    };
                    vCbQrBtn.style.display = 'inline-flex';
                } else {
                    vCbQrBtn.style.display = 'none';
                }
            }
        });
    }

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
