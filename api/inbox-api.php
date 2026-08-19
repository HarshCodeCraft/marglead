<?php
/**
 * Marg CRM - WhatsApp Team Inbox & Live Chat API Endpoint
 * Handles conversation listing, message history, live replies, templates, and flow dispatches.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cors.php';
$auth = requireApiAuth();

require_once __DIR__ . '/whatsapp-api.php';

// Helper function to process and insert raw incoming webhook payload JSON into message_logs
function processIncomingPayloadJson($pdo, $jsonString, $createdAt = null) {
    if (empty($jsonString)) return;
    $data = json_decode($jsonString, true);
    if (empty($data['entry'][0]['changes'][0]['value']['messages'])) return;

    foreach ($data['entry'][0]['changes'][0]['value']['messages'] as $msg) {
        $from    = $msg['from'] ?? '';
        $wamid   = $msg['id'] ?? '';
        $msgType = $msg['type'] ?? 'text';
        
        $body = $msg['text']['body'] ?? null;
        if (empty($body) && isset($msg['interactive']['button_reply'])) {
            $body = '🔘 Selected Button: ' . ($msg['interactive']['button_reply']['title'] ?? '');
        }
        if (empty($body) && isset($msg['interactive']['nfm_reply'])) {
            $body = '📋 Form Submitted';
        }
        if (empty($body)) {
            $body = $msgType;
        }

        if (empty($from) || empty($wamid)) continue;

        $check = $pdo->prepare("SELECT id FROM message_logs WHERE wamid = ? LIMIT 1");
        $check->execute([$wamid]);
        if (!$check->fetchColumn()) {
            $ts = isset($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : ($createdAt ?: date('Y-m-d H:i:s'));
            $ins = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, wamid, status, raw_json, created_at) VALUES ('INBOUND', ?, ?, ?, ?, 'received', ?, ?)");
            $ins->execute([$from, $msgType, $body, $wamid, json_encode($msg), $ts]);
        }
    }
}

// Ensure message_logs table exists and sync any raw webhook_logs or file log entries
function syncWebhookLogsToMessageLogs($pdo) {
    if (!$pdo) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS message_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            direction VARCHAR(10) NOT NULL DEFAULT 'INBOUND',
            recipient_or_sender VARCHAR(50) NOT NULL,
            message_type VARCHAR(50) DEFAULT 'text',
            message_body TEXT NULL,
            wamid VARCHAR(100) NULL,
            status VARCHAR(20) DEFAULT 'received',
            raw_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (recipient_or_sender),
            INDEX (direction)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 1. Sync from DB table webhook_logs
        try {
            $stmt = $pdo->query("SELECT payload, created_at FROM webhook_logs WHERE payload LIKE '%\"messages\"%' ORDER BY id ASC");
            if ($stmt) {
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($logs as $l) {
                    processIncomingPayloadJson($pdo, $l['payload'], $l['created_at']);
                }
            }
        } catch (Throwable $e) {}

        // 2. Sync from file logs/webhook.log
        $logFilePath = __DIR__ . '/../logs/webhook.log';
        if (file_exists($logFilePath)) {
            $logLines = file($logFilePath);
            foreach ($logLines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '{') && str_contains($line, '"messages"')) {
                    processIncomingPayloadJson($pdo, $line);
                }
            }
        }
    } catch (Throwable $e) {}
}

if ($db_connected && $pdo) {
    syncWebhookLogsToMessageLogs($pdo);
}

function isChatClosed($pdo, $phone) {
    if (empty($phone) || !$pdo) return false;
    $cleanDigits = preg_replace('/[^0-9]/', '', $phone);
    $clean10 = substr($cleanDigits, -10);
    $stmt = $pdo->prepare("SELECT status FROM chat_conversations WHERE phone = ? OR phone LIKE ? OR phone LIKE ? LIMIT 1");
    $stmt->execute([$phone, "%$cleanDigits%", "%$clean10%"]);
    $st = $stmt->fetchColumn();
    return (strtolower($st) === 'closed');
}

// -----------------------------------------------------------------
// ROUTE DISPATCHER
// -----------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? 'conversations';

switch ($action) {

    // -------------------------------------------------------------
    // 1. Get List of Conversations (Left Pane)
    // -------------------------------------------------------------
    case 'conversations':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            $search = trim($_GET['search'] ?? '');
            $statusFilter = strtolower(trim($_GET['status'] ?? 'open')); // 'open', 'pending', 'closed', 'all'
            
            // Query ALL distinct contact numbers for accurate global counts
            $sqlAll = "SELECT m1.*
                       FROM message_logs m1
                       INNER JOIN (
                           SELECT recipient_or_sender, MAX(id) as max_id
                           FROM message_logs
                           GROUP BY recipient_or_sender
                       ) m2 ON m1.id = m2.max_id
                       ORDER BY m1.id DESC";

            $stmtAll = $pdo->query($sqlAll);
            $allConversations = $stmtAll ? $stmtAll->fetchAll(PDO::FETCH_ASSOC) : [];

            // Fetch chat status map from chat_conversations with full phone normalization
            $statusMap = [];
            if ($stmtStatuses = $pdo->query("SELECT phone, status FROM chat_conversations")) {
                while ($row = $stmtStatuses->fetch(PDO::FETCH_ASSOC)) {
                    $pRaw = $row['phone'];
                    $pDigits = preg_replace('/[^0-9]/', '', $pRaw);
                    $p10 = substr($pDigits, -10);
                    $st = strtolower($row['status']);
                    
                    if (!empty($pRaw)) $statusMap[$pRaw] = $st;
                    if (!empty($pDigits)) $statusMap[$pDigits] = $st;
                    if (!empty($p10)) $statusMap[$p10] = $st;
                }
            }

            $counts = ['open' => 0, 'pending' => 0, 'closed' => 0, 'all' => count($allConversations)];
            $conversations = [];

            foreach ($allConversations as $c) {
                $rawPhone = $c['recipient_or_sender'];
                $phone = preg_replace('/[^0-9]/', '', $rawPhone);
                $cleanPhone = substr($phone, -10);

                // Determine chat status (default 'open')
                $chatStatus = strtolower($statusMap[$rawPhone] ?? ($statusMap[$phone] ?? ($statusMap[$cleanPhone] ?? 'open')));
                if (!in_array($chatStatus, ['open', 'pending', 'closed'])) {
                    $chatStatus = 'open';
                }

                if (isset($counts[$chatStatus])) {
                    $counts[$chatStatus]++;
                }

                // If search query is active, filter list items
                if (!empty($search)) {
                    $matchSearch = (stripos($rawPhone, $search) !== false) || 
                                  (stripos($c['message_body'] ?? '', $search) !== false);
                    if (!$matchSearch) {
                        continue;
                    }
                }

                // If tab status filter is active, filter list items
                if ($statusFilter !== 'all' && $chatStatus !== $statusFilter) {
                    continue;
                }

                $name = 'Client (' . $rawPhone . ')';
                $company = 'Marg Customer';
                $leadId = null;

                // Match with leads table
                $stmtLead = $pdo->prepare("SELECT id, name, company FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
                $stmtLead->execute(["%$cleanPhone%", "%" . $rawPhone . "%"]);
                $lead = $stmtLead->fetch(PDO::FETCH_ASSOC);

                if ($lead) {
                    $name = $lead['name'];
                    $company = $lead['company'];
                    $leadId = $lead['id'];
                } else {
                    $stmtCust = $pdo->prepare("SELECT customer_name, firm_name FROM customers WHERE mobile LIKE ? LIMIT 1");
                    $stmtCust->execute(["%$cleanPhone%"]);
                    $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
                    if ($cust) {
                        $name = $cust['customer_name'];
                        $company = $cust['firm_name'];
                    }
                }

                // 24h window
                $stmtLastIn = $pdo->prepare("SELECT created_at FROM message_logs WHERE (recipient_or_sender = ? OR recipient_or_sender LIKE ?) AND direction = 'INBOUND' ORDER BY id DESC LIMIT 1");
                $stmtLastIn->execute([$rawPhone, "%$cleanPhone%"]);
                $lastInTime = $stmtLastIn->fetchColumn();

                $windowStatus = 'Expired';
                $windowTimeText = '24h Window Expired';
                $windowSeconds = 0;

                if ($lastInTime) {
                    $elapsed = time() - strtotime($lastInTime);
                    $windowSeconds = max(0, 86400 - $elapsed);
                    if ($windowSeconds > 0) {
                        $hours = floor($windowSeconds / 3600);
                        $mins  = floor(($windowSeconds % 3600) / 60);
                        $windowStatus = 'Active';
                        $windowTimeText = "{$hours}h {$mins}m left (Free 24h Window)";
                    }
                }

                $c['chat_status']     = $chatStatus;
                $c['customer_name']   = $name;
                $c['company_name']    = $company;
                $c['lead_id']         = $leadId;
                $c['formatted_time']  = date('d M, h:i A', strtotime($c['created_at']));
                $c['window_status']   = $windowStatus;
                $c['window_time_text']= $windowTimeText;
                $c['window_seconds']  = $windowSeconds;

                $conversations[] = $c;
            }

            echo json_encode(['success' => true, 'conversations' => $conversations, 'counts' => $counts]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // 2. Get Messages for a specific Contact (Center Pane)
    // -------------------------------------------------------------
    case 'messages':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $phone = trim($_GET['phone'] ?? $_POST['phone'] ?? '');
        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        try {
            $cleanDigits = preg_replace('/[^0-9]/', '', $phone);
            $clean10 = substr($cleanDigits, -10);

            $stmt = $pdo->prepare("SELECT * FROM message_logs WHERE recipient_or_sender LIKE ? OR recipient_or_sender LIKE ? ORDER BY id ASC");
            $stmt->execute(["%$cleanDigits%", "%$clean10%"]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($messages as &$m) {
                $m['formatted_time'] = date('h:i A', strtotime($m['created_at']));
            }

            // Fetch customer profile meta
            $profile = [
                'phone' => $phone,
                'name' => 'WhatsApp Client',
                'company' => 'Marg Soft Solution Customer',
                'email' => 'N/A',
                'lead_id' => null,
                'status' => 'Active'
            ];

            $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
            $stmtLead->execute(["%$clean10%", "%$phone%"]);
            $lead = $stmtLead->fetch(PDO::FETCH_ASSOC);

            // Calculate 24h window for profile
            $stmtLastIn = $pdo->prepare("SELECT created_at FROM message_logs WHERE (recipient_or_sender = ? OR recipient_or_sender LIKE ?) AND direction = 'INBOUND' ORDER BY id DESC LIMIT 1");
            $stmtLastIn->execute([$phone, "%$clean10%"]);
            $lastInTime = $stmtLastIn->fetchColumn();

            $windowStatus = 'Expired';
            $windowTimeText = '24h Window Expired';
            $windowSeconds = 0;

            if ($lastInTime) {
                $elapsed = time() - strtotime($lastInTime);
                $windowSeconds = max(0, 86400 - $elapsed);
                if ($windowSeconds > 0) {
                    $hours = floor($windowSeconds / 3600);
                    $mins  = floor(($windowSeconds % 3600) / 60);
                    $windowStatus = 'Active';
                    $windowTimeText = "{$hours}h {$mins}m left (Free 24h Window)";
                }
            }

            // Look up associated support ticket by phone number
            $profile['ticket'] = null;
            try {
                $stmtTck = $pdo->prepare("SELECT * FROM support_tickets WHERE phone LIKE ? OR callback_number LIKE ? ORDER BY date_created DESC LIMIT 1");
                $stmtTck->execute(["%$clean10%", "%$clean10%"]);
                $tckRow = $stmtTck->fetch(PDO::FETCH_ASSOC);
                if (!$tckRow) {
                    $stmtTck2 = $pdo->prepare("SELECT * FROM tickets WHERE mobile LIKE ? ORDER BY id DESC LIMIT 1");
                    $stmtTck2->execute(["%$clean10%"]);
                    $rawTck = $stmtTck2->fetch(PDO::FETCH_ASSOC);
                    if ($rawTck) {
                        $tckRow = [
                            'id' => $rawTck['ticket_number'],
                            'status' => $rawTck['status'],
                            'subject' => $rawTck['category'] . ' - ' . $rawTck['firm_name'],
                            'priority' => $rawTck['priority'],
                            'assigned_to' => 'Unassigned'
                        ];
                    }
                }
                if ($tckRow) {
                    $profile['ticket'] = [
                        'id'          => $tckRow['id'],
                        'status'      => ucfirst($tckRow['status'] ?? 'Open'),
                        'subject'     => $tckRow['subject'] ?? 'Support Issue',
                        'priority'    => ucfirst($tckRow['priority'] ?? 'Medium'),
                        'assigned_to' => $tckRow['assigned_to'] ?? 'Unassigned'
                    ];
                }
            } catch (Throwable $eTck) {}

            // Fetch current chat status & assigned_to for profile
            $stmtCS = $pdo->prepare("SELECT status, assigned_to FROM chat_conversations WHERE phone = ? OR phone LIKE ? LIMIT 1");
            $stmtCS->execute([$phone, "%$clean10%"]);
            $cRow = $stmtCS->fetch(PDO::FETCH_ASSOC);
            $profile['chat_status'] = strtolower($cRow['status'] ?? 'open');
            $profile['assigned_to']  = $cRow['assigned_to'] ?? 'Unassigned';

            // Fetch internal staff notes (AiSensy Private Staff Notes)
            $internalNotes = [];
            try {
                $stmtNotes = $pdo->prepare("SELECT * FROM chat_internal_notes WHERE phone LIKE ? OR phone LIKE ? ORDER BY id ASC");
                $stmtNotes->execute(["%$cleanDigits%", "%$clean10%"]);
                $internalNotes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);
                foreach ($internalNotes as &$nItem) {
                    $nItem['formatted_time'] = date('d M, h:i A', strtotime($nItem['created_at']));
                }
            } catch (Throwable $eNotes) {}
            $profile['internal_notes'] = $internalNotes;

            // Fetch audit history logs
            $auditLogs = [];
            try {
                $stmtAudit = $pdo->prepare("SELECT * FROM chat_audit_logs WHERE phone LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 20");
                $stmtAudit->execute(["%$cleanDigits%", "%$clean10%"]);
                $auditLogs = $stmtAudit->fetchAll(PDO::FETCH_ASSOC);
                foreach ($auditLogs as &$aLog) {
                    $aLog['formatted_time'] = date('d M, h:i A', strtotime($aLog['created_at']));
                }
            } catch (Throwable $eAudit) {}
            $profile['audit_logs'] = $auditLogs;

            $profile['window_status']    = $windowStatus;
            $profile['window_time_text'] = $windowTimeText;
            $profile['window_seconds']   = $windowSeconds;

            echo json_encode(['success' => true, 'messages' => $messages, 'profile' => $profile]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // Save Private Internal Staff Note (AiSensy Team Inbox Note)
    // -------------------------------------------------------------
    case 'save_internal_note':
        $phone = trim($_POST['phone'] ?? '');
        $note  = trim($_POST['note_text'] ?? '');
        $actor = $_SESSION['user_name'] ?? 'Staff Agent';

        if (empty($phone) || empty($note)) {
            echo json_encode(['success' => false, 'message' => 'Phone number and note text are required']);
            exit;
        }

        try {
            $stmtInsN = $pdo->prepare("INSERT INTO chat_internal_notes (phone, actor_name, note_text) VALUES (?, ?, ?)");
            $stmtInsN->execute([$phone, $actor, $note]);

            echo json_encode(['success' => true, 'message' => 'Internal note saved successfully!']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // Assign Chat to Agent (AiSensy Agent Assignment)
    // -------------------------------------------------------------
    case 'assign_chat_agent':
        $phone = trim($_POST['phone'] ?? '');
        $agent = trim($_POST['agent_name'] ?? 'Unassigned');
        $actor = $_SESSION['user_name'] ?? 'Admin';
        $role  = $_SESSION['user_role'] ?? 'Agent';

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        try {
            $stmtC = $pdo->prepare("INSERT INTO chat_conversations (phone, assigned_to) VALUES (?, ?) ON DUPLICATE KEY UPDATE assigned_to = ?");
            $stmtC->execute([$phone, $agent, $agent]);

            $stmtAudit = $pdo->prepare("INSERT INTO chat_audit_logs (phone, action, actor_name, actor_role, remarks) VALUES (?, 'assigned', ?, ?, ?)");
            $stmtAudit->execute([$phone, $actor, $role, "Chat Assigned to $agent by $actor"]);

            echo json_encode(['success' => true, 'message' => "Chat assigned to $agent"]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // 3. Close Conversation / Mark Resolved
    // -------------------------------------------------------------
    case 'close_chat':
        $phone = trim($_POST['phone'] ?? '');
        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        try {
            $actor = $_SESSION['user_name'] ?? getAuthUserContext()['name'] ?? 'Support Agent';
            $role  = $_SESSION['user_role'] ?? 'Agent';

            $stmtC = $pdo->prepare("INSERT INTO chat_conversations (phone, status) VALUES (?, 'closed') ON DUPLICATE KEY UPDATE status = 'closed'");
            $stmtC->execute([$phone]);

            // Insert into audit trail
            $stmtAudit = $pdo->prepare("INSERT INTO chat_audit_logs (phone, action, actor_name, actor_role, remarks) VALUES (?, 'closed', ?, ?, ?)");
            $stmtAudit->execute([$phone, $actor, $role, "Chat Closed by $actor ($role)"]);

            $logText = "🔒 Conversation closed by $actor ($role)";
            $stmt = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status) VALUES ('OUTBOUND', ?, 'system', ?, 'closed')");
            $stmt->execute([$phone, $logText]);

            echo json_encode(['success' => true, 'message' => 'Conversation closed successfully']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // 4. Update Chat Status (open, pending, closed)
    // -------------------------------------------------------------
    case 'update_chat_status':
        $phone  = trim($_POST['phone'] ?? '');
        $status = strtolower(trim($_POST['status'] ?? 'open'));
        if (empty($phone) || !in_array($status, ['open', 'pending', 'closed'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        try {
            $actor = $_SESSION['user_name'] ?? getAuthUserContext()['name'] ?? 'Support Agent';
            $role  = $_SESSION['user_role'] ?? 'Agent';

            $stmtC = $pdo->prepare("INSERT INTO chat_conversations (phone, status) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = ?");
            $stmtC->execute([$phone, $status, $status]);

            if ($status === 'closed') {
                $actionName = 'closed';
                $statusEmoji = '🔒';
                $remarks = "Chat Closed by $actor ($role)";
            } elseif ($status === 'pending') {
                $actionName = 'pending';
                $statusEmoji = '🟡';
                $remarks = "Chat Status set to Pending by $actor ($role)";
            } else {
                $actionName = 'reopened';
                $statusEmoji = '🟢';
                $remarks = "Chat Re-opened by $actor ($role)";
            }

            // Insert into audit trail table
            $stmtAudit = $pdo->prepare("INSERT INTO chat_audit_logs (phone, action, actor_name, actor_role, remarks) VALUES (?, ?, ?, ?, ?)");
            $stmtAudit->execute([$phone, $actionName, $actor, $role, $remarks]);

            $logText = "{$statusEmoji} {$remarks}";
            $stmtLog = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status) VALUES ('OUTBOUND', ?, 'system', ?, ?)");
            $stmtLog->execute([$phone, $logText, $status]);

            echo json_encode(['success' => true, 'message' => 'Status updated to ' . $status]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // 5. Send Live Reply Text Message
    // -------------------------------------------------------------
    case 'send_reply':
        $phone   = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Phone number and message text are required']);
            exit;
        }

        if (isChatClosed($pdo, $phone)) {
            echo json_encode(['success' => false, 'message' => '🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending messages.']);
            exit;
        }

        $whatsapp = new WhatsAppAPI($pdo);
        $res = $whatsapp->sendText($phone, $message);

        if (!empty($res['success']) && $res['success']) {
            echo json_encode(['success' => true, 'message' => 'Message sent successfully!', 'data' => $res]);
        } else {
            echo json_encode(['success' => false, 'message' => $res['error']['message'] ?? 'Failed to send WhatsApp message', 'details' => $res]);
        }
        exit;

    // -------------------------------------------------------------
    // 6. Send Interactive Reply Buttons
    // -------------------------------------------------------------
    case 'send_buttons':
        $phone     = trim($_POST['phone'] ?? '');
        $bodyText  = trim($_POST['body_text'] ?? 'Welcome to Marg Soft Solution Support! Please select an option:');
        $headerImg = trim($_POST['header_image'] ?? 'https://datapartner.btpr.online/ProductPictures/20851800671_download(4).png');

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        if (isChatClosed($pdo, $phone)) {
            echo json_encode(['success' => false, 'message' => '🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending messages.']);
            exit;
        }

        $buttons = [
            ['id' => 'btn_sales', 'title' => 'Sales'],
            ['id' => 'btn_support', 'title' => 'Support']
        ];

        $whatsapp = new WhatsAppAPI($pdo);
        $res = $whatsapp->sendReplyButtons($phone, $bodyText, $buttons, "Marg Soft Solution", "Please select an option", $headerImg);

        if (!empty($res['success']) && $res['success']) {
            echo json_encode(['success' => true, 'message' => 'Reply buttons sent successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => $res['error']['message'] ?? 'Failed to send reply buttons']);
        }
        exit;

    // -------------------------------------------------------------
    // 7. Send WhatsApp Flow Message
    // -------------------------------------------------------------
    case 'send_flow':
        $phone  = trim($_POST['phone'] ?? '');
        $flowId = trim($_POST['flow_id'] ?? FLOW_ID);

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
            exit;
        }

        if (isChatClosed($pdo, $phone)) {
            echo json_encode(['success' => false, 'message' => '🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending messages.']);
            exit;
        }

        $whatsapp = new WhatsAppAPI($pdo);
        $res = $whatsapp->sendFlow($phone, $flowId, "Create Ticket", "Provide info and problem here", 'WELCOME_SCREEN', null, "Marg Help soft solution", "Managed by Marg soft solution.");

        if (!empty($res['success']) && $res['success']) {
            echo json_encode(['success' => true, 'message' => 'WhatsApp Flow dispatched successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => $res['error']['message'] ?? 'Failed to dispatch WhatsApp Flow']);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}
