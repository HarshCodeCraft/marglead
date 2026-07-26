<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'pending');
    
    $stmt = $pdo->prepare("
        SELECT f.*, l.name as lead_name, l.company, l.phone 
        FROM followups f 
        LEFT JOIN leads l ON f.lead_id = l.id 
        WHERE f.status = ? 
        ORDER BY f.scheduled_at ASC
    ");
    $stmt->execute([$status]);
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
