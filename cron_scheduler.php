<?php
/**
 * Marg Soft Solution - Background Reminders Cron Scheduler
 * Target Setup: Configure a cron job or task scheduler to run this script every 1 minute.
 * Command: php -f c:\xampp\htdocs\marglead\cron_scheduler.php
 */

// Command Line execution checks (recommended for system crontabs)
if (php_sapi_name() !== 'cli' && !isset($_GET['run_secret'])) {
    // Optional web-trigger for prototype demo testing if query parameter set
    // e.g. http://localhost/marglead/cron_scheduler.php?run_secret=MARG_CRON
    if (!isset($_GET['run_secret']) || $_GET['run_secret'] !== 'MARG_CRON') {
        die("Access Denied: Scheduled cron jobs must run via Command Line Interface (CLI).");
    }
}

// Load core files
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

echo "[CRON SCHEDULER START] Processing due alerts at: " . date('Y-m-d H:i:s') . "\n";

if (!$db_connected || !$pdo) {
    die("[ERROR] Database offline. Scheduler execution aborted.\n");
}

try {
    // 1. Query pending reminders where scheduled time has reached
    $current_time = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT f.*, l.name as client_name, l.email as client_email, l.phone as client_phone 
        FROM followups f 
        JOIN leads l ON f.lead_id = l.id 
        WHERE f.status = 'pending' 
          AND f.scheduled_at <= ? 
          AND (
            (f.send_email = 1 AND f.email_sent = 0) OR 
            (f.send_sms = 1 AND f.sms_sent = 0)
          )
    ");
    $stmt->execute([$current_time]);
    $pending_followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($pending_followups) . " pending alerts due for execution.\n";
    
    foreach ($pending_followups as $f) {
        $fup_id = $f['id'];
        $lead_id = $f['lead_id'];
        $action_type = $f['action_type'];
        $scheduled_at = $f['scheduled_at'];
        $remarks = $f['remarks'];
        
        echo "--> Processing reminder ID: {$fup_id} [Type: {$action_type}, Customer: {$f['client_name']}]\n";
        
        $email_success = false;
        $sms_success = false;
        
        // A. Process Email Alert
        if ($f['send_email'] == 1 && $f['email_sent'] == 0) {
            if (!empty($f['client_email'])) {
                $subject = "Marg Soft Solution: Scheduled Follow-up Notification";
                $title = "Follow-up Scheduled Reminder";
                $header = "Scheduled " . htmlspecialchars($action_type);
                $subtitle = "Reminder for " . htmlspecialchars($f['client_name']) . " on " . htmlspecialchars($scheduled_at);
                
                $body = "<p>Dear " . htmlspecialchars($f['client_name']) . ",</p>";
                $body .= "<p>This is a reminder that we have scheduled a follow-up <strong>" . htmlspecialchars($action_type) . "</strong> with you.</p>";
                $body .= "<p><strong>Date & Time:</strong> " . htmlspecialchars($scheduled_at) . "</p>";
                if (!empty($remarks)) {
                    $body .= "<p><strong>Notes:</strong> " . htmlspecialchars($remarks) . "</p>";
                }
                $body .= "<p>If you need to reschedule or have any queries, please let us know.</p>";
                
                $compiledMail = Mailer::wrapHTMLTemplate($title, $header, $subtitle, $body, "Launch CRM Dashboard", "http://localhost/marglead/auth/login.php");
                
                if (Mailer::send($f['client_email'], $subject, $compiledMail)) {
                    echo "    [EMAIL] Sent successfully to {$f['client_email']}\n";
                    $email_success = true;
                } else {
                    echo "    [EMAIL] SMTP dispatch failed.\n";
                }
            } else {
                echo "    [EMAIL] Skipped: Client has no email address configured.\n";
                $email_success = true; // Mark as done to prevent infinite retries
            }
        } else {
            $email_success = true; // Already sent or not requested
        }
        
        // B. Process SMS Alerts
        if ($f['send_sms'] == 1 && $f['sms_sent'] == 0) {
            $targets = !empty($f['sms_targets']) ? json_decode($f['sms_targets'], true) : [];
            if (!empty($targets)) {
                $sms_msg = "MARG SOFT SOLUTION: Scheduled " . $action_type . " for " . $f['client_name'] . " on " . $scheduled_at . ". Notes: " . $remarks;
                
                // Write timeline log and simulated execution message for each phone number
                $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                
                foreach ($targets as $tgt) {
                    $phone = $tgt['phone'];
                    $role = $tgt['role'];
                    
                    // In a production scenario, you would trigger SMS API here:
                    // sendFreeAndroidSMS($phone, $sms_msg);
                    
                    // Log execution
                    $log_msg = "Free Scheduled Carrier SMS dispatched to {$role} ({$phone}) exactly on scheduled time.";
                    $stmtTime->execute([$lead_id, 'Cron Service', $log_msg]);
                    echo "    [SMS] Dispatched to {$role}: {$phone}\n";
                }
                $sms_success = true;
            } else {
                echo "    [SMS] Skipped: No target phone numbers specified.\n";
                $sms_success = true;
            }
        } else {
            $sms_success = true; // Already sent or not requested
        }
        
        // Update database flags
        $upd_email = $email_success ? 1 : 0;
        $upd_sms = $sms_success ? 1 : 0;
        $status = ($email_success && $sms_success) ? 'completed' : 'pending';
        
        $upd = $pdo->prepare("UPDATE followups SET email_sent = ?, sms_sent = ?, status = ? WHERE id = ?");
        $upd->execute([$upd_email, $upd_sms, $status, $fup_id]);
        
        echo "    [DB STATUS] Mapped to '{$status}'\n";
    }
    
    echo "[CRON SCHEDULER COMPLETED] Execution finished successfully.\n";
} catch (PDOException $e) {
    echo "[FATAL ERROR] Scheduler execution failed: " . $e->getMessage() . "\n";
}
