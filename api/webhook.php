<?php
/**
 * Marg CRM - Meta WhatsApp Webhook Endpoint
 * 
 * URL: https://friendlyaisolution.com/api/webhook.php
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
// 2. POST Request Handling (Meta Webhook Events & Flow Endpoint Requests)
// -------------------------------------------------------------
if ($reqMethod !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true) ?? [];

// Check if incoming request is a WhatsApp Flow Endpoint Request (Encrypted or Ping)
$isFlowRequest = isset($data['encrypted_aes_key']) || isset($data['encrypted_flow_data']) || isset($data['action']) || (isset($_GET['action']) && in_array($_GET['action'], ['ping', 'INIT', 'data_exchange', 'complete']));

if ($isFlowRequest) {
    require __DIR__ . '/flow-endpoint.php';
    exit;
}

// Verify HMAC SHA-256 Signature (if HTTP_X_HUB_SIGNATURE_256 header present & real secret configured)
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? null;
if (!empty(APP_SECRET) && APP_SECRET !== '1a2b3c4d5e6f7g8h9i0j' && !empty($sigHeader)) {
    if (!verify_meta_signature($rawPayload, APP_SECRET, $sigHeader)) {
        write_log('error', "Webhook HMAC Signature Verification Failed! Continuing processing...");
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

                // Enhanced Media extraction & auto-download (Image, PDF, Video, Audio)
                if (in_array($msgType, ['image', 'document', 'video', 'audio', 'voice', 'sticker']) && isset($msg[$msgType])) {
                    $mediaData = $msg[$msgType];
                    $mediaId   = $mediaData['id'] ?? '';
                    $caption   = $mediaData['caption'] ?? '';
                    $filename  = $mediaData['filename'] ?? '';
                    $mimeType  = $mediaData['mime_type'] ?? '';

                    if ($msgType === 'image') {
                        $msgBodyLog = !empty($caption) ? "📷 " . $caption : "📷 Image received";
                    } elseif ($msgType === 'document') {
                        $msgBodyLog = !empty($caption) ? "📄 " . $caption : (!empty($filename) ? "📄 Document: " . $filename : "📄 PDF Document received");
                    } elseif ($msgType === 'video') {
                        $msgBodyLog = !empty($caption) ? "🎥 " . $caption : "🎥 Video received";
                    } elseif ($msgType === 'audio' || $msgType === 'voice') {
                        $msgBodyLog = "🎵 Voice Note / Audio received";
                    } elseif ($msgType === 'sticker') {
                        $msgBodyLog = "🎨 Sticker received";
                    }

                    if (!empty($mediaId)) {
                        $uploadDir = __DIR__ . '/../uploads/whatsapp/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0755, true);
                        }

                        $ext = 'bin';
                        if (!empty($filename) && str_contains($filename, '.')) {
                            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        } elseif (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) {
                            $ext = 'jpg';
                        } elseif (str_contains($mimeType, 'png')) {
                            $ext = 'png';
                        } elseif (str_contains($mimeType, 'pdf')) {
                            $ext = 'pdf';
                        } elseif (str_contains($mimeType, 'mp4')) {
                            $ext = 'mp4';
                        } elseif (str_contains($mimeType, 'ogg')) {
                            $ext = 'ogg';
                        } elseif (str_contains($mimeType, 'webp')) {
                            $ext = 'webp';
                        }

                        $cleanFile = !empty($filename) ? preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename) : "media_{$mediaId}.{$ext}";
                        $savePath  = $uploadDir . $mediaId . '_' . $cleanFile;
                        
                        if (!file_exists($savePath)) {
                            $whatsapp->downloadMedia($mediaId, $savePath);
                        }
                    }
                }

                $stmtMLog = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, wamid, status, raw_json) VALUES ('INBOUND', ?, ?, ?, ?, 'received', ?)");
                $stmtMLog->execute([$from, $msgType, $msgBodyLog, $wamid, json_encode($msg)]);

                // Check previous chat status for audit logging
                $prevStatusStmt = $pdo->prepare("SELECT status FROM chat_conversations WHERE phone = ? LIMIT 1");
                $prevStatusStmt->execute([$from]);
                $prevStatus = $prevStatusStmt->fetchColumn();

                if ($prevStatus === 'closed') {
                    // Log audit trail
                    $stmtAudit = $pdo->prepare("INSERT INTO chat_audit_logs (phone, action, actor_name, actor_role, remarks) VALUES (?, 'reopened', 'Customer (WhatsApp)', 'Customer', 'Auto Re-opened upon receiving customer message')");
                    $stmtAudit->execute([$from]);

                    // Log system message in message_logs
                    $stmtSys = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status) VALUES ('OUTBOUND', ?, 'system', '🟢 Chat auto-reopened on receiving new message from customer', 'received')");
                    $stmtSys->execute([$from]);
                }

                // Auto-set chat status to open when customer sends a message
                $stmtConv = $pdo->prepare("INSERT INTO chat_conversations (phone, status) VALUES (?, 'open') ON DUPLICATE KEY UPDATE status = 'open'");
                $stmtConv->execute([$from]);
            } catch (Throwable $e) {
                // Continue execution
            }
        }

        // =========================================================
        // SPECIAL CASE: Check if sender is a Registered Team Agent
        // =========================================================
        $fromClean = preg_replace('/[^\d]/', '', $from);
        $fromLast10 = substr($fromClean, -10);
        $teamAgent = null;

        if ($pdo && strlen($fromLast10) === 10) {
            try {
                $stmtAgent = $pdo->prepare("SELECT id, emp_code, name, whatsapp_phone, department, status FROM team_agents WHERE status = 'Active' AND RIGHT(whatsapp_phone, 10) = ? LIMIT 1");
                $stmtAgent->execute([$fromLast10]);
                $teamAgent = $stmtAgent->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                // Table might not exist or error, continue
            }
        }

        if ($teamAgent && $msgType === 'text') {
            $body = trim($msg['text']['body'] ?? '');

            // Detect 10-digit Indian client mobile number
            $clientPhone = null;
            $clientPhoneClean = '';
            if (preg_match('/(?:(?:\+|0{0,2})91[\s\-]*)?([6-9]\d{9})\b/', $body, $matches)) {
                $clientPhoneClean = $matches[1];
                $clientPhone = '+91 ' . $matches[1];
            }

            if (empty($clientPhone)) {
                // Agent sent a message without a valid client phone number
                $helpMsg = "👋 Hello *{$teamAgent['name']}* ({$teamAgent['emp_code']})!\n\n" .
                           "⚠️ *Client Mobile Number Not Detected.*\n\n" .
                           "Client ki automated ticket banane ke liye kripya unka 10-digit mobile number bhejein.\n\n" .
                           "💡 *Example:*\n" .
                           "• `9876543210`\n" .
                           "• `9876543210 Marg printer error`\n" .
                           "• `Client Sharma Ji 9876543210 urgent`\n\n" .
                           "_Technical team ko ticket jayegi aur update hone par aapko yahan alert mil jayega._";
                $whatsapp->sendText($from, $helpMsg);
            } else {
                // Valid Client Phone detected! Extract problem notes
                $problemNote = trim(str_replace([$clientPhoneClean, '+91', '91' . $clientPhoneClean], '', $body));
                $problemNote = preg_replace('/\s+/', ' ', $problemNote);
                if (empty($problemNote) || strlen($problemNote) < 3) {
                    $problemNote = "";
                }

                // Auto-lookup Party Name from client directory or leads
                $partyName = "";
                $licenseNo = "";
                try {
                    $stmtParty = $pdo->prepare("SELECT customer_id, party_name FROM client_directory WHERE mobile LIKE ? LIMIT 1");
                    $stmtParty->execute(['%' . $clientPhoneClean . '%']);
                    $pRow = $stmtParty->fetch(PDO::FETCH_ASSOC);
                    if (!empty($pRow['party_name'])) {
                        $partyName = $pRow['party_name'];
                        $licenseNo = $pRow['customer_id'] ?? '';
                    } else {
                        $stmtLead = $pdo->prepare("SELECT company, name FROM leads WHERE phone LIKE ? LIMIT 1");
                        $stmtLead->execute(['%' . $clientPhoneClean . '%']);
                        $lRow = $stmtLead->fetch(PDO::FETCH_ASSOC);
                        if (!empty($lRow['company'])) {
                            $partyName = $lRow['company'] . (!empty($lRow['name']) ? " (" . $lRow['name'] . ")" : "");
                        }
                    }
                } catch (Throwable $e) {}

                // Create Unique Support Ticket in standard format TK-YYYY-XXXXXX
                $year = date('Y');
                $prefix = "TK-{$year}-";
                try {
                    $stmtSeq = $pdo->prepare("SELECT id FROM support_tickets WHERE id LIKE ? ORDER BY id DESC LIMIT 1");
                    $stmtSeq->execute([$prefix . '%']);
                    $lastId = $stmtSeq->fetchColumn();
                    if ($lastId) {
                        $numPart = (int) substr($lastId, strlen($prefix));
                        $nextNum = $numPart + 1;
                    } else {
                        $nextNum = 1;
                    }
                    $ticketId = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
                } catch (Throwable $e) {
                    $ticketId = 'TK-' . date('ymd') . '-' . rand(1000, 9999);
                }

                $subject = !empty($problemNote) ? mb_strimwidth($problemNote, 0, 70, '...') : (!empty($partyName) ? "Technical Support - " . $partyName : "Technical Support");

                try {
                    $stmtTicket = $pdo->prepare("
                        INSERT INTO support_tickets (
                            id, customer_name, subject, priority, status, assigned_to,
                            lead_id, phone, callback_number, problem, dropped_by_emp_id,
                            dropped_by_emp_name, dropped_by_emp_phone, source, date_created
                        ) VALUES (?, ?, ?, 'medium', 'open', 'Unassigned', ?, ?, ?, ?, ?, ?, ?, 'team_whatsapp_drop', NOW())
                    ");
                    $stmtTicket->execute([
                        $ticketId,
                        $partyName,
                        $subject,
                        $licenseNo,
                        $clientPhoneClean,
                        $clientPhoneClean,
                        $problemNote,
                        $teamAgent['id'],
                        $teamAgent['name'] . ' (' . $teamAgent['emp_code'] . ')',
                        $from
                    ]);

                    // Log in support_ticket_history
                    try {
                        $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details) VALUES (?, 'created', ?, 'Team Member', ?)");
                        $stmtH->execute([
                            $ticketId,
                            $teamAgent['name'] . ' (' . $teamAgent['emp_code'] . ')',
                            "Ticket created via Team WhatsApp Lead Drop by {$teamAgent['name']} ({$teamAgent['emp_code']})" . (!empty($problemNote) ? " with note: {$problemNote}" : "")
                        ]);
                    } catch (Throwable $eH) {}

                    // Admin Topbar Notification
                    $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, 'index.php?page=support', 'warning')");
                    $stmtNotif->execute([
                        "New Team Lead: " . $ticketId,
                        "{$teamAgent['name']} ({$teamAgent['emp_code']}) dropped client {$clientPhoneClean}" . (!empty($partyName) ? " ({$partyName})" : "")
                    ]);

                    // NOTE: NO MESSAGE IS SENT TO THE CLIENT!
                    // Send Clean Professional Confirmation Receipt ONLY to the Team Member:
                    $nowStr = date('d M Y, h:i A');
                    $ackMsg = "*SUPPORT TICKET LOGGED*\n" .
                              "──────────────────────────\n" .
                              "*Ticket ID:* #{$ticketId}\n" .
                              "*Contact No:* {$clientPhoneClean}\n" .
                              (!empty($partyName) ? "*Party Name:* {$partyName}\n" : "") .
                              (!empty($problemNote) ? "*Notes:* {$problemNote}\n" : "") .
                              "*Forwarded By:* {$teamAgent['name']} ({$teamAgent['emp_code']})\n" .
                              "*Status:* Queued (Unassigned Pool)\n" .
                              "*Logged At:* {$nowStr}\n" .
                              "──────────────────────────\n" .
                              "_Ticket has been logged in the support queue. You will receive real-time updates as an engineer claims and works on this request._";
                    $whatsapp->sendText($from, $ackMsg);

                } catch (Throwable $e) {
                    write_log('error', "Failed to create team drop ticket: " . $e->getMessage());
                    $whatsapp->sendText($from, "⚠️ Ticket generate karne me error aaya: " . $e->getMessage());
                }
            }

            // Exit this message loop so normal customer flow is NOT triggered
            continue;
        }

        // =========================================================
        // CASE 1: Standard Customer Text Messages (Greetings like Hi, Support)
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
        // CASE 2: Interactive Button Replies (Sales, Support, AMC, Billing, Offers)
        // =========================================================
        elseif ($msgType === 'interactive' && isset($msg['interactive']['button_reply'])) {
            $buttonId    = $msg['interactive']['button_reply']['id'] ?? '';
            $buttonTitle = strtolower($msg['interactive']['button_reply']['title'] ?? '');

            // Option A: Sales Clicked
            if ($buttonId === 'btn_sales' || $buttonTitle === 'sales') {
                if (defined('SALES_FLOW_ID') && !empty(SALES_FLOW_ID)) {
                    // Send Sales WhatsApp Flow Form
                    $whatsapp->sendFlow($from, SALES_FLOW_ID, "Enquire Now", "Please provide your details and requirement below", 'SALES_SCREEN', null, "Marg Soft Solution - Sales", "Sales & Licensing Inquiry");
                } else {
                    $salesResponse = "We have received your enquiry for Sales.\n\nOur sales representative will contact you shortly.\n\nFor an immediate discussion, you can also call:\n\n7523830026\n\nThank you for contacting us.\n\n🙏";
                    $whatsapp->sendText($from, $salesResponse);
                }
            }

            // Option B: Support Clicked
            elseif ($buttonId === 'btn_support' || $buttonTitle === 'support') {
                // Immediately send WhatsApp Flow
                $flowId   = FLOW_ID;
                $ctaText  = "Create Ticket";
                $bodyText = "Provide info and problem here";
                $whatsapp->sendFlow($from, $flowId, $ctaText, $bodyText, 'WELCOME_SCREEN', null, "Marg Help soft solution", "Managed by Marg soft solution.");
            }

            // Option C: Pay AMC / Pay Invoice Clicked
            elseif ($buttonId === 'btn_pay_amc' || $buttonId === 'btn_pay_invoice' || str_contains($buttonTitle, 'pay')) {
                $bankResponse = "🏦 *Marg Soft Solution - Official Bank & UPI Payment Details*\n\nAccount Name: *MARG SOFT SOLUTION*\nBank Name: *HDFC Bank*\nA/C No: *50200067891234*\nIFSC Code: *HDFC0001234*\nBranch: *Main Branch*\nUPI ID: *margsoft@upi*\n\nPlease transfer payment and send screenshot here. Thank you! 🙏";
                $whatsapp->sendText($from, $bankResponse);
            }

            // Option D: Request Callback Clicked
            elseif ($buttonId === 'btn_request_call' || str_contains($buttonTitle, 'callback') || str_contains($buttonTitle, 'call')) {
                $callResponse = "📞 *Support Callback Request Received*\n\nThank you! Our support engineer has been notified and will call your mobile number shortly.\n\nFor immediate help, call: *7523830026*\nThank you for choosing Marg ERP! 🙏";
                $whatsapp->sendText($from, $callResponse);
            }

            // Option E: Send Screenshot Clicked
            elseif ($buttonId === 'btn_share_screenshot' || str_contains($buttonTitle, 'screenshot')) {
                $ssResponse = "📸 *Payment Screenshot*\n\nThank you! Please attach and send your payment transfer screenshot directly in this chat thread. Our accounts team will verify and update your receipt.";
                $whatsapp->sendText($from, $ssResponse);
            }

            // Option F: Claim Discount Offer Clicked
            elseif ($buttonId === 'btn_claim_offer' || str_contains($buttonTitle, 'claim') || str_contains($buttonTitle, 'discount')) {
                $promoResponse = "🎁 *Discount Coupon Unlocked!*\n\nYour 20% Upgrade Coupon Code: *MARG2026OFF*\n\nOur executive will call you shortly to assist with activation.\nCall: *7523830026*";
                $whatsapp->sendText($from, $promoResponse);
            }
        }

        // =========================================================
        // CASE 3: WhatsApp Flow Submission Payload (nfm_reply)
        // =========================================================
        elseif ($msgType === 'interactive' && isset($msg['interactive']['nfm_reply'])) {
            $responseJson = $msg['interactive']['nfm_reply']['response_json'] ?? '{}';
            $flowData     = json_decode($responseJson, true) ?? [];

            write_log('flow', "Received Flow Submission Payload via Webhook", $flowData);

            // Check if this is a Sales Flow Submission (Lead Creation)
            $isSalesFlow = isset($flowData['requirement']) || (isset($flowData['flow_type']) && $flowData['flow_type'] === 'sales') || (!isset($flowData['license_number']) && !isset($flowData['problem']) && !isset($flowData['c1']));

            if ($isSalesFlow && (isset($flowData['customer_name']) || isset($flowData['requirement']) || isset($flowData['phone_number']))) {
                // =========================================================
                // PROCESS SALES FLOW -> LEAD GENERATION
                // =========================================================
                $leadName    = trim($flowData['customer_name'] ?? $flowData['name'] ?? $flowData['contact_person'] ?? 'WhatsApp Lead');
                $leadPhone   = trim($flowData['phone_number'] ?? $flowData['phone'] ?? $flowData['callback_number'] ?? $flowData['mobile_number'] ?? $from);
                $requirement = trim($flowData['requirement'] ?? $flowData['message'] ?? $flowData['notes'] ?? 'General Sales Inquiry');
                $companyName = trim($flowData['company'] ?? $flowData['firm_name'] ?? $leadName);

                $leadId = generate_lead_number($pdo);

                if ($pdo) {
                    try {
                        $stmtLead = $pdo->prepare("INSERT INTO leads (id, name, contact_person, company, phone, enq_for, remarks, source, status, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'WhatsApp Flow', 'new', 'warm', NOW())");
                        $stmtLead->execute([
                            $leadId,
                            $leadName,
                            $leadName,
                            $companyName,
                            $leadPhone,
                            $requirement,
                            $requirement
                        ]);

                        // Timeline entry
                        try {
                            $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, 'WhatsApp Bot', 'New Lead captured via WhatsApp Sales Flow')");
                            $stmtTime->execute([$leadId]);
                        } catch (Throwable $eT) {}

                        // Notification for Admin
                        try {
                            $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New WhatsApp Sales Lead', ?, 'index.php?page=leads', 'info')");
                            $stmtNotif->execute(["New Lead {$leadId} ({$leadName}) received via WhatsApp Sales Flow"]);
                        } catch (Throwable $eN) {}

                        // Send Confirmation Message to Customer
                        $confirmMsg = "✅ *Sales Enquiry Received Successfully*\n\n" .
                                      "Dear *{$leadName}*,\n" .
                                      "Thank you for contacting Marg Soft Solution.\n\n" .
                                      "📋 *Enquiry Ref:* {$leadId}\n" .
                                      "Our sales representative will call you on *{$leadPhone}* shortly.\n\n" .
                                      "For instant assistance, you can call us at: *7523830026*\n\n" .
                                      "Thank you! 🙏";

                        $whatsapp->sendText($from, $confirmMsg);

                    } catch (Throwable $eLead) {
                        write_log('error', "Failed saving sales lead from webhook: " . $eLead->getMessage());
                    }
                }
                exit;
            }

            $licenseNo    = $flowData['license_number'] ?? $flowData['license_no'] ?? $flowData['client_id'] ?? $flowData['c1'] ?? 'N/A';
            $customerName = $flowData['customer_name'] ?? $flowData['contact_person'] ?? 'Valued Customer';
            $firmName     = $flowData['firm_name'] ?? $flowData['company'] ?? 'N/A';
            $callbackNo   = trim($flowData['callback_number'] ?? $flowData['callback_no'] ?? $flowData['call_back_number'] ?? $flowData['mobile_number'] ?? $flowData['mobile'] ?? $flowData['phone_number'] ?? $flowData['phone'] ?? $flowData['c4'] ?? '');
            $mobile       = !empty($callbackNo) ? $callbackNo : $from;
            $email        = $flowData['email_address'] ?? $flowData['email'] ?? 'N/A';
            $category     = $flowData['issue_category'] ?? $flowData['subject'] ?? $flowData['c2'] ?? 'Technical Support';
            $priority     = $flowData['priority'] ?? 'Medium';
            $description  = $flowData['description'] ?? $flowData['problem'] ?? $flowData['c3'] ?? 'No description provided';
            $attachment   = $flowData['attachment'] ?? null;
            $product      = 'Marg ERP';
            $renewalDate  = null;
            $address      = '';

            // Auto-lookup client details by License Number if found in DB
            if ($pdo && !empty($licenseNo) && $licenseNo !== 'N/A') {
                try {
                    $cdStmt = $pdo->prepare("SELECT * FROM client_directory WHERE customer_id = ? OR customer_id LIKE ? LIMIT 1");
                    $cdStmt->execute([$licenseNo, '%' . $licenseNo]);
                    $cdRow = $cdStmt->fetch(PDO::FETCH_ASSOC);

                    if ($cdRow) {
                        if ($customerName === 'Valued Customer' || empty($customerName)) {
                            $customerName = !empty($cdRow['contact_person']) ? $cdRow['contact_person'] : $cdRow['party_name'];
                        }
                        if ($firmName === 'N/A' || empty($firmName)) {
                            $firmName = !empty($cdRow['party_name']) ? $cdRow['party_name'] : $cdRow['company_using'];
                        }
                        if ($email === 'N/A' || empty($email)) {
                            $email = $cdRow['email'] ?? 'N/A';
                        }
                        $product = $cdRow['software_type'] ?? 'Marg ERP';
                        $renewalDate = $cdRow['due_on'] ?? null;
                        $address = trim(($cdRow['address'] ?? '') . ' ' . ($cdRow['city'] ?? '') . ' ' . ($cdRow['state'] ?? ''));
                    } else {
                        $ldStmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? OR phone LIKE ? LIMIT 1");
                        $ldStmt->execute([$licenseNo, '%' . $licenseNo . '%']);
                        $ldRow = $ldStmt->fetch(PDO::FETCH_ASSOC);
                        if ($ldRow) {
                            if ($customerName === 'Valued Customer' || empty($customerName)) {
                                $customerName = !empty($ldRow['contact_person']) ? $ldRow['contact_person'] : $ldRow['name'];
                            }
                            if ($firmName === 'N/A' || empty($firmName)) {
                                $firmName = $ldRow['company'] ?? 'N/A';
                            }
                            if ($email === 'N/A' || empty($email)) {
                                $email = $ldRow['email'] ?? 'N/A';
                            }
                            $address = trim(($ldRow['address'] ?? '') . ' ' . ($ldRow['city'] ?? '') . ' ' . ($ldRow['state'] ?? ''));
                        }
                    }
                } catch (Throwable $eLookup) {}
            }

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

                    // Also insert into main CRM support_tickets table for dashboard view (index.php?page=support)
                    try {
                        $stmtSup = $pdo->prepare("INSERT INTO support_tickets (id, customer_name, subject, priority, status, assigned_to, phone, email, problem, callback_number, lead_id, product, renewal_date, address, date_created) VALUES (?, ?, ?, ?, 'open', 'Unassigned', ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmtSup->execute([
                            $ticketNumber,
                            $customerName,
                            $category . ($firmName !== 'N/A' && !empty($firmName) ? " - " . $firmName : ""),
                            strtolower($priority),
                            $from,
                            ($email !== 'N/A' ? $email : ''),
                            $description,
                            $mobile,
                            $licenseNo,
                            $product,
                            $renewalDate,
                            $address
                        ]);
                    } catch (Throwable $eSup) {}

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
