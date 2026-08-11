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
// 1. Environment & Base URL Settings (ngrok / Live domain)
// -------------------------------------------------------------
define('NGROK_URL', 'https://ladder-giver-splendid.ngrok-free.dev/marglead');
define('BASE_URL', rtrim(NGROK_URL, '/') . '/');

// -------------------------------------------------------------
// 2. WhatsApp Cloud API Configuration
// -------------------------------------------------------------
// Meta Graph API Version
define('GRAPH_API_VERSION', 'v20.0');

// Phone Number ID (From Meta WhatsApp Dashboard)
define('PHONE_NUMBER_ID', getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '1360878153768577');

// WhatsApp Business Account ID (WABA ID)
define('BUSINESS_ACCOUNT_ID', getenv('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '1360878153768577');

// Permanent Meta Access Token (User or System User Token)
define('ACCESS_TOKEN', getenv('WHATSAPP_ACCESS_TOKEN') ?: 'EAAU44LETC4cBSDfBsnKGNrWkNtp8PPjJ3wOX2IlME4XvgtdVZCJzZBKCxLqv0O3XncF6xQ1zSt7uzaiXioCasCC0683qUZAVGf059yCw5YsLDUNRh5DkxZCbhfQTzm09lmB9VvPKuJ7feGc59fzdPilpE99VOcmMOMPY3ZCrFKTfKmPDg3bfAvSN1uohI1sPR3wZDZD');

// Webhook Verification Token (Matches Meta Webhook setup)
define('VERIFY_TOKEN', getenv('WHATSAPP_VERIFY_TOKEN') ?: 'marglead_whatsapp_token_2026');

// Meta App Secret (Used for HMAC SHA-256 Signature Verification)
define('APP_SECRET', getenv('WHATSAPP_APP_SECRET') ?: '1a2b3c4d5e6f7g8h9i0j');

// WhatsApp Flow ID (Generated in WhatsApp Business Manager / Flow Builder)
define('FLOW_ID', getenv('WHATSAPP_FLOW_ID') ?: '2356038494923110');

// Flow Public/Private Key paths (Optional for encrypted Meta Flow endpoints)
define('FLOW_PRIVATE_KEY_PATH', __DIR__ . '/private_key.pem');

// -------------------------------------------------------------
// 3. Database Credentials (XAMPP Default)
// -------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_PORT', '3307'); // Default XAMPP MySQL port set to 3307 or 3306
define('DB_NAME', 'marg_crm');
define('DB_USER', 'root');
define('DB_PASS', '');
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
