<?php
/**
 * MargLead Social Media & Ads API Gateway
 * Strict Multi-Tenant Isolation & Zero Server Storage Overhead
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login to access social media services.']);
    exit;
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 1);
$user_id   = (int)$_SESSION['user_id'];
$action    = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable.']);
    exit;
}

/**
 * Standard cURL helper with strict timeout and TLS security
 */
function sendGraphRequest(string $url, string $method = 'GET', array $params = [], ?string $token = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($params)) {
            $hasFile = false;
            foreach ($params as $v) {
                if ($v instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }
            if ($hasFile) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }
    } elseif ($method === 'GET' && !empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Network error: ' . $error, 'http_code' => $httpCode];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Invalid JSON from upstream service.', 'raw' => $raw, 'http_code' => $httpCode];
    }

    if (isset($decoded['error'])) {
        return ['success' => false, 'error' => $decoded['error']['message'] ?? 'Meta API error.', 'meta_error' => $decoded['error'], 'http_code' => $httpCode];
    }

    return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
}

try {
    switch ($action) {
        // -------------------------------------------------------------
        // 1. List Connected Accounts
        // -------------------------------------------------------------
        case 'get_accounts':
            $stmt = $pdo->prepare("
                SELECT id, platform, account_id, account_name, username, profile_picture_url, 
                       followers_count, is_active, created_at, updated_at
                FROM social_accounts 
                WHERE tenant_id = ? 
                ORDER BY platform ASC, id DESC
            ");
            $stmt->execute([$tenant_id]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'accounts' => $accounts]);
            break;

        // -------------------------------------------------------------
        // 1.1 Fetch Pages Automatically via Facebook User Access Token
        // -------------------------------------------------------------
        case 'fetch_user_pages':
            $userToken = trim($_POST['user_access_token'] ?? '');
            if (empty($userToken)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User access token is missing.']);
                exit;
            }

            $graphVersion = defined('GRAPH_API_VERSION') ? GRAPH_API_VERSION : 'v20.0';
            
            // 1. Primary Query: Fetch Pages via me/accounts
            $url = "https://graph.facebook.com/{$graphVersion}/me/accounts?" . http_build_query([
                'fields' => 'id,name,access_token,picture{url}',
                'access_token' => $userToken
            ]);

            $res = sendGraphRequest($url, 'GET');
            $pagesData = ($res['success'] && !empty($res['data']['data'])) ? $res['data']['data'] : [];

            // 2. Secondary Fallback: If me/accounts returned empty (common on New Pages Experience / NPE),
            // inspect debug_token to see if target_ids were granted for pages_show_list
            $targetPageIds = [];
            $fbUserName = '';
            
            // Retrieve Facebook User details for precise client feedback
            $meUrl = "https://graph.facebook.com/{$graphVersion}/me?fields=id,name&access_token=" . urlencode($userToken);
            $meRes = sendGraphRequest($meUrl, 'GET');
            if ($meRes['success'] && !empty($meRes['data']['name'])) {
                $fbUserName = $meRes['data']['name'];
            }

            $debugUrl = "https://graph.facebook.com/{$graphVersion}/debug_token?" . http_build_query([
                'input_token' => $userToken,
                'access_token' => $userToken
            ]);
            $debugRes = sendGraphRequest($debugUrl, 'GET');
            if ($debugRes['success'] && !empty($debugRes['data']['data']['granular_scopes'])) {
                foreach ($debugRes['data']['data']['granular_scopes'] as $sc) {
                    if (($sc['scope'] ?? '') === 'pages_show_list' && !empty($sc['target_ids'])) {
                        $targetPageIds = $sc['target_ids'];
                        break;
                    }
                }
            }

            // If me/accounts was empty but debug_token contains granted target_ids, fetch pages individually
            if (empty($pagesData) && !empty($targetPageIds)) {
                foreach ($targetPageIds as $pId) {
                    $pUrl = "https://graph.facebook.com/{$graphVersion}/{$pId}?" . http_build_query([
                        'fields' => 'id,name,access_token,picture{url}',
                        'access_token' => $userToken
                    ]);
                    $pRes = sendGraphRequest($pUrl, 'GET');
                    if ($pRes['success'] && !empty($pRes['data']['id'])) {
                        $pagesData[] = $pRes['data'];
                    }
                }
            }

            // 3. If still empty, deliver actionable diagnostic guidance
            if (empty($pagesData)) {
                // Check if pages_show_list was accepted at all
                $permUrl = "https://graph.facebook.com/{$graphVersion}/me/permissions?access_token=" . urlencode($userToken);
                $permRes = sendGraphRequest($permUrl, 'GET');
                $hasPagePerm = false;
                if ($permRes['success'] && !empty($permRes['data']['data'])) {
                    foreach ($permRes['data']['data'] as $pItem) {
                        if (($pItem['permission'] ?? '') === 'pages_show_list' && ($pItem['status'] ?? '') === 'granted') {
                            $hasPagePerm = true;
                            break;
                        }
                    }
                }

                $userLabel = $fbUserName ? "account ('{$fbUserName}')" : "Facebook account";
                if (!$hasPagePerm) {
                    $diagnosticMsg = "The Page permission ('pages_show_list') was declined for {$userLabel}. Please click Continue with Facebook again and allow page access.";
                } elseif (empty($targetPageIds)) {
                    $diagnosticMsg = "Logged in as {$userLabel}, but no specific Pages were checked in the Meta authorization screen. In Meta's login dialog, you must check the box next to your Pages (e.g. 'Friendly Ai Solution' or 'Future Mind Solution Pvt Ltd'). If Meta didn't show the checkboxes, remove this app under facebook.com/settings?tab=applications and try again.";
                } else {
                    $diagnosticMsg = "Granted access to Page IDs (" . implode(', ', $targetPageIds) . "), but Meta Graph API did not return Page details. If this Meta App is in Development mode, ensure your Facebook account is added to the App Roles.";
                }

                echo json_encode([
                    'success' => false,
                    'message' => $diagnosticMsg,
                    'pages' => [],
                    'count' => 0
                ]);
                exit;
            }

            // 4. Check for linked Instagram accounts on discovered pages
            $formattedPages = [];
            foreach ($pagesData as $p) {
                $igAccount = null;
                $pageTok = !empty($p['access_token']) ? $p['access_token'] : $userToken;
                
                // Query linked Instagram account using page token with multi-endpoint fallback
                $igUrl = "https://graph.facebook.com/{$graphVersion}/{$p['id']}?" . http_build_query([
                    'fields' => 'instagram_business_account{id,username,profile_picture_url},connected_instagram_account{id,username,profile_pic},page_backed_instagram_accounts{id,username}',
                    'access_token' => $pageTok
                ]);
                $igRes = sendGraphRequest($igUrl, 'GET');
                if ($igRes['success'] && !empty($igRes['data'])) {
                    $igData = $igRes['data']['instagram_business_account'] 
                           ?? $igRes['data']['connected_instagram_account'] 
                           ?? (!empty($igRes['data']['page_backed_instagram_accounts']['data'][0]) ? $igRes['data']['page_backed_instagram_accounts']['data'][0] : null);

                    if (!empty($igData['id'])) {
                        $igAccount = [
                            'id' => $igData['id'],
                            'username' => $igData['username'] ?? '',
                            'picture_url' => $igData['profile_picture_url'] ?? $igData['profile_pic'] ?? ''
                        ];
                    }
                }

                $formattedPages[] = [
                    'page_id' => $p['id'],
                    'page_name' => $p['name'],
                    'page_access_token' => $p['access_token'] ?? '',
                    'picture_url' => $p['picture']['data']['url'] ?? '',
                    'instagram_account' => $igAccount
                ];
            }

            echo json_encode([
                'success' => true,
                'pages' => $formattedPages,
                'count' => count($formattedPages)
            ]);
            break;

        // -------------------------------------------------------------
        // 2. Connect / Update Social Account
        // -------------------------------------------------------------
        case 'connect_account':
            $platform    = trim($_POST['platform'] ?? 'facebook_page');
            $accountId   = trim($_POST['account_id'] ?? '');
            $accountName = trim($_POST['account_name'] ?? '');
            $username    = trim($_POST['username'] ?? '');
            $accessToken = trim($_POST['access_token'] ?? '');
            $picUrl      = trim($_POST['profile_picture_url'] ?? '');

            if (empty($accountId) || empty($accountName) || empty($accessToken)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Account ID, Name, and Access Token are required.']);
                exit;
            }

            if (!in_array($platform, ['facebook_page', 'instagram', 'linkedin'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unsupported platform specified.']);
                exit;
            }

            $graphVersion = defined('GRAPH_API_VERSION') ? GRAPH_API_VERSION : 'v20.0';

            // Auto-exchange: If a User Access Token was provided for a Facebook Page, automatically exchange it for the Page Access Token
            if ($platform === 'facebook_page') {
                $checkPageUrl = "https://graph.facebook.com/{$graphVersion}/{$accountId}?" . http_build_query([
                    'fields' => 'access_token',
                    'access_token' => $accessToken
                ]);
                $pageRes = sendGraphRequest($checkPageUrl, 'GET');
                if ($pageRes['success'] && !empty($pageRes['data']['access_token'])) {
                    $accessToken = $pageRes['data']['access_token'];
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO social_accounts 
                    (tenant_id, user_id, platform, account_id, account_name, username, profile_picture_url, access_token, is_active)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    account_name = VALUES(account_name),
                    username = VALUES(username),
                    profile_picture_url = VALUES(profile_picture_url),
                    access_token = VALUES(access_token),
                    is_active = 1,
                    updated_at = NOW()
            ");
            $stmt->execute([$tenant_id, $user_id, $platform, $accountId, $accountName, $username, $picUrl, $accessToken]);

            echo json_encode(['success' => true, 'message' => 'Social account connected successfully.']);
            break;

        // -------------------------------------------------------------
        // 3. Disconnect / Remove Account
        // -------------------------------------------------------------
        case 'disconnect_account':
            $accId = (int)($_POST['id'] ?? 0);
            if ($accId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid account ID.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM social_accounts WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$accId, $tenant_id]);

            echo json_encode(['success' => true, 'message' => 'Account disconnected successfully.']);
            break;

        // -------------------------------------------------------------
        // 4. Publish or Schedule Post / Reel
        // -------------------------------------------------------------
        case 'publish_post':
            $rawAccId   = trim($_POST['account_id'] ?? '');
            $postType   = trim($_POST['post_type'] ?? 'post');
            $caption    = trim($_POST['caption'] ?? '');
            $publishMode= trim($_POST['publish_mode'] ?? 'now'); // 'now' or 'schedule'
            $scheduledAt= trim($_POST['scheduled_at'] ?? '');

            if (empty($rawAccId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please select a social channel.']);
                exit;
            }

            // Fetch target accounts (single or all connected channels)
            $targetAccounts = [];
            if ($rawAccId === 'all') {
                $stmtAccs = $pdo->prepare("SELECT * FROM social_accounts WHERE tenant_id = ? AND is_active = 1");
                $stmtAccs->execute([$tenant_id]);
                $targetAccounts = $stmtAccs->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmtAcc = $pdo->prepare("SELECT * FROM social_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1 LIMIT 1");
                $stmtAcc->execute([(int)$rawAccId, $tenant_id]);
                $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    $targetAccounts = [$acc];
                }
            }

            if (empty($targetAccounts)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Target social account(s) not found or inactive.']);
                exit;
            }

            // Handle temporary media upload if provided
            $tempPath = null;
            $mediaUrl = null;
            $mimeType = null;
            if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['media_file'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime'];
                if (!in_array($mimeType, $allowedMimes)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid file format. Allowed: JPG, PNG, WEBP, MP4.']);
                    exit;
                }

                // Temporary file buffer for immediate dispatch
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $cleanFilename = 'tmp_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $targetDir = __DIR__ . '/../uploads/tmp/';
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                $tempPath = $targetDir . $cleanFilename;
                if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Could not process media upload.']);
                    exit;
                }
            }

            $graphVersion = defined('GRAPH_API_VERSION') ? GRAPH_API_VERSION : 'v20.0';
            $publishedResults = [];
            $publishErrors = [];

            // Instant Publish Flow
            if ($publishMode === 'now') {
                foreach ($targetAccounts as $acc) {
                    $externalPostId = null;
                    $permalink = null;
                    $accId = (int)$acc['id'];

                    if ($acc['platform'] === 'facebook_page') {
                        $pageId = $acc['account_id'];
                        $token  = $acc['access_token'];

                        if ($tempPath && file_exists($tempPath)) {
                            if (strpos($mimeType, 'video') !== false) {
                                $endpoint = "https://graph.facebook.com/{$graphVersion}/{$pageId}/videos";
                                $params = [
                                    'description' => $caption,
                                    'source' => new CURLFile($tempPath, $mimeType, basename($tempPath))
                                ];
                            } else {
                                $endpoint = "https://graph.facebook.com/{$graphVersion}/{$pageId}/photos";
                                $params = [
                                    'caption' => $caption,
                                    'source' => new CURLFile($tempPath, $mimeType, basename($tempPath))
                                ];
                            }
                        } else {
                            $endpoint = "https://graph.facebook.com/{$graphVersion}/{$pageId}/feed";
                            $params = ['message' => $caption];
                        }

                        $res = sendGraphRequest($endpoint, 'POST', $params, $token);

                        // Auto-recovery: If #200 error occurred, attempt token exchange
                        if (!$res['success'] && !empty($res['error']) && strpos($res['error'], 'requires both pages_read_engagement and pages_manage_posts') !== false) {
                            $recoveryUrl = "https://graph.facebook.com/{$graphVersion}/{$pageId}?" . http_build_query([
                                'fields' => 'access_token',
                                'access_token' => $token
                            ]);
                            $recRes = sendGraphRequest($recoveryUrl, 'GET');
                            if ($recRes['success'] && !empty($recRes['data']['access_token'])) {
                                $newPageToken = $recRes['data']['access_token'];
                                $stmtUp = $pdo->prepare("UPDATE social_accounts SET access_token = ? WHERE id = ? AND tenant_id = ?");
                                $stmtUp->execute([$newPageToken, $accId, $tenant_id]);
                                $token = $newPageToken;
                                $res = sendGraphRequest($endpoint, 'POST', $params, $token);
                            }
                        }

                        if (!$res['success']) {
                            $publishErrors[] = $acc['account_name'] . ': ' . ($res['error'] ?? 'Unknown error');
                            continue;
                        }

                        $externalPostId = $res['data']['id'] ?? null;
                        $permalink = "https://facebook.com/" . $externalPostId;

                    } elseif ($acc['platform'] === 'instagram') {
                        $igUserId = $acc['account_id'];
                        $token = $acc['access_token'];

                        if (!$tempPath || !file_exists($tempPath)) {
                            $publishErrors[] = $acc['account_name'] . ': Instagram requires an image or video attachment.';
                            continue;
                        }

                        if (defined('BASE_URL') && !empty(BASE_URL) && strpos(BASE_URL, 'localhost') === false && strpos(BASE_URL, '127.0.0.1') === false) {
                            $publicMediaUrl = rtrim(BASE_URL, '/') . '/uploads/tmp/' . basename($tempPath);
                        } elseif (defined('NGROK_URL') && !empty(NGROK_URL)) {
                            $publicMediaUrl = rtrim(NGROK_URL, '/') . '/uploads/tmp/' . basename($tempPath);
                        } else {
                            $host = $_SERVER['HTTP_HOST'] ?? 'friendlyaisolution.com';
                            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $publicMediaUrl = "{$scheme}://{$host}/uploads/tmp/" . basename($tempPath);
                        }

                        $isVideo = (strpos($mimeType, 'video') !== false);
                        $mediaParams = [
                            'caption' => $caption,
                            'access_token' => $token
                        ];

                        if ($isVideo) {
                            $mediaParams['media_type'] = 'REELS';
                            $mediaParams['video_url'] = $publicMediaUrl;
                        } else {
                            $mediaParams['image_url'] = $publicMediaUrl;
                        }

                        // Step 1: Create Container
                        $containerUrl = "https://graph.facebook.com/{$graphVersion}/{$igUserId}/media";
                        $cRes = sendGraphRequest($containerUrl, 'POST', $mediaParams, $token);

                        if (!$cRes['success'] || empty($cRes['data']['id'])) {
                            $publishErrors[] = $acc['account_name'] . ': ' . ($cRes['error'] ?? 'Could not create IG media container');
                            continue;
                        }

                        $creationId = $cRes['data']['id'];

                        // Step 2: Publish Container
                        $publishUrl = "https://graph.facebook.com/{$graphVersion}/{$igUserId}/media_publish";
                        $pRes = sendGraphRequest($publishUrl, 'POST', [
                            'creation_id' => $creationId,
                            'access_token' => $token
                        ], $token);

                        if (!$pRes['success'] || empty($pRes['data']['id'])) {
                            $publishErrors[] = $acc['account_name'] . ': ' . ($pRes['error'] ?? 'Could not publish to IG');
                            continue;
                        }

                        $externalPostId = $pRes['data']['id'];
                        $permalink = "https://instagram.com/p/" . $externalPostId;
                    }

                    if ($externalPostId) {
                        $stmtPost = $pdo->prepare("
                            INSERT INTO social_posts 
                                (tenant_id, user_id, account_id, post_type, caption, external_post_id, permalink, status, published_at)
                            VALUES 
                                (?, ?, ?, ?, ?, ?, ?, 'published', NOW())
                        ");
                        $stmtPost->execute([$tenant_id, $user_id, $accId, $postType, $caption, $externalPostId, $permalink]);
                        $newPostId = (int)$pdo->lastInsertId();

                        $stmtAna = $pdo->prepare("
                            INSERT INTO social_analytics (tenant_id, post_id, external_post_id, impressions, reach, likes_count, comments_count, shares_count)
                            VALUES (?, ?, ?, 1, 1, 0, 0, 0)
                        ");
                        $stmtAna->execute([$tenant_id, $newPostId, $externalPostId]);

                        $publishedResults[] = [
                            'account' => $acc['account_name'],
                            'post_id' => $newPostId,
                            'permalink' => $permalink
                        ];
                    }
                }

                // Purge temp buffer after all dispatches
                if ($tempPath && file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                if (empty($publishedResults)) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Publishing failed: ' . implode(' | ', $publishErrors)
                    ]);
                    exit;
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Successfully published to ' . count($publishedResults) . ' channel(s)!',
                    'results' => $publishedResults,
                    'permalink' => $publishedResults[0]['permalink'] ?? ''
                ]);
                exit;
            } else {
                // Scheduled Flow
                if (empty($scheduledAt) || strtotime($scheduledAt) <= time()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Please select a valid future date and time for scheduling.']);
                    exit;
                }

                $stmtPost = $pdo->prepare("
                    INSERT INTO social_posts 
                        (tenant_id, user_id, account_id, post_type, caption, status, scheduled_at)
                    VALUES 
                        (?, ?, ?, ?, ?, 'scheduled', ?)
                ");
                $stmtPost->execute([$tenant_id, $user_id, $accId, $postType, $caption, date('Y-m-d H:i:s', strtotime($scheduledAt))]);
                $newPostId = (int)$pdo->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'message' => 'Post scheduled successfully for ' . htmlspecialchars($scheduledAt) . '.',
                    'post_id' => $newPostId
                ]);
            }
            break;

        // -------------------------------------------------------------
        // 5. Get Feed and Performance Insights
        // -------------------------------------------------------------
        case 'get_feed':
            $stmt = $pdo->prepare("
                SELECT p.*, a.account_name, a.platform, a.profile_picture_url,
                       COALESCE(an.likes_count, 0) as likes_count,
                       COALESCE(an.comments_count, 0) as comments_count,
                       COALESCE(an.shares_count, 0) as shares_count,
                       COALESCE(an.reach, 0) as reach,
                       COALESCE(an.impressions, 0) as impressions
                FROM social_posts p
                LEFT JOIN social_accounts a ON p.account_id = a.id
                LEFT JOIN social_analytics an ON p.id = an.post_id
                WHERE p.tenant_id = ?
                ORDER BY p.id DESC
                LIMIT 50
            ");
            $stmt->execute([$tenant_id]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'feed' => $posts]);
            break;

        // -------------------------------------------------------------
        // 6. Easy Ads Launcher (Create 3-Step Ad Campaign)
        // -------------------------------------------------------------
        case 'create_ad_campaign':
            $campaignName = trim($_POST['campaign_name'] ?? '');
            $objective    = trim($_POST['objective'] ?? 'MESSAGES');
            $budget       = floatval($_POST['daily_budget'] ?? 300.00);
            $targetCity   = trim($_POST['target_city'] ?? 'All India');
            $adAccountId  = trim($_POST['ad_account_id'] ?? 'act_default');

            if (empty($campaignName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Campaign name is required.']);
                exit;
            }

            if ($budget < 100.00 || $budget > 100000.00) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Daily budget must be between INR 100 and INR 100,000.']);
                exit;
            }

            if (!in_array($objective, ['MESSAGES', 'LEAD_GENERATION', 'OUTCOME_TRAFFIC'])) {
                $objective = 'MESSAGES';
            }

            $stmt = $pdo->prepare("
                INSERT INTO social_ad_campaigns 
                    (tenant_id, user_id, ad_account_id, campaign_name, objective, daily_budget_inr, target_city, status)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')
            ");
            $stmt->execute([$tenant_id, $user_id, $adAccountId, $campaignName, $objective, $budget, $targetCity]);
            $campaignId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Ad Campaign activated successfully.',
                'campaign_id' => $campaignId
            ]);
            break;

        // -------------------------------------------------------------
        // 7. List Ad Campaigns
        // -------------------------------------------------------------
        case 'get_ad_campaigns':
            $stmt = $pdo->prepare("
                SELECT * FROM social_ad_campaigns 
                WHERE tenant_id = ? 
                ORDER BY id DESC
            ");
            $stmt->execute([$tenant_id]);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'campaigns' => $campaigns]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or missing action parameter.']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
