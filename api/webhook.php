<?php

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/whatsapp-api.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';

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

                $msgCreatedAt = !empty($msg['timestamp']) ? date('Y-m-d H:i:s', (int)$msg['timestamp']) : date('Y-m-d H:i:s');
                $stmtMLog = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, wamid, status, raw_json, created_at) VALUES ('INBOUND', ?, ?, ?, ?, 'received', ?, ?)");
                $stmtMLog->execute([$from, $msgType, $msgBodyLog, $wamid, json_encode($msg), $msgCreatedAt]);

                // Check previous chat status for audit logging
                $prevStatusStmt = $pdo->prepare("SELECT status FROM chat_conversations WHERE phone = ? LIMIT 1");
                $prevStatusStmt->execute([$from]);
                $prevStatus = $prevStatusStmt->fetchColumn();

                if ($prevStatus === 'closed') {
                    // Log audit trail
                    $stmtAudit = $pdo->prepare("INSERT INTO chat_audit_logs (phone, action, actor_name, actor_role, remarks) VALUES (?, 'reopened', 'Customer (WhatsApp)', 'Customer', 'Auto Re-opened upon receiving customer message')");
                    $stmtAudit->execute([$from]);

                    // Log system message in message_logs
                    $stmtSys = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status, created_at) VALUES ('OUTBOUND', ?, 'system', '  Chat auto-reopened on receiving new message from customer', 'received', ?)");
                    $stmtSys->execute([$from, date('Y-m-d H:i:s')]);
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
            $isLeadCommand = (bool) preg_match('/\bleads?\b/i', $body);
            $isTrainingCommand = (bool) preg_match('/\b(offline\s+)?trainings?\b/i', $body);

            // Robust detection of 10-digit Indian mobile number (handles spaces, dashes, +91, 91, 0 prefixes)
            $clientPhone = null;
            $clientPhoneClean = '';
            $matchedRawPhone = '';

            // Match +91 or 91 or 0 followed by 10 digits that can have optional spaces or dashes
            if (preg_match('/(?:(?:\+|0{0,2})91[\s\-]*)?([6-9](?:[\s\-]*\d){9})\b/', $body, $matches)) {
                $rawMatched = $matches[0];
                $digitsOnly = preg_replace('/[^\d]/', '', $matches[1]);
                if (strlen($digitsOnly) === 10 && in_array($digitsOnly[0], ['6','7','8','9'])) {
                    $clientPhoneClean = $digitsOnly;
                    $clientPhone = '+91 ' . $digitsOnly;
                    $matchedRawPhone = $rawMatched;
                }
            }

            // Fallback: If not matched by regex, check if extracting all digits from body yields a valid 10-digit Indian number
            if (empty($clientPhoneClean)) {
                $allDigits = preg_replace('/[^\d]/', '', $body);
                if (strlen($allDigits) === 12 && str_starts_with($allDigits, '91') && in_array($allDigits[2], ['6','7','8','9'])) {
                    $clientPhoneClean = substr($allDigits, 2);
                    $clientPhone = '+91 ' . $clientPhoneClean;
                    $matchedRawPhone = $body;
                } elseif (strlen($allDigits) === 11 && str_starts_with($allDigits, '0') && in_array($allDigits[1], ['6','7','8','9'])) {
                    $clientPhoneClean = substr($allDigits, 1);
                    $clientPhone = '+91 ' . $clientPhoneClean;
                    $matchedRawPhone = $body;
                } elseif (strlen($allDigits) === 10 && in_array($allDigits[0], ['6','7','8','9'])) {
                    $clientPhoneClean = $allDigits;
                    $clientPhone = '+91 ' . $clientPhoneClean;
                    $matchedRawPhone = $body;
                }
            }

            if (empty($clientPhoneClean)) {
                // Agent sent a message without a valid client phone number
                $tplGuide = get_system_notification_template($pdo, 'team_agent_help_guide', [
                    'agent_name' => $teamAgent['name']
                ]);

                if (!$tplGuide['found'] || $tplGuide['is_active']) {
                    $helpMsg = !empty($tplGuide['whatsapp_body']) ? $tplGuide['whatsapp_body'] : (
                        "Hello *{$teamAgent['name']}* 👋\n\n" .
                        "📌 *To Schedule Training:*\n" .
                        "`Training <10-digit mobile> <Trainer Name>` (Online)\n" .
                        "`Offline Training <10-digit mobile> <Trainer Name>` (Offline)\n" .
                        "• *Example:* `Training 7860510928 Harsh Saini`\n\n" .
                        "📌 *To Create & Assign a Lead:*\n" .
                        "`Lead <10-digit mobile> <Employee Name>`\n" .
                        "• *Example:* `Lead 7860510928 Sahil savita`\n\n" .
                        "📌 *To Create a Support Ticket:*\n" .
                        "`<10-digit mobile> - <Issue Description>`\n" .
                        "• *Example:* `9876543210 - Printer error`"
                    );
                    $whatsapp->sendText($from, $helpMsg);
                }
            } elseif ($isLeadCommand) {
                // =========================================================
                // CASE 1: LEAD CREATION & AUTO-ASSIGNMENT COMMAND
                // Format: "Lead 7860510928 Sahil savita" or "Lead 7860510928 Sahil savita - Note"
                // =========================================================

                // Extract specific tagged parameters: (source), (group)/(stage), (company)/(party), (assigned)
                $parsedSource = 'HO'; // Default source
                if (preg_match('/([a-zA-Z0-9\s\.\-]+?)\s*\(source\)/i', $body, $sm)) {
                    $parsedSource = trim($sm[1]);
                } elseif (preg_match('/(?:source|src)[\s\:\=]+([a-zA-Z0-9\s\.\-]+?)(?=\s*(?:\([a-zA-Z]+\)|[a-zA-Z]+[\:\=]|$|\n))/i', $body, $sm)) {
                    $parsedSource = trim($sm[1]);
                }

                $parsedGroup = 'Lead';
                if (preg_match('/([a-zA-Z0-9\s\.\-]+?)\s*\((?:group|stage)\)/i', $body, $gm)) {
                    $parsedGroup = ucwords(trim($gm[1]));
                } elseif (preg_match('/(?:group|stage)[\s\:\=]+([a-zA-Z0-9\s\.\-]+?)(?=\s*(?:\([a-zA-Z]+\)|[a-zA-Z]+[\:\=]|$|\n))/i', $body, $gm)) {
                    $parsedGroup = ucwords(trim($gm[1]));
                } elseif (stripos($body, 'demo') !== false) {
                    $parsedGroup = 'Demo Scheduled';
                }

                $parsedCompany = '';
                if (preg_match('/([a-zA-Z0-9\s\.\-]+?)\s*\((?:company|party|firm)\)/i', $body, $cm)) {
                    $parsedCompany = trim($cm[1]);
                } elseif (preg_match('/(?:company|party|firm|client)[\s\:\=]+([a-zA-Z0-9\s\.\-]+?)(?=\s*(?:\([a-zA-Z]+\)|[a-zA-Z]+[\:\=]|$|\n))/i', $body, $cm)) {
                    $parsedCompany = trim($cm[1]);
                }

                // Extract assignee text & remaining notes
                $assigneeRaw = '';
                if (preg_match('/([a-zA-Z0-9\s\.\-]+?)\s*\(assigned\)/i', $body, $am)) {
                    $assigneeRaw = trim($am[1]);
                    $assigneeRaw = preg_replace('/\bleads?\b/i', '', $assigneeRaw);
                    $assigneeRaw = preg_replace('/[0-9]{10}/', '', $assigneeRaw);
                    $assigneeRaw = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+/', '', trim($assigneeRaw)));
                } elseif (preg_match('/(?:assigned|to)[\s\:\=]+([a-zA-Z0-9\s\.\-]+?)(?=\s*(?:\([a-zA-Z]+\)|[a-zA-Z]+[\:\=]|$|\n))/i', $body, $am)) {
                    $assigneeRaw = trim($am[1]);
                }

                // Strip known command tokens to extract remaining raw text if needed
                $cleanRemaining = preg_replace('/\bleads?\b/i', '', $body);
                if (!empty($matchedRawPhone)) {
                    $cleanRemaining = str_replace($matchedRawPhone, '', $cleanRemaining);
                }
                if (!empty($clientPhoneClean)) {
                    $cleanRemaining = str_replace([$clientPhoneClean, '91' . $clientPhoneClean, '+91' . $clientPhoneClean], '', $cleanRemaining);
                }
                // Strip bracketed parameters
                $cleanRemaining = preg_replace('/[a-zA-Z0-9\s\.\-]+?\s*\((?:source|group|stage|company|party|assigned)\)/i', '', $cleanRemaining);
                $cleanRemaining = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+/', '', trim($cleanRemaining)));
                
                if (empty($assigneeRaw)) {
                    $assigneeRaw = $cleanRemaining;
                }
                $extraNotes = $cleanRemaining;

                // Match Employee / Team Member from team_agents table
                $allAgents = [];
                try {
                    $stmtAllAg = $pdo->query("SELECT id, emp_code, name, whatsapp_phone, department FROM team_agents WHERE status = 'Active'");
                    $allAgents = $stmtAllAg ? $stmtAllAg->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (Throwable $e) {}

                $matchedAgent = null;
                $matchedScore = 0;

                if (!empty($assigneeRaw) && !empty($allAgents)) {
                    foreach ($allAgents as $ag) {
                        $agName = trim($ag['name']);
                        if (empty($agName)) continue;

                        // 1. Direct case-insensitive match or contains
                        if (stripos($assigneeRaw, $agName) !== false) {
                            $matchedAgent = $ag;
                            $matchedScore = 100;
                            break;
                        }

                        // 2. Tokenized word comparison
                        $nameParts = preg_split('/\s+/', strtolower($agName));
                        $assigneeParts = preg_split('/[\s\-\:\,\;\|\.\/]+/', strtolower($assigneeRaw));

                        $common = array_intersect($nameParts, $assigneeParts);
                        if (!empty($common)) {
                            $score = count($common) * 35;
                            if (in_array($nameParts[0], $assigneeParts)) {
                                $score += 35; // Priority if first name matches
                            }
                            if ($score > $matchedScore) {
                                $matchedScore = $score;
                                $matchedAgent = $ag;
                            }
                        }
                    }
                }

                if ($matchedAgent && $matchedScore >= 35) {
                    $assignedName = $matchedAgent['name'];
                    $assignedPhone = $matchedAgent['whatsapp_phone'] ?? '';
                    $assignedEmpCode = $matchedAgent['emp_code'] ?? '';

                    // Clean matched agent name tokens from extraNotes
                    $agTokens = preg_split('/\s+/', $matchedAgent['name']);
                    foreach ($agTokens as $tok) {
                        if (strlen($tok) >= 2) {
                            $extraNotes = preg_replace('/\b' . preg_quote($tok, '/') . '\b/i', '', $extraNotes);
                        }
                    }
                    $extraNotes = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+|[\s\-\:\,\;\|\.\/]+$/', '', trim($extraNotes)));
                    $extraNotes = preg_replace('/\s+/', ' ', $extraNotes);
                } else {
                    // Fallback: check users table
                    $assignedName = 'Unassigned';
                    $assignedPhone = '';
                    $assignedEmpCode = '';

                    try {
                        $stmtUsers = $pdo->query("SELECT id, name, role FROM users WHERE status = 'Active'");
                        $allUsers = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
                        foreach ($allUsers as $u) {
                            if (!empty($u['name']) && !empty($assigneeRaw) && stripos($assigneeRaw, $u['name']) !== false) {
                                $assignedName = $u['name'];
                                $extraNotes = trim(preg_replace('/' . preg_quote($u['name'], '/') . '/i', '', $extraNotes));
                                break;
                            }
                        }
                    } catch (Throwable $eU) {}

                    if ($assignedName === 'Unassigned' && !empty($assigneeRaw)) {
                        $assignedName = ucwords(mb_strimwidth($assigneeRaw, 0, 40));
                    }
                }

                // Auto-lookup Party Name from client directory or previous leads if not explicitly provided
                $partyName = !empty($parsedCompany) ? $parsedCompany : "";
                if (empty($partyName)) {
                    try {
                        $stmtParty = $pdo->prepare("SELECT customer_id, party_name FROM client_directory WHERE mobile LIKE ? LIMIT 1");
                        $stmtParty->execute(['%' . $clientPhoneClean . '%']);
                        $pRow = $stmtParty->fetch(PDO::FETCH_ASSOC);
                        if (!empty($pRow['party_name'])) {
                            $partyName = $pRow['party_name'];
                        } else {
                            $stmtLeadL = $pdo->prepare("SELECT company, name FROM leads WHERE phone LIKE ? LIMIT 1");
                            $stmtLeadL->execute(['%' . $clientPhoneClean . '%']);
                            $lRow = $stmtLeadL->fetch(PDO::FETCH_ASSOC);
                            if (!empty($lRow['company'])) {
                                $partyName = $lRow['company'] . (!empty($lRow['name']) && $lRow['name'] !== $lRow['company'] ? " (" . $lRow['name'] . ")" : "");
                            }
                        }
                    } catch (Throwable $e) {}
                }

                $leadParty = !empty($partyName) ? $partyName : "WhatsApp Lead";
                $leadId = generate_lead_number($pdo);

                $actorName = $teamAgent ? "{$teamAgent['name']} ({$teamAgent['emp_code']})" : "WhatsApp System";
                $remarksText = "Lead created via WhatsApp by {$actorName}";
                if (!empty($extraNotes)) {
                    $remarksText .= " | Note: " . $extraNotes;
                }

                try {
                    // 1. Insert into leads table with source and group_stage
                    $stmtLead = $pdo->prepare("
                        INSERT INTO leads (
                            id, name, contact_person, company, phone, source,
                            priority, status, group_stage, assigned_to, assigned_by, remarks, enq_for, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            'warm', 'new', ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    $stmtLead->execute([
                        $leadId,
                        $leadParty,
                        $leadParty,
                        $leadParty,
                        $clientPhoneClean,
                        $parsedSource,
                        $parsedGroup,
                        $assignedName,
                        $actorName,
                        $remarksText,
                        !empty($extraNotes) ? $extraNotes : 'Team WhatsApp Lead'
                    ]);

                    // 2. Timeline log
                    try {
                        $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                        $stmtTime->execute([
                            $leadId,
                            $actorName,
                            "Lead created via WhatsApp and assigned to {$assignedName} [Source: {$parsedSource}, Group: {$parsedGroup}]" . (!empty($extraNotes) ? " (Note: {$extraNotes})" : "")
                        ]);
                    } catch (Throwable $eT) {}

                    // 3. Admin Notification
                    try {
                        $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, 'index.php?page=leads', 'info')");
                        $stmtNotif->execute([
                            "New Team Lead: " . $leadId,
                            "{$teamAgent['name']} assigned lead {$clientPhoneClean} to {$assignedName}"
                        ]);
                    } catch (Throwable $eN) {}

                    // 4. Send Instant WhatsApp Confirmation to Sender (Team Member who dropped the lead)
                    $tplLeadSender = get_system_notification_template($pdo, 'lead_created_confirmation', [
                        'lead_id'           => $leadId,
                        'lead_phone'        => $clientPhoneClean,
                        'party_name'        => (!empty($partyName) ? $partyName : 'N/A'),
                        'assigned_engineer' => $assignedName,
                        'source'            => $parsedSource,
                        'stage'             => $parsedGroup,
                        'notes'             => (!empty($extraNotes) ? $extraNotes : 'No note'),
                        'created_by'        => $actorName
                    ]);

                    if (!$tplLeadSender['found'] || $tplLeadSender['is_active']) {
                        $senderReply = !empty($tplLeadSender['whatsapp_body']) ? $tplLeadSender['whatsapp_body'] : (
                            "*Lead Successfully Created & Assigned!*\n\n" .
                            "• *Lead ID:* {$leadId}\n" .
                            "• *Customer Mobile:* +91 {$clientPhoneClean}\n" .
                            (!empty($partyName) ? "• *Party / Company:* {$partyName}\n" : "") .
                            "• *Assigned To:* {$assignedName}\n" .
                            "• *Source:* {$parsedSource}\n" .
                            "• *Group / Stage:* {$parsedGroup}\n" .
                            (!empty($extraNotes) ? "• *Note:* {$extraNotes}\n" : "") .
                            "• *Created By:* {$actorName}"
                        );
                        if (!empty($assignedPhone)) {
                            $senderReply .= "\n\nWhatsApp alert sent to {$assignedName} (" . format_phone_number($assignedPhone) . ")";
                        } else {
                            $senderReply .= "\n\nNote: WhatsApp alert not sent (Assignee phone not configured in Team Agents).";
                        }
                        $whatsapp->sendText($from, $senderReply);
                    }

                    // 5. Send Instant WhatsApp Alert to Assigned Employee (e.g. Harsh Saini)
                    if (!empty($assignedPhone)) {
                        $assigneePhoneFormatted = format_phone_number($assignedPhone);
                        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://friendlyaisolution.com/';
                        $tplLeadEmp = get_system_notification_template($pdo, 'lead_assigned_employee', [
                            'assigned_engineer' => $assignedName,
                            'created_by'        => $actorName,
                            'lead_id'           => $leadId,
                            'lead_phone'        => $clientPhoneClean,
                            'party_name'        => (!empty($partyName) ? $partyName : 'Valued Client'),
                            'source'            => $parsedSource,
                            'stage'             => $parsedGroup,
                            'notes'             => (!empty($extraNotes) ? $extraNotes : 'No additional note'),
                            'created_at'        => date('d-m-Y h:i A'),
                            'crm_link'          => "{$baseUrl}index.php?page=leads"
                        ]);

                        if (!$tplLeadEmp['found'] || $tplLeadEmp['is_active']) {
                            $assigneeAlert = !empty($tplLeadEmp['whatsapp_body']) ? $tplLeadEmp['whatsapp_body'] : (
                                "Hello *{$assignedName}*,\n\n" .
                                "You have a new lead assigned by *{$actorName}*.\n\n" .
                                "*Lead Details:*\n" .
                                "• *Lead ID:* {$leadId}\n" .
                                "• *Customer Mobile:* +91 {$clientPhoneClean}\n" .
                                (!empty($partyName) ? "• *Party / Company:* {$partyName}\n" : "") .
                                "• *Source:* {$parsedSource}\n" .
                                "• *Group / Stage:* {$parsedGroup}\n" .
                                (!empty($extraNotes) ? "• *Requirement / Note:* {$extraNotes}\n" : "") .
                                "• *Assigned By:* {$actorName}\n" .
                                "• *Date & Time:* " . date('d-m-Y h:i A') . "\n\n" .
                                "Please contact the customer promptly.\n\n" .
                                "CRM Lead Link: {$baseUrl}index.php?page=leads\n\n" .
                                "Marg Soft Solution"
                            );
                            $whatsapp->sendText($assigneePhoneFormatted, $assigneeAlert);
                        }
                    }

                } catch (Throwable $e) {
                    write_log('error', "Failed to create team drop lead: " . $e->getMessage());
                    $whatsapp->sendText($from, "Lead create karne me error aaya: " . $e->getMessage());
                }

            } elseif ($isTrainingCommand) {
                // =========================================================
                // CASE: TRAINING TICKET CREATION & AUTO-ASSIGNMENT
                // Format: "Training 7860510928 Harsh Saini" or "offline Training 7860510928 Harsh Saini"
                // =========================================================
                $isOffline = (bool) preg_match('/\boffline\b/i', $body);
                $trainingMode = $isOffline ? 'Offline (On-Site)' : 'Online (Google Meet)';

                // Extract trainer text & remaining notes
                $cleanRemaining = preg_replace('/\b(offline\s+)?trainings?\b/i', '', $body);
                if (!empty($matchedRawPhone)) {
                    $cleanRemaining = str_replace($matchedRawPhone, '', $cleanRemaining);
                }
                if (!empty($clientPhoneClean)) {
                    $cleanRemaining = str_replace([$clientPhoneClean, '91' . $clientPhoneClean, '+91' . $clientPhoneClean], '', $cleanRemaining);
                }
                $cleanRemaining = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+|[\s\-\:\,\;\|\.\/]+$/', '', trim($cleanRemaining)));
                $trainerRaw = $cleanRemaining;
                $extraNotes = $cleanRemaining;

                // Match Trainer from team_agents table
                $allAgents = [];
                try {
                    $stmtAllAg = $pdo->query("SELECT id, emp_code, name, whatsapp_phone, department FROM team_agents WHERE status = 'Active'");
                    $allAgents = $stmtAllAg ? $stmtAllAg->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (Throwable $e) {}

                $matchedTrainer = null;
                $matchedScore = 0;

                if (!empty($trainerRaw) && !empty($allAgents)) {
                    foreach ($allAgents as $ag) {
                        $agName = trim($ag['name']);
                        if (empty($agName)) continue;

                        // 1. Direct match
                        if (stripos($trainerRaw, $agName) !== false) {
                            $matchedTrainer = $ag;
                            $matchedScore = 100;
                            break;
                        }

                        // 2. Tokenized comparison
                        $nameParts = preg_split('/\s+/', strtolower($agName));
                        $trainerParts = preg_split('/[\s\-\:\,\;\|\.\/]+/', strtolower($trainerRaw));
                        $common = array_intersect($nameParts, $trainerParts);
                        if (!empty($common)) {
                            $score = count($common) * 35;
                            if (in_array($nameParts[0], $trainerParts)) {
                                $score += 35;
                            }
                            if ($score > $matchedScore) {
                                $matchedScore = $score;
                                $matchedTrainer = $ag;
                            }
                        }
                    }
                }

                if ($matchedTrainer && $matchedScore >= 35) {
                    $assignedTrainerName = $matchedTrainer['name'];
                    $assignedTrainerPhone = $matchedTrainer['whatsapp_phone'] ?? '';
                    $assignedTrainerCode = $matchedTrainer['emp_code'] ?? '';

                    $trTokens = preg_split('/\s+/', $matchedTrainer['name']);
                    foreach ($trTokens as $tok) {
                        if (strlen($tok) >= 2) {
                            $extraNotes = preg_replace('/\b' . preg_quote($tok, '/') . '\b/i', '', $extraNotes);
                        }
                    }
                    $extraNotes = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+|[\s\-\:\,\;\|\.\/]+$/', '', trim($extraNotes)));
                    $extraNotes = preg_replace('/\s+/', ' ', $extraNotes);
                } else {
                    $assignedTrainerName = 'Unassigned Trainer';
                    $assignedTrainerPhone = '';
                    $assignedTrainerCode = '';

                    try {
                        $stmtUsers = $pdo->query("SELECT id, name, role FROM users WHERE status = 'Active'");
                        $allUsers = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
                        foreach ($allUsers as $u) {
                            if (!empty($u['name']) && !empty($trainerRaw) && stripos($trainerRaw, $u['name']) !== false) {
                                $assignedTrainerName = $u['name'];
                                $extraNotes = trim(preg_replace('/' . preg_quote($u['name'], '/') . '/i', '', $extraNotes));
                                break;
                            }
                        }
                    } catch (Throwable $eU) {}

                    if ($assignedTrainerName === 'Unassigned Trainer' && !empty($trainerRaw)) {
                        $assignedTrainerName = ucwords(mb_strimwidth($trainerRaw, 0, 40));
                    }
                }

                // Auto-lookup Party Name & Details from client_directory or leads
                $partyName = "";
                $clientAddress = "";
                $clientProduct = "Marg ERP 9+";
                $leadIdRef = null;

                try {
                    $stmtParty = $pdo->prepare("SELECT customer_id, party_name, address, software_type FROM client_directory WHERE mobile LIKE ? LIMIT 1");
                    $stmtParty->execute(['%' . $clientPhoneClean . '%']);
                    $pRow = $stmtParty->fetch(PDO::FETCH_ASSOC);
                    if (!empty($pRow['party_name'])) {
                        $partyName = $pRow['party_name'];
                        $clientAddress = $pRow['address'] ?? '';
                        if (!empty($pRow['software_type'])) $clientProduct = $pRow['software_type'];
                    } else {
                        $stmtLeadL = $pdo->prepare("SELECT id, company, name, address, enq_for FROM leads WHERE phone LIKE ? LIMIT 1");
                        $stmtLeadL->execute(['%' . $clientPhoneClean . '%']);
                        $lRow = $stmtLeadL->fetch(PDO::FETCH_ASSOC);
                        if (!empty($lRow['company'])) {
                            $partyName = $lRow['company'] . (!empty($lRow['name']) && $lRow['name'] !== $lRow['company'] ? " (" . $lRow['name'] . ")" : "");
                            $clientAddress = $lRow['address'] ?? '';
                            if (!empty($lRow['enq_for'])) $clientProduct = $lRow['enq_for'];
                            $leadIdRef = $lRow['id'] ?? null;
                        }
                    }
                } catch (Throwable $e) {}

                $customerDisplayName = !empty($partyName) ? $partyName : "Client (+91 {$clientPhoneClean})";

                // Generate Unique TRN-XXXX Ticket ID
                $trainingTicketId = 'TRN-' . date('md') . '-' . rand(10, 99);
                try {
                    $chk = $pdo->prepare("SELECT id FROM training_sessions WHERE id = ?");
                    $chk->execute([$trainingTicketId]);
                    if ($chk->fetch()) {
                        $trainingTicketId = 'TRN-' . date('md') . '-' . rand(100, 999);
                    }
                } catch (Throwable $e) {}

                $remarksText = "Training requested via Team WhatsApp by {$teamAgent['name']} ({$teamAgent['emp_code']})";
                if (!empty($extraNotes)) {
                    $remarksText .= " | Note: " . $extraNotes;
                }

                try {
                    // 1. Insert into training_sessions table
                    $stmtTr = $pdo->prepare("
                        INSERT INTO training_sessions (
                            id, lead_id, customer, trainer, trainer_phone, dropped_by,
                            scheduled_at, mode, hours_completed, total_hours, current_day, total_days,
                            status, connect_status, phone, product, address, topics, remarks, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            NOW(), ?, 0, 6, 0, 3,
                            'scheduled', 'Pending Connect', ?, ?, ?, 'Basic & Advanced Operations', ?, NOW()
                        )
                    ");
                    $stmtTr->execute([
                        $trainingTicketId,
                        $leadIdRef,
                        $customerDisplayName,
                        $assignedTrainerName,
                        $assignedTrainerPhone,
                        $teamAgent['name'] . ' (' . $teamAgent['emp_code'] . ')',
                        $trainingMode,
                        $clientPhoneClean,
                        $clientProduct,
                        $clientAddress,
                        $remarksText
                    ]);

                    // 2. Timeline log if associated with a lead
                    if (!empty($leadIdRef)) {
                        try {
                            $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                            $stmtTime->execute([
                                $leadIdRef,
                                $teamAgent['name'] . ' (' . $teamAgent['emp_code'] . ')',
                                "Training Ticket {$trainingTicketId} created ({$trainingMode}) and allocated to trainer {$assignedTrainerName}"
                            ]);
                        } catch (Throwable $eT) {}
                    }

                    // 3. Admin Notification
                    try {
                        $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, 'index.php?page=training', 'info')");
                        $stmtNotif->execute([
                            "New Training Allocated: " . $trainingTicketId,
                            "{$teamAgent['name']} allocated {$trainingMode} training for {$clientPhoneClean} to {$assignedTrainerName}"
                        ]);
                    } catch (Throwable $eN) {}

                    // 4. Send Instant WhatsApp Confirmation to Sender (Team Member who dropped the training)
                    $tplTrConf = get_system_notification_template($pdo, 'training_created_confirmation', [
                        'ticket_id'      => $trainingTicketId,
                        'client_phone'   => $clientPhoneClean,
                        'party_name'     => (!empty($partyName) ? $partyName : 'N/A'),
                        'training_mode'  => $trainingMode,
                        'trainer_name'   => $assignedTrainerName,
                        'notes'          => (!empty($extraNotes) ? $extraNotes : 'No note'),
                        'created_by'     => $teamAgent['name']
                    ]);

                    if (!$tplTrConf['found'] || $tplTrConf['is_active']) {
                        $senderReply = !empty($tplTrConf['whatsapp_body']) ? $tplTrConf['whatsapp_body'] : (
                            "*Training Ticket Allocated!*\n\n" .
                            "• *Ticket ID:* `{$trainingTicketId}`\n" .
                            "• *Customer Mobile:* +91 {$clientPhoneClean}\n" .
                            (!empty($partyName) ? "• *Party Name:* {$partyName}\n" : "") .
                            "• *Training Mode:* *{$trainingMode}*\n" .
                            "• *Assigned Trainer:* *{$assignedTrainerName}*\n" .
                            (!empty($extraNotes) ? "• *Note:* {$extraNotes}\n" : "") .
                            "• *Allocated By:* {$teamAgent['name']}"
                        );
                        if (!empty($assignedTrainerPhone)) {
                            $senderReply .= "\n\nWhatsApp alert sent to Trainer {$assignedTrainerName} (" . format_phone_number($assignedTrainerPhone) . ")";
                        } else {
                            $senderReply .= "\n\nNote: Trainer WhatsApp alert not sent (Trainer phone not configured in Team Agents).";
                        }
                        $whatsapp->sendText($from, $senderReply);
                    }

                    // 5. Send Instant WhatsApp Alert to Assigned Trainer (e.g. Harsh Saini)
                    if (!empty($assignedTrainerPhone)) {
                        $trainerPhoneFormatted = format_phone_number($assignedTrainerPhone);
                        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://friendlyaisolution.com/';
                        $tplTrainer = get_system_notification_template($pdo, 'training_allocated_trainer', [
                            'trainer_name'  => $assignedTrainerName,
                            'created_by'    => $teamAgent['name'] . ' (' . $teamAgent['emp_code'] . ')',
                            'ticket_id'     => $trainingTicketId,
                            'client_name'   => (!empty($partyName) ? $partyName : $clientPhoneClean),
                            'client_phone'  => $clientPhoneClean,
                            'software_type' => $clientProduct,
                            'training_mode' => $trainingMode,
                            'scheduled_at'  => date('d-m-Y h:i A'),
                            'address'       => (!empty($clientAddress) ? $clientAddress : ''),
                            'notes'         => (!empty($extraNotes) ? $extraNotes : ''),
                            'crm_link'      => "{$baseUrl}index.php?page=training"
                        ]);

                        if (!$tplTrainer['found'] || $tplTrainer['is_active']) {
                            $trainerAlert = !empty($tplTrainer['whatsapp_body']) ? $tplTrainer['whatsapp_body'] : (
                                "Hi *{$assignedTrainerName}*,\n\n" .
                                "You have been allocated a new *Marg ERP Training* by *{$teamAgent['name']}*.\n\n" .
                                "*Training Details:*\n" .
                                "• *Ticket ID:* `{$trainingTicketId}`\n" .
                                "• *Customer Mobile:* +91 {$clientPhoneClean}\n" .
                                (!empty($partyName) ? "• *Party Name:* {$partyName}\n" : "") .
                                "• *Training Mode:* *{$trainingMode}*\n" .
                                "• *Software:* {$clientProduct}\n" .
                                (!empty($extraNotes) ? "• *Note:* {$extraNotes}\n" : "") .
                                "• *Allocated By:* {$teamAgent['name']} ({$teamAgent['emp_code']})\n" .
                                "• *Date & Time:* " . date('d-m-Y h:i A') . "\n\n" .
                                "Please connect with the client promptly to conduct or schedule Day 1 training session.\n\n" .
                                "*CRM Training Portal:* {$baseUrl}index.php?page=training\n\n" .
                                "*Marg Soft Solution*"
                            );
                            $whatsapp->sendText($trainerPhoneFormatted, $trainerAlert);
                        }
                    }

                    // 6. Send Instant Welcome WhatsApp to Customer (Client)
                    try {
                        $clientPhoneFormatted = format_phone_number($clientPhoneClean);
                        $tplCl = get_system_notification_template($pdo, 'training_scheduled_customer', [
                            'client_name'    => (!empty($partyName) ? $partyName : 'Valued Client'),
                            'ticket_id'      => $trainingTicketId,
                            'software_type'  => $clientProduct,
                            'trainer_name'   => $assignedTrainerName,
                            'trainer_phone'  => $assignedTrainerPhone,
                            'training_mode'  => $trainingMode,
                            'scheduled_at'   => date('d-m-Y h:i A'),
                            'total_days'     => '3',
                            'total_hours'    => '6'
                        ]);

                        if (!$tplCl['found'] || $tplCl['is_active']) {
                            $clientGreeting = !empty($tplCl['whatsapp_body']) ? $tplCl['whatsapp_body'] : (
                                "Namaste" . (!empty($partyName) ? " *{$partyName}*" : "") . "\n\n" .
                                "Aapki *Marg ERP Software Training* request successfully schedule ho gayi hai.\n\n" .
                                "*Training Details:*\n" .
                                "• *Ticket ID:* `{$trainingTicketId}`\n" .
                                "• *Assigned Trainer:* *{$assignedTrainerName}*\n" .
                                (!empty($assignedTrainerPhone) ? "• *Trainer Helpline:* +91 {$assignedTrainerPhone}\n" : "") .
                                "• *Mode:* *{$trainingMode}*\n\n" .
                                "Hamare trainer aapse jaldi hi training time aur software setup ke liye contact karenge.\n\n" .
                                "Helpdesk: +91 93050 45727\n" .
                                "*Marg Soft Solution*"
                            );
                            $whatsapp->sendText($clientPhoneFormatted, $clientGreeting);
                        }
                    } catch (Throwable $eCl) {}

                } catch (Throwable $e) {
                    write_log('error', "Failed to create team drop training: " . $e->getMessage());
                    $whatsapp->sendText($from, "Training ticket create karne me error aaya: " . $e->getMessage());
                }

            } else {
                // =========================================================
                // CASE 2: SUPPORT TICKET CREATION (No "Lead" keyword)
                // =========================================================
                $problemNote = $body;
                if (!empty($matchedRawPhone)) {
                    $problemNote = str_replace($matchedRawPhone, '', $problemNote);
                }
                if (!empty($clientPhoneClean)) {
                    $problemNote = str_replace([$clientPhoneClean, '91' . $clientPhoneClean, '+91' . $clientPhoneClean], '', $problemNote);
                }
                $problemNote = trim(preg_replace('/^[\s\-\:\,\;\|\.\/]+/', '', trim($problemNote)));
                $problemNote = preg_replace('/\s+/', ' ', $problemNote);
                if (empty($problemNote) || strlen($problemNote) < 2) {
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
                $ticketId = generate_ticket_number($pdo);

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

                    // Also sync insert into tickets table so sequence & reporting remain unified
                    try {
                        $stmtTktSync = $pdo->prepare("
                            INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, email, category, priority, description, status, created_at)
                            VALUES (?, ?, ?, ?, ?, '', 'Technical Support', 'Medium', ?, 'Open', NOW())
                        ");
                        $stmtTktSync->execute([
                            $ticketId,
                            $licenseNo,
                            $partyName,
                            $partyName,
                            $clientPhoneClean,
                            $problemNote
                        ]);
                    } catch (Throwable $eTSync) {}

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

                } catch (Throwable $e) {
                    write_log('error', "Failed to create team drop ticket: " . $e->getMessage());
                    $whatsapp->sendText($from, "⚠️ Ticket generate karne me error aaya: " . $e->getMessage());
                }
            }

            // Exit this message loop so normal customer flow is NOT triggered
            continue;
        }

        // =========================================================
        // CASE 1: Standard Customer Text Messages (Greetings, Direct Questions, Support)
        // =========================================================
        if ($msgType === 'text') {
            $body = trim($msg['text']['body'] ?? '');
            $cleanBody = strtolower($body);

            // Compliance: Handle Opt-Out keywords (STOP, UNSUBSCRIBE, OPT OUT)
            $optOutKeywords = ['stop', 'unsubscribe', 'opt out', 'optout', 'cancel'];
            if (in_array($cleanBody, $optOutKeywords, true)) {
                $optOutResponse = "You have successfully opted out of automated WhatsApp notifications from Marg Soft Solution.\n\nTo re-subscribe or request technical support at any time, simply send *\"Hi\"* or *\"Support\"*.\n\nThank you! 🙏";
                $whatsapp->sendText($from, $optOutResponse);
                continue;
            }

            // 1. Check if conversation has active Human Takeover (Muted AI)
            $isMuted = false;
            if ($pdo) {
                try {
                    $cleanFrom = preg_replace('/[^\d]/', '', $from);
                    $c10 = substr($cleanFrom, -10);
                    $stmtMute = $pdo->prepare("SELECT is_muted FROM ai_chat_sessions WHERE phone = ? OR phone LIKE ? LIMIT 1");
                    $stmtMute->execute([$from, "%$c10%"]);
                    $isMuted = (bool)$stmtMute->fetchColumn();
                } catch (Throwable $eM) {}
            }

            if ($isMuted) {
                // A staff agent has taken over this conversation via Team Inbox
                // Message is already logged in message_logs; do not auto-reply
                continue;
            }

            // 2. Reschedule Demo Keywords Detection (with 12-Hour Validity Check)
            $isRescheduleIntent = str_contains($cleanBody, 'reschedule') 
                || str_contains($cleanBody, 'reshuclue')
                || str_contains($cleanBody, 'reshudle')
                || str_contains($cleanBody, 'change demo')
                || (str_contains($cleanBody, 'demo') && (str_contains($cleanBody, 'change') || str_contains($cleanBody, 'update') || str_contains($cleanBody, 'shift') || str_contains($cleanBody, 'time change') || str_contains($cleanBody, 'date change')));

            // Slot mapping dictionary
            $slotMap = [
                'slot_any' => 'Any Time (Flexible)',
                'slot_1'   => '10:00 AM - 12:00 PM',
                'slot_2'   => '12:00 PM - 02:00 PM',
                'slot_3'   => '02:00 PM - 04:00 PM',
                'slot_4'   => '04:00 PM - 06:00 PM'
            ];

            if ($isRescheduleIntent) {
                // Find existing lead & demo by sender's phone (phone, secondary_phone, or remarks)
                $clean10 = substr(preg_replace('/[^\d]/', '', $from), -10);
                $stmtLD = $pdo->prepare("
                    SELECT d.id AS demo_id, d.scheduled_at, d.feedback, d.created_at, l.id AS lead_id, l.name,
                           TIMESTAMPDIFF(HOUR, d.created_at, NOW()) AS hours_since_created
                    FROM demos d
                    JOIN leads l ON d.lead_id = l.id
                    WHERE (RIGHT(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), 10) = ? 
                        OR RIGHT(REPLACE(REPLACE(l.secondary_phone, ' ', ''), '-', ''), 10) = ?
                        OR l.remarks LIKE ?)
                    ORDER BY d.id DESC LIMIT 1
                ");
                $stmtLD->execute([$clean10, $clean10, "%$clean10%"]);
                $demoInfo = $stmtLD->fetch(PDO::FETCH_ASSOC);

                if ($demoInfo && intval($demoInfo['hours_since_created'] ?? 999) <= 12) {
                    // Eligible for reschedule (Within 12 Hours)
                    $currDate = date('d-M-Y', strtotime($demoInfo['scheduled_at']));
                    $rawSlot = !empty($demoInfo['feedback']) ? preg_replace('/^Time Slot:\s*/i', '', explode('|', $demoInfo['feedback'])[0]) : 'Selected Slot';
                    $currTime = $slotMap[trim($rawSlot)] ?? trim($rawSlot);

                    $reschedBody = "Aapka demo filhal *{$currDate}* ({$currTime}) ke liye scheduled hai.\n\nNayi Date aur Time slot select karne ke liye neeche diye gaye button par click karein:";
                    $demoFlowId = '1611148110659211';
                    $whatsapp->sendFlow($from, $demoFlowId, "Reschedule Demo", $reschedBody, "DEMO_SCREEN", null, "Marg ERP Demo Reschedule", "Reschedule Window Active (12h)");
                } elseif ($demoInfo) {
                    // Demo exists but expired (> 12 hours)
                    $expireMsg = "⚠️ *Demo Reschedule Window Expired*\n\n" .
                                 "Aapka demo booking samay 12 ghante se adhik ho chuka hai, isliye self-reschedule uplabdh nahi hai.\n\n" .
                                 "Kripya demo reschedule karwane ke liye hamare support executive se sampark karein:\n" .
                                 "📞 *7523830026* / *9170009697*\n\n" .
                                 "Naya demo schedule karne ke liye *\"Book Demo\"* likhein. Dhanyawad! 🙏";
                    $whatsapp->sendText($from, $expireMsg);
                } else {
                    // No demo booked yet
                    $noDemoMsg = "Aapka pehle se koi scheduled demo record nahi mila hai.\n\nNaya demo book karne ke liye *\"Book Demo\"* likhein ya neeche button se schedule karein:";
                    $demoFlowId = '1611148110659211';
                    $whatsapp->sendFlow($from, $demoFlowId, "Book Demo", $noDemoMsg, "DEMO_SCREEN", null, "Marg ERP Live Demo", "Marg Soft Solution");
                }
                continue;
            }

            // 2.4 Check Demo Schedule Status Intent ("Mera demo kab hai?", "When is my demo?", "Demo status")
            $isCheckDemoIntent = preg_match('/\b(kab\s+hai|kab\s+h|kab\s+hoga|when\s+is|check\s+demo|demo\s+check|demo\s+status|demo\s+details|demo\s+timing|demo\s+time|demo\s+date|demo\s+detail|mera\s+demo)\b/i', $cleanBody)
                || (str_contains($cleanBody, 'demo') && (str_contains($cleanBody, 'kab') || str_contains($cleanBody, 'status') || str_contains($cleanBody, 'check') || str_contains($cleanBody, 'batao') || str_contains($cleanBody, 'kis time') || str_contains($cleanBody, 'kis din')));

            if ($isCheckDemoIntent) {
                $clean10 = substr(preg_replace('/[^\d]/', '', $from), -10);
                $stmtLD = $pdo->prepare("
                    SELECT d.id AS demo_id, d.scheduled_at, d.feedback, d.mode, d.status AS demo_status,
                           l.id AS lead_id, l.name, l.phone,
                           TIMESTAMPDIFF(HOUR, d.created_at, NOW()) AS hours_since_created
                    FROM demos d
                    JOIN leads l ON d.lead_id = l.id
                    WHERE (RIGHT(REPLACE(REPLACE(l.phone, ' ', ''), '-', ''), 10) = ? 
                        OR RIGHT(REPLACE(REPLACE(l.secondary_phone, ' ', ''), '-', ''), 10) = ?
                        OR l.remarks LIKE ?)
                    ORDER BY d.id DESC LIMIT 1
                ");
                $stmtLD->execute([$clean10, $clean10, "%$clean10%"]);
                $demoInfo = $stmtLD->fetch(PDO::FETCH_ASSOC);

                if ($demoInfo && !empty($demoInfo['scheduled_at'])) {
                    $demoDateFormatted = date('d-M-Y (l)', strtotime($demoInfo['scheduled_at']));
                    $rawSlot = !empty($demoInfo['feedback']) ? preg_replace('/^Time Slot:\s*/i', '', explode('|', $demoInfo['feedback'])[0]) : 'Selected Slot';
                    $timeSlot = $slotMap[trim($rawSlot)] ?? trim($rawSlot);
                    $clientName = (!empty($demoInfo['name']) && $demoInfo['name'] !== 'WhatsApp Lead') ? $demoInfo['name'] : 'Customer';
                    $demoMode = !empty($demoInfo['mode']) ? $demoInfo['mode'] : 'Online Live Walkthrough';
                    $leadRef = $demoInfo['lead_id'];

                    $statusMsg = "📋 *Marg ERP Demo Schedule Details* 🚀\n\n" .
                                 "Namaste *{$clientName}*! Aapke demo ki details yeh hain:\n\n" .
                                 "📅 *Demo Date:* {$demoDateFormatted}\n" .
                                 "⏰ *Time Slot:* {$timeSlot}\n" .
                                 "💻 *Mode:* {$demoMode}\n" .
                                 "📋 *Lead Ref ID:* {$leadRef}\n" .
                                 "✅ *Status:* Confirmed / Scheduled\n\n" .
                                 "📌 *Note:* Hamaare Marg product specialist aapke select kiye gaye time par connect karenge.\n\n" .
                                 "🔄 *Reschedule karna chahte hain?*\n" .
                                 "Agar aap date/time badalna chahte hain to simply reply karein: *\"Reschedule Demo\"* (Booking ke 12h ke andar).\n\n" .
                                 "📞 Support: *7523830026* / *9170009697*\n" .
                                 "Thank you for choosing Marg ERP! 🙏";

                    $whatsapp->sendText($from, $statusMsg);
                } else {
                    // No demo scheduled yet for this number
                    $noDemoBody = "Namaste! Aapke is WhatsApp number (+{$from}) par abhi koi Marg ERP live demo scheduled nahi hai.\n\n" .
                                  "Kya aap Marg ERP AI+ software ka live product walkthrough dekhna chahte hain?\n\n" .
                                  "Neeche diye gaye button par click karke apna free demo slot schedule karein:";
                    $demoFlowId = '1611148110659211';
                    $whatsapp->sendFlow($from, $demoFlowId, "Book Free Demo", $noDemoBody, "DEMO_SCREEN", null, "Marg ERP Demo Booking", "Free 1-on-1 Walkthrough");
                }
                continue;
            }

            // 2.5 Explicit "Book Demo" keyword intent
            if ($cleanBody === 'book demo' || $cleanBody === 'demo book' || $cleanBody === 'schedule demo') {
                $demoFlowId = '1611148110659211';
                $whatsapp->sendFlow($from, $demoFlowId, "Book Demo", "Please choose your preferred date and time for Marg ERP Live Demo walkthrough.", "DEMO_SCREEN", null, "Marg ERP Live Demo", "Marg Soft Solution");
                continue;
            }

            // 3. Intelligent Greeting Detection (Avoid repetitive spamming of marketing banner if conversation is active)
            $explicitMenuWords = ['menu', 'start', 'options', 'main menu'];
            $isExplicitMenu = in_array($cleanBody, $explicitMenuWords, true);

            $greetings = ['hi', 'hello', 'hey', 'hii', 'namaste', 'hola'];
            $wordCount = str_word_count($cleanBody);
            $isPureGreeting = ($wordCount <= 2) && in_array($cleanBody, $greetings, true);

            // Check if there was recent interaction in the last 2 hours
            $hasRecentInteraction = false;
            try {
                $stmtRecent = $pdo->prepare("SELECT COUNT(*) FROM message_logs WHERE recipient_or_sender = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)");
                $stmtRecent->execute([$from]);
                $hasRecentInteraction = ($stmtRecent->fetchColumn() > 2);
            } catch (Throwable $eR) {}

            // Send full interactive welcome banner ONLY on explicit menu request or cold first message
            if ($isExplicitMenu || ($isPureGreeting && !$hasRecentInteraction)) {
                $tplGreet = get_system_notification_template($pdo, 'bot_greeting_welcome', []);
                $welcomeText = !empty($tplGreet['whatsapp_body']) ? $tplGreet['whatsapp_body'] : "Welcome To Marg Soft Solution\nIndian business management and accounting software designed for small and medium businesses. It helps companies manage daily operations such as billing, accounting, inventory, GST compliance, sales, purchases, and reporting from a single platform.";
                $buttons = [
                    ['id' => 'btn_sales', 'title' => 'Sales'],
                    ['id' => 'btn_support', 'title' => 'Support']
                ];
                $headerImage = "https://datapartner.btpr.online/ProductPictures/20851800671_download(4).png";
                $whatsapp->sendReplyButtons($from, $welcomeText, $buttons, "Welcome to Marg Soft Solution", "Please select an option", $headerImage);
                continue;
            }

            // 4. All Support Queries, Operational Questions, Sales & Consultations -> Super-Smart AI Assistant
            handleAISalesAssistantInteraction($whatsapp, $pdo, $from, $body);
            continue;
        }

        // =========================================================
        // CASE 2: Interactive Button Replies (Sales, Support, AMC, Billing, Offers, Demo)
        // =========================================================
        elseif ($msgType === 'interactive' && isset($msg['interactive']['button_reply'])) {
            $buttonId    = $msg['interactive']['button_reply']['id'] ?? '';
            $buttonTitle = strtolower($msg['interactive']['button_reply']['title'] ?? '');

            // Option 0: Demo Clicked (e.g. "BOOK DEMO", "DEMO")
            if (str_contains($buttonTitle, 'demo') || str_contains($buttonId, 'demo')) {
                $demoFlowId = '1611148110659211';
                $whatsapp->sendFlow($from, $demoFlowId, "Book Demo", "Please choose your preferred date and time for Marg ERP Live Demo walkthrough.", "DEMO_SCREEN", null, "Marg ERP Live Demo", "Marg Soft Solution");
            }

            // Option A: Sales Clicked -> AI Sales Assistant engages
            elseif ($buttonId === 'btn_sales' || $buttonTitle === 'sales') {
                handleAISalesAssistantInteraction($whatsapp, $pdo, $from, "I want to know about Marg ERP software editions, pricing, and schedule a demo.");
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
                $tplBank = get_system_notification_template($pdo, 'bank_payment_details', [
                    'account_name'   => 'MARG SOFT SOLUTION',
                    'bank_name'      => 'HDFC Bank',
                    'account_number' => '50200067891234',
                    'ifsc_code'      => 'HDFC0001234',
                    'branch'         => 'Main Branch',
                    'account_type'   => 'Current Account',
                    'upi_id'         => 'margsoft@upi',
                    'notes'          => 'Please transfer payment and send screenshot here.'
                ]);
                $bankResponse = !empty($tplBank['whatsapp_body']) ? $tplBank['whatsapp_body'] : "🏦 *Marg Soft Solution - Official Bank & UPI Payment Details*\n\nAccount Name: *MARG SOFT SOLUTION*\nBank Name: *HDFC Bank*\nA/C No: *50200067891234*\nIFSC Code: *HDFC0001234*\nBranch: *Main Branch*\nUPI ID: *margsoft@upi*\n\nPlease transfer payment and send screenshot here. Thank you! 🙏";
                $whatsapp->sendText($from, $bankResponse);
            }

            // Option D: Request Callback Clicked
            elseif ($buttonId === 'btn_request_call' || str_contains($buttonTitle, 'callback') || str_contains($buttonTitle, 'call')) {
                $tplCb = get_system_notification_template($pdo, 'bot_callback_request', [
                    'helpline' => '7523830026'
                ]);
                $callResponse = !empty($tplCb['whatsapp_body']) ? $tplCb['whatsapp_body'] : "📞 *Support Callback Request Received*\n\nThank you! Our support engineer has been notified and will call your mobile number shortly.\n\nFor immediate help, call: *7523830026*\nThank you for choosing Marg ERP! 🙏";
                $whatsapp->sendText($from, $callResponse);
            }

            // Option E: Send Screenshot Clicked
            elseif ($buttonId === 'btn_share_screenshot' || str_contains($buttonTitle, 'screenshot')) {
                $ssResponse = "📸 *Payment Screenshot*\n\nThank you! Please attach and send your payment transfer screenshot directly in this chat thread. Our accounts team will verify and update your receipt.";
                $whatsapp->sendText($from, $ssResponse);
            }

            // Option F: Offer / Commercial Query Clicked
            elseif ($buttonId === 'btn_claim_offer' || str_contains($buttonTitle, 'claim') || str_contains($buttonTitle, 'discount')) {
                $promoResponse = "📋 *Marg ERP Best Commercial Package*\n\nMarg ERP software ke prices company ki taraf se standard aur fixed hain. Isme aapko full software license ke sath free onboarding training, GST setup aur dedicated support provide kiya jata hai.\n\nSpecial customized package aur free live demo ke liye hamare sales advisor aapse jald hi sampark karenge.\n\nHelpline: *7523830026* / *9170009697* 🙏";
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

            // Check if this is a Sales or Demo Flow Submission
            $isSalesFlow = isset($flowData['requirement']) 
                || (isset($flowData['flow_type']) && $flowData['flow_type'] === 'sales') 
                || isset($flowData['demo_date'])
                || isset($flowData['full_name'])
                || (!isset($flowData['license_number']) && !isset($flowData['problem']) && !isset($flowData['c1']));

            if ($isSalesFlow && (isset($flowData['customer_name']) || isset($flowData['full_name']) || isset($flowData['requirement']) || isset($flowData['phone_number']) || isset($flowData['phone']) || isset($flowData['demo_date']))) {
                // =========================================================
                // PROCESS SALES / DEMO FLOW -> LEAD & DEMO GENERATION
                // =========================================================
                $leadName    = trim($flowData['full_name'] ?? $flowData['customer_name'] ?? $flowData['name'] ?? $flowData['contact_person'] ?? 'Customer');
                $formPhone   = trim($flowData['phone'] ?? $flowData['phone_number'] ?? $flowData['callback_number'] ?? $flowData['mobile_number'] ?? '');
                $senderPhone = format_phone_number($from);
                
                // Primary phone is always the verified WhatsApp sender number; alternate form phone goes to secondary!
                $leadPhone   = !empty($senderPhone) ? $senderPhone : (!empty($formPhone) ? format_phone_number($formPhone) : $from);
                $secondaryPhone = (!empty($formPhone) && substr($formPhone, -10) !== substr($leadPhone, -10)) ? $formPhone : '';

                $demoDate    = trim($flowData['demo_date'] ?? '');
                $rawDemoTime = trim($flowData['demo_time'] ?? '');
                $slotMap = [
                    'slot_any' => 'Any Time (Flexible)',
                    'slot_1'   => '10:00 AM - 12:00 PM',
                    'slot_2'   => '12:00 PM - 02:00 PM',
                    'slot_3'   => '02:00 PM - 04:00 PM',
                    'slot_4'   => '04:00 PM - 06:00 PM'
                ];
                $demoTime = $slotMap[$rawDemoTime] ?? (!empty($rawDemoTime) ? $rawDemoTime : 'Selected Slot');
                
                if (!empty($demoDate)) {
                    $requirement = "Marg ERP Demo scheduled for " . $demoDate . (!empty($demoTime) ? " (" . $demoTime . ")" : "");
                } else {
                    $requirement = trim($flowData['requirement'] ?? $flowData['message'] ?? $flowData['notes'] ?? 'General Sales Inquiry');
                }
                
                $companyName = trim($flowData['company'] ?? $flowData['firm_name'] ?? $leadName);

                if ($pdo) {
                    try {
                        // -------------------------------------------------------------
                        // 1. LEAD DEDUPLICATION CHECK
                        // Check if lead already exists in CRM by form phone or sender phone
                        // -------------------------------------------------------------
                        $cleanFlowDigits = !empty($formPhone) ? substr(preg_replace('/[^\d]/', '', $formPhone), -10) : '';
                        $cleanFromDigits = substr(preg_replace('/[^\d]/', '', $from), -10);
                        $phoneDigits = array_unique(array_filter([$cleanFlowDigits, $cleanFromDigits]));

                        $existingLead = null;
                        if (!empty($phoneDigits)) {
                            $clauses = [];
                            $params = [];
                            foreach ($phoneDigits as $d) {
                                if (strlen($d) === 10) {
                                    $clauses[] = "RIGHT(REPLACE(REPLACE(phone, ' ', ''), '-', ''), 10) = ?";
                                    $params[] = $d;
                                    $clauses[] = "RIGHT(REPLACE(REPLACE(secondary_phone, ' ', ''), '-', ''), 10) = ?";
                                    $params[] = $d;
                                }
                            }
                            if (!empty($clauses)) {
                                $stmtFind = $pdo->prepare("SELECT * FROM leads WHERE " . implode(' OR ', $clauses) . " ORDER BY id DESC LIMIT 1");
                                $stmtFind->execute($params);
                                $existingLead = $stmtFind->fetch(PDO::FETCH_ASSOC) ?: null;
                            }
                        }

                        $isReschedule = false;

                        if ($existingLead) {
                            // =========================================================
                            // CASE A: EXISTING LEAD FOUND -> NO DUPLICATE!
                            // UPDATE GROUP TO 'Demo Scheduled', UPDATE REMARKS & RESCHEDULE
                            // =========================================================
                            $leadId = $existingLead['id'];
                            $updateName = (!empty($existingLead['name']) && $existingLead['name'] !== 'WhatsApp Lead') ? $existingLead['name'] : $leadName;

                            // Check if a demo was already scheduled (Reschedule scenario)
                            $stmtDCheck = $pdo->prepare("SELECT id, status, scheduled_at FROM demos WHERE lead_id = ? AND status IN ('scheduled', 'pending') ORDER BY id DESC LIMIT 1");
                            $stmtDCheck->execute([$leadId]);
                            $existingDemo = $stmtDCheck->fetch(PDO::FETCH_ASSOC);

                            $isReschedule = ($existingLead['status'] === 'demo_scheduled' || !empty($existingDemo));
                            $nowStr = date('d-m-Y H:i');
                            $actionLabel = $isReschedule ? "Demo Rescheduled" : "Demo Scheduled";

                            // Append booking details to remarks
                            $remarkLine = "[{$actionLabel} via WhatsApp Flow on {$nowStr}]: Date: {$demoDate}, Time: {$demoTime}"
                                . (!empty($leadPhone) ? " | Form Mobile: {$leadPhone}" : "")
                                . " | WhatsApp: +{$from}";
                            $updatedRemarks = !empty($existingLead['remarks']) ? $existingLead['remarks'] . "\n" . $remarkLine : $remarkLine;

                            // Update secondary_phone if form phone differs from existing primary phone
                            $secPhone = (!empty($leadPhone) && $leadPhone !== $existingLead['phone']) ? $leadPhone : $existingLead['secondary_phone'];

                            // Update existing lead status, group_stage, remarks, and timestamp
                            $stmtUpdLead = $pdo->prepare("UPDATE leads SET name = ?, contact_person = ?, status = 'demo_scheduled', group_stage = 'Demo Scheduled', remarks = ?, enq_for = ?, secondary_phone = ?, updated_at = NOW() WHERE id = ?");
                            $stmtUpdLead->execute([$updateName, $updateName, $updatedRemarks, $requirement, $secPhone, $leadId]);

                            // Update or Insert in demos table
                            if (!empty($demoDate)) {
                                try {
                                    $schedAt = date('Y-m-d H:i:s', strtotime($demoDate . ' 11:00:00'));
                                    if ($existingDemo) {
                                        // Update existing scheduled demo (Reschedule)
                                        $stmtUpdDemo = $pdo->prepare("UPDATE demos SET scheduled_at = ?, status = 'scheduled', feedback = ? WHERE id = ?");
                                        $stmtUpdDemo->execute([$schedAt, "Time Slot: {$demoTime} | Rescheduled via WhatsApp Flow", $existingDemo['id']]);
                                    } else {
                                        // Create demo record under this existing lead
                                        $demoId = 'DEMO-' . date('ymd') . '-' . rand(100, 999);
                                        $stmtInsDemo = $pdo->prepare("INSERT INTO demos (id, company_id, lead_id, scheduled_at, mode, engineer, status, feedback) VALUES (?, 1, ?, ?, 'Online', 'Assigned Specialist', 'scheduled', ?)");
                                        $stmtInsDemo->execute([$demoId, $leadId, $schedAt, "Time Slot: {$demoTime} | Booked via WhatsApp Flow"]);
                                    }
                                } catch (Throwable $eD) {}
                            }

                            // Timeline entry for existing lead
                            try {
                                $timelineText = $isReschedule
                                    ? "Demo Rescheduled to {$demoDate} ({$demoTime}) by customer via WhatsApp Flow"
                                    : "Demo Scheduled for {$demoDate} ({$demoTime}) via WhatsApp Flow (Group moved to Demo Scheduled)";
                                $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, 'WhatsApp Bot', ?)");
                                $stmtTime->execute([$leadId, $timelineText]);
                            } catch (Throwable $eT) {}

                            // Notification for Admin
                            try {
                                $notifTitle = $isReschedule ? "Marg ERP Demo Rescheduled" : "Marg ERP Demo Booked";
                                $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, 'index.php?page=leads', 'info')");
                                $stmtNotif->execute([$notifTitle, "Lead {$leadId} ({$updateName}) {$actionLabel} to {$demoDate} {$demoTime}"]);
                            } catch (Throwable $eN) {}

                        } else {
                            // =========================================================
                            // CASE B: NEW LEAD CREATION
                            // =========================================================
                            $leadId = generate_lead_number($pdo);
                            $updateName = $leadName;

                            $nowStr = date('d-m-Y H:i');
                            $initialRemark = "[Demo Booked via WhatsApp Flow on {$nowStr}]: Date: {$demoDate}, Time: {$demoTime}"
                                . (!empty($leadPhone) ? " | Form Mobile: {$leadPhone}" : "")
                                . " | WhatsApp: +{$from}";

                            $stmtLead = $pdo->prepare("INSERT INTO leads (id, name, contact_person, company, phone, secondary_phone, enq_for, remarks, source, status, group_stage, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'WhatsApp Flow', 'demo_scheduled', 'Demo Scheduled', 'warm', NOW())");
                            $stmtLead->execute([
                                $leadId,
                                $leadName,
                                $leadName,
                                $companyName,
                                $leadPhone,
                                $secondaryPhone,
                                $requirement,
                                $initialRemark
                            ]);

                            // Insert in demos table
                            if (!empty($demoDate)) {
                                try {
                                    $demoId = 'DEMO-' . date('ymd') . '-' . rand(100, 999);
                                    $schedAt = date('Y-m-d H:i:s', strtotime($demoDate . ' 11:00:00'));
                                    $stmtDemo = $pdo->prepare("INSERT INTO demos (id, company_id, lead_id, scheduled_at, mode, engineer, status, feedback) VALUES (?, 1, ?, ?, 'Online', 'Assigned Specialist', 'scheduled', ?)");
                                    $stmtDemo->execute([$demoId, $leadId, $schedAt, "Time Slot: {$demoTime} | Booked via WhatsApp Flow"]);
                                } catch (Throwable $eD) {}
                            }

                            // Timeline entry
                            try {
                                $actionMsg = !empty($demoDate) ? "New Lead created & Demo booked for {$demoDate} {$demoTime} via WhatsApp Demo Flow" : "New Lead captured via WhatsApp Sales Flow";
                                $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, 'WhatsApp Bot', ?)");
                                $stmtTime->execute([$leadId, $actionMsg]);
                            } catch (Throwable $eT) {}

                            // Notification for Admin
                            try {
                                $notifTitle = !empty($demoDate) ? "New Marg ERP Demo Booked" : "New WhatsApp Sales Lead";
                                $stmtNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', ?, ?, 'index.php?page=leads', 'info')");
                                $stmtNotif->execute([$notifTitle, "Lead {$leadId} ({$leadName}) booked demo for {$demoDate} {$demoTime}"]);
                            } catch (Throwable $eN) {}
                        }

                        // Send Confirmation Message to Customer
                        if (!empty($demoDate)) {
                            if ($isReschedule) {
                                $confirmMsg = "✅ *Marg ERP Demo Rescheduled Successfully!* 🔄\n\n" .
                                              "Dear *{$updateName}*,\n" .
                                              "Aapka live product walkthrough demo successfully reschedule ho gaya hai:\n\n" .
                                              "📅 *New Demo Date:* {$demoDate}\n" .
                                              "⏰ *New Time Slot:* {$demoTime}\n" .
                                              "📞 *Contact Number:* {$leadPhone}\n" .
                                              "📋 *Lead Ref ID:* {$leadId}\n\n" .
                                              "Hamaari Marg product specialist team aapke select kiye gaye slot par connect karegi.\n\n" .
                                              "For instant support, call: *7523830026* / *9170009697*\n\n" .
                                              "Thank you! 🙏";
                            } else {
                                $tplDemo = get_system_notification_template($pdo, 'demo_scheduled_confirmation', [
                                    'demo_id'       => $leadId,
                                    'client_name'   => $updateName,
                                    'party_name'    => (!empty($partyName) ? $partyName : 'Marg ERP User'),
                                    'software_type' => 'Marg ERP AI+ Software',
                                    'scheduled_at'  => "{$demoDate} ({$demoTime})"
                                ]);

                                $confirmMsg = !empty($tplDemo['whatsapp_body']) ? $tplDemo['whatsapp_body'] : (
                                    "✅ *Marg ERP Demo Scheduled Successfully!* 🚀\n\n" .
                                    "Dear *{$updateName}*,\n" .
                                    "Thank you for booking a Live Demo walkthrough of Marg ERP AI+ Software.\n\n" .
                                    "📅 *Demo Date:* {$demoDate}\n" .
                                    "⏰ *Time Slot:* {$demoTime}\n" .
                                    "📞 *Contact Number:* {$leadPhone}\n" .
                                    "📋 *Enquiry Ref:* {$leadId}\n\n" .
                                    "Our product specialist will connect with you at your chosen slot.\n\n" .
                                    "For instant assistance, you can call us at: *7523830026* / *9170009697*\n\n" .
                                    "Thank you! 🙏"
                                );
                            }
                        } else {
                            $tplSale = get_system_notification_template($pdo, 'lead_sales_inquiry_ack', [
                                'client_name' => $updateName,
                                'lead_id'     => $leadId,
                                'lead_phone'  => $leadPhone,
                                'helpline'    => '7523830026'
                            ]);

                            $confirmMsg = !empty($tplSale['whatsapp_body']) ? $tplSale['whatsapp_body'] : (
                                "✅ *Sales Enquiry Received Successfully*\n\n" .
                                "Dear *{$updateName}*,\n" .
                                "Thank you for contacting Marg Soft Solution.\n\n" .
                                "📋 *Enquiry Ref:* {$leadId}\n" .
                                "Our sales representative will call you on *{$leadPhone}* shortly.\n\n" .
                                "For instant assistance, you can call us at: *7523830026*\n\n" .
                                "Thank you! 🙏"
                            );
                        }

                        $whatsapp->sendText($from, $confirmMsg);

                    } catch (Throwable $eLead) {
                        write_log('error', "Failed saving sales lead from webhook: " . $eLead->getMessage());
                    }
                }
                exit;
            }

            $licenseNo    = $flowData['license_number'] ?? $flowData['license_no'] ?? $flowData['client_id'] ?? $flowData['c1'] ?? 'N/A';
            $customerName = $flowData['customer_name'] ?? $flowData['contact_person'] ?? 'Valued Customer';
            $callbackNo   = trim($flowData['callback_number'] ?? $flowData['callback_no'] ?? $flowData['call_back_number'] ?? $flowData['mobile_number'] ?? $flowData['mobile'] ?? $flowData['phone_number'] ?? $flowData['phone'] ?? $flowData['c4'] ?? '');
            $cleanCbDigits = preg_replace('/[^\d]/', '', $callbackNo);
            if (strlen($cleanCbDigits) < 10) {
                // If user entered less than 10 digits (typo), fallback to their verified WhatsApp sender number
                $cleanFromDigits = preg_replace('/[^\d]/', '', $from);
                $callbackNo = (strlen($cleanFromDigits) >= 10) ? substr($cleanFromDigits, -10) : $from;
            }
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

                        // Log in support_ticket_history
                        try {
                            $stmtH = $pdo->prepare("INSERT INTO support_ticket_history (ticket_id, action, actor_name, actor_role, details, created_at) VALUES (?, 'created', ?, 'Customer', ?, NOW())");
                            $stmtH->execute([
                                $ticketNumber,
                                $customerName . (!empty($mobile) ? " ({$mobile})" : ""),
                                "Ticket created via WhatsApp by Customer. Category: {$category}, Priority: {$priority}" . (!empty($description) ? ". Problem: {$description}" : "")
                            ]);
                        } catch (Throwable $eH) {}
                    } catch (Throwable $eSup) {}

                    // Send Instant Confirmation Message to Customer
                    $tplTktConfirm = get_system_notification_template($pdo, 'ticket_created', [
                        'ticket_id'   => $ticketNumber,
                        'client_name' => $customerName
                    ]);

                    if (!$tplTktConfirm['found'] || $tplTktConfirm['is_active']) {
                        $confirmMsg = !empty($tplTktConfirm['whatsapp_body']) ? $tplTktConfirm['whatsapp_body'] : (
                            "✅ *Support Ticket Created*\n\n" .
                            "Dear Customer, your ticket *#{$ticketNumber}* has been registered successfully.\n\n" .
                            "Our technical support engineer will contact you shortly.\n\n" .
                            "Thank you for choosing *Marg Soft Solution*."
                        );
                        $whatsapp->sendText($from, $confirmMsg);
                    }

                } catch (Throwable $e) {
                    write_log('error', "Failed saving flow ticket in webhook: " . $e->getMessage());
                }
            }
        }
    }
}
