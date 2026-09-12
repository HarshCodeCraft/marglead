<?php
/**
 * Marg CRM - Process Ticket Update Action
 * 
 * Updates ticket status, technician assignment, notes, and optionally
 * sends a status update WhatsApp message to the customer.
 */

require_once __DIR__ . '/../api/whatsapp-api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ticket-list.php");
    exit;
}

$id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status         = trim($_POST['status'] ?? 'Open');
$assignedTo     = trim($_POST['assigned_to'] ?? '');
$internalNotes  = trim($_POST['internal_notes'] ?? '');
$notifyCustomer = isset($_POST['notify_customer']) && $_POST['notify_customer'] == '1';

if ($id > 0 && $pdo) {
    try {
        // Fetch current ticket
        $stmtFetch = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmtFetch->execute([$id]);
        $ticket = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        if ($ticket) {
            // Update ticket in database
            $stmtUp = $pdo->prepare("UPDATE tickets SET status = ?, assigned_to = ?, internal_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmtUp->execute([$status, $assignedTo, $internalNotes, $id]);

            // Sync with support_tickets table if exists
            try {
                $syncStmt = $pdo->prepare("UPDATE support_tickets SET status = ?, assigned_to = ? WHERE id = ?");
                $syncStmt->execute([strtolower($status), $assignedTo, $ticket['ticket_number']]);
            } catch (Throwable $eSync) {}

            // Log activity history in support_ticket_history
            $adminUser = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
            $adminRole = !empty($_SESSION['user_role']) ? $_SESSION['user_role'] : 'Admin';
            
            if ($ticket['assigned_to'] !== $assignedTo && !empty($assignedTo)) {
                try {
                    $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'transferred', ?, ?, ?, NOW())");
                    $stmtH->execute([$ticket['ticket_number'], $adminUser, $adminRole, "Ticket transferred / assigned from '{$ticket['assigned_to']}' to '{$assignedTo}'"]);
                } catch (Throwable $eH) {}
            }

            if (!empty($internalNotes)) {
                $isClosed = in_array(strtolower($status), ['resolved', 'closed']);
                $actType = $isClosed ? 'resolution' : 'work_note';
                $noteLabel = $isClosed ? 'Solution / Resolution' : 'Work Remark / Update';
                try {
                    $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmtH->execute([$ticket['ticket_number'], $actType, $adminUser, $adminRole, "{$noteLabel}: {$internalNotes}"]);
                } catch (Throwable $eH) {}
            }

            if (strtolower($ticket['status']) !== strtolower($status)) {
                $isReopen = in_array(strtolower($ticket['status']), ['resolved', 'closed']) && in_array(strtolower($status), ['open', 'in_progress', 'pending']);
                $isClose = in_array(strtolower($status), ['resolved', 'closed']);
                $actType = $isReopen ? 'reopened' : ($isClose ? 'resolved' : 'status_change');
                $statusNote = $isReopen ? "Ticket REOPENED from '" . ucfirst($ticket['status']) . "' to '" . ucfirst($status) . "'" : ($isClose ? "Ticket marked as " . ucfirst($status) : "Status updated from '" . ucfirst($ticket['status']) . "' to '" . ucfirst($status) . "'");
                try {
                    $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $stmtH->execute([$ticket['ticket_number'], $actType, $adminUser, $adminRole, $statusNote]);
                } catch (Throwable $eH) {}
            }

            // Notify Customer via WhatsApp if requested
            if ($notifyCustomer && !empty($ticket['mobile'])) {
                $whatsapp = new WhatsAppAPI($pdo);
                $updateMsg = "ℹ️ *Ticket Status Update*\n\n" .
                             "*Ticket Number:* {$ticket['ticket_number']}\n" .
                             "*Current Status:* *{$status}*\n";

                if (!empty($assignedTo)) {
                    $updateMsg .= "*Assigned Engineer:* {$assignedTo}\n";
                }
                if (!empty($internalNotes)) {
                    $updateMsg .= "\n*Update Note:*\n{$internalNotes}\n";
                }

                $updateMsg .= "\nThank you for choosing Marg Soft Solution.";

                $whatsapp->sendText($ticket['mobile'], $updateMsg);
            }

            $_SESSION['flash_msg'] = "Ticket {$ticket['ticket_number']} updated successfully!";
        }
    } catch (Throwable $e) {
        write_log('error', "Failed updating ticket ID $id: " . $e->getMessage());
        $_SESSION['flash_msg'] = "Error updating ticket: " . $e->getMessage();
    }
}

header("Location: ticket-view.php?id=" . $id);
exit;
