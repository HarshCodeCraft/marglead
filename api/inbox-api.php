<?php
/**
 * Marg CRM - WhatsApp Team Inbox & Live Chat API Endpoint
 * Handles conversation listing, message history, live replies, templates, and flow dispatches.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
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
            
            // Query distinct contact numbers with their latest message
            $sql = "SELECT m1.*
                    FROM message_logs m1
                    INNER JOIN (
                        SELECT recipient_or_sender, MAX(id) as max_id
                        FROM message_logs
                        GROUP BY recipient_or_sender
                    ) m2 ON m1.id = m2.max_id";

            if (!empty($search)) {
                $sql .= " WHERE m1.recipient_or_sender LIKE ? OR m1.message_body LIKE ?";
                $stmt = $pdo->prepare($sql . " ORDER BY m1.id DESC");
                $stmt->execute(["%$search%", "%$search%"]);
            } else {
                $stmt = $pdo->query($sql . " ORDER BY m1.id DESC");
            }

            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enhance conversations with customer profile data from customers/leads tables & 24h window
            foreach ($conversations as &$c) {
                $phone = preg_replace('/[^0-9]/', '', $c['recipient_or_sender']);
                $cleanPhone = substr($phone, -10);

                $name = 'Client (' . $c['recipient_or_sender'] . ')';
                $company = 'Marg Customer';
                $leadId = null;

                // Match with leads table
                $stmtLead = $pdo->prepare("SELECT id, name, company FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
                $stmtLead->execute(["%$cleanPhone%", "%" . $c['recipient_or_sender'] . "%"]);
                $lead = $stmtLead->fetch(PDO::FETCH_ASSOC);

                if ($lead) {
                    $name = $lead['name'];
                    $company = $lead['company'];
                    $leadId = $lead['id'];
                } else {
                    // Match with customers table
                    $stmtCust = $pdo->prepare("SELECT customer_name, firm_name FROM customers WHERE mobile LIKE ? LIMIT 1");
                    $stmtCust->execute(["%$cleanPhone%"]);
                    $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
                    if ($cust) {
                        $name = $cust['customer_name'];
                        $company = $cust['firm_name'];
                    }
                }

                // Calculate 24-hour service window from last INBOUND customer message
                $stmtLastIn = $pdo->prepare("SELECT created_at FROM message_logs WHERE (recipient_or_sender = ? OR recipient_or_sender LIKE ?) AND direction = 'INBOUND' ORDER BY id DESC LIMIT 1");
                $stmtLastIn->execute([$c['recipient_or_sender'], "%$cleanPhone%"]);
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

                $c['customer_name']   = $name;
                $c['company_name']    = $company;
                $c['lead_id']         = $leadId;
                $c['formatted_time']  = date('d M, h:i A', strtotime($c['created_at']));
                $c['window_status']   = $windowStatus;
                $c['window_time_text']= $windowTimeText;
                $c['window_seconds']  = $windowSeconds;
            }

            echo json_encode(['success' => true, 'conversations' => $conversations]);
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

            $profile['window_status']    = $windowStatus;
            $profile['window_time_text'] = $windowTimeText;
            $profile['window_seconds']   = $windowSeconds;

            echo json_encode(['success' => true, 'messages' => $messages, 'profile' => $profile]);
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
            $stmt = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status) VALUES ('OUTBOUND', ?, 'system', '🔒 Conversation closed by support agent', 'closed')");
            $stmt->execute([$phone]);
            echo json_encode(['success' => true, 'message' => 'Conversation closed successfully']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // -------------------------------------------------------------
    // 3. Send Live Reply Text Message
    // -------------------------------------------------------------
    case 'send_reply':
        $phone   = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($phone) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Phone number and message text are required']);
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
    // 4. Send Interactive Reply Buttons
    // -------------------------------------------------------------
    case 'send_buttons':
        $phone     = trim($_POST['phone'] ?? '');
        $bodyText  = trim($_POST['body_text'] ?? 'Welcome to Marg Soft Solution Support! Please select an option:');
        $headerImg = trim($_POST['header_image'] ?? 'https://datapartner.btpr.online/ProductPictures/20851800671_download(4).png');

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
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
    // 5. Send WhatsApp Flow Message
    // -------------------------------------------------------------
    case 'send_flow':
        $phone  = trim($_POST['phone'] ?? '');
        $flowId = trim($_POST['flow_id'] ?? FLOW_ID);

        if (empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Phone number is required']);
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
