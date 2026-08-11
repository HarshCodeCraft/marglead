<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$auth = getAuthUserContext();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'scheduled');
    $lead_id = trim($_GET['lead_id'] ?? '');

    $where = [];
    $params = [];

    if (!empty($status) && $status !== 'all') {
        $where[] = "d.status = ?";
        $params[] = $status;
    }

    if (!empty($lead_id)) {
        $where[] = "d.lead_id = ?";
        $params[] = $lead_id;
    }

    if (!$auth['isAdmin'] && !empty($auth['name'])) {
        $where[] = "d.engineer LIKE ?";
        $params[] = '%' . $auth['name'] . '%';
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT d.*, l.name as lead_name, l.company, l.phone, l.email 
        FROM demos d 
        LEFT JOIN leads l ON d.lead_id = l.id 
        $whereSql 
        ORDER BY d.scheduled_at ASC
    ");
    $stmt->execute($params);
    $demos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse([
        'success' => true,
        'count' => count($demos),
        'demos' => $demos
    ]);
} elseif ($method === 'POST') {
    $input = getJsonInput();
    $action = trim($input['action'] ?? '');

    if ($action === 'complete') {
        $id = trim($input['id'] ?? '');
        $rating = (int)($input['rating'] ?? 5);
        $feedback = trim($input['feedback'] ?? 'Demo completed successfully');

        $stmt = $pdo->prepare("UPDATE demos SET status = 'completed', rating = ?, feedback = ? WHERE id = ?");
        $stmt->execute([$rating, $feedback, $id]);

        sendJsonResponse(['success' => true, 'message' => 'Product Demo marked completed.']);
    } else {
        // Schedule New Product Demo
        $lead_id = trim($input['lead_id'] ?? '');
        $scheduled_at = trim($input['scheduled_at'] ?? date('Y-m-d H:i:s', strtotime('+1 day 11:00')));
        $mode = trim($input['mode'] ?? 'Online');
        $engineer = trim($input['engineer'] ?? ($auth['name'] ?: 'Amit Sen'));

        if (empty($lead_id)) {
            sendJsonResponse(['success' => false, 'message' => 'Lead ID is required.'], 400);
        }

        $demoId = 'DM-' . rand(1000, 9999);

        $stmt = $pdo->prepare("
            INSERT INTO demos (id, lead_id, scheduled_at, mode, engineer, status, rating, feedback)
            VALUES (?, ?, ?, ?, ?, 'scheduled', NULL, ?)
        ");
        $stmt->execute([
            $demoId,
            $lead_id,
            $scheduled_at,
            $mode,
            $engineer,
            $input['feedback'] ?? 'Marg ERP Software Demo Scheduled'
        ]);

        // Add lead timeline entry
        $tStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
        $tStmt->execute([$lead_id, $auth['name'] ?: 'Mobile App', "Scheduled " . $mode . " demo for " . date('d M h:i A', strtotime($scheduled_at))]);

        sendJsonResponse([
            'success' => true,
            'message' => 'Software Demo scheduled successfully.',
            'demo_id' => $demoId
        ], 201);
    }
}
