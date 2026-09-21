<?php
/**
 * Marg CRM - Centralized AI Sales Service Adapter
 * 
 * Provides an isolated, provider-agnostic bridge for AI Conversational Sales,
 * Demo Booking, and Intent Analysis (Google Gemini Flash with dynamic key resolution).
 */

if (!defined('GEMINI_DEFAULT_MODEL')) {
    define('GEMINI_DEFAULT_MODEL', 'gemini-3.1-flash-lite');
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
            // If DB key is empty, check environment variable
            if (empty($settings['api_key'])) {
                $envKey = getenv('GEMINI_API_KEY');
                if (!empty($envKey)) {
                    $settings['api_key'] = $envKey;
                }
            }
            return $settings;
        }
    } catch (Throwable $e) {
        // Fallback
    }

    return [
        'bot_enabled' => 1,
        'ai_provider' => 'gemini',
        'api_key' => getenv('GEMINI_API_KEY') ?: '',
        'ai_model' => GEMINI_DEFAULT_MODEL,
        'system_prompt' => "You are the official WhatsApp AI Sales & Solutions Consultant for Marg Soft Solution (Marg ERP 9+). You are friendly, intelligent, open-minded, and consultative. You understand customer business problems and explain how Marg ERP solves them, guiding them naturally to schedule a free live demo. If customer asks about competitors (Tally, Busy, Vyapar, etc.), open-mindedly understand their need, ask what challenges they face in their shop/business, and highlight how Marg ERP's specialized pharma/retail/distribution features solve them. If someone asks for internal/CRM records, simply say 'Mere paas abhi iski jaankari nahi hai'. Never dump pricing repeatedly unless asked. Keep replies conversational, warm, and WhatsApp-friendly (under 80 words).",
        'knowledge_base' => "Marg ERP 9+ is India's #1 Pharma, Healthcare & Retail ERP. Over 60% pharma trade runs on Marg. Key capabilities: 7-Second Billing (high speed POS), Batch & Expiry tracking with Near-Expiry loss prevention alerts, 100% GST e-Invoicing & E-Way bills, Auto-Purchase CSV import from distributors, Multi-rate pricing, schemes & discounts, Barcode scanning, WhatsApp bill sharing, Outstanding collection reminders.",
        'pricing_basic' => '₹8,999 + 18% GST',
        'pricing_silver' => '₹12,600 + 18% GST',
        'pricing_gold' => '₹25,200 + 18% GST',
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
 * Primary AI Chat Completion & Intent Classification Engine
 *
 * @param array $history Previous conversation messages [['role' => 'user'|'model', 'text' => '...']]
 * @param string $userMessage New incoming message from customer
 * @param array $customerContext Metadata like customer_name, firm_name, phone
 * @param PDO $pdo
 * @return array ['success' => bool, 'reply' => string, 'action' => string, 'lead' => array, 'raw' => string]
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
        // Fallback message if no API key is set
        return [
            'success' => true,
            'reply'   => "Namaste! 🙏 Welcome to Marg Soft Solution.\n\nMarg ERP demo aur best pricing ke liye hamare sales advisor aapse jald hi sampark karenge.\n\nImmediate query ke liye call karein: *7523830026*.",
            'action'  => 'NONE'
        ];
    }

    $rawModel = !empty($settings['ai_model']) ? trim($settings['ai_model']) : GEMINI_DEFAULT_MODEL;

    // Deprecated model alias mapping for Gemini models
    $deprecatedMap = [
        'gemini-1.5-flash'      => 'gemini-3.1-flash-lite',
        'gemini-1.5-flash-8b'   => 'gemini-3.1-flash-lite',
        'gemini-1.5-pro'        => 'gemini-3.1-flash-lite',
        'gemini-2.0-flash'      => 'gemini-3.1-flash-lite',
        'gemini-2.0-flash-lite' => 'gemini-3.1-flash-lite',
        'gemini-2.0-flash-thinking-exp' => 'gemini-3.1-flash-lite',
        'gemini-2.5-flash'      => 'gemini-3.1-flash-lite'
    ];
    $model = $deprecatedMap[$rawModel] ?? $rawModel;

    // Build the Master System Instruction with strict Guardrails
    $sysPrompt = $settings['system_prompt'] ?? '';
    $kb = $settings['knowledge_base'] ?? '';
    $pBasic = $settings['pricing_basic'] ?? '₹8,999 + 18% GST';
    $pSilver = $settings['pricing_silver'] ?? '₹12,600 + 18% GST';
    $pGold = $settings['pricing_gold'] ?? '₹25,200 + 18% GST';

    $fullSystemInstruction = <<<PROMPT
{$sysPrompt}

### VERIFIED PRODUCT & PRICING RULES:
1. Marg ERP Basic Edition (Single User): {$pBasic}
2. Marg ERP Silver Edition (Pharma & Retail, Expiry, GST, WhatsApp, Single User): {$pSilver}
3. Marg ERP Gold Edition (Multi-User LAN Unlimited): {$pGold}

### KNOWLEDGE BASE:
{$kb}

### CONSULTATIVE SALES & CONVERSATIONAL RULES:
1. OPEN-MINDED CONSULTATIVE APPROACH (COMPETITORS & OTHER SOFTWARES):
   - Never be defensive or rigid when customer mentions other softwares (like Tally, Busy, Vyapar, etc.).
   - Acknowledge politely and ask what they like or what challenges they currently face in their business:
     (e.g., "Tally accha accounting software hai, par aap uske baare mein specifically kya jaanna chahte hain? Abhi aapke business ya shop mein billing, inventory ya accounts ko lekar kya specific challenges aa rahe hain?")
   - When customer explains their business trade or pain point, show how Marg ERP's specialized features solve it:
     * Pharma / Chemist: 7-second high-speed billing, Batch & Expiry tracking, Near-expiry alerts (saving big losses), auto-purchase CSV import from medicine distributors.
     * Retail / Supermarket: Barcode POS billing, WhatsApp bill sharing, fast billing during crowd rush.
     * Wholesale / Distribution: Multi-rate pricing, schemes, credit limits, outstanding auto-reminders.
2. NO ROBOTIC PRICING REPETITION:
   - Do NOT blindly repeat the pricing of all 3 editions in every reply.
   - Only quote pricing when the customer asks for price, budget, or edition comparison.
3. NATURAL LANGUAGE MATCHING:
   - If customer asks "hindi me batao" or speaks Hindi, reply naturally and warmly in conversational Hindi/Hinglish.
4. INTERNAL DATA & CRM PRIVACY:
   - If customer asks about internal CRM data, total leads, customer contacts, company database, or admin credentials, NEVER give a robotic security lecture. Simply reply:
     "Mere paas abhi iski jaankari nahi hai. Main Marg ERP software features, editions aur free demo booking me aapki poori madad kar sakta hoon."
5. NO UNAUTHORIZED DISCOUNTS:
   - If asked for discount, reply: "Hum aapko best offer aur free onboarding training provide karenge, jispar hum demo ke baad discuss kar sakte hain."
6. SUPPORT HANDOFF:
   - If the user describes technical errors, bugs, printer issues, or asks for software support, reply:
     "Lagta hai aapko technical assistance ki zaroorat hai. Kripya hamare Support Option par click karein taaki hamare technical support engineer aapse screen share par connect kar sakein." (Set action to "SUPPORT_HANDOFF").
7. DEMO BOOKING GOAL:
   - When the customer agrees to see a demo or provides details (e.g. name, shop name, city, preferred time), extract them and set action to "BOOK_DEMO".

### OUTPUT FORMAT:
You MUST respond with valid JSON ONLY matching this exact schema:
{
  "reply_text": "Your friendly, concise WhatsApp reply in Hinglish (max 75 words).",
  "action": "NONE" | "BOOK_DEMO" | "SUPPORT_HANDOFF",
  "lead_details": {
    "contact_name": "extracted name or empty string",
    "firm_name": "extracted business/shop name or empty string",
    "city": "extracted city or empty string",
    "product_interest": "Marg Silver / Basic / Gold or empty string",
    "preferred_time": "e.g. Tomorrow 3 PM or empty string"
  }
}
PROMPT;

    // Assemble Contents array for Gemini REST API
    $contents = [];

    // Append limited recent history (last 8 turns max to save tokens & maintain focus)
    if (is_array($history)) {
        $recentHistory = array_slice($history, -8);
        foreach ($recentHistory as $turn) {
            $role = ($turn['role'] === 'user') ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string)($turn['text'] ?? '')]]
            ];
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
                ['text' => $fullSystemInstruction]
            ]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.4,
            'topP' => 0.8,
            'maxOutputTokens' => 500,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // If request failed and model was not gemini-3.1-flash-lite, auto-retry with gemini-3.1-flash-lite
    if ((!empty($curlErr) || $httpCode !== 200) && $model !== 'gemini-3.1-flash-lite') {
        $fallbackUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . urlencode($apiKey);
        $chFb = curl_init($fallbackUrl);
        curl_setopt_array($chFb, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
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
        // Graceful fallback response on connection error
        return [
            'success' => true,
            'reply'   => "Namaste! 🙏 Marg ERP demo aur pricing ki jaankari ke liye hamare sales specialist aapse jald hi call par connect karenge.\n\nImmediate query ke liye call karein: *7523830026*.",
            'action'  => 'NONE',
            'raw_err' => $curlErr ?: ("HTTP " . $httpCode . ": " . substr($response, 0, 150))
        ];
    }

    $resData = json_decode($response, true);
    $candidateText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($candidateText)) {
        return [
            'success' => true,
            'reply'   => "Namaste! Marg ERP sales desk me aapka swagat hai. Aap Marg Basic, Silver ya Gold kis software ka demo dekhna chahenge?",
            'action'  => 'NONE'
        ];
    }

    // Parse the JSON response
    $parsed = json_decode($candidateText, true);
    if (!is_array($parsed)) {
        // Strip markdown code fences if present
        $cleanJson = preg_replace('/^```(?:json)?/i', '', trim($candidateText));
        $cleanJson = preg_replace('/```$/', '', trim($cleanJson));
        $parsed = json_decode($cleanJson, true);
    }

    if (is_array($parsed) && !empty($parsed['reply_text'])) {
        return [
            'success' => true,
            'reply'   => $parsed['reply_text'],
            'action'  => $parsed['action'] ?? 'NONE',
            'lead'    => $parsed['lead_details'] ?? [],
            'raw'     => $candidateText
        ];
    }

    // If parsing failed, use candidate text directly
    return [
        'success' => true,
        'reply'   => trim($candidateText),
        'action'  => 'NONE'
    ];
}

/**
 * Handle incoming WhatsApp message through AI Sales & Demo Booking workflow
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

        if (!$session) {
            $stmtIns = $pdo->prepare("INSERT INTO ai_chat_sessions (phone, current_state, last_message_at) VALUES (?, 'sales_engaged', NOW())");
            $stmtIns->execute([$from]);
            $sessionId = $pdo->lastInsertId();
            $session = [
                'id' => $sessionId,
                'phone' => $from,
                'current_state' => 'sales_engaged',
                'conversation_history' => '[]',
                'customer_name' => '',
                'firm_name' => '',
                'city' => '',
                'lead_id' => null,
                'demo_id' => null
            ];
        }

        $history = !empty($session['conversation_history']) ? json_decode($session['conversation_history'], true) : [];
        if (!is_array($history)) $history = [];

        // 2. Fetch context from leads or customers if available
        $customerContext = [
            'phone' => $from,
            'customer_name' => $session['customer_name'] ?? '',
            'firm_name' => $session['firm_name'] ?? '',
            'city' => $session['city'] ?? ''
        ];

        if (empty($customerContext['customer_name'])) {
            $stmtLead = $pdo->prepare("SELECT name, company, city FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
            $stmtLead->execute(["%$c10%", "%$from%"]);
            $leadFound = $stmtLead->fetch(PDO::FETCH_ASSOC);
            if ($leadFound) {
                $customerContext['customer_name'] = $leadFound['name'] ?? '';
                $customerContext['firm_name'] = $leadFound['company'] ?? '';
                $customerContext['city'] = $leadFound['city'] ?? '';
            }
        }

        // 3. Call AI Service
        $aiResult = callAIService($history, $incomingText, $customerContext, $pdo);

        $reply = $aiResult['reply'] ?? "Namaste! Marg ERP sales desk me aapka swagat hai. Main aapko demo aur pricing me kaise assist kar sakta hoon?";
        $action = $aiResult['action'] ?? 'NONE';

        // 4. Handle Actions
        if ($action === 'BOOK_DEMO') {
            $leadData = $aiResult['lead'] ?? [];
            $leadName = !empty($leadData['contact_name']) ? trim($leadData['contact_name']) : (!empty($customerContext['customer_name']) ? $customerContext['customer_name'] : 'WhatsApp Customer');
            $firmName = !empty($leadData['firm_name']) ? trim($leadData['firm_name']) : (!empty($customerContext['firm_name']) ? $customerContext['firm_name'] : 'Retail Store');
            $city     = !empty($leadData['city']) ? trim($leadData['city']) : (!empty($customerContext['city']) ? $customerContext['city'] : '');
            $product  = !empty($leadData['product_interest']) ? trim($leadData['product_interest']) : 'Marg ERP Silver';
            $prefTime = !empty($leadData['preferred_time']) ? trim($leadData['preferred_time']) : 'Tomorrow 3:00 PM';

            // Find or Create Lead
            $existingLeadId = $session['lead_id'];
            if (empty($existingLeadId)) {
                $stmtCheck = $pdo->prepare("SELECT id FROM leads WHERE phone LIKE ? OR phone LIKE ? LIMIT 1");
                $stmtCheck->execute(["%$c10%", "%$from%"]);
                $existingLeadId = $stmtCheck->fetchColumn();
            }

            if (empty($existingLeadId)) {
                if (function_exists('generate_lead_number')) {
                    $existingLeadId = generate_lead_number($pdo);
                } else {
                    $existingLeadId = 'LD-' . rand(10000, 99999);
                }

                $insLead = $pdo->prepare("INSERT INTO leads (id, name, contact_person, company, city, phone, enq_for, remarks, source, status, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'WhatsApp AI Sales', 'demo_scheduled', 'hot', NOW())");
                $insLead->execute([$existingLeadId, $leadName, $leadName, $firmName, $city, $from, $product, "AI Auto-Booked Demo for $prefTime"]);
            } else {
                $updLead = $pdo->prepare("UPDATE leads SET status = 'demo_scheduled', priority = 'hot', enq_for = ? WHERE id = ?");
                $updLead->execute([$product, $existingLeadId]);
            }

            // Schedule Demo in demos table
            $demoId = 'DM-' . rand(1000, 9999);
            $demoDate = date('Y-m-d 15:00:00', strtotime('+1 day'));
            try {
                $insDemo = $pdo->prepare("INSERT INTO demos (id, lead_id, scheduled_at, mode, engineer, status) VALUES (?, ?, ?, 'Online (Google Meet)', 'Unassigned', 'scheduled')");
                $insDemo->execute([$demoId, $existingLeadId, $demoDate]);
            } catch (Throwable $eD) {}

            // Insert into followups table
            try {
                $insFup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, 'Demo', ?, ?, 'Unassigned', 'pending')");
                $insFup->execute([$existingLeadId, $demoDate, "AI Scheduled Demo Walkthrough (Online)"]);
            } catch (Throwable $eF) {}

            // Insert into timeline
            try {
                $insTL = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, 'WhatsApp AI Bot', ?)");
                $insTL->execute([$existingLeadId, "Auto-scheduled software demo ($demoId) via WhatsApp AI Assistant"]);
            } catch (Throwable $eTL) {}

            // Admin notification
            try {
                $insNotif = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New AI Demo Scheduled', ?, 'index.php?page=demo', 'success')");
                $insNotif->execute(["Demo {$demoId} for {$leadName} ({$from}) auto-scheduled by AI Sales Assistant."]);
            } catch (Throwable $eN) {}

            // Update session
            $updSess = $pdo->prepare("UPDATE ai_chat_sessions SET lead_id = ?, demo_id = ?, customer_name = ?, firm_name = ?, city = ?, current_state = 'demo_booked' WHERE id = ?");
            $updSess->execute([$existingLeadId, $demoId, $leadName, $firmName, $city, $session['id']]);

            // Dispatch reply text to customer
            $whatsapp->sendText($from, $reply);

            // Send interactive confirmation card
            $confirmCard = "🎉 *Demo Scheduled Successfully!*\n\n" .
                           "📋 *Demo Ref:* {$demoId}\n" .
                           "👤 *Name:* {$leadName}\n" .
                           "🏪 *Firm:* {$firmName}\n" .
                           "💻 *Edition:* {$product}\n" .
                           "⏰ *Slot:* {$prefTime}\n\n" .
                           "Hamare Marg Software specialist screen connect karke aapko poora demo dikhayenge.\n\n" .
                           "Thank you for choosing Marg ERP! 🙏";
            $whatsapp->sendText($from, $confirmCard);

        } elseif ($action === 'SUPPORT_HANDOFF') {
            // First send AI explanation
            $whatsapp->sendText($from, $reply);

            // Then send Support Flow Form
            if (defined('FLOW_ID') && !empty(FLOW_ID)) {
                $whatsapp->sendFlow($from, FLOW_ID, "Create Ticket", "Please submit your technical issue here", 'WELCOME_SCREEN', null, "Marg Help Soft Solution", "Support Desk");
            }

        } else {
            // Standard conversational response
            $whatsapp->sendText($from, $reply);
        }

        // 5. Update Conversation History in Session
        $history[] = ['role' => 'user', 'text' => $incomingText];
        $history[] = ['role' => 'model', 'text' => $reply];

        // Keep last 10 turns
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $updHistory = $pdo->prepare("UPDATE ai_chat_sessions SET conversation_history = ?, last_message_at = NOW() WHERE id = ?");
        $updHistory->execute([json_encode($history, JSON_UNESCAPED_UNICODE), $session['id']]);

        // 6. Log outbound AI message into message_logs
        try {
            $stmtLog = $pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, status, created_at) VALUES ('OUTBOUND', ?, 'text', ?, 'sent', NOW())");
            $stmtLog->execute([$from, $reply]);
        } catch (Throwable $eL) {}

        return true;

    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('error', "handleAISalesAssistantInteraction Error: " . $e->getMessage());
        }
        return false;
    }
}
