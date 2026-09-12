<?php
/**
 * Marg ERP CRM - Tenant Provisioning & Multi-Tenancy Helper
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!function_exists('provisionNewCrmClient')) {
    function provisionNewCrmClient($masterPdo, $companyCode, $companyName, $ownerName, $ownerEmail, $phone, $plan, $passwordStr, $expiryMonths = 12) {
        global $db_host, $db_port, $db_user, $db_pass;
        
        $codeSlug = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $companyCode));
        if (empty($codeSlug)) {
            return ['success' => false, 'message' => 'Invalid Company Code slug.'];
        }
        
        $dbName = 'marg_crm_' . $codeSlug;
        $tablePrefix = "t_{$codeSlug}_";
        $masterDbName = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
        $finalDbName = $dbName;
        $isIsolatedDb = true;
        $tenantPdo = null;
        
        try {
            // A. Attempt creating new isolated database in MySQL (if host permits)
            try {
                $masterPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $createEx) {
                // Shared host restriction (Hostinger Error 1044)
            }
            
            // B. Connect to isolated database or fallback to Hostinger shared DB isolated table structure
            try {
                $tenantDsn = "mysql:host=$db_host;port=$db_port;dbname=$dbName;charset=utf8mb4";
                $tenantPdo = new PDO($tenantDsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } catch (PDOException $connEx) {
                // Hostinger Shared Hosting Fallback: Create isolated client tables (t_code_...) inside u978772385_friendlyaidata
                $tenantPdo = $masterPdo;
                $finalDbName = $tablePrefix;
                $isIsolatedDb = false;
            }
            
            // Keep existing bcrypt hash if already hashed, or hash plain text
            $pwdHash = (strpos($passwordStr, '$2y$') === 0) ? $passwordStr : password_hash($passwordStr, PASSWORD_DEFAULT);
            $allPermissions = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","installation","training","support","renewals","reports","settings"]);

            if ($isIsolatedDb && $tenantPdo) {
                // Standalone Database Provisioning
                $schemaFile = __DIR__ . '/../schema.sql';
                if (file_exists($schemaFile)) {
                    $sql = file_get_contents($schemaFile);
                    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS (marg_crm|[a-zA-Z0-9_]+);/i', '', $sql);
                    $sql = preg_replace('/USE (marg_crm|[a-zA-Z0-9_]+);/i', '', $sql);
                    
                    $queries = explode(';', $sql);
                    foreach ($queries as $q) {
                        $q = trim($q);
                        if (!empty($q)) {
                            if (stripos($q, 'INSERT INTO tenant_companies') !== false) {
                                $q = str_replace('INSERT INTO tenant_companies', 'INSERT IGNORE INTO tenant_companies', $q);
                            }
                            try {
                                $tenantPdo->exec($q);
                            } catch (PDOException $ex) {}
                        }
                    }
                }
                $tenantPdo->exec("TRUNCATE TABLE users");
                $stmtUser = $tenantPdo->prepare("INSERT INTO users (name, email, password, role, status, permissions) VALUES (?, ?, ?, 'Admin', 'Active', ?)");
                $stmtUser->execute([$ownerName, $ownerEmail, $pwdHash, $allPermissions]);
            } else {
                // Hostinger Shared DB Provisioning: Isolated Client Operational Tables (leads, quotations, etc.)
                $tablesToClone = [
                    'leads', 'timeline', 'followups', 'demos', 
                    'quotations', 'payments', 'bank_accounts', 'installations', 
                    'trainings', 'tickets', 'client_directory', 'message_logs', 
                    'chat_conversations', 'merchant_waba_settings', 'bot_flows'
                ];
                foreach ($tablesToClone as $tbl) {
                    try {
                        $tenantPdo->exec("CREATE TABLE IF NOT EXISTS `{$tablePrefix}{$tbl}` LIKE `{$tbl}`");
                    } catch (PDOException $e) {}
                }
            }
            
            // C. Register / Update in master tenant_companies table
            $defaultModulesJson = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","bank_accounts","installation","training","support","renewals","reports","settings","bot_flows","whatsapp_flows","team_inbox","broadcast_campaigns","merchant_waba_settings","whatsapp_settings","bulk_broadcast","clients"]);
            $expiryDate = date('Y-m-d', strtotime("+{$expiryMonths} months"));
            $stmtMaster = $masterPdo->prepare("INSERT INTO tenant_companies (company_name, company_code, owner_name, owner_email, phone, password, db_name, plan, status, expiry_date, allowed_modules) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), owner_name=VALUES(owner_name), owner_email=VALUES(owner_email), phone=VALUES(phone), password=VALUES(password), plan=VALUES(plan), db_name=VALUES(db_name), expiry_date=VALUES(expiry_date), allowed_modules=COALESCE(tenant_companies.allowed_modules, VALUES(allowed_modules))");
            $stmtMaster->execute([$companyName, $codeSlug, $ownerName, $ownerEmail, $phone, $pwdHash, $finalDbName, $plan, $expiryDate, $defaultModulesJson]);
            
            // D. Also ensure users table has matching Active user account
            try {
                $stmtU = $masterPdo->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
                $stmtU->execute([strtolower($ownerEmail)]);
                $existingU = $stmtU->fetch();
                if (!$existingU) {
                    $stmtInsU = $masterPdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'Tenant Admin', 'Active')");
                    $stmtInsU->execute([$ownerName, $ownerEmail, $pwdHash]);
                } else {
                    $stmtUpU = $masterPdo->prepare("UPDATE users SET name = ?, password = ?, role = 'Tenant Admin', status = 'Active' WHERE id = ?");
                    $stmtUpU->execute([$ownerName, $pwdHash, $existingU['id']]);
                }
            } catch (\PDOException $exU) {}

            return [
                'success' => true,
                'message' => "CRM Client \"{$companyName}\" provisioned successfully with database structure \"{$finalDbName}\"!",
                'db_name' => $finalDbName,
                'company_code' => $codeSlug
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database provisioning failure: ' . $e->getMessage()];
        }
    }
}
