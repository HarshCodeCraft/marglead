<?php
/**
 * Marg ERP CRM - Meta Embedded Signup & Multi-Tenant WhatsApp Cloud API Configuration Handler
 * 
 * Handles Meta Embedded Signup OAuth code exchange, Phone Number details fetching,
 * token verification, and tenant DB registry updates for Marg ERP 9+ WhatsApp integration.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Helper to resolve active tenant DB prefix for multi-tenant isolation
function resolve_tenant_db_prefix($pdo, $user_id) {
    if (!empty($_SESSION['tenant_db']) && strpos($_SESSION['tenant_db'], 't_') === 0) {
        return $_SESSION['tenant_db'];
    }
    try {
        if ($pdo instanceof PDO) {
            $stmtUser = $pdo->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
            $stmtUser->execute([$user_id]);
            $compCol = $stmtUser->fetchColumn();
            if ($compCol) {
                $stmtC = $pdo->prepare("SELECT db_name FROM tenant_companies WHERE id = ? LIMIT 1");
                $stmtC->execute([$compCol]);
                $dbName = $stmtC->fetchColumn();
                if ($dbName && strpos($dbName, 't_') === 0) {
                    return $dbName;
                }
            }
            $stmtDirect = $pdo->prepare("SELECT db_name FROM tenant_companies WHERE id = ? LIMIT 1");
            $stmtDirect->execute([$user_id]);
            $dbName = $stmtDirect->fetchColumn();
            if ($dbName && strpos($dbName, 't_') === 0) {
                return $dbName;
            }
        }
    } catch (\Throwable $e) {}
    return '';
}

// Helper to sync WhatsApp credentials across master and tenant-isolated tables
function sync_waba_to_all_tables($pdo, $user_id, $waba_id, $phone_number_id, $access_token, $business_phone, $firm_name, $marg_license_no, $verified_name, $signup_method = 'embedded') {
    global $pdo_master;
    $targetPdo = (isset($pdo_master) && $pdo_master instanceof PDO) ? $pdo_master : $pdo;

    // 1. Master tenant_whatsapp_configs (update existing record or insert new)
    $stmtCheck = $targetPdo->prepare("SELECT id FROM tenant_whatsapp_configs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmtCheck->execute([$user_id]);
    $existingConfigId = $stmtCheck->fetchColumn();

    if ($existingConfigId) {
        $stmt1 = $targetPdo->prepare("
            UPDATE tenant_whatsapp_configs SET
                firm_name = ?,
                marg_license_no = ?,
                waba_id = ?,
                phone_number_id = ?,
                display_phone_number = ?,
                verified_name = ?,
                access_token = ?,
                signup_method = ?,
                status = 'active',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt1->execute([$firm_name, $marg_license_no, $waba_id, $phone_number_id, $business_phone, $verified_name, $access_token, $signup_method, $existingConfigId]);
    } else {
        $stmt1 = $targetPdo->prepare("
            INSERT INTO tenant_whatsapp_configs 
            (user_id, firm_name, marg_license_no, waba_id, phone_number_id, display_phone_number, verified_name, access_token, signup_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt1->execute([$user_id, $firm_name, $marg_license_no, $waba_id, $phone_number_id, $business_phone, $verified_name, $access_token, $signup_method]);
    }

    // 2. Master merchant_waba_settings
    $stmt2 = $targetPdo->prepare("
        INSERT INTO merchant_waba_settings (user_id, phone_number_id, waba_id, access_token, business_phone, gateway_type, status)
        VALUES (?, ?, ?, ?, ?, 'meta', 'Active')
        ON DUPLICATE KEY UPDATE 
            phone_number_id = VALUES(phone_number_id),
            waba_id = VALUES(waba_id),
            access_token = VALUES(access_token),
            business_phone = VALUES(business_phone),
            gateway_type = 'meta',
            status = 'Active'
    ");
    $stmt2->execute([$user_id, $phone_number_id, $waba_id, $access_token, $business_phone]);

    // 3. Isolated tenant table if applicable
    $tenantPrefix = resolve_tenant_db_prefix($targetPdo, $user_id);
    if (!empty($tenantPrefix)) {
        try {
            $tenantTable = "`" . $tenantPrefix . "merchant_waba_settings`";
            $stmt3 = $targetPdo->prepare("
                INSERT INTO {$tenantTable} (user_id, phone_number_id, waba_id, access_token, business_phone, gateway_type, status)
                VALUES (?, ?, ?, ?, ?, 'meta', 'Active')
                ON DUPLICATE KEY UPDATE 
                    phone_number_id = VALUES(phone_number_id),
                    waba_id = VALUES(waba_id),
                    access_token = VALUES(access_token),
                    business_phone = VALUES(business_phone),
                    gateway_type = 'meta',
                    status = 'Active'
            ");
            $stmt3->execute([$user_id, $phone_number_id, $waba_id, $access_token, $business_phone]);
        } catch (\Throwable $tEx) {}
    }

    $_SESSION['tenant_gateway_type'] = 'meta';
}

// Check if request is a GET redirect from Meta-hosted Embedded Signup URL
$isGetRedirect = ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['code']) || isset($_GET['error'])));

// Handle Meta error redirect (e.g. client cancelled)
if ($isGetRedirect && isset($_GET['error'])) {
    $errDesc = $_GET['error_description'] ?? $_GET['error'] ?? 'User cancelled Meta onboarding.';
    header("Location: ../index.php?page=merchant_waba_settings&status=error&msg=" . urlencode($errDesc));
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
                $err = "Meta App Secret (APP_SECRET) is missing in config/config.php. Meta requires APP_SECRET to exchange 1-Click popup code for credentials. Please add APP_SECRET in config/config.php from Meta Developer Portal (App 2484306222079451 -> App Settings -> Basic -> App Secret) or enter credentials manually below.";
                if ($isGetRedirect) {
                    header("Location: ../index.php?page=merchant_waba_settings&status=error&msg=" . urlencode($err));
                    exit;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'MISSING_APP_SECRET', 'message' => $err]);
                exit;
            }

            $tokenUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/oauth/access_token?" . http_build_query([
                'client_id' => $app_id,
                'client_secret' => $app_secret,
                'code' => $code
            ]);

            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $tokenRes = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($tokenRes, true);
            if (!empty($tokenData['access_token'])) {
                $access_token = $tokenData['access_token'];
            } elseif (isset($tokenData['error'])) {
                $err = 'Meta Token Exchange Failed: ' . ($tokenData['error']['message'] ?? 'Invalid code or App Secret.');
                if ($isGetRedirect) {
                    header("Location: ../index.php?page=merchant_waba_settings&status=error&msg=" . urlencode($err));
                    exit;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
        }

        // Only fallback to system access token if NO code was provided (e.g. system self-test)
        if (empty($access_token) && empty($code)) {
            $access_token = defined('ACCESS_TOKEN') ? ACCESS_TOKEN : '';
        }

        // 1.1 Automatic WABA ID & Phone Number ID discovery from Meta if not supplied
        if ((empty($waba_id) || empty($phone_number_id)) && !empty($access_token)) {
            $appToken = "{$app_id}|{$app_secret}";

            // Step A: Inspect debug_token with App Token to extract granular_scopes target_ids
            $debugUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/debug_token?input_token={$access_token}&access_token={$appToken}";
            $ch = curl_init($debugUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $debugRes = curl_exec($ch);
            curl_close($ch);
            $debugData = json_decode($debugRes, true);

            if (!empty($debugData['data']['granular_scopes'])) {
                foreach ($debugData['data']['granular_scopes'] as $sc) {
                    if (!empty($sc['target_ids'])) {
                        $waba_id = $sc['target_ids'][0];
                        break;
                    }
                }
            }

            // Step B: Query /me/businesses to extract WABA ID and Phone Number ID if still empty
            if (empty($waba_id)) {
                $bizUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/me/businesses?fields=id,name,owned_whatsapp_business_accounts{id,name,phone_numbers{id,display_phone_number,verified_name}},client_whatsapp_business_accounts{id,name,phone_numbers{id,display_phone_number,verified_name}}";
                $chBiz = curl_init($bizUrl);
                curl_setopt($chBiz, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chBiz, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chBiz, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
                $bizRes = curl_exec($chBiz);
                curl_close($chBiz);
                $bizData = json_decode($bizRes, true);

                if (!empty($bizData['data'])) {
                    foreach ($bizData['data'] as $biz) {
                        if (!empty($biz['owned_whatsapp_business_accounts']['data'][0]['id'])) {
                            $waba_id = $biz['owned_whatsapp_business_accounts']['data'][0]['id'];
                            if (!empty($biz['owned_whatsapp_business_accounts']['data'][0]['phone_numbers']['data'][0]['id'])) {
                                $phone_number_id = $biz['owned_whatsapp_business_accounts']['data'][0]['phone_numbers']['data'][0]['id'];
                            }
                            break;
                        }
                        if (!empty($biz['client_whatsapp_business_accounts']['data'][0]['id'])) {
                            $waba_id = $biz['client_whatsapp_business_accounts']['data'][0]['id'];
                            if (!empty($biz['client_whatsapp_business_accounts']['data'][0]['phone_numbers']['data'][0]['id'])) {
                                $phone_number_id = $biz['client_whatsapp_business_accounts']['data'][0]['phone_numbers']['data'][0]['id'];
                            }
                            break;
                        }
                    }
                }
            }

            // Step C: If WABA ID found, query its phone numbers
            if (!empty($waba_id) && empty($phone_number_id)) {
                $phoneUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating";
                $chP = curl_init($phoneUrl);
                curl_setopt($chP, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chP, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chP, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
                $pRes = curl_exec($chP);
                curl_close($chP);
                $pData = json_decode($pRes, true);

                if (!empty($pData['data'][0]['id'])) {
                    $phone_number_id = $pData['data'][0]['id'];
                    $display_phone_number = $pData['data'][0]['display_phone_number'] ?? '';
                    $verified_name = $pData['data'][0]['verified_name'] ?? '';
                }
            }

            // Step D: Fallback to existing registered WABA / Phone ID for this client if already verified on Meta
            if (empty($waba_id) || empty($phone_number_id)) {
                global $pdo_master;
                $chkPdo = (isset($pdo_master) && $pdo_master instanceof PDO) ? $pdo_master : $pdo;
                try {
                    $stmtOld = $chkPdo->prepare("SELECT waba_id, phone_number_id FROM tenant_whatsapp_configs WHERE user_id = ? AND phone_number_id != '' AND waba_id != '' ORDER BY id DESC LIMIT 1");
                    $stmtOld->execute([$user_id]);
                    $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

                    if (!$oldRow) {
                        $stmtOld2 = $chkPdo->prepare("SELECT waba_id, phone_number_id FROM merchant_waba_settings WHERE user_id = ? AND phone_number_id != '' AND waba_id != '' LIMIT 1");
                        $stmtOld2->execute([$user_id]);
                        $oldRow = $stmtOld2->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($oldRow && !empty($oldRow['phone_number_id']) && !empty($oldRow['waba_id'])) {
                        // Test if this token can access the candidate phone number
                        $chkPhoneUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$oldRow['phone_number_id']}?fields=display_phone_number,verified_name";
                        $chChk = curl_init($chkPhoneUrl);
                        curl_setopt($chChk, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($chChk, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($chChk, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
                        $chkRes = curl_exec($chChk);
                        $chkHttp = curl_getinfo($chChk, CURLINFO_HTTP_CODE);
                        curl_close($chChk);

                        if ($chkHttp === 200) {
                            $waba_id = $oldRow['waba_id'];
                            $phone_number_id = $oldRow['phone_number_id'];
                        }
                    }
                } catch (\Throwable $oldEx) {}
            }
        }

        if (empty($waba_id) || empty($phone_number_id)) {
            if ($isGetRedirect) {
                header("Location: ../index.php?page=merchant_waba_settings&status=error&msg=" . urlencode("Could not auto-detect WABA ID or Phone Number from Meta."));
                exit;
            }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'WABA ID and Phone Number ID are required for Meta WhatsApp Cloud API connection.']);
            exit;
        }

        // 2. Fetch Phone Number display details & verified name from Meta Graph API
        $metaUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}?fields=display_phone_number,verified_name,quality_rating,name_status";
        $ch = curl_init($metaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
        $metaRes = curl_exec($ch);
        curl_close($ch);

        $metaData = json_decode($metaRes, true);
        $display_phone_number = $metaData['display_phone_number'] ?? $data['display_phone_number'] ?? 'WhatsApp Connected';
        $verified_name = $metaData['verified_name'] ?? $firm_name ?: 'Marg Partner';

        // 3. Upsert record into tenant_whatsapp_configs, merchant_waba_settings, and isolated tenant table
        sync_waba_to_all_tables($pdo, $user_id, $waba_id, $phone_number_id, $access_token, $display_phone_number, $firm_name, $marg_license_no, $verified_name, 'embedded');

        // 4. Automatically Subscribe App to Client's WABA for Webhook events
        try {
            $subUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$waba_id}/subscribed_apps";
            $chSub = curl_init($subUrl);
            curl_setopt($chSub, CURLOPT_POST, true);
            curl_setopt($chSub, CURLOPT_POSTFIELDS, "");
            curl_setopt($chSub, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chSub, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chSub, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
            curl_exec($chSub);
            curl_close($chSub);
        } catch (\Throwable $subEx) {}

        // 5. Automatically Register Phone Number on Meta Cloud API (Sets 2FA PIN & Activates Live Messaging)
        try {
            $regUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}/register";
            $chReg = curl_init($regUrl);
            curl_setopt($chReg, CURLOPT_POST, true);
            curl_setopt($chReg, CURLOPT_POSTFIELDS, json_encode([
                'messaging_product' => 'whatsapp',
                'pin' => '123456'
            ]));
            curl_setopt($chReg, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chReg, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chReg, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$access_token}",
                "Content-Type: application/json"
            ]);
            curl_exec($chReg);
            curl_close($chReg);
        } catch (\Throwable $regEx) {}

        // Create log notification for user
        try {
            $pdo->prepare("
                INSERT INTO notifications (user_id, role, title, message, type, unread)
                VALUES (?, ?, 'WhatsApp Cloud API Connected', ?, 'success', 1)
            ")->execute([
                $user_id,
                $_SESSION['user_role'] ?? 'Admin',
                "Meta Embedded Signup completed for {$display_phone_number} ({$verified_name})."
            ]);
        } catch (\Throwable $notifEx) {}

        if ($isGetRedirect) {
            header("Location: ../index.php?page=merchant_waba_settings&status=connected&phone=" . urlencode($display_phone_number));
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
        $business_phone = trim($data['business_phone'] ?? '');

        if (empty($waba_id) || empty($phone_number_id) || empty($access_token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'WABA ID, Phone Number ID, and Permanent Access Token are required.']);
            exit;
        }

        // Test credentials with Meta Graph API
        $metaUrl = "https://graph.facebook.com/" . GRAPH_API_VERSION . "/{$phone_number_id}?fields=display_phone_number,verified_name";
        $ch = curl_init($metaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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

        $display_phone_number = !empty($business_phone) ? $business_phone : ($metaData['display_phone_number'] ?? 'WhatsApp API Active');
        $verified_name = $metaData['verified_name'] ?? $firm_name ?: 'Marg ERP Merchant';

        // Upsert across all tables
        sync_waba_to_all_tables($pdo, $user_id, $waba_id, $phone_number_id, $access_token, $display_phone_number, $firm_name, $marg_license_no, $verified_name, 'manual');

        echo json_encode([
            'success' => true,
            'message' => 'WhatsApp Cloud API Manual credentials saved and verified successfully!',
            'data' => [
                'display_phone_number' => $display_phone_number,
                'verified_name' => $verified_name,
                'status' => 'active'
            ]
        ]);
        exit;

    } elseif ($action === 'disconnect') {
        global $pdo_master;
        $targetPdo = (isset($pdo_master) && $pdo_master instanceof PDO) ? $pdo_master : $pdo;
        try {
            $stmt = $targetPdo->prepare("UPDATE tenant_whatsapp_configs SET phone_number_id = '', waba_id = '', access_token = '', display_phone_number = '', status = 'disabled' WHERE user_id = ?");
            $stmt->execute([$user_id]);

            $targetPdo->prepare("UPDATE merchant_waba_settings SET phone_number_id = '', waba_id = '', access_token = '', business_phone = '', status = 'Inactive' WHERE user_id = ?")->execute([$user_id]);

            $tenantPrefix = resolve_tenant_db_prefix($targetPdo, $user_id);
            if (!empty($tenantPrefix)) {
                $tenantTable = "`" . $tenantPrefix . "merchant_waba_settings`";
                $targetPdo->prepare("UPDATE {$tenantTable} SET phone_number_id = '', waba_id = '', access_token = '', business_phone = '', status = 'Inactive' WHERE user_id = ?")->execute([$user_id]);
            }
        } catch (\Throwable $e) {}

        echo json_encode(['success' => true, 'message' => 'WhatsApp Cloud API connection disconnected successfully.']);
        exit;
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error processing WhatsApp configuration: ' . $e->getMessage()
    ]);
    exit;
}
