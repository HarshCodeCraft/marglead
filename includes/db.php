<?php
/**
 * Marg ERP CRM - Database Connection Helper
 * Configured for standard XAMPP local host environments.
 */

$db_host = 'localhost';
$db_name = 'marg_crm';
$db_user = 'root';
$db_pass = ''; // Standard XAMPP default password is empty
$db_port = '3307';
$db_charset = 'utf8mb4';

$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=$db_charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    $db_connected = true;
    
    // Schema auto-upgrade to support new Lead fields
    $columnsQuery = $pdo->query("SHOW COLUMNS FROM leads");
    $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tags', $columns)) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN tags VARCHAR(255) NULL AFTER priority");
    }
    if (!in_array('enq_for', $columns)) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN enq_for VARCHAR(255) NULL AFTER products");
    }
    if (!in_array('contact_person', $columns)) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN contact_person VARCHAR(100) NULL AFTER name");
    }
    
    // Modify city/state to be nullable for bulk spreadsheet imports
    $pdo->exec("ALTER TABLE leads MODIFY COLUMN city VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE leads MODIFY COLUMN state VARCHAR(50) NULL");

    // Schema auto-upgrade to support user-specific permissions
    $userColumnsQuery = $pdo->query("SHOW COLUMNS FROM users");
    $userColumns = $userColumnsQuery->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('permissions', $userColumns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL");
    }
    if (!in_array('profile_photo', $userColumns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
    }
    if (!in_array('action_permissions', $userColumns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN action_permissions TEXT NULL");
    }

    // Schema auto-upgrade to support lead installation checklist status
    $leadColumnsQuery = $pdo->query("SHOW COLUMNS FROM leads");
    $leadColumns = $leadColumnsQuery->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('installation_status', $leadColumns)) {
        $pdo->exec("ALTER TABLE leads ADD COLUMN installation_status VARCHAR(255) NULL");
    }

    // Schema auto-upgrade to support email archiving
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'sent_emails'");
    if ($tableCheck->rowCount() === 0) {
        $pdo->exec("CREATE TABLE sent_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(50) DEFAULT 'Sent',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Schema auto-upgrade to support advanced support tickets columns
    $ticketCheck = $pdo->query("SHOW COLUMNS FROM support_tickets");
    $ticketColumns = $ticketCheck->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredTicketCols = [
        'lead_id' => 'VARCHAR(50) NULL',
        'phone' => 'VARCHAR(50) NULL',
        'email' => 'VARCHAR(100) NULL',
        'product' => 'VARCHAR(255) NULL',
        'renewal_date' => 'DATE NULL',
        'address' => 'TEXT NULL',
        'problem' => 'TEXT NULL',
        'due_date' => 'DATE NULL',
        'callback_number' => 'VARCHAR(50) NULL',
        'custom_fields' => 'TEXT NULL'
    ];
    
    foreach ($requiredTicketCols as $colName => $colType) {
        if (!in_array($colName, $ticketColumns)) {
            $pdo->exec("ALTER TABLE support_tickets ADD COLUMN $colName $colType");
        }
    }

    // Schema auto-upgrade to support follow-up scheduled triggers
    $fupCheck = $pdo->query("SHOW COLUMNS FROM followups");
    $fupColumns = $fupCheck->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredFupCols = [
        'send_email' => 'TINYINT DEFAULT 0',
        'send_sms' => 'TINYINT DEFAULT 0',
        'email_sent' => 'TINYINT DEFAULT 0',
        'sms_sent' => 'TINYINT DEFAULT 0',
        'sms_targets' => 'TEXT NULL'
    ];
    
    foreach ($requiredFupCols as $colName => $colType) {
        if (!in_array($colName, $fupColumns)) {
            $pdo->exec("ALTER TABLE followups ADD COLUMN $colName $colType");
        }
    }

    // Schema auto-upgrade to support dynamic notifications
    $notifCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if ($notifCheck->rowCount() === 0) {
        $pdo->exec("CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            role VARCHAR(50) NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(20) DEFAULT 'info',
            unread TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    // Schema auto-upgrade to ensure demos table exists
    $demosCheck = $pdo->query("SHOW TABLES LIKE 'demos'");
    if ($demosCheck->rowCount() === 0) {
        $pdo->exec("CREATE TABLE demos (
            id VARCHAR(20) PRIMARY KEY,
            lead_id VARCHAR(20) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            mode VARCHAR(50) DEFAULT 'Online (Google Meet)',
            engineer VARCHAR(100) NOT NULL,
            status VARCHAR(20) DEFAULT 'scheduled',
            rating INT NULL,
            feedback TEXT NULL,
            cancel_reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Schema auto-upgrade to ensure quotations table exists and has items_json column
    $quoteCheck = $pdo->query("SHOW TABLES LIKE 'quotations'");
    if ($quoteCheck->rowCount() === 0) {
        $pdo->exec("CREATE TABLE quotations (
            id VARCHAR(20) PRIMARY KEY,
            lead_id VARCHAR(20) NOT NULL,
            issue_date DATE NOT NULL,
            valid_until DATE NOT NULL,
            taxable_amount DECIMAL(12, 2) NOT NULL,
            gst_amount DECIMAL(12, 2) NOT NULL,
            grand_total DECIMAL(12, 2) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_by VARCHAR(100) NOT NULL,
            items_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $qColsQuery = $pdo->query("SHOW COLUMNS FROM quotations");
        $qCols = $qColsQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('items_json', $qCols)) {
            $pdo->exec("ALTER TABLE quotations ADD COLUMN items_json TEXT NULL");
        }
    }

    // Schema auto-upgrade to ensure bank_accounts table exists
    $bankCheck = $pdo->query("SHOW TABLES LIKE 'bank_accounts'");
    if ($bankCheck->rowCount() === 0) {
        $pdo->exec("CREATE TABLE bank_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(150) NOT NULL,
            bank_name VARCHAR(150) NOT NULL,
            account_number VARCHAR(100) NOT NULL,
            ifsc_code VARCHAR(50) NOT NULL,
            branch VARCHAR(100) NULL,
            account_type VARCHAR(50) DEFAULT 'Current Account',
            upi_id VARCHAR(100) NULL,
            qr_code_image VARCHAR(255) NULL,
            is_primary TINYINT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed default primary corporate account
        $pdo->exec("INSERT INTO bank_accounts (account_name, bank_name, account_number, ifsc_code, branch, account_type, upi_id, is_primary, status) VALUES 
        ('Marg Soft Solutions Pvt Ltd', 'HDFC Bank Ltd.', '50200045091234', 'HDFC0000123', 'Okhla Phase 3, New Delhi', 'Current Account', 'margsoft@okicici', 1, 'Active'),
        ('Marg Soft Solutions Pvt Ltd', 'ICICI Bank Ltd.', '000705012398', 'ICIC0000007', 'Connaught Place, New Delhi', 'Current Account', 'margsoftpay@icici', 0, 'Active')");
    }
} catch (\PDOException $e) {
    $pdo = null;
    $db_connected = false;
    // Log exception for debugging (non-fatal)
    error_log("Database connection failure: " . $e->getMessage());
}
