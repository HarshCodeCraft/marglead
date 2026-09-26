<?php

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/api/whatsapp-api.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';

// Permission Check
if (!hasAccess('training', $user_role)) {
    header('Location: index.php?page=dashboard');
    exit;
}

$canCreate = hasActionAccess('can_create');
$canEdit = hasActionAccess('can_edit');
$canDelete = hasActionAccess('can_delete');

$flash_msg = '';
$flash_type = '';

// Helper to send WhatsApp if phone is present
function sendTrainingWhatsApp($phone, $message, $pdo) {
    if (empty($phone) || empty($pdo)) return false;
    try {
        $whatsapp = new WhatsAppAPI($pdo);
        return $whatsapp->sendText($phone, $message);
    } catch (Throwable $e) {
        write_log('error', "Training WA dispatch error: " . $e->getMessage());
        return false;
    }
}

// --------------------------------------------------------------------------
// 1. Action Handlers (Create, Log Connect Update, Record Day Session, Reschedule, Close)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // A. Create / Allocate Training
    if ($_POST['action'] === 'create_training' && $canCreate) {
        $lead_id = trim($_POST['lead_id'] ?? '');
        $customer = trim($_POST['customer_name'] ?? '');
        $trainer = trim($_POST['trainer'] ?? '');
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $mode = $_POST['mode'] ?? 'Online (Google Meet)';
        $total_hours = intval($_POST['total_hours'] ?? 6);
        $total_days = intval($_POST['total_days'] ?? 3);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $product = trim($_POST['product'] ?? 'Marg ERP 9+');
        $renewal_date = !empty($_POST['renewal_date']) ? $_POST['renewal_date'] : null;
        $address = trim($_POST['address'] ?? '');
        $topics = trim($_POST['topics'] ?? 'Basic & Advanced Operations');
        $remarks = trim($_POST['remarks'] ?? '');

        if (!empty($customer) && !empty($trainer) && !empty($scheduled_at)) {
            if ($db_connected && $pdo) {
                try {
                    // Match trainer phone from team_agents
                    $trainerPhone = '';
                    try {
                        $stAg = $pdo->prepare("SELECT whatsapp_phone FROM team_agents WHERE name LIKE ? AND status = 'Active' LIMIT 1");
                        $stAg->execute(['%' . trim(explode('(', $trainer)[0]) . '%']);
                        $agRow = $stAg->fetch(PDO::FETCH_ASSOC);
                        if ($agRow) $trainerPhone = $agRow['whatsapp_phone'];
                    } catch (Throwable $e) {}

                    $trId = 'TRN-' . date('md') . '-' . rand(10, 99);
                    $stmtIns = $pdo->prepare("
                        INSERT INTO training_sessions (
                            id, lead_id, customer, trainer, trainer_phone, dropped_by,
                            scheduled_at, mode, hours_completed, total_hours, current_day, total_days,
                            status, connect_status, phone, email, product, renewal_date, address, topics, remarks, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, 0, ?, 0, ?,
                            'scheduled', 'Pending Connect', ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    $stmtIns->execute([
                        $trId, $lead_id, $customer, $trainer, $trainerPhone, $user_name,
                        $scheduled_at, $mode, $total_hours, $total_days,
                        $phone, $email, $product, $renewal_date, $address, $topics, $remarks
                    ]);

                    // Insert timeline log if lead_id present
                    if (!empty($lead_id) && str_starts_with($lead_id, 'LD-')) {
                        try {
                            $tlStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                            $tlStmt->execute([$lead_id, $user_name, "Scheduled {$total_days}-Day Marg Training ({$mode}) with trainer {$trainer} on " . date('M d, Y h:i A', strtotime($scheduled_at))]);
                        } catch (Throwable $e) {}
                    }

                    // WhatsApp Alert to Customer
                    if (!empty($phone)) {
                        $tplDataTrCust = get_system_notification_template($pdo, 'training_scheduled_customer', [
                            'client_name'    => $customer,
                            'ticket_id'      => $trId,
                            'software_type'  => $product,
                            'trainer_name'   => $trainer,
                            'trainer_phone'  => $trainerPhone,
                            'training_mode'  => $mode,
                            'scheduled_at'   => date('d-m-Y h:i A', strtotime($scheduled_at)),
                            'total_days'     => $total_days,
                            'total_hours'    => $total_hours
                        ]);

                        if (!$tplDataTrCust['found'] || $tplDataTrCust['is_active']) {
                            $custMsg = !empty($tplDataTrCust['whatsapp_body']) ? $tplDataTrCust['whatsapp_body'] : (
                                "Namaste *{$customer}*\n\n" .
                                "Aapki *Marg ERP Product Training* allocate ho gayi hai.\n\n" .
                                "*Training Details:*\n" .
                                "• *Ticket ID:* `{$trId}`\n" .
                                "• *Software:* *{$product}*\n" .
                                "• *Assigned Trainer:* *{$trainer}*\n" .
                                (!empty($trainerPhone) ? "• *Trainer Helpline:* +91 {$trainerPhone}\n" : "") .
                                "• *Mode:* *{$mode}*\n" .
                                "• *Scheduled Date:* " . date('d-m-Y h:i A', strtotime($scheduled_at)) . "\n" .
                                "• *Total Plan:* {$total_days} Days ({$total_hours} Hours)\n\n" .
                                "Hamare Marg software trainer aapse training session ke liye jaldi connect karenge.\n\n" .
                                "Helpdesk: +91 93050 45727\n*Marg Soft Solution*"
                            );
                            sendTrainingWhatsApp($phone, $custMsg, $pdo);
                        }
                    }

                    // WhatsApp Alert to Trainer
                    if (!empty($trainerPhone)) {
                        $crmPortalUrl = (defined('BASE_URL') ? BASE_URL : 'https://friendlyaisolution.com/') . "index.php?page=training";
                        $tplDataTrainer = get_system_notification_template($pdo, 'training_allocated_trainer', [
                            'trainer_name'  => $trainer,
                            'created_by'    => $user_name,
                            'ticket_id'     => $trId,
                            'client_name'   => $customer,
                            'client_phone'  => $phone,
                            'software_type' => $product,
                            'training_mode' => $mode,
                            'scheduled_at'  => date('d-m-Y h:i A', strtotime($scheduled_at)),
                            'address'       => $address,
                            'notes'         => $remarks,
                            'crm_link'      => $crmPortalUrl
                        ]);

                        if (!$tplDataTrainer['found'] || $tplDataTrainer['is_active']) {
                            $trMsg = !empty($tplDataTrainer['whatsapp_body']) ? $tplDataTrainer['whatsapp_body'] : (
                                "Hi *{$trainer}*,\n\n" .
                                "New *Marg ERP Training* allocated to you by *{$user_name}*.\n\n" .
                                "• *Ticket ID:* `{$trId}`\n" .
                                "• *Customer:* {$customer} (+91 {$phone})\n" .
                                "• *Software:* {$product}\n" .
                                "• *Mode:* {$mode}\n" .
                                "• *Scheduled Date:* " . date('d-m-Y h:i A', strtotime($scheduled_at)) . "\n" .
                                (!empty($address) ? "• *Address:* {$address}\n" : "") .
                                (!empty($remarks) ? "• *Remarks:* {$remarks}\n" : "") .
                                "\nPlease connect with the customer promptly.\n\n" .
                                "Portal: " . $crmPortalUrl
                            );
                            sendTrainingWhatsApp($trainerPhone, $trMsg, $pdo);
                        }
                    }

                    $flash_msg = "Trainer allocated & training ticket {$trId} created successfully for \"{$customer}\"!";
                    $flash_type = "success";
                } catch (PDOException $e) {
                    $flash_msg = "Error creating training allocation: " . $e->getMessage();
                    $flash_type = "danger";
                }
            }
        } else {
            $flash_msg = "Please provide Client Name, Trainer, and Scheduled Target Date.";
            $flash_type = "danger";
        }
    }

    // B. Log Employee Connect / Call / Reach-out Update
    elseif ($_POST['action'] === 'log_employee_connect' && $canEdit) {
        $trId = trim($_POST['training_id'] ?? '');
        $empName = trim($_POST['employee_name'] ?? $user_name);
        $connectStatus = trim($_POST['connect_status'] ?? 'Connected - Training Active');
        $connectChannel = trim($_POST['connect_channel'] ?? 'Phone Call');
        $updateNotes = trim($_POST['update_notes'] ?? '');
        $nextFollowup = trim($_POST['next_followup'] ?? '');

        if (!empty($trId) && !empty($updateNotes) && $db_connected && $pdo) {
            try {
                $stmtTr = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
                $stmtTr->execute([$trId]);
                $trData = $stmtTr->fetch(PDO::FETCH_ASSOC);

                if ($trData) {
                    $topicsText = "[{$connectChannel}] {$connectStatus}";
                    $stmtLog = $pdo->prepare("
                        INSERT INTO training_daily_sessions (
                            training_id, day_number, session_date, duration_minutes,
                            topics_covered, trainer_notes, status, update_type, logged_by, connect_status, created_at
                        ) VALUES (?, ?, CURDATE(), 0, ?, ?, 'completed', 'call_connect', ?, ?, NOW())
                    ");
                    $curDay = (int)($trData['current_day'] ?? 0);
                    $stmtLog->execute([
                        $trId, $curDay, $topicsText, $updateNotes, $empName, $connectStatus
                    ]);

                    $updSql = "UPDATE training_sessions SET 
                                connect_status = ?, 
                                last_connect_by = ?, 
                                last_connect_at = NOW(), 
                                last_connect_notes = ?";
                    $updParams = [$connectStatus, $empName, $updateNotes];

                    if ($connectStatus === 'Connected - Training Active' && $trData['status'] === 'scheduled') {
                        $updSql .= ", status = 'active'";
                    } elseif ($connectStatus === 'Call Rescheduled by Client' || $connectStatus === 'Rescheduled') {
                        $updSql .= ", status = 'rescheduled'";
                    }

                    if (!empty($nextFollowup)) {
                        $updSql .= ", scheduled_at = ?";
                        $updParams[] = $nextFollowup;
                    }

                    $updSql .= " WHERE id = ?";
                    $updParams[] = $trId;

                    $stmtUpd = $pdo->prepare($updSql);
                    $stmtUpd->execute($updParams);

                    if (!empty($trData['lead_id']) && str_starts_with($trData['lead_id'], 'LD-')) {
                        try {
                            $tlStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                            $tlStmt->execute([$trData['lead_id'], $empName, "Training Update [{$connectStatus}]: {$updateNotes}"]);
                        } catch (Throwable $e) {}
                    }

                    $flash_msg = "Employee reach-out update logged for {$trData['customer']}!";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error logging update: " . $e->getMessage();
                $flash_type = "danger";
            }
        } else {
            $flash_msg = "Please enter connect details and remarks note.";
            $flash_type = "danger";
        }
    }

    // C. Record Daily Training Session (Day 1, Day 2, Day 3...)
    elseif ($_POST['action'] === 'record_daily_session' && $canEdit) {
        $trId = trim($_POST['training_id'] ?? '');
        $dayNumber = intval($_POST['day_number'] ?? 1);
        $sessionDate = trim($_POST['session_date'] ?? date('Y-m-d'));
        $durationMin = intval($_POST['duration_minutes'] ?? 60);
        $topicsCovered = trim($_POST['topics_covered'] ?? '');
        $trainerNotes = trim($_POST['trainer_notes'] ?? '');
        $nextScheduledAt = trim($_POST['next_scheduled_at'] ?? '');

        if (!empty($trId) && $db_connected && $pdo) {
            try {
                $stmtTr = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
                $stmtTr->execute([$trId]);
                $trData = $stmtTr->fetch(PDO::FETCH_ASSOC);

                if ($trData) {
                    $stmtDaily = $pdo->prepare("
                        INSERT INTO training_daily_sessions (
                            training_id, day_number, session_date, duration_minutes,
                            topics_covered, trainer_notes, status, update_type, logged_by, connect_status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 'completed', 'session', ?, 'Connected - Training Active', NOW())
                    ");
                    $stmtDaily->execute([
                        $trId, $dayNumber, $sessionDate, $durationMin,
                        $topicsCovered, $trainerNotes, $user_name
                    ]);

                    $addedHours = round($durationMin / 60, 1);
                    $newHours = (int)$trData['hours_completed'] + (int)ceil($addedHours);
                    $totalDays = (int)($trData['total_days'] ?? 3);

                    $updateSql = "UPDATE training_sessions SET 
                                    current_day = ?, 
                                    hours_completed = ?, 
                                    status = 'active', 
                                    connect_status = 'Connected - Training Active',
                                    last_connect_by = ?,
                                    last_connect_at = NOW(),
                                    last_connect_notes = ?,
                                    last_session_at = NOW()";
                    $params = [
                        $dayNumber, 
                        $newHours, 
                        $user_name, 
                        "Day {$dayNumber} Session: " . $topicsCovered
                    ];

                    if (!empty($nextScheduledAt)) {
                        $updateSql .= ", scheduled_at = ?";
                        $params[] = $nextScheduledAt;
                    }
                    $updateSql .= " WHERE id = ?";
                    $params[] = $trId;

                    $stmtUpd = $pdo->prepare($updateSql);
                    $stmtUpd->execute($params);

                    // Send WhatsApp Notification to Customer
                    if (!empty($trData['phone'])) {
                        $custPhone = $trData['phone'];
                        $custName = $trData['customer'];
                        $trainerName = $trData['trainer'];

                        $sessMsg = "Namaste *{$custName}*\n\n" .
                                   "Aapka aaj ka *Marg ERP Training Session (Day {$dayNumber})* successfully complete ho gaya hai.\n\n" .
                                   "*Session Summary:*\n" .
                                   "• *Day:* Day {$dayNumber} of {$totalDays}\n" .
                                   "• *Trainer:* {$trainerName}\n" .
                                   (!empty($topicsCovered) ? "• *Topics Covered:* {$topicsCovered}\n" : "") .
                                   "• *Duration:* {$durationMin} minutes\n" .
                                   (!empty($nextScheduledAt) ? "• *Next Session Scheduled:* " . date('d-m-Y h:i A', strtotime($nextScheduledAt)) . "\n" : "") .
                                   "\nAapke Marg ERP regular usage progress ke liye hamari team tatpar hai.\n\n" .
                                   "Helpdesk: +91 93050 45727\n*Marg Soft Solution*";

                        sendTrainingWhatsApp($custPhone, $sessMsg, $pdo);
                    }

                    $flash_msg = "Day {$dayNumber} session recorded successfully & client notified on WhatsApp!";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error recording session: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // D. Reschedule Training
    elseif ($_POST['action'] === 'reschedule_training' && $canEdit) {
        $trId = trim($_POST['training_id'] ?? '');
        $newScheduledAt = trim($_POST['scheduled_at'] ?? '');
        $rescheduleReason = trim($_POST['rescheduled_reason'] ?? 'Customer requested reschedule');

        if (!empty($trId) && !empty($newScheduledAt) && $db_connected && $pdo) {
            try {
                $stmtTr = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
                $stmtTr->execute([$trId]);
                $trData = $stmtTr->fetch(PDO::FETCH_ASSOC);

                if ($trData) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE training_sessions SET 
                            scheduled_at = ?, 
                            status = 'rescheduled', 
                            rescheduled_reason = ?,
                            connect_status = 'Call Rescheduled by Client',
                            last_connect_by = ?,
                            last_connect_at = NOW(),
                            last_connect_notes = ?
                        WHERE id = ?
                    ");
                    $stmtUpd->execute([
                        $newScheduledAt, 
                        $rescheduleReason, 
                        $user_name, 
                        "Rescheduled to " . date('d-m-Y h:i A', strtotime($newScheduledAt)) . " Reason: " . $rescheduleReason,
                        $trId
                    ]);

                    try {
                        $stmtDailyRes = $pdo->prepare("
                            INSERT INTO training_daily_sessions (
                                training_id, day_number, session_date, duration_minutes,
                                topics_covered, trainer_notes, status, update_type, logged_by, connect_status, created_at
                            ) VALUES (?, ?, CURDATE(), 0, ?, ?, 'rescheduled', 'reschedule', ?, 'Call Rescheduled by Client', NOW())
                        ");
                        $stmtDailyRes->execute([
                            $trId,
                            (int)($trData['current_day'] ?? 0),
                            "Rescheduled to " . date('d-m-Y h:i A', strtotime($newScheduledAt)),
                            $rescheduleReason,
                            $user_name
                        ]);
                    } catch (Throwable $e) {}

                    $formattedDate = date('d-m-Y h:i A', strtotime($newScheduledAt));

                    // Send WhatsApp to Client
                    if (!empty($trData['phone'])) {
                        $reschedMsg = "Namaste *{$trData['customer']}*\n\n" .
                                      "Aapki *Marg ERP Product Training* reschedule kar di gayi hai:\n\n" .
                                      "*Nayi Training Date & Time:* *{$formattedDate}*\n" .
                                      "• *Trainer:* {$trData['trainer']}\n" .
                                      "• *Mode:* {$trData['mode']}\n" .
                                      (!empty($rescheduleReason) ? "• *Reason:* {$rescheduleReason}\n" : "") .
                                      "\nHamare trainer naye scheduled samay par aapse connect karenge. Thank you.\n\n" .
                                      "Helpdesk: +91 93050 45727\n*Marg Soft Solution*";
                        sendTrainingWhatsApp($trData['phone'], $reschedMsg, $pdo);
                    }

                    // Alert Trainer
                    if (!empty($trData['trainer_phone'])) {
                        $trAlert = "*Training Rescheduled Alert*\n\n" .
                                   "• *Ticket:* `{$trId}`\n" .
                                   "• *Client:* {$trData['customer']} (+91 {$trData['phone']})\n" .
                                   "• *New Schedule:* *{$formattedDate}*\n" .
                                   "• *Reason:* {$rescheduleReason}\n\n" .
                                   "Please update your schedule accordingly.";
                        sendTrainingWhatsApp($trData['trainer_phone'], $trAlert, $pdo);
                    }

                    $flash_msg = "Training ticket {$trId} rescheduled to " . date('M d, Y h:i A', strtotime($newScheduledAt)) . "!";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error rescheduling training: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // E. Close Training Ticket (Completed - No Certificates)
    elseif ($_POST['action'] === 'close_training' && $canEdit) {
        $trId = trim($_POST['training_id'] ?? '');
        $completionNotes = trim($_POST['completion_notes'] ?? 'Marg ERP Training successfully completed');

        if (!empty($trId) && $db_connected && $pdo) {
            try {
                $stmtTr = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
                $stmtTr->execute([$trId]);
                $trData = $stmtTr->fetch(PDO::FETCH_ASSOC);

                if ($trData) {
                    $totalHours = (int)($trData['total_hours'] ?? 6);
                    $totalDays = (int)($trData['total_days'] ?? 3);
                    
                    $stmtUpd = $pdo->prepare("
                        UPDATE training_sessions SET 
                            status = 'completed', 
                            current_day = ?, 
                            hours_completed = ?, 
                            connect_status = 'Training Completed',
                            last_connect_by = ?,
                            last_connect_at = NOW(),
                            last_connect_notes = ?,
                            remarks = CONCAT(IFNULL(remarks,''), ' | Closed: ', ?) 
                        WHERE id = ?
                    ");
                    $stmtUpd->execute([$totalDays, $totalHours, $user_name, $completionNotes, $completionNotes, $trId]);

                    try {
                        $stmtDailyClose = $pdo->prepare("
                            INSERT INTO training_daily_sessions (
                                training_id, day_number, session_date, duration_minutes,
                                topics_covered, trainer_notes, status, update_type, logged_by, connect_status, created_at
                            ) VALUES (?, ?, CURDATE(), 0, 'Training Ticket Closed / Completed', ?, 'completed', 'completion', ?, 'Training Completed', NOW())
                        ");
                        $stmtDailyClose->execute([
                            $trId,
                            $totalDays,
                            $completionNotes,
                            $user_name
                        ]);
                    } catch (Throwable $e) {}

                    // Send WhatsApp Completion Message to Customer
                    if (!empty($trData['phone'])) {
                        $closeMsg = "Namaste *{$trData['customer']}*\n\n" .
                                    "Aapki *Marg ERP Software Product Training* successfully complete ho gayi hai.\n\n" .
                                    "*Training Completion Summary:*\n" .
                                    "• *Ticket ID:* `{$trId}`\n" .
                                    "• *Total Days Completed:* {$totalDays} Days\n" .
                                    "• *Assigned Trainer:* {$trData['trainer']}\n" .
                                    "• *Software:* " . (!empty($trData['product']) ? $trData['product'] : 'Marg ERP') . "\n" .
                                    "• *Status:* *Training Completed*\n\n" .
                                    "Marg software ke daily billing, inventory aur GST work me kisi bhi help ke liye hamari technical team hamesha aapke sath hai.\n\n" .
                                    "Technical Helpdesk: +91 93050 45727\n\n" .
                                    "Thank you for choosing *Marg Soft Solution*.";
                        sendTrainingWhatsApp($trData['phone'], $closeMsg, $pdo);
                    }

                    $flash_msg = "Training ticket {$trId} marked as completed & closed successfully!";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error closing training: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// --------------------------------------------------------------------------
// 2. Fetch Clients & Leads for Autocomplete (Marg Client Directory)
// --------------------------------------------------------------------------
$db_clients = [];
$db_clients[] = [
    'id' => 'TRIAL-NEW',
    'name' => 'Trial Version Client (New Marg Prospect)',
    'phone' => '+91 98000 11122',
    'email' => 'prospect@trialclient.com',
    'product' => 'Marg ERP 9+ Trial',
    'renewal_date' => date('Y-m-d', strtotime('+30 days')),
    'address' => 'Marg Trial Version License Workspace Premises',
    'source' => 'Trial Prospect'
];

if ($db_connected && $pdo) {
    try {
        $dirStmt = $pdo->query("
            SELECT customer_id as id, party_name as name, mobile as phone, email, 
                   software_type as product, due_on as renewal_date, address, 
                   'Client Directory' as source 
            FROM client_directory 
            WHERE party_name IS NOT NULL AND party_name != '' 
            ORDER BY id DESC LIMIT 500
        ");
        while ($row = $dirStmt->fetch(PDO::FETCH_ASSOC)) {
            $db_clients[] = $row;
        }
    } catch (PDOException $e) { }

    try {
        $leadsStmt = $pdo->query("
            SELECT id, name, phone, email, enq_for as product, created_at as renewal_date, 
                   address, 'CRM Lead' as source 
            FROM leads 
            WHERE name IS NOT NULL AND name != '' 
            ORDER BY created_at DESC LIMIT 500
        ");
        while ($row = $leadsStmt->fetch(PDO::FETCH_ASSOC)) {
            $db_clients[] = $row;
        }
    } catch (PDOException $e) { }
}
$clients_json = json_encode($db_clients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// --------------------------------------------------------------------------
// 3. Fetch Trainers List (from team_agents & users)
// --------------------------------------------------------------------------
$trainers = ['Harsh Saini (Technical)', 'Sahil Savita (Sales/Trainer)', 'Prakash Raj (Senior Trainer)', 'Sonal Mehta (Technical Lead)'];
if ($db_connected && $pdo) {
    try {
        $agStmt = $pdo->query("SELECT name, department FROM team_agents WHERE status = 'Active' ORDER BY name ASC");
        $agList = $agStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($agList)) {
            $trainers = [];
            foreach ($agList as $ag) {
                $trainers[] = $ag['name'] . ' (' . $ag['department'] . ')';
            }
        }
    } catch (PDOException $e) { }
}

// --------------------------------------------------------------------------
// 4. Fetch Training Sessions Data & Apply Search Filters
// --------------------------------------------------------------------------
$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$trainer_filter = trim($_GET['trainer'] ?? '');
$trainings = [];

if ($db_connected && $pdo) {
    try {
        $sql = "SELECT * FROM training_sessions WHERE 1=1";
        $params = [];

        if (!empty($search_query)) {
            $sql .= " AND (id LIKE ? OR customer LIKE ? OR trainer LIKE ? OR phone LIKE ? OR product LIKE ? OR lead_id LIKE ?)";
            $sq = '%' . $search_query . '%';
            $params = array_fill(0, 6, $sq);
        }

        if (!empty($status_filter)) {
            if ($status_filter === 'completed') {
                $sql .= " AND status IN ('completed', 'certified')";
            } else {
                $sql .= " AND status = ?";
                $params[] = $status_filter;
            }
        }

        if (!empty($trainer_filter)) {
            $sql .= " AND trainer LIKE ?";
            $params[] = '%' . $trainer_filter . '%';
        }

        $sql .= " ORDER BY created_at DESC";
        $stmtT = $pdo->prepare($sql);
        $stmtT->execute($params);
        $trainings = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trainings = [];
    }
}

// --------------------------------------------------------------------------
// 5. Fetch Daily Session & Connect History (Map by Training ID)
// --------------------------------------------------------------------------
$daily_sessions_by_tr = [];
if ($db_connected && $pdo) {
    try {
        $stmtDailyList = $pdo->query("SELECT * FROM training_daily_sessions ORDER BY training_id, id ASC");
        while ($dRow = $stmtDailyList->fetch(PDO::FETCH_ASSOC)) {
            $daily_sessions_by_tr[$dRow['training_id']][] = $dRow;
        }
    } catch (PDOException $e) { }
}
$daily_json = json_encode($daily_sessions_by_tr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// KPI Counters
$totalCount = count($trainings);
$scheduledCount = 0;
$inProgressCount = 0;
$completedCount = 0;
$pendingConnectCount = 0;

foreach ($trainings as $t) {
    $st = $t['status'] ?? 'scheduled';
    $cn = strtolower($t['connect_status'] ?? 'pending connect');
    if ($st === 'completed' || $st === 'certified') {
        $completedCount++;
    } elseif ($st === 'active' || $st === 'rescheduled') {
        $inProgressCount++;
    } else {
        $scheduledCount++;
    }

    if (empty($t['last_connect_at']) && ($cn === 'pending connect' || empty($cn))) {
        $pendingConnectCount++;
    }
}
?>

<div class="training-container" style="max-width: 1400px; margin: 0 auto; padding-bottom: 3rem;">
    <!-- Flash Notification -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> mb-4 p-3 flex align-center gap-2" style="border-radius: var(--border-radius-sm);">
            <i data-lucide="<?php echo $flash_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" style="width: 18px; height: 18px;"></i>
            <span class="text-sm font-semibold"><?php echo htmlspecialchars($flash_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Standard CRM Page Header (Aligned Right Button, No Line Break) -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <div class="flex align-center gap-2 text-xs text-muted mb-1">
                <span>Customer Helpdesk</span>
                <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
                <span class="font-semibold text-main">Marg ERP Training Desk</span>
            </div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--text-main);" class="m-0">
                Customer Training Registry
            </h2>
            <p class="text-muted text-sm m-0">Manage client software trainings, multi-day milestones, and live employee reach-out accountability.</p>
        </div>

        <div class="flex gap-2 flex-wrap align-center">
            <?php if ($canCreate): ?>
                <button type="button" class="btn btn-primary text-sm flex align-center gap-2" onclick="openTrainingAllocationModal()">
                    <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                    <span>Allocate Trainer</span>
                </button>
            <?php endif; ?>
        </div>
    </div>


    <!-- Standard CRM KPI Row (4 Balanced Equal Columns matching Support module) -->
    <div class="mb-6" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
        <a href="index.php?page=training" class="card p-4 flex align-center gap-3.5 transition-all" style="text-decoration: none; border: 1px solid <?php echo empty($status_filter) ? 'var(--primary)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background-color: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="laptop" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.72rem;">Total Trainings</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--text-main); line-height: 1.2; margin-top: 2px;"><?php echo $totalCount; ?></span>
            </div>
        </a>

        <a href="index.php?page=training&status=scheduled" class="card p-4 flex align-center gap-3.5 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'scheduled') ? '#0284c7' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background-color: rgba(2, 132, 199, 0.1); color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="calendar" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.72rem;">Scheduled / New</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: #0284c7; line-height: 1.2; margin-top: 2px;"><?php echo $scheduledCount; ?></span>
            </div>
        </a>

        <a href="index.php?page=training&status=active" class="card p-4 flex align-center gap-3.5 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'active' || $status_filter === 'rescheduled') ? 'var(--warning)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background-color: var(--warning-light); color: var(--warning); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="phone-forwarded" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.72rem;">In-Progress / Active</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--warning); line-height: 1.2; margin-top: 2px;"><?php echo $inProgressCount; ?></span>
            </div>
        </a>

        <a href="index.php?page=training&status=completed" class="card p-4 flex align-center gap-3.5 transition-all" style="text-decoration: none; border: 1px solid <?php echo ($status_filter === 'completed') ? 'var(--success)' : 'var(--border-color)'; ?>; background-color: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="width: 44px; height: 44px; border-radius: 10px; background-color: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="check-circle-2" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-bold" style="text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.72rem;">Completed / Closed</span>
                <span class="text-2xl font-extrabold" style="font-family: var(--font-heading); color: var(--success); line-height: 1.2; margin-top: 2px;"><?php echo $completedCount; ?></span>
            </div>
        </a>
    </div>

    <!-- Search & Filters Toolbar (Strict Single-Row Grid matching modules/support.php) -->
    <div class="card p-4 mb-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md); box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <form action="index.php" method="GET" class="flex flex-col gap-3">
            <input type="hidden" name="page" value="training">

            <div class="flex justify-between align-center border-bottom pb-2.5" style="border-bottom: 1px solid var(--border-color);">
                <div class="flex align-center gap-2">
                    <i data-lucide="filter" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    <h3 class="m-0 text-sm font-bold" style="font-family: var(--font-heading); color: var(--text-main);">Filter Training Sessions</h3>
                </div>
                <?php if (!empty($search_query) || !empty($status_filter) || !empty($trainer_filter)): ?>
                    <a href="index.php?page=training" class="btn btn-secondary text-xs text-danger flex align-center gap-1" style="padding: 0.25rem 0.65rem; border-radius: 6px;">
                        <i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i>
                        <span>Clear All Filters</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="grid" style="grid-template-columns: 2.2fr 1.2fr 1.2fr 110px; gap: 0.85rem; align-items: end; margin-top: 0.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 0.4rem; display: block; color: var(--text-muted);">Search Records</label>
                    <div style="position: relative;">
                        <input type="text" name="search" class="form-control text-xs" placeholder="Client Name, License ID, Phone, Marg Software..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 2.2rem; height: 38px; border-radius: var(--border-radius-sm);">
                        <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--text-muted);"></i>
                    </div>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 0.4rem; display: block; color: var(--text-muted);">Training Status</label>
                    <select name="status" class="form-control text-xs" style="height: 38px; border-radius: var(--border-radius-sm);">
                        <option value="">All Statuses</option>
                        <option value="scheduled" <?php echo ($status_filter === 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>In-Progress</option>
                        <option value="rescheduled" <?php echo ($status_filter === 'rescheduled') ? 'selected' : ''; ?>>Rescheduled</option>
                        <option value="completed" <?php echo ($status_filter === 'completed' || $status_filter === 'certified') ? 'selected' : ''; ?>>Completed / Closed</option>
                    </select>
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 0.4rem; display: block; color: var(--text-muted);">Assigned Trainer</label>
                    <select name="trainer" class="form-control text-xs" style="height: 38px; border-radius: var(--border-radius-sm);">
                        <option value="">All Trainers</option>
                        <?php foreach ($trainers as $trOpt): ?>
                            <option value="<?php echo htmlspecialchars($trOpt); ?>" <?php echo ($trainer_filter === $trOpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($trOpt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary text-xs flex align-center justify-center gap-1.5" style="width: 100%; height: 38px; padding: 0.5rem; border-radius: var(--border-radius-sm); font-weight: 600;">
                        <i data-lucide="filter" style="width: 14px; height: 14px;"></i>
                        <span>Apply</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Training Registry Table Card (Identical structure to modules/support.php) -->
    <div class="card p-0" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div class="p-3.5 px-4 flex justify-between align-center" style="border-bottom: 1px solid var(--border-color); background-color: var(--border-card);">
            <div class="flex align-center gap-2">
                <span class="text-sm font-bold text-main">Customer Training & Connect Registry:</span>
                <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700; font-size: 0.78rem; padding: 3px 9px;">
                    <?php echo count($trainings); ?> Records
                </span>
            </div>
            <?php if ($pendingConnectCount > 0): ?>
                <span class="badge flex align-center gap-1.5" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); font-size: 0.74rem; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
                    <i data-lucide="alert-triangle" style="width: 13px; height: 13px;"></i>
                    <span><?php echo $pendingConnectCount; ?> Pending Contact</span>
                </span>
            <?php endif; ?>
        </div>

        <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table class="table" style="font-size: 0.85rem; width: 100%; min-width: 1100px; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; background-color: var(--bg-app); border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 13%;">Ticket ID</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 22%;">Marg Client Details</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 16%;">Trainer & Mode</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 14%;">Progress</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 19%;">Employee Connect & Live Update</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; width: 7%;">Status</th>
                        <th style="padding: 0.85rem 1rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 700; text-align: right; width: 9%; padding-right: 1.25rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trainings)): ?>
                        <tr>
                            <td colspan="7" class="text-center p-5 text-muted">
                                <i data-lucide="laptop" style="width: 36px; height: 36px; margin: 0 auto 0.5rem auto; color: var(--text-muted);"></i>
                                <div style="font-weight: 600; color: var(--text-main);">No Marg training sessions found matching criteria.</div>
                                <div class="text-xs text-muted mb-3">Drop <code>Training &lt;number&gt; &lt;trainer&gt;</code> on WhatsApp bot or click below.</div>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openTrainingAllocationModal()">
                                    <i data-lucide="plus-circle" style="width: 14px; height: 14px;"></i>
                                    <span>Allocate First Trainer</span>
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trainings as $tr): ?>
                            <?php 
                            $trJson = htmlspecialchars(json_encode($tr), ENT_QUOTES, 'UTF-8');
                            $isOffline = (stripos($tr['mode'] ?? '', 'offline') !== false || stripos($tr['mode'] ?? '', 'site') !== false);
                            $curDay = (int)($tr['current_day'] ?? 0);
                            $totDays = (int)($tr['total_days'] ?? 3);
                            if ($totDays <= 0) $totDays = 3;
                            $pct = min(100, round(($curDay / $totDays) * 100));

                            $cleanPhone = preg_replace('/[^0-9]/', '', $tr['phone'] ?? '');
                            $displayPhone = preg_replace('/^91/', '', $cleanPhone);
                            if (strlen($displayPhone) !== 10) $displayPhone = $cleanPhone;

                            $connectStatus = trim($tr['connect_status'] ?? 'Pending Connect');
                            $isClosed = ($tr['status'] === 'completed' || $tr['status'] === 'certified');

                            // Resolve display customer name
                            $custNameDisplay = trim($tr['customer'] ?? '');
                            if (empty($custNameDisplay) || is_numeric($custNameDisplay)) {
                                $custNameDisplay = !empty($tr['customer']) ? 'Marg Client #' . $tr['customer'] : 'Marg Client';
                            }
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color); vertical-align: middle;">
                                
                                <!-- Column 1: Ticket ID (Structured vertical flex) -->
                                <td style="padding: 0.85rem 1rem;">
                                    <div class="flex flex-col gap-1">
                                        <span class="font-bold text-primary font-mono text-xs"><?php echo htmlspecialchars($tr['id']); ?></span>
                                        <span class="text-xs text-muted font-mono flex align-center gap-1" style="font-size: 0.72rem;">
                                            <i data-lucide="calendar" style="width: 11px; height: 11px; display: inline-block;"></i>
                                            <span><?php echo !empty($tr['created_at']) ? date('d M Y', strtotime($tr['created_at'])) : date('d M Y'); ?></span>
                                        </span>
                                        <?php if (!empty($tr['dropped_by'])): ?>
                                            <span class="text-xs text-muted" style="font-size: 0.68rem; line-height: 1.2;">
                                                via <?php echo htmlspecialchars($tr['dropped_by']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Column 2: Rich Marg Client Information -->
                                <td style="padding: 0.85rem 1rem;">
                                    <div class="flex align-center gap-1.5 mb-1">
                                        <strong class="text-sm font-bold text-main" style="cursor: pointer;" onclick='openClientDetailsModal(<?php echo $trJson; ?>)' title="View Marg Client Profile">
                                            <?php echo htmlspecialchars($custNameDisplay); ?>
                                        </strong>
                                        <button type="button" class="btn-icon p-0" style="background: none; border: none; cursor: pointer; color: var(--primary);" title="View Marg License Profile" onclick='openClientDetailsModal(<?php echo $trJson; ?>)'>
                                            <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>

                                    <div class="flex align-center gap-2 mb-1.5 text-xs">
                                        <span class="text-muted font-mono font-semibold">ID: <?php echo htmlspecialchars($tr['lead_id'] ?: 'NA'); ?></span>
                                        <?php if (!empty($cleanPhone)): ?>
                                            <span class="text-muted">•</span>
                                            <a href="https://wa.me/91<?php echo $cleanPhone; ?>" target="_blank" class="font-mono font-bold text-success flex align-center gap-1" style="text-decoration: none;">
                                                <i data-lucide="message-circle" style="width: 12px; height: 12px;"></i>
                                                <span>+91 <?php echo htmlspecialchars($displayPhone); ?></span>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="flex align-center gap-1.5 flex-wrap">
                                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.68rem; font-weight: 600; padding: 2px 8px;">
                                            <i data-lucide="box" style="width: 10px; height: 10px; display: inline-block; vertical-align: -1px; margin-right: 2px;"></i>
                                            <?php echo htmlspecialchars($tr['product'] ?: 'Marg ERP 9+'); ?>
                                        </span>
                                        <?php if (!empty($tr['address'])): ?>
                                            <span class="text-xs text-muted flex align-center gap-1" style="max-width: 170px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.72rem;" title="<?php echo htmlspecialchars($tr['address']); ?>">
                                                <i data-lucide="map-pin" style="width: 11px; height: 11px; flex-shrink: 0;"></i>
                                                <span><?php echo htmlspecialchars($tr['address']); ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Column 3: Trainer & Mode -->
                                <td style="padding: 0.85rem 1rem;">
                                    <div class="font-semibold text-sm text-main flex align-center gap-1.5 mb-1">
                                        <i data-lucide="user-check" style="width: 14px; height: 14px; color: var(--primary);"></i>
                                        <span><?php echo htmlspecialchars($tr['trainer']); ?></span>
                                    </div>
                                    <?php if (!empty($tr['trainer_phone'])): ?>
                                        <div class="text-xs font-mono text-muted mb-1.5 flex align-center gap-1">
                                            <i data-lucide="phone" style="width: 11px; height: 11px;"></i>
                                            <span>+91 <?php echo htmlspecialchars($tr['trainer_phone']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <?php if ($isOffline): ?>
                                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-size: 0.7rem; font-weight: 600; padding: 2px 8px;">
                                                <i data-lucide="building-2" style="width: 11px; height: 11px; display: inline-block; vertical-align: -1px; margin-right: 2px;"></i>
                                                Offline On-Site
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="--badge-bg: rgba(2, 132, 199, 0.1); --badge-color: #0284c7; font-size: 0.7rem; font-weight: 600; padding: 2px 8px;">
                                                <i data-lucide="globe" style="width: 11px; height: 11px; display: inline-block; vertical-align: -1px; margin-right: 2px;"></i>
                                                Online Remote
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Column 4: Multi-Day Progress with Clear Visible Track -->
                                <td style="padding: 0.85rem 1rem;">
                                    <div style="min-width: 110px; max-width: 135px;">
                                        <div class="flex align-center justify-between mb-1">
                                            <span class="badge" style="--badge-bg: rgba(147, 51, 234, 0.1); --badge-color: #9333ea; font-size: 0.7rem; font-weight: 700; padding: 2px 7px;">
                                                Day <?php echo $curDay; ?> / <?php echo $totDays; ?>
                                            </span>
                                            <span class="font-mono text-xs text-muted font-semibold" style="font-size: 0.72rem;"><?php echo $tr['hours_completed']; ?>h / <?php echo $tr['total_hours']; ?>h</span>
                                        </div>
                                        <div style="background: rgba(147, 51, 234, 0.12); height: 6px; border-radius: 6px; overflow: hidden; width: 100%; margin-bottom: 4px;">
                                            <div style="background: linear-gradient(90deg, #9333ea, #a855f7); width: <?php echo max(6, $pct); ?>%; height: 100%; border-radius: 6px; transition: width 0.3s ease;"></div>
                                        </div>
                                        <div class="font-mono text-xs text-muted flex align-center gap-1" style="font-size: 0.7rem;">
                                            <i data-lucide="clock" style="width: 10px; height: 10px; color: var(--text-muted);"></i>
                                            <span><?php echo !empty($tr['scheduled_at']) ? date('d M, h:i A', strtotime($tr['scheduled_at'])) : '-'; ?></span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Column 5: Employee Connect & Live Update -->
                                <td style="padding: 0.85rem 1rem;">
                                    <div class="flex align-center gap-1.5 mb-1.5">
                                        <?php 
                                        if (empty($tr['last_connect_at']) && ($connectStatus === 'Pending Connect' || empty($connectStatus))) {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="alert-triangle" style="width: 12px; height: 12px;"></i> Not Connected Yet</span>';
                                        } elseif ($connectStatus === 'Connected - Training Active') {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="phone-call" style="width: 12px; height: 12px;"></i> Connected - Active</span>';
                                        } elseif ($connectStatus === 'Client Busy / Call Back') {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="clock" style="width: 12px; height: 12px;"></i> Call Back / Busy</span>';
                                        } elseif ($connectStatus === 'RNR (Ringing No Response)') {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: rgba(234, 88, 12, 0.1); --badge-color: #ea580c; font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="phone-off" style="width: 12px; height: 12px;"></i> Ringing No Response</span>';
                                        } elseif ($connectStatus === 'Call Rescheduled by Client' || $connectStatus === 'Rescheduled') {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: rgba(147, 51, 234, 0.1); --badge-color: #9333ea; font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="calendar" style="width: 12px; height: 12px;"></i> Rescheduled</span>';
                                        } elseif ($connectStatus === 'Training Completed') {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700; font-size: 0.72rem; padding: 3px 8px;"><i data-lucide="check-circle-2" style="width: 12px; height: 12px;"></i> Completed</span>';
                                        } else {
                                            echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700; font-size: 0.72rem; padding: 3px 8px;">' . htmlspecialchars($connectStatus) . '</span>';
                                        }
                                        ?>
                                        <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 2px 7px; font-size: 0.68rem; border-radius: 4px; height: 22px;" onclick='openEmployeeConnectModal(<?php echo $trJson; ?>)' title="Log Trainer Reach-out">
                                            <i data-lucide="edit-3" style="width: 10px; height: 10px;"></i>
                                            <span>Update</span>
                                        </button>
                                    </div>

                                    <?php if (!empty($tr['last_connect_notes'])): ?>
                                        <div style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 5px 8px; font-size: 0.75rem; max-width: 250px;">
                                            <div class="flex justify-between align-center text-muted font-mono" style="font-size: 0.68rem; margin-bottom: 2px;">
                                                <span>By: <strong class="text-main"><?php echo htmlspecialchars($tr['last_connect_by'] ?: $tr['trainer']); ?></strong></span>
                                                <span><?php echo !empty($tr['last_connect_at']) ? date('d M, h:i A', strtotime($tr['last_connect_at'])) : ''; ?></span>
                                            </div>
                                            <div class="text-main" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3;" title="<?php echo htmlspecialchars($tr['last_connect_notes']); ?>">
                                                <?php echo htmlspecialchars($tr['last_connect_notes']); ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-muted" style="font-size: 0.72rem;">Pending initial trainer contact</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Column 6: Overall Status -->
                                <td style="padding: 0.85rem 1rem;">
                                    <?php 
                                    if ($isClosed) {
                                        echo '<span class="badge flex align-center gap-1" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700; font-size: 0.72rem; padding: 4px 10px;"><i data-lucide="check" style="width: 12px; height: 12px;"></i> Completed</span>';
                                    } elseif ($tr['status'] === 'active') {
                                        echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-weight: 700; font-size: 0.72rem; padding: 4px 10px;">In-Progress</span>';
                                    } elseif ($tr['status'] === 'rescheduled') {
                                        echo '<span class="badge" style="--badge-bg: rgba(147, 51, 234, 0.1); --badge-color: #9333ea; font-weight: 700; font-size: 0.72rem; padding: 4px 10px;">Rescheduled</span>';
                                    } else {
                                        echo '<span class="badge" style="--badge-bg: rgba(2, 132, 199, 0.1); --badge-color: #0284c7; font-weight: 700; font-size: 0.72rem; padding: 4px 10px;">Scheduled</span>';
                                    }
                                    ?>
                                </td>

                                <!-- Column 7: Actions (Compact & Never Overflowing) -->
                                <td style="padding: 0.85rem 1rem; text-align: right; white-space: nowrap; padding-right: 1.25rem;">
                                    <div class="flex align-center justify-end gap-1.5">
                                        <!-- Connect Button -->
                                        <button type="button" class="btn btn-secondary text-xs flex align-center gap-1" style="padding: 4px 8px; font-size: 0.74rem; border-color: var(--primary); color: var(--primary); font-weight: 600;" onclick='openEmployeeConnectModal(<?php echo $trJson; ?>)' title="Log Trainer Reach-out">
                                            <i data-lucide="phone-call" style="width: 12px; height: 12px;"></i>
                                            <span>Connect</span>
                                        </button>

                                        <!-- Record Day Session Button -->
                                        <button type="button" class="btn text-xs flex align-center gap-1 font-bold" style="background: #10b981; color: #ffffff; border: none; padding: 4px 8px; font-size: 0.74rem; border-radius: var(--border-radius-sm);" onclick="openRecordDayModal('<?php echo htmlspecialchars(addslashes($tr['id'])); ?>', '<?php echo htmlspecialchars(addslashes($custNameDisplay)); ?>', <?php echo $curDay; ?>, <?php echo $totDays; ?>)" title="Record Day's Training Progress">
                                            <i data-lucide="play-circle" style="width: 12px; height: 12px;"></i>
                                            <span>Day <?php echo ($curDay + 1); ?></span>
                                        </button>

                                        <!-- Reschedule Icon Button -->
                                        <button type="button" class="btn-icon" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-card); color: var(--text-muted); cursor: pointer;" onclick="openRescheduleModal('<?php echo htmlspecialchars(addslashes($tr['id'])); ?>', '<?php echo htmlspecialchars(addslashes($custNameDisplay)); ?>', '<?php echo htmlspecialchars(addslashes($tr['scheduled_at'])); ?>')" title="Reschedule Training">
                                            <i data-lucide="calendar" style="width: 13px; height: 13px;"></i>
                                        </button>

                                        <!-- Interaction History Icon Button -->
                                        <button type="button" class="btn-icon" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-card); color: var(--text-muted); cursor: pointer;" onclick="openHistoryModal('<?php echo htmlspecialchars(addslashes($tr['id'])); ?>', '<?php echo htmlspecialchars(addslashes($custNameDisplay)); ?>')" title="View Interaction History">
                                            <i data-lucide="history" style="width: 13px; height: 13px;"></i>
                                        </button>

                                        <!-- Close / Complete Ticket Icon Button -->
                                        <?php if (!$isClosed): ?>
                                            <button type="button" class="btn-icon" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; background: var(--success-light); color: var(--success); cursor: pointer;" onclick="openCloseTrainingModal('<?php echo htmlspecialchars(addslashes($tr['id'])); ?>', '<?php echo htmlspecialchars(addslashes($custNameDisplay)); ?>')" title="Mark Training Completed">
                                                <i data-lucide="check-circle-2" style="width: 13px; height: 13px;"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: Schedule & Allocate Trainer -->
<!-- ========================================================================= -->
<div id="schedule-training-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 650px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="plus-circle" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                        Allocate Marg Trainer
                    </h3>
                    <span class="text-xs text-muted">Select client from Directory/Leads or enter manually to allocate trainer.</span>
                </div>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('schedule-training-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-4" action="index.php?page=training" method="POST" style="max-height: 540px; overflow-y: auto;">
            <input type="hidden" name="action" value="create_training">

            <!-- Client Info Box -->
            <div class="p-3.5" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
                <div class="flex justify-between align-center mb-3 pb-2 border-bottom" style="border-bottom: 1px solid var(--border-color);">
                    <h4 class="text-xs font-bold uppercase m-0 text-main" style="letter-spacing: 0.04em;">Client Details</h4>
                    <span class="text-xs text-muted">Auto-fills from Directory</span>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0" style="grid-column: span 2; position: relative;">
                        <label class="form-label text-xs font-bold text-main" style="margin-bottom: 0.35rem;">Search / Select Client *</label>
                        <select id="training-client-select-picker" class="form-control text-xs font-semibold mb-2" onchange="onTrainingSelectPickerChange(this)" style="height: 38px;">
                            <!-- Populated on DOM load -->
                        </select>
                        <div style="position: relative;">
                            <input type="text" id="training-client-search-input" class="form-control text-xs font-semibold" placeholder="Type firm name, license ID, phone to filter..." autocomplete="off" oninput="filterTrainingClientDropdown()" onfocus="showTrainingClientDropdown()" style="padding-right: 2rem; height: 38px;">
                            <i data-lucide="search" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                        </div>
                        <div id="training-client-dropdown-menu" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 999; max-height: 200px; overflow-y: auto; background-color: var(--bg-card); border: 1px solid var(--primary); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); margin-top: 4px;">
                        </div>
                        <input type="hidden" name="customer_name" id="trn-customer-name" required>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Marg License / Client ID</label>
                        <input type="text" name="lead_id" id="trn-client-id" class="form-control text-xs font-mono" placeholder="e.g. MG-9481" style="height: 38px;">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">WhatsApp Mobile *</label>
                        <input type="text" name="phone" id="trn-phone" class="form-control text-xs font-mono" placeholder="9876543210" required style="height: 38px;">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Marg Software Edition *</label>
                        <select name="product" id="trn-product" class="form-control text-xs font-semibold" required style="height: 38px;">
                            <option value="Marg ERP 9+">Marg ERP 9+</option>
                            <option value="Marg ERP Basic">Marg ERP Basic</option>
                            <option value="Marg ERP Silver">Marg ERP Silver</option>
                            <option value="Marg ERP Gold">Marg ERP Gold</option>
                            <option value="Marg Books">Marg Books</option>
                            <option value="Marg Cloud VPC">Marg Cloud VPC</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">License Renewal Date</label>
                        <input type="date" name="renewal_date" id="trn-renewal" class="form-control text-xs font-mono" style="height: 38px;">
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Client Address</label>
                        <input type="text" name="address" id="trn-address" class="form-control text-xs" placeholder="Shop / Office Address, City" style="height: 38px;">
                    </div>
                </div>
            </div>

            <!-- Training Parameters Box -->
            <div class="p-3.5" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
                <h4 class="text-xs font-bold uppercase m-0 mb-3 text-muted" style="letter-spacing: 0.04em;">Training Parameters</h4>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Assigned Trainer *</label>
                        <select name="trainer" class="form-control text-xs font-semibold" required style="height: 38px;">
                            <?php foreach ($trainers as $trOpt): ?>
                                <option value="<?php echo htmlspecialchars($trOpt); ?>"><?php echo htmlspecialchars($trOpt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Target Date & Time *</label>
                        <input type="datetime-local" name="scheduled_at" class="form-control text-xs font-mono" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>" style="height: 38px;">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Training Mode *</label>
                        <select name="mode" class="form-control text-xs font-semibold" style="height: 38px;">
                            <option value="Online (Google Meet)">Online (Google Meet / AnyDesk)</option>
                            <option value="Offline (On-Site)">Offline (On-Site at Client Premises)</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Total Plan Days</label>
                        <input type="number" name="total_days" class="form-control text-xs font-mono" value="3" min="1" max="15" required style="height: 38px;">
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Modules / Topics to Cover</label>
                        <input type="text" name="topics" class="form-control text-xs" placeholder="e.g. Master Setup, Sale/Purchase, GST Returns, Barcode" value="Master Setup, Daily Billing, GST Returns, Barcoding" style="height: 38px;">
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Special Notes / Remarks</label>
                        <input type="text" name="remarks" class="form-control text-xs" placeholder="e.g. Call before coming, printer setup needed" style="height: 38px;">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-top" style="border-bottom: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('schedule-training-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm flex align-center gap-1.5 font-semibold">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Save Allocation & Dispatch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: Log Employee Reach-out & Connect Update -->
<!-- ========================================================================= -->
<div id="employee-connect-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 520px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                    Log Trainer Reach-Out Update
                </h3>
                <span class="text-xs text-muted">Record trainer calls, customer connection status, and conversation remarks.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('employee-connect-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-3.5" action="index.php?page=training" method="POST">
            <input type="hidden" name="action" value="log_employee_connect">
            <input type="hidden" name="training_id" id="emp-tr-id" value="">

            <div class="p-3" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                <div class="flex justify-between align-center">
                    <div>
                        <span class="text-xs text-muted font-semibold uppercase block" style="font-size: 0.68rem;">Customer</span>
                        <strong class="text-sm font-bold text-main" id="emp-client-name"></strong>
                    </div>
                    <div id="emp-ticket-badge"></div>
                </div>
                <div class="flex align-center gap-3 text-xs text-muted font-mono mt-2 pt-2 border-top" style="border-color: var(--border-color);">
                    <span id="emp-client-phone" class="flex align-center gap-1"></span>
                    <span id="emp-client-product" class="flex align-center gap-1"></span>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Trainer / Logged By *</label>
                    <input type="text" name="employee_name" id="emp-logged-name" class="form-control text-xs font-semibold" value="<?php echo htmlspecialchars($user_name); ?>" required style="height: 38px;">
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Contact Channel *</label>
                    <select name="connect_channel" class="form-control text-xs font-semibold" style="height: 38px;">
                        <option value="Phone Call">Phone Call</option>
                        <option value="WhatsApp Chat">WhatsApp Chat</option>
                        <option value="AnyDesk / Remote">AnyDesk / Remote Screen Share</option>
                        <option value="On-Site Visit">On-Site Visit</option>
                    </select>
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-bold text-main" style="margin-bottom: 0.35rem;">Reach-out Status *</label>
                    <select name="connect_status" id="emp-connect-status-select" class="form-control text-xs font-bold" required style="height: 38px;">
                        <option value="Connected - Training Active">Connected - Training Active / In-Progress</option>
                        <option value="Client Busy / Call Back">Client Busy / Call Back Requested</option>
                        <option value="RNR (Ringing No Response)">RNR (Ringing No Response / Not Picking)</option>
                        <option value="Call Rescheduled by Client">Call Rescheduled by Client</option>
                        <option value="Training Completed">Training Session Completed</option>
                        <option value="Other Update">General Update / Note</option>
                    </select>
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Update Notes / Discussion Summary *</label>
                    <textarea name="update_notes" id="emp-update-notes" class="form-control text-xs" rows="3" placeholder="Explain what happened, e.g.: Connected on AnyDesk, demonstrated GST voucher entry, customer will practice till 4 PM..." required></textarea>
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Next Follow-Up Date & Time (Optional)</label>
                    <input type="datetime-local" name="next_followup" class="form-control text-xs font-mono" style="height: 38px;">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-top" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('employee-connect-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm flex align-center gap-1.5 font-semibold">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Save Update</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: Record Daily Training Session (Day 1, Day 2, Day 3...) -->
<!-- ========================================================================= -->
<div id="record-session-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 500px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                    Record Training Session
                </h3>
                <span class="text-xs text-muted" id="rec-session-subtitle">Log day-by-day training milestone and notify customer.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('record-session-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-3.5" action="index.php?page=training" method="POST">
            <input type="hidden" name="action" value="record_daily_session">
            <input type="hidden" name="training_id" id="rec-tr-id" value="">

            <div class="p-3" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                <span class="text-xs text-muted font-semibold uppercase block" style="font-size: 0.68rem;">Customer Firm</span>
                <strong class="text-sm font-bold text-main" id="rec-client-name"></strong>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Session Day *</label>
                    <input type="number" name="day_number" id="rec-day-number" class="form-control text-xs font-mono font-bold" min="1" max="30" required style="height: 38px;">
                </div>

                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Session Date *</label>
                    <input type="date" name="session_date" id="rec-session-date" class="form-control text-xs font-mono" value="<?php echo date('Y-m-d'); ?>" required style="height: 38px;">
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Duration *</label>
                    <select name="duration_minutes" class="form-control text-xs font-semibold" style="height: 38px;">
                        <option value="30">30 Minutes</option>
                        <option value="45">45 Minutes</option>
                        <option value="60" selected>60 Minutes (1 Hour)</option>
                        <option value="90">90 Minutes (1.5 Hours)</option>
                        <option value="120">120 Minutes (2 Hours)</option>
                    </select>
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Topics Covered *</label>
                    <textarea name="topics_covered" class="form-control text-xs" rows="2" placeholder="e.g. Master setup, GST voucher creation, bill printing..." required></textarea>
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Trainer Feedback</label>
                    <input type="text" name="trainer_notes" class="form-control text-xs" placeholder="e.g. Understood billing flow easily" style="height: 38px;">
                </div>

                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Next Session Scheduled (Optional)</label>
                    <input type="datetime-local" name="next_scheduled_at" class="form-control text-xs font-mono" value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day 11:00')); ?>" style="height: 38px;">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-top" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('record-session-modal')">Cancel</button>
                <button type="submit" class="btn text-sm flex align-center gap-1.5 font-bold" style="background: #10b981; color: #ffffff; border: none;">
                    <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i>
                    <span>Save Milestone</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: Reschedule Training -->
<!-- ========================================================================= -->
<div id="reschedule-training-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 480px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                    Reschedule Training
                </h3>
                <span class="text-xs text-muted">Set new training date & time and alert customer + trainer on WhatsApp.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('reschedule-training-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-3.5" action="index.php?page=training" method="POST">
            <input type="hidden" name="action" value="reschedule_training">
            <input type="hidden" name="training_id" id="resched-tr-id" value="">

            <div class="p-3" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                <span class="text-xs text-muted font-semibold uppercase block" style="font-size: 0.68rem;">Customer</span>
                <strong class="text-sm font-bold text-main" id="resched-client-name"></strong>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">New Scheduled Date & Time *</label>
                <input type="datetime-local" name="scheduled_at" id="resched-datetime" class="form-control text-xs font-mono" required style="height: 38px;">
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Reason for Rescheduling *</label>
                <input type="text" name="rescheduled_reason" class="form-control text-xs" placeholder="e.g. Client requested evening time / Server setup in progress" required style="height: 38px;">
            </div>

            <div class="flex justify-end gap-2 pt-3 border-top" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('reschedule-training-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm flex align-center gap-1.5 font-semibold">
                    <i data-lucide="clock" style="width: 14px; height: 14px;"></i>
                    <span>Confirm Reschedule</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: Mark Training Completed & Close Ticket (NO CERTIFICATE) -->
<!-- ========================================================================= -->
<div id="close-training-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 480px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--success);">
                    Complete & Close Training Ticket
                </h3>
                <span class="text-xs text-muted">Complete training for this client. Automated completion alert will be sent.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('close-training-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-3.5" action="index.php?page=training" method="POST">
            <input type="hidden" name="action" value="close_training">
            <input type="hidden" name="training_id" id="close-tr-id" value="">

            <div class="p-3" style="background-color: var(--success-light); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--border-radius-sm);">
                <span class="text-xs text-muted font-semibold uppercase block" style="font-size: 0.68rem;">Customer</span>
                <strong class="text-sm font-bold text-main" id="close-client-name"></strong>
                <div class="text-xs text-muted mt-1">This marks all training milestones completed and closes the ticket.</div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold text-main" style="margin-bottom: 0.35rem;">Closing Remarks / Completion Summary</label>
                <textarea name="completion_notes" class="form-control text-xs" rows="2">Marg ERP product training successfully completed for all core modules.</textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-top" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('close-training-modal')">Cancel</button>
                <button type="submit" class="btn text-sm flex align-center gap-1.5 font-bold" style="background: #10b981; color: #ffffff; border: none;">
                    <i data-lucide="check-circle-2" style="width: 14px; height: 14px;"></i>
                    <span>Complete & Close</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 6: Training & Employee Connect Complete Audit Trail (History) -->
<!-- ========================================================================= -->
<div id="session-history-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 620px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                    Training Audit History
                </h3>
                <span class="text-xs text-muted" id="hist-client-title"></span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('session-history-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <div class="modal-body p-5" style="max-height: 480px; overflow-y: auto;">
            <div id="history-content-container">
                <!-- Dynamically populated via JS -->
            </div>
        </div>

        <div class="modal-footer p-3.5 border-top flex justify-end" style="border-color: var(--border-color);">
            <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('session-history-modal')">Close</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 7: Marg Client & License Profile Dossier (Like Support Ticket) -->
<!-- ========================================================================= -->
<div id="client-details-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 580px; background: var(--bg-card); border-radius: var(--border-radius-lg); border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color); padding: 1.25rem 1.5rem;">
            <div class="flex align-center gap-2.5">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 6px; border-radius: 8px;">
                    <i data-lucide="building" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="m-0" id="cd-title-firm" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
                        Marg Client Profile
                    </h3>
                    <span class="text-xs text-muted" id="cd-title-id"></span>
                </div>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('client-details-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <div class="modal-body p-5 flex flex-col gap-3.5" style="max-height: 500px; overflow-y: auto;">
            <!-- Profile Card -->
            <div class="p-4" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">Client / Firm Name:</span>
                        <strong class="text-sm text-main block" id="cd-firm-name">-</strong>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">License / Client ID:</span>
                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-family: monospace; font-weight: 700;" id="cd-lead-id">-</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">WhatsApp Mobile:</span>
                        <div id="cd-phone-wrapper">-</div>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">Email Address:</span>
                        <span class="text-xs text-main font-mono" id="cd-email">-</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">Marg Software Edition:</span>
                        <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-weight: 700;" id="cd-product">-</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">Renewal / Expiry Date:</span>
                        <span class="text-xs font-mono text-main" id="cd-renewal">-</span>
                    </div>
                    <div style="grid-column: span 2;">
                        <span class="text-xs text-muted block" style="margin-bottom: 2px;">Office / Business Address:</span>
                        <span class="text-xs text-main" id="cd-address">-</span>
                    </div>
                </div>
            </div>

            <!-- Training Allocation Snapshot -->
            <div class="p-4" style="background-color: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
                <h5 class="text-xs font-bold uppercase text-muted m-0 mb-2.5" style="letter-spacing: 0.04em;">Training Allocation Details</h5>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.82rem;">
                    <div>
                        <span class="text-xs text-muted block">Assigned Trainer:</span>
                        <strong class="text-main" id="cd-trainer">-</strong>
                    </div>
                    <div>
                        <span class="text-xs text-muted block">Training Mode:</span>
                        <span class="text-main" id="cd-mode">-</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block">Multi-Day Progress:</span>
                        <span class="text-main" id="cd-progress">-</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block">Reach-out Status:</span>
                        <span class="text-main" id="cd-connect-status">-</span>
                    </div>
                    <div style="grid-column: span 2;">
                        <span class="text-xs text-muted block">Plan Topics:</span>
                        <span class="text-xs text-muted" id="cd-topics">-</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer p-3.5 border-top flex justify-between align-center" style="border-color: var(--border-color);">
            <div id="cd-wa-action"></div>
            <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('client-details-modal')">Close</button>
        </div>
    </div>
</div>

<script>
    const trainingClientsData = <?php echo $clients_json; ?>;
    const trainingDailyData = <?php echo $daily_json; ?>;

    function initTrainingClientPicker() {
        const picker = document.getElementById('training-client-select-picker');
        if (!picker) return;

        let optHtml = '<option value="">-- Select Client from Directory --</option>';
        trainingClientsData.forEach((c, idx) => {
            const label = c.name + (c.id ? ' (' + c.id + ')' : '') + (c.phone ? ' - ' + c.phone : '');
            optHtml += `<option value="${idx}">${escapeTrnText(label)}</option>`;
        });
        picker.innerHTML = optHtml;
    }

    function onTrainingSelectPickerChange(selectEl) {
        const idx = selectEl.value;
        if (idx !== '') {
            selectTrainingClientByIndex(parseInt(idx, 10));
        }
    }

    function showTrainingClientDropdown() {
        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu) {
            filterTrainingClientDropdown();
            menu.style.display = 'block';
        }
    }

    function filterTrainingClientDropdown() {
        const input = document.getElementById('training-client-search-input');
        const menu = document.getElementById('training-client-dropdown-menu');
        if (!input || !menu) return;

        const val = input.value.trim().toLowerCase();
        const trnCustName = document.getElementById('trn-customer-name');
        if (trnCustName) trnCustName.value = input.value;
        
        let html = '';
        const filtered = [];

        trainingClientsData.forEach((c, idx) => {
            if (
                !val ||
                (c.name && c.name.toString().toLowerCase().includes(val)) ||
                (c.id && c.id.toString().toLowerCase().includes(val)) ||
                (c.phone && c.phone.toString().toLowerCase().includes(val)) ||
                (c.email && c.email.toString().toLowerCase().includes(val))
            ) {
                filtered.push({ item: c, origIndex: idx });
            }
        });

        if (filtered.length === 0) {
            html = '<div style="padding: 10px; font-size: 12px; color: var(--text-muted); text-align: center;">No matching client found</div>';
        } else {
            filtered.slice(0, 60).forEach(f => {
                const c = f.item;
                const idx = f.origIndex;
                const badgeColor = c.id === 'TRIAL-NEW' ? '#d97706' : (c.source === 'CRM Lead' ? '#2563eb' : '#059669');
                html += `
                    <div style="padding: 10px 12px; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.15s;" 
                         onmouseover="this.style.background='var(--border-card)'" 
                         onmouseout="this.style.background='transparent'" 
                         onclick="selectTrainingClientByIndex(${idx})">
                        <div class="flex align-center justify-between">
                            <span class="font-bold text-xs" style="color: var(--text-main);">${escapeTrnText(c.name)}</span>
                            <span class="badge" style="--badge-bg: var(--border-card); --badge-color: ${badgeColor}; font-weight: 700; font-size: 10px;">${escapeTrnText(c.source || 'Client')}</span>
                        </div>
                        <div class="flex align-center gap-3 text-xs text-muted mt-1 font-mono" style="font-size: 11px;">
                            <span>ID: ${escapeTrnText(c.id || '-')}</span>
                            <span>Phone: ${escapeTrnText(c.phone || '-')}</span>
                            ${c.product ? `<span>Product: ${escapeTrnText(c.product)}</span>` : ''}
                        </div>
                    </div>
                `;
            });
        }

        menu.innerHTML = html;
        menu.style.display = 'block';
    }

    function selectTrainingClientByIndex(idx) {
        const c = trainingClientsData[idx];
        if (!c) return;

        document.getElementById('training-client-search-input').value = c.name;
        document.getElementById('trn-customer-name').value = c.name;
        document.getElementById('trn-client-id').value = c.id || '';
        document.getElementById('trn-phone').value = c.phone || '';
        if (document.getElementById('trn-renewal') && c.renewal_date) {
            document.getElementById('trn-renewal').value = c.renewal_date.substring(0, 10);
        }
        if (document.getElementById('trn-address') && c.address) {
            document.getElementById('trn-address').value = c.address;
        }

        if (c.product) {
            const prodSelect = document.getElementById('trn-product');
            let found = false;
            for (let i = 0; i < prodSelect.options.length; i++) {
                if (prodSelect.options[i].value.toLowerCase() === c.product.toLowerCase()) {
                    prodSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) {
                const opt = new Option(c.product, c.product, true, true);
                prodSelect.add(opt);
            }
        }

        const picker = document.getElementById('training-client-select-picker');
        if (picker) picker.value = idx.toString();

        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu) menu.style.display = 'none';
    }

    function escapeTrnText(str) {
        if (!str) return '';
        return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    document.addEventListener('click', function(e) {
        const input = document.getElementById('training-client-search-input');
        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu && input && !input.contains(e.target) && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    function openTrainingAllocationModal() {
        initTrainingClientPicker();
        window.openModal('schedule-training-modal');
        if (window.lucide) lucide.createIcons();
    }

    // Modal: Log Employee Connect Update
    function openEmployeeConnectModal(tr) {
        document.getElementById('emp-tr-id').value = tr.id;
        document.getElementById('emp-client-name').innerText = tr.customer;
        document.getElementById('emp-ticket-badge').innerHTML = `<span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-family:monospace; font-weight:700;">${escapeTrnText(tr.id)}</span>`;
        
        const phoneEl = document.getElementById('emp-client-phone');
        if (phoneEl) {
            phoneEl.innerHTML = tr.phone ? `<i data-lucide="phone" style="width:12px;height:12px;"></i> +91 ${escapeTrnText(tr.phone)}` : '';
        }
        
        const prodEl = document.getElementById('emp-client-product');
        if (prodEl) {
            prodEl.innerHTML = tr.product ? `<i data-lucide="box" style="width:12px;height:12px;"></i> ${escapeTrnText(tr.product)}` : '';
        }
        
        const sel = document.getElementById('emp-connect-status-select');
        if (sel && tr.connect_status) {
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value.toLowerCase() === tr.connect_status.toLowerCase()) {
                    sel.selectedIndex = i;
                    break;
                }
            }
        }

        document.getElementById('emp-update-notes').value = '';
        window.openModal('employee-connect-modal');
        if (window.lucide) lucide.createIcons();
    }

    // Modal: Marg Client & License Profile Dossier
    function openClientDetailsModal(tr) {
        document.getElementById('cd-title-firm').innerText = tr.customer;
        document.getElementById('cd-title-id').innerText = 'Ticket: ' + tr.id + (tr.lead_id ? ' • Client ID: ' + tr.lead_id : '');
        document.getElementById('cd-firm-name').innerText = tr.customer;
        document.getElementById('cd-lead-id').innerText = tr.lead_id || 'Not Assigned';
        
        const cleanPhone = (tr.phone || '').replace(/[^0-9]/g, '');
        if (cleanPhone) {
            document.getElementById('cd-phone-wrapper').innerHTML = `
                <a href="https://wa.me/91${cleanPhone}" target="_blank" class="font-bold text-success flex align-center gap-1 font-mono" style="text-decoration: none;">
                    <i data-lucide="message-circle" style="width: 13px; height: 13px;"></i>
                    <span>+91 ${cleanPhone.replace(/^91/, '')}</span>
                </a>
            `;
            document.getElementById('cd-wa-action').innerHTML = `
                <a href="https://wa.me/91${cleanPhone}" target="_blank" class="btn btn-sm btn-primary flex align-center gap-1">
                    <i data-lucide="message-circle" style="width: 14px; height: 14px;"></i>
                    <span>Chat on WhatsApp</span>
                </a>
            `;
        } else {
            document.getElementById('cd-phone-wrapper').innerText = '-';
            document.getElementById('cd-wa-action').innerHTML = '';
        }

        document.getElementById('cd-email').innerText = tr.email || 'N/A';
        document.getElementById('cd-product').innerText = tr.product || 'Marg ERP 9+';
        document.getElementById('cd-renewal').innerText = tr.renewal_date || 'Active / Lifetime';
        document.getElementById('cd-address').innerText = tr.address || 'Address not registered in directory';
        document.getElementById('cd-trainer').innerText = tr.trainer + (tr.trainer_phone ? ' (+91 ' + tr.trainer_phone + ')' : '');
        document.getElementById('cd-mode').innerText = tr.mode || 'Online';
        document.getElementById('cd-progress').innerText = `Day ${tr.current_day || 0} of ${tr.total_days || 3} (${tr.hours_completed || 0}h / ${tr.total_hours || 6}h)`;
        document.getElementById('cd-connect-status').innerText = tr.connect_status || 'Pending Connect';
        document.getElementById('cd-topics').innerText = tr.topics || 'Basic & Advanced Operations';

        window.openModal('client-details-modal');
        if (window.lucide) lucide.createIcons();
    }

    function openRecordDayModal(trId, clientName, curDay, totDays) {
        document.getElementById('rec-tr-id').value = trId;
        document.getElementById('rec-client-name').innerText = clientName + ' (' + trId + ')';
        document.getElementById('rec-day-number').value = curDay + 1;
        document.getElementById('rec-session-subtitle').innerText = `Recording Day ${curDay + 1} of ${totDays} session.`;
        window.openModal('record-session-modal');
        if (window.lucide) lucide.createIcons();
    }

    function openRescheduleModal(trId, clientName, curSched) {
        document.getElementById('resched-tr-id').value = trId;
        document.getElementById('resched-client-name').innerText = clientName + ' (' + trId + ')';
        if (curSched) {
            document.getElementById('resched-datetime').value = curSched.replace(' ', 'T').substring(0, 16);
        }
        window.openModal('reschedule-training-modal');
        if (window.lucide) lucide.createIcons();
    }

    // Close Modal - NO CERTIFICATES
    function openCloseTrainingModal(trId, clientName) {
        document.getElementById('close-tr-id').value = trId;
        document.getElementById('close-client-name').innerText = clientName + ' (' + trId + ')';
        window.openModal('close-training-modal');
        if (window.lucide) lucide.createIcons();
    }

    // Complete Audit History Modal
    function openHistoryModal(trId, clientName) {
        document.getElementById('hist-client-title').innerText = `${clientName} • Ticket: ${trId}`;
        const container = document.getElementById('history-content-container');
        const list = trainingDailyData[trId] || [];

        if (list.length === 0) {
            container.innerHTML = `
                <div class="text-center p-4 text-muted">
                    <i data-lucide="calendar-x" style="width: 32px; height: 32px; margin: 0 auto 0.5rem auto; color: var(--text-muted);"></i>
                    <div>No daily sessions or reach-out updates recorded yet for this ticket.</div>
                    <div class="text-xs text-muted mt-1">Use "Connect" to log calls or "Day 1" to log session progress.</div>
                </div>
            `;
        } else {
            let html = '<div class="flex flex-col gap-3">';
            list.forEach(item => {
                const isConnect = (item.update_type === 'call_connect');
                const isResched = (item.update_type === 'reschedule');
                const isClose = (item.update_type === 'completion');

                let badgeStyle = '--badge-bg: rgba(147, 51, 234, 0.1); --badge-color: #9333ea;';
                let badgeIcon = 'play-circle';
                let badgeText = `Day ${item.day_number || 1} Session`;

                if (isConnect) {
                    badgeStyle = '--badge-bg: var(--primary-light); --badge-color: var(--primary);';
                    badgeIcon = 'phone-call';
                    badgeText = 'Call / Connect Update';
                } else if (isResched) {
                    badgeStyle = '--badge-bg: var(--warning-light); --badge-color: var(--warning);';
                    badgeIcon = 'calendar';
                    badgeText = 'Rescheduled';
                } else if (isClose) {
                    badgeStyle = '--badge-bg: var(--success-light); --badge-color: var(--success);';
                    badgeIcon = 'check-circle-2';
                    badgeText = 'Training Completed';
                }

                html += `
                    <div style="border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 12px; background-color: var(--bg-app);">
                        <div class="flex align-center justify-between mb-1">
                            <span class="badge flex align-center gap-1" style="${badgeStyle} font-weight: 700; font-size: 11px;">
                                <i data-lucide="${badgeIcon}" style="width: 12px; height: 12px;"></i>
                                ${badgeText}
                            </span>
                            <span class="text-xs text-muted font-mono">
                                ${item.session_date || ''} ${item.duration_minutes > 0 ? ('• ' + item.duration_minutes + ' mins') : ''}
                            </span>
                        </div>
                        <div class="text-sm font-semibold text-main" style="margin-top: 4px;">
                            ${escapeTrnText(item.topics_covered || 'Marg Operations & Training')}
                        </div>
                        ${item.trainer_notes ? `<div class="text-xs text-muted mt-2" style="background-color: var(--bg-card); padding: 8px 10px; border-radius: 6px; border: 1px solid var(--border-color); line-height: 1.4;"><strong class="text-main">Remarks:</strong> ${escapeTrnText(item.trainer_notes)}</div>` : ''}
                        ${item.logged_by ? `<div class="text-xs text-muted mt-1.5 font-mono" style="font-size: 10px;">Logged by: <strong class="text-main">${escapeTrnText(item.logged_by)}</strong> • ${item.created_at || ''}</div>` : ''}
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        window.openModal('session-history-modal');
        if (window.lucide) lucide.createIcons();
    }

    document.addEventListener('DOMContentLoaded', function() {
        initTrainingClientPicker();
        if (window.lucide) lucide.createIcons();
    });
</script>
