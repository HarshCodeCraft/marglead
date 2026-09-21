<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'System User';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

// Check view access
if (!hasAccess('support', $user_role)) {
    echo "<div class='alert alert-danger' style='margin: 20px; padding: 15px; border-radius: 8px; font-weight: bold;'>Access Denied: You do not have permissions to view Support Tickets.</div>";
    return;
}

$canCreate = hasAccess('support_create', $user_role);
$canEdit = hasAccess('support_edit', $user_role);
$canAssign = hasAccess('support_assign', $user_role);
$canClose = hasAccess('support_close', $user_role);

// Auto-sync incoming WhatsApp Flow tickets from `tickets` table into `support_tickets`
function syncWhatsAppFlowTickets($pdo) {
    if (!$pdo) return;
    try {
        $stmtSync = $pdo->query("SELECT * FROM tickets ORDER BY id DESC LIMIT 50");
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

                    try {
                        $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'created', ?, 'Customer', ?, ?)");
                        $stmtH->execute([
                            $tId,
                            $cName . (!empty($phone) ? " ({$phone})" : ""),
                            "Ticket created via WhatsApp Flow by Customer. Category: " . ($rt['category'] ?? 'General Support') . (!empty($prob) ? ". Problem: {$prob}" : ""),
                            $dateCreated
                        ]);
                    } catch (Throwable $eH) {}
                }
            }
        }
    } catch (Throwable $eSync) {}
}

function getFilteredSupportTickets($pdo, $is_admin, $user_name, $filters = []) {
    if (!$pdo) return [];
    $operator_filter = trim($filters['operator'] ?? '');
    $search_query    = trim($filters['search'] ?? '');
    $status_filter    = trim($filters['status'] ?? '');
    $priority_filter  = trim($filters['priority'] ?? '');
    $product_filter   = trim($filters['product'] ?? '');

    $where_conditions = [];
    $query_params     = [];

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

    try {
        $stmt = $pdo->prepare("SELECT * FROM support_tickets {$where_sql} ORDER BY date_created DESC");
        $stmt->execute($query_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function getSupportTicketCounters($pdo, $is_admin, $user_name) {
    $counts = [
        'open' => 0,
        'in_progress' => 0,
        'critical' => 0,
        'resolved' => 0,
    ];
    if (!$pdo) return $counts;
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
                $counts['resolved']++;
            } elseif ($cStat === 'in_progress' || $cStat === 'pending') {
                $counts['in_progress']++;
            } else {
                $counts['open']++;
            }
            if (strtolower($ct['priority'] ?? '') === 'critical' && $cStat !== 'resolved' && $cStat !== 'closed') {
                $counts['critical']++;
            }
        }
    } catch (Throwable $e) {}
    return $counts;
}

function renderSupportTicketRows($tickets, $user_name, $is_admin, $canEdit) {
    ob_start();
    if (empty($tickets)): ?>
        <tr>
            <td colspan="10" class="text-center text-muted py-8">
                <i data-lucide="inbox" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; color: var(--text-muted);"></i>
                <p class="text-sm font-semibold mb-1">No support tickets found matching your query.</p>
            </td>
        </tr>
    <?php else:
        foreach ($tickets as $t):
            $tJson = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
            $cNameDisplay = trim($t['customer_name'] ?? '');
            $phoneNum = trim(!empty($t['callback_number']) ? $t['callback_number'] : ($t['phone'] ?? ''));
            $cleanPhone = preg_replace('/[^0-9+]/', '', $phoneNum);
            $displayPhone = preg_replace('/^\+?91/', '', $cleanPhone);
            if (strlen($displayPhone) !== 10) {
                $displayPhone = $cleanPhone;
            }
            $telPayload = 'tel:' . $cleanPhone;
            $cNameEsc = htmlspecialchars(addslashes(!empty($cNameDisplay) ? $cNameDisplay : 'Client'), ENT_QUOTES, 'UTF-8');

            $dispProb = trim($t['problem'] ?? '');
            $dispSubj = trim($t['subject'] ?? '');
            $primaryText = !empty($dispSubj) ? $dispSubj : (!empty($dispProb) ? $dispProb : 'Technical Support');
            $secondaryText = !empty($t['resolution']) ? ('Solution: ' . $t['resolution']) : ((!empty($dispProb) && $dispProb !== $dispSubj) ? $dispProb : '');

            $p = strtolower($t['priority'] ?? 'medium');
            $s = strtolower($t['status'] ?? 'open');

            $tAssigned = strtolower(trim($t['assigned_to'] ?? ''));
            $currUser = strtolower(trim($user_name ?? ''));
            $isAssignedToMe = !empty($tAssigned) && $tAssigned !== 'unassigned' && ($tAssigned === $currUser);
            $isUnassigned = empty($tAssigned) || $tAssigned === 'unassigned';
            $canUserEditThisTicket = $is_admin || $isAssignedToMe || ($canEdit && $isUnassigned);
        ?>
        <tr data-ticket-id="<?php echo htmlspecialchars($t['id']); ?>">
            <td style="padding: 0.85rem 1rem;">
                <span class="font-bold text-primary font-mono text-xs block"><?php echo htmlspecialchars($t['id']); ?></span>
                <span class="text-xs text-muted font-mono block mt-1" style="font-size: 0.7rem;" title="Ticket Creation Date">
                    <i data-lucide="calendar" style="width: 10px; height: 10px; display: inline-block; vertical-align: middle; margin-right: 2px;"></i>
                    <?php echo !empty($t['date_created']) ? date('d M Y, h:i A', strtotime($t['date_created'])) : date('d M Y'); ?>
                </span>
            </td>
            <td>
                <strong class="text-main block text-sm"><?php echo htmlspecialchars(!empty($cNameDisplay) ? $cNameDisplay : '-'); ?></strong>
                <span class="text-xs text-muted font-mono">ID: <?php echo htmlspecialchars(!empty($t['lead_id']) ? $t['lead_id'] : 'NA'); ?></span>
            </td>
            <td>
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
                    if ($s === 'resolved') echo '<span class="badge badge-success text-xs">Resolved</span>';
                    elseif ($s === 'in_progress') echo '<span class="badge text-xs" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In Progress</span>';
                    else echo '<span class="badge text-xs text-primary">Open</span>';
                ?>
            </td>
            <td class="font-mono text-xs text-muted"><?php echo htmlspecialchars($t['due_date'] ?? '-'); ?></td>
            <td style="text-align: right; padding-right: 1.25rem;">
                <div class="flex align-center justify-end gap-1.5">
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
    <?php
        endforeach;
    endif;
    return ob_get_clean();
}

// Ajax handler for zero-refresh live tickets auto-sync
if (isset($_GET['action']) && $_GET['action'] === 'fetch_live_tickets') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    if ($db_connected && $pdo) {
        syncWhatsAppFlowTickets($pdo);
        $liveTickets = getFilteredSupportTickets($pdo, $is_admin, $user_name, $_GET);
        $liveCounters = getSupportTicketCounters($pdo, $is_admin, $user_name);
        $tbodyHtml = renderSupportTicketRows($liveTickets, $user_name, $is_admin, $canEdit);
        $latestId = !empty($liveTickets[0]['id']) ? $liveTickets[0]['id'] : '';

        echo json_encode([
            'status'           => 'success',
            'total'            => count($liveTickets),
            'counts'           => $liveCounters,
            'tbody_html'       => $tbodyHtml,
            'latest_ticket_id' => $latestId,
            'timestamp'        => time()
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database offline']);
    }
    exit;
}

// Ajax handler to fetch ticket history
if (isset($_GET['action']) && $_GET['action'] === 'get_ticket_history') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $tId = trim($_GET['ticket_id'] ?? '');
    try {
        $stmtH = $pdo->prepare("SELECT * FROM support_ticket_history WHERE ticket_id = ? ORDER BY created_at ASC, id ASC");
        $stmtH->execute([$tId]);
        $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'history' => $history]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'history' => []]);
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
                        $takeMsg = "*Support Ticket Accepted*\n\n" .
                                   "*Ticket ID:* #{$ticketId}\n" .
                                   "*Client:* {$clientInfo}\n" .
                                   "*Status:* In Progress\n" .
                                   "*Assigned Engineer:* {$currentUserName}\n" .
                                   "*Time:* {$nowStr}\n\n" .
                                   "*{$currentUserName}* has accepted this ticket and initiated technical support.";
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
            $ticketId = generate_ticket_number($pdo);
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

            // Also sync into tickets table
            try {
                $stmtTktSync = $pdo->prepare("
                    INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'whatsapp@marglead.com', 'Technical Support', 'High', ?, 'Open', NOW())
                ");
                $stmtTktSync->execute([
                    $ticketId,
                    $license_no,
                    $customer_name,
                    $customer_name,
                    $callback_number,
                    $problem
                ]);
            } catch (Throwable $eTSync) {}

            // Log creation in support_ticket_history
            try {
                $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'created', ?, 'Staff', ?, NOW())");
                $stmtH->execute([
                    $ticketId,
                    $_SESSION['user_name'] ?? 'System User',
                    "WhatsApp Ticket created for {$customer_name} ({$license_no}). Assigned To: {$assigned_to}. Problem: {$problem}"
                ]);
            } catch (Throwable $eH) {}

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
        if (empty($customer_name) && !empty($_POST['client_search_input'])) {
            $customer_name = trim($_POST['client_search_input']);
        }
        if (empty($customer_name) && !empty($_POST['phone'])) {
            $customer_name = 'Client (' . preg_replace('/[^\d]/', '', $_POST['phone']) . ')';
        }

        $lead_id = trim($_POST['lead_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $product = trim($_POST['product'] ?? 'Marg ERP');
        $renewal_date = empty($_POST['renewal_date']) ? null : $_POST['renewal_date'];
        $address = trim($_POST['address'] ?? '');
        
        $priority = trim($_POST['priority'] ?? 'high');
        $status = trim($_POST['status'] ?? 'open');
        $subject = trim($_POST['subject'] ?? '');
        $problem = trim($_POST['problem'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? 'Unassigned');
        $due_date = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $callback_number = trim($_POST['callback_number'] ?? '');
        
        if (empty($subject) && !empty($problem)) {
            $subject = mb_strimwidth($problem, 0, 70, '...');
        }

        if ($db_connected && $pdo) {
            try {
                $ticketId = generate_ticket_number($pdo);

                $stmt = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, renewal_date, address, problem, due_date, callback_number, date_created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$ticketId, $customer_name, $subject, $priority, $status, $assigned_to, $lead_id, $phone, $email, $product, $renewal_date, $address, $problem, $due_date, $callback_number]);
                
                // Also sync into tickets table
                try {
                    $stmtTktSync = $pdo->prepare("
                        INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmtTktSync->execute([
                        $ticketId,
                        $lead_id,
                        $customer_name,
                        $customer_name,
                        $phone,
                        $email,
                        $subject,
                        ucfirst($priority),
                        $problem,
                        ucfirst($status)
                    ]);
                } catch (Throwable $eTSync) {}
                
                // Log creation in support_ticket_history
                try {
                    $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'created', ?, ?, ?, NOW())");
                    $stmtH->execute([
                        $ticketId,
                        $_SESSION['user_name'] ?? 'Staff',
                        $_SESSION['user_role'] ?? 'Staff',
                        "Ticket created manually in CRM. Priority: " . ucfirst($priority) . ", Assigned To: {$assigned_to}" . (!empty($subject) ? ". Subject: {$subject}" : "")
                    ]);
                } catch (Throwable $eH) {}

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
        $subject = trim($_POST['subject'] ?? '');
        $problem = trim($_POST['problem'] ?? '');
        $resolution = trim($_POST['resolution'] ?? '');
        $assigned_to = trim($_POST['assigned_to'] ?? '');
        $due_date = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $callback_number = trim($_POST['callback_number'] ?? '');
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
                $origStmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
                $origStmt->execute([$ticketId]);
                $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($orig) {
                    // Preserve existing values if POST parameter was blank
                    if (empty($customer_name) && !empty($orig['customer_name'])) {
                        $customer_name = $orig['customer_name'];
                    }
                    if (empty($lead_id) && !empty($orig['lead_id'])) {
                        $lead_id = $orig['lead_id'];
                    }
                    if (empty($phone) && !empty($orig['phone'])) {
                        $phone = $orig['phone'];
                    }
                    if (empty($email) && !empty($orig['email'])) {
                        $email = $orig['email'];
                    }
                    if (empty($product) && !empty($orig['product'])) {
                        $product = $orig['product'];
                    }
                    if (empty($renewal_date) && !empty($orig['renewal_date'])) {
                        $renewal_date = $orig['renewal_date'];
                    }
                    if (empty($address) && !empty($orig['address'])) {
                        $address = $orig['address'];
                    }
                    if (empty($callback_number) && !empty($orig['callback_number'])) {
                        $callback_number = $orig['callback_number'];
                    }
                    if (empty($subject) && !empty($orig['subject'])) {
                        $subject = $orig['subject'];
                    }
                    if (empty($problem) && !empty($orig['problem'])) {
                        $problem = $orig['problem'];
                    }

                    if (empty($assigned_to) && !empty($orig['assigned_to'])) {
                        $assigned_to = $orig['assigned_to'];
                    }

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
                    
                    // Preserve previous resolution if no new remark was entered
                    $finalResolution = !empty($resolution) ? $resolution : ($orig['resolution'] ?? '');

                    $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ?, status = ?, subject = ?, problem = ?, resolution = ?, assigned_to = ?, due_date = ?, callback_number = ?, lead_id = ?, customer_name = ?, phone = ?, email = ?, product = ?, renewal_date = ?, address = ? WHERE id = ?");
                    $stmt->execute([$priority, $status, $subject, $problem, $finalResolution, $assigned_to, $due_date, $callback_number, $lead_id, $customer_name, $phone, $email, $product, $renewal_date, $address, $ticketId]);

                    // Auto-close chat in Team Inbox silently (NO chat message shown in conversation) when ticket is resolved/closed
                    if (in_array(strtolower($status), ['resolved', 'closed'])) {
                        $custPhone = !empty($phone) ? $phone : (!empty($orig['phone']) ? $orig['phone'] : (!empty($callback_number) ? $callback_number : ''));
                        if (!empty($custPhone)) {
                            $cleanPhone = preg_replace('/[^0-9]/', '', $custPhone);
                            $last10 = substr($cleanPhone, -10);
                            if (!empty($cleanPhone)) {
                                try {
                                    $stmtCloseChat = $pdo->prepare("UPDATE chat_conversations SET status = 'closed' WHERE phone = ? OR phone LIKE ? OR phone LIKE ?");
                                    $stmtCloseChat->execute([$custPhone, "%$cleanPhone%", "%$last10%"]);
                                } catch (Throwable $eCC) {}
                            }
                        }
                    }

                    $actorName = !empty($user_name) ? $user_name : (!empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Support Team');
                    $actorRole = !empty($user_role) ? $user_role : (!empty($_SESSION['user_role']) ? $_SESSION['user_role'] : 'Technical Support');

                    // 1. Log transfer / re-assignment in support_ticket_history
                    $assigneeChanged = ($orig['assigned_to'] !== $assigned_to);
                    if ($assigneeChanged) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'transferred', ?, ?, ?, NOW())");
                            $stmtH->execute([
                                $ticketId,
                                $actorName,
                                $actorRole,
                                "Ticket transferred / assigned from '{$orig['assigned_to']}' to '{$assigned_to}'"
                            ]);
                        } catch (Throwable $eH) {
                            write_log('error', "Failed logging ticket transfer history: " . $eH->getMessage());
                        }
                    }

                    // 2. Log work remark / solution note if entered by employee
                    if (!empty($resolution)) {
                        $isClosedState = in_array(strtolower($status), ['resolved', 'closed']);
                        $actType = $isClosedState ? 'resolution' : 'work_note';
                        $noteLabel = $isClosedState ? 'Solution / Resolution' : 'Work Remark / Update';
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $stmtH->execute([
                                $ticketId,
                                $actType,
                                $actorName,
                                $actorRole,
                                "{$noteLabel}: {$resolution}"
                            ]);
                        } catch (Throwable $eH) {
                            write_log('error', "Failed logging ticket work remark history: " . $eH->getMessage());
                        }
                    }

                    // 3. Log status change / reopen / resolution in support_ticket_history
                    $statusChanged = ($orig['status'] !== $status);
                    if ($statusChanged) {
                        $origStatLower = strtolower($orig['status']);
                        $newStatLower = strtolower($status);
                        $isReopen = in_array($origStatLower, ['resolved', 'closed']) && in_array($newStatLower, ['open', 'in_progress', 'pending']);
                        $isClose = in_array($newStatLower, ['resolved', 'closed']);
                        
                        $actType = $isReopen ? 'reopened' : ($isClose ? 'resolved' : 'status_change');
                        
                        if ($isReopen) {
                            $statusNote = "Ticket REOPENED from '" . ucfirst($orig['status']) . "' to '" . ucfirst($status) . "'";
                        } elseif ($isClose) {
                            $statusNote = "Ticket marked as " . ucfirst($status);
                        } else {
                            $statusNote = "Status updated from '" . ucfirst($orig['status']) . "' to '" . ucfirst($status) . "'";
                        }
                        
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $stmtH->execute([
                                $ticketId,
                                $actType,
                                $actorName,
                                $actorRole,
                                $statusNote
                            ]);
                        } catch (Throwable $eH) {
                            write_log('error', "Failed logging ticket status history: " . $eH->getMessage());
                        }
                    }

                    // 4. Log priority change
                    if (strtolower($orig['priority'] ?? '') !== strtolower($priority)) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'priority_change', ?, ?, ?, NOW())");
                            $stmtH->execute([
                                $ticketId,
                                $actorName,
                                $actorRole,
                                "Priority changed from '" . ucfirst($orig['priority']) . "' to '" . ucfirst($priority) . "'"
                            ]);
                        } catch (Throwable $eH) {
                            write_log('error', "Failed logging ticket priority history: " . $eH->getMessage());
                        }
                    }

                    // 5. Log due date update
                    if (!empty($due_date) && ($orig['due_date'] ?? '') !== $due_date) {
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'due_date_update', ?, ?, ?, NOW())");
                            $stmtH->execute([
                                $ticketId,
                                $actorName,
                                $actorRole,
                                "Target due date updated to " . date('d M Y', strtotime($due_date))
                            ]);
                        } catch (Throwable $eH) {
                            write_log('error', "Failed logging ticket due date history: " . $eH->getMessage());
                        }
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
                                    $resNote = !empty($resolution) ? $resolution : "Issue resolved successfully.";
                                    $empClosureMsg = "✅ *Support Ticket Resolved*\n\n" .
                                                     "*Ticket ID:* #{$ticketId}\n" .
                                                     "*Client:* {$clientInfo}\n" .
                                                     "*Status:* Resolved & Closed\n" .
                                                     "*Resolved By:* {$techAgentName}\n" .
                                                     "*Solution:* {$resNote}\n" .
                                                     "*Closed At:* {$nowStr}\n\n" .
                                                     "The service request has been successfully closed.";
                                    $whatsappObj->sendText($empDropPhone, $empClosureMsg);
                                }
                            } else {
                                // Status changed (e.g. Call Back, In Progress, Follow-up) OR new remarks/resolution updated
                                $statusChanged = ($orig['status'] !== $status);
                                $remarksChanged = (!empty($resolution) && ($orig['resolution'] ?? '') !== $resolution);
                                
                                if ($statusChanged || $remarksChanged) {
                                    $statusLabel = ucwords(str_replace('_', ' ', $status));
                                    $resNote = !empty($resolution) ? $resolution : (!empty($problem) ? $problem : "Status updated by technician.");
                                    $empUpdateMsg = "📌 *Support Ticket Update*\n\n" .
                                                    "*Ticket ID:* #{$ticketId}\n" .
                                                    "*Client:* {$clientInfo}\n" .
                                                    "*Current Status:* {$statusLabel}\n" .
                                                    "*Handled By:* {$techAgentName}\n" .
                                                    "*Remarks:* {$resNote}\n" .
                                                    "*Time:* {$nowStr}";
                                    $whatsappObj->sendText($empDropPhone, $empUpdateMsg);
                                }
                            }
                        } catch (Throwable $eWaEmp) {
                            write_log('error', "Failed sending team agent WhatsApp update: " . $eWaEmp->getMessage());
                        }
                    }

                    // =========================================================
                    // 2. Customer Notification (Sent on Ticket Resolution / Closure)
                    // =========================================================
                    if ($orig['status'] !== $status && ($status === 'resolved' || $status === 'closed')) {
                        try {
                            $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'Ticket Resolved/Closed', ?, 'success')");
                            $adminNotifMsg = "Ticket " . $ticketId . " has been marked as Resolved by " . $techAgentName;
                            $adminNotifStmt->execute([$adminNotifMsg]);
                        } catch (Throwable $eAdm) {}

                        // Send WhatsApp notification to customer
                        try {
                            if (!isset($whatsappObj)) {
                                require_once __DIR__ . '/../api/whatsapp-api.php';
                                $whatsappObj = new WhatsAppAPI($pdo);
                            }
                            $custPhone = !empty($callback_number) ? $callback_number : (!empty($orig['callback_number']) ? $orig['callback_number'] : (!empty($orig['phone']) ? $orig['phone'] : ($phone ?? null)));
                            
                            if (!empty($custPhone)) {
                                $clientNameVal = !empty($customer_name) ? $customer_name : (!empty($orig['customer_name']) ? $orig['customer_name'] : 'Valued Client');
                                if ($clientNameVal === 'Client' || str_starts_with($clientNameVal, 'Client (')) {
                                    $clientNameVal = 'Valued Client';
                                }

                                $finalSolution = !empty($resolution) ? $resolution : (!empty($orig['resolution']) ? $orig['resolution'] : "Problem successfully resolved by support engineer.");

                                $resMsg = "*Support Ticket Resolved*\n\n" .
                                          "Dear *{$clientNameVal}*,\n" .
                                          "Your technical support ticket *#{$ticketId}* has been successfully resolved.\n\n" .
                                          " *Ticket Summary:*\n" .
                                          "• *Ticket ID:* #{$ticketId}\n" .
                                          "• *Engineer:* {$techAgentName} (Technical Support)\n" .
                                          "• *Resolution / Solution:* {$finalSolution}\n" .
                                          "• *Closed At:* {$nowStr}\n\n" .
                                          "──────────────────────────\n" .
                                          "*महत्वपूर्ण सुझाव (Priority Support Tip):*\n" .
                                          "भविष्य में अपनी समस्या के सबसे तेज़ और प्राथमिकता समाधान के लिए, कृपया इसी WhatsApp नंबर पर *\"Hi\"* या *\"Support\"* लिखकर अपनी टिकट दर्ज करें। हमारी टेक्निकल टीम तुरंत आपसे कनेक्ट होकर समस्या हल करेगी।\n\n" .
                                          "Thank you for choosing *Marg Soft Solution*!";

                                $whatsappObj->sendText($custPhone, $resMsg);
                            }
                        } catch (Throwable $eWa) {
                            write_log('error', "Failed sending customer resolution WhatsApp message: " . $eWa->getMessage());
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
        $stmt = $pdo->query("SELECT name, role FROM users WHERE status = 'Active' AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') ORDER BY name ASC");
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
    syncWhatsAppFlowTickets($pdo);
    $tickets = getFilteredSupportTickets($pdo, $is_admin, $user_name, [
        'status'   => $status_filter,
        'priority' => $priority_filter,
        'product'  => $product_filter,
        'operator' => $operator_filter,
        'search'   => $search_query
    ]);
}

// Clean up old demo tickets from database if present
if ($db_connected && $pdo) {
    try {
        $pdo->exec("DELETE FROM support_tickets WHERE id IN ('TCK-8902', 'TCK-8789')");
        $pdo->exec("DELETE FROM tickets WHERE ticket_number IN ('TCK-8902', 'TCK-8789')");
    } catch (Throwable $eClean) {}
}

// Calculate counters directly from database for user scope
$ticketCounters = getSupportTicketCounters($pdo, $is_admin, $user_name);
$criticalCount   = $ticketCounters['critical'];
$openCount       = $ticketCounters['open'];
$inProgressCount = $ticketCounters['in_progress'];
$resolvedCount   = $ticketCounters['resolved'];
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

        <div class="flex gap-2 flex-wrap align-center">
            <div class="support-live-badge flex align-center gap-1.5" id="support-live-pill" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 0.72rem; font-weight: 700; padding: 6px 12px; border-radius: 20px; border: 1px solid rgba(16, 185, 129, 0.25); display: inline-flex; align-items: center; gap: 6px;">
                <span class="support-pulse-dot" style="width: 7px; height: 7px; background: #10b981; border-radius: 50%; display: inline-block;"></span>
                <span id="support-live-text">LIVE AUTO-SYNC: ON</span>
            </div>
            <button type="button" class="btn btn-secondary text-sm flex align-center gap-1.5" id="btn-manual-refresh-tickets" onclick="triggerManualTicketsRefresh(this)" title="Fetch latest tickets now">
                <i data-lucide="rotate-cw" id="tickets-refresh-icon" style="width: 14px; height: 14px;"></i>
                <span>Refresh</span>
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

    <!-- KPI Summary Row (4 Columns Side-by-Side) -->
    <div class="mb-6" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
        <a href="index.php?page=support&status=open" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'open') ? 'var(--primary)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'open') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="ticket" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Open Queue</span>
                <span id="kpi-open-count" class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo number_format($openCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&status=in_progress" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'in_progress' || $status_filter === 'pending') ? 'var(--warning)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'in_progress' || $status_filter === 'pending') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="clock" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">In Progress / Pending</span>
                <span id="kpi-in-progress-count" class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo number_format($inProgressCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&priority=critical" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($priority_filter === 'critical') ? 'var(--danger)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($priority_filter === 'critical') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--danger-light); color: var(--danger); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="alert-triangle" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Critical Priority</span>
                <span id="kpi-critical-count" class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--danger);"><?php echo number_format($criticalCount); ?></span>
            </div>
        </a>

        <a href="index.php?page=support&status=resolved" class="card p-4 flex align-center gap-4 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'var(--success)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); transform: <?php echo ($status_filter === 'resolved' || $status_filter === 'closed') ? 'translateY(-2px)' : 'none'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
            <div style="width: 48px; height: 48px; border-radius: 12px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="check-circle-2" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Resolved / Closed</span>
                <span id="kpi-resolved-count" class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success);"><?php echo number_format($resolvedCount); ?></span>
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
                <span id="support-tickets-count-badge" class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700; font-size: 0.8rem;">
                    <?php echo count($tickets); ?> Tickets
                </span>
            </div>
        </div>

        <div class="table-responsive" id="support-tickets-table-container">
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
                <tbody id="support-tickets-tbody">
                    <?php echo renderSupportTicketRows($tickets, $user_name, $is_admin, $canEdit); ?>
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
                            <input type="text" id="client-search-input" name="client_search_input" class="form-control text-xs font-semibold" placeholder="Select or type client name" autocomplete="off" oninput="document.getElementById('new-ticket-client-name').value = this.value; filterClientSearchDropdown();" onfocus="showClientSearchDropdown()" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main); padding-right: 2rem;">
                            <i data-lucide="chevron-down" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                        </div>
                        
                        <!-- Live Search Autocomplete Popup Menu -->
                        <div id="client-search-dropdown-menu" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 999; max-height: 220px; overflow-y: auto; background-color: var(--bg-card); border: 2px solid var(--primary); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.6); margin-top: 4px;">
                            <!-- Populate via JS -->
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Client id</label>
                        <input type="text" name="lead_id" id="new-ticket-client-id" class="form-control text-xs font-mono" placeholder="Optional / Auto-filled" style="background-color: var(--bg-card); border-color: var(--border-color); color: var(--text-main);">
                    </div>
                    
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Mobile no.*</label>
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
                        <input type="hidden" name="customer_name" id="new-ticket-client-name">
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

<style>
.btn-ai-chip {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #cbd5e1);
    color: var(--text-main, #334155);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.71rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.btn-ai-chip:hover {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
    transform: translateY(-1px);
}
.ai-suggest-popover {
    margin-top: 8px;
    background: var(--bg-card, #ffffff);
    border: 1.5px solid #10b981;
    border-radius: 10px;
    padding: 10px 12px;
    box-shadow: 0 4px 16px rgba(16,185,129,0.15);
    position: relative;
    animation: fadeIn 0.2s ease;
}
/* Live Inline Word AI Ribbon */
.ai-live-word-ribbon {
    margin-top: 6px;
    background: linear-gradient(135deg, rgba(99,102,241,0.07), rgba(16,185,129,0.07));
    border: 1.5px solid rgba(99,102,241,0.3);
    border-radius: 8px;
    padding: 6px 10px;
    box-shadow: 0 4px 14px rgba(99,102,241,0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    animation: fadeIn 0.15s ease-out;
}
.ai-live-badge-glow {
    font-size: 0.72rem;
    font-weight: 700;
    color: #4f46e5;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.ai-word-misspelled {
    text-decoration: line-through;
    color: #dc2626;
    background: rgba(220,38,38,0.08);
    border: 1px dashed rgba(220,38,38,0.3);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 0.74rem;
    font-weight: 700;
    font-family: monospace;
}
.ai-pill-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 0.73rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 6px rgba(16,185,129,0.3);
    transition: all 0.15s ease;
}
.ai-pill-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(16,185,129,0.4);
    filter: brightness(1.05);
}
.ai-pill-btn kbd {
    background: rgba(0,0,0,0.25);
    color: #fff;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 0.65rem;
    font-family: inherit;
}
.ai-hint-kbd {
    font-size: 0.68rem;
    color: var(--text-muted, #64748b);
    font-weight: 500;
}
.btn-ribbon-dismiss {
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    padding: 2px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
}
.btn-ribbon-dismiss:hover {
    color: #64748b;
    background: rgba(0,0,0,0.05);
}
</style>
                        <div class="form-group m-0" style="position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; flex-wrap: wrap; gap: 4px;">
                                <label class="form-label text-xs font-bold mb-0" style="color: #10b981; display: inline-flex; align-items: center; gap: 5px;">
                                    <i data-lucide="check-circle" style="width:13px; height:13px;"></i>
                                    Resolved Description / Solution (Employee Fill)
                                </label>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span id="ai-res-typing-indicator" style="display: none; font-size: 0.68rem; color: #6366f1; font-weight: 600; align-items: center; gap: 4px;">
                                        <i data-lucide="loader-2" class="spin" style="width: 11px; height: 11px;"></i> AI checking...
                                    </span>
                                    <span class="badge" style="background: linear-gradient(135deg, rgba(99,102,241,0.12), rgba(168,85,247,0.12)); color: #6366f1; border: 1px solid rgba(99,102,241,0.25); font-size: 0.68rem; font-weight: 700; padding: 2px 7px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i data-lucide="sparkles" style="width: 11px; height: 11px;"></i> QuillBot AI Assistant
                                    </span>
                                </div>
                            </div>

                            <textarea name="resolution" id="edit-ticket-resolution" rows="3" class="form-control text-xs" style="border-radius: 8px; resize: vertical; border-color: rgba(16,185,129,0.4); font-family: inherit; line-height: 1.45;" placeholder="Enter solution details, technical steps taken, or resolution provided for client..."></textarea>

                            <!-- AI Live Inline Word-Level Suggestion Ribbon (Real-Time As Employee Types) -->
                            <div id="ai-live-word-ribbon" class="ai-live-word-ribbon" style="display: none;">
                                <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap;">
                                    <span class="ai-live-badge-glow">
                                        <i data-lucide="sparkles" style="width: 12px; height: 12px;"></i> AI Suggestion:
                                    </span>
                                    <span class="ai-word-misspelled" id="ai-ribbon-wrong">word</span>
                                    <span style="color: #94a3b8; font-size: 0.75rem;">➔</span>
                                    <div id="ai-ribbon-pills" style="display: inline-flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                        <!-- Interactive Pills Injected Here -->
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="ai-hint-kbd">Press <kbd style="background:rgba(0,0,0,0.08); border:1px solid #cbd5e1; border-radius:3px; padding:1px 4px; font-size:0.65rem;">Tab ⇥</kbd> to accept &bull; keep typing to skip</span>
                                    <button type="button" class="btn-ribbon-dismiss" onclick="dismissLiveWordRibbon()" title="Dismiss suggestion">
                                        <i data-lucide="x" style="width: 12px; height: 12px;"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- AI Quick Mode Bar -->
                            <div style="display: flex; align-items: center; gap: 5px; margin-top: 6px; flex-wrap: wrap;">
                                <span style="font-size: 0.68rem; color: var(--text-muted, #64748b); font-weight: 700; text-transform: uppercase;">AI Rewrite:</span>
                                <button type="button" class="btn-ai-chip" onclick="triggerAiTextCorrection('grammar')" title="Fix all spelling, punctuation, and grammar mistakes">
                                    <i data-lucide="spell-check" style="width: 11px; height: 11px; color: #10b981;"></i> Fix Grammar &amp; Spelling
                                </button>
                                <button type="button" class="btn-ai-chip" onclick="triggerAiTextCorrection('professional')" title="Polish into clean, professional IT support documentation">
                                    <i data-lucide="briefcase" style="width: 11px; height: 11px; color: #2563eb;"></i> Professional Polish
                                </button>
                                <button type="button" class="btn-ai-chip" onclick="triggerAiTextCorrection('paraphrase')" title="QuillBot style sentence paraphrasing &amp; clarity">
                                    <i data-lucide="refresh-cw" style="width: 11px; height: 11px; color: #8b5cf6;"></i> QuillBot Paraphrase
                                </button>
                                <button type="button" class="btn-ai-chip" onclick="triggerAiTextCorrection('technical_steps')" title="Format as step-by-step resolution list">
                                    <i data-lucide="list-ordered" style="width: 11px; height: 11px; color: #d97706;"></i> Step-by-Step
                                </button>
                            </div>

                            <!-- AI Suggestion Popover Card -->
                            <div id="ai-res-suggestion-card" class="ai-suggest-popover" style="display: none;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                        <span style="background: rgba(16,185,129,0.15); color: #059669; font-weight: 800; font-size: 0.68rem; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">
                                            <i data-lucide="sparkles" style="width: 11px; height: 11px;"></i> AI Corrected Suggestion
                                        </span>
                                        <span id="ai-res-suggestion-note" style="font-size: 0.72rem; color: var(--text-muted, #64748b); font-weight: 500;"></span>
                                    </div>
                                    <button type="button" onclick="closeAiSuggestionCard()" style="border: none; background: transparent; cursor: pointer; color: #94a3b8; padding: 2px;" title="Dismiss">
                                        <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                    </button>
                                </div>

                                <div id="ai-res-suggestion-preview" style="font-size: 0.8rem; line-height: 1.45; color: var(--text-main, #0f172a); background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.25); padding: 8px 10px; border-radius: 6px; white-space: pre-wrap; font-weight: 500;"></div>

                                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 6px; margin-top: 8px;">
                                    <button type="button" class="btn btn-secondary text-xs" style="padding: 3px 8px; font-size: 0.72rem;" onclick="copyAiSuggestion()">
                                        <i data-lucide="copy" style="width: 11px; height: 11px;"></i> Copy
                                    </button>
                                    <button type="button" class="btn btn-success text-xs font-bold" style="padding: 4px 12px; font-size: 0.74rem; background: #10b981; color: white; display: inline-flex; align-items: center; gap: 4px;" onclick="applyAiSuggestion()">
                                        <i data-lucide="check" style="width: 12px; height: 12px;"></i> Apply &amp; Replace
                                    </button>
                                </div>
                            </div>
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

                <!-- SECTION 4: CLIENT LIFETIME TICKETS & ASSIGNMENT/ACTIVITY TIMELINE -->
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem 1.25rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 1rem; align-items: stretch;">
                        
                        <!-- LEFT PANEL: All Tickets for this Client ID / License -->
                        <div style="display: flex; flex-direction: column; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 12px; padding: 0.85rem; max-height: 250px;">
                            <div class="flex justify-between align-center mb-2 pb-1.5" style="border-bottom: 1px solid var(--border-color);">
                                <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary); display:flex; align-items:center; gap: 6px;">
                                    <i data-lucide="layers" style="width:13px; height:13px;"></i> Client Tickets (<span id="client-tickets-count">0</span>)
                                </div>
                                <span class="text-xs text-muted" style="font-size: 0.65rem; font-weight: 600;">Click to view logs</span>
                            </div>
                            
                            <div id="client-tickets-list-container" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 0.45rem; padding-right: 2px;">
                                <p class="text-xs text-muted m-0" style="font-style: italic;">Loading client tickets...</p>
                            </div>
                        </div>

                        <!-- RIGHT PANEL: Timeline / Activity Logs for Selected Ticket -->
                        <div style="display: flex; flex-direction: column; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 12px; padding: 0.85rem; max-height: 250px;">
                            <div class="flex justify-between align-center mb-2 pb-1.5" style="border-bottom: 1px solid var(--border-color);">
                                <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-main); display:flex; align-items:center; gap: 6px;">
                                    <i data-lucide="history" style="width:13px; height:13px; color: var(--primary);"></i> History for <span id="active-history-ticket-id" class="font-mono text-primary font-bold">#---</span>
                                </div>
                                <span id="edit-history-count" class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted); font-size: 0.65rem; font-weight: 700;">0 logs</span>
                            </div>

                            <div id="edit-ticket-history-container" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 0.45rem; padding-right: 2px;">
                                <p class="text-xs text-muted m-0" style="font-style: italic;">Select a ticket to view history...</p>
                            </div>
                        </div>

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
    let pName = (data.customer_name || data.party_name || '').trim();
    if (!pName) pName = '-';
    const contact = (data.contact_person && data.contact_person.trim() !== '') ? data.contact_person.trim() : (pName !== '-' ? pName : '-');
    
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
    const prod = data.product || data.software_type || (pName !== '-' ? 'Marg ERP' : '-');
    const swType = data.sw_type || (pName !== '-' ? 'Marg' : '-');
    const uType = (data.user_type || data.no_of_users) ? ((data.user_type || 'Multi User') + (data.no_of_users ? ' (' + data.no_of_users + ')' : '')) : (pName !== '-' ? 'Multi User (1)' : '-');
    const numComp = data.company_using || data.no_of_companies || (pName !== '-' ? 250 : '-');
    const stat = data.party_status || (pName !== '-' ? 'Running' : '-');
    const trade = data.software_trade || (pName !== '-' ? 'Business Services' : '-');
    const homeUser = data.home_user || (pName !== '-' ? 'No' : '-');
    const amt = (data.total_amount && parseFloat(data.total_amount) > 0) ? ('₹' + parseFloat(data.total_amount).toFixed(2)) : (pName !== '-' ? '₹0.00' : '-');
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

    // Hidden inputs for POST form submit - only set if valid
    const elName = document.getElementById('edit-ticket-client-name'); if (elName && pName !== '-') elName.value = pName;
    const elPhone = document.getElementById('edit-ticket-phone'); if (elPhone && mob !== '-') elPhone.value = mob;
    const elEmail = document.getElementById('edit-ticket-email'); if (elEmail && em !== '-') elEmail.value = em;
    const elProd = document.getElementById('edit-ticket-product'); if (elProd && prod !== '-') elProd.value = prod;
    const elRen = document.getElementById('edit-ticket-renewal'); if (elRen && ren !== '-') elRen.value = ren;
    const elAddr = document.getElementById('edit-ticket-address'); if (elAddr && fullAddr !== '-') elAddr.value = fullAddr;

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
                    renSub.innerText = ' Renewal is due today';
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
                    renSub.innerText = ' ' + diffDays + ' days left';
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

    // Set hidden inputs directly from ticket FIRST so existing values are preserved
    if (document.getElementById('edit-ticket-client-name')) document.getElementById('edit-ticket-client-name').value = ticket.customer_name || "";
    if (document.getElementById('edit-ticket-phone')) document.getElementById('edit-ticket-phone').value = ticket.phone || "";
    if (document.getElementById('edit-ticket-email')) document.getElementById('edit-ticket-email').value = ticket.email || "";
    if (document.getElementById('edit-ticket-product')) document.getElementById('edit-ticket-product').value = ticket.product || "";
    if (document.getElementById('edit-ticket-renewal')) document.getElementById('edit-ticket-renewal').value = ticket.renewal_date || "";
    if (document.getElementById('edit-ticket-address')) document.getElementById('edit-ticket-address').value = ticket.address || "";

    // Set callback input right away before view update
    const cbInput = document.getElementById('edit-ticket-callback');
    if (cbInput) {
        let cleanCb = (ticket.callback_number || ticket.phone || "").trim();
        const digits = cleanCb.replace(/[^0-9]/g, '');
        if (digits.length === 12 && digits.startsWith('91')) {
            cleanCb = digits.substring(2);
        }
        cbInput.value = cleanCb;
    }

    // Set initial compact view from ticket fields
    updateClientCompactView(ticket);

    // Auto-fetch fresh Client Directory data if lead_id or phone exists
    if (ticket.lead_id) {
        autoFetchClientDetails();
    } else if (ticket.phone || ticket.callback_number) {
        const lookupPhone = (ticket.phone || ticket.callback_number).replace(/[^0-9]/g, '').slice(-10);
        if (lookupPhone && typeof masterClientsData !== 'undefined' && masterClientsData) {
            const match = masterClientsData.find(c => {
                const mob = String(c.mobile || '').replace(/[^0-9]/g, '').slice(-10);
                return mob === lookupPhone;
            });
            if (match) {
                if (document.getElementById('edit-ticket-client-id')) document.getElementById('edit-ticket-client-id').value = match.customer_id || '';
                updateClientCompactView(match);
            }
        }
    }
    
    // Editable Ticket Parameters
    document.getElementById('edit-ticket-priority').value = ticket.priority || "medium";
    
    // Populate Subject, Problem Summary & Employee Resolution/Solution
    const subjElem = document.getElementById('edit-ticket-subject');
    if (subjElem) subjElem.value = ticket.subject || "";
    
    const probElem = document.getElementById('edit-ticket-problem');
    if (probElem) probElem.value = ticket.problem || "";

    // Clear resolution / remark input so employee can enter a fresh remark / update
    const resElem = document.getElementById('edit-ticket-resolution');
    if (resElem) resElem.value = "";
    closeAiSuggestionCard();
    
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
    if (cbInput && !cbInput.value) {
        cbInput.value = ticket.callback_number || ticket.phone || "";
    }
    
    // Load ticket activity & lifetime client tickets
    const leadIdVal = ticket.lead_id || (document.getElementById('edit-ticket-client-id') ? document.getElementById('edit-ticket-client-id').value : '');
    const phoneVal = ticket.phone || ticket.callback_number || '';
    loadTicketHistory(ticket.id, leadIdVal, phoneVal);

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

let cachedClientTickets = [];
let currentActiveHistoryTicketId = '';

function renderTimelineLogs(history, ticketId) {
    const container = document.getElementById('edit-ticket-history-container');
    const countElem = document.getElementById('edit-history-count');
    const activeLabel = document.getElementById('active-history-ticket-id');
    if (activeLabel) activeLabel.innerText = '#' + (ticketId || '---');

    if (!container) return;

    if (Array.isArray(history) && history.length > 0) {
        if (countElem) countElem.innerText = history.length + (history.length === 1 ? ' log' : ' logs');
        let html = '';
        history.forEach(item => {
            let actionLabel = String(item.action || 'LOG').toUpperCase();
            let badgeBg = 'rgba(59, 130, 246, 0.12)';
            let badgeColor = '#2563eb';
            
            if (item.action === 'created') {
                actionLabel = 'CREATED';
                badgeBg = 'rgba(14, 165, 233, 0.15)';
                badgeColor = '#0284c7';
            } else if (item.action === 'taken') {
                actionLabel = 'CLAIMED';
                badgeBg = 'rgba(16, 185, 129, 0.15)';
                badgeColor = '#059669';
            } else if (item.action === 'transferred') {
                actionLabel = 'TRANSFERRED';
                badgeBg = 'rgba(245, 158, 11, 0.15)';
                badgeColor = '#d97706';
            } else if (item.action === 'work_note' || item.action === 'remark') {
                actionLabel = 'WORK REMARK';
                badgeBg = 'rgba(99, 102, 241, 0.15)';
                badgeColor = '#4f46e5';
            } else if (item.action === 'resolution' || item.action === 'resolved' || item.action === 'closed') {
                actionLabel = 'RESOLVED / CLOSED';
                badgeBg = 'rgba(16, 185, 129, 0.2)';
                badgeColor = '#10b981';
            } else if (item.action === 'reopened') {
                actionLabel = 'REOPENED';
                badgeBg = 'rgba(239, 68, 68, 0.15)';
                badgeColor = '#dc2626';
            } else if (item.action === 'status_change') {
                actionLabel = 'STATUS UPDATE';
                badgeBg = 'rgba(139, 92, 246, 0.15)';
                badgeColor = '#7c3aed';
            } else if (item.action === 'priority_change') {
                actionLabel = 'PRIORITY UPDATE';
                badgeBg = 'rgba(249, 115, 22, 0.15)';
                badgeColor = '#ea580c';
            } else if (item.action === 'due_date_update') {
                actionLabel = 'DUE DATE';
                badgeBg = 'rgba(20, 184, 166, 0.15)';
                badgeColor = '#0d9488';
            }

            const dt = new Date(item.created_at);
            const timeStr = isNaN(dt.getTime()) ? item.created_at : dt.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });

            const actorEsc = String(item.actor_name || 'System').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const roleEsc = item.actor_role ? String(item.actor_role).replace(/</g, '&lt;').replace(/>/g, '&gt;') : '';
            const detailsEsc = String(item.details || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            html += `
                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.5rem 0.65rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 2px; flex-grow: 1;">
                        <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                            <span class="badge text-xs" style="--badge-bg: ${badgeBg}; --badge-color: ${badgeColor}; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; padding: 1px 5px;">${actionLabel}</span>
                            <strong style="font-size: 0.74rem; color: var(--text-main);">${actorEsc}</strong>
                            ${roleEsc ? `<span class="text-muted" style="font-size: 0.65rem;">(${roleEsc})</span>` : ''}
                        </div>
                        <span style="font-size: 0.73rem; color: var(--text-main); line-height: 1.35; margin-top: 1px;">${detailsEsc}</span>
                    </div>
                    <span class="font-mono text-muted" style="font-size: 0.62rem; white-space: nowrap; flex-shrink: 0;">${timeStr}</span>
                </div>
            `;
        });
        container.innerHTML = html;
    } else {
        if (countElem) countElem.innerText = '0 logs';
        container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic; padding: 0.5rem;">No activity history recorded yet for #' + ticketId + '.</p>';
    }
}

function renderClientTicketsList(tickets, activeId) {
    const container = document.getElementById('client-tickets-list-container');
    const countElem = document.getElementById('client-tickets-count');
    if (!container) return;

    if (!Array.isArray(tickets) || tickets.length === 0) {
        if (countElem) countElem.innerText = '0';
        container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic; padding: 0.5rem;">No other tickets for this License / Client.</p>';
        return;
    }

    if (countElem) countElem.innerText = tickets.length;
    let html = '';

    tickets.forEach(t => {
        const isCurrentActive = (String(t.id).toLowerCase() === String(activeId).toLowerCase());
        const borderStyle = isCurrentActive ? 'border: 1.5px solid var(--primary); background: rgba(37, 99, 235, 0.08);' : 'border: 1px solid var(--border-color); background: var(--bg-card);';
        
        let statColor = '#0284c7';
        let statBg = 'rgba(14, 165, 233, 0.12)';
        let statLabel = 'Open';
        const stLower = String(t.status || '').toLowerCase();
        if (stLower === 'resolved' || stLower === 'closed') {
            statColor = '#059669';
            statBg = 'rgba(16, 185, 129, 0.15)';
            statLabel = 'Closed';
        } else if (stLower === 'in_progress') {
            statColor = '#d97706';
            statBg = 'rgba(245, 158, 11, 0.15)';
            statLabel = 'In Progress';
        }

        const dateStr = t.date_created ? new Date(t.date_created).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
        const probEsc = String(t.subject || t.problem || 'No description').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const assignedEsc = String(t.assigned_to || 'Unassigned').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const logCount = t.log_count || 0;

        html += `
            <div onclick="switchActiveTicketHistory('${t.id}')" style="cursor: pointer; border-radius: 8px; padding: 0.5rem 0.6rem; transition: all 0.15s; ${borderStyle}" class="client-ticket-item" id="client-ticket-card-${t.id}">
                <div class="flex justify-between align-center mb-1">
                    <div class="flex align-center gap-1.5">
                        <strong class="font-mono text-primary" style="font-size: 0.74rem;">#${t.id}</strong>
                        ${isCurrentActive ? '<span class="badge" style="--badge-bg: var(--primary); --badge-color: #fff; font-size: 0.58rem; padding: 0 4px; border-radius: 3px;">Active</span>' : ''}
                    </div>
                    <span class="badge" style="--badge-bg: ${statBg}; --badge-color: ${statColor}; font-size: 0.6rem; font-weight: 700; text-transform: uppercase;">${statLabel}</span>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-main); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                    ${probEsc}
                </div>
                <div class="flex justify-between align-center text-muted font-mono" style="font-size: 0.62rem;">
                    <span><i data-lucide="user" style="width:9px; height:9px; display:inline-block; vertical-align:middle;"></i> ${assignedEsc}</span>
                    <span>${dateStr} (${logCount} logs)</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function switchActiveTicketHistory(ticketId) {
    currentActiveHistoryTicketId = ticketId;
    const activeLabel = document.getElementById('active-history-ticket-id');
    if (activeLabel) activeLabel.innerText = '#' + ticketId;

    // Re-render client tickets list to update active border highlight
    if (cachedClientTickets && cachedClientTickets.length > 0) {
        renderClientTicketsList(cachedClientTickets, ticketId);
    }

    const container = document.getElementById('edit-ticket-history-container');
    if (container) container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic; padding: 0.5rem;">Loading timeline for #' + ticketId + '...</p>';

    fetch('api/ticket-history.php?ticket_id=' + encodeURIComponent(ticketId))
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                renderTimelineLogs(data.history || [], ticketId);
            }
        })
        .catch(() => {
            if (container) container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Could not load history for #' + ticketId + '.</p>';
        });
}

function loadTicketHistory(ticketId, clientId = '', phone = '') {
    currentActiveHistoryTicketId = ticketId;
    const container = document.getElementById('edit-ticket-history-container');
    const ctContainer = document.getElementById('client-tickets-list-container');
    if (container) container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Loading timeline...</p>';
    if (ctContainer) ctContainer.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Loading client tickets...</p>';

    let url = 'api/ticket-history.php?ticket_id=' + encodeURIComponent(ticketId);
    if (clientId) url += '&client_id=' + encodeURIComponent(clientId);
    if (phone) url += '&phone=' + encodeURIComponent(phone);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                cachedClientTickets = data.client_tickets || [];
                renderClientTicketsList(cachedClientTickets, ticketId);
                renderTimelineLogs(data.history || [], ticketId);
            } else {
                if (container) container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">No history available.</p>';
                if (ctContainer) ctContainer.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">No tickets found.</p>';
            }
        })
        .catch(() => {
            if (container) container.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Could not load history.</p>';
            if (ctContainer) ctContainer.innerHTML = '<p class="text-xs text-muted m-0" style="font-style: italic;">Could not load tickets.</p>';
        });
}

function autoFetchClientDetails() {
    const licInput = document.getElementById('edit-ticket-client-id');
    if (!licInput || !licInput.value.trim()) return;

    const query = licInput.value.trim().toLowerCase();

    // Also refresh client lifetime tickets for this License / Client ID
    const curTid = document.getElementById('edit-ticket-id-hidden') ? document.getElementById('edit-ticket-id-hidden').value : '';
    loadTicketHistory(curTid, licInput.value.trim(), '');

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

<!-- CSS Styles for Support Desk Live Auto-Sync -->
<style>
.support-pulse-dot {
    animation: supportPulse 1.8s infinite;
}
@keyframes supportPulse {
    0% { transform: scale(0.95); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.45; }
    100% { transform: scale(0.95); opacity: 1; }
}
.refresh-spin {
    animation: rotateRefresh 0.75s linear infinite;
}
@keyframes rotateRefresh {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
@keyframes toastSlideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.support-new-ticket-toast {
    animation: toastSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.row-new-arrival {
    animation: rowPulseGlow 3s ease-out;
}
@keyframes rowPulseGlow {
    0% { background-color: rgba(16, 185, 129, 0.25); }
    50% { background-color: rgba(16, 185, 129, 0.12); }
    100% { background-color: transparent; }
}
</style>

<script>
// =========================================================================
// Support Ticket Desk - Zero-Refresh Real-Time Live Auto-Fetch Engine
// =========================================================================
let supportLivePollTimer = null;
let lastKnownTicketId = <?php echo json_encode(!empty($tickets[0]['id']) ? $tickets[0]['id'] : ''); ?>;
let lastKnownTotal = <?php echo (int)count($tickets); ?>;
let isSupportPolling = true;

// Web Audio API Gentle Chime Generator (No external audio file needed)
function playNewTicketChime() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        if (ctx.state === 'suspended') {
            ctx.resume();
        }
        const now = ctx.currentTime;

        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(698.46, now); // F5
        gain1.gain.setValueAtTime(0.18, now);
        gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.35);

        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880.00, now + 0.12); // A5
        gain2.gain.setValueAtTime(0.22, now + 0.12);
        gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.65);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.12);
        osc2.stop(now + 0.65);
    } catch (e) {}
}

function showNewTicketToast(ticketId, count) {
    let container = document.getElementById('support-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'support-toast-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 999999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'support-new-ticket-toast';
    toast.style.cssText = 'background: #0f172a; color: #ffffff; padding: 12px 18px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; pointer-events: auto; font-size: 0.85rem; border-left: 4px solid #10b981; max-width: 360px;';
    toast.innerHTML = `
        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(16, 185, 129, 0.2); color: #10b981; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="bell" style="width: 18px; height: 18px;"></i>
        </div>
        <div style="flex: 1; min-width: 0;">
            <div style="font-weight: 700; color: #f8fafc; margin-bottom: 2px;">New Support Ticket Received!</div>
            <div style="color: #94a3b8; font-size: 0.78rem;">Ticket <strong>#${escapeHtml(ticketId)}</strong> is now live in your desk.</div>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: transparent; border: none; color: #64748b; cursor: pointer; padding: 2px; font-size: 1rem; line-height: 1;">&times;</button>
    `;

    container.appendChild(toast);
    if (window.lucide) lucide.createIcons();

    setTimeout(() => {
        toast.style.transition = 'all 0.4s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 400);
    }, 5000);
}

function fetchLiveSupportTickets(isManual = false) {
    if (!isSupportPolling && !isManual) return;

    // Build URL query with current page filters
    const currentParams = new URLSearchParams(window.location.search);
    currentParams.set('page', 'support');
    currentParams.set('action', 'fetch_live_tickets');
    currentParams.set('_t', Date.now());

    fetch('index.php?' + currentParams.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
    })
    .then(res => {
        if (res.status === 401 || res.status === 403) {
            return res.json().catch(() => ({})).then(data => {
                window.location.href = (data && data.redirect) ? data.redirect : 'auth/login.php?reason=concurrent_login';
                return null;
            });
        }
        return res.json();
    })
    .then(data => {
        if (!data) return;
        if (data.status === 'session_terminated' || data.status === 'session_expired') {
            window.location.href = data.redirect || 'auth/login.php?reason=concurrent_login';
            return;
        }
        if (data.status !== 'success') return;

        // 1. Update KPI Counters
        if (data.counts) {
            const kOpen = document.getElementById('kpi-open-count');
            const kProg = document.getElementById('kpi-in-progress-count');
            const kCrit = document.getElementById('kpi-critical-count');
            const kRes  = document.getElementById('kpi-resolved-count');
            if (kOpen && kOpen.textContent != data.counts.open) kOpen.textContent = data.counts.open;
            if (kProg && kProg.textContent != data.counts.in_progress) kProg.textContent = data.counts.in_progress;
            if (kCrit && kCrit.textContent != data.counts.critical) kCrit.textContent = data.counts.critical;
            if (kRes  && kRes.textContent != data.counts.resolved) kRes.textContent = data.counts.resolved;
        }

        // 2. Update Total Badge
        const badge = document.getElementById('support-tickets-count-badge');
        if (badge) {
            badge.textContent = data.total + ' Tickets';
        }

        // 3. Detect New Ticket Arrival
        const hasNewTicket = data.latest_ticket_id && (data.latest_ticket_id !== lastKnownTicketId) && (data.total > lastKnownTotal);
        if (hasNewTicket) {
            playNewTicketChime();
            showNewTicketToast(data.latest_ticket_id, data.total);
            lastKnownTicketId = data.latest_ticket_id;
            lastKnownTotal = data.total;
        } else if (data.latest_ticket_id) {
            lastKnownTicketId = data.latest_ticket_id;
            lastKnownTotal = data.total;
        }

        // 4. Update Table Rows (Skip if modal is actively open to avoid losing user state)
        const activeModal = document.querySelector('.modal-overlay.open, .modal.open');
        if (!activeModal && data.tbody_html) {
            const tbody = document.getElementById('support-tickets-tbody');
            if (tbody && tbody.innerHTML !== data.tbody_html) {
                tbody.innerHTML = data.tbody_html;
                if (window.lucide) {
                    lucide.createIcons();
                }
                // Highlight new row if fresh ticket arrived
                if (hasNewTicket && data.latest_ticket_id) {
                    const newRow = tbody.querySelector(`tr[data-ticket-id="${data.latest_ticket_id}"]`);
                    if (newRow) {
                        newRow.classList.add('row-new-arrival');
                    }
                }
            }
        }
    })
    .catch(err => {
        // Silent catch for network drops
    })
    .finally(() => {
        if (isManual) {
            const icon = document.getElementById('tickets-refresh-icon');
            if (icon) icon.classList.remove('refresh-spin');
        }
    });
}

function triggerManualTicketsRefresh(btn) {
    const icon = document.getElementById('tickets-refresh-icon');
    if (icon) icon.classList.add('refresh-spin');
    fetchLiveSupportTickets(true);
}

// Start auto-sync polling every 3.5 seconds
document.addEventListener('DOMContentLoaded', () => {
    if (!supportLivePollTimer) {
        supportLivePollTimer = setInterval(() => {
            fetchLiveSupportTickets(false);
        }, 3500);
    }
});

// ==============================================================
// QuillBot & Grammarly-Style Real-Time Live AI Writing Assistant
// ==============================================================
let aiTypingDebounceTimer = null;
let aiWordRemoteCheckTimer = null;
let activeWordSuggestion = null; // { word, start, end, suggestions: [] }
let currentAiSuggestionText = '';

// High-frequency IT Support & Marg ERP Fast Typo Dictionary (Instant 0ms)
const AI_FAST_TYPO_DICT = {
    'printr': ['printer'],
    'prntr': ['printer'],
    'prnt': ['printer', 'print'],
    'isue': ['issue'],
    'issuse': ['issue', 'issues'],
    'problm': ['problem'],
    'probelm': ['problem'],
    'softwear': ['software'],
    'softwer': ['software'],
    'sofware': ['software'],
    'softwere': ['software'],
    'custmr': ['customer'],
    'custmer': ['customer'],
    'clint': ['client'],
    'cleint': ['client'],
    'instaled': ['installed'],
    'instal': ['install', 'installed'],
    'istall': ['install'],
    'intall': ['install'],
    'seting': ['settings'],
    'setings': ['settings'],
    'confgr': ['configured'],
    'configration': ['configuration'],
    'resolvd': ['resolved'],
    'reolved': ['resolved'],
    'sloved': ['solved', 'resolved'],
    'solvd': ['solved', 'resolved'],
    'conection': ['connection'],
    'conect': ['connect', 'connected'],
    'conected': ['connected'],
    'updte': ['update', 'updated'],
    'updat': ['update', 'updated'],
    'datbase': ['database'],
    'databse': ['database'],
    'reindex': ['data re-indexing'],
    'reindexing': ['data re-indexing'],
    'billformat': ['invoice format'],
    'bilformat': ['invoice format'],
    'watsapp': ['WhatsApp'],
    'whatsap': ['WhatsApp'],
    'watsap': ['WhatsApp'],
    'margh': ['Marg ERP'],
    'licence': ['license'],
    'lisence': ['license'],
    'acount': ['account'],
    'acounts': ['accounts'],
    'paswrd': ['password'],
    'passwrd': ['password'],
    'passward': ['password'],
    'recevd': ['received'],
    'recieved': ['received'],
    'recvd': ['received'],
    'servr': ['server'],
    'serivr': ['server'],
    'cal': ['called', 'call'],
    'caled': ['called'],
    'mesage': ['message'],
    'msg': ['message'],
    'finacial': ['financial'],
    'fiancial': ['financial'],
    'chek': ['checked', 'check'],
    'cheked': ['checked'],
    'chcked': ['checked'],
    'succesful': ['successful'],
    'succesfully': ['successfully'],
    'runing': ['running'],
    'eror': ['error'],
    'errror': ['error'],
    'guid': ['guided', 'guidance'],
    'guidence': ['guidance'],
    'provid': ['provided', 'provide'],
    'provied': ['provided'],
    'alredy': ['already'],
    'plz': ['please'],
    'pls': ['please'],
    'thnx': ['thanks'],
    'thnks': ['thanks'],
    'reqst': ['requested', 'request'],
    'verifid': ['verified'],
    'verfy': ['verify'],
    'fomrat': ['format'],
    'formt': ['format'],
    'chng': ['changed', 'change'],
    'chnged': ['changed'],
    'don': ['done'],
    'compltd': ['completed']
};

function initResolutionInlineAiEngine() {
    const resElem = document.getElementById('edit-ticket-resolution');
    if (!resElem || resElem.dataset.aiInlineBound) return;
    resElem.dataset.aiInlineBound = 'true';

    // 1. Intercept TAB & ESCAPE for 1-click or 1-key accept/reject
    resElem.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            if (activeWordSuggestion && activeWordSuggestion.suggestions && activeWordSuggestion.suggestions.length > 0) {
                e.preventDefault(); // Don't jump to next form element
                acceptCurrentWordSuggestion(activeWordSuggestion.suggestions[0]);
            }
        } else if (e.key === 'Escape') {
            dismissLiveWordRibbon();
        }
    });

    // 2. Real-time typing listener (Word-by-word typo check)
    resElem.addEventListener('input', function() {
        handleResolutionWordTyping(this);
    });

    // 3. Caret move / click listener
    resElem.addEventListener('keyup', function(e) {
        if (e.key !== 'Tab' && e.key !== 'Escape') {
            handleResolutionWordTyping(this);
        }
    });
    resElem.addEventListener('click', function() {
        handleResolutionWordTyping(this);
    });
}

function handleResolutionWordTyping(elem) {
    const val = elem.value;
    const caret = elem.selectionEnd;

    // Determine the word at or immediately before the caret
    let start = caret;
    let end = caret;

    // Walk backwards to word boundary
    while (start > 0 && /\w/.test(val[start - 1])) {
        start--;
    }
    // Walk forward to word boundary
    while (end < val.length && /\w/.test(val[end])) {
        end++;
    }

    const currentWord = val.slice(start, end).trim();

    // If caret is right after a word that just got typed (e.g. user pressed Space)
    let wordToCheck = currentWord;
    let wordStart = start;
    let wordEnd = end;

    if (!wordToCheck && caret > 0 && /\s/.test(val[caret - 1])) {
        let prevEnd = caret - 1;
        while (prevEnd > 0 && /\s/.test(val[prevEnd])) prevEnd--;
        let prevStart = prevEnd;
        while (prevStart > 0 && /\w/.test(val[prevStart - 1])) prevStart--;
        if (prevEnd >= prevStart) {
            wordToCheck = val.slice(prevStart, prevEnd + 1).trim();
            wordStart = prevStart;
            wordEnd = prevEnd + 1;
        }
    }

    if (!wordToCheck || wordToCheck.length < 2) {
        dismissLiveWordRibbon();
        return;
    }

    const cleanWord = wordToCheck.toLowerCase().replace(/[^a-z0-9]/g, '');

    // 1. FAST LOCAL CHECK (0ms instant response)
    if (AI_FAST_TYPO_DICT[cleanWord]) {
        const reps = AI_FAST_TYPO_DICT[cleanWord];
        showLiveWordRibbon(wordToCheck, reps, wordStart, wordEnd);
        return;
    }

    // 2. DEBOUNCED REMOTE AI / NLP CHECK (LanguageTool + Gemini)
    clearTimeout(aiWordRemoteCheckTimer);
    aiWordRemoteCheckTimer = setTimeout(() => {
        checkWordWithRemoteAi(wordToCheck, val, wordStart, wordEnd);
    }, 450);

    // Also debounce full sentence background suggestion after long pause
    clearTimeout(aiTypingDebounceTimer);
    if (val.trim().length > 15) {
        aiTypingDebounceTimer = setTimeout(() => {
            triggerAiTextCorrection('grammar', false);
        }, 2200);
    }
}

function checkWordWithRemoteAi(word, fullText, start, end) {
    if (!word || word.length < 3) return;
    const resElem = document.getElementById('edit-ticket-resolution');
    if (!resElem) return;

    fetch('api/ai-text-correct.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: fullText, mode: 'grammar' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.word_matches && data.word_matches.length > 0) {
            // Check if any word match corresponds to our current targeted word
            const match = data.word_matches.find(m => 
                m.word.toLowerCase() === word.toLowerCase() || 
                (Math.abs(m.offset - start) <= 3 && m.word.toLowerCase() === word.toLowerCase())
            );
            if (match && match.suggestions && match.suggestions.length > 0) {
                showLiveWordRibbon(word, match.suggestions, start, end);
            }
        }
    })
    .catch(() => {});
}

function showLiveWordRibbon(wrongWord, suggestions, start, end) {
    activeWordSuggestion = {
        word: wrongWord,
        start: start,
        end: end,
        suggestions: suggestions
    };

    const ribbon = document.getElementById('ai-live-word-ribbon');
    const wrongElem = document.getElementById('ai-ribbon-wrong');
    const pillsContainer = document.getElementById('ai-ribbon-pills');

    if (!ribbon || !wrongElem || !pillsContainer) return;

    wrongElem.innerText = wrongWord;
    pillsContainer.innerHTML = '';

    suggestions.slice(0, 3).forEach((sug, idx) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-pill-btn';
        btn.dataset.word = sug;
        btn.onclick = function() {
            acceptCurrentWordSuggestion(sug);
        };
        // Show Tab shortcut tag on the primary suggestion
        if (idx === 0) {
            btn.innerHTML = `<span>${sug}</span> <kbd>Tab ⇥</kbd>`;
            btn.title = `Press Tab or Click to replace '${wrongWord}' with '${sug}'`;
        } else {
            btn.innerHTML = `<span>${sug}</span>`;
            btn.title = `Click to choose '${sug}'`;
        }
        pillsContainer.appendChild(btn);
    });

    ribbon.style.display = 'flex';
    if (window.lucide) lucide.createIcons();
}

function acceptCurrentWordSuggestion(chosenWord) {
    const resElem = document.getElementById('edit-ticket-resolution');
    if (!resElem || !activeWordSuggestion) return;

    const val = resElem.value;
    const { start, end, word } = activeWordSuggestion;

    // Verify target word matches what is in the textarea at that index range
    const currentSub = val.slice(start, end);
    let newText = val;
    let newCursorPos = start + chosenWord.length + 1;

    if (currentSub.toLowerCase() === word.toLowerCase()) {
        newText = val.slice(0, start) + chosenWord + ' ' + val.slice(end);
    } else {
        // Fallback global replace if index slightly shifted
        newText = val.replace(new RegExp('\\b' + word + '\\b', 'i'), chosenWord + ' ');
        newCursorPos = newText.indexOf(chosenWord) + chosenWord.length + 1;
    }

    resElem.value = newText;
    resElem.focus();
    resElem.setSelectionRange(newCursorPos, newCursorPos);

    // Visual green flash
    resElem.style.transition = 'box-shadow 0.2s ease';
    resElem.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.4)';
    setTimeout(() => {
        resElem.style.boxShadow = '';
    }, 400);

    dismissLiveWordRibbon();
}

function dismissLiveWordRibbon() {
    activeWordSuggestion = null;
    const ribbon = document.getElementById('ai-live-word-ribbon');
    if (ribbon) ribbon.style.display = 'none';
}

function triggerAiTextCorrection(mode, isExplicit = true) {
    const resElem = document.getElementById('edit-ticket-resolution');
    if (!resElem) return;
    const txt = resElem.value.trim();
    if (!txt) {
        if (isExplicit) alert('Please write some resolution notes or technical steps first for AI to polish!');
        return;
    }

    const indicator = document.getElementById('ai-res-typing-indicator');
    if (indicator) indicator.style.display = 'inline-flex';

    fetch('api/ai-text-correct.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: txt, mode: mode })
    })
    .then(res => res.json())
    .then(data => {
        if (indicator) indicator.style.display = 'none';
        if (data.success && data.corrected) {
            if (!isExplicit && data.corrected.trim().toLowerCase() === txt.toLowerCase()) {
                return;
            }
            currentAiSuggestionText = data.corrected;
            const card = document.getElementById('ai-res-suggestion-card');
            const preview = document.getElementById('ai-res-suggestion-preview');
            const note = document.getElementById('ai-res-suggestion-note');
            if (card && preview) {
                preview.innerText = data.corrected;
                if (note) note.innerText = data.explanation || 'QuillBot AI recommendation ready';
                card.style.display = 'block';
                if (window.lucide) lucide.createIcons();
            }
        }
    })
    .catch(err => {
        if (indicator) indicator.style.display = 'none';
        console.error('AI Text Correction failed:', err);
    });
}

function applyAiSuggestion() {
    const resElem = document.getElementById('edit-ticket-resolution');
    if (resElem && currentAiSuggestionText) {
        resElem.value = currentAiSuggestionText;
        closeAiSuggestionCard();
        resElem.style.transition = 'box-shadow 0.25s ease';
        resElem.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.35)';
        setTimeout(() => {
            resElem.style.boxShadow = '';
        }, 800);
    }
}

function copyAiSuggestion() {
    if (currentAiSuggestionText) {
        navigator.clipboard.writeText(currentAiSuggestionText).then(() => {
            alert('AI text copied to clipboard!');
        });
    }
}

function closeAiSuggestionCard() {
    const card = document.getElementById('ai-res-suggestion-card');
    if (card) card.style.display = 'none';
}

// Ensure engine binds as soon as page loads or modal opens
document.addEventListener('DOMContentLoaded', () => {
    initResolutionInlineAiEngine();
    // Also re-bind whenever edit ticket modal is triggered
    const editModal = document.getElementById('edit-ticket-modal');
    if (editModal) {
        editModal.addEventListener('shown.bs.modal', initResolutionInlineAiEngine);
        // Fallback for custom modals
        const observer = new MutationObserver(() => {
            if (editModal.style.display === 'block' || editModal.classList.contains('show') || !editModal.classList.contains('d-none')) {
                initResolutionInlineAiEngine();
            }
        });
        observer.observe(editModal, { attributes: true, attributeFilter: ['style', 'class'] });
    }
});
</script>
