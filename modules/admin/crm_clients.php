<?php
/**
 * Marg ERP CRM - CRM Clients & Multi-Tenant SaaS Management Module
 * Allows Super Admin to provision, manage, isolate data, and impersonate SaaS CRM Clients.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Access Security Check: Restricted to System Admin (Database Role Check)
if (!isSystemAdminRole($_SESSION['user_role'] ?? '')) {
    echo "<div class='card p-6 text-center' style='max-width: 500px; margin: 4rem auto; border: 1px solid var(--danger); background: var(--bg-card);'>
        <i data-lucide='shield-alert' style='width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1rem auto;'></i>
        <h3 class='text-lg font-bold mb-2' style='color: var(--danger);'>Access Denied</h3>
        <p class='text-muted text-sm mb-4'>The CRM Clients management console is reserved for System Administrators.</p>
        <a href='index.php?page=dashboard' class='btn btn-primary text-xs'>Return to Workspace Dashboard</a>
    </div>";
    return;
}

// --------------------------------------------------------------------------
// 1. Tenant Database Provisioning Engine Function
// --------------------------------------------------------------------------
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
        
        $pwdHash = password_hash($passwordStr, PASSWORD_DEFAULT);
        $allPermissions = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","installation","training","support","renewals","reports","settings"]);

        if ($isIsolatedDb && $tenantPdo) {
            // Standalone Database Provisioning
            $schemaFile = __DIR__ . '/../../schema.sql';
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
        
        // C. Register in master tenant_companies table (Includes Password & Default Allowed Modules directly)
        $defaultModulesJson = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","bank_accounts","installation","training","support","renewals","reports","settings","bot_flows","whatsapp_flows","team_inbox","broadcast_campaigns","merchant_waba_settings","whatsapp_settings","bulk_broadcast","clients"]);
        $expiryDate = date('Y-m-d', strtotime("+{$expiryMonths} months"));
        $stmtMaster = $masterPdo->prepare("INSERT INTO tenant_companies (company_name, company_code, owner_name, owner_email, phone, password, db_name, plan, status, expiry_date, allowed_modules) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), owner_name=VALUES(owner_name), owner_email=VALUES(owner_email), phone=VALUES(phone), password=VALUES(password), plan=VALUES(plan), db_name=VALUES(db_name), expiry_date=VALUES(expiry_date), allowed_modules=COALESCE(tenant_companies.allowed_modules, VALUES(allowed_modules))");
        $stmtMaster->execute([$companyName, $codeSlug, $ownerName, $ownerEmail, $phone, $pwdHash, $finalDbName, $plan, $expiryDate, $defaultModulesJson]);
        
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

// --------------------------------------------------------------------------
// 1.1 Batch Baileys Live Status Check Helper (Ultra-fast parallel query)
// --------------------------------------------------------------------------
function batchGetLiveBaileysStatus($clients) {
    if (empty($clients)) return [];
    
    $engineUrl = defined('WHATSAPP_ENGINE_URL') ? WHATSAPP_ENGINE_URL : 'http://140.238.167.58:3000';
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($clients as $c) {
        $uid = (int)($c['id'] ?? 0);
        if ($uid <= 0) continue;
        
        $ch = curl_init(rtrim($engineUrl, '/') . '/status?user_id=' . $uid);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_multi_add_handle($mh, $ch);
        $handles[$uid] = $ch;
    }
    
    if (empty($handles)) return [];
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
    } while ($running > 0);
    
    $results = [];
    foreach ($handles as $uid => $ch) {
        $content = curl_multi_getcontent($ch);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $results[$uid] = $data;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    return $results;
}

// --------------------------------------------------------------------------
// 1.2 Tenant WhatsApp Details Helper (Exact Real-Time Status & Gateway Details)
// --------------------------------------------------------------------------
function getTenantWabaDetails($pdo_master, $tenant, $liveBaileysStatuses = []) {
    global $db_host, $db_port, $db_user, $db_pass;
    $tenantId = (int)($tenant['id'] ?? 0);
    $companyCode = $tenant['company_code'] ?? '';
    $dbName = $tenant['db_name'] ?? '';
    
    $settings = null;
    
    // For master company (user_id = 1 or company_code = 'master'), strictly query master user 1 record
    if ($tenantId === 1 || $companyCode === 'master' || $dbName === 'u978772385_friendlyaidata') {
        if ($pdo_master) {
            try {
                $stmt = $pdo_master->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = 1 LIMIT 1");
                $stmt->execute();
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}
        }
    } else {
        // 1. Try tenant DB / table prefix first (where live tenant sessions save their connection)
        if (!empty($dbName) && $pdo_master) {
            try {
                if (strpos($dbName, 't_') === 0) {
                    $tbl = "{$dbName}merchant_waba_settings";
                    $stmtT = $pdo_master->prepare("SELECT * FROM `{$tbl}` WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtT->execute([$tenantId]);
                    $tenantSettings = $stmtT->fetch(PDO::FETCH_ASSOC);
                    if (!$tenantSettings) {
                        $stmtT2 = $pdo_master->query("SELECT * FROM `{$tbl}` ORDER BY id DESC LIMIT 1");
                        $tenantSettings = $stmtT2->fetch(PDO::FETCH_ASSOC);
                    }
                    if ($tenantSettings) {
                        $settings = $tenantSettings;
                    }
                } else {
                    $tDsn = "mysql:host=$db_host;port=$db_port;dbname={$dbName};charset=utf8mb4";
                    $tPdo = new PDO($tDsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $stmtT = $tPdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                    $stmtT->execute([$tenantId]);
                    $tenantSettings = $stmtT->fetch(PDO::FETCH_ASSOC);
                    if ($tenantSettings) {
                        $settings = $tenantSettings;
                    }
                }
            } catch (PDOException $e) {}
        }

        // 2. Fallback to master merchant_waba_settings by user_id
        if (!$settings && $pdo_master && $tenantId > 0) {
            try {
                $stmt = $pdo_master->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ? LIMIT 1");
                $stmt->execute([$tenantId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {}
        }
    }

    // 3. Check tenant_whatsapp_configs in master
    $metaConfig = null;
    if ($pdo_master && $tenantId > 0) {
        try {
            $stmtTwc = $pdo_master->prepare("SELECT * FROM tenant_whatsapp_configs WHERE user_id = ? OR LOWER(firm_name) LIKE ? ORDER BY id DESC LIMIT 1");
            $stmtTwc->execute([$tenantId, '%' . strtolower($companyCode) . '%']);
            $metaConfig = $stmtTwc->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
    }

    // Ensure API Key exists
    $apiKey = !empty($settings['tenant_api_key']) ? $settings['tenant_api_key'] : '';
    if (empty($apiKey)) {
        $apiKey = 'MARG-WABA-' . strtoupper(substr(md5('tenant_' . $tenantId . '_' . $companyCode), 0, 16));
        if ($pdo_master && $tenantId > 0) {
            try {
                $stmtIns = $pdo_master->prepare("INSERT INTO merchant_waba_settings (user_id, tenant_api_key, webhook_verify_token, gateway_type, web_api_session_status) VALUES (?, ?, ?, 'web_api', 'disconnected') ON DUPLICATE KEY UPDATE tenant_api_key = VALUES(tenant_api_key)");
                $stmtIns->execute([$tenantId, $apiKey, bin2hex(random_bytes(8))]);
                if (!$settings) $settings = [];
                $settings['tenant_api_key'] = $apiKey;
            } catch (PDOException $e) {}
        }
    }

    // Gateway type determination
    $has_meta_creds = (!empty($settings['phone_number_id']) && !empty($settings['access_token'])) || (!empty($metaConfig['phone_number_id']) && !empty($metaConfig['access_token']));
    
    if ($tenantId === 1 || $companyCode === 'master') {
        $gateway_type = (!empty($settings['gateway_type'])) ? $settings['gateway_type'] : 'meta';
    } else {
        if (!empty($settings['gateway_type'])) {
            $gateway_type = $settings['gateway_type'];
        } elseif ($has_meta_creds) {
            $gateway_type = 'meta';
        } else {
            $gateway_type = 'web_api';
        }
    }

    // Real-Time Live Baileys Engine Status
    $baileysLive = $liveBaileysStatuses[$tenantId] ?? null;
    $engine_status = $baileysLive['status'] ?? null;
    $engine_phone = $baileysLive['phone_number'] ?? ($baileysLive['phone'] ?? '');

    $is_connected = false;
    $session_state = 'not_paired'; // 'connected', 'logged_out', 'not_paired', 'meta_connected', 'not_configured'
    $phone = '';
    $last_phone = '';

    if ($gateway_type === 'web_api') {
        $db_saved_phone = !empty($settings['business_phone']) ? $settings['business_phone'] : '';
        $db_status = $settings['web_api_session_status'] ?? 'disconnected';

        if ($engine_status === 'connected' && !empty($engine_phone)) {
            // Live Baileys session is active and verified
            $is_connected = true;
            $session_state = 'connected';
            $phone = '+' . ltrim($engine_phone, '+');
        } elseif ($engine_status === 'scan_qr' || $engine_status === 'disconnected') {
            // Baileys server reported scan_qr or disconnected
            if ($db_status === 'connected' || !empty($db_saved_phone)) {
                // Previously had a connected phone, but user logged out from WhatsApp on mobile or session expired!
                $is_connected = false;
                $session_state = 'logged_out';
                $last_phone = $db_saved_phone;
                $phone = '';
            } else {
                // Fresh client, not yet paired
                $is_connected = false;
                $session_state = 'not_paired';
                $phone = '';
            }
        } else {
            // Engine unreachable / offline fallback to DB
            if ($db_status === 'connected' && !empty($db_saved_phone)) {
                $is_connected = true;
                $session_state = 'connected';
                $phone = $db_saved_phone;
            } else {
                $is_connected = false;
                $session_state = !empty($db_saved_phone) ? 'logged_out' : 'not_paired';
                $last_phone = $db_saved_phone;
            }
        }
    } else {
        // Meta Cloud WABA
        if ($has_meta_creds) {
            $is_connected = true;
            $session_state = 'meta_connected';
            if (!empty($settings['business_phone'])) {
                $phone = $settings['business_phone'];
            } elseif (!empty($metaConfig['display_phone_number'])) {
                $phone = $metaConfig['display_phone_number'];
            }
        } else {
            $is_connected = false;
            $session_state = 'not_configured';
        }
    }

    return [
        'api_key'                => $apiKey,
        'gateway_type'           => $gateway_type,
        'is_connected'           => $is_connected,
        'session_state'          => $session_state,
        'phone'                  => $phone,
        'last_phone'             => $last_phone,
        'engine_status'          => $engine_status,
        'phone_number_id'        => $settings['phone_number_id'] ?? ($metaConfig['phone_number_id'] ?? ''),
        'waba_id'                => $settings['waba_id'] ?? ($metaConfig['waba_id'] ?? ''),
        'access_token'           => $settings['access_token'] ?? ($metaConfig['access_token'] ?? ''),
        'web_api_url'            => $settings['web_api_url'] ?? '',
        'web_api_token'          => $settings['web_api_token'] ?? '',
        'web_api_instance_id'    => $settings['web_api_instance_id'] ?? '',
        'web_api_session_status' => $settings['web_api_session_status'] ?? 'disconnected'
    ];
}

// --------------------------------------------------------------------------
// 2. Action Handlers (Create, Edit, Suspend, Impersonate, Delete, Test, Config)
// --------------------------------------------------------------------------
$flash_msg = '';
$flash_type = '';

// Download config.json directly via GET
if (isset($_GET['action']) && $_GET['action'] === 'download_config' && isset($_GET['tenant_id']) && isset($pdo_master)) {
    $tenantId = intval($_GET['tenant_id']);
    $stmtGetT = $pdo_master->prepare("SELECT * FROM tenant_companies WHERE id = ?");
    $stmtGetT->execute([$tenantId]);
    $tRec = $stmtGetT->fetch(PDO::FETCH_ASSOC);
    if ($tRec) {
        $wDetails = getTenantWabaDetails($pdo_master, $tRec);
        $configData = [
            'api_key' => $wDetails['api_key']
        ];
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="config.json"');
        echo json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // A. Create New CRM Client
    if ($_POST['action'] === 'create_crm_client') {
        $cName = trim($_POST['company_name'] ?? '');
        $cCode = trim($_POST['company_code'] ?? '');
        $oName = trim($_POST['owner_name'] ?? '');
        $oEmail = trim($_POST['owner_email'] ?? '');
        $cPhone = trim($_POST['phone'] ?? '');
        $cPlan = $_POST['plan'] ?? 'Silver';
        $cPwd = $_POST['password'] ?? 'client123';
        $cDuration = intval($_POST['duration_months'] ?? 12);
        
        if (!empty($cName) && !empty($cCode) && !empty($oEmail)) {
            $provRes = provisionNewCrmClient($pdo_master, $cCode, $cName, $oName, $oEmail, $cPhone, $cPlan, $cPwd, $cDuration);
            $flash_msg = $provRes['message'];
            $flash_type = $provRes['success'] ? 'success' : 'danger';
        } else {
            $flash_msg = "Please fill in Company Name, Code, and Owner Email.";
            $flash_type = 'danger';
        }
    }
    // C. Register Already ERP User
    elseif ($_POST['action'] === 'register_erp_user') {
        $ownerEmail = trim($_POST['owner_email'] ?? '');
        $cPhone = trim($_POST['phone'] ?? '');
        $licenseNo = trim($_POST['marg_license_no'] ?? '');
        $cName = trim($_POST['company_name'] ?? '');
        $cPwd = $_POST['password'] ?? 'marg123';
        $cPlan = $_POST['plan'] ?? 'Silver';
        
        if (!empty($ownerEmail) && !empty($licenseNo)) {
            $codeSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $licenseNo));
            if (empty($codeSlug)) $codeSlug = 'erp_' . time();
            $companyName = !empty($cName) ? $cName : ("ERP License " . $licenseNo);
            
            $provRes = provisionNewCrmClient($pdo_master, $codeSlug, $companyName, $companyName, $ownerEmail, $cPhone, $cPlan, $cPwd, 12);
            if ($provRes['success']) {
                try {
                    $stmtUpLic = $pdo_master->prepare("UPDATE tenant_companies SET phone = ? WHERE company_code = ?");
                    $stmtUpLic->execute([$cPhone, $codeSlug]);
                } catch (\PDOException $e) {}
                
                $flash_msg = "🎉 Marg ERP User registered successfully! Email: {$ownerEmail} | License: {$licenseNo}. Client can now log in and connect Meta WABA!";
                $flash_type = 'success';
            } else {
                $flash_msg = $provRes['message'];
                $flash_type = 'danger';
            }
        } else {
            $flash_msg = "Please enter Owner Email and Marg ERP License Number.";
            $flash_type = 'danger';
        }
    }
    // B. Edit Client Plan / Expiry / Status / Details
    elseif ($_POST['action'] === 'update_client_status') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $cName = trim($_POST['company_name'] ?? '');
        $oName = trim($_POST['owner_name'] ?? '');
        $oEmail = strtolower(trim($_POST['owner_email'] ?? ''));
        $cPhone = trim($_POST['phone'] ?? '');
        $uPlan = $_POST['plan'] ?? 'Silver';
        $uStatus = $_POST['status'] ?? 'Active';
        $uExpiry = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        
        if ($tenantId > 0 && isset($pdo_master)) {
            try {
                $stmtU = $pdo_master->prepare("UPDATE tenant_companies SET company_name = COALESCE(NULLIF(?, ''), company_name), owner_name = COALESCE(NULLIF(?, ''), owner_name), owner_email = COALESCE(NULLIF(?, ''), owner_email), phone = COALESCE(NULLIF(?, ''), phone), plan = ?, status = ?, expiry_date = ? WHERE id = ?");
                $stmtU->execute([$cName, $oName, $oEmail, $cPhone, $uPlan, $uStatus, $uExpiry, $tenantId]);

                if (!empty($oEmail)) {
                    $pdo_master->prepare("UPDATE users SET name = COALESCE(NULLIF(?, ''), name), status = ? WHERE LOWER(email) = LOWER(?)")->execute([$oName, $uStatus, $oEmail]);
                }

                $flash_msg = "CRM Client settings & profile details updated successfully!";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_msg = "Error updating client: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
    // C. Toggle Suspend / Activate
    elseif ($_POST['action'] === 'toggle_client_suspend') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $curStatus = $_POST['current_status'] ?? 'Active';
        $newStatus = ($curStatus === 'Active') ? 'Suspended' : 'Active';
        
        if ($tenantId > 0 && isset($pdo_master)) {
            try {
                $stmtT = $pdo_master->prepare("UPDATE tenant_companies SET status = ? WHERE id = ?");
                $stmtT->execute([$newStatus, $tenantId]);
                $flash_msg = "CRM Client status changed to \"{$newStatus}\".";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_msg = "Error toggling status: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
    // D. Delete CRM Client Data
    elseif ($_POST['action'] === 'delete_crm_client') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        if ($tenantId > 1 && isset($pdo_master)) { // Cannot delete primary master tenant
            try {
                $stmtGet = $pdo_master->prepare("SELECT * FROM tenant_companies WHERE id = ?");
                $stmtGet->execute([$tenantId]);
                $tRec = $stmtGet->fetch();
                if ($tRec) {
                    $targetDb = $tRec['db_name'];
                    $masterDb = defined('DB_NAME') ? DB_NAME : '';
                    $dbDropped = false;

                    // Safely attempt to drop tenant database or table prefix tables
                    if (!empty($targetDb) && $targetDb !== $masterDb) {
                        if (strpos($targetDb, 't_') === 0) {
                            // Drop isolated client tables t_code_... inside master DB
                            $tablesToDrop = [
                                'users', 'leads', 'timeline', 'followups', 'demos', 
                                'quotations', 'payments', 'bank_accounts', 'installations', 
                                'trainings', 'tickets', 'client_directory', 'message_logs', 
                                'chat_conversations', 'merchant_waba_settings', 'bot_flows'
                            ];
                            foreach ($tablesToDrop as $tbl) {
                                try {
                                    $pdo_master->exec("DROP TABLE IF EXISTS `{$targetDb}{$tbl}`");
                                } catch (PDOException $e) {}
                            }
                            $dbDropped = true;
                        } else {
                            try {
                                $pdo_master->exec("DROP DATABASE IF EXISTS `{$targetDb}`");
                                $dbDropped = true;
                            } catch (PDOException $dropEx) {
                                // Catch Error 1044 / Access denied gracefully on shared hosting (Hostinger)
                                $dbDropped = false;
                            }
                        }
                    }

                    // Delete master tenant company record
                    $delStmt = $pdo_master->prepare("DELETE FROM tenant_companies WHERE id = ?");
                    $delStmt->execute([$tenantId]);

                    $flash_msg = "CRM Client \"{$tRec['company_name']}\" deleted permanently from CRM." . ($dbDropped ? " Database \"{$targetDb}\" dropped." : "");
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error deleting CRM Client: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
    // E. Update Client Power & Page Access Permissions
    // E. Update Client Power & Page Access Permissions
    elseif ($_POST['action'] === 'update_client_permissions') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $modulesArr = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : [];

        // If either workspace_dashboard or whatsapp_dashboard is checked, also preserve 'dashboard' for fallback
        if (in_array('workspace_dashboard', $modulesArr) || in_array('whatsapp_dashboard', $modulesArr)) {
            if (!in_array('dashboard', $modulesArr)) {
                $modulesArr[] = 'dashboard';
            }
        }

        // Auto-sync alias keys for WhatsApp settings and bot flows
        if (in_array('whatsapp_settings', $modulesArr) && !in_array('merchant_waba_settings', $modulesArr)) {
            $modulesArr[] = 'merchant_waba_settings';
        }
        if (in_array('merchant_waba_settings', $modulesArr) && !in_array('whatsapp_settings', $modulesArr)) {
            $modulesArr[] = 'whatsapp_settings';
        }
        if (in_array('whatsapp_flows', $modulesArr) && !in_array('bot_flows', $modulesArr)) {
            $modulesArr[] = 'bot_flows';
        }
        if (in_array('bot_flows', $modulesArr) && !in_array('whatsapp_flows', $modulesArr)) {
            $modulesArr[] = 'whatsapp_flows';
        }
        if (in_array('broadcast_campaigns', $modulesArr) && !in_array('bulk_broadcast', $modulesArr)) {
            $modulesArr[] = 'bulk_broadcast';
        }
        if (in_array('bulk_broadcast', $modulesArr) && !in_array('broadcast_campaigns', $modulesArr)) {
            $modulesArr[] = 'broadcast_campaigns';
        }

        $cleanModules = array_values(array_unique($modulesArr));
        $modulesJson = json_encode($cleanModules);
        
        if ($tenantId > 0 && isset($pdo_master)) {
            try {
                $stmtPerm = $pdo_master->prepare("UPDATE tenant_companies SET allowed_modules = ? WHERE id = ?");
                $stmtPerm->execute([$modulesJson, $tenantId]);

                // Synchronize with tenant DB or tenant prefix user permissions
                $stmtGetT = $pdo_master->prepare("SELECT * FROM tenant_companies WHERE id = ?");
                $stmtGetT->execute([$tenantId]);
                $tComp = $stmtGetT->fetch(PDO::FETCH_ASSOC);
                if ($tComp && !empty($tComp['db_name'])) {
                    if (strpos($tComp['db_name'], 't_') === 0) {
                        try {
                            $userTbl = $tComp['db_name'] . 'users';
                            $stmtUpdUser = $pdo_master->prepare("UPDATE `{$userTbl}` SET permissions = ? WHERE role = 'Admin'");
                            $stmtUpdUser->execute([$modulesJson]);
                        } catch (PDOException $tEx) {}
                    } else {
                        try {
                            $tDsn = "mysql:host=$db_host;port=$db_port;dbname={$tComp['db_name']};charset=utf8mb4";
                            $tPdo = new PDO($tDsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                            $stmtUpdUser = $tPdo->prepare("UPDATE users SET permissions = ? WHERE role = 'Admin'");
                            $stmtUpdUser->execute([$modulesJson]);
                        } catch (PDOException $tEx) {}
                    }
                }

                // Clear session cache so permissions take effect instantly
                unset($_SESSION['tenant_allowed_modules']);
                unset($_SESSION['tenant_allowed_db']);
                unset($_SESSION['user_permissions']);

                // If currently impersonating this client, update session modules immediately
                if (!empty($_SESSION['impersonate_tenant_id']) && (int)$_SESSION['impersonate_tenant_id'] === $tenantId) {
                    $_SESSION['tenant_allowed_modules'] = $cleanModules;
                }

                $flash_msg = "Client Power & Page Access permissions updated successfully!";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_msg = "Error updating permissions: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
    // F. Super Admin Test Dispatch for Client WhatsApp
    elseif ($_POST['action'] === 'test_client_waba_dispatch') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $testMobile = trim($_POST['test_mobile'] ?? '');
        $testBillNo = trim($_POST['test_bill_no'] ?? ('INV-TEST-' . time()));
        
        $phoneDigits = preg_replace('/\D/', '', $testMobile);
        if (strlen($phoneDigits) === 10) $phoneDigits = '91' . $phoneDigits;

        if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
            $flash_msg = "Please enter a valid 10-digit mobile number for test dispatch.";
            $flash_type = "warning";
        } else {
            $stmtGetT = $pdo_master->prepare("SELECT * FROM tenant_companies WHERE id = ?");
            $stmtGetT->execute([$tenantId]);
            $tenantObj = $stmtGetT->fetch(PDO::FETCH_ASSOC);

            if ($tenantObj) {
                $wabaDetails = getTenantWabaDetails($pdo_master, $tenantObj);
                
                if ($wabaDetails['gateway_type'] === 'web_api') {
                    // Send via WhatsApp Web API Engine
                    $webUrl = !empty($wabaDetails['web_api_url']) ? rtrim($wabaDetails['web_api_url'], '/') : ((defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://friendlyaisolution.com') . '/api/whatsapp_web_engine.php');
                    $endpoint = (strpos($webUrl, 'action=') !== false) 
                        ? $webUrl . '&action=send_message' 
                        : ((strpos($webUrl, '.php') !== false) ? ($webUrl . '?action=send_message') : (rtrim($webUrl, '/') . '/send-message'));

                    $postFields = [
                        'action'    => 'send_message',
                        'user_id'   => $tenantId,
                        'recipient' => $phoneDigits,
                        'phone'     => $phoneDigits,
                        'message'   => "🎉 Marg ERP 9+ WhatsApp Web Test for {$tenantObj['company_name']}!\nBill No: {$testBillNo}\nGateway: Self-Hosted Web API.",
                        'token'     => $wabaDetails['web_api_token'] ?? '',
                        'instance'  => $wabaDetails['web_api_instance_id'] ?? ''
                    ];

                    $ch = curl_init($endpoint);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . ($wabaDetails['web_api_token'] ?? '')]);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postFields));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                    $resRaw = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $resJson = json_decode($resRaw, true) ?? [];

                    $isSuccess = false;
                    if (!empty($resJson['status']) && strtolower($resJson['status']) === 'success') $isSuccess = true;
                    if (!empty($resJson['success']) && $resJson['success'] === true) $isSuccess = true;

                    if ($isSuccess) {
                        $flash_msg = "🎉 Test message successfully sent via WhatsApp to {$phoneDigits} for client \"{$tenantObj['company_name']}\"!";
                        $flash_type = "success";
                    } else {
                        $errDetail = !empty($resJson['message']) ? $resJson['message'] : ($resRaw ?: ('HTTP ' . $httpCode));
                        $flash_msg = "⚠️ WhatsApp Web Error: " . $errDetail;
                        $flash_type = "danger";
                    }
                } else {
                    // Send via Meta Cloud API or Gateway Webhook
                    $phone_number_id = $wabaDetails['phone_number_id'];
                    $access_token = $wabaDetails['access_token'];

                    if (empty($phone_number_id) || empty($access_token)) {
                        // Attempt dispatch via marg_erp_gateway endpoint with tenant API Key
                        $base_gateway = defined('BASE_URL') ? BASE_URL : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/');
                        $gatewayUrl = rtrim($base_gateway, '/') . '/api/marg_erp_gateway.php?api_key=' . urlencode($wabaDetails['api_key']) . '&mob=' . urlencode($phoneDigits) . '&msg=' . urlencode("Marg ERP Test for " . $tenantObj['company_name']) . '&bill_no=' . urlencode($testBillNo);

                        $ch = curl_init($gatewayUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        $resRaw = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        $resJson = json_decode($resRaw, true) ?? [];

                        if ($httpCode === 200 || (!empty($resJson['status']) && $resJson['status'] === 'success')) {
                            $flash_msg = "🎉 Test message dispatched to {$phoneDigits} via Gateway Webhook for \"{$tenantObj['company_name']}\"!";
                            $flash_type = "success";
                        } else {
                            $flash_msg = "Meta Cloud API credentials missing for client \"{$tenantObj['company_name']}\". Please configure Phone ID & Token.";
                            $flash_type = "warning";
                        }
                    } else {
                        $metaUrl = "https://graph.facebook.com/v20.0/{$phone_number_id}/messages";
                        $samplePdf = 'https://friendlyaisolution.com/uploads/invoices/Marg_GUI_Invoice_BILL001.pdf';
                        $payload = [
                            'messaging_product' => 'whatsapp',
                            'to'                => $phoneDigits,
                            'type'              => 'template',
                            'template'          => [
                                'name'     => 'marg_bill',
                                'language' => ['code' => 'en'],
                                'components' => [
                                    [
                                        'type' => 'header',
                                        'parameters' => [
                                            [
                                                'type' => 'document',
                                                'document' => [
                                                    'link' => $samplePdf,
                                                    'filename' => "Invoice.pdf"
                                                ]
                                            ]
                                        ]
                                    ],
                                    [
                                        'type' => 'body',
                                        'parameters' => [
                                            ['type' => 'text', 'text' => $tenantObj['company_name']],
                                            ['type' => 'text', 'text' => 'Valued Customer'],
                                            ['type' => 'text', 'text' => $testBillNo],
                                            ['type' => 'text', 'text' => '14500.00'],
                                            ['type' => 'text', 'text' => '0.00'],
                                            ['type' => 'text', 'text' => 'UPI@OKBANK'],
                                            ['type' => 'text', 'text' => 'BANK'],
                                            ['type' => 'text', 'text' => '123456789'],
                                            ['type' => 'text', 'text' => 'BRANCH'],
                                            ['type' => 'text', 'text' => 'IFSC001'],
                                            ['type' => 'text', 'text' => $tenantObj['company_name']],
                                            ['type' => 'text', 'text' => $wabaDetails['phone'] ?: '+91 92773 87778'],
                                            ['type' => 'text', 'text' => $samplePdf]
                                        ]
                                    ]
                                ]
                            ]
                        ];

                        $ch = curl_init($metaUrl);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json']);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                        $resRaw = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        $resJson = json_decode($resRaw, true) ?? [];

                        if ($httpCode === 200 && isset($resJson['messages'][0]['id'])) {
                            $flash_msg = "🎉 Test Meta Cloud API Message sent to {$phoneDigits} for {$tenantObj['company_name']}! (Msg ID: {$resJson['messages'][0]['id']})";
                            $flash_type = "success";
                        } else {
                            $errDetail = $resJson['error']['message'] ?? json_encode($resJson);
                            $flash_msg = "❌ Meta Test Dispatch Failed: " . $errDetail;
                            $flash_type = "danger";
                        }
                    }
                }
            } else {
                $flash_msg = "Client not found.";
                $flash_type = "danger";
            }
        }
    }
}

// --------------------------------------------------------------------------
// 3. Fetch Master CRM Clients List & SaaS Metrics
// --------------------------------------------------------------------------
$clients = [];
$total_clients = 0;
$active_clients = 0;
$suspended_clients = 0;
$mrr_total = 0;

if (isset($pdo_master)) {
    try {
        $stmtC = $pdo_master->query("SELECT * FROM tenant_companies ORDER BY id ASC");
        $clients = $stmtC->fetchAll(PDO::FETCH_ASSOC);
        $liveBaileysStatuses = batchGetLiveBaileysStatus($clients);
        
        $total_clients = count($clients);
        foreach ($clients as $c) {
            if ($c['status'] === 'Active') $active_clients++;
            if ($c['status'] === 'Suspended' || $c['status'] === 'Expired') $suspended_clients++;
            
            // Calculate MRR estimate based on subscription plan
            $plan_fee = 0;
            if ($c['plan'] === 'Basic') $plan_fee = 1999;
            elseif ($c['plan'] === 'Silver') $plan_fee = 4999;
            elseif ($c['plan'] === 'Gold') $plan_fee = 9999;
            elseif ($c['plan'] === 'Enterprise') $plan_fee = 24999;
            
            if ($c['status'] === 'Active') {
                $mrr_total += $plan_fee;
            }
        }
    } catch (PDOException $e) {
        $clients = [];
    }
}
?>

<div class="crm-clients-container">
    <style>
        .status-pill {
            font-size: 0.725rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
            letter-spacing: 0.02em;
        }
        .status-pill-connected {
            background: rgba(16, 185, 129, 0.14);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }
        .status-pill-loggedout {
            background: rgba(239, 68, 68, 0.14);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.35);
        }
        .status-pill-notpaired {
            background: rgba(245, 158, 11, 0.14);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.35);
        }
        .pulse-dot-green {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            box-shadow: 0 0 6px rgba(16, 185, 129, 0.8);
            animation: pulseGreen 1.8s infinite;
        }
        @keyframes pulseGreen {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .client-row:hover {
            background: rgba(59, 130, 246, 0.02) !important;
        }
    </style>
    <!-- Super Admin Impersonation Alert Banner -->
    <?php if (isset($_SESSION['impersonate_tenant_db']) && !empty($_SESSION['impersonate_tenant_db'])): ?>
        <div class="p-4 mb-6 flex align-center justify-between" style="background: rgba(245, 124, 0, 0.15); border: 2px dashed #f57c00; border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-3">
                <i data-lucide="eye" style="width: 24px; height: 24px; color: #f57c00;"></i>
                <div>
                    <span class="text-sm font-bold block" style="color: #f57c00;">Currently Impersonating Client Instance</span>
                    <span class="text-xs text-muted">You are viewing database <strong><?php echo htmlspecialchars($_SESSION['impersonate_tenant_db']); ?></strong> (<?php echo htmlspecialchars($_SESSION['impersonate_company_name'] ?? ''); ?>). All CRM modifications apply only to this client.</span>
                </div>
            </div>
            <a href="index.php?action=stop_impersonation" class="btn btn-primary text-xs flex align-center gap-2" style="background-color: #f57c00; border: none;">
                <i data-lucide="log-out" style="width: 14px; height: 14px;"></i>
                <span>Exit Client View</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Flash Message -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> mb-6 p-4 border-radius-md flex align-center gap-3" style="background: var(--<?php echo $flash_type; ?>-light); border: 1px solid var(--<?php echo $flash_type; ?>); color: var(--<?php echo $flash_type; ?>);">
            <i data-lucide="info" style="width: 20px; height: 20px;"></i>
            <span class="text-sm font-semibold"><?php echo htmlspecialchars($flash_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Header & Action Button -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">CRM Clients & SaaS Multi-Tenancy</h2>
            <p class="text-muted text-sm">Provision dedicated CRM accounts, manage client subscription plans, enforce data isolation, and impersonate tenant workspaces.</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <button type="button" class="btn btn-primary text-sm flex align-center gap-2" style="padding: 0.65rem 1.25rem;" onclick="window.openModal('create-crm-client-modal')">
                <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                <span>Register New CRM Client</span>
            </button>
            <button type="button" class="btn btn-success text-sm flex align-center gap-2" style="padding: 0.65rem 1.25rem; background: #10b981; border: none; color: white;" onclick="window.openModal('register-erp-user-modal')">
                <i data-lucide="user-check" style="width: 16px; height: 16px;"></i>
                <span>Already ERP User</span>
            </button>
        </div>
    </div>

    <!-- SaaS KPI Summary Cards -->
    <div class="grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(59, 130, 246, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="building-2" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Total SaaS Clients</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading);"><?php echo $total_clients; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(52, 211, 153, 0.12); color: var(--success); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="shield-check" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Active Subscriptions</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--success);"><?php echo $active_clients; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(245, 124, 0, 0.12); color: #f57c00; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="alert-triangle" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Suspended / Expired</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: #f57c00;"><?php echo $suspended_clients; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(124, 58, 237, 0.12); color: var(--accent); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="badge-indian-rupee" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Estimated MRR</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--accent);">₹<?php echo number_format($mrr_total, 0); ?></span>
            </div>
        </div>
    </div>

    <!-- CRM Clients Directory Table -->
    <div class="card p-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
        <div class="flex justify-between align-center mb-4 flex-wrap gap-2">
            <h4 class="text-sm font-bold" style="text-transform: uppercase; letter-spacing: 0.05em;">Registered CRM SaaS Clients</h4>
            <span class="text-xs text-muted">Each client instance runs with dedicated database data isolation</span>
        </div>

        <div class="table-responsive">
            <table class="w-full text-left" style="border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); background: var(--border-card);">
                        <th class="p-3 text-xs font-bold text-muted">ID &amp; COMPANY</th>
                        <th class="p-3 text-xs font-bold text-muted">OWNER / EMAIL</th>
                        <th class="p-3 text-xs font-bold text-muted">WHATSAPP GATEWAY &amp; STATUS</th>
                        <th class="p-3 text-xs font-bold text-muted">STORAGE MODE</th>
                        <th class="p-3 text-xs font-bold text-muted">PLAN</th>
                        <th class="p-3 text-xs font-bold text-muted">STATUS</th>
                        <th class="p-3 text-xs font-bold text-muted">EXPIRY DATE</th>
                        <th class="p-3 text-xs font-bold text-muted text-right">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-6 text-muted text-sm">No CRM Clients registered yet. Click "Register New CRM Client" to provision an isolated account.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $cl): 
                            $status_class = ($cl['status'] === 'Active') ? 'success' : (($cl['status'] === 'Suspended') ? 'danger' : 'warning');
                            $plan_class = ($cl['plan'] === 'Enterprise') ? 'accent' : (($cl['plan'] === 'Gold') ? 'primary' : 'secondary');

                            $default_all = ["workspace_dashboard","whatsapp_dashboard","dashboard","leads","clients","customer_kyc","pipeline","followups","demo","quotation","payments","bank_accounts","installation","training","support","renewals","team_inbox","merchant_waba_settings","whatsapp_settings","whatsapp_flows","bot_flows","broadcast_campaigns","bulk_broadcast","reports","settings"];
                            $allowed_modules = !empty($cl['allowed_modules']) ? json_decode($cl['allowed_modules'], true) : $default_all;
                            if (!is_array($allowed_modules)) $allowed_modules = $default_all;

                            // Fetch live stats from tenant DB safely
                            $tenant_users_cnt = 0;
                            $tenant_leads_cnt = 0;
                            if ($cl['id'] == 1 || $cl['company_code'] === 'master') {
                                try {
                                    $tenant_users_cnt = (int)$pdo_master->query("SELECT COUNT(*) FROM users")->fetchColumn();
                                    $tenant_leads_cnt = (int)$pdo_master->query("SELECT COUNT(*) FROM leads")->fetchColumn();
                                } catch (Exception $ex) {}
                            } elseif (!empty($cl['db_name']) && strpos($cl['db_name'], 't_') === 0) {
                                try {
                                    $uCnt = (int)$pdo_master->query("SELECT COUNT(*) FROM `{$cl['db_name']}users`")->fetchColumn();
                                    $tenant_users_cnt = ($uCnt > 0) ? $uCnt : 1;
                                } catch (Exception $ex) { $tenant_users_cnt = 1; }
                                try {
                                    $tenant_leads_cnt = (int)$pdo_master->query("SELECT COUNT(*) FROM `{$cl['db_name']}leads`")->fetchColumn();
                                } catch (Exception $ex) { $tenant_leads_cnt = 0; }
                            } else {
                                try {
                                    $tDsnInst = "mysql:host=$db_host;port=$db_port;dbname={$cl['db_name']};charset=utf8mb4";
                                    $tPdoInst = new PDO($tDsnInst, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                                    $tenant_users_cnt = (int)$tPdoInst->query("SELECT COUNT(*) FROM users")->fetchColumn();
                                    $tenant_leads_cnt = (int)$tPdoInst->query("SELECT COUNT(*) FROM leads")->fetchColumn();
                                } catch (PDOException $ex) {
                                    $tenant_users_cnt = 1;
                                    $tenant_leads_cnt = 0;
                                }
                            }

                            // Fetch Tenant WhatsApp Configuration & Dispatch Stats with Real-Time Baileys check
                            $wInfo = getTenantWabaDetails($pdo_master, $cl, $liveBaileysStatuses);
                            $tenant_msgs_today = 0;
                            $tenant_msgs_month = 0;
                            try {
                                $stmtTLog = $pdo_master->prepare("SELECT 
                                    SUM(CASE WHEN DATE(created_at) = CURRENT_DATE() THEN 1 ELSE 0 END) as today_cnt,
                                    SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as month_cnt
                                    FROM marg_erp_logs WHERE user_id = ? OR tenant_api_key = ?");
                                $stmtTLog->execute([$cl['id'], $wInfo['api_key']]);
                                $logCounts = $stmtTLog->fetch(PDO::FETCH_ASSOC);
                                $tenant_msgs_today = (int)($logCounts['today_cnt'] ?? 0);
                                $tenant_msgs_month = (int)($logCounts['month_cnt'] ?? 0);
                            } catch (PDOException $ex) {}
                        ?>
                            <tr class="client-row" style="border-bottom: 1px solid var(--border-color); transition: background 0.15s ease;">
                                <td class="p-3">
                                    <div class="flex align-center gap-3">
                                        <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(99, 102, 241, 0.22)); color: var(--primary); font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; border: 1px solid rgba(59, 130, 246, 0.25);">
                                            <?php echo strtoupper(substr($cl['company_code'], 0, 2)); ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold" style="color: var(--text-main); line-height: 1.25;"><?php echo htmlspecialchars($cl['company_name']); ?></span>
                                            <div class="flex align-center gap-2 mt-1">
                                                <span class="text-xs text-muted font-mono" style="font-size: 0.7rem; background: var(--bg-body); padding: 1px 5px; border-radius: 4px; border: 1px solid var(--border-color);">
                                                    code: <strong><?php echo htmlspecialchars($cl['company_code']); ?></strong>
                                                </span>
                                                <span class="text-xs text-muted" style="font-size: 0.68rem;">ID: #<?php echo $cl['id']; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-xs font-bold" style="color: var(--text-main);"><?php echo htmlspecialchars($cl['owner_name']); ?></span>
                                        <a href="mailto:<?php echo htmlspecialchars($cl['owner_email']); ?>" class="text-xs text-primary" style="font-size: 0.725rem; text-decoration: none; word-break: break-all;">
                                            <i data-lucide="mail" style="width: 10px; height: 10px; display: inline-block; vertical-align: middle;"></i>
                                            <?php echo htmlspecialchars($cl['owner_email']); ?>
                                        </a>
                                        <?php if (!empty($cl['phone'])): ?>
                                            <span class="text-xs text-muted" style="font-size: 0.68rem; margin-top: 2px;">
                                                📞 ERP: <code><?php echo htmlspecialchars($cl['phone']); ?></code>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <!-- Real-Time WhatsApp Gateway & Session Status Column -->
                                <td class="p-3">
                                    <div class="flex flex-col gap-1">
                                        <?php if ($wInfo['session_state'] === 'meta_connected'): ?>
                                            <!-- Meta Cloud API Active -->
                                            <div class="flex align-center gap-2 flex-wrap">
                                                <span class="status-pill status-pill-connected">
                                                    <span class="pulse-dot-green"></span> Connected
                                                </span>
                                                <span class="badge" style="background: rgba(99, 102, 241, 0.12); color: #6366f1; font-size: 0.68rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(99, 102, 241, 0.25);">
                                                    Meta Cloud WABA
                                                </span>
                                            </div>
                                            <div class="flex align-center gap-1 font-mono text-xs font-bold mt-0.5" style="color: var(--text-main);">
                                                <span>📱 <?php echo htmlspecialchars($wInfo['phone']); ?></span>
                                            </div>

                                        <?php elseif ($wInfo['session_state'] === 'connected'): ?>
                                            <!-- Self-Hosted Web API Live Connected -->
                                            <div class="flex align-center gap-2 flex-wrap">
                                                <span class="status-pill status-pill-connected">
                                                    <span class="pulse-dot-green"></span> Connected
                                                </span>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #059669; font-size: 0.68rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(16, 185, 129, 0.25);">
                                                    Web API (Baileys)
                                                </span>
                                            </div>
                                            <div class="flex align-center gap-1 font-mono text-xs font-bold mt-0.5" style="color: var(--text-main);">
                                                <span>📱 <?php echo htmlspecialchars($wInfo['phone']); ?></span>
                                            </div>

                                        <?php elseif ($wInfo['session_state'] === 'logged_out'): ?>
                                            <!-- Web API Logged Out from Phone -->
                                            <div class="flex align-center gap-2 flex-wrap">
                                                <span class="status-pill status-pill-loggedout" title="User logged out from WhatsApp on phone or Baileys session expired">
                                                    <i data-lucide="alert-triangle" style="width: 11px; height: 11px;"></i> Logged Out
                                                </span>
                                                <span class="badge" style="background: rgba(239, 68, 68, 0.08); color: #dc2626; font-size: 0.68rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(239, 68, 68, 0.25);">
                                                    Re-Scan Required
                                                </span>
                                            </div>
                                            <div class="text-xs mt-0.5" style="color: #ef4444; font-size: 0.72rem; font-weight: 600;">
                                                <?php if (!empty($wInfo['last_phone'])): ?>
                                                    <span>📱 <?php echo htmlspecialchars($wInfo['last_phone']); ?> <span style="font-weight: 400; color: var(--text-muted);">(Session Expired)</span></span>
                                                <?php else: ?>
                                                    <span>Session disconnected from phone</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-1">
                                                <button type="button" class="btn btn-xs" style="background: #2563eb; color: white; border: none; font-size: 0.7rem; padding: 3px 9px; border-radius: 4px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(37,99,235,0.3);" onclick="openQrPairModal(<?php echo $cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>', '<?php echo htmlspecialchars($wInfo['last_phone'] ?? ''); ?>')">
                                                    <i data-lucide="qr-code" style="width: 11px; height: 11px;"></i> Reconnect QR
                                                </button>
                                            </div>

                                        <?php elseif ($wInfo['session_state'] === 'not_paired'): ?>
                                            <!-- Web API Fresh Client / Never Paired -->
                                            <div class="flex align-center gap-2 flex-wrap">
                                                <span class="status-pill status-pill-notpaired">
                                                    <i data-lucide="scan" style="width: 11px; height: 11px;"></i> Not Paired
                                                </span>
                                                <span class="badge" style="background: rgba(100, 116, 139, 0.1); color: #64748b; font-size: 0.68rem; font-weight: 600; padding: 2px 6px; border-radius: 4px;">
                                                    Web API (Baileys)
                                                </span>
                                            </div>
                                            <span class="text-xs text-muted mt-0.5" style="font-size: 0.72rem; font-style: italic;">No WhatsApp Paired Yet</span>
                                            <div class="mt-1">
                                                <button type="button" class="btn btn-xs" style="background: #10b981; color: white; border: none; font-size: 0.7rem; padding: 3px 9px; border-radius: 4px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(16,185,129,0.3);" onclick="openQrPairModal(<?php echo $cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>', '')">
                                                    <i data-lucide="qr-code" style="width: 11px; height: 11px;"></i> Pair WhatsApp
                                                </button>
                                            </div>

                                        <?php else: ?>
                                            <!-- Meta Incomplete -->
                                            <div class="flex align-center gap-2 flex-wrap">
                                                <span class="status-pill" style="background: rgba(100, 116, 139, 0.14); color: #64748b; border: 1px solid rgba(100, 116, 139, 0.3);">
                                                    <i data-lucide="shield-off" style="width: 11px; height: 11px;"></i> Meta Pending
                                                </span>
                                            </div>
                                            <span class="text-xs text-muted mt-0.5" style="font-size: 0.72rem; font-style: italic;">Token / Phone ID Missing</span>
                                        <?php endif; ?>

                                        <!-- Tenant API Key 1-Click Copy -->
                                        <div class="flex align-center gap-1 mt-1">
                                            <span class="text-xs text-muted" style="font-size: 0.7rem;">Key:</span>
                                            <code style="font-size: 0.7rem; background: var(--bg-body); padding: 1px 6px; border-radius: 4px; color: var(--primary); font-family: monospace; cursor: pointer; border: 1px solid var(--border-color);" onclick="copyToClipboard('<?php echo htmlspecialchars($wInfo['api_key']); ?>', 'Tenant API Key')" title="Click to copy full API Key">
                                                <?php echo htmlspecialchars(substr($wInfo['api_key'], 0, 13)) . '...'; ?>
                                                <i data-lucide="copy" style="width: 10px; height: 10px; vertical-align: middle; display: inline-block;"></i>
                                            </code>
                                        </div>
                                    </div>
                                </td>
                                <!-- Storage Mode & Accurate Counts Column -->
                                <td class="p-3">
                                    <div class="flex flex-col gap-1">
                                        <div>
                                            <?php if ($cl['id'] == 1 || $cl['company_code'] === 'master'): ?>
                                                <span class="badge text-xs font-mono" style="background: rgba(59, 130, 246, 0.1); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.25); font-size: 0.7rem; padding: 2px 7px;">
                                                    <i data-lucide="database" style="width: 11px; height: 11px; margin-right: 3px; vertical-align: middle;"></i>
                                                    Master DB
                                                </span>
                                            <?php else: ?>
                                                <span class="badge text-xs font-mono" style="background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); font-size: 0.7rem; padding: 2px 7px;">
                                                    <i data-lucide="shield-check" style="width: 11px; height: 11px; margin-right: 3px; vertical-align: middle;"></i>
                                                    <?php echo htmlspecialchars($cl['db_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-muted" style="font-size: 0.725rem; font-weight: 500;">
                                            👥 Users: <strong style="color: var(--text-main);"><?php echo $tenant_users_cnt; ?></strong> | 📊 Leads: <strong style="color: var(--text-main);"><?php echo number_format($tenant_leads_cnt); ?></strong>
                                        </div>
                                        <div class="text-xs" style="font-size: 0.725rem; color: #10b981; font-weight: 600;">
                                            ✉️ Msgs Today: <strong><?php echo $tenant_msgs_today; ?></strong> | Mo: <strong><?php echo $tenant_msgs_month; ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <span class="badge text-xs" style="--badge-bg: var(--<?php echo $plan_class; ?>-light); --badge-color: var(--<?php echo $plan_class; ?>); font-weight: 700;">
                                        <?php echo htmlspecialchars($cl['plan']); ?> Plan
                                    </span>
                                </td>
                                <td class="p-3">
                                    <span class="badge text-xs" style="--badge-bg: var(--<?php echo $status_class; ?>-light); --badge-color: var(--<?php echo $status_class; ?>); font-weight: 700;">
                                        <?php echo htmlspecialchars($cl['status']); ?>
                                    </span>
                                </td>
                                <td class="p-3 text-xs font-semibold">
                                    <?php echo !empty($cl['expiry_date']) ? date('M d, Y', strtotime($cl['expiry_date'])) : 'Lifetime'; ?>
                                </td>
                                <td class="p-3 text-right">
                                    <div class="flex align-center justify-end gap-2 flex-wrap">
                                        <!-- Power Access & Page Permissions -->
                                        <button type="button" 
                                                class="btn btn-sm btn-cyan text-xs flex align-center gap-1" 
                                                onclick='openPermissionsModal(<?php echo $cl['id']; ?>, <?php echo json_encode($cl['company_name']); ?>, <?php echo json_encode($allowed_modules); ?>)'
                                                title="Grant/Revoke Page &amp; Module Permissions">
                                            <i data-lucide="shield-alert" style="width: 13px; height: 13px;"></i>
                                            <span>Power Access</span>
                                        </button>

                                        <!-- Test Client WhatsApp API Button -->
                                        <button type="button" 
                                                class="btn btn-sm btn-success text-xs flex align-center gap-1" 
                                                style="background: #10b981; border: none; color: white; font-weight: 600;"
                                                onclick='openTestWabaModal(<?php echo $cl['id']; ?>, <?php echo json_encode($cl['company_name']); ?>, <?php echo json_encode($wInfo['api_key']); ?>, <?php echo json_encode($wInfo['gateway_type']); ?>, <?php echo $wInfo['is_connected'] ? "true" : "false"; ?>, <?php echo json_encode($wInfo['phone']); ?>)'
                                                title="Test WhatsApp Dispatch for <?php echo htmlspecialchars($cl['company_name']); ?>">
                                            <i data-lucide="send" style="width: 13px; height: 13px;"></i>
                                            <span>Test API</span>
                                        </button>

                                        <!-- Direct WhatsApp QR Pair Button for Web API -->
                                        <?php if ($wInfo['gateway_type'] === 'web_api'): ?>
                                            <button type="button" 
                                                    class="btn btn-sm text-xs flex align-center gap-1" 
                                                    style="background: rgba(37, 99, 235, 0.12); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.3); font-weight: 600;"
                                                    onclick="openQrPairModal(<?php echo $cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>', '<?php echo htmlspecialchars($wInfo['phone'] ?: ($wInfo['last_phone'] ?? '')); ?>')"
                                                    title="Pair / Scan WhatsApp QR Code for <?php echo htmlspecialchars($cl['company_name']); ?>">
                                                <i data-lucide="qr-code" style="width: 13px; height: 13px;"></i>
                                                <span>QR Pair</span>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Download config.json Button -->
                                        <button type="button" 
                                                class="btn btn-sm btn-secondary text-xs flex align-center gap-1" 
                                                style="font-weight: 600;"
                                                onclick='downloadClientConfigJson(<?php echo json_encode($wInfo['api_key']); ?>, <?php echo json_encode($cl['company_code']); ?>)'
                                                title="Download Marg ERP Desktop .exe config.json for <?php echo htmlspecialchars($cl['company_name']); ?>">
                                            <i data-lucide="file-code" style="width: 13px; height: 13px; color: var(--primary);"></i>
                                            <span>config.json</span>
                                        </button>

                                        <!-- Edit Plan & Status -->
                                        <button type="button" 
                                                class="btn btn-sm btn-icon" 
                                                onclick="openEditPlanModal(<?php echo $cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>', '<?php echo htmlspecialchars(addslashes($cl['owner_name'])); ?>', '<?php echo htmlspecialchars(addslashes($cl['owner_email'])); ?>', '<?php echo htmlspecialchars(addslashes($cl['phone'] ?? '')); ?>', '<?php echo $cl['plan']; ?>', '<?php echo $cl['status']; ?>', '<?php echo $cl['expiry_date']; ?>')" 
                                                title="Edit Client Details, Subscription Plan &amp; Expiry">
                                            <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                        </button>

                                        <!-- Suspend / Reactivate Form -->
                                        <form action="index.php?page=crm_clients" method="POST" style="display: inline;" onsubmit="return confirm('Change client status to <?php echo ($cl['status'] === 'Active') ? 'Suspended' : 'Active'; ?>?');">
                                            <input type="hidden" name="action" value="toggle_client_suspend">
                                            <input type="hidden" name="tenant_id" value="<?php echo $cl['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $cl['status']; ?>">
                                            <button type="submit" class="btn btn-sm btn-icon" title="<?php echo ($cl['status'] === 'Active') ? 'Suspend Client' : 'Reactivate Client'; ?>">
                                                <i data-lucide="<?php echo ($cl['status'] === 'Active') ? 'pause-circle' : 'play-circle'; ?>" style="width: 14px; height: 14px; color: <?php echo ($cl['status'] === 'Active') ? 'var(--warning)' : 'var(--success)'; ?>;"></i>
                                            </button>
                                        </form>

                                        <?php if ($cl['id'] > 1): ?>
                                            <!-- Delete Client Database -->
                                            <form action="index.php?page=crm_clients" method="POST" style="display: inline;" onsubmit="return confirm('PERMANENT WARNING: Delete database &amp; all data for <?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_crm_client">
                                                <input type="hidden" name="tenant_id" value="<?php echo $cl['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-icon" title="Delete Client &amp; Database">
                                                    <i data-lucide="trash-2" style="width: 14px; height: 14px; color: var(--danger);"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Register New CRM SaaS Client -->
<div id="create-crm-client-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Provision New CRM SaaS Client</h3>
            <button class="btn-icon" onclick="window.closeModal('create-crm-client-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="create_crm_client">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Business / Company Name *</label>
                    <input type="text" name="company_name" class="form-control" placeholder="e.g. Acme Pharmaceuticals" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Company Code Slug *</label>
                    <input type="text" name="company_code" class="form-control" placeholder="e.g. acmepharma" required pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores">
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Client Owner Name *</label>
                    <input type="text" name="owner_name" class="form-control" placeholder="e.g. Rajesh Sharma" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Client Owner Email *</label>
                    <input type="email" name="owner_email" class="form-control" placeholder="e.g. rajesh@acmepharma.com" required>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Phone Number</label>
                    <input type="text" name="phone" class="form-control" placeholder="+91 98765 43210">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Initial Password *</label>
                    <input type="text" name="password" class="form-control" value="client123" required>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Subscription Plan</label>
                    <select name="plan" class="form-control">
                        <option value="Basic">Basic Plan (₹1,999/mo)</option>
                        <option value="Silver" selected>Silver Suite (₹4,999/mo)</option>
                        <option value="Gold">Gold Pro (₹9,999/mo)</option>
                        <option value="Enterprise">Platinum Enterprise (₹24,999/mo)</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Subscription Duration</label>
                    <select name="duration_months" class="form-control">
                        <option value="1">1 Month Trial</option>
                        <option value="6">6 Months</option>
                        <option value="12" selected>12 Months (1 Year)</option>
                        <option value="24">24 Months (2 Years)</option>
                        <option value="36">36 Months (3 Years)</option>
                    </select>
                </div>
            </div>

            <div class="p-3 border-radius-sm" style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2);">
                <span class="text-xs text-muted block">
                    <i data-lucide="database" style="width: 14px; height: 14px; color: var(--primary); vertical-align: middle; margin-right: 4px;"></i>
                    Submitting this form will automatically create dedicated MySQL database <code>marg_crm_{company_code}</code>, initialize full CRM tables, and seed the owner login account.
                </span>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('create-crm-client-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs flex align-center gap-2">
                    <i data-lucide="server" style="width: 14px; height: 14px;"></i>
                    <span>Provision CRM Client</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Register Existing Marg ERP User -->
<div id="register-erp-user-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading); color: #10b981;">Register Existing Marg ERP User</h3>
            <button class="btn-icon" onclick="window.closeModal('register-erp-user-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="register_erp_user">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">User Email Address *</label>
                    <input type="email" name="owner_email" class="form-control" placeholder="e.g. client@marguser.com" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Phone Number *</label>
                    <input type="text" name="phone" class="form-control" placeholder="e.g. 9876543210" required>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Marg ERP License No *</label>
                    <input type="text" name="marg_license_no" class="form-control" placeholder="e.g. LIC-1114878" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Initial Password *</label>
                    <input type="text" name="password" class="form-control" value="marg123" required>
                </div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Firm / Company Name (Optional)</label>
                <input type="text" name="company_name" class="form-control" placeholder="e.g. POSHAK PATHAK TRADERS">
            </div>

            <div class="p-3 border-radius-sm" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2);">
                <span class="text-xs text-muted block">
                    <i data-lucide="shield-check" style="width: 14px; height: 14px; color: #10b981; vertical-align: middle; margin-right: 4px;"></i>
                    This registers the existing Marg ERP client in the CRM. Once logged in, the client will immediately get a Meta Embedded Signup modal to connect their WABA account in 1-Click!
                </span>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('register-erp-user-modal')">Cancel</button>
                <button type="submit" class="btn btn-success text-xs flex align-center gap-2" style="background: #10b981; border: none; color: white;">
                    <i data-lucide="user-check" style="width: 14px; height: 14px;"></i>
                    <span>Register ERP User & Generate ID</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit Plan, Profile & Expiry -->
<div id="edit-client-plan-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);" id="edit-plan-modal-title">Edit Client &amp; Subscription</h3>
            <button class="btn-icon" onclick="window.closeModal('edit-client-plan-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="update_client_status">
            <input type="hidden" name="tenant_id" id="edit-tenant-id" value="">

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Firm / Company Name *</label>
                <input type="text" name="company_name" id="edit-tenant-company-name" class="form-control" required>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Owner / Contact Name *</label>
                    <input type="text" name="owner_name" id="edit-tenant-owner-name" class="form-control" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Owner Email (Login ID) *</label>
                    <input type="email" name="owner_email" id="edit-tenant-owner-email" class="form-control" required>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Phone Number</label>
                    <input type="text" name="phone" id="edit-tenant-phone" class="form-control" placeholder="+91 98765 43210">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Subscription Plan</label>
                    <select name="plan" id="edit-tenant-plan" class="form-control">
                        <option value="Basic">Basic Plan</option>
                        <option value="Silver">Silver Suite</option>
                        <option value="Gold">Gold Pro</option>
                        <option value="Enterprise">Platinum Enterprise</option>
                    </select>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Account Status</label>
                    <select name="status" id="edit-tenant-status" class="form-control">
                        <option value="Active">Active</option>
                        <option value="Trial">Trial</option>
                        <option value="Suspended">Suspended</option>
                        <option value="Expired">Expired</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Expiry Date *</label>
                    <input type="date" name="expiry_date" id="edit-tenant-expiry" class="form-control" required>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('edit-client-plan-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs flex align-center gap-2">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Save Client &amp; Subscription</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Client Power & Page Access Permissions Console -->
<div id="edit-client-permissions-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 650px;">
        <div class="modal-header">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading);" id="perm-modal-title">Admin Client Power & Page Permissions</h3>
                <span class="text-xs text-muted">Enable or disable specific workspace pages & modules for this client</span>
            </div>
            <button class="btn-icon" onclick="window.closeModal('edit-client-permissions-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="update_client_permissions">
            <input type="hidden" name="tenant_id" id="perm-tenant-id" value="">

            <div class="flex justify-between align-center p-3 border-radius-sm" style="background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.2);">
                <span class="text-xs text-muted flex align-center gap-1">
                    <i data-lucide="shield-check" style="width: 14px; height: 14px; color: var(--accent-cyan);"></i>
                    Selected modules will be accessible to all users under this client's isolated database.
                </span>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 8px;" onclick="selectAllModules(true)">Select All</button>
                    <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 8px;" onclick="selectAllModules(false)">Deselect All</button>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: repeat(2, 1fr); gap: 0.75rem; max-height: 340px; overflow-y: auto; padding-right: 4px;">
                <?php 
                $all_sys_modules = [
                    'workspace_dashboard'   => ['name' => 'Workspace Dashboard', 'desc' => 'Overview & Key Performance Indicators (Leads, Deals & Tickets)'],
                    'whatsapp_dashboard'    => ['name' => 'WhatsApp Hub & Gateway', 'desc' => 'WhatsApp Web API Console, QR Pairing & Live Stats'],
                    'leads'                 => ['name' => 'Leads Management', 'desc' => 'Directory of Leads & Customer Profiles'],
                    'clients'               => ['name' => 'Clients Directory', 'desc' => 'Active Customer Accounts & Business Profiles'],
                    'customer_kyc'          => ['name' => 'Customer KYC Verification', 'desc' => 'Customer KYC Details & Verification Records'],
                    'pipeline'              => ['name' => 'Sales Pipeline Kanban', 'desc' => 'Stage-by-stage Deal Pipeline'],
                    'followups'             => ['name' => 'Follow-up Planner', 'desc' => 'Call Schedules & Reminders'],
                    'demo'                  => ['name' => 'Product Demos', 'desc' => 'Live & Online Client Demo Tracker'],
                    'quotation'             => ['name' => 'Quotation & Invoice Builder', 'desc' => 'GST Invoices, Proformas & PDF Export'],
                    'payments'              => ['name' => 'Payment Tracker', 'desc' => 'Outstanding & Payment Reminders'],
                    'bank_accounts'         => ['name' => 'Bank & QR Details', 'desc' => 'Bank Accounts & Payment QR Code Setup'],
                    'installation'          => ['name' => 'Deployment & Setup', 'desc' => 'On-site & Online Setup Checklist'],
                    'training'              => ['name' => 'Client Staff Training', 'desc' => 'Training Log & Hours Certification'],
                    'support'               => ['name' => 'Support Tickets', 'desc' => 'Customer Issues & Ticketing Desk'],
                    'renewals'              => ['name' => 'License Renewals', 'desc' => 'Expiry Tracker & AMC Reminders'],
                    'team_inbox'            => ['name' => 'Team Inbox & Live Chat', 'desc' => 'Multi-agent Team Chat Inbox'],
                    'merchant_waba_settings'=> ['name' => 'Marg ERP WABA Setup', 'desc' => 'Marg ERP 9+ Webhook Gateway Setup & QR'],
                    'whatsapp_settings'     => ['name' => 'WhatsApp Cloud API', 'desc' => 'Meta API Settings & Embedded Signup'],
                    'whatsapp_flows'        => ['name' => 'Bots & Auto-Reply', 'desc' => 'Interactive Bot Flow Designer & Auto-Replies (Requires Meta Cloud API)'],
                    'broadcast_campaigns'   => ['name' => 'WhatsApp Campaigns', 'desc' => 'Targeted Audience WhatsApp Broadcasts'],
                    'bulk_broadcast'        => ['name' => 'Bulk Marketing Broadcast', 'desc' => 'Mass CSV/Excel Marketing Broadcasts'],
                    'reports'               => ['name' => 'Reports & Analytics', 'desc' => 'Business Intelligence & CSV Exports'],
                    'settings'              => ['name' => 'Workspace Settings', 'desc' => 'General CRM & Company Preferences'],
                    'privacy_policy'        => ['name' => 'Privacy Policy', 'desc' => 'Legal Privacy Policy & Compliance Document'],
                    'terms_conditions'      => ['name' => 'Terms & Conditions', 'desc' => 'Legal Terms of Service & Usage Terms']
                ];
                foreach ($all_sys_modules as $mod_key => $mod_info):
                ?>
                    <label class="flex align-center gap-3 p-3 border-radius-sm cursor-pointer" style="background: var(--bg-body); border: 1px solid var(--border-color); transition: all 0.2s ease;">
                        <input type="checkbox" name="modules[]" value="<?php echo $mod_key; ?>" class="module-perm-chk" style="width: 16px; height: 16px; accent-color: var(--accent-cyan);">
                        <div class="flex flex-col">
                            <span class="text-xs font-bold" style="color: var(--text-main);"><?php echo htmlspecialchars($mod_info['name']); ?></span>
                            <span class="text-xs text-muted" style="font-size: 0.725rem;"><?php echo htmlspecialchars($mod_info['desc']); ?></span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('edit-client-permissions-modal')">Cancel</button>
                <button type="submit" class="btn btn-cyan text-xs flex align-center gap-2">
                    <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                    <span>Save Power Permissions</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 4: Super Admin WhatsApp API Test Console for Client -->
<div id="test-client-waba-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 540px;">
        <div class="modal-header">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading);" id="test-modal-title">Test WhatsApp API Dispatch</h3>
                <span class="text-xs text-muted" id="test-modal-subtitle">Dispatch a live test invoice message using client credentials</span>
            </div>
            <button class="btn-icon" onclick="window.closeModal('test-client-waba-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="test_client_waba_dispatch">
            <input type="hidden" name="tenant_id" id="test-tenant-id" value="">

            <div class="p-3 border-radius-sm" style="background: var(--bg-body); border: 1px solid var(--border-color);">
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <span class="text-xs text-muted block" style="font-size: 0.7rem;">Active Gateway:</span>
                        <strong class="text-xs" id="test-gateway-type" style="color: var(--primary);">Meta Cloud API</strong>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="font-size: 0.7rem;">Connection Status:</span>
                        <strong class="text-xs" id="test-conn-status" style="color: #10b981;">  Connected</strong>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="font-size: 0.7rem;">Connected Phone:</span>
                        <strong class="text-xs font-mono" id="test-phone-display">+91 92773 87778</strong>
                    </div>
                    <div>
                        <span class="text-xs text-muted block" style="font-size: 0.7rem;">Tenant API Key:</span>
                        <strong class="text-xs font-mono text-primary" id="test-api-key-display">MARG-WABA-...</strong>
                    </div>
                </div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Enter Test WhatsApp Mobile Number (10 Digits) *</label>
                <input type="text" name="test_mobile" id="test-mobile-input" class="form-control text-xs" placeholder="e.g. 9876543210" required>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Test Invoice / Bill Number</label>
                <input type="text" name="test_bill_no" id="test-bill-input" class="form-control text-xs font-mono" value="INV-TEST-<?php echo time(); ?>" readonly style="background: var(--bg-body);">
            </div>

            <div class="flex justify-between align-center mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('test-client-waba-modal')">Cancel</button>
                <button type="submit" class="btn btn-success text-xs flex align-center gap-2" style="background: #10b981; border: none; color: white; padding: 0.6rem 1.25rem; font-weight: 600;">
                    <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                    <span>Dispatch Test WhatsApp Message</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Admin Live WhatsApp QR Pairing & Status Modal -->
<div id="admin-tenant-qr-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 480px;">
        <div class="modal-header">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading);" id="qr-modal-title">WhatsApp Pairing Console</h3>
                <span class="text-xs text-muted" id="qr-modal-subtitle">Scan QR code or use Phone Pairing Code</span>
            </div>
            <button class="btn-icon" onclick="closeQrPairModal()"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <div class="modal-body flex flex-col gap-4 p-4 text-center">
            <input type="hidden" id="qr-modal-tenant-id" value="">

            <div id="qr-loading-spinner" class="py-6 flex flex-col align-center justify-center gap-3">
                <div style="width: 36px; height: 36px; border: 3px solid rgba(59,130,246,0.2); border-top-color: #2563eb; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto;"></div>
                <span class="text-xs text-muted">Connecting to WhatsApp Engine...</span>
            </div>

            <div id="qr-display-container" style="display: none;" class="flex flex-col align-center justify-center">
                <div style="padding: 12px; background: white; border-radius: 12px; border: 1px solid var(--border-color); display: inline-block; box-shadow: 0 4px 12px rgba(0,0,0,0.06); margin: 0 auto;">
                    <img id="qr-image-tag" src="" alt="WhatsApp QR Code" style="width: 240px; height: 240px; display: block;">
                </div>
                <div class="flex align-center justify-center gap-2 mt-3">
                    <span class="pulse-dot-green"></span>
                    <span class="text-xs font-semibold" style="color: var(--text-main);">Open WhatsApp &gt; Linked Devices &gt; Link a device</span>
                </div>
                <span class="text-xs text-muted mt-1" style="font-size: 0.72rem;">QR code auto-refreshes every 20 seconds</span>
            </div>

            <div id="qr-success-banner" style="display: none; background: rgba(16,185,129,0.12); border: 1px solid #10b981; border-radius: 8px;" class="p-4">
                <i data-lucide="check-circle-2" style="width: 36px; height: 36px; color: #10b981; margin: 0 auto 8px auto;"></i>
                <h4 class="m-0 font-bold" style="color: #059669;">WhatsApp Connected Successfully!</h4>
                <p class="text-xs text-muted mt-1 mb-0" id="qr-success-phone"></p>
            </div>

            <div class="p-3 border-radius-sm text-left" style="background: var(--bg-body); border: 1px solid var(--border-color);">
                <span class="text-xs font-bold block mb-1" style="color: var(--text-main);">Or Pair with Phone Number (No QR needed):</span>
                <div class="flex gap-2">
                    <input type="text" id="qr-phone-input" class="form-control text-xs" placeholder="e.g. 9876543210" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-primary text-xs" onclick="requestTenantPairingCode()">Get Code</button>
                </div>
                <div id="qr-pairing-code-display" class="mt-2 text-center" style="display: none; background: rgba(37,99,235,0.08); padding: 8px; border-radius: 6px; border: 1px dashed #2563eb;">
                    <span class="text-xs text-muted block">Enter this 8-digit code on your WhatsApp phone:</span>
                    <strong class="font-mono text-lg" id="qr-code-text" style="color: #2563eb; letter-spacing: 0.15em;"></strong>
                </div>
            </div>

            <div class="flex justify-between align-center mt-1">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeQrPairModal()">Close</button>
                <button type="button" class="btn btn-sm text-xs flex align-center gap-1" style="background: rgba(59,130,246,0.1); color: var(--primary); border: 1px solid var(--primary);" onclick="refreshCurrentQr()">
                    <i data-lucide="refresh-cw" style="width: 12px; height: 12px;"></i> Refresh QR
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let qrPollTimer = null;
let currentQrTenantId = null;

function openQrPairModal(tenantId, companyName, lastPhone) {
    currentQrTenantId = tenantId;
    document.getElementById('qr-modal-tenant-id').value = tenantId;
    document.getElementById('qr-modal-title').textContent = 'WhatsApp Pairing: ' + companyName;
    document.getElementById('qr-modal-subtitle').textContent = 'Tenant #' + tenantId + ' • Live WhatsApp Web Pairing';
    document.getElementById('qr-phone-input').value = lastPhone ? lastPhone.replace(/\D/g, '').slice(-10) : '';
    document.getElementById('qr-loading-spinner').style.display = 'flex';
    document.getElementById('qr-display-container').style.display = 'none';
    document.getElementById('qr-success-banner').style.display = 'none';
    document.getElementById('qr-pairing-code-display').style.display = 'none';
    
    window.openModal('admin-tenant-qr-modal');
    loadTenantQrCode();
    
    clearInterval(qrPollTimer);
    qrPollTimer = setInterval(pollTenantStatus, 3500);
}

function closeQrPairModal() {
    clearInterval(qrPollTimer);
    window.closeModal('admin-tenant-qr-modal');
}

function loadTenantQrCode() {
    if (!currentQrTenantId) return;
    fetch('api/whatsapp_web_engine.php?action=get_qr&user_id=' + currentQrTenantId)
        .then(res => res.json())
        .then(data => {
            document.getElementById('qr-loading-spinner').style.display = 'none';
            if (data.status === 'connected') {
                showQrSuccess(data.phone || data.phone_number);
            } else if (data.qr_image || data.qr_code) {
                const imgUrl = data.qr_image || ('https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(data.qr_code));
                document.getElementById('qr-image-tag').src = imgUrl;
                document.getElementById('qr-display-container').style.display = 'flex';
            } else {
                document.getElementById('qr-loading-spinner').style.display = 'flex';
            }
        })
        .catch(err => {
            console.error('QR Load error:', err);
        });
}

function pollTenantStatus() {
    if (!currentQrTenantId) return;
    fetch('api/whatsapp_web_engine.php?action=check_status&user_id=' + currentQrTenantId)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'connected') {
                showQrSuccess(data.phone || data.phone_number);
                clearInterval(qrPollTimer);
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        })
        .catch(() => {});
}

function showQrSuccess(phone) {
    document.getElementById('qr-loading-spinner').style.display = 'none';
    document.getElementById('qr-display-container').style.display = 'none';
    const banner = document.getElementById('qr-success-banner');
    banner.style.display = 'block';
    document.getElementById('qr-success-phone').textContent = phone ? ('Paired Number: +' + phone.replace(/\D/g, '')) : 'Device linked!';
}

function refreshCurrentQr() {
    document.getElementById('qr-loading-spinner').style.display = 'flex';
    document.getElementById('qr-display-container').style.display = 'none';
    loadTenantQrCode();
}

function requestTenantPairingCode() {
    const phone = document.getElementById('qr-phone-input').value.trim();
    if (!phone || phone.length < 10) {
        alert('Please enter a valid 10-digit mobile number');
        return;
    }
    fetch('api/whatsapp_web_engine.php?action=get_pairing_code&user_id=' + currentQrTenantId + '&phone=' + encodeURIComponent(phone))
        .then(res => res.json())
        .then(data => {
            if (data.code || data.pairing_code) {
                const code = data.code || data.pairing_code;
                document.getElementById('qr-code-text').textContent = code;
                document.getElementById('qr-pairing-code-display').style.display = 'block';
            } else if (data.message) {
                alert(data.message);
            }
        })
        .catch(err => {
            alert('Failed to request pairing code: ' + err.message);
        });
}

function openEditPlanModal(tenantId, companyName, ownerName, ownerEmail, phone, plan, status, expiryDate) {
    document.getElementById('edit-tenant-id').value = tenantId;
    document.getElementById('edit-plan-modal-title').textContent = 'Edit Client & Subscription: ' + companyName;
    document.getElementById('edit-tenant-company-name').value = companyName || '';
    document.getElementById('edit-tenant-owner-name').value = ownerName || '';
    document.getElementById('edit-tenant-owner-email').value = ownerEmail || '';
    document.getElementById('edit-tenant-phone').value = phone || '';
    document.getElementById('edit-tenant-plan').value = plan;
    document.getElementById('edit-tenant-status').value = status;
    document.getElementById('edit-tenant-expiry').value = expiryDate;
    window.openModal('edit-client-plan-modal');
}

function openPermissionsModal(tenantId, companyName, allowedModules) {
    document.getElementById('perm-tenant-id').value = tenantId;
    document.getElementById('perm-modal-title').textContent = 'Power Permissions: ' + companyName;

    const checkboxes = document.querySelectorAll('.module-perm-chk');
    const hasExplicitDash = Array.isArray(allowedModules) && (allowedModules.includes('workspace_dashboard') || allowedModules.includes('whatsapp_dashboard'));
    checkboxes.forEach(chk => {
        let val = chk.value;
        let isChecked = Array.isArray(allowedModules) && allowedModules.includes(val);

        if (!isChecked && Array.isArray(allowedModules)) {
            // Check legacy aliases only if not explicitly configured with modern dashboard keys
            if (!hasExplicitDash) {
                if (val === 'workspace_dashboard' && allowedModules.includes('dashboard')) isChecked = true;
                if (val === 'whatsapp_dashboard' && (allowedModules.includes('dashboard') || allowedModules.includes('merchant_waba_settings') || allowedModules.includes('whatsapp_settings'))) isChecked = true;
            }
            if (val === 'whatsapp_settings' && allowedModules.includes('merchant_waba_settings')) isChecked = true;
            if (val === 'merchant_waba_settings' && allowedModules.includes('whatsapp_settings')) isChecked = true;
            if (val === 'whatsapp_flows' && allowedModules.includes('bot_flows')) isChecked = true;
            if (val === 'bot_flows' && allowedModules.includes('whatsapp_flows')) isChecked = true;
            if (val === 'broadcast_campaigns' && allowedModules.includes('bulk_broadcast')) isChecked = true;
            if (val === 'bulk_broadcast' && allowedModules.includes('broadcast_campaigns')) isChecked = true;
            if (val === 'customer_kyc' && (allowedModules.includes('clients') || allowedModules.includes('leads'))) isChecked = true;
            if (val === 'clients' && allowedModules.includes('leads')) isChecked = true;
        }

        chk.checked = isChecked;
    });

    window.openModal('edit-client-permissions-modal');
}

function selectAllModules(selectState) {
    document.querySelectorAll('.module-perm-chk').forEach(chk => {
        chk.checked = selectState;
    });
}

function copyToClipboard(text, label = 'Copied') {
    if (!navigator.clipboard) {
        prompt('Copy ' + label + ':', text);
        return;
    }
    navigator.clipboard.writeText(text).then(() => {
        alert('🎉 ' + label + ' copied to clipboard:\n' + text);
    }).catch(() => {
        prompt('Copy ' + label + ':', text);
    });
}

function downloadClientConfigJson(apiKey, companyCode) {
    const configData = {
        api_key: apiKey
    };
    const jsonStr = JSON.stringify(configData, null, 2);
    const blob = new Blob([jsonStr], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'config.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function openTestWabaModal(tenantId, companyName, apiKey, gatewayType, isConnected, phone) {
    document.getElementById('test-tenant-id').value = tenantId;
    document.getElementById('test-modal-title').textContent = 'Test WhatsApp API: ' + companyName;
    document.getElementById('test-gateway-type').textContent = (gatewayType === 'web_api') ? 'Self-Hosted WhatsApp Web API' : 'Meta WhatsApp Cloud API';
    
    const connEl = document.getElementById('test-conn-status');
    const isConn = (isConnected === true || isConnected === 'true' || isConnected === 1);
    if (isConn) {
        connEl.textContent = '🟢 Connected';
        connEl.style.color = '#10b981';
    } else {
        connEl.textContent = '🔴 Not Connected';
        connEl.style.color = '#ef4444';
    }

    document.getElementById('test-phone-display').textContent = phone || 'No Phone Linked';
    document.getElementById('test-api-key-display').textContent = apiKey || 'Not Generated';
    document.getElementById('test-bill-input').value = 'INV-TEST-' + Math.floor(Date.now() / 1000);
    document.getElementById('test-mobile-input').value = '';
    
    window.openModal('test-client-waba-modal');
}
</script>
