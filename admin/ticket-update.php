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

                $updateMsg .= "\nThank you for choosing ABC Software.";

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
