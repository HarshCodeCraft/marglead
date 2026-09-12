<?php
/**
 * Marg CRM - Support Ticket History Endpoint
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$tId = trim($_GET['ticket_id'] ?? '');
$clientId = trim($_GET['client_id'] ?? $_GET['lead_id'] ?? '');
$phone = trim($_GET['phone'] ?? '');

if (empty($tId) && empty($clientId) && empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Ticket ID or Client ID is required.', 'history' => [], 'client_tickets' => []]);
    exit;
}

if (!$db_connected || !$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection offline.', 'history' => [], 'client_tickets' => []]);
    exit;
}

try {
    $currentTicket = null;
    if (!empty($tId)) {
        $stmtT = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
        $stmtT->execute([$tId]);
        $currentTicket = $stmtT->fetch(PDO::FETCH_ASSOC);
        
        if ($currentTicket) {
            if (empty($clientId) && !empty($currentTicket['lead_id'])) {
                $clientId = $currentTicket['lead_id'];
            }
            if (empty($phone) && !empty($currentTicket['phone'])) {
                $phone = $currentTicket['phone'];
            }
        }
    }

    // 1. Fetch History for requested ticket
    $history = [];
    if (!empty($tId)) {
        $stmtH = $pdo->prepare("SELECT * FROM support_ticket_history WHERE ticket_id = ? ORDER BY created_at ASC, id ASC");
        $stmtH->execute([$tId]);
        $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Fetch all tickets for this Client ID / Phone
    $clientTickets = [];
    $whereParts = [];
    $params = [];

    if (!empty($clientId)) {
        $whereParts[] = "(lead_id = ?)";
        $params[] = $clientId;
    }

    if (!empty($phone)) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $last10 = substr($cleanPhone, -10);
        if (!empty($last10)) {
            $whereParts[] = "(phone LIKE ? OR callback_number LIKE ?)";
            $params[] = '%' . $last10;
            $params[] = '%' . $last10;
        }
    }

    if (!empty($whereParts)) {
        $sql = "SELECT id, customer_name, lead_id, phone, callback_number, product, subject, problem, resolution, status, priority, assigned_to, date_created, due_date FROM support_tickets WHERE " . implode(" OR ", $whereParts) . " ORDER BY date_created DESC, id DESC";
        $stmtCT = $pdo->prepare($sql);
        $stmtCT->execute($params);
        $clientTickets = $stmtCT->fetchAll(PDO::FETCH_ASSOC);
    }

    // Attach log count to each client ticket
    if (!empty($clientTickets)) {
        $tIds = array_column($clientTickets, 'id');
        $inClause = implode(',', array_fill(0, count($tIds), '?'));
        try {
            $countStmt = $pdo->prepare("SELECT ticket_id, COUNT(*) as log_count FROM support_ticket_history WHERE ticket_id IN ($inClause) GROUP BY ticket_id");
            $countStmt->execute($tIds);
            $counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($clientTickets as &$ct) {
                $ct['log_count'] = (int)($counts[$ct['id']] ?? 0);
            }
            unset($ct);
        } catch (Throwable $eC) {}
    }

    echo json_encode([
        'status' => 'success',
        'history' => $history,
        'client_tickets' => $clientTickets,
        'client_id' => $clientId,
        'active_ticket_id' => $tId
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'history' => [], 'client_tickets' => []]);
}
exit;
