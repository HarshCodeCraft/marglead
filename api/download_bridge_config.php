<?php
/**
 * Marg ERP Desktop Bridge - Download Config JSON
 * Provides pre-configured configuration for local Marg ERP Bridge service
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die("Authentication required");
}

$active_tenant_db = $_SESSION['impersonate_tenant_db'] ?? $_SESSION['tenant_db'] ?? '';
$tenant_code = $_SESSION['tenant_code'] ?? '';
$api_key = $_SESSION['tenant_api_key'] ?? '';
$company_name = $_SESSION['tenant_name'] ?? $_SESSION['company_name'] ?? 'Marg Client';

// If API key is missing from session, fetch from DB
if (empty($api_key) || empty($tenant_code)) {
    global $pdo_master, $pdo;
    $db_to_use = $pdo_master ?? $pdo;
    if ($db_to_use) {
        try {
            $stmt = $db_to_use->prepare("SELECT company_code, api_key, company_name FROM tenant_companies WHERE db_name = ? OR owner_email = ? OR company_code = ? LIMIT 1");
            $stmt->execute([$active_tenant_db, $_SESSION['user_email'] ?? '', $tenant_code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tenant_code = $row['company_code'];
                $api_key = $row['api_key'];
                $company_name = $row['company_name'];
            }
        } catch (\PDOException $e) {}
    }
}

// Generate fallback API key if not yet generated
if (empty($api_key) && !empty($tenant_code)) {
    $api_key = 'marg_' . bin2hex(random_bytes(16));
    global $pdo_master, $pdo;
    $db_to_use = $pdo_master ?? $pdo;
    if ($db_to_use) {
        try {
            $upd = $db_to_use->prepare("UPDATE tenant_companies SET api_key = ? WHERE company_code = ?");
            $upd->execute([$api_key, $tenant_code]);
            $_SESSION['tenant_api_key'] = $api_key;
        } catch (\PDOException $e) {}
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? 'https://' : 'http://';
$http_host = $_SERVER['HTTP_HOST'] ?? 'friendlyaisolution.com';
$endpoint = $scheme . $http_host . '/api/marg_erp_gateway.php';

$configData = [
    "app_name" => "Marg ERP WhatsApp Billing Bridge",
    "company_name" => $company_name,
    "company_code" => $tenant_code,
    "api_key" => $api_key,
    "endpoint" => $endpoint,
    "marg_folder" => "C:\\Users\\Public\\MARG\\33144",
    "pdf_sync_folder" => "C:\\Users\\Public\\MARG\\33144\\PDF",
    "poll_interval_seconds" => 3,
    "auto_retry" => true,
    "log_level" => "INFO",
    "created_at" => date('Y-m-d H:i:s')
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="config.json"');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
