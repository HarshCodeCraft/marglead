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
// 1. Environment & Base URL Settings (Auto-Detects Localhost vs Hostinger Live)
// -------------------------------------------------------------
$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$is_local_env = ($http_host === 'localhost' || strpos($http_host, '127.0.0.1') !== false || strpos($http_host, '::1') !== false || strpos($http_host, 'ngrok') !== false);

if ($is_local_env) {
    define('NGROK_URL', getenv('APP_URL') ?: ('http://' . $http_host . '/marglead'));
    define('BASE_URL', rtrim(NGROK_URL, '/') . '/');
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '3307'); // Default XAMPP MySQL port
    define('DB_NAME', getenv('DB_NAME') ?: 'marg_crm');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
} else {
    define('NGROK_URL', getenv('APP_URL') ?: 'https://friendlyaisolution.com');
    define('BASE_URL', rtrim(NGROK_URL, '/') . '/');
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '3306'); // Standard Hostinger MySQL port
    define('DB_NAME', getenv('DB_NAME') ?: 'u978772385_friendlyaidata');
    define('DB_USER', getenv('DB_USER') ?: 'u978772385_friendlyaidata');
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
define('DB_CHARSET', 'utf8mb4');

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
