<?php
/**
 * Meta Embedded Signup & Multi-Tenant WhatsApp Cloud API Configuration Handler
 * 
 * Handles Meta Embedded Signup OAuth code exchange, Phone Number details fetching,
 * token verification, and tenant DB registry updates for Marg ERP 9+ WhatsApp integration.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Check if request is a GET redirect from Meta-hosted Embedded Signup URL
$isGetRedirect = ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['code']) || isset($_GET['error'])));

// Handle Meta error redirect (e.g. client cancelled)
if ($isGetRedirect && isset($_GET['error'])) {
    $errDesc = $_GET['error_description'] ?? $_GET['error'] ?? 'User cancelled Meta onboarding.';
    header("Location: ../index.php?page=whatsapp_settings&status=error&msg=" . urlencode($errDesc));
    exit;
}

// User identification: Check active state from Meta redirect, or active session, or payload
$user_id = 0;
if (isset($_GET['state']) && is_numeric($_GET['state']) && (int)$_GET['state'] > 0) {
    $user_id = (int)$_GET['state'];
} elseif (isset($_POST['state']) && is_numeric($_POST['state']) && (int)$_POST['state'] > 0) {
    $user_id = (int)$_POST['state'];
} elseif (!empty($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

if (!$user_id && !empty($data['user_id']) && is_numeric($data['user_id'])) {
    $user_id = (int)$data['user_id'];
}
if (!$user_id && !empty($data['state']) && is_numeric($data['state'])) {
    $user_id = (int)$data['state'];
}
if (!$user_id) {
    $user_id = 1; // Default to main admin if direct onboarding redirect without state
}

if (!$isGetRedirect) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) && !$user_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login first.']);
        exit;
    }
}

$action = $data['action'] ?? ($isGetRedirect ? 'embedded_signup' : 'embedded_signup');
$firm_name = trim($data['firm_name'] ?? ($_SESSION['company_name'] ?? 'Marg Partner'));
$marg_license_no = trim($data['marg_license_no'] ?? '');

try {
    if ($action === 'embedded_signup' || $action === 'save_embedded' || $isGetRedirect) {
        $waba_id = trim($data['waba_id'] ?? ($_GET['waba_id'] ?? ''));
        $phone_number_id = trim($data['phone_number_id'] ?? ($_GET['phone_number_id'] ?? ''));
        $code = trim($data['code'] ?? ($_GET['code'] ?? ''));
        $access_token = trim($data['access_token'] ?? '');
        $app_id = defined('META_APP_ID') ? META_APP_ID : (getenv('META_APP_ID') ?: '2484306222079451');
        $app_secret = defined('APP_SECRET') ? APP_SECRET : (getenv('META_APP_SECRET') ?: '');

        // 1. If authorization code is provided, exchange for token with Meta Graph API
        if (!empty($code) && empty($access_token)) {
            if (empty($app_secret)) {
                $err = "Meta App Secret is missing in config/config.php. Please add APP_SECRET to enable automatic token exchange.";
                if ($isGetRedirect) {
                    header("Location: ../index.php?page=whatsapp_settings&status=error&msg=" . urlencode($err));
                    exit;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }

            $tokenUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/oauth/access_token?" . http_build_query([
                'client_id' => $app_id,
                'client_secret' => $app_secret,
                'code' => $code
            ]);

            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $tokenRes = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($tokenRes, true);
            if (!empty($tokenData['access_token'])) {
                $access_token = $tokenData['access_token'];
            } elseif (isset($tokenData['error'])) {
                $err = 'Meta Token Exchange Failed: ' . ($tokenData['error']['message'] ?? 'Invalid code or App Secret.');
                if ($isGetRedirect) {
                    header("Location: ../index.php?page=whatsapp_settings&status=error&msg=" . urlencode($err));
                    exit;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
        }

        // Only fallback to system access token if NO code was provided (e.g. system self-test)
        if (empty($access_token) && empty($code)) {
            $access_token = ACCESS_TOKEN;
        }

        // 1.1 Automatic WABA ID & Phone Number ID discovery from Meta if not supplied
        if ((empty($waba_id) || empty($phone_number_id)) && !empty($access_token)) {
            // Check debug_token for granular scopes (contains WABA target_ids)
            $debugUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/debug_token?input_token={$access_token}&access_token={$access_token}";
            $ch = curl_init($debugUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $debugRes = curl_exec($ch);
            curl_close($ch);
            $debugData = json_decode($debugRes, true);

            if (!empty($debugData['data']['granular_scopes'])) {
                foreach ($debugData['data']['granular_scopes'] as $sc) {
                    if (!empty($sc['target_ids'][0])) {
                        $waba_id = $sc['target_ids'][0];
                        break;
                    }
                }
            }

            // If WABA ID found, query its phone numbers
            if (!empty($waba_id)) {
                $phoneUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating";
                $ch = curl_init($phoneUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
                $pRes = curl_exec($ch);
                curl_close($ch);
                $pData = json_decode($pRes, true);

                if (!empty($pData['data'][0]['id'])) {
                    $phone_number_id = $pData['data'][0]['id'];
                    $display_phone_number = $pData['data'][0]['display_phone_number'] ?? '';
                    $verified_name = $pData['data'][0]['verified_name'] ?? '';
                }
            }
        }

        if (empty($waba_id) || empty($phone_number_id)) {
            if ($isGetRedirect) {
                header("Location: ../index.php?page=whatsapp_settings&status=error&msg=" . urlencode("Could not auto-detect WABA ID or Phone Number from Meta."));
                exit;
            }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'WABA ID and Phone Number ID are required for Meta Embedded Signup.']);
            exit;
        }

        // Fallback to system access token if OAuth access token not returned
        if (empty($access_token)) {
            $access_token = ACCESS_TOKEN;
        }

        // 2. Fetch Phone Number display details & verified name from Meta Graph API
        $metaUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}?fields=display_phone_number,verified_name,quality_rating,name_status";
        $ch = curl_init($metaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
        $metaRes = curl_exec($ch);
        curl_close($ch);

        $metaData = json_decode($metaRes, true);
        $display_phone_number = $metaData['display_phone_number'] ?? $data['display_phone_number'] ?? 'WhatsApp Connected';
        $verified_name = $metaData['verified_name'] ?? $firm_name ?: 'Marg Partner';

        // 3. Upsert record into tenant_whatsapp_configs
        $stmt = $pdo->prepare("
            INSERT INTO tenant_whatsapp_configs 
            (user_id, firm_name, marg_license_no, waba_id, phone_number_id, display_phone_number, verified_name, access_token, signup_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'embedded', 'active')
            ON DUPLICATE KEY UPDATE 
                firm_name = VALUES(firm_name),
                marg_license_no = VALUES(marg_license_no),
                waba_id = VALUES(waba_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name),
                access_token = VALUES(access_token),
                signup_method = 'embedded',
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $user_id,
            $firm_name,
            $marg_license_no,
            $waba_id,
            $phone_number_id,
            $display_phone_number,
            $verified_name,
            $access_token
        ]);

        // 4. Auto-sync into merchant_waba_settings table for Marg ERP Gateway & Campaign API compatibility
        $stmtSync = $pdo->prepare("
            INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, business_phone)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                phone_number_id = VALUES(phone_number_id),
                waba_id = VALUES(waba_id),
                access_token = VALUES(access_token),
                business_phone = VALUES(business_phone)
        ");
        $stmtSync->execute([$user_id, $phone_number_id, $waba_id, $access_token, $display_phone_number]);

        // 4.1 Sync into tenant isolated table if user_id belongs to a tenant company
        try {
            $stmtTenantChk = $pdo->prepare("SELECT * FROM tenant_companies WHERE id = ? LIMIT 1");
            $stmtTenantChk->execute([$user_id]);
            $tenantCompany = $stmtTenantChk->fetch(PDO::FETCH_ASSOC);
            if ($tenantCompany && !empty($tenantCompany['db_name'])) {
                $tenantDbPrefix = $tenantCompany['db_name'];
                $tenantWabaTable = $tenantDbPrefix . 'merchant_waba_settings';
                $stmtTenantWaba = $pdo->prepare("
                    INSERT INTO `{$tenantWabaTable}` (user_id, phone_number_id, waba_id, access_token, business_phone, gateway_type, status)
                    VALUES (?, ?, ?, ?, ?, 'meta', 'Active')
                    ON DUPLICATE KEY UPDATE 
                        phone_number_id = VALUES(phone_number_id),
                        waba_id = VALUES(waba_id),
                        access_token = VALUES(access_token),
                        business_phone = VALUES(business_phone),
                        gateway_type = 'meta',
                        status = 'Active'
                ");
                $stmtTenantWaba->execute([$user_id, $phone_number_id, $waba_id, $access_token, $display_phone_number]);
            }
        } catch (\Throwable $tEx) {}

        // 4.1 Automatically Subscribe App to Client's WABA for Webhook events (Messages & Status Updates)
        try {
            $subUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/subscribed_apps";
            $chSub = curl_init($subUrl);
            curl_setopt($chSub, CURLOPT_POST, true);
            curl_setopt($chSub, CURLOPT_POSTFIELDS, "");
            curl_setopt($chSub, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chSub, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
            $subRes = curl_exec($chSub);
            curl_close($chSub);
        } catch (\Throwable $subEx) {}

        // 4.2 Automatically Register Phone Number on Meta Cloud API (Sets 2FA PIN & Activates Live Messaging)
        try {
            $regUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}/register";
            $chReg = curl_init($regUrl);
            curl_setopt($chReg, CURLOPT_POST, true);
            curl_setopt($chReg, CURLOPT_POSTFIELDS, json_encode([
                'messaging_product' => 'whatsapp',
                'pin' => '123456'
            ]));
            curl_setopt($chReg, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chReg, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$access_token}",
                "Content-Type: application/json"
            ]);
            curl_exec($chReg);
            curl_close($chReg);
        } catch (\Throwable $regEx) {}

        // Create log notification for user
        $pdo->prepare("
            INSERT INTO notifications (user_id, role, title, message, type, unread)
            VALUES (?, ?, 'WhatsApp Cloud API Connected', ?, 'success', 1)
        ")->execute([
            $user_id,
            $_SESSION['user_role'] ?? 'Admin',
            "Meta Embedded Signup completed for {$display_phone_number} ({$verified_name})."
        ]);

        if ($isGetRedirect) {
            header("Location: ../index.php?page=whatsapp_settings&status=connected&phone=" . urlencode($display_phone_number));
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Meta Embedded Signup successful! WhatsApp Cloud API is now fully connected.',
            'data' => [
                'waba_id' => $waba_id,
                'phone_number_id' => $phone_number_id,
                'display_phone_number' => $display_phone_number,
                'verified_name' => $verified_name,
                'status' => 'active'
            ]
        ]);
        exit;

    } elseif ($action === 'manual_save') {
        $waba_id = trim($data['waba_id'] ?? '');
        $phone_number_id = trim($data['phone_number_id'] ?? '');
        $access_token = trim($data['access_token'] ?? '');

        if (empty($waba_id) || empty($phone_number_id) || empty($access_token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'WABA ID, Phone Number ID, and Permanent Access Token are required.']);
            exit;
        }

        // Test credentials with Meta Graph API
        $metaUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}?fields=display_phone_number,verified_name";
        $ch = curl_init($metaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
        $metaRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $metaData = json_decode($metaRes, true);
        if ($httpCode !== 200 && isset($metaData['error'])) {
            echo json_encode([
                'success' => false, 
                'message' => 'Meta API Validation Failed: ' . ($metaData['error']['message'] ?? 'Invalid Phone Number ID or Token.')
            ]);
            exit;
        }

        $display_phone_number = $metaData['display_phone_number'] ?? 'WhatsApp API Active';
        $verified_name = $metaData['verified_name'] ?? $firm_name ?: 'Marg ERP Merchant';

        $stmt = $pdo->prepare("
            INSERT INTO tenant_whatsapp_configs 
            (user_id, firm_name, marg_license_no, waba_id, phone_number_id, display_phone_number, verified_name, access_token, signup_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', 'active')
            ON DUPLICATE KEY UPDATE 
                firm_name = VALUES(firm_name),
                marg_license_no = VALUES(marg_license_no),
                waba_id = VALUES(waba_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name),
                access_token = VALUES(access_token),
                signup_method = 'manual',
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $user_id,
            $firm_name,
            $marg_license_no,
            $waba_id,
            $phone_number_id,
            $display_phone_number,
            $verified_name,
            $access_token
        ]);

        // Auto-sync into merchant_waba_settings table for Marg ERP Gateway & Campaign API compatibility
        $stmtSync = $pdo->prepare("
            INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, business_phone)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                phone_number_id = VALUES(phone_number_id),
                waba_id = VALUES(waba_id),
                access_token = VALUES(access_token),
                business_phone = VALUES(business_phone)
        ");
        $stmtSync->execute([$user_id, $phone_number_id, $waba_id, $access_token, $display_phone_number]);

        echo json_encode([
            'success' => true,
            'message' => 'WhatsApp Cloud API Manual credentials saved & verified successfully!',
            'data' => [
                'display_phone_number' => $display_phone_number,
                'verified_name' => $verified_name,
                'status' => 'active'
            ]
        ]);
        exit;

    } elseif ($action === 'disconnect') {
        $stmt = $pdo->prepare("UPDATE tenant_whatsapp_configs SET status = 'disabled' WHERE user_id = ?");
        $stmt->execute([$user_id]);

        $pdo->prepare("UPDATE merchant_waba_settings SET phone_number_id = '', waba_id = '', access_token = '' WHERE user_id = ?")->execute([$user_id]);

        echo json_encode(['success' => true, 'message' => 'WhatsApp Cloud API connection disconnected successfully.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
    exit;
}
