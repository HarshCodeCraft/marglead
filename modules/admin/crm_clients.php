<?php
/**
 * Marg ERP CRM - CRM Clients & Multi-Tenant SaaS Management Module
 * Allows Super Admin to provision, manage, isolate data, and impersonate SaaS CRM Clients.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$user_role = $_SESSION['user_role'] ?? '';
$master_db_name = defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata';
$tenant_db = $_SESSION['tenant_db'] ?? $master_db_name;
$is_impersonating = !empty($_SESSION['impersonate_tenant_db']);

// Access Security Check: Restricted to System Admin (Super Admin / Admin of Master DB)
if (!in_array($user_role, ['Super Admin', 'Admin']) || ($tenant_db !== $master_db_name && !$is_impersonating)) {
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
    
    try {
        // A. Create new database in MySQL
        $masterPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // B. Connect to new tenant DB
        $tenantDsn = "mysql:host=$db_host;port=$db_port;dbname=$dbName;charset=utf8mb4";
        $tenantPdo = new PDO($tenantDsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // C. Load schema.sql statements safely into new tenant database
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
                    } catch (PDOException $ex) {
                        // Ignore non-fatal warnings
                    }
                }
            }
        }
        
        // D. Truncate default master users in tenant DB and provision Client Owner
        $tenantPdo->exec("TRUNCATE TABLE users");
        $pwdHash = password_hash($passwordStr, PASSWORD_DEFAULT);
        $allPermissions = json_encode(["dashboard","leads","pipeline","followups","demo","quotation","payments","installation","training","support","renewals","reports","settings"]);
        
        $stmtUser = $tenantPdo->prepare("INSERT INTO users (name, email, password, role, status, permissions) VALUES (?, ?, ?, 'Admin', 'Active', ?)");
        $stmtUser->execute([$ownerName, $ownerEmail, $pwdHash, $allPermissions]);
        
        // E. Register in master tenant_companies table
        $expiryDate = date('Y-m-d', strtotime("+{$expiryMonths} months"));
        $stmtMaster = $masterPdo->prepare("INSERT INTO tenant_companies (company_name, company_code, owner_name, owner_email, phone, db_name, plan, status, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name), owner_name=VALUES(owner_name), owner_email=VALUES(owner_email), phone=VALUES(phone), plan=VALUES(plan), expiry_date=VALUES(expiry_date)");
        $stmtMaster->execute([$companyName, $codeSlug, $ownerName, $ownerEmail, $phone, $dbName, $plan, $expiryDate]);
        
        return [
            'success' => true,
            'message' => "CRM Client \"{$companyName}\" provisioned successfully with database \"{$dbName}\"!",
            'db_name' => $dbName,
            'company_code' => $codeSlug
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database provisioning failure: ' . $e->getMessage()];
    }
}

// --------------------------------------------------------------------------
// 2. Action Handlers (Create, Edit, Suspend, Impersonate, Delete)
// --------------------------------------------------------------------------
$flash_msg = '';
$flash_type = '';

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
    // B. Edit Client Plan / Expiry / Status
    elseif ($_POST['action'] === 'update_client_status') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $uPlan = $_POST['plan'] ?? 'Silver';
        $uStatus = $_POST['status'] ?? 'Active';
        $uExpiry = $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+1 year'));
        
        if ($tenantId > 0 && isset($pdo_master)) {
            try {
                $stmtU = $pdo_master->prepare("UPDATE tenant_companies SET plan = ?, status = ?, expiry_date = ? WHERE id = ?");
                $stmtU->execute([$uPlan, $uStatus, $uExpiry, $tenantId]);
                $flash_msg = "CRM Client settings updated successfully!";
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
                    // Drop isolated DB
                    $pdo_master->exec("DROP DATABASE IF EXISTS `{$tRec['db_name']}`");
                    // Delete master record
                    $delStmt = $pdo_master->prepare("DELETE FROM tenant_companies WHERE id = ?");
                    $delStmt->execute([$tenantId]);
                    $flash_msg = "CRM Client \"{$tRec['company_name']}\" and database \"{$tRec['db_name']}\" deleted permanently.";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_msg = "Error deleting CRM Client: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
    // E. Update Client Power & Page Access Permissions
    elseif ($_POST['action'] === 'update_client_permissions') {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        $modulesArr = isset($_POST['modules']) && is_array($_POST['modules']) ? $_POST['modules'] : [];

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

        $modulesJson = json_encode(array_values(array_unique($modulesArr)));
        
        if ($tenantId > 0 && isset($pdo_master)) {
            try {
                $stmtPerm = $pdo_master->prepare("UPDATE tenant_companies SET allowed_modules = ? WHERE id = ?");
                $stmtPerm->execute([$modulesJson, $tenantId]);

                // Synchronize with tenant DB user permissions
                $stmtGetT = $pdo_master->prepare("SELECT * FROM tenant_companies WHERE id = ?");
                $stmtGetT->execute([$tenantId]);
                $tComp = $stmtGetT->fetch();
                if ($tComp && !empty($tComp['db_name'])) {
                    try {
                        $tDsn = "mysql:host=$db_host;port=$db_port;dbname={$tComp['db_name']};charset=utf8mb4";
                        $tPdo = new PDO($tDsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        $stmtUpdUser = $tPdo->prepare("UPDATE users SET permissions = ? WHERE role = 'Admin'");
                        $stmtUpdUser->execute([$modulesJson]);
                    } catch (PDOException $tEx) {
                        // Tenant DB sync warning ignored
                    }
                }

                // Clear session cache so permissions take effect instantly
                unset($_SESSION['tenant_allowed_modules']);
                unset($_SESSION['tenant_allowed_db']);
                unset($_SESSION['user_permissions']);

                $flash_msg = "Client Power & Page Access permissions updated successfully!";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_msg = "Error updating permissions: " . $e->getMessage();
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
                        <th class="p-3 text-xs font-bold text-muted">ID & COMPANY</th>
                        <th class="p-3 text-xs font-bold text-muted">OWNER / EMAIL</th>
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
                            <td colspan="7" class="text-center py-6 text-muted text-sm">No CRM Clients registered yet. Click "Register New CRM Client" to provision an isolated account.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $cl): 
                            $status_class = ($cl['status'] === 'Active') ? 'success' : (($cl['status'] === 'Suspended') ? 'danger' : 'warning');
                            $plan_class = ($cl['plan'] === 'Enterprise') ? 'accent' : (($cl['plan'] === 'Gold') ? 'primary' : 'secondary');

                            $default_all = ["dashboard","leads","pipeline","followups","demo","quotation","payments","bank_accounts","installation","training","support","renewals","team_inbox","merchant_waba_settings","whatsapp_settings","whatsapp_flows","bot_flows","broadcast_campaigns","bulk_broadcast","reports","settings"];
                            $allowed_modules = !empty($cl['allowed_modules']) ? json_decode($cl['allowed_modules'], true) : $default_all;
                            if (!is_array($allowed_modules)) $allowed_modules = $default_all;

                            // Fetch live stats from tenant DB safely
                            $tenant_users_cnt = 'N/A';
                            $tenant_leads_cnt = 'N/A';
                            try {
                                $tDsnInst = "mysql:host=$db_host;port=$db_port;dbname={$cl['db_name']};charset=utf8mb4";
                                $tPdoInst = new PDO($tDsnInst, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                                $tenant_users_cnt = $tPdoInst->query("SELECT COUNT(*) FROM users")->fetchColumn();
                                $tenant_leads_cnt = $tPdoInst->query("SELECT COUNT(*) FROM leads")->fetchColumn();
                            } catch (PDOException $ex) {
                                // DB down or unreadable
                            }
                        ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td class="p-3">
                                    <div class="flex align-center gap-3">
                                        <div style="width: 36px; height: 36px; border-radius: var(--border-radius-sm); background: rgba(59, 130, 246, 0.12); color: var(--primary); font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                                            <?php echo strtoupper(substr($cl['company_code'], 0, 2)); ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-semibold" style="color: var(--text-main);"><?php echo htmlspecialchars($cl['company_name']); ?></span>
                                            <span class="text-xs text-muted">Code: <code><?php echo htmlspecialchars($cl['company_code']); ?></code></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-col">
                                        <span class="text-xs font-semibold"><?php echo htmlspecialchars($cl['owner_name']); ?></span>
                                        <a href="mailto:<?php echo htmlspecialchars($cl['owner_email']); ?>" class="text-xs text-primary"><?php echo htmlspecialchars($cl['owner_email']); ?></a>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted); font-family: monospace;">
                                            <i data-lucide="database" style="width: 12px; height: 12px; margin-right: 4px;"></i>
                                            <?php echo htmlspecialchars($cl['db_name']); ?>
                                        </span>
                                        <span class="text-xs text-muted" style="font-size: 0.725rem;">
                                            👥 Users: <strong><?php echo $tenant_users_cnt; ?></strong> | 📊 Leads: <strong><?php echo $tenant_leads_cnt; ?></strong>
                                        </span>
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
                                                title="Grant/Revoke Page & Module Permissions">
                                            <i data-lucide="shield-alert" style="width: 13px; height: 13px;"></i>
                                            <span>Power Access</span>
                                        </button>

                                        <!-- Impersonate / Login as Client -->
                                        <a href="index.php?action=impersonate_client&db=<?php echo urlencode($cl['db_name']); ?>&company=<?php echo urlencode($cl['company_name']); ?>" 
                                           class="btn btn-sm btn-secondary text-xs flex align-center gap-1" 
                                           title="Impersonate & Login to <?php echo htmlspecialchars($cl['company_name']); ?> CRM instance">
                                            <i data-lucide="log-in" style="width: 13px; height: 13px; color: var(--primary);"></i>
                                            <span>Access</span>
                                        </a>

                                        <!-- Edit Plan & Status -->
                                        <button type="button" 
                                                class="btn btn-sm btn-icon" 
                                                onclick="openEditPlanModal(<?php echo $cl['id']; ?>, '<?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>', '<?php echo $cl['plan']; ?>', '<?php echo $cl['status']; ?>', '<?php echo $cl['expiry_date']; ?>')" 
                                                title="Edit Subscription Plan & Expiry">
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
                                            <form action="index.php?page=crm_clients" method="POST" style="display: inline;" onsubmit="return confirm('PERMANENT WARNING: Delete database & all data for <?php echo htmlspecialchars(addslashes($cl['company_name'])); ?>? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_crm_client">
                                                <input type="hidden" name="tenant_id" value="<?php echo $cl['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-icon" title="Delete Client & Database">
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

<!-- Modal 2: Edit Plan & Expiry -->
<div id="edit-client-plan-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 450px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);" id="edit-plan-modal-title">Edit Subscription Plan</h3>
            <button class="btn-icon" onclick="window.closeModal('edit-client-plan-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=crm_clients" method="POST">
            <input type="hidden" name="action" value="update_client_status">
            <input type="hidden" name="tenant_id" id="edit-tenant-id" value="">

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Subscription Plan</label>
                <select name="plan" id="edit-tenant-plan" class="form-control">
                    <option value="Basic">Basic Plan</option>
                    <option value="Silver">Silver Suite</option>
                    <option value="Gold">Gold Pro</option>
                    <option value="Enterprise">Platinum Enterprise</option>
                </select>
            </div>

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
                <label class="form-label text-xs font-semibold">Expiry Date</label>
                <input type="date" name="expiry_date" id="edit-tenant-expiry" class="form-control" required>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('edit-client-plan-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs">Save Subscription Changes</button>
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
                    'dashboard' => ['name' => 'Workspace Dashboard', 'desc' => 'Overview & Key Performance Indicators'],
                    'leads' => ['name' => 'Leads Management', 'desc' => 'Directory of Leads & Customer Profiles'],
                    'pipeline' => ['name' => 'Sales Pipeline Kanban', 'desc' => 'Stage-by-stage Deal Pipeline'],
                    'followups' => ['name' => 'Follow-up Planner', 'desc' => 'Call Schedules & Reminders'],
                    'demo' => ['name' => 'Product Demos', 'desc' => 'Live & Online Client Demo Tracker'],
                    'quotation' => ['name' => 'Quotation & Invoice Builder', 'desc' => 'GST Invoices & PDF Export'],
                    'payments' => ['name' => 'Payment Tracker', 'desc' => 'Outstanding & Payment Reminders'],
                    'bank_accounts' => ['name' => 'Bank & QR Details', 'desc' => 'Bank Accounts & Payment QR Code Setup'],
                    'installation' => ['name' => 'Deployment & Setup', 'desc' => 'On-site & Online Setup Checklist'],
                    'training' => ['name' => 'Client Staff Training', 'desc' => 'Training Log & Hours Certification'],
                    'support' => ['name' => 'Support Tickets', 'desc' => 'Customer Issues & Ticketing Desk'],
                    'renewals' => ['name' => 'License Renewals', 'desc' => 'Expiry Tracker & AMC Reminders'],
                    'team_inbox' => ['name' => 'Team Inbox & Live Chat', 'desc' => 'Multi-agent Team Chat Inbox'],
                    'merchant_waba_settings' => ['name' => 'Marg ERP WABA Setup', 'desc' => 'Marg ERP 9+ Webhook Gateway Setup'],
                    'whatsapp_settings' => ['name' => 'WhatsApp Cloud API', 'desc' => 'Meta API Settings & Embedded Signup'],
                    'whatsapp_flows' => ['name' => 'WhatsApp Flow Builder', 'desc' => 'Interactive Bot Flow Designer'],
                    'broadcast_campaigns' => ['name' => 'WhatsApp Campaigns', 'desc' => 'Targeted Audience WhatsApp Broadcasts'],
                    'bulk_broadcast' => ['name' => 'Bulk Marketing Broadcast', 'desc' => 'Mass CSV/Excel Marketing Broadcasts'],
                    'reports' => ['name' => 'Reports & Analytics', 'desc' => 'Business Intelligence & CSV Exports'],
                    'settings' => ['name' => 'Workspace Settings', 'desc' => 'General CRM & Company Preferences']
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

<script>
function openEditPlanModal(tenantId, companyName, plan, status, expiryDate) {
    document.getElementById('edit-tenant-id').value = tenantId;
    document.getElementById('edit-plan-modal-title').textContent = 'Edit Subscription: ' + companyName;
    document.getElementById('edit-tenant-plan').value = plan;
    document.getElementById('edit-tenant-status').value = status;
    document.getElementById('edit-tenant-expiry').value = expiryDate;
    window.openModal('edit-client-plan-modal');
}

function openPermissionsModal(tenantId, companyName, allowedModules) {
    document.getElementById('perm-tenant-id').value = tenantId;
    document.getElementById('perm-modal-title').textContent = 'Power Permissions: ' + companyName;

    const checkboxes = document.querySelectorAll('.module-perm-chk');
    checkboxes.forEach(chk => {
        let val = chk.value;
        let isChecked = Array.isArray(allowedModules) && allowedModules.includes(val);

        if (!isChecked && Array.isArray(allowedModules)) {
            if (val === 'whatsapp_settings' && allowedModules.includes('merchant_waba_settings')) isChecked = true;
            if (val === 'merchant_waba_settings' && allowedModules.includes('whatsapp_settings')) isChecked = true;
            if (val === 'whatsapp_flows' && allowedModules.includes('bot_flows')) isChecked = true;
            if (val === 'bot_flows' && allowedModules.includes('whatsapp_flows')) isChecked = true;
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
</script>
