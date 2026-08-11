<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $lead_id = trim($_GET['id'] ?? '');
    
    if (!empty($lead_id)) {
        // Fetch single lead detail
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lead) {
            sendJsonResponse(['success' => false, 'message' => 'Lead not found.'], 404);
        }
        
        // Fetch lead timeline
        $timelineStmt = $pdo->prepare("SELECT * FROM timeline WHERE lead_id = ? ORDER BY log_time DESC");
        $timelineStmt->execute([$lead_id]);
        $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse([
            'success' => true,
            'lead' => $lead,
            'timeline' => $timeline
        ]);
    } else {
        $auth = getAuthUserContext();
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $priority = trim($_GET['priority'] ?? '');
        $req_assigned = trim($_GET['assigned_to'] ?? '');

        // SECURITY ENFORCEMENT: Non-admin users are strictly restricted to their own assigned data
        $assigned_to = '';
        if (!$auth['isAdmin'] && !empty($auth['name'])) {
            $assigned_to = $auth['name'];
        } elseif (!empty($req_assigned) && strtolower($req_assigned) !== 'all') {
            $assigned_to = $req_assigned;
        }
        
        $where = [];
        $params = [];
        
        if (!empty($search)) {
            $where[] = "(name LIKE ? OR company LIKE ? OR phone LIKE ? OR city LIKE ? OR id LIKE ?)";
            $st = '%' . $search . '%';
            for ($i = 0; $i < 5; $i++) $params[] = $st;
        }
        
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
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $sql = "SELECT * FROM leads $whereClause ORDER BY created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse([
            'success' => true,
            'count' => count($leads),
            'leads' => $leads
        ]);
    }
} elseif ($method === 'POST') {
    $input = getJsonInput();
    
    // Check if this is an update request or new lead request
    $lead_id = trim($input['id'] ?? '');
    $action = trim($input['action'] ?? '');
    
    if ($action === 'update_status' && !empty($lead_id)) {
        $new_status = trim($input['status'] ?? '');
        $actor = trim($input['actor'] ?? 'Mobile App User');
        
        $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $lead_id]);
        
        // Log timeline
        $tStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
        $tStmt->execute([$lead_id, $actor, "Updated lead status to " . $new_status]);
        
        sendJsonResponse(['success' => true, 'message' => 'Lead status updated successfully.']);
    } else {
        // Create New Lead
        $name = trim($input['name'] ?? '');
        $company = trim($input['company'] ?? '');
        $phone = trim($input['phone'] ?? '');
        
        if (empty($name) || empty($company) || empty($phone)) {
            sendJsonResponse(['success' => false, 'message' => 'Name, Company, and Phone are required.'], 400);
        }
        
        // Generate Lead ID LD-XXXX
        $randId = 'LD-' . rand(1000, 9999);
        
        $stmt = $pdo->prepare("
            INSERT INTO leads (id, name, contact_person, company, email, phone, city, state, address, gst, source, priority, tags, status, assigned_to, budget, products, enq_for, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $randId,
            $name,
            $input['contact_person'] ?? $name,
            $company,
            $input['email'] ?? null,
            $phone,
            $input['city'] ?? 'New Delhi',
            $input['state'] ?? 'Delhi',
            $input['address'] ?? null,
            $input['gst'] ?? null,
            $input['source'] ?? 'Mobile App',
            $input['priority'] ?? 'warm',
            $input['tags'] ?? 'Mobile',
            $input['status'] ?? 'new',
            $input['assigned_to'] ?? 'Amit S.',
            $input['budget'] ?? 0.00,
            $input['products'] ?? 'Marg ERP Pro',
            $input['enq_for'] ?? 'Marg ERP Pro',
            $input['remarks'] ?? 'Created from Mobile App'
        ]);
        
        // Add timeline
        $tStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
        $tStmt->execute([$randId, $input['actor'] ?? 'Mobile App User', 'Lead captured via Mobile APK']);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Lead created successfully.',
            'lead_id' => $randId
        ], 201);
    }
}
