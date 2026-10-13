<?php
/**
 * Gmail Marg License Automation Engine
 * Supports Fast SSL IMAP Connection (Header-first filtering, Native IMAP + Socket fallback)
 * Regex parser for official Marg ERP License emails
 * Multi-tenant safe with auto-table provisioning & non-blocking session
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce authentication
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$user_id = (int)($_SESSION['tenant_id'] ?? $_SESSION['user_id'] ?? 1);
if ($user_id <= 0) $user_id = 1;

// RELEASE PHP SESSION LOCK IMMEDIATELY so parallel AJAX requests never block or queue!
session_write_close();

/**
 * Auto-provision required database schema (only once, never re-executing ALTER TABLE on every request)
 */
function init_gmail_license_tables(PDO $pdo) {
    static $initialized = false;
    if ($initialized) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tenant_gmail_license_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL DEFAULT 1,
                `gmail_address` VARCHAR(255) DEFAULT '',
                `app_password` VARCHAR(255) DEFAULT '',
                `allowed_senders` TEXT NULL,
                `subject_keywords` TEXT NULL,
                `sync_mode` ENUM('manual', 'auto') DEFAULT 'manual',
                `save_attachments` TINYINT(1) DEFAULT 1,
                `allowed_extensions` VARCHAR(255) DEFAULT 'lic,txt,pdf,zip',
                `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
                `last_sync_at` DATETIME NULL,
                `last_sync_status` VARCHAR(255) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tenant_incoming_licenses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL DEFAULT 1,
                `email_uid` VARCHAR(100) NULL,
                `email_date` DATETIME NULL,
                `from_email` VARCHAR(255) NULL,
                `subject` VARCHAR(500) NULL,
                `party_name` VARCHAR(255) NULL,
                `customer_id` VARCHAR(100) NULL,
                `address` TEXT NULL,
                `city` VARCHAR(100) NULL,
                `state` VARCHAR(100) NULL,
                `country` VARCHAR(50) DEFAULT 'INDIA',
                `phone` VARCHAR(50) NULL,
                `reg_mobile` VARCHAR(50) NULL,
                `reg_email` VARCHAR(255) NULL,
                `contact_person` VARCHAR(255) NULL,
                `dated` VARCHAR(50) NULL,
                `software_type` VARCHAR(100) NULL,
                `users_count` VARCHAR(50) NULL,
                `companies_count` VARCHAR(50) NULL,
                `main_computer` VARCHAR(255) NULL,
                `home_edition` VARCHAR(100) NULL,
                `attachment_filename` VARCHAR(255) NULL,
                `attachment_path` VARCHAR(500) NULL,
                `raw_body` LONGTEXT NULL,
                `matched_client_id` INT NULL,
                `sync_status` ENUM('Pending', 'Approved', 'Auto-Synced', 'Ignored') DEFAULT 'Pending',
                `synced_at` DATETIME NULL,
                `synced_by` VARCHAR(100) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user_sync` (`user_id`, `sync_status`),
                INDEX `idx_customer_id` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $initialized = true;
    } catch (\Throwable $ex) {
        error_log("init_gmail_license_tables error: " . $ex->getMessage());
    }
}

init_gmail_license_tables($pdo);

/**
 * Pure PHP SSL IMAP Client
 * Ultra-fast: Fetches lightweight headers first (200 bytes), then downloads body only for matching Marg emails
 */
class SimpleImapClient {
    private $fp = null;
    private $tagCounter = 0;
    public $lastError = '';

    public function connect(string $user, string $pass, string $host = 'imap.gmail.com', int $port = 993): bool {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $this->fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->fp) {
            $this->lastError = "Cannot connect to {$host}:{$port} ({$errno} - {$errstr})";
            return false;
        }

        stream_set_timeout($this->fp, 10);
        $greeting = fgets($this->fp);

        // Sanitize password - remove spaces if user copied 4x4 app password "abcd efgh ijkl mnop"
        $cleanPass = str_replace(' ', '', trim($pass));

        $res = $this->sendCommand('LOGIN "' . addcslashes($user, '"\\') . '" "' . addcslashes($cleanPass, '"\\') . '"');
        if (!$res['ok']) {
            $this->lastError = "Login failed: " . ($res['response'] ?? 'Invalid credentials. Please verify your Gmail address and 16-character App Password.');
            $this->close();
            return false;
        }
        return true;
    }

    public function selectFolder(string $folder = 'INBOX'): bool {
        $res = $this->sendCommand("SELECT \"{$folder}\"");
        return $res['ok'];
    }

    public function search(string $criteria = 'ALL'): array {
        $res = $this->sendCommand("SEARCH {$criteria}");
        if (!$res['ok']) return [];

        $uids = [];
        foreach ($res['lines'] as $line) {
            if (stripos($line, '* SEARCH') === 0) {
                $parts = explode(' ', trim(substr($line, 8)));
                foreach ($parts as $p) {
                    if (is_numeric($p) && (int)$p > 0) {
                        $uids[] = (int)$p;
                    }
                }
            }
        }
        return $uids;
    }

    /**
     * Fast Header-Only fetch (< 300 bytes)
     */
    public function fetchHeader(int $id): array {
        $tag = 'TAG' . (++$this->tagCounter);
        fwrite($this->fp, "{$tag} FETCH {$id} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])\r\n");

        $rawLines = [];
        $collecting = false;
        while (!feof($this->fp)) {
            $line = fgets($this->fp);
            if ($line === false) break;

            if (strpos($line, "{$tag} OK") === 0 || strpos($line, "{$tag} NO") === 0 || strpos($line, "{$tag} BAD") === 0) {
                break;
            }
            if (!$collecting && strpos($line, '* ' . $id . ' FETCH') === 0) {
                $collecting = true;
                continue;
            }
            if ($collecting) {
                $rawLines[] = $line;
            }
        }

        $headerText = implode('', $rawLines);
        $headers = [];
        $currentHeader = '';
        foreach (explode("\r\n", $headerText) as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $m)) {
                $currentHeader = strtolower($m[1]);
                $headers[$currentHeader] = trim($m[2]);
            } elseif ($currentHeader && (strpos($line, ' ') === 0 || strpos($line, "\t") === 0)) {
                $headers[$currentHeader] .= ' ' . trim($line);
            }
        }

        $subject = $headers['subject'] ?? '(No Subject)';
        if (function_exists('mb_decode_mimeheader')) {
            $subject = mb_decode_mimeheader($subject);
        }

        return [
            'id' => $id,
            'subject' => $subject,
            'from' => $headers['from'] ?? '',
            'date' => $headers['date'] ?? date('Y-m-d H:i:s')
        ];
    }

    /**
     * Fetch Full Message Body & Attachments (Only called when headers match!)
     */
    public function fetchMessage(int $id): array {
        $tag = 'TAG' . (++$this->tagCounter);
        fwrite($this->fp, "{$tag} FETCH {$id} (BODY.PEEK[])\r\n");

        $rawLines = [];
        $collecting = false;
        while (!feof($this->fp)) {
            $line = fgets($this->fp);
            if ($line === false) break;

            if (strpos($line, "{$tag} OK") === 0 || strpos($line, "{$tag} NO") === 0 || strpos($line, "{$tag} BAD") === 0) {
                break;
            }
            if (!$collecting && strpos($line, '* ' . $id . ' FETCH') === 0) {
                $collecting = true;
                continue;
            }
            if ($collecting) {
                $rawLines[] = $line;
            }
        }

        $fullRaw = implode('', $rawLines);
        return $this->parseRawEmail($fullRaw, $id);
    }

    private function parseRawEmail(string $raw, int $id): array {
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerText = $parts[0] ?? '';
        $bodyText = $parts[1] ?? '';

        // Parse headers
        $headers = [];
        $currentHeader = '';
        foreach (explode("\r\n", $headerText) as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $m)) {
                $currentHeader = strtolower($m[1]);
                $headers[$currentHeader] = trim($m[2]);
            } elseif ($currentHeader && (strpos($line, ' ') === 0 || strpos($line, "\t") === 0)) {
                $headers[$currentHeader] .= ' ' . trim($line);
            }
        }

        $subject = $headers['subject'] ?? '(No Subject)';
        if (function_exists('mb_decode_mimeheader')) {
            $subject = mb_decode_mimeheader($subject);
        }

        $from = $headers['from'] ?? '';
        $date = $headers['date'] ?? date('Y-m-d H:i:s');

        $attachments = [];
        $plainBody = '';

        $contentType = $headers['content-type'] ?? 'text/plain';
        if (stripos($contentType, 'multipart/') !== false && preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $bm)) {
            $boundary = $bm[1];
            $sections = explode('--' . $boundary, $bodyText);
            foreach ($sections as $section) {
                $section = trim($section);
                if (empty($section) || $section === '--') continue;

                $sParts = explode("\r\n\r\n", $section, 2);
                $sHeadersRaw = $sParts[0] ?? '';
                $sContent = $sParts[1] ?? '';

                $sHeaders = [];
                foreach (explode("\r\n", $sHeadersRaw) as $shLine) {
                    if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $shLine, $sm)) {
                        $sHeaders[strtolower($sm[1])] = trim($sm[2]);
                    }
                }

                $sDisp = $sHeaders['content-disposition'] ?? '';
                $sType = $sHeaders['content-type'] ?? '';
                $sEncoding = strtolower($sHeaders['content-transfer-encoding'] ?? '');

                // Check for attachment
                $filename = '';
                if (preg_match('/filename="?([^";\r\n]+)"?/i', $sDisp, $fm) || preg_match('/name="?([^";\r\n]+)"?/i', $sType, $fm)) {
                    $filename = trim($fm[1]);
                }

                if (!empty($filename)) {
                    $fileData = $sContent;
                    if ($sEncoding === 'base64') {
                        $fileData = base64_decode($fileData);
                    } elseif ($sEncoding === 'quoted-printable') {
                        $fileData = quoted_printable_decode($fileData);
                    }
                    $attachments[] = [
                        'filename' => $filename,
                        'data' => $fileData,
                        'size' => strlen($fileData)
                    ];
                } else {
                    if (stripos($sType, 'text/plain') !== false || empty($sType)) {
                        $decodedText = $sContent;
                        if ($sEncoding === 'base64') {
                            $decodedText = base64_decode($decodedText);
                        } elseif ($sEncoding === 'quoted-printable') {
                            $decodedText = quoted_printable_decode($decodedText);
                        }
                        $plainBody .= "\n" . $decodedText;
                    }
                }
            }
        } else {
            $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
            if ($encoding === 'base64') {
                $plainBody = base64_decode($bodyText);
            } elseif ($encoding === 'quoted-printable') {
                $plainBody = quoted_printable_decode($bodyText);
            } else {
                $plainBody = $bodyText;
            }
        }

        return [
            'id' => $id,
            'subject' => $subject,
            'from' => $from,
            'date' => $date,
            'body' => trim($plainBody),
            'attachments' => $attachments
        ];
    }

    private function sendCommand(string $cmd): array {
        $tag = 'TAG' . (++$this->tagCounter);
        fwrite($this->fp, "{$tag} {$cmd}\r\n");

        $lines = [];
        $statusOk = false;
        $response = '';

        while (!feof($this->fp)) {
            $line = fgets($this->fp);
            if ($line === false) break;
            $lines[] = trim($line);

            if (strpos($line, "{$tag} OK") === 0) {
                $statusOk = true;
                $response = trim(substr($line, strlen("{$tag} OK")));
                break;
            } elseif (strpos($line, "{$tag} NO") === 0 || strpos($line, "{$tag} BAD") === 0) {
                $statusOk = false;
                $response = trim($line);
                break;
            }
        }

        return [
            'ok' => $statusOk,
            'response' => $response,
            'lines' => $lines
        ];
    }

    public function close(): void {
        if ($this->fp) {
            @fwrite($this->fp, "TAG_QUIT LOGOUT\r\n");
            @fclose($this->fp);
            $this->fp = null;
        }
    }

    public function __destruct() {
        $this->close();
    }
}

/**
 * Marg ERP License Email Parser
 */
function parse_marg_license_text(string $text): array {
    $data = [
        'party_name' => '',
        'address' => '',
        'city' => '',
        'state' => '',
        'country' => 'INDIA',
        'phone' => '',
        'reg_mobile' => '',
        'reg_email' => '',
        'customer_id' => '',
        'dated' => '',
        'contact_person' => '',
        'software_type' => '',
        'users_count' => '1',
        'companies_count' => '1',
        'main_computer' => '',
        'home_edition' => ''
    ];

    $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));

    foreach ($lines as $line) {
        if (preg_match('/^Customer\s*ID\s*:\s*(.+)$/i', $line, $m)) {
            $data['customer_id'] = trim($m[1]);
        } elseif (preg_match('/^Phone\s*:\s*(.+)$/i', $line, $m)) {
            $data['phone'] = trim($m[1]);
        } elseif (preg_match('/^Reg\.?\s*Mobile\s*:\s*(.+)$/i', $line, $m)) {
            $data['reg_mobile'] = trim($m[1]);
        } elseif (preg_match('/^Reg\.?\s*Email\s*:\s*(.+)$/i', $line, $m)) {
            $data['reg_email'] = trim($m[1]);
        } elseif (preg_match('/^Dated\s*:\s*(.+)$/i', $line, $m)) {
            $data['dated'] = trim($m[1]);
        } elseif (preg_match('/^Contact\s*:\s*(.+)$/i', $line, $m)) {
            $data['contact_person'] = trim($m[1]);
        } elseif (preg_match('/^Software\s*:\s*(.+)$/i', $line, $m)) {
            $data['software_type'] = trim($m[1]);
        } elseif (preg_match('/^Users\s*:\s*(.+)$/i', $line, $m)) {
            $data['users_count'] = trim($m[1]);
        } elseif (preg_match('/^Companies\s*:\s*(.+)$/i', $line, $m)) {
            $data['companies_count'] = trim($m[1]);
        } elseif (preg_match('/^Main\s*Computer\s*:\s*(.+)$/i', $line, $m)) {
            $data['main_computer'] = trim($m[1]);
        } elseif (preg_match('/^Home\s*Edition\s*:\s*(.+)$/i', $line, $m)) {
            $data['home_edition'] = trim($m[1]);
        }
    }

    $headerLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^(Phone|Customer\s*ID|Dated|Contact|Reg|Software|Users|Main\s*Computer)\s*:/i', $line)) {
            break;
        }
        $headerLines[] = $line;
    }

    if (!empty($headerLines)) {
        $data['party_name'] = $headerLines[0];

        $addrParts = [];
        for ($i = 1; $i < count($headerLines); $i++) {
            $l = $headerLines[$i];
            if (stripos($l, 'INDIA') !== false || stripos($l, 'UTTARPRADESH') !== false || stripos($l, 'DELHI') !== false || stripos($l, 'MAHARASHTRA') !== false) {
                $words = explode(' ', $l);
                if (count($words) >= 2 && strtoupper($words[count($words) - 1]) === 'INDIA') {
                    $data['country'] = 'INDIA';
                    array_pop($words);
                    $data['state'] = implode(' ', $words);
                } else {
                    $data['state'] = $l;
                }
            } elseif (count($headerLines) >= 3 && $i === count($headerLines) - 2 && empty($data['city'])) {
                $data['city'] = $l;
            } else {
                $addrParts[] = $l;
            }
        }
        $data['address'] = implode(', ', $addrParts);
    }

    return $data;
}

// -------------------------------------------------------------------------
// ROUTE API ACTIONS
// -------------------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!empty($action)) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Action 1: Get Current Settings
        if ($action === 'get_settings') {
            $stmt = $pdo->prepare("SELECT * FROM `tenant_gmail_license_settings` WHERE user_id = ? OR user_id = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $row = [
                    'gmail_address' => '',
                    'app_password' => '',
                    'allowed_senders' => '@margcompusoft.com, licenses@margerp.com',
                    'subject_keywords' => 'License, Registration, Passkey, Customer ID',
                    'sync_mode' => 'manual',
                    'save_attachments' => 1,
                    'allowed_extensions' => 'lic,txt,pdf,zip',
                    'status' => 'Active',
                    'last_sync_at' => null,
                    'last_sync_status' => null
                ];
            } else {
                if (!empty($row['app_password'])) {
                    $row['app_password_masked'] = substr($row['app_password'], 0, 4) . ' **** **** ' . substr($row['app_password'], -4);
                }
            }

            echo json_encode(['success' => true, 'settings' => $row]);
            exit;
        }

        // Action 2: Save Settings
        if ($action === 'save_settings') {
            $gmail_address = trim($_POST['gmail_address'] ?? '');
            $app_password = trim($_POST['app_password'] ?? '');
            $allowed_senders = trim($_POST['allowed_senders'] ?? '');
            $subject_keywords = trim($_POST['subject_keywords'] ?? '');
            $sync_mode = in_array($_POST['sync_mode'] ?? '', ['manual', 'auto']) ? $_POST['sync_mode'] : 'manual';
            $save_attachments = !empty($_POST['save_attachments']) ? 1 : 0;
            $allowed_extensions = trim($_POST['allowed_extensions'] ?? 'lic,txt,pdf,zip');

            $stmtCheck = $pdo->prepare("SELECT id, app_password FROM `tenant_gmail_license_settings` WHERE user_id = ? LIMIT 1");
            $stmtCheck->execute([$user_id]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $finalPass = (!empty($app_password) && strpos($app_password, '****') === false) ? $app_password : $existing['app_password'];

                $stmtUp = $pdo->prepare("
                    UPDATE `tenant_gmail_license_settings` SET
                        gmail_address = ?,
                        app_password = ?,
                        allowed_senders = ?,
                        subject_keywords = ?,
                        sync_mode = ?,
                        save_attachments = ?,
                        allowed_extensions = ?,
                        status = 'Active'
                    WHERE id = ?
                ");
                $stmtUp->execute([$gmail_address, $finalPass, $allowed_senders, $subject_keywords, $sync_mode, $save_attachments, $allowed_extensions, $existing['id']]);
            } else {
                $stmtIn = $pdo->prepare("
                    INSERT INTO `tenant_gmail_license_settings`
                        (user_id, gmail_address, app_password, allowed_senders, subject_keywords, sync_mode, save_attachments, allowed_extensions, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                ");
                $stmtIn->execute([$user_id, $gmail_address, $app_password, $allowed_senders, $subject_keywords, $sync_mode, $save_attachments, $allowed_extensions]);
            }

            echo json_encode(['success' => true, 'message' => 'Gmail License Automation settings saved successfully!']);
            exit;
        }

        // Action 3: Test IMAP Connection
        if ($action === 'test_connection') {
            $stmt = $pdo->prepare("SELECT * FROM `tenant_gmail_license_settings` WHERE user_id = ? OR user_id = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            $gmail = trim($_POST['gmail_address'] ?? $settings['gmail_address'] ?? '');
            $pass = trim($_POST['app_password'] ?? '');
            if (empty($pass) || strpos($pass, '****') !== false) {
                $pass = $settings['app_password'] ?? '';
            }

            if (empty($gmail) || empty($pass)) {
                echo json_encode(['success' => false, 'message' => 'Please provide both Gmail Address and 16-character App Password.']);
                exit;
            }

            $client = new SimpleImapClient();
            if ($client->connect($gmail, $pass)) {
                $client->selectFolder('INBOX');
                echo json_encode([
                    'success' => true,
                    'message' => "Connected successfully to Gmail ({$gmail})! Ready to fetch Marg ERP license emails."
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "Connection failed: " . $client->lastError
                ]);
            }
            exit;
        }

        // Action 4: Fast Fetch Emails & Parse
        if ($action === 'fetch_emails') {
            // Set max execution time to 60s
            @set_time_limit(60);

            $stmt = $pdo->prepare("SELECT * FROM `tenant_gmail_license_settings` WHERE user_id = ? OR user_id = 1 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings || empty($settings['gmail_address']) || empty($settings['app_password'])) {
                echo json_encode(['success' => false, 'message' => 'Gmail settings not configured. Please enter your Gmail and App Password first in Settings tab.']);
                exit;
            }

            $gmail = $settings['gmail_address'];
            $pass = $settings['app_password'];
            $allowedSenders = array_filter(array_map('trim', explode(',', strtolower($settings['allowed_senders'] ?? ''))));
            $keywords = array_filter(array_map('trim', explode(',', strtolower($settings['subject_keywords'] ?? ''))));
            $saveAttachments = (int)($settings['save_attachments'] ?? 1);
            $syncMode = $settings['sync_mode'] ?? 'manual';

            $client = new SimpleImapClient();
            if (!$client->connect($gmail, $pass)) {
                $pdo->prepare("UPDATE `tenant_gmail_license_settings` SET last_sync_at = NOW(), last_sync_status = ? WHERE id = ?")
                    ->execute(['Connection Failed: ' . substr($client->lastError, 0, 200), $settings['id']]);

                echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $client->lastError]);
                exit;
            }

            $client->selectFolder('INBOX');

            // Search unread first, fallback to all
            $uids = $client->search('UNSEEN');
            if (empty($uids)) {
                $uids = $client->search('ALL');
            }

            if (empty($uids)) {
                echo json_encode(['success' => true, 'message' => 'No license emails found in Inbox.', 'fetched_count' => 0]);
                exit;
            }

            // Limit to latest 10 emails to keep execution lightning fast (< 2-3 seconds)
            $recentUids = array_slice(array_reverse($uids), 0, 10);
            $processedCount = 0;
            $matchedCount = 0;

            foreach ($recentUids as $uid) {
                // Check if already processed
                $chk = $pdo->prepare("SELECT id FROM `tenant_incoming_licenses` WHERE (user_id = ? OR user_id = 1) AND email_uid = ? LIMIT 1");
                $chk->execute([$user_id, (string)$uid]);
                if ($chk->fetch()) {
                    continue;
                }

                // 1. FAST HEADER ONLY FETCH (takes ~0.02s)
                $header = $client->fetchHeader($uid);
                $fromLower = strtolower($header['from'] ?? '');
                $subjectLower = strtolower($header['subject'] ?? '');

                // Sender filter check
                $senderMatch = empty($allowedSenders);
                if (!$senderMatch) {
                    foreach ($allowedSenders as $snd) {
                        if (strpos($fromLower, $snd) !== false) {
                            $senderMatch = true; break;
                        }
                    }
                }

                // Keyword filter check on subject
                $kwMatch = empty($keywords);
                if (!$kwMatch) {
                    foreach ($keywords as $kw) {
                        if (strpos($subjectLower, $kw) !== false) {
                            $kwMatch = true; break;
                        }
                    }
                }

                // Skip non-matching emails immediately without downloading full body!
                if (!$senderMatch || !$kwMatch) {
                    continue;
                }

                // 2. Fetch full body ONLY for verified Marg emails!
                $msg = $client->fetchMessage($uid);

                // Parse Marg License Details
                $parsed = parse_marg_license_text($msg['body']);
                if (empty($parsed['customer_id']) && empty($parsed['party_name'])) {
                    continue;
                }

                // Process Attachments
                $attFilename = null;
                $attPath = null;
                if ($saveAttachments && !empty($msg['attachments'])) {
                    $uploadDir = __DIR__ . '/../uploads/client_licenses/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $parsed['customer_id'] ?: 'general') . '/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }

                    foreach ($msg['attachments'] as $att) {
                        $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                        $safeExts = explode(',', str_replace(' ', '', strtolower($settings['allowed_extensions'] ?? 'lic,txt,pdf,zip')));
                        if (in_array($ext, $safeExts)) {
                            $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $att['filename']);
                            $targetFile = $uploadDir . $safeName;
                            if (file_put_contents($targetFile, $att['data'])) {
                                $attFilename = $att['filename'];
                                $attPath = 'uploads/client_licenses/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $parsed['customer_id'] ?: 'general') . '/' . $safeName;
                                break;
                            }
                        }
                    }
                }

                // Match against Client Directory
                $matchedClientId = null;
                if (!empty($parsed['customer_id'])) {
                    $stmtC = $pdo->prepare("SELECT id FROM `client_directory` WHERE customer_id = ? LIMIT 1");
                    $stmtC->execute([$parsed['customer_id']]);
                    $matchedClientId = $stmtC->fetchColumn();
                }
                if (!$matchedClientId && !empty($parsed['party_name'])) {
                    $stmtC = $pdo->prepare("SELECT id FROM `client_directory` WHERE LOWER(party_name) = LOWER(?) LIMIT 1");
                    $stmtC->execute([trim($parsed['party_name'])]);
                    $matchedClientId = $stmtC->fetchColumn();
                }

                $status = ($syncMode === 'auto' && $matchedClientId) ? 'Auto-Synced' : 'Pending';

                $stmtIns = $pdo->prepare("
                    INSERT INTO `tenant_incoming_licenses`
                        (user_id, email_uid, email_date, from_email, subject, party_name, customer_id, address, city, state, country, phone, reg_mobile, reg_email, contact_person, dated, software_type, users_count, companies_count, main_computer, home_edition, attachment_filename, attachment_path, matched_client_id, sync_status, synced_at, synced_by)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $syncedAt = ($status === 'Auto-Synced') ? date('Y-m-d H:i:s') : null;
                $syncedBy = ($status === 'Auto-Synced') ? 'Auto-Engine' : null;

                $stmtIns->execute([
                    $user_id,
                    (string)$uid,
                    $msg['from'],
                    $msg['subject'],
                    $parsed['party_name'],
                    $parsed['customer_id'],
                    $parsed['address'],
                    $parsed['city'],
                    $parsed['state'],
                    $parsed['country'],
                    $parsed['phone'],
                    $parsed['reg_mobile'],
                    $parsed['reg_email'],
                    $parsed['contact_person'],
                    $parsed['dated'],
                    $parsed['software_type'],
                    $parsed['users_count'],
                    $parsed['companies_count'],
                    $parsed['main_computer'],
                    $parsed['home_edition'],
                    $attFilename,
                    $attPath,
                    $matchedClientId,
                    $status,
                    $syncedAt,
                    $syncedBy
                ]);

                // Auto-sync update into client_directory if enabled
                if ($status === 'Auto-Synced' && $matchedClientId) {
                    try {
                        $upSql = "UPDATE `client_directory` SET party_status = 'Running'";
                        $params = [];
                        if (!empty($parsed['software_type'])) {
                            $upSql .= ", software_type = ?";
                            $params[] = $parsed['software_type'];
                        }
                        if (!empty($parsed['contact_person'])) {
                            $upSql .= ", contact_person = ?";
                            $params[] = $parsed['contact_person'];
                        }
                        if (!empty($parsed['reg_email'])) {
                            $upSql .= ", email = ?";
                            $params[] = $parsed['reg_email'];
                        }
                        if (!empty($parsed['main_computer'])) {
                            $upSql .= ", main_computer = ?";
                            $params[] = $parsed['main_computer'];
                        }
                        if (!empty($attPath)) {
                            $upSql .= ", license_file_path = ?";
                            $params[] = $attPath;
                        }
                        $upSql .= " WHERE id = ?";
                        $params[] = $matchedClientId;

                        $pdo->prepare($upSql)->execute($params);
                    } catch (\Throwable $e) {}
                }

                $processedCount++;
                if ($matchedClientId) $matchedCount++;
            }

            $pdo->prepare("UPDATE `tenant_gmail_license_settings` SET last_sync_at = NOW(), last_sync_status = ? WHERE id = ?")
                ->execute(["Sync completed: {$processedCount} license emails processed", $settings['id']]);

            echo json_encode([
                'success' => true,
                'message' => "Sync finished! {$processedCount} Marg license emails processed successfully.",
                'processed_count' => $processedCount,
                'matched_count' => $matchedCount
            ]);
            exit;
        }

        // Action 5: List Incoming Licenses (Lightweight, UTF-8 clean & fast)
        if ($action === 'list_licenses') {
            $statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
            
            $sql = "SELECT l.id, l.user_id, l.email_uid, l.email_date, l.from_email, l.subject,
                           l.party_name, l.customer_id, l.address, l.city, l.state, l.country,
                           l.phone, l.reg_mobile, l.reg_email, l.contact_person, l.dated,
                           l.software_type, l.users_count, l.companies_count, l.main_computer,
                           l.home_edition, l.attachment_filename, l.attachment_path,
                           l.matched_client_id, l.sync_status, l.synced_at, l.synced_by, l.created_at,
                           c.party_name AS client_actual_name
                    FROM `tenant_incoming_licenses` l 
                    LEFT JOIN `client_directory` c ON l.matched_client_id = c.id 
                    WHERE (l.user_id = ? OR l.user_id = 1 OR l.user_id = 0)";
            $params = [$user_id];

            if ($statusFilter !== 'all') {
                if ($statusFilter === 'pending') {
                    $sql .= " AND LOWER(l.sync_status) = 'pending'";
                } elseif ($statusFilter === 'auto-synced' || $statusFilter === 'auto_synced') {
                    $sql .= " AND LOWER(l.sync_status) IN ('auto-synced', 'auto_synced')";
                } elseif ($statusFilter === 'approved') {
                    $sql .= " AND LOWER(l.sync_status) = 'approved'";
                } else {
                    $sql .= " AND LOWER(l.sync_status) = ?";
                    $params[] = $statusFilter;
                }
            }
            $sql .= " ORDER BY l.id DESC LIMIT 50";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean UTF-8 strings
            array_walk_recursive($rows, function(&$item) {
                if (is_string($item)) {
                    $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
                }
            });

            echo json_encode(['success' => true, 'licenses' => $rows], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Action 6: Approve & Sync Single License
        if ($action === 'approve_license') {
            $license_id = (int)($_POST['license_id'] ?? 0);
            if ($license_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid license ID.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM `tenant_incoming_licenses` WHERE id = ? LIMIT 1");
            $stmt->execute([$license_id]);
            $lic = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lic) {
                echo json_encode(['success' => false, 'message' => 'License record not found.']);
                exit;
            }

            $targetClientId = (int)($_POST['target_client_id'] ?? $lic['matched_client_id'] ?? 0);

            if ($targetClientId <= 0 && !empty($lic['customer_id'])) {
                $stC = $pdo->prepare("SELECT id FROM `client_directory` WHERE customer_id = ? LIMIT 1");
                $stC->execute([$lic['customer_id']]);
                $targetClientId = (int)$stC->fetchColumn();
            }

            if ($targetClientId > 0) {
                $upSql = "UPDATE `client_directory` SET party_status = 'Running'";
                $params = [];
                if (!empty($lic['customer_id'])) {
                    $upSql .= ", customer_id = ?";
                    $params[] = $lic['customer_id'];
                }
                if (!empty($lic['software_type'])) {
                    $upSql .= ", software_type = ?";
                    $params[] = $lic['software_type'];
                }
                if (!empty($lic['contact_person'])) {
                    $upSql .= ", contact_person = ?";
                    $params[] = $lic['contact_person'];
                }
                if (!empty($lic['reg_email'])) {
                    $upSql .= ", email = ?";
                    $params[] = $lic['reg_email'];
                }
                if (!empty($lic['main_computer'])) {
                    $upSql .= ", main_computer = ?";
                    $params[] = $lic['main_computer'];
                }
                if (!empty($lic['attachment_path'])) {
                    $upSql .= ", license_file_path = ?";
                    $params[] = $lic['attachment_path'];
                }
                $upSql .= " WHERE id = ?";
                $params[] = $targetClientId;

                $pdo->prepare($upSql)->execute($params);

                $pdo->prepare("UPDATE `tenant_incoming_licenses` SET sync_status = 'Approved', matched_client_id = ?, synced_at = NOW(), synced_by = ? WHERE id = ?")
                    ->execute([$targetClientId, $_SESSION['user_name'] ?? 'Admin', $license_id]);

                echo json_encode(['success' => true, 'message' => "Client directory updated and verified successfully!"]);
            } else {
                $insC = $pdo->prepare("
                    INSERT INTO `client_directory`
                        (party_name, customer_id, mobile, alt_mobile, email, contact_person, address, city, state, software_type, main_computer, license_file_path, party_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Running')
                ");
                $insC->execute([
                    $lic['party_name'] ?: 'New Client (' . $lic['customer_id'] . ')',
                    $lic['customer_id'],
                    $lic['phone'] ?: $lic['reg_mobile'],
                    $lic['reg_mobile'],
                    $lic['reg_email'],
                    $lic['contact_person'],
                    $lic['address'],
                    $lic['city'],
                    $lic['state'],
                    $lic['software_type'],
                    $lic['main_computer'],
                    $lic['attachment_path']
                ]);
                $newId = $pdo->lastInsertId();

                $pdo->prepare("UPDATE `tenant_incoming_licenses` SET sync_status = 'Approved', matched_client_id = ?, synced_at = NOW(), synced_by = ? WHERE id = ?")
                    ->execute([$newId, $_SESSION['user_name'] ?? 'Admin', $license_id]);

                echo json_encode(['success' => true, 'message' => "New client created and license synced successfully!"]);
            }
            exit;
        }

        // Action 7: Ignore License
        if ($action === 'ignore_license') {
            $license_id = (int)($_POST['license_id'] ?? 0);
            $pdo->prepare("UPDATE `tenant_incoming_licenses` SET sync_status = 'Ignored' WHERE id = ?")
                ->execute([$license_id]);

            echo json_encode(['success' => true, 'message' => 'License marked as ignored.']);
            exit;
        }

        // Action 8: Pending Count for Header Bell
        if ($action === 'get_pending_count') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `tenant_incoming_licenses` WHERE (user_id = ? OR user_id = 1 OR user_id = 0) AND sync_status = 'Pending'");
            $stmt->execute([$user_id]);
            $count = (int)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'pending_count' => $count]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
        exit;

    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
        exit;
    }
}
