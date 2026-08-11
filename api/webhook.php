<?php
/**
 * Marg CRM - Meta WhatsApp Webhook Endpoint
 * 
 * URL: https://ladder-giver-splendid.ngrok-free.dev/marglead/api/webhook.php
 * 
 * Responsibilities:
 * 1. GET: Verify Meta Webhook Subscription (hub.challenge)
 * 2. POST: Handle incoming messages, interactive button replies, and WhatsApp flow submissions.
 */

require_once __DIR__ . '/whatsapp-api.php';

// -------------------------------------------------------------
// 1. GET Request Handling (Meta Verification Challenge & Health Check)
// -------------------------------------------------------------
$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($reqMethod === 'GET') {
    $hasMetaParams = isset($_GET['hub_mode']) || isset($_GET['hub.mode']) || isset($_GET['hub_verify_token']) || isset($_GET['hub.verify_token']);
    
    if ($hasMetaParams) {
        $challenge = WhatsAppAPI::verifyWebhook($_GET, VERIFY_TOKEN);
        if ($challenge !== null) {
            write_log('webhook', "GET Verification Successful! Challenge returned.");
            http_response_code(200);
            echo $challenge;
            exit;
        } else {
            write_log('error', "GET Verification Failed. Invalid verify token or parameters.", $_GET);
            http_response_code(403);
            echo "Verification failed. Invalid verify token.";
            exit;
        }
    } else {
        // Friendly Status Response when opening endpoint directly in browser
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode([
            'status'         => 'ACTIVE',
            'message'        => 'Marg CRM WhatsApp Webhook Endpoint is Live and Ready!',
            'verify_token'   => VERIFY_TOKEN,
            'webhook_url'    => BASE_URL . 'api/webhook.php',
            'instructions'   => 'In Meta Developer Portal, set Callback URL to this endpoint and Verify Token to ' . VERIFY_TOKEN
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -------------------------------------------------------------
// 2. POST Request Handling (Meta Webhook Events)
// -------------------------------------------------------------
if ($reqMethod !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

$rawPayload = file_get_contents('php://input');

// Verify HMAC SHA-256 Signature (if HTTP_X_HUB_SIGNATURE_256 header present)
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? null;
if (!empty(APP_SECRET) && !empty($sigHeader)) {
    if (!verify_meta_signature($rawPayload, APP_SECRET, $sigHeader)) {
        write_log('error', "Webhook HMAC Signature Verification Failed!");
        http_response_code(401);
        echo "Invalid Signature";
        exit;
    }
}

// Log Webhook Payload in File
write_log('webhook', "Incoming Webhook POST Event", $rawPayload);

// Log Payload in DB webhook_logs
if ($pdo) {
    try {
        $stmtWLog = $pdo->prepare("INSERT INTO webhook_logs (event_type, sender_phone, payload, headers, ip_address) VALUES ('INCOMING', ?, ?, ?, ?)");
        $stmtWLog->execute([
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
            $rawPayload,
            json_encode(getallheaders()),
            $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ]);
    } catch (Throwable $e) {
        // Continue execution if DB logging fails
    }
}

$data = json_decode($rawPayload, true);

// Fast return 200 OK to Meta so it does not retry the webhook
http_response_code(200);
echo "EVENT_RECEIVED";

// Process incoming payload structure safely
if (empty($data['entry'][0]['changes'][0]['value']['messages'])) {
    // If payload contains statuses (read, delivered, sent), just exit cleanly
    exit;
}

$whatsapp = new WhatsAppAPI($pdo);

foreach ($data['entry'][0]['changes'] as $change) {
    $value = $change['value'] ?? [];
    $messages = $value['messages'] ?? [];
    $contacts = $value['contacts'] ?? [];

    foreach ($messages as $msg) {
        $from = $msg['from'] ?? '';
        $wamid = $msg['id'] ?? '';
        $msgType = $msg['type'] ?? '';

        // Mark incoming message as read (blue tick)
        if (!empty($wamid)) {
            $whatsapp->markAsRead($wamid);
        }

        // Save incoming message in message_logs DB table
        if ($pdo) {
            try {
                $msgBodyLog = $msg['text']['body'] ?? ($msg['interactive']['button_reply']['title'] ?? $msgType);
                $stmtMLog = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, wamid, status, raw_json) VALUES ('INBOUND', ?, ?, ?, ?, 'received', ?)");
                $stmtMLog->execute([$from, $msgType, $msgBodyLog, $wamid, json_encode($msg)]);
            } catch (Throwable $e) {
                // Continue execution
            }
        }

        // =========================================================
        // CASE 1: Standard Text Messages (Greetings like Hi, Support)
        // =========================================================
        if ($msgType === 'text') {
            $body = trim($msg['text']['body'] ?? '');
            $cleanBody = strtolower($body);

            // Case-insensitive greeting recognition or default customer touchpoint
            $greetings = ['hi', 'hello', 'hey', 'hii', 'support', 'help', 'start', 'menu', 'options'];
            $isGreeting = false;
            foreach ($greetings as $g) {
                if (str_contains($cleanBody, $g)) {
                    $isGreeting = true;
                    break;
                }
            }

            // Always reply with welcome message and reply buttons for greetings / first messages
            if ($isGreeting || true) { // Always respond to text queries with menu buttons
                $welcomeText = "Welcome To Marg Soft  Solution\nIndian business management and accounting software designed for small and medium businesses. It helps companies manage daily operations such as billing, accounting, inventory, GST compliance, sales, purchases, and reporting from a single platform.";
                $buttons = [
                    ['id' => 'btn_sales', 'title' => 'Sales'],
                    ['id' => 'btn_support', 'title' => 'Support']
                ];
                $headerImage = "https://datapartner.btpr.online/ProductPictures/20851800671_download(4).png";
                $whatsapp->sendReplyButtons($from, $welcomeText, $buttons, "Welcome to Marg Soft Solution", "Please select an option", $headerImage);
            }
        }

        // =========================================================
        // CASE 2: Interactive Button Replies (Sales or Support)
        // =========================================================
        elseif ($msgType === 'interactive' && isset($msg['interactive']['button_reply'])) {
            $buttonId    = $msg['interactive']['button_reply']['id'] ?? '';
            $buttonTitle = strtolower($msg['interactive']['button_reply']['title'] ?? '');

            // Option A: Sales Clicked
            if ($buttonId === 'btn_sales' || $buttonTitle === 'sales') {
                $salesResponse = "We have received your enquiry for Sales.\n\nOur sales representative will contact you shortly.\n\nFor an immediate discussion, you can also call:\n\n7523830026\n\nThank you for contacting us.\n\n🙏";
                $whatsapp->sendText($from, $salesResponse);
            }

            // Option B: Support Clicked
            elseif ($buttonId === 'btn_support' || $buttonTitle === 'support') {
                // Immediately send WhatsApp Flow
                $flowId   = FLOW_ID;
                $ctaText  = "Create Ticket";
                $bodyText = "Provide info and problem here";
                $whatsapp->sendFlow($from, $flowId, $ctaText, $bodyText, 'screen_1', null, "Marg Help soft solution", "Managed by Marg soft solution.");
            }
        }

        // =========================================================
        // CASE 3: WhatsApp Flow Submission Payload (nfm_reply)
        // =========================================================
        elseif ($msgType === 'interactive' && isset($msg['interactive']['nfm_reply'])) {
            $responseJson = $msg['interactive']['nfm_reply']['response_json'] ?? '{}';
            $flowData     = json_decode($responseJson, true) ?? [];

            write_log('flow', "Received Flow Submission Payload via Webhook", $flowData);

            $licenseNo    = $flowData['license_number'] ?? $flowData['c1'] ?? 'N/A';
            $customerName = $flowData['customer_name'] ?? $flowData['contact_person'] ?? 'Valued Customer';
            $firmName     = $flowData['firm_name'] ?? $flowData['company'] ?? 'N/A';
            $mobile       = $flowData['mobile_number'] ?? $flowData['call_back_number'] ?? $flowData['c4'] ?? $from;
            $email        = $flowData['email_address'] ?? 'N/A';
            $category     = $flowData['issue_category'] ?? $flowData['subject'] ?? $flowData['c2'] ?? 'Technical Support';
            $priority     = $flowData['priority'] ?? 'Medium';
            $description  = $flowData['description'] ?? $flowData['problem'] ?? $flowData['c3'] ?? 'No description provided';
            $attachment   = $flowData['attachment'] ?? null;

            // Generate Ticket Number (TK-2026-XXXXXX)
            $ticketNumber = generate_ticket_number($pdo);

            // Insert ticket into database
            if ($pdo) {
                try {
                    $stmtIns = $pdo->prepare("INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, attachment, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open')");
                    $stmtIns->execute([
                        $ticketNumber,
                        $licenseNo,
                        $firmName,
                        $customerName,
                        $mobile,
                        $email,
                        $category,
                        $priority,
                        $description,
                        $attachment
                    ]);

                    // Send Instant Confirmation Message to Customer
                    $confirmMsg = "✅ *Ticket Created Successfully*\n\n" .
                                  "*Ticket Number*\n" .
                                  "{$ticketNumber}\n\n" .
                                  "Thank you for contacting ABC Software.\n\n" .
                                  "Our support engineer will contact you shortly.";

                    $whatsapp->sendText($from, $confirmMsg);

                } catch (Throwable $e) {
                    write_log('error', "Failed saving flow ticket in webhook: " . $e->getMessage());
                }
            }
        }
    }
}
