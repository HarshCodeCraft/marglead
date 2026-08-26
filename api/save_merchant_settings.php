<?php
/**
 * Save Merchant Gateway Settings (Admin Dashboard Action)
 * Supports both Official Meta WABA & 3rd-Party Web API gateways.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db.php';

// Check DB Connection
if (!$db_connected || !$pdo) {
    $redirectUrl = "../index.php?page=merchant_waba_settings&error=" . urlencode("Database connection offline.");
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Database connection offline.']);
        exit;
    }
    header("Location: " . $redirectUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Session ya Form se tenant_api_key le lo
    $tenant_api_key  = trim($_POST['tenant_api_key'] ?? $_SESSION['tenant_api_key'] ?? ''); 
    $gateway_type    = trim($_POST['gateway_type'] ?? 'meta');
    $phone_number_id = trim($_POST['phone_number_id'] ?? '');
    $access_token    = trim($_POST['access_token'] ?? '');
    $web_api_url     = trim($_POST['web_api_url'] ?? '');
    $web_api_token   = trim($_POST['web_api_token'] ?? '');
    $user_id         = (int)($_SESSION['user_id'] ?? 1);

    if (empty($tenant_api_key)) {
        $msg = "Invalid or missing Merchant API Key.";
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        header("Location: ../index.php?page=merchant_waba_settings&error=" . urlencode($msg));
        exit;
    }

    try {
        // Schema upgrade check for columns (gateway_type, web_api_url, web_api_token)
        $cols = $pdo->query("SHOW COLUMNS FROM merchant_waba_settings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('gateway_type', $cols)) {
            $pdo->exec("ALTER TABLE merchant_waba_settings ADD COLUMN gateway_type VARCHAR(20) DEFAULT 'meta'");
        }
        if (!in_array('web_api_url', $cols)) {
            $pdo->exec("ALTER TABLE merchant_waba_settings ADD COLUMN web_api_url VARCHAR(255) NULL");
        }
        if (!in_array('web_api_token', $cols)) {
            $pdo->exec("ALTER TABLE merchant_waba_settings ADD COLUMN web_api_token TEXT NULL");
        }

        // Check if settings record exists for this tenant_api_key or user_id
        $checkStmt = $pdo->prepare("SELECT id FROM merchant_waba_settings WHERE tenant_api_key = ? OR user_id = ? LIMIT 1");
        $checkStmt->execute([$tenant_api_key, $user_id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt = $pdo->prepare("UPDATE merchant_waba_settings 
                SET tenant_api_key = ?, gateway_type = ?, phone_number_id = ?, access_token = ?, web_api_url = ?, web_api_token = ? 
                WHERE id = ?");
            $updateStmt->execute([$tenant_api_key, $gateway_type, $phone_number_id, $access_token, $web_api_url, $web_api_token, $existing['id']]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO merchant_waba_settings 
                (user_id, tenant_api_key, gateway_type, phone_number_id, access_token, web_api_url, web_api_token, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
            $insertStmt->execute([$user_id, $tenant_api_key, $gateway_type, $phone_number_id, $access_token, $web_api_url, $web_api_token]);
        }

        $successMsg = "Merchant Gateway settings updated successfully.";
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $successMsg]);
            exit;
        }

        header("Location: ../index.php?page=merchant_waba_settings&success=" . urlencode($successMsg));
        exit;

    } catch (PDOException $e) {
        $errorMsg = "Database error: " . $e->getMessage();
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        }
        header("Location: ../index.php?page=merchant_waba_settings&error=" . urlencode($errorMsg));
        exit;
    }
}
?>