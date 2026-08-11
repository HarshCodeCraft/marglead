<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $auth = getAuthUserContext();
    $status = trim($_GET['status'] ?? 'pending');
    $req_assigned = trim($_GET['assigned_to'] ?? '');
    
    // SECURITY ENFORCEMENT: Non-admin users are strictly restricted to their own assigned followups
    $assigned_to = '';
    if (!$auth['isAdmin'] && !empty($auth['name'])) {
        $assigned_to = $auth['name'];
    } elseif (!empty($req_assigned) && strtolower($req_assigned) !== 'all') {
        $assigned_to = $req_assigned;
    }
    
    $where = ["f.status = ?"];
    $params = [$status];

    if (!empty($assigned_to)) {
        $where[] = "f.assigned_to LIKE ?";
        $params[] = '%' . $assigned_to . '%';
    }

    $whereClause = "WHERE " . implode(" AND ", $where);

    $stmt = $pdo->prepare("
        SELECT f.*, l.name as lead_name, l.company, l.phone 
        FROM followups f 
        LEFT JOIN leads l ON f.lead_id = l.id 
        $whereClause 
        ORDER BY f.scheduled_at ASC
    ");
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendJsonResponse([
        'success' => true,
        'count' => count($followups),
        'followups' => $followups
    ]);
} elseif ($method === 'POST') {
    $input = getJsonInput();
    $action = trim($input['action'] ?? '');
    
    if ($action === 'complete') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE followups SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
        sendJsonResponse(['success' => true, 'message' => 'Followup marked completed.']);
    } else {
        $lead_id = trim($input['lead_id'] ?? '');
        $action_type = trim($input['action_type'] ?? 'call');
        $scheduled_at = trim($input['scheduled_at'] ?? date('Y-m-d H:i:s', strtotime('+1 day')));
        
        if (empty($lead_id)) {
            sendJsonResponse(['success' => false, 'message' => 'Lead ID is required.'], 400);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, status, assigned_to)
            VALUES (?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $lead_id,
            $action_type,
            $scheduled_at,
            $input['remarks'] ?? null,
            $input['assigned_to'] ?? 'Amit S.'
        ]);
        
        sendJsonResponse(['success' => true, 'message' => 'Followup scheduled successfully.'], 201);
    }
}
