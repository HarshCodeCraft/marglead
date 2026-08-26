<?php
/**
 * Marg ERP CRM - Real-Time Future Reminder API
 * Checks upcoming followups and demos scheduled for the current time onwards.
 * Triggers exactly twice per task: 5 minutes before scheduled time and at the exact scheduled time.
 */
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$auth = getAuthUserContext();
$assigned_user = '';
if (!$auth['isAdmin'] && !empty($auth['name'])) {
    $assigned_user = $auth['name'];
}

$reminders = [];

try {
    // 1. Query future / upcoming pending followups (from 1 minute ago onwards)
    $where = ["f.status = 'pending'", "f.scheduled_at >= (NOW() - INTERVAL 1 MINUTE)"];
    $params = [];

    if (!empty($assigned_user)) {
        $where[] = "f.assigned_to LIKE ?";
        $params[] = '%' . $assigned_user . '%';
    }

    $whereClause = "WHERE " . implode(" AND ", $where);

    $stmtF = $pdo->prepare("
        SELECT f.id, f.lead_id, f.action_type, f.scheduled_at, f.remarks, f.assigned_to,
               l.name as lead_name, l.company, l.phone, 'followup' as reminder_kind
        FROM followups f
        LEFT JOIN leads l ON f.lead_id = l.id
        $whereClause
        ORDER BY f.scheduled_at ASC
    ");
    $stmtF->execute($params);
    $followups = $stmtF->fetchAll(PDO::FETCH_ASSOC);

    // 2. Query future / upcoming scheduled demos
    $whereD = ["d.status = 'scheduled'", "d.scheduled_at >= (NOW() - INTERVAL 1 MINUTE)"];
    $paramsD = [];

    if (!empty($assigned_user)) {
        $whereD[] = "d.engineer LIKE ?";
        $paramsD[] = '%' . $assigned_user . '%';
    }

    $whereDClause = "WHERE " . implode(" AND ", $whereD);

    $stmtD = $pdo->prepare("
        SELECT d.id, d.lead_id, CONCAT('Product Demo (', d.mode, ')') as action_type, d.scheduled_at, d.feedback as remarks, d.engineer as assigned_to,
               l.name as lead_name, l.company, l.phone, 'demo' as reminder_kind
        FROM demos d
        LEFT JOIN leads l ON d.lead_id = l.id
        $whereDClause
        ORDER BY d.scheduled_at ASC
    ");
    $stmtD->execute($paramsD);
    $demos = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    $allPending = array_merge($followups, $demos);
    $nowSec = time();

    foreach ($allPending as $item) {
        $schedSec = strtotime($item['scheduled_at']);
        $diffSec = $schedSec - $nowSec; // Positive = future (seconds until scheduled)

        // Pop 1: 5 minutes before scheduled time (between 30s and 330s in the future)
        if ($diffSec > 30 && $diffSec <= 330) {
            $item['alert_type'] = '5min_warning';
            $item['mins_left'] = max(1, ceil($diffSec / 60));
            $reminders[] = $item;
        }
        // Pop 2: At exact scheduled time (between -60s and +30s)
        elseif ($diffSec >= -60 && $diffSec <= 30) {
            $item['alert_type'] = 'due_now';
            $item['mins_left'] = 0;
            $reminders[] = $item;
        }
    }

    sendJsonResponse([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'count' => count($reminders),
        'reminders' => $reminders
    ]);
} catch (PDOException $e) {
    sendJsonResponse(['success' => false, 'message' => 'Query error: ' . $e->getMessage()], 500);
}
