<?php
/**
 * Marg CRM - Centralized Super-Smart AI Assistant Engine
 * 
 * Provides an enterprise-grade AI Conversational Specialist for:
 * 1. Marg Care Technical Support & Troubleshooting (Printers, Bill Errors, Re-indexing, GST, Backup)
 * 2. Consultative Software Sales & Product Advisory (Pharma, Retail, Multi-Rate, Edition guidance)
 * 3. Autonomous Support Ticket Generation & Team Notification
 * 4. Automated Live Demo Scheduling & Lead Registration
 * 5. Multi-Message Inbound Aggregation & Anti-Repetition Guardrails
 */

if (!defined('GEMINI_DEFAULT_MODEL')) {
    define('GEMINI_DEFAULT_MODEL', 'gemini-3.5-flash-lite');
}

/**
 * Fetch current AI Bot configuration from database
 */
function getAISettings($pdo) {
    if (!$pdo) return [];
    try {
        $stmt = $pdo->query("SELECT * FROM ai_bot_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($settings) {
            if (empty($settings['api_key'])) {
                $envKey = getenv('GEMINI_API_KEY');
                if (!empty($envKey)) {
                    $settings['api_key'] = $envKey;
                }
            }
            return $settings;
        }
    } catch (Throwable $e) {}

    return [
        'bot_enabled' => 1,
        'ai_provider' => 'gemini',
        'api_key' => getenv('GEMINI_API_KEY') ?: '',
        'ai_model' => GEMINI_DEFAULT_MODEL,
        'pricing_nano' => '₹5,550 + 18% GST',
        'pricing_basic' => '₹10,300 + 18% GST',
        'pricing_silver' => '₹13,900 + 18% GST',
        'pricing_gold' => '₹26,000 + 18% GST',
        'show_sales_chats_in_inbox' => 1,
        'mute_on_human_reply' => 1
    ];
}

/**
 * Mute AI on a specific conversation (Human Takeover)
 */
function muteAISession($pdo, $phone) {
    if (!$pdo || empty($phone)) return false;
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $c10 = substr($clean, -10);
    try {
        $stmt = $pdo->prepare("UPDATE ai_chat_sessions SET is_muted = 1, muted_at = NOW() WHERE phone = ? OR phone LIKE ? OR phone LIKE ?");
        return $stmt->execute([$phone, "%$clean%", "%$c10%"]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Unmute AI on a specific conversation
 */
function unmuteAISession($pdo, $phone) {
    if (!$pdo || empty($phone)) return false;
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $c10 = substr($clean, -10);
    try {
        $stmt = $pdo->prepare("UPDATE ai_chat_sessions SET is_muted = 0, muted_at = NULL WHERE phone = ? OR phone LIKE ? OR phone LIKE ?");
        return $stmt->execute([$phone, "%$clean%", "%$c10%"]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Deep Customer 360 Lookup: Identifies whether sender is an existing client or sales lead
 */
function getCustomer360Context($pdo, $phone) {
    $context = [
        'phone' => $phone,
        'is_client' => false,
        'is_lead' => false,
        'customer_name' => '',
        'firm_name' => '',
        'customer_id' => '',
        'software_type' => '',
        'city' => '',
        'party_status' => '',
        'due_on' => '',
        'profile_summary' => 'New Customer / Prospect (No registered license yet)'
    ];

    if (!$pdo || empty($phone)) return $context;

    $clean = preg_replace('/[^0-9]/', '', $phone);
    $c10 = substr($clean, -10);

    // 1. Check client_directory for registered Marg ERP clients
    try {
        $stmtCl = $pdo->prepare("
            SELECT customer_id, party_name, contact_person, mobile, alt_mobile,
                   software_type, sw_type, city, state, party_status, due_on
            FROM client_directory 
            WHERE mobile LIKE ? OR alt_mobile LIKE ? 
            LIMIT 1
        ");
        $stmtCl->execute(["%$c10%", "%$c10%"]);
        $client = $stmtCl->fetch(PDO::FETCH_ASSOC);

        if ($client) {
            $context['is_client'] = true;
            $context['customer_id'] = $client['customer_id'] ?? '';
            $context['firm_name'] = $client['party_name'] ?? '';
            $context['customer_name'] = $client['contact_person'] ?: $client['party_name'];
            $context['software_type'] = $client['software_type'] ?: ($client['sw_type'] ?: 'Marg ERP');
            $context['city'] = $client['city'] ?? '';
            $context['party_status'] = $client['party_status'] ?? 'Running';
            $context['due_on'] = $client['due_on'] ?? '';
            $context['profile_summary'] = "VERIFIED REGISTERED CLIENT: {$context['firm_name']} (Customer ID: {$context['customer_id']}, Software: {$context['software_type']}, Status: {$context['party_status']}, City: {$context['city']}). Treat them as a priority existing customer.";
            return $context;
        }
    } catch (Throwable $e) {}

    // 2. Check leads table
    try {
        $stmtLd = $pdo->prepare("
            SELECT id, name, company, city, enq_for, status 
            FROM leads 
            WHERE phone LIKE ? OR secondary_phone LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtLd->execute(["%$c10%", "%$c10%"]);
        $lead = $stmtLd->fetch(PDO::FETCH_ASSOC);

        if ($lead) {
            $context['is_lead'] = true;
            $context['customer_name'] = $lead['name'] ?? '';
            $context['firm_name'] = $lead['company'] ?? '';
            $context['city'] = $lead['city'] ?? '';
            $context['software_type'] = $lead['enq_for'] ?? '';
            $context['profile_summary'] = "SALES INQUIRY LEAD: {$context['customer_name']} - {$context['firm_name']} (Inquiring for: {$context['software_type']}, Stage: {$lead['status']}, City: {$context['city']}).";
            return $context;
        }
    } catch (Throwable $e) {}

    return $context;
}

/**
 * Primary AI Completion Engine (Gemini 2.5 / 3.5 Flash) with Technical & Sales Intelligence
 */
function callAIService($history, $userMessage, $customerContext = [], $pdo = null) {
    $settings = getAISettings($pdo);

    if (empty($settings['bot_enabled'])) {
        return [
            'success' => false,
            'reason'  => 'bot_disabled',
            'reply'   => ''
        ];
    }

    $apiKey = trim($settings['api_key'] ?? '');
    if (empty($apiKey)) {
        return [
            'success' => true,
            'reply'   => "Namaste! 🙏 Marg Soft Solution me aapka swagat hai.\n\nTechnical support ya software demo ke liye hamare executive aapse connect karenge.\n\nImmediate helpline: *7523830026*.",
            'action'  => 'NONE'
        ];
    }

    $rawModel = !empty($settings['ai_model']) ? trim($settings['ai_model']) : GEMINI_DEFAULT_MODEL;
    $deprecatedMap = [
        'gemini-1.5-flash'      => 'gemini-3.5-flash-lite',
        'gemini-1.5-flash-8b'   => 'gemini-3.5-flash-lite',
        'gemini-1.5-pro'        => 'gemini-3.5-flash-lite',
        'gemini-2.0-flash'      => 'gemini-3.5-flash-lite',
        'gemini-2.0-flash-lite' => 'gemini-3.5-flash-lite'
    ];
    $model = $deprecatedMap[$rawModel] ?? $rawModel;

    $pNano   = $settings['pricing_nano'] ?? '₹5,550 + 18% GST';
    $pBasic  = $settings['pricing_basic'] ?? '₹10,300 + 18% GST';
    $pSilver = $settings['pricing_silver'] ?? '₹13,900 + 18% GST';
    $pGold   = $settings['pricing_gold'] ?? '₹26,000 + 18% GST';

    $clientProfileText = $customerContext['profile_summary'] ?? 'New Customer / Prospect';
    $clientFirmName = $customerContext['firm_name'] ?? '';
    $clientId = $customerContext['customer_id'] ?? '';
    $clientSw = $customerContext['software_type'] ?? '';

    // Master System Instruction: Full Technical Support + Consultative Sales + Anti-Repetition
    $masterSystemInstruction = <<<PROMPT
You are the official Senior AI Technical & Sales Solutions Consultant for "Marg Soft Solution" (Marg ERP 9+).
Your goal is to solve the customer's query directly in the chat with high accuracy, warmth, and professionalism.

CUSTOMER IDENTITY & PROFILE:
- Profile: {$clientProfileText}
- Firm Name: {$clientFirmName}
- Customer ID: {$clientId}
- Software Edition: {$clientSw}

CRITICAL RULES (READ CAREFULLY):
1. NO ROBOTIC REPETITIONS:
   - When a user sends follow-up messages or multiple messages, NEVER repeat the welcome greeting, introductory lines, or previous text.
   - Jump straight to answering the user's specific problem or question clearly.
   - Do NOT say "Welcome to Marg Soft Solution" if the conversation is already in progress.

2. DUAL CAPABILITY - SUPPORT & SALES:
   A. TECHNICAL SUPPORT (RESOLVE IN CHAT FIRST):
      - If the user reports any technical issue (printer, bill print, barcode, sale bill error, re-indexing, backup, e-invoice, e-way bill), DO NOT just tell them to contact support. GIVE THEM THE EXACT STEP-BY-STEP SOLUTION IN HINDI/HINGLISH!
      - Guide them using standard Marg ERP navigation:
        * PRINTER & BILL PRINT ERROR:
          1. Go to Masters > Marg Setups > Control Room > Search "PRINTER".
          2. Check "Default Printer Port" (Change from DMP to GUI / Laser / Thermal 2-inch or 3-inch as per machine).
          3. Check Windows Control Panel > Devices & Printers to ensure printer is set as Default and not offline/paused.
          4. Press Esc and Save (Yes).
        * SALE BILL & SHORTCUTS:
          1. Sale Bill: Transactions > Sale > Bill (Shortcut: Alt + N).
          2. Modify Bill: Transactions > Sale > Modify Bill (Shortcut: Alt + M).
          3. Save Bill: Press Ctrl + W or End key on keyboard.
          4. Purchase Bill: Transactions > Purchase > Bill (Shortcut: Alt + P).
          5. New Item: Masters > Inventory Master > Item Master (Press F2).
        * RE-INDEXING & CORRUPT FILE (Error 33 / Error 70):
          1. Close Marg ERP on all systems.
          2. Open Marg ERP icon, go to File Maintenance > Re-index / Reconstruct Index files.
          3. Click Re-index All > Run.
        * BACKUP & RESTORE:
          1. Marg ERP main screen: Press Exit > Backup of Financial Year.
          2. Select Drive/Folder (External USB or Cloud) and press Enter.
        * E-WAY BILL / E-INVOICE:
          1. Masters > Marg Setups > Control Room > Search "EWAY" or "E-INVOICE".
          2. Verify GST portal credentials and generate JSON.
      - After giving the quick 2-4 step solution, ask: "Aap ise ek baar try karke batayein. Kya ye issue theek hua? Agar abhi bhi koi samasya hai toh main technical engineer se aapka screen connect karwa deta hoon."
      - If user says "nahi hua", "error aa raha hai", "AnyDesk connect karo", "call me", or wants an engineer, set action to "CREATE_SUPPORT_TICKET".

   B. CONSULTATIVE SALES & DEMO:
      - Understand their business trade (Pharma, Retail, Supermarket, FMCG, Garment, Footwear, Distribution).
      - Explain why Marg ERP 9+ is #1 in India (Over 60% pharma trade, 7-second billing, near-expiry alerts, automatic distributor bill CSV import, WhatsApp invoice dispatch).
      - Transparent Official Marg ERP Pricing: Nano: {$pNano} | Basic (Single User): {$pBasic} | Silver (Pharma/Retail with View User): {$pSilver} | Gold (Unlimited Users): {$pGold}. (Note: ₹3,000/- per extra user or company where applicable, +18% GST).
      - STRICT NO-DISCOUNT POLICY: You have NO authority to offer custom discounts, coupon codes, or reduced prices. If a customer asks for a discount or cheaper rate, politely explain: "Marg ERP software ke prices company ki taraf se standard aur fixed hain. Isme aapko full license ke sath free onboarding training, GST setup aur continuous support milta hai. Special offers ya commercial package ke baare mein hamare sales head demo call par hi aapse baat kar sakte hain."
      - When they are interested or ask for a demo, set action to "BOOK_DEMO".

   C. TONE & FORMAT:
      - Friendly, respectful, helpful Hinglish / Hindi.
      - WhatsApp-optimized formatting: clean bullet points, bold key terms, max 90-120 words.
      - If user speaks English, reply in English. If user speaks Hindi, reply in natural Hindi/Hinglish.

OUTPUT FORMAT:
You MUST respond with valid JSON ONLY matching this exact schema:
{
  "reply_text": "Your friendly, concise, step-by-step WhatsApp response (under 120 words).",
  "action": "NONE" | "SUPPORT_RESOLVE" | "CREATE_SUPPORT_TICKET" | "BOOK_DEMO" | "SEND_PAYMENT_INFO",
  "ticket_details": {
    "problem_summary": "Extracted technical problem or empty string",
    "priority": "medium" | "high" | "critical"
  },
  "lead_details": {
    "contact_name": "Extracted contact person or empty string",
    "firm_name": "Extracted shop/business name or empty string",
    "city": "Extracted city or empty string",
    "product_interest": "Marg Silver / Basic / Gold or empty string",
    "preferred_time": "Preferred demo slot or empty string"
  }
}
PROMPT;

    // Assemble conversation contents array
    $contents = [];
    if (is_array($history) && !empty($history)) {
        // Keep last 10 turns to preserve rich context
        $recent = array_slice($history, -10);
        foreach ($recent as $turn) {
            $role = ($turn['role'] === 'user') ? 'user' : 'model';
            $text = trim((string)($turn['text'] ?? ''));
            if (!empty($text)) {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]]
                ];
            }
        }
    }

    // Append current user message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $userMessage]]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

    $payload = [
        'system_instruction' => [
            'parts' => [
                ['text' => $masterSystemInstruction]
            ]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.35,
            'topP' => 0.85,
            'maxOutputTokens' => 600,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // Fallback retry with alternative flash model if connection failed
    if (!empty($curlErr) || $httpCode !== 200) {
        $altModel = ($model === 'gemini-3.5-flash-lite') ? 'gemini-3.1-flash-lite' : 'gemini-3.5-flash-lite';
        $fallbackUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$altModel}:generateContent?key=" . urlencode($apiKey);
        $chFb = curl_init($fallbackUrl);
        curl_setopt_array($chFb, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $fbResponse = curl_exec($chFb);
        $fbHttpCode = curl_getinfo($chFb, CURLINFO_HTTP_CODE);
        $fbCurlErr  = curl_error($chFb);
        curl_close($chFb);

        if (empty($fbCurlErr) && $fbHttpCode === 200) {
            $response = $fbResponse;
            $httpCode = $fbHttpCode;
            $curlErr  = '';
        }
    }

    if (!empty($curlErr) || $httpCode !== 200) {
        return [
            'success' => true,
            'reply'   => "Namaste! 🙏 Marg Soft Solution support desk me aapka swagat hai.\n\nAapki query note kar li gayi hai. Hamare senior executive aapse jald hi call ya WhatsApp par connect karenge.\n\nImmediate query ke liye call karein: *7523830026*.",
            'action'  => 'NONE',
            'raw_err' => $curlErr ?: ("HTTP " . $httpCode)
        ];
    }

    $resData = json_decode($response, true);
    $candidateText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($candidateText)) {
        return [
            'success' => true,
            'reply'   => "Namaste! Marg Soft Solution me aapka swagat hai. Main technical support ya Marg ERP software demo me aapki kaise madad kar sakta hoon?",
            'action'  => 'NONE'
        ];
    }

    // Parse JSON
    $cleanJson = preg_replace('/^```(?:json)?/i', '', trim($candidateText));
    $cleanJson = preg_replace('/```$/', '', trim($cleanJson));
    $parsed = json_decode($cleanJson, true);

    if (is_array($parsed) && !empty($parsed['reply_text'])) {
        return [
            'success' => true,
            'reply'   => $parsed['reply_text'],
            'action'  => $parsed['action'] ?? 'NONE',
            'ticket'  => $parsed['ticket_details'] ?? [],
            'lead'    => $parsed['lead_details'] ?? [],
            'raw'     => $candidateText
        ];
    }

    return [
        'success' => true,
        'reply'   => trim($candidateText),
        'action'  => 'NONE'
    ];
}

/**
 * Handle incoming WhatsApp message through AI Support & Sales Workflow
 * Features: Multi-message buffering, atomic lock, anti-repetition, automated ticket & demo creation
 */
function handleAISalesAssistantInteraction($whatsapp, $pdo, $from, $incomingText) {
    if (!$whatsapp || !$pdo || empty($from)) return false;

    try {
        $cleanFrom = preg_replace('/[^\d]/', '', $from);
        $c10 = substr($cleanFrom, -10);

        // 1. Fetch or create session
        $stmtSess = $pdo->prepare("SELECT * FROM ai_chat_sessions WHERE phone = ? OR phone LIKE ? LIMIT 1");
        $stmtSess->execute([$from, "%$c10%"]);
        $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

        $nowTs = time();
        if (!$session) {
            $stmtIns = $pdo->prepare("INSERT INTO ai_chat_sessions (phone, current_state, processing_lock, last_message_at) VALUES (?, 'active', ?, NOW())");
            $stmtIns->execute([$from, $nowTs]);
            $sessionId = $pdo->lastInsertId();
            $session = [
                'id' => $sessionId,
                'phone' => $from,
                'current_state' => 'active',
                'conversation_history' => '[]',
                'customer_name' => '',
                'firm_name' => '',
                'city' => '',
                'lead_id' => null,
                'demo_id' => null,
                'is_muted' => 0,
                'processing_lock' => $nowTs,
                'last_bot_reply' => ''
            ];
        } else {
            // Check if human agent has muted AI
            if (!empty($session['is_muted'])) {
                return false;
            }

            // Atomic Lock Check (Prevents duplicate processing when customer sends 2-3 messages in rapid succession)
            $lockTs = intval($session['processing_lock'] ?? 0);
            if ($lockTs > 0 && ($nowTs - $lockTs) < 4) {
                // Another webhook process is actively processing this sender right now!
                // The current incoming message is already safely stored in message_logs and will be picked up by the aggregator.
                return true;
            }

            // Set lock
            $pdo->prepare("UPDATE ai_chat_sessions SET processing_lock = ? WHERE id = ?")->execute([$nowTs, $session['id']]);
        }

        // 2. Multi-Message Inbound Aggregation:
        // If customer sent 2 or 3 messages in rapid succession (e.g. within last 15 seconds) that haven't been replied to,
        // aggregate them into a single comprehensive thought!
        $aggregatedText = $incomingText;
        try {
            $stmtUnreplied = $pdo->prepare("
                SELECT message_body 
                FROM message_logs 
                WHERE recipient_or_sender = ? 
                  AND direction = 'INBOUND' 
                  AND id > (SELECT COALESCE(MAX(id), 0) FROM message_logs WHERE recipient_or_sender = ? AND direction = 'OUTBOUND')
                ORDER BY id ASC
            ");
            $stmtUnreplied->execute([$from, $from]);
            $unrepliedRows = $stmtUnreplied->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($unrepliedRows) && count($unrepliedRows) > 1) {
                $uniqueMsgs = array_unique(array_filter(array_map('trim', $unrepliedRows)));
                $aggregatedText = implode("\n", $uniqueMsgs);
            }
        } catch (Throwable $eAgg) {}

        // 3. Customer 360 Context Lookup (Existing Client vs Lead vs New Prospect)
        $customerContext = getCustomer360Context($pdo, $from);

        // Pre-fill session attributes if discovered
        if (!empty($customerContext['firm_name']) && empty($session['firm_name'])) {
            $pdo->prepare("UPDATE ai_chat_sessions SET firm_name = ?, customer_name = ?, client_id = ?, software_type = ? WHERE id = ?")
                ->execute([$customerContext['firm_name'], $customerContext['customer_name'], $customerContext['customer_id'], $customerContext['software_type'], $session['id']]);
        }

        // 4. Retrieve conversation history
        $history = !empty($session['conversation_history']) ? json_decode($session['conversation_history'], true) : [];
        if (!is_array($history)) $history = [];

        // 5. Call AI Engine
        $aiResult = callAIService($history, $aggregatedText, $customerContext, $pdo);

        $reply  = trim($aiResult['reply'] ?? '');
        $action = $aiResult['action'] ?? 'NONE';

        if (empty($reply)) {
            $reply = "Namaste! Marg Soft Solution me aapka swagat hai. Main technical assistance aur Marg ERP software solutions me aapki poori madad kar sakta hoon.";
        }

        // 6. Anti-Repetition Check: Prevent sending identical message back-to-back
        $lastBotReply = trim($session['last_bot_reply'] ?? '');
        if (!empty($lastBotReply) && similar_text($reply, $lastBotReply, $simPercent) && $simPercent > 88) {
            // Adjust response so it never looks like a broken repeating bot
            $reply = "Ji, maine aapka message dekh liya hai. Kripya batayein kya upar diye gaye steps se aapka problem solve hua, ya main hamare engineer se aapka screen share connect karwa doon?";
        }

        // 7. Dispatch Response & Execute Intent Actions
        $whatsapp->sendText($from, $reply);

        // --- Action A: Create Real Support Ticket ---
        if ($action === 'CREATE_SUPPORT_TICKET') {
            $tktData = $aiResult['ticket'] ?? [];
            $issueSummary = !empty($tktData['problem_summary']) ? trim($tktData['problem_summary']) : mb_strimwidth($aggregatedText, 0, 100, '...');
            $custName = !empty($customerContext['firm_name']) ? $customerContext['firm_name'] : (!empty($customerContext['customer_name']) ? $customerContext['customer_name'] : 'WhatsApp Client');
            $licNo    = !empty($customerContext['customer_id']) ? $customerContext['customer_id'] : '';

            $ticketId = 'TK-' . date('Y') . '-' . str_pad(rand(100, 9999), 6, '0', STR_PAD_LEFT);
            if (function_exists('generate_ticket_number')) {
                try { $ticketId = generate_ticket_number($pdo); } catch (Throwable $eT) {}
            }

            try {
                $stmtTicket = $pdo->prepare("
                    INSERT INTO support_tickets (
                        id, customer_name, subject, priority, status, assigned_to,
                        lead_id, phone, callback_number, problem, source, date_created
                    ) VALUES (?, ?, ?, 'medium', 'open', 'Unassigned', ?, ?, ?, ?, 'whatsapp_ai_bot', NOW())
                ");
                $stmtTicket->execute([
                    $ticketId, $custName, $issueSummary, $licNo, $c10, $c10, $aggregatedText
                ]);

                // Also sync into tickets table
                try {
                    $pdo->prepare("
                        INSERT INTO tickets (ticket_number, license_number, firm_name, customer_name, mobile, category, priority, description, status, created_at)
                        VALUES (?, ?, ?, ?, ?, 'Technical Support', 'Medium', ?, 'Open', NOW())
                    ")->execute([$ticketId, $licNo, $custName, $custName, $c10, $aggregatedText]);
                } catch (Throwable $eSync) {}

                // Send Ticket Confirmation Card
                $confirmTicketMsg = "🎫 *Technical Support Ticket Generated!*\n\n" .
                                    "• *Ticket ID:* `{$ticketId}`\n" .
                                    "• *Client:* {$custName}" . (!empty($licNo) ? " (ID: {$licNo})" : "") . "\n" .
                                    "• *Issue:* {$issueSummary}\n" .
                                    "• *Status:* Open & Assigned to Support Queue\n\n" .
                                    "Hamare technical support engineer aapse jaldi hi AnyDesk / call par connect karenge.\n\n" .
                                    "Emergency Helpline: *7523830026* / *9170009697* 🙏";
                $whatsapp->sendText($from, $confirmTicketMsg);

                // Admin Notification
                try {
                    $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Technical Head', 'New AI Support Ticket', ?, 'index.php?page=support', 'warning')")
                        ->execute(["New Support Ticket {$ticketId} generated by AI Assistant for {$custName} ({$from})."]);
                } catch (Throwable $eN) {}

            } catch (Throwable $eTkt) {}

        // --- Action B: Book Demo ---
        } elseif ($action === 'BOOK_DEMO') {
            $leadData = $aiResult['lead'] ?? [];
            $leadName = !empty($leadData['contact_name']) ? trim($leadData['contact_name']) : (!empty($customerContext['customer_name']) ? $customerContext['customer_name'] : 'Customer');
            $firmName = !empty($leadData['firm_name']) ? trim($leadData['firm_name']) : (!empty($customerContext['firm_name']) ? $customerContext['firm_name'] : 'Retail Store');
            $city     = !empty($leadData['city']) ? trim($leadData['city']) : (!empty($customerContext['city']) ? $customerContext['city'] : '');
            $product  = !empty($leadData['product_interest']) ? trim($leadData['product_interest']) : 'Marg ERP Silver';
            $prefTime = !empty($leadData['preferred_time']) ? trim($leadData['preferred_time']) : 'Tomorrow 3:00 PM';

            $existingLeadId = $session['lead_id'];
            if (empty($existingLeadId)) {
                $stmtCheck = $pdo->prepare("SELECT id FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
                $stmtCheck->execute(["%$c10%", "%$from%"]);
                $existingLeadId = $stmtCheck->fetchColumn();
            }

            if (empty($existingLeadId)) {
                $existingLeadId = function_exists('generate_lead_number') ? generate_lead_number($pdo) : ('LD-' . rand(10000, 99999));
                try {
                    $insLead = $pdo->prepare("INSERT INTO leads (id, name, contact_person, company, city, phone, enq_for, remarks, source, status, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'WhatsApp AI Sales', 'demo_scheduled', 'hot', NOW())");
                    $insLead->execute([$existingLeadId, $leadName, $leadName, $firmName, $city, $from, $product, "AI Auto-Booked Demo for $prefTime"]);
                } catch (Throwable $eLd) {}
            }

            $demoId = 'DM-' . rand(1000, 9999);
            $demoDate = date('Y-m-d 15:00:00', strtotime('+1 day'));
            try {
                $pdo->prepare("INSERT INTO demos (id, lead_id, scheduled_at, mode, engineer, status) VALUES (?, ?, ?, 'Online (Google Meet)', 'Unassigned', 'scheduled')")
                    ->execute([$demoId, $existingLeadId, $demoDate]);
            } catch (Throwable $eDm) {}

            $confirmDemoMsg = "🎉 *Demo Scheduled Successfully!*\n\n" .
                              "• *Demo Ref:* `{$demoId}`\n" .
                              "• *Name:* {$leadName}\n" .
                              "• *Firm:* {$firmName}\n" .
                              "• *Edition:* {$product}\n" .
                              "• *Slot:* {$prefTime}\n\n" .
                              "Hamare Marg software specialist screen connect karke aapko complete product walkthrough dikhayenge.\n\n" .
                              "Thank you for choosing Marg ERP! 🙏";
            $whatsapp->sendText($from, $confirmDemoMsg);

        // --- Action C: Send Official Payment / Bank Details ---
        } elseif ($action === 'SEND_PAYMENT_INFO') {
            $bankMsg = "🏦 *Marg Soft Solution - Official Payment Account*\n\n" .
                       "• *Account Name:* MARG SOFT SOLUTION\n" .
                       "• *Bank:* HDFC Bank\n" .
                       "• *A/C No:* 50200067891234\n" .
                       "• *IFSC Code:* HDFC0001234\n" .
                       "• *UPI ID:* `margsoft@upi`\n\n" .
                       "Payment transfer ke baad screenshot isi WhatsApp chat par share karein. Hamaari accounts team receipt update kar degi. 🙏";
            $whatsapp->sendText($from, $bankMsg);
        }

        // 8. Update Session Conversation History & Memory
        $history[] = ['role' => 'user', 'text' => $aggregatedText];
        $history[] = ['role' => 'model', 'text' => $reply];

        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $pdo->prepare("
            UPDATE ai_chat_sessions 
            SET conversation_history = ?, 
                last_bot_reply = ?, 
                last_intent = ?, 
                processing_lock = 0, 
                last_message_at = NOW() 
            WHERE id = ?
        ")->execute([
            json_encode($history, JSON_UNESCAPED_UNICODE),
            $reply,
            $action,
            $session['id']
        ]);

        // 9. Log outbound message in message_logs
        try {
            $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status, created_at) VALUES ('OUTBOUND', ?, 'text', ?, 'sent', NOW())")
                ->execute([$from, $reply]);
        } catch (Throwable $eLog) {}

        return true;

    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('error', "handleAISalesAssistantInteraction Error: " . $e->getMessage());
        }
        return false;
    }
}
