<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$auth = getAuthUserContext();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user_name = $auth['name'];
    $isAdmin = $auth['isAdmin'];

    // 1. Fetch unread system notifications
    $whereNotif = [];
    $paramsNotif = [];

    if (!$isAdmin && !empty($user_name)) {
        $whereNotif[] = "(user_id = ? OR role = ? OR role = 'All')";
        $paramsNotif[] = $user_name;
        $paramsNotif[] = $auth['role'];
    }

    $whereNotifSql = !empty($whereNotif) ? "WHERE " . implode(" AND ", $whereNotif) : "";
    $stmtNotif = $pdo->prepare("SELECT * FROM notifications $whereNotifSql ORDER BY created_at DESC LIMIT 15");
    $stmtNotif->execute($paramsNotif);
    $notifications = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

    // 2. Build live exact time reminders for today's followups & demos
    $todayStr = date('Y-m-d');
    $reminders = [];

    // Followups scheduled for today or overdue
    $fSql = "SELECT f.*, l.name as lead_name, l.company, l.phone, l.email 
             FROM followups f 
             LEFT JOIN leads l ON f.lead_id = l.id 
             WHERE f.status = 'pending'";

    $fParams = [];
    if (!$isAdmin && !empty($user_name)) {
        $fSql .= " AND f.assigned_to LIKE ?";
        $fParams[] = '%' . $user_name . '%';
    }
    $fSql .= " ORDER BY f.scheduled_at ASC LIMIT 10";

    $fStmt = $pdo->prepare($fSql);
    $fStmt->execute($fParams);
    $followups = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($followups as $f) {
        $schedTime = strtotime($f['scheduled_at']);
        $isToday = (date('Y-m-d', $schedTime) === $todayStr);
        $isOverdue = ($schedTime < time() && !$isToday);

        $reminders[] = [
            'id' => 'f-' . $f['id'],
            'ref_id' => $f['id'],
            'type' => 'followup',
            'action_type' => $f['action_type'],
            'title' => ($isOverdue ? '⚠️ OVERDUE: ' : ($isToday ? '⏰ TODAY: ' : '📅 ')) . strtoupper($f['action_type']) . ' REMINDER',
            'message' => 'Followup with ' . ($f['lead_name'] ?: $f['lead_id']) . ' (' . ($f['company'] ?: 'Client') . ')',
            'scheduled_at' => $f['scheduled_at'],
            'formatted_time' => date('h:i A', $schedTime),
            'formatted_date' => date('d M Y', $schedTime),
            'lead_name' => $f['lead_name'] ?: $f['company'] ?: $f['lead_id'],
            'phone' => $f['phone'],
            'email' => $f['email'],
            'is_overdue' => $isOverdue,
            'is_today' => $isToday
        ];
    }

    // 3. Product Demos scheduled for today or upcoming
    try {
        $dSql = "SELECT d.*, l.name as lead_name, l.company, l.phone 
                 FROM demos d 
                 LEFT JOIN leads l ON d.lead_id = l.id 
                 WHERE d.status = 'scheduled'";
        $dParams = [];
        if (!$isAdmin && !empty($user_name)) {
            $dSql .= " AND d.engineer LIKE ?";
            $dParams[] = '%' . $user_name . '%';
        }
        $dSql .= " ORDER BY d.scheduled_at ASC LIMIT 10";

        $dStmt = $pdo->prepare($dSql);
        $dStmt->execute($dParams);
        $demos = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($demos as $d) {
            $schedTime = strtotime($d['scheduled_at']);
            $isToday = (date('Y-m-d', $schedTime) === $todayStr);
            $isOverdue = ($schedTime < time() && !$isToday);

            $reminders[] = [
                'id' => 'd-' . $d['id'],
                'ref_id' => $d['id'],
                'type' => 'demo',
                'action_type' => 'demo',
                'title' => ($isOverdue ? '⚠️ OVERDUE DEMO: ' : ($isToday ? '💻 TODAY DEMO: ' : '💻 UPCOMING DEMO: ')) . $d['mode'] . ' Demo',
                'message' => 'Marg ERP Software Demo for ' . ($d['lead_name'] ?: $d['lead_id']),
                'scheduled_at' => $d['scheduled_at'],
                'formatted_time' => date('h:i A', $schedTime),
                'formatted_date' => date('d M Y', $schedTime),
                'lead_name' => $d['lead_name'] ?: $d['lead_id'],
                'phone' => $d['phone'],
                'is_overdue' => $isOverdue,
                'is_today' => $isToday
            ];
        }
    } catch (Exception $ex) {
        // Ignore if demos table missing or empty
    }

    sendJsonResponse([
        'success' => true,
        'unread_count' => count($reminders) + count($notifications),
        'notifications' => $notifications,
        'reminders' => $reminders
    ]);
} elseif ($method === 'POST') {
    $input = getJsonInput();
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        $title = trim($input['title'] ?? 'System Reminder');
        $message = trim($input['message'] ?? '');
        $type = trim($input['type'] ?? 'info');
        
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$input['user_id'] ?? null, $input['role'] ?? 'All', $title, $message, $type]);
        
        sendJsonResponse(['success' => true, 'message' => 'Notification created.']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Invalid action.'], 400);
    }
}
