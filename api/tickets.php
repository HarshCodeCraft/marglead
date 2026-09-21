<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $auth = getAuthUserContext();
    $status = trim($_GET['status'] ?? '');
    $priority = trim($_GET['priority'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $req_assigned = trim($_GET['assigned_to'] ?? '');
    
    // SECURITY ENFORCEMENT: Non-admin users are strictly restricted to their own assigned tickets
    $assigned_to = '';
    if (!$auth['isAdmin'] && !empty($auth['name'])) {
        $assigned_to = $auth['name'];
    } elseif (!empty($req_assigned) && strtolower($req_assigned) !== 'all') {
        $assigned_to = $req_assigned;
    }

    $where = [];
    $params = [];
    
    if (!empty($status)) {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    if (!empty($priority)) {
        $where[] = "priority = ?";
        $params[] = $priority;
    }

    if (!empty($assigned_to)) {
        $where[] = "assigned_to LIKE ?";
        $params[] = '%' . $assigned_to . '%';
    }
    
    if (!empty($search)) {
        $where[] = "(customer_name LIKE ? OR subject LIKE ? OR id LIKE ? OR phone LIKE ?)";
        $st = '%' . $search . '%';
        for ($i = 0; $i < 4; $i++) $params[] = $st;
    }
    
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT * FROM support_tickets $whereClause ORDER BY date_created DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendJsonResponse([
        'success' => true,
        'count' => count($tickets),
        'tickets' => $tickets
    ]);
} elseif ($method === 'POST') {
    $input = getJsonInput();
    $action = trim($input['action'] ?? '');
    
    if ($action === 'update_status') {
        $ticket_id = trim($input['ticket_id'] ?? '');
        $status = trim($input['status'] ?? '');
        $remark = trim($input['remark'] ?? $input['resolution'] ?? '');
        
        if (empty($ticket_id) || empty($status)) {
            sendJsonResponse(['success' => false, 'message' => 'Ticket ID and status required.'], 400);
        }

        $origStmt = $pdo->prepare("SELECT status, phone, callback_number FROM support_tickets WHERE id = ?");
        $origStmt->execute([$ticket_id]);
        $origRow = $origStmt->fetch(PDO::FETCH_ASSOC);
        $origStatus = $origRow['status'] ?? 'open';
        $custPhone = !empty($origRow['phone']) ? $origRow['phone'] : ($origRow['callback_number'] ?? '');
        
        $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $stmt->execute([$status, $ticket_id]);

        // Auto-close chat in Team Inbox silently (NO chat message logged)
        if (in_array(strtolower($status), ['resolved', 'closed']) && !empty($custPhone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $custPhone);
            $last10 = substr($cleanPhone, -10);
            if (!empty($cleanPhone)) {
                try {
                    $stmtCloseChat = $pdo->prepare("UPDATE chat_conversations SET status = 'closed' WHERE phone = ? OR phone LIKE ? OR phone LIKE ?");
                    $stmtCloseChat->execute([$custPhone, "%$cleanPhone%", "%$last10%"]);
                } catch (Throwable $eCC) {}
            }
        }

        try {
            $isReopen = in_array(strtolower($origStatus), ['resolved', 'closed']) && in_array(strtolower($status), ['open', 'in_progress', 'pending']);
            $isClose = in_array(strtolower($status), ['resolved', 'closed']);
            $actType = $isReopen ? 'reopened' : ($isClose ? 'resolved' : 'status_change');
            $statusNote = "Status updated from '" . ucfirst($origStatus) . "' to '" . ucfirst($status) . "'" . (!empty($remark) ? ". Remark: {$remark}" : "");
            if ($isReopen) $statusNote = "Ticket REOPENED from '" . ucfirst($origStatus) . "' to '" . ucfirst($status) . "'" . (!empty($remark) ? ". Reason: {$remark}" : "");
            
            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, ?, ?, 'API User', ?, NOW())");
            $stmtH->execute([$ticket_id, $actType, $_SESSION['user_name'] ?? 'API User', $statusNote]);
        } catch (Throwable $eH) {}
        
        sendJsonResponse(['success' => true, 'message' => 'Ticket status updated successfully.']);
    } else {
        // Create new ticket
        $customer_name = trim($input['customer_name'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $priority = trim($input['priority'] ?? 'medium');
        
        if (empty($customer_name) || empty($subject)) {
            sendJsonResponse(['success' => false, 'message' => 'Customer Name and Subject are required.'], 400);
        }
        
        $tckId = generate_ticket_number($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, problem, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $tckId,
            $customer_name,
            $subject,
            $priority,
            $input['status'] ?? 'open',
            $input['assigned_to'] ?? 'Unassigned',
            $input['lead_id'] ?? null,
            $input['phone'] ?? null,
            $input['email'] ?? null,
            $input['product'] ?? 'Marg ERP Pro',
            $input['problem'] ?? null
        ]);

        try {
            $stmtTktSync = $pdo->prepare("
                INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtTktSync->execute([
                $tckId,
                $input['lead_id'] ?? '',
                $customer_name,
                $customer_name,
                $input['phone'] ?? '',
                $input['email'] ?? '',
                $subject,
                ucfirst($priority),
                $input['problem'] ?? '',
                ucfirst($input['status'] ?? 'open')
            ]);
        } catch (Throwable $eTSync) {}

        try {
            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'created', ?, 'API', ?, NOW())");
            $stmtH->execute([
                $tckId,
                $_SESSION['user_name'] ?? 'API User',
                "Ticket created via API for {$customer_name}. Subject: {$subject}, Priority: {$priority}"
            ]);
        } catch (Throwable $eH) {}
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'ticket_id' => $tckId
        ], 201);
    }
}
