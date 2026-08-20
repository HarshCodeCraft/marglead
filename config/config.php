<?php
/**
 * Marg CRM - Central Configuration File
 * 
 * Production & Development settings for WhatsApp Cloud API, WhatsApp Flows,
 * Webhooks, and MySQL Database Connection.
 */

// Set default timezone to Asia/Kolkata (IST)
date_default_timezone_set('Asia/Kolkata');

// Prevent direct file access header warnings
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// Session Initialization
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// -------------------------------------------------------------
// 1. Environment & Base URL Settings (Supports Ngrok Local & Live Server)
// -------------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://';
$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$is_ngrok = (strpos($http_host, 'ngrok') !== false);
$is_local_env = ($http_host === 'localhost' || strpos($http_host, '127.0.0.1') !== false || strpos($http_host, '::1') !== false || $is_ngrok);

if ($is_ngrok) {
    define('BASE_URL', 'https://' . $http_host . '/marglead/');
    define('NGROK_URL', 'https://ladder-giver-splendid.ngrok-free.dev/marglead');
    define('DB_HOST', getenv('DB_HOST') ?: 'srv2214.hstgr.io');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'u978772385_friendlyaidata');
    define('DB_USER', getenv('DB_USER') ?: 'u978772385_friendlyaidata');
    define('DB_PASS', getenv('DB_PASS') ?: 'Liahshsrahinahs%$#@12345');
} elseif ($is_local_env) {
    define('BASE_URL', $scheme . $http_host . '/marglead/');
    define('NGROK_URL', 'https://ladder-giver-splendid.ngrok-free.dev/marglead');
    define('DB_HOST', getenv('DB_HOST') ?: 'srv2214.hstgr.io');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'u978772385_friendlyaidata');
    define('DB_USER', getenv('DB_USER') ?: 'u978772385_friendlyaidata');
    define('DB_PASS', getenv('DB_PASS') ?: 'Liahshsrahinahs%$#@12345');
} else {
    // Production / Live Server (friendlyaisolution.com)
    define('BASE_URL', $scheme . $http_host . '/');
    define('NGROK_URL', 'https://friendlyaisolution.com');
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'u978772385_friendlyaidata');
    define('DB_USER', getenv('DB_USER') ?: 'u978772385_friendlyaidata');
    define('DB_PASS', getenv('DB_PASS') ?: 'Liahshsrahinahs%$#@12345');
}
define('DB_CHARSET', 'utf8mb4');

// -------------------------------------------------------------
// 2. Meta WhatsApp Cloud API Credentials & Webhook Token
// -------------------------------------------------------------
if (!defined('PHONE_NUMBER_ID')) {
    define('PHONE_NUMBER_ID', getenv('PHONE_NUMBER_ID') ?: '100609346387812');
}
if (!defined('BUSINESS_ACCOUNT_ID')) {
    define('BUSINESS_ACCOUNT_ID', getenv('BUSINESS_ACCOUNT_ID') ?: '100459873456123');
}
if (!defined('ACCESS_TOKEN')) {
    define('ACCESS_TOKEN', getenv('ACCESS_TOKEN') ?: '');
}
if (!defined('VERIFY_TOKEN')) {
    define('VERIFY_TOKEN', getenv('VERIFY_TOKEN') ?: 'marglead_whatsapp_token_2026');
}
if (!defined('APP_SECRET')) {
    define('APP_SECRET', getenv('APP_SECRET') ?: '');
}
if (!defined('FLOW_ID')) {
    define('FLOW_ID', getenv('FLOW_ID') ?: '1838065533836150');
}
if (!defined('GRAPH_API_VERSION')) {
    define('GRAPH_API_VERSION', 'v20.0');
}

// -------------------------------------------------------------
// 3. WhatsApp Flow RSA Encryption Keys Configuration
// -------------------------------------------------------------
if (!defined('FLOW_PRIVATE_KEY_PATH')) {
    define('FLOW_PRIVATE_KEY_PATH', __DIR__ . '/private_key.pem');
}
if (!defined('FLOW_PUBLIC_KEY_PATH')) {
    define('FLOW_PUBLIC_KEY_PATH', __DIR__ . '/public_key.pem');
}

// -------------------------------------------------------------
// 4. File Upload & Logging Settings
// -------------------------------------------------------------
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('LOG_DIR', __DIR__ . '/../logs/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB limit

// Ensure uploads and logs directory exist
if (!file_exists(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}
