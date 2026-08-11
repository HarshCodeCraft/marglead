<?php
/**
 * Marg CRM - WhatsApp Cloud API Wrapper Class
 * 
 * Production-ready cURL implementation for Meta Graph API v20.0+.
 * Supports Text, Interactive Reply Buttons, WhatsApp Flows, Media, Templates, and Read Receipts.
 */

require_once __DIR__ . '/helpers.php';

class WhatsAppAPI {
    private string $phoneNumberId;
    private string $accessToken;
    private string $graphVersion;
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->phoneNumberId = PHONE_NUMBER_ID;
        $this->accessToken   = ACCESS_TOKEN;
        $this->graphVersion  = GRAPH_API_VERSION;
        $this->pdo           = $pdo;
    }

    /**
     * Send plain text message.
     */
    public function sendText(string $to, string $message): array {
        $to = format_phone_number($to);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'text',
            'text'               => [
                'preview_url' => false,
                'body'        => $message
            ]
        ];

        return $this->executeCurl($payload, 'text', $message);
    }

    /**
     * Send Interactive Reply Buttons (Up to 3 buttons).
     * 
     * $buttons format: [
     *     ['id' => 'btn_sales', 'title' => 'Sales'],
     *     ['id' => 'btn_support', 'title' => 'Support']
     * ]
     */
    public function sendReplyButtons(string $to, string $bodyText, array $buttons, ?string $headerText = null, ?string $footerText = null, ?string $headerImageUrl = null): array {
        $to = format_phone_number($to);
        $formattedButtons = [];

        foreach ($buttons as $btn) {
            $formattedButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id'    => $btn['id'],
                    'title' => substr($btn['title'], 0, 20) // Meta 20 char limit on button titles
                ]
            ];
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => $bodyText],
            'action' => ['buttons' => $formattedButtons]
        ];

        if (!empty($headerImageUrl)) {
            $interactive['header'] = [
                'type'  => 'image',
                'image' => ['link' => $headerImageUrl]
            ];
        } elseif (!empty($headerText)) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $headerText
            ];
        }

        if (!empty($footerText)) {
            $interactive['footer'] = ['text' => $footerText];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'interactive',
            'interactive'        => $interactive
        ];

        return $this->executeCurl($payload, 'reply_buttons', $bodyText);
    }

    /**
     * Send WhatsApp Flow Message.
     */
    public function sendFlow(string $to, string $flowId, string $ctaText, string $bodyText, string $screen = 'screen_1', ?array $dataPayload = null, ?string $headerText = null, ?string $footerText = null): array {
        $to = format_phone_number($to);

        $flowActionPayload = [
            'screen' => $screen
        ];
        if (!empty($dataPayload)) {
            $flowActionPayload['data'] = (object)$dataPayload;
        }

        $interactive = [
            'type' => 'flow',
            'body' => ['text' => $bodyText],
            'action' => [
                'name' => 'flow',
                'parameters' => [
                    'flow_message_version' => '3',
                    'flow_token'           => 'flow_token_' . time() . '_' . rand(1000, 9999),
                    'flow_id'              => $flowId,
                    'flow_cta'             => $ctaText,
                    'flow_action'          => 'navigate',
                    'flow_action_payload'  => $flowActionPayload,
                    'mode'                 => 'draft'
                ]
            ]
        ];

        if (!empty($headerText)) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $headerText
            ];
        }

        if (!empty($footerText)) {
            $interactive['footer'] = ['text' => $footerText];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'interactive',
            'interactive'        => $interactive
        ];

        return $this->executeCurl($payload, 'flow_message', "Flow ID: $flowId | CTA: $ctaText");
    }

    /**
     * Send Generic Interactive Payload.
     */
    public function sendInteractive(string $to, array $interactivePayload): array {
        $to = format_phone_number($to);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'interactive',
            'interactive'        => $interactivePayload
        ];

        return $this->executeCurl($payload, 'interactive', json_encode($interactivePayload));
    }

    /**
     * Send WhatsApp Message Template.
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode = 'en_US', array $components = []): array {
        $to = format_phone_number($to);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'template',
            'template'           => [
                'name'       => $templateName,
                'language'   => ['code' => $languageCode],
                'components' => $components
            ]
        ];

        return $this->executeCurl($payload, 'template', "Template: $templateName");
    }

    /**
     * Send Image message.
     */
    public function sendImage(string $to, string $imageUrl, ?string $caption = null): array {
        $to = format_phone_number($to);
        $imageObj = ['link' => $imageUrl];
        if (!empty($caption)) {
            $imageObj['caption'] = $caption;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'image',
            'image'              => $imageObj
        ];

        return $this->executeCurl($payload, 'image', $caption ?: $imageUrl);
    }

    /**
     * Send Document message.
     */
    public function sendDocument(string $to, string $documentUrl, ?string $caption = null, ?string $filename = null): array {
        $to = format_phone_number($to);
        $docObj = ['link' => $documentUrl];
        if (!empty($caption)) {
            $docObj['caption'] = $caption;
        }
        if (!empty($filename)) {
            $docObj['filename'] = $filename;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                 => $to,
            'type'               => 'document',
            'document'           => $docObj
        ];

        return $this->executeCurl($payload, 'document', $filename ?: $documentUrl);
    }

    /**
     * Mark message as read (send blue double tick).
     */
    public function markAsRead(string $messageId): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $messageId
        ];

        return $this->executeCurl($payload, 'mark_as_read', "Marking WAMID: $messageId");
    }

    /**
     * Download Media from Meta Servers using Media ID.
     */
    public function downloadMedia(string $mediaId, string $savePath): bool {
        try {
            // Step 1: Retrieve Media URL from Meta
            $getUrl = "https://graph.facebook.com/{$this->graphVersion}/{$mediaId}";
            $ch = curl_init($getUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$this->accessToken}"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $metaData = json_decode($response, true);
            if (empty($metaData['url'])) {
                write_log('error', "Failed fetching media URL for ID: $mediaId", $response);
                return false;
            }

            // Step 2: Download binary content from Media URL
            $mediaUrl = $metaData['url'];
            $fp = fopen($savePath, 'w+');
            $ch = curl_init($mediaUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$this->accessToken}",
                "User-Agent: MargCRM-WhatsAppBot/1.0"
            ]);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);

            return file_exists($savePath) && filesize($savePath) > 0;
        } catch (Throwable $e) {
            write_log('error', "Download media exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload/Register Business RSA Public Key with Meta Graph API for WhatsApp Flows.
     * Endpoint: POST /{PHONE_NUMBER_ID}/whatsapp_business_encryption
     */
    public function registerPublicKey(string $publicKeyPem): array {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/whatsapp_business_encryption";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'business_public_key' => $publicKeyPem
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/x-www-form-urlencoded"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true) ?? [];
        write_log('api', "Register Public Key POST $url | HTTP $httpCode", $data);

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response'  => $data
        ];
    }

    /**
     * Verify GET Request from Meta Webhook configuration.
     */
    public static function verifyWebhook(array $getParams, string $expectedToken): ?string {
        $mode      = $getParams['hub_mode'] ?? $getParams['hub.mode'] ?? null;
        $token     = $getParams['hub_verify_token'] ?? $getParams['hub.verify_token'] ?? null;
        $challenge = $getParams['hub_challenge'] ?? $getParams['hub.challenge'] ?? null;

        if ($mode === 'subscribe' && !empty($token) && hash_equals($expectedToken, $token)) {
            return $challenge;
        }
        return null;
    }

    /**
     * Core cURL Executor with detailed Logging & DB persistence.
     */
    private function executeCurl(array $payload, string $msgType, string $msgSummary): array {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages";
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP SSL bypass if needed

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $resData = json_decode($response, true) ?? [];

        // Log API Call in File
        write_log('api', "POST $url | HTTP $httpCode ({$durationMs}ms)", [
            'payload'  => $payload,
            'response' => $resData,
            'error'    => $curlError
        ]);

        // Log in DB api_logs
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO api_logs (endpoint, http_code, request_data, response_data, duration_ms) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$url, $httpCode, $jsonPayload, $response, $durationMs]);
            } catch (Throwable $e) {
                // Ignore DB log error to avoid breaking API response
            }
        }

        // Log in DB message_logs
        if ($this->pdo) {
            try {
                $wamid = $resData['messages'][0]['id'] ?? null;
                $recipient = $payload['to'] ?? '';
                $stmtMsg = $this->pdo->prepare("INSERT INTO message_logs (direction, recipient_or_sender, message_type, message_body, wamid, status, raw_json) VALUES ('OUTBOUND', ?, ?, ?, ?, ?, ?)");
                $stmtMsg->execute([
                    $recipient,
                    $msgType,
                    $msgSummary,
                    $wamid,
                    ($httpCode >= 200 && $httpCode < 300) ? 'sent' : 'failed',
                    $response
                ]);
            } catch (Throwable $e) {
                // Ignore DB log error
            }
        }

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response'  => $resData,
            'error'     => $curlError
        ];
    }
}
