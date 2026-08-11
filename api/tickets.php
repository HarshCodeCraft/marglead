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
        
        if (empty($ticket_id) || empty($status)) {
            sendJsonResponse(['success' => false, 'message' => 'Ticket ID and status required.'], 400);
        }
        
        $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
        $stmt->execute([$status, $ticket_id]);
        
        sendJsonResponse(['success' => true, 'message' => 'Ticket status updated successfully.']);
    } else {
        // Create new ticket
        $customer_name = trim($input['customer_name'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $priority = trim($input['priority'] ?? 'medium');
        
        if (empty($customer_name) || empty($subject)) {
            sendJsonResponse(['success' => false, 'message' => 'Customer Name and Subject are required.'], 400);
        }
        
        $tckId = 'TCK-' . rand(1000, 9999);
        
        $stmt = $pdo->prepare("
            INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, problem)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $tckId,
            $customer_name,
            $subject,
            $priority,
            $input['status'] ?? 'open',
            $input['assigned_to'] ?? 'Rahul P.',
            $input['lead_id'] ?? null,
            $input['phone'] ?? null,
            $input['email'] ?? null,
            $input['product'] ?? 'Marg ERP Pro',
            $input['problem'] ?? null
        ]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'ticket_id' => $tckId
        ], 201);
    }
}
