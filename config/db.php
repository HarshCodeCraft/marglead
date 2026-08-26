<?php
/**
 * Marg CRM - Database Connection & Auto-Migration Helper
 * 
 * Configured for XAMPP Localhost / Production environments.
 * Initializes PDO connection and creates all WhatsApp Ticketing System tables automatically.
 */

require_once __DIR__ . '/config.php';

$db_host = DB_HOST;
$db_port = DB_PORT;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASS;
$db_charset = DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Attempt connection with primary configured port
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=$db_charset";
    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    } catch (PDOException $e) {
        $fallback_port = ($db_port === '3307') ? '3306' : '3307';
        $dsn_fallback = "mysql:host=$db_host;port=$fallback_port;dbname=$db_name;charset=$db_charset";
        try {
            $pdo = new PDO($dsn_fallback, $db_user, $db_pass, $options);
        } catch (PDOException $e2) {
            $alt_db_name = ($db_name === 'marg_crm') ? 'u978772385_friendlyaidata' : 'marg_crm';
            $dsn_alt = "mysql:host=$db_host;port=$db_port;dbname=$alt_db_name;charset=$db_charset";
            try {
                $pdo = new PDO($dsn_alt, $db_user, $db_pass, $options);
            } catch (PDOException $e3) {
                $dsn_alt_fallback = "mysql:host=$db_host;port=$fallback_port;dbname=$alt_db_name;charset=$db_charset";
                try {
                    $pdo = new PDO($dsn_alt_fallback, $db_user, $db_pass, $options);
                } catch (PDOException $e4) {
                    $pdo = null;
                }
            }
        }
    }

    if ($pdo) {
        $db_connected = true;
    } else {
        $db_connected = false;
    }

    if ($pdo) {
        // -------------------------------------------------------------
        // Auto Database Schema Migration for WhatsApp Support Ticket System
        // -------------------------------------------------------------

    // 1. Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'Support Executive',
        status VARCHAR(20) DEFAULT 'Active',
        permissions TEXT NULL,
        profile_photo VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Customers Table Auto-Migration (Ensure all required fields exist)
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        license_no VARCHAR(50) NULL,
        customer_name VARCHAR(100) NULL,
        firm_name VARCHAR(150) NULL,
        mobile VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        amc_expiry DATE NULL,
        status VARCHAR(20) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safely add missing columns to customers table if table existed previously
    $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('license_no', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN license_no VARCHAR(50) NULL AFTER id");
    }
    if (!in_array('customer_name', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN customer_name VARCHAR(100) NULL AFTER license_no");
    }
    if (!in_array('firm_name', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN firm_name VARCHAR(150) NULL AFTER customer_name");
    }
    if (!in_array('mobile', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN mobile VARCHAR(50) NULL AFTER firm_name");
    }
    if (!in_array('amc_expiry', $custCols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN amc_expiry DATE NULL AFTER email");
    }

    // 3. Tickets Table (WhatsApp Ticket System Core)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_number VARCHAR(50) NOT NULL UNIQUE,
        license_number VARCHAR(50) NULL,
        firm_name VARCHAR(150) NULL,
        customer_name VARCHAR(100) NOT NULL,
        mobile VARCHAR(50) NOT NULL,
        email VARCHAR(150) NULL,
        category VARCHAR(100) NOT NULL,
        priority VARCHAR(20) DEFAULT 'Medium',
        description TEXT NOT NULL,
        attachment VARCHAR(255) NULL,
        status VARCHAR(30) DEFAULT 'Open',
        assigned_to VARCHAR(100) NULL,
        internal_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ticket_no (ticket_number),
        INDEX idx_license_no (license_number),
        INDEX idx_status (status),
        INDEX idx_mobile (mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Webhook Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(50) DEFAULT 'INCOMING',
        sender_phone VARCHAR(50) NULL,
        payload LONGTEXT NOT NULL,
        headers TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender (sender_phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 5. Message Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS message_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        direction ENUM('INBOUND', 'OUTBOUND') NOT NULL,
        recipient_or_sender VARCHAR(50) NOT NULL,
        message_type VARCHAR(50) NOT NULL,
        message_body TEXT NULL,
        wamid VARCHAR(100) NULL,
        status VARCHAR(50) DEFAULT 'sent',
        raw_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wamid (wamid),
        INDEX idx_phone (recipient_or_sender)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Flow Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS flow_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        flow_id VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        phone VARCHAR(50) NULL,
        license_number VARCHAR(50) NULL,
        request_payload LONGTEXT NULL,
        response_payload LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_flow_id (flow_id),
        INDEX idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 7. Chat Conversations Status Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
        phone VARCHAR(50) PRIMARY KEY,
        status VARCHAR(20) DEFAULT 'open',
        assigned_to VARCHAR(100) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. Chat Audit Logs Table (Tracks who opened/closed/reopened chats and when)
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(50) NOT NULL,
        action VARCHAR(50) NOT NULL,
        actor_name VARCHAR(100) NOT NULL,
        actor_role VARCHAR(50) NULL,
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 9. Broadcast Campaigns & Audience Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        template_name VARCHAR(100) NOT NULL,
        target_type VARCHAR(50) DEFAULT 'clients',
        custom_message TEXT NULL,
        total_contacts INT DEFAULT 0,
        sent_count INT DEFAULT 0,
        failed_count INT DEFAULT 0,
        pending_count INT DEFAULT 0,
        delay_seconds INT DEFAULT 2,
        status VARCHAR(30) DEFAULT 'pending_approval',
        created_by VARCHAR(100) NULL,
        approved_by VARCHAR(100) NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_audience (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        mobile VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NULL,
        company_name VARCHAR(150) NULL,
        status VARCHAR(20) DEFAULT 'pending',
        sent_at DATETIME NULL,
        error_message TEXT NULL,
        INDEX idx_campaign (campaign_id),
        INDEX idx_mobile (mobile)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 7. API Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        endpoint VARCHAR(255) NOT NULL,
        http_code INT NOT NULL,
        request_data LONGTEXT NULL,
        response_data LONGTEXT NULL,
        duration_ms FLOAT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. WhatsApp Saved Templates Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        category VARCHAR(50) DEFAULT 'General',
        header_type VARCHAR(30) DEFAULT 'none',
        header_text VARCHAR(255) NULL,
        header_content VARCHAR(255) NULL,
        body_text TEXT NOT NULL,
        footer_text VARCHAR(255) NULL,
        buttons_json TEXT NULL,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $pdo->exec("ALTER TABLE whatsapp_templates ADD COLUMN header_type VARCHAR(30) DEFAULT 'none'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE whatsapp_templates ADD COLUMN header_content VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE whatsapp_templates ADD COLUMN buttons_json TEXT NULL"); } catch (PDOException $e) {}

    // 8. Attachments Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_size INT DEFAULT 0,
        file_type VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket_id (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 9. WhatsApp Keyword Auto-Responder Triggers Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_keyword_triggers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        keyword VARCHAR(100) NOT NULL UNIQUE,
        match_type VARCHAR(30) DEFAULT 'exact',
        reply_type VARCHAR(30) DEFAULT 'text',
        reply_payload TEXT NOT NULL,
        flow_id VARCHAR(50) NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 10. System Control Settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

} catch (Throwable $e) {
    $db_connected = false;
    $db_error = $e->getMessage();
    error_log("Database connection failed in config/db.php: " . $e->getMessage());
}
