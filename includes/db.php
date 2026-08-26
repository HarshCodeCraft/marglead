<?php
/**
 * Marg ERP CRM - Database Connection Helper
 * Configured for Hostinger Production & Localhost environments.
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Multi-Tenant Table Rewriter PDO Proxy
 * Automatically prefixes table names in SQL queries with active tenant prefix (e.g. t_poshak_)
 */
if (!class_exists('TenantAwarePDO')) {
    class TenantAwarePDO extends PDO {
        public function __construct() {}

        public static function createFromExisting(PDO $masterPdo, string $prefix): TenantAwarePDO {
            $instance = new class($masterPdo, $prefix) extends TenantAwarePDO {
                private PDO $realPdo;
                private string $prefixStr;

                public function __construct(PDO $pdo, string $prefix) {
                    $this->realPdo = $pdo;
                    $this->prefixStr = $prefix;
                }

                public function rewriteSql(string $sql): string {
                    if (empty($this->prefixStr)) return $sql;
                    
                    $tables = [
                        'leads', 'timeline', 'followups', 'demos', 
                        'quotations', 'payments', 'bank_accounts', 'installations', 
                        'trainings', 'training_sessions', 'tickets', 'support_tickets', 'ticket_replies',
                        'client_directory', 'customers', 'customer_kyc_details', 'customer_reviews',
                        'message_logs', 'chat_conversations', 'merchant_waba_settings', 'bot_flows',
                        'renewals', 'invoices', 'lead_documents', 'broadcast_campaigns', 'campaign_audience',
                        'whatsapp_keyword_triggers', 'whatsapp_templates', 'notifications', 'activity_logs'
                    ];

                    foreach ($tables as $tbl) {
                        if (strpos($sql, $this->prefixStr . $tbl) !== false) {
                            continue;
                        }
                        $pattern = '/\b(FROM|JOIN|INTO|UPDATE|TABLE|TRUNCATE)\s+(`?' . $tbl . '`?)\b/i';
                        $sql = preg_replace_callback($pattern, function($matches) use ($tbl) {
                            $targetTbl = $this->prefixStr . trim($matches[2], '`');
                            $baseTbl = trim($matches[2], '`');
                            
                            // Auto-provision tenant table if missing
                            try {
                                $this->realPdo->exec("CREATE TABLE IF NOT EXISTS `$targetTbl` LIKE `$baseTbl`");
                            } catch (\Exception $e) {}

                            return $matches[1] . ' `' . $targetTbl . '`';
                        }, $sql);
                    }
                    return $sql;
                }

                public function prepare($query, $options = []): PDOStatement|false {
                    $rewritten = $this->rewriteSql($query);
                    try {
                        return $this->realPdo->prepare($rewritten, $options ?: []);
                    } catch (\PDOException $e) {
                        if ($e->getCode() == '42S02' || strpos($e->getMessage(), "doesn't exist") !== false) {
                            // Retry after auto-table creation attempt
                            return $this->realPdo->prepare($rewritten, $options ?: []);
                        }
                        throw $e;
                    }
                }

                public function query($query, $fetchMode = null, ...$fetchModeArgs): PDOStatement|false {
                    $rewritten = $this->rewriteSql($query);
                    try {
                        if ($fetchMode !== null) {
                            return $this->realPdo->query($rewritten, $fetchMode, ...$fetchModeArgs);
                        }
                        return $this->realPdo->query($rewritten);
                    } catch (\PDOException $e) {
                        if ($e->getCode() == '42S02' || strpos($e->getMessage(), "doesn't exist") !== false) {
                            if ($fetchMode !== null) {
                                return $this->realPdo->query($rewritten, $fetchMode, ...$fetchModeArgs);
                            }
                            return $this->realPdo->query($rewritten);
                        }
                        throw $e;
                    }
                }

                public function exec($statement): int|false {
                    $rewritten = $this->rewriteSql($statement);
                    try {
                        return $this->realPdo->exec($rewritten);
                    } catch (\PDOException $e) {
                        if ($e->getCode() == '42S02' || strpos($e->getMessage(), "doesn't exist") !== false) {
                            return $this->realPdo->exec($rewritten);
                        }
                        throw $e;
                    }
                }

                public function quote(string $string, int $type = PDO::PARAM_STR): string|false {
                    return $this->realPdo->quote($string, $type);
                }

                public function getAttribute(int $attribute): mixed {
                    return $this->realPdo->getAttribute($attribute);
                }

                public function setAttribute(int $attribute, mixed $value): bool {
                    return $this->realPdo->setAttribute($attribute, $value);
                }

                public function errorCode(): ?string {
                    return $this->realPdo->errorCode();
                }

                public function errorInfo(): array {
                    return $this->realPdo->errorInfo();
                }

                public function lastInsertId($name = null): string|false {
                    return $this->realPdo->lastInsertId($name);
                }

                public function beginTransaction(): bool {
                    return $this->realPdo->beginTransaction();
                }

                public function commit(): bool {
                    return $this->realPdo->commit();
                }

                public function rollBack(): bool {
                    return $this->realPdo->rollBack();
                }

                public function inTransaction(): bool {
                    return $this->realPdo->inTransaction();
                }
            };
            return $instance;
        }
    }
}

$db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db_name = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
$db_user = defined('DB_USER') ? DB_USER : 'u978772385_friendlyaidata';
$db_pass = defined('DB_PASS') ? DB_PASS : 'Liahshsrahinahs%$#@12345';
$db_port = defined('DB_PORT') ? DB_PORT : '3307';
$db_charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

$dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=$db_charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 3,
];

try {
    try {
        $pdo_master = new PDO($dsn, $db_user, $db_pass, $options);
    } catch (\PDOException $e) {
        $fallback_port = ($db_port === '3307') ? '3306' : '3307';
        $dsn_fallback = "mysql:host=$db_host;port=$fallback_port;dbname=$db_name;charset=$db_charset";
        try {
            $pdo_master = new PDO($dsn_fallback, $db_user, $db_pass, $options);
            $db_port = $fallback_port;
        } catch (\PDOException $e2) {
            // Fallback to local XAMPP MySQL if remote connection fails locally
            try {
                $pdo_master = new PDO("mysql:host=localhost;port=3307;dbname=$db_name;charset=utf8mb4", "root", "", $options);
            } catch (\PDOException $e5) {
                try {
                    $pdo_master = new PDO("mysql:host=localhost;port=3306;dbname=$db_name;charset=utf8mb4", "root", "", $options);
                } catch (\PDOException $e6) {
                    $pdo_master = null;
                }
            }
        }
    }
    $pdo = $pdo_master;
    $db_connected = !empty($pdo);

    // Multi-tenant Dynamic Database Router
    $active_tenant_db = null;
    if (isset($_SESSION['impersonate_tenant_db']) && !empty($_SESSION['impersonate_tenant_db'])) {
        $active_tenant_db = $_SESSION['impersonate_tenant_db'];
    } elseif (isset($_SESSION['tenant_db']) && !empty($_SESSION['tenant_db']) && $_SESSION['tenant_db'] !== $db_name) {
        $active_tenant_db = $_SESSION['tenant_db'];
    }

    if (!empty($active_tenant_db) && $active_tenant_db !== $db_name && !empty($pdo)) {
        // If active_tenant_db starts with 't_', it is a table prefix inside master DB, not a separate MySQL schema
        if (strpos($active_tenant_db, 't_') === 0) {
            $pdo = TenantAwarePDO::createFromExisting($pdo_master, $active_tenant_db);
        } else {
            try {
                $tenant_dsn = "mysql:host=$db_host;port=$db_port;dbname=$active_tenant_db;charset=$db_charset";
                $pdo = new PDO($tenant_dsn, $db_user, $db_pass, $options);
            } catch (\PDOException $te) {
                // Fallback to master DB if tenant DB connection fails
                $pdo = $pdo_master;
            }
        }
    }
    
    // Ensure MySQL session time_zone matches PHP Asia/Kolkata (IST)
    if (!empty($pdo)) {
        try {
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (\Exception $e) {}
    }

    // Schema auto-upgrade to support new Lead fields safely
    if (!empty($pdo)) {
        try {
            $columnsQuery = $pdo->query("SHOW COLUMNS FROM leads");
            $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tags', $columns)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN tags VARCHAR(255) NULL");
            }
            if (!in_array('enq_for', $columns)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN enq_for VARCHAR(255) NULL");
            }
            if (!in_array('contact_person', $columns)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN contact_person VARCHAR(100) NULL");
            }
            if (!in_array('assigned_by', $columns)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN assigned_by VARCHAR(100) NULL");
            }
            if (!in_array('group_stage', $columns)) {
                $pdo->exec("ALTER TABLE leads ADD COLUMN group_stage VARCHAR(100) NULL");
            }
            if (in_array('city', $columns)) {
                $pdo->exec("ALTER TABLE leads MODIFY COLUMN city VARCHAR(50) NULL");
            }
            if (in_array('state', $columns)) {
                $pdo->exec("ALTER TABLE leads MODIFY COLUMN state VARCHAR(50) NULL");
            }
        } catch (\Exception $e) {}

        // Schema auto-upgrade to support user-specific permissions & OTP reset security safely
        try {
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
            if (!in_array('otp_code', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) NULL");
            }
            if (!in_array('otp_expires_at', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL");
            }
            if (!in_array('reset_token', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
            }
            if (!in_array('reset_token_expires_at', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL");
            }
            if (!in_array('reset_ip', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN reset_ip VARCHAR(50) NULL");
            }
            if (!in_array('reset_user_agent', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN reset_user_agent TEXT NULL");
            }
            if (!in_array('reset_session_secret', $userColumns)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN reset_session_secret VARCHAR(255) NULL");
            }
        } catch (\Exception $e) {}

    // Schema auto-upgrade to support tenant_companies password column & default allowed_modules
    try {
        $tenantCompQuery = $pdo->query("SHOW COLUMNS FROM tenant_companies");
        $tenantCompColumns = $tenantCompQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('password', $tenantCompColumns)) {
            $pdo->exec("ALTER TABLE tenant_companies ADD COLUMN password VARCHAR(255) NULL");
        }
        
        // Auto-fix empty allowed_modules for existing SaaS clients
        $defaultModulesJson = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","bank_accounts","installation","training","support","renewals","reports","settings","bot_flows","whatsapp_flows","team_inbox","broadcast_campaigns","merchant_waba_settings","whatsapp_settings","bulk_broadcast","clients"]);
        $pdo->exec("UPDATE tenant_companies SET allowed_modules = '{$defaultModulesJson}' WHERE allowed_modules IS NULL OR allowed_modules = '' OR allowed_modules = 'null'");
    } catch (\Exception $e) {}

    // Schema auto-upgrade to support lead installation checklist status
    try {
        $leadColumnsQuery = $pdo->query("SHOW COLUMNS FROM leads");
        $leadColumns = $leadColumnsQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('installation_status', $leadColumns)) {
            $pdo->exec("ALTER TABLE leads ADD COLUMN installation_status VARCHAR(255) NULL");
        }
    } catch (\Exception $e) {}

    // Schema auto-upgrade to support Client Directory Category
    try {
        $cdCheck = $pdo->query("SHOW TABLES LIKE 'client_directory'");
        if ($cdCheck && $cdCheck->rowCount() > 0) {
            $cdCols = $pdo->query("SHOW COLUMNS FROM client_directory")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category', $cdCols)) {
                $pdo->exec("ALTER TABLE client_directory ADD COLUMN category VARCHAR(50) NULL DEFAULT 'Category A' AFTER party_status");
            }
        }
        $custCheck = $pdo->query("SHOW TABLES LIKE 'customers'");
        if ($custCheck && $custCheck->rowCount() > 0) {
            $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category', $custCols)) {
                $pdo->exec("ALTER TABLE customers ADD COLUMN category VARCHAR(50) NULL DEFAULT 'Category A' AFTER status");
            }
        }
    } catch (\PDOException $catEx) {}

    // Schema auto-upgrade for SaaS tenant_companies allowed_modules
    try {
        $tenantCompColsQuery = $pdo_master->query("SHOW COLUMNS FROM tenant_companies");
        $tenantCompCols = $tenantCompColsQuery->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('allowed_modules', $tenantCompCols)) {
            $pdo_master->exec("ALTER TABLE tenant_companies ADD COLUMN allowed_modules TEXT NULL");
        }
    } catch (\PDOException $tce) {
        // Ignore if tenant_companies table doesn't exist yet
    }

    // Schema auto-upgrade to support customer reviews and ratings
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            company VARCHAR(100) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            rating DECIMAL(2, 1) DEFAULT 5.0,
            review_text TEXT NOT NULL,
            service_name VARCHAR(100) DEFAULT 'Marg ERP 9+',
            source VARCHAR(50) DEFAULT 'Google Verified',
            status ENUM('Approved', 'Pending', 'Hidden') DEFAULT 'Approved',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\PDOException $rEx) {
        // Ignore review table creation error
    }

    // Schema auto-upgrade for Enterprise Security (login_attempts & activity_logs)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            email VARCHAR(150) NOT NULL,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip_address),
            INDEX (email),
            INDEX (attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            user_name VARCHAR(100) DEFAULT 'System',
            user_role VARCHAR(50) DEFAULT 'Guest',
            action VARCHAR(100) NOT NULL,
            module VARCHAR(100) NOT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (action),
            INDEX (module),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\PDOException $secEx) {
        // Ignore security table creation error
    }

    // Schema auto-upgrade to support email archiving & peripheral modules (safely isolated)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'sent_emails'");
        if ($tableCheck && $tableCheck->rowCount() === 0) {
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
        $tCheck = $pdo->query("SHOW TABLES LIKE 'support_tickets'");
        if ($tCheck && $tCheck->rowCount() > 0) {
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
        }

        // Schema auto-upgrade to support follow-up scheduled triggers
        $fCheck = $pdo->query("SHOW TABLES LIKE 'followups'");
        if ($fCheck && $fCheck->rowCount() > 0) {
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
        }

        // Schema auto-upgrade to support dynamic notifications with links
        $notifCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
        if ($notifCheck && $notifCheck->rowCount() === 0) {
            $pdo->exec("CREATE TABLE notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                role VARCHAR(50) NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(255) NULL,
                type VARCHAR(20) DEFAULT 'info',
                unread TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else if ($notifCheck && $notifCheck->rowCount() > 0) {
            $notifCols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('link', $notifCols)) {
                $pdo->exec("ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL");
            }
        }

        // Schema auto-upgrade to ensure demos table exists
        $demosCheck = $pdo->query("SHOW TABLES LIKE 'demos'");
        if ($demosCheck && $demosCheck->rowCount() === 0) {
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
        if ($quoteCheck && $quoteCheck->rowCount() === 0) {
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
        } else if ($quoteCheck && $quoteCheck->rowCount() > 0) {
            $qColsQuery = $pdo->query("SHOW COLUMNS FROM quotations");
            $qCols = $qColsQuery->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('items_json', $qCols)) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN items_json TEXT NULL");
            }
        }

        // Schema auto-upgrade to ensure bank_accounts table exists
        $bankCheck = $pdo->query("SHOW TABLES LIKE 'bank_accounts'");
        if ($bankCheck && $bankCheck->rowCount() === 0) {
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
        }

        // Schema auto-upgrade to ensure training_sessions table exists
        $trainCheck = $pdo->query("SHOW TABLES LIKE 'training_sessions'");
        if ($trainCheck && $trainCheck->rowCount() === 0) {
            $pdo->exec("CREATE TABLE training_sessions (
                id VARCHAR(20) PRIMARY KEY,
                lead_id VARCHAR(50) NULL,
                customer VARCHAR(255) NOT NULL,
                trainer VARCHAR(100) NOT NULL,
                scheduled_at DATETIME NOT NULL,
                mode VARCHAR(50) DEFAULT 'Online (Google Meet)',
                hours_completed INT DEFAULT 0,
                total_hours INT DEFAULT 6,
                status VARCHAR(20) DEFAULT 'scheduled',
                phone VARCHAR(50) NULL,
                email VARCHAR(100) NULL,
                product VARCHAR(255) NULL,
                renewal_date DATE NULL,
                address TEXT NULL,
                topics TEXT NULL,
                remarks TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        // Schema auto-upgrade to ensure merchant_waba_settings table exists
        $wabaCheck = $pdo->query("SHOW TABLES LIKE 'merchant_waba_settings'");
        if ($wabaCheck && $wabaCheck->rowCount() === 0) {
            $pdo->exec("CREATE TABLE merchant_waba_settings (
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
        }

        // Schema auto-upgrade to ensure marg_erp_logs table exists
        $erpLogCheck = $pdo->query("SHOW TABLES LIKE 'marg_erp_logs'");
        if ($erpLogCheck && $erpLogCheck->rowCount() === 0) {
            $pdo->exec("CREATE TABLE marg_erp_logs (
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
        }
    } catch (\Exception $e) {}
}
} catch (\PDOException $e) {
    $pdo = null;
    $db_connected = false;
    $db_connect_error = $e->getMessage();
    // Log exception for debugging (non-fatal)
    error_log("Database connection failure: " . $e->getMessage());
}
