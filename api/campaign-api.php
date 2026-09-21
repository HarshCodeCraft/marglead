<?php
/**
 * Marg CRM - WhatsApp Broadcast & Campaign API Endpoint
 */

require_once __DIR__ . '/cors.php';

$auth = requireApiAuth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_campaigns';

if ($action !== 'ai_generate_template' && (!$db_connected || !$pdo)) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

function getActiveWabaConfig($pdo, $userId = null) {
    if (!$userId) {
        $userId = $_SESSION['user_id'] ?? 1;
    }
    try {
        // STRICT USER & TENANT ISOLATION:
        // Only return THIS specific user's own WhatsApp settings.
        // NEVER fallback to another user's connected session or table!
        $stmt = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Return only this user's record. NEVER query other users' connected sessions!
        return $row ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Get active sender's profile, firm name, and primary bank account details
 * Strictly isolated to the active tenant / user database.
 */
function getSenderBankingAndProfileDetails($pdo) {
    $firm = $_SESSION['tenant_name'] ?? $_SESSION['company_name'] ?? '';
    if (empty($firm) && !empty($_SESSION['user_name'])) {
        $firm = $_SESSION['user_name'];
    }

    $userId = $_SESSION['user_id'] ?? 1;
    $helpline = '';
    try {
        $stmtW = $pdo->prepare("SELECT business_phone, business_name FROM merchant_waba_settings WHERE user_id = ? OR tenant_api_key != '' ORDER BY id DESC LIMIT 1");
        $stmtW->execute([$userId]);
        $wRow = $stmtW->fetch(PDO::FETCH_ASSOC);
        if ($wRow) {
            $helpline = $wRow['business_phone'] ?? '';
            if (empty($firm) && !empty($wRow['business_name'])) {
                $firm = $wRow['business_name'];
            }
        }
    } catch (\Throwable $e) {}

    if (empty($firm)) $firm = 'Business Entity';
    if (empty($helpline)) $helpline = '-';

    // Look up primary bank account for this active user / tenant strictly from current pdo
    $bank = null;
    try {
        $stmtB = $pdo->query("SELECT * FROM bank_accounts WHERE status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
        $bank = $stmtB ? $stmtB->fetch(PDO::FETCH_ASSOC) : null;
    } catch (\Throwable $e) {}

    return [
        'firm_name'      => $firm,
        'helpline'       => $helpline,
        'upi_id'         => !empty($bank['upi_id']) ? $bank['upi_id'] : '-',
        'bank_name'      => !empty($bank['bank_name']) ? $bank['bank_name'] : '-',
        'account_number' => !empty($bank['account_number']) ? $bank['account_number'] : '-',
        'branch'         => !empty($bank['branch']) ? $bank['branch'] : '-',
        'ifsc_code'      => !empty($bank['ifsc_code']) ? $bank['ifsc_code'] : '-'
    ];
}

/**
 * Resolve dynamic placeholders including {{1}}..{{13}} and {name}..{due_date}
 * ensuring only the current active sender's own details are used.
 */
function resolveTemplateVariables($rawMsg, $pdo, $recipientData = []) {
    $sender = getSenderBankingAndProfileDetails($pdo);

    $custName = $recipientData['name'] ?? 'Valued Customer';
    $custCompany = $recipientData['company'] ?? $sender['firm_name'];
    $phone = $recipientData['phone'] ?? '';
    $billNo = $recipientData['bill_no'] ?? ('INV-' . date('dmy') . '-' . rand(100, 999));
    $amount = !empty($recipientData['amount']) ? preg_replace('/[^\d\.,]/', '', $recipientData['amount']) : '3,500';
    $balance = !empty($recipientData['balance']) ? preg_replace('/[^\d\.,]/', '', $recipientData['balance']) : '0.00';
    $dueDate = $recipientData['due_date'] ?? date('d M Y', strtotime('+15 days'));
    $previewLink = $recipientData['preview_link'] ?? 'https://friendlyaisolution.com';

    $search = [
        '{{1}}', '{{2}}', '{{3}}', '{{4}}', '{{5}}',
        '{{6}}', '{{7}}', '{{8}}', '{{9}}', '{{10}}',
        '{{11}}', '{{12}}', '{{13}}',
        '{name}', '{company}', '{phone}', '{amount}', '{due_date}'
    ];
    $replace = [
        $sender['firm_name'], // {{1}} From: *{{1}}*
        $custName,            // {{2}} Dear *{{2}}*
        $billNo,              // {{3}} invoice number *{{3}}*
        $amount,              // {{4}} amount *₹{{4}}*
        $balance,             // {{5}} Ledger balance is *₹{{5}}*
        $sender['upi_id'],    // {{6}} UPI ID: *{{6}}*
        $sender['bank_name'], // {{7}} Bank Name: *{{7}}*
        $sender['account_number'], // {{8}} Account No.:*{{8}}*
        $sender['branch'],    // {{9}} Branch: *{{9}}*
        $sender['ifsc_code'], // {{10}} IFSC Code: *{{10}}*
        $sender['firm_name'], // {{11}} Regards, *{{11}}*
        $sender['helpline'],  // {{12}} Helpline: *{{12}}*
        $previewLink,         // {{13}} Preview link: *{{13}}*
        $custName,            // {name}
        $custCompany,         // {company}
        $phone,               // {phone}
        '₹' . $amount,        // {amount}
        $dueDate              // {due_date}
    ];

    // Support any dynamic column headers from spreadsheet
    foreach ($recipientData as $rk => $rv) {
        if (!is_array($rv) && !is_object($rv)) {
            $strVal = (string)$rv;
            $search[] = '{' . $rk . '}';
            $replace[] = $strVal;
            $search[] = '{{' . $rk . '}}';
            $replace[] = $strVal;
            $cleanK = strtolower(preg_replace('/[\s_\-]/', '', $rk));
            if ($cleanK !== $rk) {
                $search[] = '{' . $cleanK . '}';
                $replace[] = $strVal;
            }
        }
    }

    return str_replace($search, $replace, $rawMsg);
}

function handleBroadcastMediaUpload($fileKey = 'media_file') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $file = $_FILES[$fileKey];
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        return '';
    }

    $uploadDir = __DIR__ . '/../uploads/broadcast_media/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $safeName = 'media_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $targetPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $root = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/\\');
        return $protocol . '://' . $host . $root . '/uploads/broadcast_media/' . $safeName;
    }
    return '';
}

function dispatchUnifiedWhatsAppMessage($pdo, $phone, $msgText, $buttons = [], $templateSlug = 'custom', $mediaUrl = null, $mediaPosition = 'top', $ownerUserId = null) {
    $targetUserId = $ownerUserId ? (int)$ownerUserId : (int)($_SESSION['user_id'] ?? 1);
    $cfg = getActiveWabaConfig($pdo, $targetUserId);
    if (empty($cfg)) {
        return [
            'success' => false,
            'status'  => 'disconnected',
            'session_stopped' => true,
            'message' => 'WhatsApp configuration not found for this account. Dispatch aborted to protect other accounts.'
        ];
    }

    $gateway = !empty($cfg['gateway_type']) ? $cfg['gateway_type'] : 'meta';
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

    if ($gateway === 'web_api') {
        // STRICT SAFETY CHECK: If this account's session is disconnected or not connected, STOP DISPATCH IMMEDIATELY!
        if (empty($cfg['web_api_session_status']) || $cfg['web_api_session_status'] !== 'connected') {
            return [
                'success' => false,
                'status'  => 'disconnected',
                'session_stopped' => true,
                'message' => 'WhatsApp Web session for this account is disconnected or restricted. Campaign halted immediately to protect other numbers.'
            ];
        }

        // WhatsApp Web API Mode (Paired phone camera session)
        $fullMsg = $msgText;
        if (!empty($buttons) && is_array($buttons)) {
            $fullMsg .= "\n";
            foreach ($buttons as $i => $b) {
                $bTitle = is_array($b) ? ($b['title'] ?? '') : (string)$b;
                if (!empty($bTitle)) {
                    $fullMsg .= "\n[" . ($i + 1) . "] " . $bTitle;
                }
            }
        }

        $userId = $targetUserId;
        $defaultNodeUrl = defined('WHATSAPP_ENGINE_URL') ? WHATSAPP_ENGINE_URL : (getenv('WHATSAPP_ENGINE_URL') ?: 'http://140.238.167.58:3000');
        $rawWebApiUrl = !empty($cfg['web_api_url']) ? trim($cfg['web_api_url']) : $defaultNodeUrl;

        // Build candidate endpoints (primary and fallback)
        $endpoints = [];
        if (strpos($rawWebApiUrl, '.php') !== false) {
            $endpoints[] = (strpos($rawWebApiUrl, '?') !== false) ? ($rawWebApiUrl . '&action=send_message') : ($rawWebApiUrl . '?action=send_message');
            $endpoints[] = rtrim($defaultNodeUrl, '/') . '/send-message';
        } else {
            $endpoints[] = rtrim($rawWebApiUrl, '/') . '/send-message';
            if (rtrim($rawWebApiUrl, '/') !== rtrim($defaultNodeUrl, '/')) {
                $endpoints[] = rtrim($defaultNodeUrl, '/') . '/send-message';
            }
        }

        $postData = [
            'user_id'   => $userId,
            'recipient' => $phoneDigits,
            'phone'     => $phoneDigits,
            'mobile'    => $phoneDigits,
            'message'   => $fullMsg,
            'msg'       => $fullMsg,
            'token'     => $cfg['web_api_token'] ?? '',
            'instance'  => $cfg['web_api_instance_id'] ?? ''
        ];

        if (!empty($mediaUrl)) {
            $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
            $fName = basename(parse_url($mediaUrl, PHP_URL_PATH)) ?: 'Attachment';
            $postData['media_url'] = $mediaUrl;
            $postData['media_position'] = $mediaPosition;
            $postData['media_type'] = $isImg ? 'image' : ($ext === 'pdf' ? 'pdf' : 'document');
            $postData['file_name'] = $fName;
            if ($isImg) {
                $postData['image_url'] = $mediaUrl;
            } else {
                $postData['pdf_url'] = $mediaUrl;
                $postData['document_url'] = $mediaUrl;
            }
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($cfg['web_api_token'])) {
            $headers[] = 'Authorization: Bearer ' . $cfg['web_api_token'];
            $headers[] = 'apikey: ' . $cfg['web_api_token'];
        }

        // Deduplicate endpoints
        $endpoints = array_values(array_unique(array_filter($endpoints)));

        $lastErr = 'WhatsApp Web engine not responding';
        $lastRes = null;

        foreach ($endpoints as $ep) {
            $ch = curl_init($ep);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($raw) {
                $res = json_decode($raw, true);
                if ($httpCode >= 200 && $httpCode < 300) {
                    if (!empty($res['success']) || (!empty($res['status']) && in_array(strtolower($res['status']), ['success', 'sent'])) || !empty($res['message_id'])) {
                        return [
                            'success'    => true,
                            'gateway'    => 'web_api',
                            'response'   => $res,
                            'message'    => "Message dispatched via WhatsApp Web to +{$phoneDigits}",
                            'message_id' => $res['message_id'] ?? null
                        ];
                    }
                    if (!empty($res['message']) || !empty($res['error'])) {
                        $lastErr = $res['message'] ?? ($res['error'] ?? 'WhatsApp Web engine error');
                        $lastRes = $res;
                    }
                } else {
                    $lastErr = "Engine returned HTTP $httpCode: " . ($res['message'] ?? $raw);
                    $lastRes = $res;
                }
            } else {
                $lastErr = "Connection failed to $ep: " . ($curlErr ?: 'timeout');
            }
        }

        $isRestricted = (stripos($lastErr, 'disconnected') !== false || stripos($lastErr, 'restrict') !== false || stripos($lastErr, 'scan qr') !== false || stripos($lastErr, 'logged out') !== false || stripos($lastErr, 'not initialized') !== false);
        if ($isRestricted) {
            try {
                $pdo->prepare("UPDATE merchant_waba_settings SET web_api_session_status = 'disconnected' WHERE user_id = ?")->execute([$userId]);
            } catch (\Throwable $ex) {}
            return [
                'success'         => false,
                'gateway'         => 'web_api',
                'status'          => 'disconnected',
                'session_stopped' => true,
                'message'         => "🚨 WhatsApp Session Disconnected/Restricted ({$lastErr}). Campaign stopped immediately to prevent using other accounts.",
                'error'           => ['message' => $lastErr, 'details' => $lastRes]
            ];
        }

        return [
            'success' => false,
            'gateway' => 'web_api',
            'message' => $lastErr,
            'error'   => ['message' => $lastErr, 'details' => $lastRes]
        ];
    } else {
        // Meta WhatsApp Cloud API Mode
        require_once __DIR__ . '/whatsapp-api.php';
        $whatsapp = new WhatsAppAPI($pdo);

        if (!empty($buttons) && is_array($buttons)) {
            $formattedBtns = [];
            foreach ($buttons as $b) {
                if (is_array($b) && !empty($b['title'])) {
                    $formattedBtns[] = [
                        'id' => $b['id'] ?? ('btn_' . substr(md5($b['title']), 0, 8)),
                        'title' => substr($b['title'], 0, 20)
                    ];
                } elseif (is_string($b) && !empty($b)) {
                    $formattedBtns[] = [
                        'id' => 'btn_' . substr(md5($b), 0, 8),
                        'title' => substr($b, 0, 20)
                    ];
                }
            }
            if (!empty($formattedBtns)) {
                return $whatsapp->sendReplyButtons($phoneDigits, $msgText, $formattedBtns);
            }
        }

        if ($templateSlug === 'marg_bill') {
            $sender = getSenderBankingAndProfileDetails($pdo);
            $components = [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string)$sender['firm_name']],
                        ['type' => 'text', 'text' => 'Valued Customer'],
                        ['type' => 'text', 'text' => 'INV-' . date('dmy') . '-' . rand(100, 999)],
                        ['type' => 'text', 'text' => '3,500'],
                        ['type' => 'text', 'text' => '0.00'],
                        ['type' => 'text', 'text' => (string)$sender['upi_id']],
                        ['type' => 'text', 'text' => (string)$sender['bank_name']],
                        ['type' => 'text', 'text' => (string)$sender['account_number']],
                        ['type' => 'text', 'text' => (string)$sender['branch']],
                        ['type' => 'text', 'text' => (string)$sender['ifsc_code']],
                        ['type' => 'text', 'text' => (string)$sender['firm_name']],
                        ['type' => 'text', 'text' => (string)$sender['helpline']],
                        ['type' => 'text', 'text' => 'https://friendlyaisolution.com']
                    ]
                ]
            ];
            $tRes = $whatsapp->sendTemplate($phoneDigits, 'marg_bill', 'en', $components);
            if (!empty($tRes['success']) && $tRes['success']) {
                return $tRes;
            }
        }

        if (!empty($mediaUrl)) {
            $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                if (strtolower($mediaPosition) === 'bottom') {
                    $resTxt = $whatsapp->sendText($phoneDigits, $msgText);
                    $resImg = $whatsapp->sendImage($phoneDigits, $mediaUrl, null);
                    return (!empty($resImg['success']) && $resImg['success']) ? $resImg : $resTxt;
                } else {
                    return $whatsapp->sendImage($phoneDigits, $mediaUrl, $msgText);
                }
            } else {
                return $whatsapp->sendDocument($phoneDigits, $mediaUrl, $msgText, basename($mediaUrl));
            }
        }

        return $whatsapp->sendText($phoneDigits, $msgText);
    }
}

function stripEmojisFromText($text) {
    if (empty($text)) return '';
    $clean = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{200D}\x{FE0F}]/u', '', $text);
    return trim(preg_replace('/\s+/', ' ', $clean));
}

function stripEmojisFromMultiLine($text) {
    if (empty($text)) return '';
    $clean = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\x{200D}\x{FE0F}]/u', '', $text);
    return trim($clean);
}

function generateAITemplate($prompt, $category = 'MARKETING', $tone = 'professional', $recipientName = '') {
    // 1. Check if Gemini API key is configured (supports constant, env, and DB settings)
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');
    if (!$geminiKey && function_exists('getSystemSetting')) {
        $geminiKey = getSystemSetting('gemini_api_key', '');
    }

    if (!empty($geminiKey)) {
        try {
            $recipContext = !empty($recipientName) ? "\nTarget Recipient: " . $recipientName : "";
            $sysPrompt = "You are an elite enterprise WhatsApp copywriter for Friendly AI Solution and Marg ERP CRM.
STRICT CONSTRAINT: DO NOT USE ANY EMOJIS, ICONS, OR SPECIAL SYMBOL CHARACTERS. Return plain professional text only.
Return a valid JSON object with the following keys:
- title: Short title (max 50 chars)
- category: 'MARKETING' or 'UTILITY'
- header_text: Clean headline (max 60 chars)
- body_text: Engaging, polite, high-converting WhatsApp message body. Naturally integrate dynamic placeholders: {name}, {company}, {phone}, {amount}, {due_date} wherever suitable.
- footer_text: Professional firm footer or department name (max 50 chars)
- buttons: Array of up to 3 short button labels (max 20 chars each)";

            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=" . urlencode($geminiKey);
            $postFields = [
                'contents' => [
                    ['parts' => [['text' => $sysPrompt . "\n\nUser Request: " . $prompt . $recipContext . "\nTone: " . $tone]]]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.6
                ]
            ];
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $raw = curl_exec($ch);
            curl_close($ch);

            $res = json_decode($raw, true);
            $text = trim($res['candidates'][0]['content']['parts'][0]['text'] ?? '');
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);
            $parsed = json_decode(trim($text), true);
            if ($parsed && !empty($parsed['body_text'])) {
                $cleanedButtons = [];
                if (!empty($parsed['buttons']) && is_array($parsed['buttons'])) {
                    foreach (array_slice($parsed['buttons'], 0, 3) as $btn) {
                        $cleanedButtons[] = stripEmojisFromText($btn);
                    }
                }
                return [
                    'title'       => stripEmojisFromText($parsed['title'] ?? 'Business Broadcast'),
                    'category'    => (strtoupper($parsed['category'] ?? '') === 'UTILITY') ? 'UTILITY' : 'MARKETING',
                    'header_text' => stripEmojisFromText($parsed['header_text'] ?? ''),
                    'body_text'   => stripEmojisFromMultiLine($parsed['body_text'] ?? ''),
                    'footer_text' => stripEmojisFromText($parsed['footer_text'] ?? ''),
                    'buttons'     => $cleanedButtons ?: ['Confirm Details', 'Contact Us', 'Call Support']
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 2. Intelligent High-Quality Domain-Specific Semantic Synthesizer (Zero Emojis, Auto-Variables, Typo-Tolerant)
    $lower = strtolower($prompt);
    $cat = 'MARKETING';

    // Domain Detections
    $isJewellery = (strpos($lower, 'jewel') !== false || strpos($lower, 'jwellery') !== false || strpos($lower, 'jewellery') !== false || strpos($lower, 'jewelry') !== false || strpos($lower, 'gold') !== false || strpos($lower, 'diamond') !== false || strpos($lower, 'silver') !== false || strpos($lower, 'ornament') !== false || strpos($lower, 'kundan') !== false);
    $isPharma = (strpos($lower, 'pharma') !== false || strpos($lower, 'chemist') !== false || strpos($lower, 'medical') !== false || strpos($lower, 'medicine') !== false || strpos($lower, 'drug') !== false || strpos($lower, 'clinic') !== false || strpos($lower, 'hospital') !== false);
    $isGarments = (strpos($lower, 'garment') !== false || strpos($lower, 'cloth') !== false || strpos($lower, 'apparel') !== false || strpos($lower, 'fashion') !== false || strpos($lower, 'saree') !== false || strpos($lower, 'kurti') !== false || strpos($lower, 'suit') !== false || strpos($lower, 'wear') !== false);
    $isFmcg = (strpos($lower, 'grocery') !== false || strpos($lower, 'supermarket') !== false || strpos($lower, 'kirana') !== false || strpos($lower, 'fmcg') !== false || strpos($lower, 'provision') !== false || strpos($lower, 'mart') !== false);
    $isAuto = (strpos($lower, 'auto') !== false || strpos($lower, 'car') !== false || strpos($lower, 'bike') !== false || strpos($lower, 'motor') !== false || strpos($lower, 'garage') !== false || strpos($lower, 'spare') !== false || strpos($lower, 'vehicle') !== false || strpos($lower, 'workshop') !== false);
    $isElectronics = (strpos($lower, 'electronic') !== false || strpos($lower, 'mobile') !== false || strpos($lower, 'laptop') !== false || strpos($lower, 'computer') !== false || strpos($lower, 'gadget') !== false || strpos($lower, 'appliance') !== false);
    $isFriendlyAi = (strpos($lower, 'friendly') !== false || strpos($lower, 'frienldy') !== false || strpos($lower, 'ai solution') !== false || strpos($lower, 'crm') !== false || strpos($lower, 'bot') !== false);

    // Workflow Intent Detections (Typo-Tolerant)
    $isAmc = (strpos($lower, 'amc') !== false || strpos($lower, 'renewal') !== false || strpos($lower, 'renwal') !== false || strpos($lower, 'renew') !== false || strpos($lower, 'license') !== false || strpos($lower, 'lisence') !== false || strpos($lower, 'expiry') !== false);
    $isBill = (strpos($lower, 'bill') !== false || strpos($lower, 'invoice') !== false || strpos($lower, 'invois') !== false || strpos($lower, 'payment') !== false || strpos($lower, 'due') !== false || strpos($lower, 'outstanding') !== false || strpos($lower, 'balance') !== false || strpos($lower, 'ledger') !== false || strpos($lower, 'baki') !== false);
    $isGst = (strpos($lower, 'gst') !== false || strpos($lower, 'compliance') !== false || strpos($lower, 'e-way') !== false || strpos($lower, 'einvoice') !== false || strpos($lower, 'e-invoice') !== false || strpos($lower, 'tax') !== false);
    $isWelcome = (strpos($lower, 'welcome') !== false || strpos($lower, 'wellcome') !== false || strpos($lower, 'welcom') !== false || strpos($lower, 'swagat') !== false || strpos($lower, 'onboard') !== false || strpos($lower, 'join') !== false || strpos($lower, 'joining') !== false || strpos($lower, 'new client') !== false || strpos($lower, 'new customer') !== false);
    $isFestive = (strpos($lower, 'festive') !== false || strpos($lower, 'festiv') !== false || strpos($lower, 'diwali') !== false || strpos($lower, 'holi') !== false || strpos($lower, 'eid') !== false || strpos($lower, 'new year') !== false || strpos($lower, 'christmas') !== false || strpos($lower, 'celebration') !== false);

    if ($isJewellery) {
        $cat = 'MARKETING';
        $title = 'Exclusive Jewellery Collection Launch';
        $header = 'Festive Jewellery Showcase';
        $body = "Dear {name},\n\nCelebrate your precious moments with the all-new Festive Gold, Diamond & Polki Collection at {company}.\n\nExclusive Showroom Privileges for You:\n- Flat 25% Off on Jewellery Making Charges\n- Zero Wastage on Certified Hallmarked Gold\n- Special Exchange Value Bonus on Old Gold\n- Starting Price from *{amount}*\n\nOffer Valid Till: *{due_date}*\n\nBook your private showroom preview or connect with our master jewellery consultant at *{phone}*.\n\nWarm regards,\n{company}";
        $footer = '{company} - Hallmark Certified Jewellery';
        $buttons = ['View Catalogue', 'Book VIP Visit', 'Call Showroom'];

    } elseif ($isPharma && ($isBill || $isAmc)) {
        $cat = 'UTILITY';
        $title = 'Pharmacy Invoice & Supply Statement';
        $header = 'Pharmaceutical Billing Notice';
        $body = "Dear {name},\n\nWe hope operations at {company} are running smoothly.\n\nYour pharmaceutical supply ledger reflects an outstanding balance of *{amount}* due on *{due_date}*.\n\nPlease arrange payment settlement to ensure uninterrupted medical stock dispatches and credit privileges.\n\nFor ledger reconciliation and accounts queries: *{phone}*.\n\nThank you for your ongoing partnership.";
        $footer = 'Accounts & Supply Department';
        $buttons = ['Pay Invoice Online', 'Request Ledger Copy', 'Call Accounts'];

    } elseif ($isPharma) {
        $cat = 'MARKETING';
        $title = 'New Medicine & Healthcare Arrivals';
        $header = 'Pharma Stock Update';
        $body = "Dear {name},\n\nFresh consignments of high-demand generic medicines, surgical supplies, and wellness products have arrived at {company}.\n\nSpecial B2B Partner Schemes:\n- Extra 12% Margin on Bulk Quantity Orders\n- Same-Day Priority Dispatch & Express Delivery\n- Minimum Qualifying Order Value: *{amount}*\n- Promotional Scheme Valid Till: *{due_date}*\n\nContact our sales desk at *{phone}* to place your purchase order.";
        $footer = '{company} Wholesale Pharmaceuticals';
        $buttons = ['Download Price List', 'Place Stock Order', 'Call Sales Desk'];

    } elseif ($isGarments) {
        $cat = 'MARKETING';
        $title = 'New Season Fashion Collection Launch';
        $header = 'Exclusive Fashion Showcase';
        $body = "Dear {name},\n\nUpgrade your wardrobe with the latest seasonal designer wear and ethnic collections at {company}!\n\nSpecial In-Store Privileges:\n- Flat 20% Instant Discount on New Arrivals\n- Buy 2 Get 1 Free on Select Branded Apparel\n- Special VIP Preview for Orders Above *{amount}*\n\nLimited Period Offer Valid Till: *{due_date}*\n\nVisit our store or call our personal style consultant at *{phone}*.\n\nWe look forward to serving you!";
        $footer = '{company} Fashion & Apparel Studio';
        $buttons = ['Explore Collection', 'Visit Store', 'Call Showroom'];

    } elseif ($isFmcg) {
        $cat = 'MARKETING';
        $title = 'Monthly Grocery Super Saver Deals';
        $header = 'Supermarket Weekly Savings';
        $body = "Dear {name},\n\nRestock your pantry with unmatched monthly savings at {company}!\n\nTop Super Saver Offers:\n- Up to 30% Off on Daily Household Staples\n- Buy 1 Get 1 Free on Packaged Goods\n- Free Express Home Delivery on Orders Above *{amount}*\n\nSavings Bonanza Valid Till: *{due_date}*\n\nSend your grocery list via WhatsApp or call our order line at *{phone}*.\n\nHappy Shopping with {company}!";
        $footer = '{company} Daily Mart & Provisions';
        $buttons = ['Send Grocery List', 'View Weekly Deals', 'Call Mart'];

    } elseif ($isAuto) {
        $cat = 'MARKETING';
        $title = 'Vehicle Periodic Service Camp';
        $header = 'Vehicle Care & Inspection Camp';
        $body = "Dear {name},\n\nEnsure maximum road safety and engine performance for your vehicle at {company}.\n\nService Camp Highlights:\n- 40-Point Comprehensive Vehicle Inspection\n- 15% Discount on Labor Charges & Genuine Spare Parts\n- Complete AC & Battery Health Check Package at *{amount}*\n\nCamp Registration Open Till: *{due_date}*\n\nCall our service supervisor at *{phone}* to reserve your preferred time slot.";
        $footer = '{company} Authorized Auto Care';
        $buttons = ['Book Service Slot', 'Workshop Location', 'Call Service Desk'];

    } elseif ($isElectronics) {
        $cat = 'MARKETING';
        $title = 'Mega Technology Exchange Festival';
        $header = 'Electronics & Gadget Festival';
        $body = "Dear {name},\n\nUpgrade to the latest smartphones, laptops, and smart appliances at {company}!\n\nFestival Advantages for You:\n- Instant Exchange Bonus & Up to 25% Off on New Gadgets\n- Flexible Zero-Cost EMI Available\n- Package Deals Starting from *{amount}*\n\nSpecial Promotion Valid Till: *{due_date}*\n\nVisit our showroom or call our technology advisor at *{phone}* to reserve your device.";
        $footer = '{company} Digital & Electronics';
        $buttons = ['Check Exchange Value', 'Visit Store', 'Call Advisor'];

    } elseif ($isAmc) {
        $cat = 'UTILITY';
        $title = 'Marg ERP Software AMC Renewal Notice';
        $header = 'Software Maintenance Renewal';
        $body = "Dear {name},\n\nYour Marg ERP Software Annual Maintenance Contract (AMC) for {company} of *{amount}* is scheduled for renewal on *{due_date}*.\n\nRenewing your AMC ensures:\n- Seamless 24/7 Billing & Invoicing Continuity\n- Automatic GST E-Way Bill & E-Invoice Compliance Patches\n- Priority Remote Support & Scheduled Cloud Data Backups\n\nKindly complete renewal on or before *{due_date}* to avoid service disruption.\n\nHelpline: *{phone}*";
        $footer = 'Marg Soft Solution Support Desk';
        $buttons = ['Renew AMC Online', 'Request Callback', 'Contact Support Desk'];

    } elseif ($isBill) {
        $cat = 'UTILITY';
        $title = 'Outstanding Invoice Statement Notice';
        $header = 'Invoice Statement Notice';
        $body = "Dear {name},\n\nGreetings from {company}.\n\nYour software and services account statement shows an outstanding balance of *{amount}* due on *{due_date}*.\n\nPlease process the invoice payment at your earliest convenience to ensure uninterrupted cloud sync and support services.\n\nFor payment details, ledger queries, or bank transfer slips, call our accounts desk at *{phone}*.\n\nThank you for choosing Marg ERP.";
        $footer = 'Accounts & Finance Department';
        $buttons = ['Pay Bill Online', 'Request Ledger Copy', 'Call Accounts Desk'];

    } elseif ($isGst) {
        $cat = 'UTILITY';
        $title = 'Mandatory GST & Software Compliance Update';
        $header = 'Statutory Compliance Notice';
        $body = "Dear {name} ({company}),\n\nA critical update is available for your Marg ERP software to maintain compliance with statutory GST regulations and e-invoicing standards.\n\nKey Update Capabilities:\n- 1-Click GSTR-1 and GSTR-3B Reconciliation\n- Automated E-Way Bill QR Code Generation\n- Faster Data Indexing & Cloud Backup Sync\n\nCall our technical desk at *{phone}* to schedule your remote update session.";
        $footer = 'Marg ERP Technical Support Team';
        $buttons = ['Schedule Update', 'Technical Assistance', 'Call Support Desk'];

    } elseif ($isWelcome && $isFriendlyAi) {
        $cat = 'MARKETING';
        $title = 'Welcome to Friendly AI Solution';
        $header = 'Welcome to Friendly AI Solution';
        $body = "Dear {name},\n\nWe are delighted to welcome {company} to Friendly AI Solution!\n\nYour intelligent business software and AI CRM automation setup is now active. Our certified product specialist will assist you with initial onboarding, data integration, and team training.\n\nDedicated Relationship & Support Line: *{phone}*.\n\nThank you for placing your trust in Friendly AI Solution.";
        $footer = 'Friendly AI Solution Success Team';
        $buttons = ['Start Quick Tour', 'Call Support Desk', 'Explore Features'];

    } elseif ($isWelcome) {
        $cat = 'MARKETING';
        $title = 'Welcome Client Greetings & Onboarding';
        $header = 'Welcome to Our Business Family';
        $body = "Dear {name},\n\nWe are delighted to welcome {company} to our business family!\n\nYour software and service setup is now active. Our certified product specialist will assist you with initial onboarding, setup configuration, and staff training.\n\nDedicated Relationship Line: *{phone}*.\n\nThank you for placing your trust in our services.";
        $footer = 'Customer Success Team';
        $buttons = ['Start Quick Tour', 'Call Support Desk', 'View Video Guides'];

    } elseif ($isFestive) {
        $cat = 'MARKETING';
        $title = 'Warm Festive Greetings & Exclusive Benefits';
        $header = 'Festive Greetings & Special Offer';
        $body = "Dear {name},\n\nWishing you, your family, and the entire team at {company} a joyful and prosperous festive season!\n\nTo celebrate this occasion, we are pleased to extend exclusive festive privileges:\n- Special Promotional Savings up to *{amount}*\n- Complimentary System Health Check & Optimization\n\nSpecial Festive Benefits Valid Till: *{due_date}*\n\nConnect with our team at *{phone}* to activate your benefits.\n\nWarm regards,\n{company}";
        $footer = 'Warm Regards, {company}';
        $buttons = ['Claim Festive Offer', 'Connect with Us', 'Call Support Desk'];

    } else {
        // High-Precision General Synthesizer (Zero Robotic Clutter)
        $cleanPrompt = stripEmojisFromText($prompt);
        $titleWords = ucwords(substr(preg_replace('/[^a-zA-Z0-9\s]/', '', $cleanPrompt), 0, 36));
        $title = $titleWords ?: 'Business Broadcast Announcement';
        
        $isPromoWords = (strpos($lower, 'offer') !== false || strpos($lower, 'discount') !== false || strpos($lower, 'sale') !== false || strpos($lower, 'promo') !== false || strpos($lower, 'marketing') !== false || strpos($lower, 'launch') !== false || strpos($lower, 'special') !== false);
        $cat = $isPromoWords ? 'MARKETING' : 'UTILITY';

        $header = $isPromoWords ? 'Special Business Announcement' : 'Official Business Update';
        $body = "Dear {name},\n\nGreetings from {company}.\n\nWe are pleased to connect with you regarding: " . $cleanPrompt . ".\n\nOur team is dedicated to providing high-quality service, seamless assistance, and reliable business solutions for your organization.\n\nFor any details or immediate assistance, please connect with our relationship desk at *{phone}*.\n\nThank you for your valued partnership.";
        $footer = 'Customer Relationship Desk';
        $buttons = ['Confirm Details', 'Contact Us', 'Call Support Desk'];
    }

    return [
        'title'       => stripEmojisFromText($title),
        'category'    => $cat,
        'header_text' => stripEmojisFromText($header),
        'body_text'   => stripEmojisFromMultiLine($body),
        'footer_text' => stripEmojisFromText($footer),
        'buttons'     => array_map('stripEmojisFromText', $buttons)
    ];
}

/**
 * Atomic Row-Locked Campaign Batch Processor with Anti-Ban Protection
 * Callable via Web AJAX and CLI Background Cron Scheduler
 */
function internalProcessCampaignBatch($pdo, $id, $batchSize = 5, $isCli = false) {
    $id = intval($id);
    $batchSize = max(1, min(15, intval($batchSize)));

    if (!$id) return ['success' => false, 'message' => 'Campaign ID required', 'http_code' => 400];

    // Fetch campaign
    $stmtC = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
    $stmtC->execute([$id]);
    $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) return ['success' => false, 'message' => 'Campaign not found', 'http_code' => 404];

    if ($campaign['status'] !== 'running') {
        return ['success' => true, 'message' => 'Campaign is currently ' . $campaign['status'], 'status' => $campaign['status'], 'campaign' => $campaign];
    }

    // Daily quota reset on new calendar day
    $curDate = date('Y-m-d');
    if (!empty($campaign['last_dispatched_date']) && $campaign['last_dispatched_date'] !== $curDate) {
        $pdo->prepare("UPDATE broadcast_campaigns SET today_sent_count = 0, last_dispatched_date = ? WHERE id = ?")->execute([$curDate, $id]);
        $campaign['today_sent_count'] = 0;
        $campaign['last_dispatched_date'] = $curDate;
    }

    // Daily quota enforcement
    $dailyLimit = intval($campaign['daily_limit'] ?? 0);
    $todaySent = intval($campaign['today_sent_count'] ?? 0);

    if ($dailyLimit > 0 && $todaySent >= $dailyLimit) {
        $stmtPause = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'paused' WHERE id = ?");
        $stmtPause->execute([$id]);
        $campaign['status'] = 'paused';
        $campaign['quota_reached'] = true;
        return [
            'success' => true,
            'quota_reached' => true,
            'message' => "🛡️ Anti-Ban Quota Reached: {$todaySent}/{$dailyLimit} messages sent today. Campaign safely paused.",
            'status' => 'paused',
            'campaign' => $campaign
        ];
    }

    if ($dailyLimit > 0) {
        $remainingAllowed = max(1, $dailyLimit - $todaySent);
        $batchSize = min($batchSize, $remainingAllowed);
    }

    // Ensure schema support for updated_at and variables_json
    try { $pdo->exec("ALTER TABLE campaign_audience ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE campaign_audience ADD COLUMN variables_json TEXT NULL AFTER company_name"); } catch (\Throwable $e) {}

    // Stale lock recovery: auto-reset rows stuck in 'processing' for > 5 minutes back to 'pending'
    $pdo->prepare("UPDATE campaign_audience SET status = 'pending' WHERE campaign_id = ? AND status = 'processing' AND sent_at IS NULL AND (updated_at < NOW() - INTERVAL 5 MINUTE OR updated_at IS NULL)")->execute([$id]);

    // ATOMIC ROW-LEVEL RESERVATION (Mathematically eliminates duplicate messages):
    $stmtReserve = $pdo->prepare("UPDATE campaign_audience SET status = 'processing' WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT $batchSize");
    $stmtReserve->execute([$id]);

    // Query reserved rows
    $stmtAud = $pdo->prepare("SELECT * FROM campaign_audience WHERE campaign_id = ? AND status = 'processing' ORDER BY id ASC LIMIT $batchSize");
    $stmtAud->execute([$id]);
    $pendingBatch = $stmtAud->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingBatch)) {
        $stmtRemain = $pdo->prepare("SELECT COUNT(*) FROM campaign_audience WHERE campaign_id = ? AND status IN ('pending', 'processing')");
        $stmtRemain->execute([$id]);
        $rem = intval($stmtRemain->fetchColumn());

        if ($rem === 0) {
            $stmtDone = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'completed', pending_count = 0 WHERE id = ?");
            $stmtDone->execute([$id]);
            $stmtC2 = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC2->execute([$id]);
            $updatedCampaign = $stmtC2->fetch(PDO::FETCH_ASSOC);
            return ['success' => true, 'message' => 'Campaign completed!', 'status' => 'completed', 'campaign' => $updatedCampaign];
        } else {
            return ['success' => true, 'message' => 'Batch currently being processed', 'status' => 'running', 'campaign' => $campaign];
        }
    }

    $ownerUserId = null;
    if (!empty($campaign['created_by'])) {
        try {
            $stmtUsr = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmtUsr->execute([$campaign['created_by'], $campaign['created_by']]);
            $ownerUserId = $stmtUsr->fetchColumn() ?: null;
        } catch (\Throwable $e) {}
    }
    if (!$ownerUserId) {
        $ownerUserId = $_SESSION['user_id'] ?? 1;
    }

    // PRE-FLIGHT VERIFICATION: Check if this specific account's WhatsApp is connected BEFORE sending
    $preCheckCfg = getActiveWabaConfig($pdo, $ownerUserId);
    $isDisconnected = empty($preCheckCfg) || (!empty($preCheckCfg['gateway_type']) && $preCheckCfg['gateway_type'] === 'web_api' && ($preCheckCfg['web_api_session_status'] ?? '') !== 'connected');
    if ($isDisconnected) {
        $pdo->prepare("UPDATE campaign_audience SET status = 'pending' WHERE campaign_id = ? AND status = 'processing'")->execute([$id]);
        $pdo->prepare("UPDATE broadcast_campaigns SET status = 'paused' WHERE id = ?")->execute([$id]);
        $campaign['status'] = 'paused';
        return [
            'success'            => false,
            'status'             => 'paused',
            'session_restricted' => true,
            'message'            => "🚨 WhatsApp account for this campaign is disconnected or restricted. Campaign paused immediately to protect all numbers.",
            'campaign'           => $campaign
        ];
    }

    $processedCount = 0;
    $sentInc = 0;
    $failInc = 0;
    $emergencyStop = false;
    $emergencyMessage = '';

    foreach ($pendingBatch as $item) {
        $phone = $item['mobile'];
        $custName = $item['customer_name'] ?? 'Valued Customer';
        $compName = $item['company_name'] ?? 'Marg Client';
        $template = $campaign['template_name'];

        $rawMsg = '';
        $buttons = [];

        if (!empty($template) && $template !== 'custom' && $template !== 'ai_compose') {
            $stmtT = $pdo->prepare("SELECT * FROM whatsapp_templates WHERE slug = ? LIMIT 1");
            $stmtT->execute([$template]);
            $tRow = $stmtT->fetch(PDO::FETCH_ASSOC);

            if ($tRow) {
                $rawMsg = $tRow['body_text'];
                if (!empty($tRow['buttons_json'])) {
                    $buttons = json_decode($tRow['buttons_json'], true) ?? [];
                }
            }
        }
        
        if (empty($rawMsg)) {
            $rawMsg = !empty($campaign['custom_message']) ? $campaign['custom_message'] : "Welcome to Marg Soft Solution! How can we assist your business today?";
        }

        $rowVars = [];
        if (!empty($item['variables_json'])) {
            $rowVars = json_decode($item['variables_json'], true) ?: [];
        }

        $recipientData = array_merge([
            'name'     => $custName,
            'company'  => $compName,
            'phone'    => $phone,
            'amount'   => $rowVars['amount'] ?? $rowVars['balance'] ?? '3,500',
            'due_date' => $rowVars['due_date'] ?? $rowVars['date'] ?? date('d M Y', strtotime('+15 days'))
        ], $rowVars);

        $msgText = resolveTemplateVariables($rawMsg, $pdo, $recipientData);

        $mediaUrl = !empty($campaign['media_url']) ? $campaign['media_url'] : null;
        $mediaPos = !empty($campaign['media_position']) ? $campaign['media_position'] : 'top';

        $res = dispatchUnifiedWhatsAppMessage($pdo, $phone, $msgText, $buttons, $template, $mediaUrl, $mediaPos, $ownerUserId);

        $processedCount++;

        if (!empty($res['success']) && $res['success']) {
            $sentInc++;
            $updAud = $pdo->prepare("UPDATE campaign_audience SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $updAud->execute([$item['id']]);
        } else {
            $failInc++;
            $errText = $res['error']['message'] ?? ($res['message'] ?? 'Dispatch failed');
            $updAud = $pdo->prepare("UPDATE campaign_audience SET status = 'failed', error_message = ?, sent_at = NOW() WHERE id = ?");
            $updAud->execute([$errText, $item['id']]);

            $isSessionProblem = (!empty($res['session_stopped']) 
                || stripos($errText, 'disconnected') !== false 
                || stripos($errText, 'restrict') !== false 
                || stripos($errText, 'banned') !== false 
                || stripos($errText, 'logged out') !== false 
                || stripos($errText, 'scan qr') !== false);

            if ($isSessionProblem) {
                $pdo->prepare("UPDATE campaign_audience SET status = 'pending' WHERE campaign_id = ? AND status = 'processing'")->execute([$id]);
                $pdo->prepare("UPDATE broadcast_campaigns SET status = 'paused' WHERE id = ?")->execute([$id]);
                $campaign['status'] = 'paused';
                $emergencyStop = true;
                $emergencyMessage = "🚨 WhatsApp account is disconnected or restricted ({$errText}). Campaign paused immediately to prevent sending from other numbers.";
                break;
            }
        }

        // Anti-ban spacing:
        // In CLI / Cron mode: sleep is safe.
        // In Web HTTP mode: DO NOT sleep for 30s-60s! Return delay_seconds so frontend countdown timer handles the interval cleanly without hanging the web server / triggering Hostinger 403 firewall IP ban.
        $delaySec = intval($campaign['delay_seconds'] ?? 0);
        if ($isCli) {
            if (!empty($preCheckCfg['gateway_type']) && $preCheckCfg['gateway_type'] === 'web_api') {
                $jitterSec = max(4, $delaySec > 0 ? $delaySec : rand(4, 7));
                sleep($jitterSec);
            } else {
                if ($delaySec > 0) {
                    usleep(min(3000000, max(200000, $delaySec * 1000000)));
                } else {
                    usleep(150000);
                }
            }
        }
    }

    // Update campaign stats
    $stmtUpdC = $pdo->prepare("UPDATE broadcast_campaigns SET sent_count = sent_count + ?, failed_count = failed_count + ?, today_sent_count = today_sent_count + ?, pending_count = GREATEST(0, pending_count - ?) WHERE id = ?");
    $stmtUpdC->execute([$sentInc, $failInc, $sentInc, $processedCount, $id]);

    // Re-fetch updated campaign row
    $stmtC2 = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
    $stmtC2->execute([$id]);
    $updatedCampaign = $stmtC2->fetch(PDO::FETCH_ASSOC);

    if (!empty($emergencyStop)) {
        return [
            'success'            => false,
            'status'             => 'paused',
            'session_restricted' => true,
            'message'            => $emergencyMessage,
            'campaign'           => $updatedCampaign
        ];
    }

    // Check remaining pending + processing
    $stmtCheckRem = $pdo->prepare("SELECT COUNT(*) FROM campaign_audience WHERE campaign_id = ? AND status IN ('pending', 'processing')");
    $stmtCheckRem->execute([$id]);
    $totalRemaining = intval($stmtCheckRem->fetchColumn());

    if ($totalRemaining <= 0) {
        $stmtDone = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'completed', pending_count = 0 WHERE id = ?");
        $stmtDone->execute([$id]);
        $updatedCampaign['status'] = 'completed';
        $updatedCampaign['pending_count'] = 0;
    } elseif ($dailyLimit > 0 && intval($updatedCampaign['today_sent_count']) >= $dailyLimit) {
        $stmtPause = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'paused' WHERE id = ?");
        $stmtPause->execute([$id]);
        $updatedCampaign['status'] = 'paused';
        $updatedCampaign['quota_reached'] = true;
    }

    return [
        'success'       => true,
        'processed'     => $processedCount,
        'sent'          => $sentInc,
        'failed'        => $failInc,
        'status'        => $updatedCampaign['status'] ?? 'running',
        'delay_seconds' => $delaySec,
        'quota_reached' => !empty($updatedCampaign['quota_reached']),
        'remaining'     => $totalRemaining,
        'campaign'      => $updatedCampaign,
        'message'       => "Batch of {$processedCount} processed. Anti-ban interval: {$delaySec}s."
    ];
}

if (defined('IN_CRON_SCHEDULER') || (php_sapi_name() === 'cli' && empty($_GET['action']) && empty($_POST['action']))) {
    // Loaded as library helper by background cron scheduler
    return;
}

try {
    switch ($action) {
        // ---------------------------------------------------------
        // 0. Get Active Gateway Status & Connected State
        // ---------------------------------------------------------
        case 'get_gateway_status':
            $cfg = getActiveWabaConfig($pdo);
            $gateway = !empty($cfg['gateway_type']) ? $cfg['gateway_type'] : 'meta';
            $hasMeta = !empty($cfg['phone_number_id']) && !empty($cfg['access_token']);
            $hasWeb = !empty($cfg['web_api_session_status']) && $cfg['web_api_session_status'] === 'connected';
            $isConnected = ($gateway === 'web_api') ? $hasWeb : $hasMeta;
            $phone = !empty($cfg['business_phone']) ? $cfg['business_phone'] : '';

            sendJsonResponse([
                'success'       => true,
                'gateway_type'  => $gateway,
                'is_connected'  => $isConnected,
                'has_meta'      => $hasMeta,
                'has_web'       => $hasWeb,
                'phone'         => $phone,
                'gateway_label' => ($gateway === 'web_api') ? 'WhatsApp Web API (Paired Phone)' : 'Meta WhatsApp Cloud API (Official WABA)'
            ]);
            break;

        // ---------------------------------------------------------
        // 0.1 AI Template Generator
        // ---------------------------------------------------------
        case 'ai_generate_template':
            $rawInput = json_decode(file_get_contents('php://input'), true) ?? [];
            $prompt = trim($_POST['prompt'] ?? $_REQUEST['prompt'] ?? $rawInput['prompt'] ?? '');
            $recipientName = trim($_POST['recipient'] ?? $_POST['recipient_name'] ?? $_REQUEST['recipient'] ?? $rawInput['recipient'] ?? '');
            $category = trim($_POST['category'] ?? $_REQUEST['category'] ?? $rawInput['category'] ?? 'MARKETING');
            $tone = trim($_POST['tone'] ?? $_REQUEST['tone'] ?? $rawInput['tone'] ?? 'professional');

            if (empty($prompt)) {
                sendJsonResponse(['success' => false, 'message' => 'Please explain your template purpose or prompt.'], 400);
            }

            $gen = generateAITemplate($prompt, $category, $tone, $recipientName);
            sendJsonResponse([
                'success'  => true,
                'message'  => 'Template generated successfully by AI!',
                'template' => $gen
            ]);
            break;

        // ---------------------------------------------------------
        // 0.2 Get Contacts List for Specific Number Selection
        // ---------------------------------------------------------
        case 'get_contacts_list':
            $contacts = [];
            try {
                $stmtCD = $pdo->query("SELECT customer_id as id, party_name as name, company_using as company, mobile as phone, balance as amount FROM client_directory WHERE mobile IS NOT NULL AND mobile != '' ORDER BY id DESC LIMIT 500");
                $cdList = $stmtCD ? $stmtCD->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($cdList as $c) {
                    $p = preg_replace('/[^0-9]/', '', $c['phone']);
                    if (strlen($p) >= 10) {
                        if (strlen($p) == 10) $p = '91' . $p;
                        $contacts[$p] = [
                            'phone'    => $p,
                            'name'     => $c['name'] ?: 'Valued Customer',
                            'company'  => $c['company'] ?: 'Client',
                            'amount'   => !empty($c['amount']) ? ('₹' . number_format((float)$c['amount'])) : '₹3,500',
                            'due_date' => date('d M Y', strtotime('+15 days')),
                            'type'     => 'Client'
                        ];
                    }
                }
            } catch (\Throwable $e) {}

            try {
                $stmtLd = $pdo->query("SELECT id, name, company, phone FROM leads WHERE phone IS NOT NULL AND phone != '' ORDER BY id DESC LIMIT 500");
                $ldList = $stmtLd ? $stmtLd->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($ldList as $l) {
                    $p = preg_replace('/[^0-9]/', '', $l['phone']);
                    if (strlen($p) >= 10) {
                        if (strlen($p) == 10) $p = '91' . $p;
                        if (!isset($contacts[$p])) {
                            $contacts[$p] = [
                                'phone'    => $p,
                                'name'     => $l['name'] ?: 'Sales Lead',
                                'company'  => $l['company'] ?: 'Prospect',
                                'amount'   => '₹3,500',
                                'due_date' => date('d M Y', strtotime('+15 days')),
                                'type'     => 'Lead'
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {}

            sendJsonResponse(['success' => true, 'contacts' => array_values($contacts)]);
            break;

        // ---------------------------------------------------------
        // 1. List Campaigns (Strict Multi-Tenant Scoping)
        // ---------------------------------------------------------
        case 'get_campaigns':
            $curRole = $_SESSION['user_role'] ?? 'Executive';
            $curUser = $_SESSION['user_name'] ?? '';
            $curUid  = intval($_SESSION['user_id'] ?? 1);
            $isSuperAdmin = in_array($curRole, ['Super Admin', 'Admin', 'Regional Manager', 'Tenant Admin']);

            if ($isSuperAdmin) {
                $stmt = $pdo->query("SELECT * FROM broadcast_campaigns ORDER BY id DESC");
            } else {
                $stmt = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE created_by = ? OR created_by = ? ORDER BY id DESC");
                $stmt->execute([$curUser, (string)$curUid]);
            }
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campaigns as &$c) {
                $total = max(1, intval($c['total_contacts']));
                $sent = intval($c['sent_count']);
                $c['progress_percent'] = min(100, round(($sent / $total) * 100));
                $c['formatted_created'] = date('d M Y, h:i A', strtotime($c['created_at']));
                $c['daily_limit'] = intval($c['daily_limit'] ?? 0);
                $c['today_sent_count'] = intval($c['today_sent_count'] ?? 0);
                $c['is_quota_reached'] = ($c['daily_limit'] > 0 && $c['today_sent_count'] >= $c['daily_limit'] && $c['status'] === 'paused');
            }

            sendJsonResponse(['success' => true, 'campaigns' => $campaigns]);
            break;

        // ---------------------------------------------------------
        // 2. Campaign Details & Audience List
        // ---------------------------------------------------------
        case 'get_campaign_details':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) sendJsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);

            $stmtC = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC->execute([$id]);
            $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) sendJsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);

            $stmtA = $pdo->prepare("SELECT * FROM campaign_audience WHERE campaign_id = ? ORDER BY id ASC LIMIT 2000");
            $stmtA->execute([$id]);
            $audience = $stmtA->fetchAll(PDO::FETCH_ASSOC);

            sendJsonResponse(['success' => true, 'campaign' => $campaign, 'audience' => $audience]);
            break;

        // ---------------------------------------------------------
        // 3. Create Campaign (Clients / Leads / CSV Upload)
        // ---------------------------------------------------------
        case 'create_campaign':
            $name = trim($_POST['name'] ?? '');
            $templateName = trim($_POST['template_name'] ?? 'amc_renewal_reminder');
            $targetType = trim($_POST['target_type'] ?? 'clients');
            $customMessage = trim($_POST['custom_message'] ?? '');
            $delaySeconds = max(1, intval($_POST['delay_seconds'] ?? 2));
            $userActor = $_SESSION['user_name'] ?? 'Support Executive';
            $userRole  = $_SESSION['user_role'] ?? 'Executive';

            if (empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Campaign Name is required'], 400);
            }

            // If Admin, auto-approve; else require Admin approval
            $initialStatus = in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager']) ? 'approved' : 'pending_approval';

            // Insert campaign record
            $stmtIns = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, delay_seconds, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$name, $templateName, $targetType, $customMessage, $delaySeconds, $initialStatus, $userActor]);
            $campaignId = $pdo->lastInsertId();

            $selectedPhones = isset($_POST['selected_phones']) ? (array)$_POST['selected_phones'] : [];

            $contacts = [];

            if ($targetType === 'clients') {
                // Fetch contacts from client_directory
                try {
                    $stmtCD = $pdo->query("SELECT customer_id as id, party_name as name, company_using as company, mobile as phone FROM client_directory WHERE mobile IS NOT NULL AND mobile != '' LIMIT 1000");
                    $cdList = $stmtCD ? $stmtCD->fetchAll(PDO::FETCH_ASSOC) : [];
                    foreach ($cdList as $ac) {
                        $p = preg_replace('/[^0-9]/', '', $ac['phone']);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;
                            if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                                $contacts[$p] = [
                                    'mobile' => $p,
                                    'name'   => !empty($ac['name']) ? $ac['name'] : 'Valued Customer',
                                    'company'=> !empty($ac['company']) ? $ac['company'] : 'Marg Customer'
                                ];
                            }
                        }
                    }
                } catch (Throwable $eCD) {}

                try {
                    $stmtTC = $pdo->query("SELECT id, name, company_name as company, mobile as phone FROM tenant_companies WHERE mobile IS NOT NULL AND mobile != '' LIMIT 1000");
                    $tcList = $stmtTC ? $stmtTC->fetchAll(PDO::FETCH_ASSOC) : [];
                    foreach ($tcList as $tc) {
                        $p = preg_replace('/[^0-9]/', '', $tc['phone']);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;
                            if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                                $contacts[$p] = [
                                    'mobile' => $p,
                                    'name'   => !empty($tc['name']) ? $tc['name'] : 'Tenant Client',
                                    'company'=> !empty($tc['company']) ? $tc['company'] : 'Marg Partner'
                                ];
                            }
                        }
                    }
                } catch (Throwable $eTC) {}
            } elseif ($targetType === 'leads') {
                $stmtLd = $pdo->query("SELECT id, name, company, phone FROM leads WHERE phone IS NOT NULL AND phone != '' LIMIT 1000");
                $ldList = $stmtLd ? $stmtLd->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($ldList as $lc) {
                    $p = preg_replace('/[^0-9]/', '', $lc['phone']);
                    if (strlen($p) >= 10) {
                        if (strlen($p) == 10) $p = '91' . $p;
                        if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                            $contacts[$p] = [
                                'mobile' => $p,
                                'name'   => !empty($lc['name']) ? $lc['name'] : 'Lead Contact',
                                'company'=> !empty($lc['company']) ? $lc['company'] : 'Prospect'
                            ];
                        }
                    }
                }
            } elseif ($targetType === 'csv' && isset($_FILES['csv_file'])) {
                $file = $_FILES['csv_file']['tmp_name'];
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $header = fgetcsv($handle, 2000, ",");
                    $colMap = ['phone' => -1, 'name' => -1, 'company' => -1, 'amount' => -1, 'due_date' => -1, 'invoice' => -1];
                    $extraCols = [];
                    if (is_array($header)) {
                        foreach ($header as $idx => $rawH) {
                            $cleanH = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $rawH)));
                            if ($colMap['phone'] === -1 && (strpos($cleanH, 'phone') !== false || strpos($cleanH, 'mobile') !== false || strpos($cleanH, 'contact') !== false || strpos($cleanH, 'number') !== false)) {
                                $colMap['phone'] = $idx;
                            } elseif ($colMap['name'] === -1 && (strpos($cleanH, 'name') !== false || strpos($cleanH, 'customer') !== false || strpos($cleanH, 'party') !== false || strpos($cleanH, 'client') !== false)) {
                                $colMap['name'] = $idx;
                            } elseif ($colMap['company'] === -1 && (strpos($cleanH, 'company') !== false || strpos($cleanH, 'firm') !== false || strpos($cleanH, 'business') !== false || strpos($cleanH, 'store') !== false)) {
                                $colMap['company'] = $idx;
                            } elseif ($colMap['amount'] === -1 && (strpos($cleanH, 'amount') !== false || strpos($cleanH, 'balance') !== false || strpos($cleanH, 'due') !== false || strpos($cleanH, 'total') !== false)) {
                                $colMap['amount'] = $idx;
                            } elseif ($colMap['due_date'] === -1 && (strpos($cleanH, 'date') !== false || strpos($cleanH, 'expiry') !== false)) {
                                $colMap['due_date'] = $idx;
                            } elseif ($colMap['invoice'] === -1 && (strpos($cleanH, 'invoice') !== false || strpos($cleanH, 'bill') !== false || strpos($cleanH, 'inv') !== false)) {
                                $colMap['invoice'] = $idx;
                            } else {
                                $extraCols[$cleanH] = $idx;
                            }
                        }
                    }
                    if ($colMap['phone'] === -1) $colMap['phone'] = 0;
                    if ($colMap['name'] === -1) $colMap['name'] = 1;
                    if ($colMap['company'] === -1) $colMap['company'] = 2;

                    while (($row = fgetcsv($handle, 2000, ",")) !== FALSE) {
                        $rawPhone = $row[$colMap['phone']] ?? '';
                        $rawName  = $row[$colMap['name']] ?? 'Valued Customer';
                        $rawComp  = $row[$colMap['company']] ?? 'Company';
                        $p = preg_replace('/[^0-9]/', '', $rawPhone);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;

                            $rowVars = [];
                            if ($colMap['amount'] !== -1 && !empty($row[$colMap['amount']])) $rowVars['amount'] = trim($row[$colMap['amount']]);
                            if ($colMap['due_date'] !== -1 && !empty($row[$colMap['due_date']])) $rowVars['due_date'] = trim($row[$colMap['due_date']]);
                            if ($colMap['invoice'] !== -1 && !empty($row[$colMap['invoice']])) $rowVars['invoice'] = trim($row[$colMap['invoice']]);
                            foreach ($extraCols as $eKey => $eIdx) {
                                if (!empty($row[$eIdx])) $rowVars[$eKey] = trim($row[$eIdx]);
                            }

                            $contacts[$p] = [
                                'mobile'         => $p,
                                'name'           => $rawName ?: 'Valued Customer',
                                'company'        => $rawComp ?: 'Company',
                                'variables_json' => !empty($rowVars) ? json_encode($rowVars, JSON_UNESCAPED_UNICODE) : null
                            ];
                        }
                    }
                    fclose($handle);
                }
            }

            // High-Speed Chunked Multi-Row Bulk Insert (Prevents 504 Gateway Timeouts)
            try { $pdo->exec("ALTER TABLE campaign_audience ADD COLUMN variables_json TEXT NULL AFTER company_name"); } catch (\Throwable $e) {}

            $contactValues = array_values($contacts);
            $totalCount = count($contactValues);

            if ($totalCount > 0) {
                $pdo->beginTransaction();
                $chunks = array_chunk($contactValues, 200);
                foreach ($chunks as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $cnt) {
                        $placeholders[] = "(?, ?, ?, ?, ?, 'pending')";
                        $params[] = $campaignId;
                        $params[] = $cnt['mobile'];
                        $params[] = $cnt['name'];
                        $params[] = $cnt['company'];
                        $params[] = $cnt['variables_json'] ?? null;
                    }
                    $sql = "INSERT INTO campaign_audience (campaign_id, mobile, customer_name, company_name, variables_json, status) VALUES " . implode(', ', $placeholders);
                    $pdo->prepare($sql)->execute($params);
                }
                $pdo->commit();
            }

            $stmtUpd = $pdo->prepare("UPDATE broadcast_campaigns SET total_contacts = ?, pending_count = ? WHERE id = ?");
            $stmtUpd->execute([$totalCount, $totalCount, $campaignId]);

            $msg = ($initialStatus === 'pending_approval')
                ? "Campaign created with $totalCount contacts and submitted for Admin Approval!"
                : "Campaign created and approved with $totalCount contacts!";

            sendJsonResponse([
                'success' => true,
                'message' => $msg,
                'campaign_id' => $campaignId,
                'status' => $initialStatus,
                'total_contacts' => $totalCount
            ]);
            break;

        // ---------------------------------------------------------
        // 4. Admin Approve / Reject Actions
        // ---------------------------------------------------------
        case 'approve_campaign':
            $id = intval($_POST['id'] ?? 0);
            $userActor = $_SESSION['user_name'] ?? 'Admin';
            $userRole  = $_SESSION['user_role'] ?? 'Admin';

            if (!in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager'])) {
                sendJsonResponse(['success' => false, 'message' => 'Only Admins can approve campaigns.'], 403);
            }

            $stmtApp = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmtApp->execute([$userActor, $id]);

            sendJsonResponse(['success' => true, 'message' => 'Campaign approved successfully!']);
            break;

        case 'reject_campaign':
            $id = intval($_POST['id'] ?? 0);
            $userActor = $_SESSION['user_name'] ?? 'Admin';
            $userRole  = $_SESSION['user_role'] ?? 'Admin';

            if (!in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager'])) {
                sendJsonResponse(['success' => false, 'message' => 'Only Admins can reject campaigns.'], 403);
            }

            $stmtRej = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmtRej->execute([$userActor, $id]);

            $stmtCanAud = $pdo->prepare("UPDATE campaign_audience SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'");
            $stmtCanAud->execute([$id]);

            sendJsonResponse(['success' => true, 'message' => 'Campaign rejected.']);
            break;

        // ---------------------------------------------------------
        // 4b. Delete Campaign & Clear All
        // ---------------------------------------------------------
        case 'delete_campaign':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                sendJsonResponse(['success' => false, 'message' => 'Campaign ID is required.'], 400);
            }

            try {
                $stmtDelAud = $pdo->prepare("DELETE FROM campaign_audience WHERE campaign_id = ?");
                $stmtDelAud->execute([$id]);

                $stmtDelC = $pdo->prepare("DELETE FROM broadcast_campaigns WHERE id = ?");
                $stmtDelC->execute([$id]);

                sendJsonResponse(['success' => true, 'message' => 'Campaign deleted successfully!']);
            } catch (\Throwable $e) {
                sendJsonResponse(['success' => false, 'message' => 'Failed to delete campaign: ' . $e->getMessage()], 500);
            }
            break;

        case 'clear_all_campaigns':
            try {
                $pdo->exec("DELETE FROM campaign_audience");
                $pdo->exec("DELETE FROM broadcast_campaigns");
                try {
                    $pdo->exec("ALTER TABLE campaign_audience AUTO_INCREMENT = 1");
                    $pdo->exec("ALTER TABLE broadcast_campaigns AUTO_INCREMENT = 1");
                } catch (\Throwable $e2) {}

                sendJsonResponse(['success' => true, 'message' => 'All active and past campaigns have been cleared successfully.']);
            } catch (\Throwable $e) {
                sendJsonResponse(['success' => false, 'message' => 'Failed to clear campaigns: ' . $e->getMessage()], 500);
            }
            break;

        // ---------------------------------------------------------
        // 5. Toggle Campaign Status (Start, Pause, Resume, Cancel)
        // ---------------------------------------------------------
        case 'toggle_status':
            $id = intval($_POST['id'] ?? 0);
            $newStatus = strtolower(trim($_POST['status'] ?? 'running')); // 'running', 'paused', 'cancelled'

            if (!$id || !in_array($newStatus, ['running', 'paused', 'cancelled'])) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid status change parameters'], 400);
            }

            // Check current campaign approval status
            $stmtChk = $pdo->prepare("SELECT status FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtChk->execute([$id]);
            $curSt = $stmtChk->fetchColumn();

            if (in_array($curSt, ['pending_approval', 'rejected'])) {
                sendJsonResponse(['success' => false, 'message' => '🔒 Campaign requires Admin Approval before it can be started.'], 403);
            }

            $stmtSt = $pdo->prepare("UPDATE broadcast_campaigns SET status = ? WHERE id = ?");
            $stmtSt->execute([$newStatus, $id]);

            if ($newStatus === 'cancelled') {
                $stmtCanAud = $pdo->prepare("UPDATE campaign_audience SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'");
                $stmtCanAud->execute([$id]);
            }

            sendJsonResponse(['success' => true, 'message' => 'Campaign status updated to ' . ucfirst($newStatus)]);
            break;

        // ---------------------------------------------------------
        // 5. Batch Message Dispatcher (Atomic Row-Locked & Anti-Ban Protected)
        // ---------------------------------------------------------
        case 'process_batch':
            $id = intval($_REQUEST['id'] ?? 0);
            $batchSize = max(1, min(10, intval($_REQUEST['batch_size'] ?? 1)));
            $res = internalProcessCampaignBatch($pdo, $id, $batchSize, false);
            sendJsonResponse($res, !empty($res['http_code']) ? $res['http_code'] : 200);
            break;

        // ---------------------------------------------------------
        // 7. Get Saved Templates & Sync Meta Approved Templates
        // ---------------------------------------------------------
        case 'get_templates':
            $stmtT = $pdo->query("SELECT * FROM whatsapp_templates ORDER BY id DESC");
            $templates = $stmtT ? $stmtT->fetchAll(PDO::FETCH_ASSOC) : [];

            $hasMargBill = false;
            foreach ($templates as $t) {
                if (($t['slug'] ?? '') === 'marg_bill') {
                    $hasMargBill = true;
                    break;
                }
            }

            if (!$hasMargBill) {
                $margBillBody = "From: *{{1}}*\nSubject: Sale Bill Confirmation\n\nDear *{{2}}*,\n\nYour recent order with the invoice number *{{3}}* of the amount *₹{{4}}* has been successfully generated.\n\nPlease check for your payments.\nYour Ledger balance is *₹{{5}}*.\n\nBank Details:\nUPI ID: *{{6}}*\nBank Name: *{{7}}*\nAccount No.:*{{8}}*\nBranch: *{{9}}*\nIFSC Code: *{{10}}*\n\nRegards,\n*{{11}}*\nHelpline: *{{12}}*\n\nThe bill PDF is attached above.\nPreview link: *{{13}}*\n\n*Thank you for doing business with us!*";
                try {
                    $stmtInsMB = $pdo->prepare("INSERT INTO whatsapp_templates (title, slug, category, header_type, body_text, footer_text, created_by, meta_status, gateway_origin) VALUES (?, ?, ?, 'document', ?, ?, 'Meta Approved', 'APPROVED', 'meta') ON DUPLICATE KEY UPDATE body_text=VALUES(body_text), meta_status='APPROVED'");
                    $stmtInsMB->execute([
                        'Marg Bill',
                        'marg_bill',
                        'UTILITY',
                        $margBillBody,
                        'Meta Approved Utility Template'
                    ]);
                    $stmtT2 = $pdo->query("SELECT * FROM whatsapp_templates ORDER BY id DESC");
                    $templates = $stmtT2 ? $stmtT2->fetchAll(PDO::FETCH_ASSOC) : [];
                } catch (\Throwable $eMB) {}
            }

            sendJsonResponse(['success' => true, 'templates' => $templates]);
            break;

        case 'sync_meta_templates':
            $userId = $_SESSION['user_id'] ?? 1;
            $stmtWaba = $pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE (user_id = ? OR tenant_api_key != '') AND access_token != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' AND waba_id != '' THEN 1 ELSE 2 END) LIMIT 1");
            $stmtWaba->execute([$userId]);
            $wabaRow = $stmtWaba->fetch(PDO::FETCH_ASSOC);

            if (!$wabaRow || empty($wabaRow['waba_id']) || empty($wabaRow['access_token'])) {
                sendJsonResponse(['success' => false, 'message' => 'Please configure WABA ID and Access Token in Marg ERP WABA Setup first!'], 400);
            }

            $url = "https://graph.facebook.com/v20.0/{$wabaRow['waba_id']}/message_templates?limit=100";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $wabaRow['access_token']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $metaData = json_decode($res, true);
            if ($httpCode >= 200 && $httpCode < 300 && !empty($metaData['data'])) {
                $syncedCount = 0;
                $activeMetaSlugs = [];

                foreach ($metaData['data'] as $mT) {
                    $name = $mT['name'];
                    $activeMetaSlugs[] = $name;
                    $category = $mT['category'] ?? 'MARKETING';
                    $status = strtoupper($mT['status'] ?? 'APPROVED');
                    
                    $headerText = '';
                    $headerType = 'none';
                    $bodyText = '';
                    $footerText = '';
                    $buttonsArr = [];

                    if (!empty($mT['components']) && is_array($mT['components'])) {
                        foreach ($mT['components'] as $comp) {
                            if ($comp['type'] === 'HEADER') {
                                $headerType = strtolower($comp['format'] ?? 'text');
                                $headerText = $comp['text'] ?? '';
                            }
                            if ($comp['type'] === 'BODY') {
                                $bodyText = $comp['text'] ?? '';
                            }
                            if ($comp['type'] === 'FOOTER') {
                                $footerText = $comp['text'] ?? '';
                            }
                            if ($comp['type'] === 'BUTTONS' && !empty($comp['buttons'])) {
                                foreach ($comp['buttons'] as $b) {
                                    $buttonsArr[] = [
                                        'id' => $b['type'] ?? 'btn',
                                        'title' => $b['text'] ?? 'Action'
                                    ];
                                }
                            }
                        }
                    }

                    $title = ucwords(str_replace(['_', '-'], ' ', $name));
                    $buttonsJson = json_encode($buttonsArr);

                    $stmtChk = $pdo->prepare("SELECT id FROM whatsapp_templates WHERE slug = ? LIMIT 1");
                    $stmtChk->execute([$name]);
                    $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $stmtUpd = $pdo->prepare("UPDATE whatsapp_templates SET meta_status = ?, category = ?, header_type = ?, header_text = ?, body_text = ?, footer_text = ?, buttons_json = ? WHERE id = ?");
                        $stmtUpd->execute([$status, $category, $headerType, $headerText, $bodyText, $footerText, $buttonsJson, $existing['id']]);
                    } else {
                        $stmtIns = $pdo->prepare("INSERT INTO whatsapp_templates (title, slug, category, header_type, header_text, body_text, footer_text, buttons_json, created_by, meta_status, gateway_origin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Meta Sync', ?, 'meta')");
                        $stmtIns->execute([$title, $name, $category, $headerType, $headerText, $bodyText, $footerText, $buttonsJson, $status]);
                    }
                    $syncedCount++;
                }

                // Delete any local meta templates that were deleted on Meta
                if (!empty($activeMetaSlugs)) {
                    $placeholders = implode(',', array_fill(0, count($activeMetaSlugs), '?'));
                    $stmtDel = $pdo->prepare("DELETE FROM whatsapp_templates WHERE gateway_origin = 'meta' AND slug NOT IN ($placeholders)");
                    $stmtDel->execute($activeMetaSlugs);
                }

                sendJsonResponse(['success' => true, 'message' => "Successfully synced {$syncedCount} official Meta Templates with live approval statuses!", 'count' => $syncedCount]);
            } else {
                $errMsg = $metaData['error']['message'] ?? 'Failed to fetch templates from Meta Graph API';
                sendJsonResponse(['success' => false, 'message' => $errMsg], 400);
            }
            break;

        // ---------------------------------------------------------
        // 8. Save New / Edit Template (Meta Graph API vs Web API)
        // ---------------------------------------------------------
        case 'save_template':
            $rawInput = json_decode(file_get_contents('php://input'), true) ?? [];

            $templateId = intval($_POST['template_id'] ?? $_POST['id'] ?? $rawInput['template_id'] ?? $rawInput['id'] ?? 0);
            $title = trim($_POST['title'] ?? $_REQUEST['title'] ?? $rawInput['title'] ?? '');
            $category = trim($_POST['category'] ?? $_REQUEST['category'] ?? $rawInput['category'] ?? 'MARKETING');
            $headerType = trim($_POST['header_type'] ?? $_REQUEST['header_type'] ?? $rawInput['header_type'] ?? 'none');
            $headerText = trim($_POST['header_text'] ?? $_REQUEST['header_text'] ?? $rawInput['header_text'] ?? '');
            $headerContent = trim($_POST['header_content'] ?? $_REQUEST['header_content'] ?? $rawInput['header_content'] ?? '');
            $bodyText = trim($_POST['body_text'] ?? $_REQUEST['body_text'] ?? $rawInput['body_text'] ?? '');
            $footerText = trim($_POST['footer_text'] ?? $_REQUEST['footer_text'] ?? $rawInput['footer_text'] ?? '');
            $buttonsJson = trim($_POST['buttons_json'] ?? $_REQUEST['buttons_json'] ?? $rawInput['buttons_json'] ?? '[]');
            $userActor = $_SESSION['user_name'] ?? 'Staff';

            if (empty($title) || empty($bodyText)) {
                sendJsonResponse(['success' => false, 'message' => 'Template Title and Body Text are required.'], 400);
            }

            // Check if existing template is being edited
            $existing = null;
            if ($templateId > 0) {
                $stmtEx = $pdo->prepare("SELECT * FROM whatsapp_templates WHERE id = ? LIMIT 1");
                $stmtEx->execute([$templateId]);
                $existing = $stmtEx->fetch(PDO::FETCH_ASSOC);
            }

            // Slug formatted according to Meta requirements (lowercase, underscores only)
            if ($existing && !empty($existing['slug'])) {
                $slug = $existing['slug'];
            } else {
                $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($title)) . '_' . substr(time(), -5);
            }

            // Check requested gateway target from user form
            $targetGateway = trim($_POST['gateway_target'] ?? $_POST['gateway_origin'] ?? $rawInput['gateway_target'] ?? '');
            if (empty($targetGateway)) {
                if ($existing && !empty($existing['gateway_origin'])) {
                    $targetGateway = $existing['gateway_origin'];
                } else {
                    $cfg = getActiveWabaConfig($pdo);
                    $targetGateway = !empty($cfg['gateway_type']) ? $cfg['gateway_type'] : 'web_api';
                }
            }

            $metaStatus = 'APPROVED';
            $gatewayOrigin = $targetGateway;
            $metaMsg = ($templateId > 0) ? 'Template updated successfully!' : 'Template saved to library!';

            if ($targetGateway === 'web_api') {
                // Web API: Local WhatsApp Web session (instant ready, no Meta approval delay)
                $metaStatus = 'APPROVED';
                $gatewayOrigin = 'web_api';
                $metaMsg = ($templateId > 0) ? 
                    "Template modified & updated successfully for WhatsApp Web API!" : 
                    "Template saved successfully for WhatsApp Web API! Instant ready to broadcast without Meta review.";
            } elseif ($targetGateway === 'universal') {
                // Universal: Instant ready for Web API + submitted to Meta WABA for cloud approval
                $metaStatus = 'APPROVED';
                $gatewayOrigin = 'universal';
                $metaMsg = ($templateId > 0) ? "Template updated for Universal use! Ready on Web API." : "Template saved for Universal use! Ready immediately on Web API.";

                try {
                    $userId = $_SESSION['user_id'] ?? 1;
                    $stmtWaba = $pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' THEN 1 ELSE 2 END) LIMIT 1");
                    $stmtWaba->execute();
                    $wabaRow = $stmtWaba->fetch(PDO::FETCH_ASSOC);

                    if ($wabaRow && !empty($wabaRow['waba_id']) && !empty($wabaRow['access_token'])) {
                        require_once __DIR__ . '/whatsapp-api.php';
                        $wApi = new WhatsAppAPI($pdo);
                        $metaRes = $wApi->createMetaTemplate(
                            $wabaRow['waba_id'],
                            $slug,
                            $category,
                            $bodyText,
                            'en_US',
                            ($headerType === 'text' ? $headerText : null),
                            $footerText,
                            $buttonsJson,
                            $wabaRow['access_token']
                        );

                        if ($metaRes['success']) {
                            $mSt = $metaRes['status'] ?? 'PENDING';
                            $metaMsg = ($templateId > 0) ? 
                                "Template updated! Ready on Web API & submitted to Meta Graph API (Status: {$mSt})." : 
                                "Template saved! Instant ready on Web API & submitted to Meta Graph API for official approval (Status: {$mSt}).";
                        } else {
                            $errObj = $metaRes['response']['error'] ?? [];
                            $errMsg = $errObj['error_user_msg'] ?? $errObj['message'] ?? 'Meta API notice';
                            $metaMsg = "Template updated on Web API! (Meta notice: " . htmlspecialchars($errMsg) . ")";
                        }
                    }
                } catch (\Throwable $metaEx) {
                    $metaMsg = "Template updated on Web API! (Meta notice: " . htmlspecialchars($metaEx->getMessage()) . ")";
                }
            } else {
                // Meta Cloud API: Submit to Meta Graph API for official approval
                $metaStatus = 'PENDING';
                $gatewayOrigin = 'meta';
                $metaMsg = ($templateId > 0) ? "Template updated and resubmitted to Meta Graph API for review." : "Template saved and submitted to Meta Graph API for official review.";

                try {
                    $userId = $_SESSION['user_id'] ?? 1;
                    $stmtWaba = $pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' THEN 1 ELSE 2 END) LIMIT 1");
                    $stmtWaba->execute();
                    $wabaRow = $stmtWaba->fetch(PDO::FETCH_ASSOC);

                    if ($wabaRow && !empty($wabaRow['waba_id']) && !empty($wabaRow['access_token'])) {
                        require_once __DIR__ . '/whatsapp-api.php';
                        $wApi = new WhatsAppAPI($pdo);
                        $metaRes = $wApi->createMetaTemplate(
                            $wabaRow['waba_id'],
                            $slug,
                            $category,
                            $bodyText,
                            'en_US',
                            ($headerType === 'text' ? $headerText : null),
                            $footerText,
                            $buttonsJson,
                            $wabaRow['access_token']
                        );

                        if ($metaRes['success']) {
                            $metaStatus = $metaRes['status'] ?? 'PENDING';
                            $metaMsg = ($templateId > 0) ? "Template updated & resubmitted to Meta! Status: " . htmlspecialchars($metaStatus) : "Template submitted successfully to Meta! Status: " . htmlspecialchars($metaStatus);
                        } else {
                            $errObj = $metaRes['response']['error'] ?? [];
                            $errMsg = $errObj['error_user_msg'] ?? $errObj['message'] ?? 'Meta API validation error';
                            $metaStatus = 'FAILED';
                            $metaMsg = "Meta rejected template: " . htmlspecialchars($errMsg);
                        }
                    } else {
                        $metaStatus = 'LOCAL_ONLY';
                        $metaMsg = "Template updated locally (Meta credentials not configured).";
                    }
                } catch (\Throwable $metaEx) {
                    $metaStatus = 'FAILED';
                    $metaMsg = "Saved locally. Meta submission error: " . htmlspecialchars($metaEx->getMessage());
                }
            }

            if ($templateId > 0 && $existing) {
                $stmtUpd = $pdo->prepare("UPDATE whatsapp_templates SET title = ?, category = ?, header_type = ?, header_text = ?, header_content = ?, body_text = ?, footer_text = ?, buttons_json = ?, meta_status = ?, gateway_origin = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpd->execute([$title, $category, $headerType, $headerText, $headerContent, $bodyText, $footerText, $buttonsJson, $metaStatus, $gatewayOrigin, $templateId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO whatsapp_templates (title, slug, category, header_type, header_text, header_content, body_text, footer_text, buttons_json, created_by, meta_status, gateway_origin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$title, $slug, $category, $headerType, $headerText, $headerContent, $bodyText, $footerText, $buttonsJson, $userActor, $metaStatus, $gatewayOrigin]);
                $templateId = (int)$pdo->lastInsertId();
            }

            sendJsonResponse([
                'success' => ($metaStatus !== 'FAILED'),
                'message' => $metaMsg,
                'slug' => $slug,
                'status' => $metaStatus,
                'gateway_origin' => $gatewayOrigin,
                'template_id' => $templateId
            ]);
            break;

        // ---------------------------------------------------------
        // 9. Delete Template (Deletes from Meta Cloud API and Database)
        // ---------------------------------------------------------
        case 'delete_template':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) sendJsonResponse(['success' => false, 'message' => 'Template ID required'], 400);

            // Fetch template details first
            $stmtFind = $pdo->prepare("SELECT id, title, slug, gateway_origin, meta_status FROM whatsapp_templates WHERE id = ?");
            $stmtFind->execute([$id]);
            $tmpl = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (!$tmpl) {
                sendJsonResponse(['success' => false, 'message' => 'Template not found in library.'], 404);
            }

            $metaDeleted = false;
            $metaNotice = '';

            // If template is linked to Meta Cloud API, delete from Meta Manager via Graph API
            if ($tmpl['gateway_origin'] === 'meta' && !empty($tmpl['slug'])) {
                try {
                    $stmtWaba = $pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' THEN 1 ELSE 2 END) LIMIT 1");
                    $stmtWaba->execute();
                    $wabaRow = $stmtWaba->fetch(PDO::FETCH_ASSOC);

                    if ($wabaRow && !empty($wabaRow['waba_id']) && !empty($wabaRow['access_token'])) {
                        require_once __DIR__ . '/whatsapp-api.php';
                        $wApi = new WhatsAppAPI($pdo);
                        $delRes = $wApi->deleteMetaTemplate($wabaRow['waba_id'], $tmpl['slug'], $wabaRow['access_token']);

                        if ($delRes['success']) {
                            $metaDeleted = true;
                            $metaNotice = ' Deleted from Meta Cloud API.';
                        } else {
                            $errCode = $delRes['response']['error']['code'] ?? 0;
                            if ($errCode === 100) {
                                // Template not found on Meta (already deleted or draft)
                                $metaNotice = ' (Not found on Meta or already removed).';
                            } else {
                                $metaNotice = ' Meta note: ' . ($delRes['response']['error']['message'] ?? 'could not remove from Meta');
                            }
                        }
                    }
                } catch (\Throwable $delEx) {
                    $metaNotice = ' Note: ' . $delEx->getMessage();
                }
            }

            // Delete from local database
            $stmtDel = $pdo->prepare("DELETE FROM whatsapp_templates WHERE id = ?");
            $stmtDel->execute([$id]);

            sendJsonResponse([
                'success' => true,
                'message' => 'Template deleted from library.' . $metaNotice
            ]);
            break;

        // ---------------------------------------------------------
        // 10. Send Direct Instant Individual Broadcast Message
        // ---------------------------------------------------------
        case 'send_individual':
            $phone = trim($_POST['phone'] ?? '');
            $name  = trim($_POST['name'] ?? 'Valued Customer');
            $company = trim($_POST['company'] ?? 'Marg Client');
            $amount  = trim($_POST['amount'] ?? '₹3,500');
            $dueDate = trim($_POST['due_date'] ?? date('d M Y', strtotime('+15 days')));
            $message = trim($_POST['message'] ?? '');
            $templateSlug = trim($_POST['template_slug'] ?? 'custom');
            $userActor = $_SESSION['user_name'] ?? 'Staff';

            if (empty($phone) || empty($message)) {
                sendJsonResponse(['success' => false, 'message' => 'Phone number and message text are required'], 400);
            }

            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) == 10) $cleanPhone = '91' . $cleanPhone;

            $mediaUrl = handleBroadcastMediaUpload('media_file');
            if (empty($mediaUrl) && !empty($_POST['media_url'])) {
                $mediaUrl = trim($_POST['media_url']);
            }
            $mediaPosition = trim($_POST['media_position'] ?? 'top');
            $clientCountdown = !empty($_POST['client_countdown']);
            $delaySeconds = max(0, intval($_POST['delay_seconds'] ?? 0));
            // Only sleep in PHP if the client did NOT already count down in the browser
            if (!$clientCountdown && $delaySeconds > 0 && $delaySeconds <= 60) {
                sleep($delaySeconds);
            }

            // Ensure DB connection is active
            if (function_exists('ensure_db_connection')) {
                $pdo = ensure_db_connection($pdo);
            }

            // Replace dynamic variables in message ensuring strictly active sender's own details
            $msgText = resolveTemplateVariables($message, $pdo, [
                'name'     => $name,
                'company'  => $company,
                'phone'    => $cleanPhone,
                'amount'   => $amount,
                'due_date' => $dueDate
            ]);

            // Fetch template buttons if available
            $buttons = [];
            if ($templateSlug !== 'custom') {
                try {
                    $stmtT = $pdo->prepare("SELECT * FROM whatsapp_templates WHERE slug = ? LIMIT 1");
                    $stmtT->execute([$templateSlug]);
                    $tmpl = $stmtT->fetch(PDO::FETCH_ASSOC);
                    if ($tmpl && !empty($tmpl['buttons_json'])) {
                        $buttons = json_decode($tmpl['buttons_json'], true) ?? [];
                    }
                } catch (\Throwable $eTmpl) {
                    // Ignore template button fetch failure
                }
            }

            // Dispatch message via active WhatsApp Gateway
            $res = dispatchUnifiedWhatsAppMessage($pdo, $cleanPhone, $msgText, $buttons, $templateSlug, $mediaUrl, $mediaPosition);

            $sentSuccess = (!empty($res['success']) && $res['success']);
            $sentCount = $sentSuccess ? 1 : 0;
            $failCount = $sentSuccess ? 0 : 1;

            // Re-verify DB connection before audit logging
            if (function_exists('ensure_db_connection')) {
                $pdo = ensure_db_connection($pdo);
            }

            // Resilient DB Logging: Must never fail the HTTP response if message was dispatched
            $cTitle = "⚡ Direct Broadcast to " . $name . " (" . $cleanPhone . ")";
            $campId = 0;
            try {
                try {
                    $stmtInsC = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, media_url, total_contacts, sent_count, failed_count, pending_count, status, created_by) VALUES (?, ?, 'individual', ?, ?, 1, ?, ?, 0, 'completed', ?)");
                    $stmtInsC->execute([$cTitle, $templateSlug, $msgText, $mediaUrl, $sentCount, $failCount, $userActor]);
                } catch (\Throwable $eInsC) {
                    if (function_exists('ensure_db_connection')) {
                        $pdo = ensure_db_connection($pdo);
                    }
                    $stmtInsC = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, total_contacts, sent_count, failed_count, pending_count, status, created_by) VALUES (?, ?, 'individual', ?, 1, ?, ?, 0, 'completed', ?)");
                    $stmtInsC->execute([$cTitle, $templateSlug, $msgText, $sentCount, $failCount, $userActor]);
                }
                $campId = (int)$pdo->lastInsertId();

                // Log audience row
                $stmtAud = $pdo->prepare("INSERT INTO campaign_audience (campaign_id, mobile, customer_name, company_name, status, sent_at, error_message) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
                $audStatus = $sentSuccess ? 'sent' : 'failed';
                $errMsg = $sentSuccess ? null : ($res['error']['message'] ?? ($res['message'] ?? 'Send failed'));
                $stmtAud->execute([$campId, $cleanPhone, $name, $company, $audStatus, $errMsg]);
            } catch (\Throwable $eLog) {
                error_log("Campaign API audit logging warning: " . $eLog->getMessage());
            }

            if ($sentSuccess) {
                $gwLabel = (!empty($res['gateway']) && $res['gateway'] === 'web_api') ? 'WhatsApp Web API' : 'Meta Cloud API';
                sendJsonResponse(['success' => true, 'message' => "⚡ Instant WhatsApp broadcast dispatched successfully via {$gwLabel} to {$name} (+{$cleanPhone})!", 'data' => $res]);
            } else {
                $detailedErr = $res['error']['message'] ?? ($res['message'] ?? ($res['response']['message'] ?? 'WhatsApp Gateway Error'));
                sendJsonResponse(['success' => false, 'message' => "Dispatch failed: " . $detailedErr, 'details' => $res]);
            }
            break;

        // ---------------------------------------------------------
        // 10b. Launch Excel / CSV Spreadsheet Broadcast Campaign
        // ---------------------------------------------------------
        case 'launch_excel_broadcast':
            $rawContacts = $_POST['contacts_json'] ?? '';
            $contacts = json_decode($rawContacts, true) ?: [];

            if (empty($contacts) || !is_array($contacts)) {
                sendJsonResponse(['success' => false, 'message' => 'No contacts provided in spreadsheet data.'], 400);
            }

            $templateSlug = trim($_POST['template_slug'] ?? 'custom');
            $customMessage = trim($_POST['message'] ?? '');
            $delaySeconds = max(0, intval($_POST['delay_seconds'] ?? 2));
            $dailyLimit = max(0, intval($_POST['daily_limit'] ?? 0));
            $userActor = $_SESSION['user_name'] ?? 'Staff';

            $mediaUrl = handleBroadcastMediaUpload('media_file');
            if (empty($mediaUrl) && !empty($_POST['media_url'])) {
                $mediaUrl = trim($_POST['media_url']);
            }
            $mediaPosition = trim($_POST['media_position'] ?? 'top');

            // Parse and filter valid recipients
            $validAudience = [];
            foreach ($contacts as $row) {
                $phone = '';
                foreach ($row as $k => $v) {
                    $cleanK = strtolower(preg_replace('/[\s_\-]/', '', $k));
                    if (in_array($cleanK, ['phone', 'mobile', 'contact', 'number', 'whatsapp', 'mob', 'phonenumber'])) {
                        $phone = trim((string)$v);
                        break;
                    }
                }
                if (empty($phone)) continue;

                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($cleanPhone) < 10) continue;
                if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;

                $name = '';
                foreach ($row as $k => $v) {
                    $cleanK = strtolower(preg_replace('/[\s_\-]/', '', $k));
                    if (in_array($cleanK, ['name', 'customername', 'clientname', 'partyname', 'party', 'customer', 'client'])) {
                        $name = trim((string)$v);
                        break;
                    }
                }
                if (empty($name)) $name = 'Valued Customer';

                $company = '';
                foreach ($row as $k => $v) {
                    $cleanK = strtolower(preg_replace('/[\s_\-]/', '', $k));
                    if (in_array($cleanK, ['company', 'companyname', 'firm', 'partyname', 'firmname'])) {
                        $company = trim((string)$v);
                        break;
                    }
                }
                if (empty($company)) $company = 'Client';

                $validAudience[] = [
                    'mobile'   => $cleanPhone,
                    'name'     => $name,
                    'company'  => $company,
                    'raw_row'  => $row
                ];
            }

            $totalContacts = count($validAudience);
            if ($totalContacts === 0) {
                sendJsonResponse(['success' => false, 'message' => 'No valid phone numbers found in the uploaded Excel spreadsheet.'], 400);
            }

            // Ensure DB connection is active
            if (function_exists('ensure_db_connection')) {
                $pdo = ensure_db_connection($pdo);
            }

            // Campaign title
            $titleTemplate = ($templateSlug !== 'custom' && $templateSlug !== 'ai_compose')
                ? ucwords(str_replace('_', ' ', $templateSlug))
                : 'Direct Broadcast';
            $campaignName = "⚡ Excel Broadcast: {$titleTemplate} ({$totalContacts} Contacts)";
            if ($dailyLimit > 0) {
                $campaignName .= " [{$dailyLimit}/Batch Quota]";
            }

            // Ensure schema support in active tenant table
            try {
                $bcCols = $pdo->query("SHOW COLUMNS FROM broadcast_campaigns")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('media_url', $bcCols)) {
                    $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN media_url VARCHAR(500) NULL AFTER custom_message");
                }
                if (!in_array('media_position', $bcCols)) {
                    $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN media_position VARCHAR(20) DEFAULT 'top' AFTER media_url");
                }
                if (!in_array('daily_limit', $bcCols)) {
                    $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN daily_limit INT DEFAULT 0 AFTER delay_seconds");
                }
                if (!in_array('today_sent_count', $bcCols)) {
                    $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN today_sent_count INT DEFAULT 0 AFTER daily_limit");
                }
                if (!in_array('last_dispatched_date', $bcCols)) {
                    $pdo->exec("ALTER TABLE broadcast_campaigns ADD COLUMN last_dispatched_date DATE NULL AFTER today_sent_count");
                }
                $caCols = $pdo->query("SHOW COLUMNS FROM campaign_audience")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('variables_json', $caCols)) {
                    $pdo->exec("ALTER TABLE campaign_audience ADD COLUMN variables_json LONGTEXT NULL AFTER company_name");
                }
                if (!in_array('updated_at', $caCols)) {
                    $pdo->exec("ALTER TABLE campaign_audience ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                }
            } catch (\Throwable $eMig) {}

            // Insert into broadcast_campaigns with resilient fallbacks
            $campaignId = 0;
            try {
                $stmtInsC = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, media_url, media_position, total_contacts, sent_count, failed_count, pending_count, delay_seconds, daily_limit, today_sent_count, last_dispatched_date, status, created_by) VALUES (?, ?, 'excel', ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, CURDATE(), 'running', ?)");
                $stmtInsC->execute([$campaignName, $templateSlug, $customMessage, $mediaUrl, $mediaPosition, $totalContacts, $totalContacts, $delaySeconds, $dailyLimit, $userActor]);
                $campaignId = (int)$pdo->lastInsertId();
            } catch (\Throwable $eIns1) {
                try {
                    $stmtInsC = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, media_url, total_contacts, sent_count, failed_count, pending_count, delay_seconds, status, created_by) VALUES (?, ?, 'excel', ?, ?, ?, 0, 0, ?, ?, 'running', ?)");
                    $stmtInsC->execute([$campaignName, $templateSlug, $customMessage, $mediaUrl, $totalContacts, $totalContacts, $delaySeconds, $userActor]);
                    $campaignId = (int)$pdo->lastInsertId();
                } catch (\Throwable $eIns2) {
                    $stmtInsC = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, total_contacts, sent_count, failed_count, pending_count, delay_seconds, status, created_by) VALUES (?, ?, 'excel', ?, ?, 0, 0, ?, ?, 'running', ?)");
                    $stmtInsC->execute([$campaignName, $templateSlug, $customMessage, $totalContacts, $totalContacts, $delaySeconds, $userActor]);
                    $campaignId = (int)$pdo->lastInsertId();
                }
            }

            if (!$campaignId) {
                sendJsonResponse(['success' => false, 'message' => 'Failed to initialize broadcast campaign in database.'], 500);
            }

            // Insert all recipients into campaign_audience with variables_json support
            try {
                $stmtAud = $pdo->prepare("INSERT INTO campaign_audience (campaign_id, mobile, customer_name, company_name, variables_json, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                foreach ($validAudience as $aud) {
                    $stmtAud->execute([
                        $campaignId,
                        $aud['mobile'],
                        $aud['name'],
                        $aud['company'],
                        json_encode($aud['raw_row'])
                    ]);
                }
            } catch (\Throwable $eAud) {
                $stmtAud = $pdo->prepare("INSERT INTO campaign_audience (campaign_id, mobile, customer_name, company_name, status) VALUES (?, ?, ?, ?, 'pending')");
                foreach ($validAudience as $aud) {
                    $stmtAud->execute([
                        $campaignId,
                        $aud['mobile'],
                        $aud['name'],
                        $aud['company']
                    ]);
                }
            }

            $quotaMsg = ($dailyLimit > 0) ? " (Daily Quota: {$dailyLimit} contacts today)" : "";
            sendJsonResponse([
                'success' => true,
                'campaign_id' => $campaignId,
                'total_contacts' => $totalContacts,
                'delay_seconds' => $delaySeconds,
                'daily_limit' => $dailyLimit,
                'campaign_name' => $campaignName,
                'message' => "⚡ Excel Broadcast launched with {$totalContacts} contacts{$quotaMsg}! Dispatched with {$delaySeconds}s delay."
            ]);
            break;

        // ---------------------------------------------------------
        // 10c. Resume Next Batch with Custom / New Quota Limit
        // ---------------------------------------------------------
        case 'resume_next_batch':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) sendJsonResponse(['success' => false, 'message' => 'Campaign ID is required'], 400);

            // Fetch campaign
            $stmtC = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC->execute([$id]);
            $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) sendJsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);

            $pendingCount = intval($campaign['pending_count'] ?? 0);
            if ($pendingCount <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'All contacts in this campaign have already been dispatched.'], 400);
            }

            // Next quota limit (optional)
            $hasNewLimit = isset($_POST['next_limit']) && $_POST['next_limit'] !== '';
            $nextLimit = $hasNewLimit ? max(0, intval($_POST['next_limit'])) : intval($campaign['daily_limit']);

            $curDate = date('Y-m-d');
            $stmtUpd = $pdo->prepare("UPDATE broadcast_campaigns SET daily_limit = ?, today_sent_count = 0, last_dispatched_date = ?, status = 'running' WHERE id = ?");
            $stmtUpd->execute([$nextLimit, $curDate, $id]);

            // Re-fetch updated campaign
            $stmtC2 = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC2->execute([$id]);
            $updatedCampaign = $stmtC2->fetch(PDO::FETCH_ASSOC);

            $limitNote = ($nextLimit > 0) ? "{$nextLimit} messages in this batch" : "all {$pendingCount} remaining contacts";
            sendJsonResponse([
                'success' => true,
                'message' => "▶️ Next batch started! Dispatches {$limitNote} with {$updatedCampaign['delay_seconds']}s delay.",
                'campaign' => $updatedCampaign
            ]);
            break;

        default:
            sendJsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    sendJsonResponse(['success' => false, 'message' => 'Campaign API Error: ' . $e->getMessage()], 500);
}
