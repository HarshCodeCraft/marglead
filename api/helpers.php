<?php
/**
 * Marg CRM - API Utility & Helper Functions
 * 
 * Includes logging, ticket ID generation, JSON response formatting,
 * phone formatting, and security helper functions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

/**
 * Write log entry to appropriate log file in /logs directory.
 * Supported types: 'webhook', 'flow', 'api', 'error'
 */
function write_log(string $type, string $message, $data = null): void {
    $log_dir = LOG_DIR;
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $filepath = $log_dir . $type . '.log';
    
    // Auto-rotate if log exceeds 5MB
    if (file_exists($filepath) && filesize($filepath) > 5 * 1024 * 1024) {
        @rename($filepath, $filepath . '.' . date('Y-m-d_H-i-s') . '.old');
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'LOCAL';
    $formatted_data = (!empty($data)) ? (is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT)) : '';

    $log_entry = sprintf(
        "[%s] [%s] [%s]\n%s\n%s\n%s\n",
        $timestamp,
        strtoupper($type),
        $ip,
        $message,
        !empty($formatted_data) ? "Data: " . $formatted_data : "",
        str_repeat('-', 60)
    );

    @file_put_contents($filepath, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Output standardized JSON API response and exit.
 */
function json_response(bool $success, string $message, $data = null, int $http_code = 200): void {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'success'   => $success,
        'message'   => $message,
        'timestamp' => date('c'),
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Generate Next Ticket Number in format: TK-YYYY-XXXXXX (e.g. TK-2026-000001)
 */
function generate_ticket_number(PDO $pdo): string {
    $year = date('Y');
    $prefix = "TK-{$year}-";

    try {
        $stmt = $pdo->prepare("SELECT ticket_number FROM tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $lastTicket = $stmt->fetchColumn();

        if ($lastTicket) {
            $numPart = (int) substr($lastTicket, strlen($prefix));
            $nextNum = $numPart + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        write_log('error', "Failed generating ticket number: " . $e->getMessage());
        return $prefix . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Format phone number to clean WhatsApp format (country code + digits).
 * E.g., "+91 98765-43210" -> "919876543210"
 */
function format_phone_number(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    // Default to India country code 91 if 10 digits provided
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return $digits;
}

/**
 * Sanitize string or array recursively against XSS.
 */
function sanitize_input($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize_input($value);
        }
        return $data;
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Verify Meta HMAC SHA-256 Webhook Signature
 */
function verify_meta_signature(string $payload, string $secret, ?string $header_signature): bool {
    if (empty($secret)) {
        return true; // Skip if no app secret set
    }
    if (empty($header_signature)) {
        return false;
    }

    $expected_signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected_signature, $header_signature);
}
