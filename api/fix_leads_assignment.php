<?php
/**
 * Friendly AI Solution - 1-Click Lead Re-assignment Tool
 * Matches lead 'assigned_to' (or email) against 'users' table by Email or Name.
 * Re-assigns matching leads to User's Name, and un-matched leads to Super Admin.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$db = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] ? $GLOBALS['pdo'] : (isset($pdo) && $pdo ? $pdo : null);

if (!$db) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed. Please check MySQL server.']);
    exit;
}

try {
    // 1. Build Users Email & Name Lookup Cache
    $user_email_map = [];
    $user_name_map = [];
    $super_admin_name = 'Admin';

    $uStmt = $pdo->query("SELECT name, email, role FROM users WHERE status = 'Active'");
    $users_list = $uStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users_list as $u) {
        $uName = trim($u['name']);
        $uEmail = strtolower(trim($u['email']));

        if (!empty($uEmail)) {
            $user_email_map[$uEmail] = $uName;
        }
        if (!empty($uName)) {
            $user_name_map[strtolower($uName)] = $uName;
        }

        // Set super admin fallback
        if (strtolower($u['role']) === 'admin' || strtolower($u['role']) === 'super admin' || strtolower($u['role']) === 'superadmin') {
            $super_admin_name = $uName;
        }
    }

    // 2. Fetch all existing leads
    $lStmt = $pdo->query("SELECT id, name, email, phone, assigned_to FROM leads");
    $leads = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $reassigned_users = 0;
    $reassigned_admin = 0;
    $total_processed = 0;

    $updLead = $pdo->prepare("UPDATE leads SET assigned_to = ? WHERE id = ?");
    $updFup = $pdo->prepare("UPDATE followups SET assigned_to = ? WHERE lead_id = ?");

    foreach ($leads as $l) {
        $total_processed++;
        $leadId = $l['id'];
        $rawAssignee = strtolower(trim($l['assigned_to'] ?? ''));
        $targetAssignee = null;

        // Try matching by assigned_to email or name
        if (!empty($rawAssignee)) {
            if (isset($user_email_map[$rawAssignee])) {
                $targetAssignee = $user_email_map[$rawAssignee];
            } elseif (isset($user_name_map[$rawAssignee])) {
                $targetAssignee = $user_name_map[$rawAssignee];
            }
        }

        // Fallback: If not found in assigned_to, check if lead's own email matches a user email
        if (empty($targetAssignee) && !empty($l['email'])) {
            $leadEmail = strtolower(trim($l['email']));
            if (isset($user_email_map[$leadEmail])) {
                $targetAssignee = $user_email_map[$leadEmail];
            }
        }

        // Final Fallback: Assign to Super Admin
        if (empty($targetAssignee)) {
            $targetAssignee = $super_admin_name;
            $reassigned_admin++;
        } else {
            $reassigned_users++;
        }

        // Execute update if assigned_to changed
        if ($l['assigned_to'] !== $targetAssignee) {
            $updLead->execute([$targetAssignee, $leadId]);
            $updFup->execute([$targetAssignee, $leadId]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully re-assigned {$total_processed} leads! ({$reassigned_users} matched to specific User Names, {$reassigned_admin} assigned to Super Admin '{$super_admin_name}').",
        'total_processed' => $total_processed,
        'reassigned_to_users' => $reassigned_users,
        'reassigned_to_admin' => $reassigned_admin,
        'super_admin_name' => $super_admin_name
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
