<?php
/**
 * Marg ERP CRM - Database Update & Migration Wizard
 * Open this file in browser to sync and update Hostinger Live Database schema.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$results = [];

if ($db_connected && $pdo) {
    try {
        // 1. Client Directory Table & Category Column
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_directory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT DEFAULT 1,
            sno INT NULL,
            sw_type VARCHAR(50) DEFAULT 'Marg',
            customer_id VARCHAR(50) NOT NULL UNIQUE,
            subpartner_code VARCHAR(50) NULL,
            subpartner_name VARCHAR(150) NULL,
            party_name VARCHAR(200) NOT NULL,
            company_using VARCHAR(100) NULL,
            address TEXT NULL,
            mobile VARCHAR(50) NULL,
            email VARCHAR(150) NULL,
            user_type VARCHAR(50) DEFAULT 'Single User',
            software_type VARCHAR(100) DEFAULT 'Marg ERP Silver',
            no_of_users INT DEFAULT 1,
            contact_person VARCHAR(100) NULL,
            due_on DATE NULL,
            act_on DATE NULL,
            days INT DEFAULT 0,
            party_status VARCHAR(50) DEFAULT 'Running',
            category VARCHAR(50) DEFAULT 'Category A',
            city VARCHAR(100) NULL,
            transferred_party VARCHAR(20) DEFAULT 'No',
            online_zip_code VARCHAR(20) NULL,
            state VARCHAR(100) NULL,
            home_user VARCHAR(20) DEFAULT 'No',
            software_trade VARCHAR(150) NULL,
            version VARCHAR(50) NULL,
            total_amount DECIMAL(12, 2) DEFAULT 0.00,
            software_hit_date DATE NULL,
            wallet_id VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $cdCols = $pdo->query("SHOW COLUMNS FROM client_directory")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category', $cdCols)) {
            $pdo->exec("ALTER TABLE client_directory ADD COLUMN category VARCHAR(50) NULL DEFAULT 'Category A' AFTER party_status");
            $results[] = ["status" => "success", "msg" => "Added 'category' column to 'client_directory' table."];
        } else {
            $results[] = ["status" => "info", "msg" => "'client_directory' table is fully updated with 'category' column."];
        }
        if (!in_array('area', $cdCols)) {
            $pdo->exec("ALTER TABLE client_directory ADD COLUMN area VARCHAR(150) NULL AFTER city");
            $results[] = ["status" => "success", "msg" => "Added 'area' column to 'client_directory' table."];
        } else {
            $results[] = ["status" => "info", "msg" => "'client_directory' table is fully updated with 'area' column."];
        }

        // 2. Customers Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            license_no VARCHAR(50) NULL,
            customer_name VARCHAR(100) NULL,
            firm_name VARCHAR(150) NULL,
            mobile VARCHAR(50) NULL,
            email VARCHAR(150) NULL,
            amc_expiry DATE NULL,
            status VARCHAR(20) DEFAULT 'Active',
            category VARCHAR(50) DEFAULT 'Category A',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category', $custCols)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN category VARCHAR(50) NULL DEFAULT 'Category A' AFTER status");
            $results[] = ["status" => "success", "msg" => "Added 'category' column to 'customers' table."];
        } else {
            $results[] = ["status" => "info", "msg" => "'customers' table is up to date."];
        }

        // 3. Leads Table Updates
        $leadCols = $pdo->query("SHOW COLUMNS FROM leads")->fetchAll(PDO::FETCH_COLUMN);
        $leadNewCols = [
            'tags' => "VARCHAR(255) NULL AFTER priority",
            'enq_for' => "VARCHAR(255) NULL AFTER products",
            'contact_person' => "VARCHAR(100) NULL AFTER name",
            'assigned_by' => "VARCHAR(100) NULL AFTER assigned_to",
            'installation_status' => "VARCHAR(255) NULL"
        ];
        foreach ($leadNewCols as $col => $definition) {
            if (!in_array($col, $leadCols)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN $col $definition");
                $results[] = ["status" => "success", "msg" => "Added '$col' column to 'leads' table."];
            }
        }
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN city VARCHAR(50) NULL");
        $pdo->exec("ALTER TABLE leads MODIFY COLUMN state VARCHAR(50) NULL");
        $results[] = ["status" => "info", "msg" => "'leads' table schema updated successfully."];

        // 4. Users Table Security Columns
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $userNewCols = [
            'permissions' => "TEXT NULL",
            'profile_photo' => "VARCHAR(255) NULL",
            'action_permissions' => "TEXT NULL",
            'otp_code' => "VARCHAR(10) NULL",
            'otp_expires_at' => "DATETIME NULL",
            'reset_token' => "VARCHAR(255) NULL",
            'reset_token_expires_at' => "DATETIME NULL",
            'reset_ip' => "VARCHAR(50) NULL",
            'reset_user_agent' => "TEXT NULL",
            'reset_session_secret' => "VARCHAR(255) NULL"
        ];
        foreach ($userNewCols as $col => $def) {
            if (!in_array($col, $userCols)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
                $results[] = ["status" => "success", "msg" => "Added '$col' column to 'users' table."];
            }
        }
        $results[] = ["status" => "info", "msg" => "'users' permissions & security columns verified."];

        // 5. Merchant WABA Settings & Logs
        $pdo->exec("CREATE TABLE IF NOT EXISTS merchant_waba_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            waba_id VARCHAR(100) NULL,
            phone_number_id VARCHAR(100) NULL,
            access_token TEXT NULL,
            business_phone VARCHAR(20) NULL,
            tenant_api_key VARCHAR(100) NOT NULL UNIQUE,
            webhook_verify_token VARCHAR(100) NULL,
            status VARCHAR(20) DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS marg_erp_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            tenant_api_key VARCHAR(100) NOT NULL,
            recipient_phone VARCHAR(20) NOT NULL,
            event_type VARCHAR(50) DEFAULT 'Invoice',
            bill_number VARCHAR(100) NULL,
            bill_amount DECIMAL(12,2) DEFAULT 0.00,
            template_name VARCHAR(100) NULL,
            status VARCHAR(20) DEFAULT 'Sent',
            meta_message_id VARCHAR(150) NULL,
            error_message TEXT NULL,
            payload_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $results[] = ["status" => "success", "msg" => "Merchant WABA & ERP log tables verified."];

    } catch (Exception $e) {
        $results[] = ["status" => "danger", "msg" => "Migration Error: " . $e->getMessage()];
    }
} else {
    $errDetail = isset($db_connect_error) ? $db_connect_error : 'Check DB_USER / DB_PASS credentials in config/config.php';
    $results[] = ["status" => "danger", "msg" => "Database Connection Failed: " . $errDetail];
}

// Handle Auto Delete Request
if (isset($_POST['delete_self'])) {
    @unlink(__FILE__);
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration & Upgrade Wizard - Marg ERP CRM</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1.5rem;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 650px;
            width: 100%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .btn {
            background: #3b82f6;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        .btn-danger { background: #ef4444; }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
            <div style="background: rgba(59, 130, 246, 0.15); padding: 0.75rem; border-radius: 12px; color: #3b82f6;">
                <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21 3.582 4 8 4s8-1.79 8-4"></path></svg>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 1.35rem; font-weight: 800;">Database Upgrade Completed</h2>
                <span style="color: #94a3b8; font-size: 0.85rem;">Hostinger Live Database Sync & Schema Migration Status</span>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <?php foreach ($results as $res): ?>
                <div class="list-item">
                    <span><?php echo htmlspecialchars($res['msg']); ?></span>
                    <span class="badge badge-<?php echo $res['status']; ?>"><?php echo strtoupper($res['status']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: space-between; align-items: center; border-top: 1px solid #334155; padding-top: 1.25rem;">
            <form method="POST" style="margin: 0;">
                <button type="submit" name="delete_self" class="btn btn-danger text-sm" onclick="return confirm('Security Check: Are you sure you want to delete this migration file from live server?');">
                    🗑️ Delete This Updater File for Security
                </button>
            </form>
            <a href="index.php?page=clients" class="btn">Go to Clients Directory 🚀</a>
        </div>
    </div>
</body>
</html>
