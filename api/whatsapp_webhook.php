<?php
/**
 * WhatsApp Business Cloud API & Webhook Listener for Marg Lead CRM
 * 
 * Handles incoming WhatsApp messages, WhatsApp Flows, and interactive responses.
 * Automatically creates real support tickets in the database (support_tickets table).
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// -------------------------------------------------------------
// 1. Meta WhatsApp Webhook Verification Challenge (GET Request)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $allowed_tokens = ['marglead_whatsapp_token_2026', '@Jarvis07'];
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && in_array($token, $allowed_tokens, true)) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid verification token']);
        exit;
    }
}

// -------------------------------------------------------------
// 2. Process Incoming Webhook Payload (POST Request)
// -------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

$license_no = '';
$subject = 'General Technical Support';
$problem = '';
$callback_number = '';
$customer_name = 'WhatsApp Customer';

// Parse Meta WhatsApp Cloud API structure
if (isset($data['entry'][0]['changes'][0]['value'])) {
    $value = $data['entry'][0]['changes'][0]['value'];
    $incoming_phone_id = $value['metadata']['phone_number_id'] ?? '';
    
    // Dynamic Multi-Tenant Tenant Configuration Lookup
    $tenantConfig = null;
    if (!empty($incoming_phone_id) && isset($pdo) && $pdo) {
        $tenantStmt = $pdo->prepare("SELECT * FROM tenant_whatsapp_configs WHERE phone_number_id = ? AND status = 'active' LIMIT 1");
        $tenantStmt->execute([$incoming_phone_id]);
        $tenantConfig = $tenantStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Extract customer phone number
    if (isset($value['contacts'][0]['wa_id'])) {
        $callback_number = $value['contacts'][0]['wa_id'];
    }
    if (isset($value['contacts'][0]['profile']['name'])) {
        $customer_name = $value['contacts'][0]['profile']['name'];
    }

    if (isset($value['messages'][0])) {
        $messageObj = $value['messages'][0];
        $msgType = $messageObj['type'] ?? '';

        // Handle Interactive Button / Flow Form Response
        if ($msgType === 'interactive') {
            $interactive = $messageObj['interactive'];
            if (isset($interactive['nfm_reply'])) {
                // Decrypted WhatsApp Flow JSON Response
                $flowResponse = json_decode($interactive['nfm_reply']['response_json'] ?? '{}', true);
                $license_no = trim($flowResponse['license_no'] ?? $flowResponse['client_id'] ?? '');
                $subject = trim($flowResponse['subject'] ?? 'Billing & Technical Issue');
                $problem = trim($flowResponse['problem'] ?? '');
                $callback_number = trim($flowResponse['callback_no'] ?? $callback_number);
            } elseif (isset($interactive['button_reply'])) {
                $btnId = $interactive['button_reply']['id'] ?? '';
                if ($btnId === 'support_btn') {
                    // Send WhatsApp Flow Trigger Back to Customer
                    sendWhatsAppMessage($callback_number, "Please provide your info and problem by tapping 'Create ticket'.");
                    exit;
                }
            }
        } elseif ($msgType === 'text') {
            $body = strtolower(trim($messageObj['text']['body'] ?? ''));
            if ($body === 'hi' || $body === 'hello') {
                // Auto-reply with interactive greeting card
                sendWhatsAppGreeting($callback_number, $customer_name);
                exit;
            }
        }
    }
} else {
    // Standard Direct API POST Payload (Twilio / Custom Webhook / Interakt)
    $license_no = trim($data['license_no'] ?? $data['client_id'] ?? '');
    $subject = trim($data['subject'] ?? 'General Technical Support');
    $problem = trim($data['problem'] ?? '');
    $callback_number = trim($data['callback_number'] ?? $data['phone'] ?? $data['From'] ?? '');
    if (!empty($data['customer_name'])) {
        $customer_name = trim($data['customer_name']);
    }
}

// Fallback values if license_no or problem is empty
if (empty($license_no)) {
    $license_no = 'LIC-' . rand(1000, 9999);
}
if (empty($problem)) {
    $problem = 'WhatsApp support inquiry from ' . $callback_number;
}

if (!$db_connected || !$pdo) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable']);
    exit;
}

try {
    // Generate unique Ticket ID
    $ticketId = 'TCK-' . rand(1000, 9999);
    
    // Look up customer name in database by phone or license number
    $custStmt = $pdo->prepare("SELECT party_name FROM client_directory WHERE party_name LIKE ? OR mobile LIKE ? LIMIT 1");
    $custStmt->execute(['%' . $license_no . '%', '%' . $callback_number . '%']);
    $foundName = $custStmt->fetchColumn();
    if ($foundName) {
        $customer_name = $foundName;
    } else {
        $leadStmt = $pdo->prepare("SELECT name FROM leads WHERE name LIKE ? OR phone LIKE ? LIMIT 1");
        $leadStmt->execute(['%' . $license_no . '%', '%' . $callback_number . '%']);
        if ($leadName = $leadStmt->fetchColumn()) {
            $customer_name = $leadName;
        }
    }

    // Auto-assign to an active technician/support staff
    $assigned_to = 'Harsh Vardhan';
    $userStmt = $pdo->query("SELECT name FROM users WHERE status = 'Active' AND (role LIKE '%Support%' OR role LIKE '%Engineer%') ORDER BY RAND() LIMIT 1");
    if ($userStmt && $uName = $userStmt->fetchColumn()) {
        $assigned_to = $uName;
    }

    if (empty($subject) || $subject === 'General Technical Support') {
        $subject = mb_strimwidth($problem, 0, 70, '...');
    }

    $product = 'Marg ERP 9+';
    $priority = 'high';
    $status = 'open';
    $due_date = date('Y-m-d', strtotime('+2 days'));

    // Save Real Ticket to Database (support_tickets table)
    $stmt = $pdo->prepare("INSERT INTO support_tickets 
        (id, customer_name, subject, priority, status, assigned_to, lead_id, phone, email, product, address, problem, due_date, callback_number, date_created) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stmt->execute([
        $ticketId,
        $customer_name,
        $subject,
        $priority,
        $status,
        $assigned_to,
        $license_no,
        $callback_number,
        'whatsapp@marglead.com',
        $product,
        'Real WhatsApp Business API',
        $problem,
        $due_date,
        $callback_number
    ]);

    // Create system notification for assigned representative
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'New Ticket Assigned', ?, 'index.php?page=support', 'warning')");
    $notifMsg = "Real WhatsApp Ticket " . $ticketId . " (" . $license_no . ") assigned to you.";
    $notifStmt->execute([$assigned_to, $notifMsg]);

    // Create system notification for admin
    $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New Support Ticket Raised', ?, 'index.php?page=support', 'danger')");
    $adminNotifMsg = "New Real WhatsApp Ticket " . $ticketId . " raised for " . $customer_name;
    $adminNotifStmt->execute([$adminNotifMsg]);

    // Confirmation message to WhatsApp customer
    $replyMsg = "Dear Customer, 👋\n\nThank you for contacting us. Your ticket has been successfully created. 🎟️\n\nTicket ID: " . $ticketId . "\nOur support team (" . $assigned_to . ") will review your issue and call you back on " . $callback_number . " shortly.\n\nWe appreciate your patience and support. 😊\n\nRegards,\nSupport Team";

    sendWhatsAppMessage($callback_number, $replyMsg);

    echo json_encode([
        'status' => 'success',
        'ticket_id' => $ticketId,
        'customer_name' => $customer_name,
        'assigned_to' => $assigned_to
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Helper function to send message via Meta WhatsApp Business Cloud API
 */
function sendWhatsAppMessage($to, $message) {
    $token = 'YOUR_META_WHATSAPP_ACCESS_TOKEN';
    $phone_number_id = 'YOUR_META_PHONE_NUMBER_ID';

    if ($token === 'YOUR_META_WHATSAPP_ACCESS_TOKEN') return; // Pending configuration

    $url = "https://graph.facebook.com/v18.0/{$phone_number_id}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Helper to send interactive greeting card & Sales/Support buttons
 */
function sendWhatsAppGreeting($to, $name) {
    $token = 'YOUR_META_WHATSAPP_ACCESS_TOKEN';
    $phone_number_id = 'YOUR_META_PHONE_NUMBER_ID';

    if ($token === 'YOUR_META_WHATSAPP_ACCESS_TOKEN') return;

    $url = "https://graph.facebook.com/v18.0/{$phone_number_id}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'header' => [
                'type' => 'image',
                'image' => ['link' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?q=80&w=600&auto=format&fit=crop']
            ],
            'body' => [
                'text' => "Welcome To Marg Soft Solution\nIndian business management and accounting software designed for small and medium businesses."
            ],
            'action' => [
                'buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'sales_btn', 'title' => 'Sales']],
                    ['type' => 'reply', 'reply' => ['id' => 'support_btn', 'title' => 'Support']]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
