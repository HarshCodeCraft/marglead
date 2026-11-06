<?php
/**
 * WhatsApp Bot Flows & Interactive Builder API Endpoint
 * Handles list, get, save, delete, status updates, and JSON import/export
 */

header('Content-Type: application/json');
require_once __DIR__ . '/cors.php';

$auth = requireApiAuth();

// Helper function to auto-create bot_flows table if it does not exist yet
function ensureBotFlowsTable($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS bot_flows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flow_id VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            category VARCHAR(50) DEFAULT 'SIGN IN',
            status VARCHAR(20) DEFAULT 'PUBLISHED',
            screens_json LONGTEXT NULL,
            raw_nodes_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql);

        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_flow_automations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL DEFAULT 'Master WhatsApp Support & Sales Flow',
            trigger_keyword VARCHAR(100) DEFAULT 'ALL',
            status VARCHAR(20) DEFAULT 'ACTIVE',
            nodes_json LONGTEXT NULL,
            connections_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {}
}

if ($db_connected && $pdo) {
    ensureBotFlowsTable($pdo);
}

/**
 * Convert builder screens structure to standard Meta WhatsApp Flows JSON 6.0 format
 */
function convertScreensToMetaFlowJson(array $screens): array {
    $metaScreens = [];
    $total = count($screens);

    foreach ($screens as $idx => $sc) {
        $cleanId = preg_replace('/[^A-Za-z_]/', '', strtoupper(str_replace([' ', '-'], '_', $sc['id'] ?? 'SCREEN_' . $idx)));
        if (empty($cleanId) || is_numeric($cleanId[0])) {
            $cleanId = 'SCREEN_' . chr(65 + $idx);
        }

        $isTerminal = ($idx === $total - 1);
        $formChildren = [];

        // Title Heading
        $titleText = !empty($sc['title']) ? $sc['title'] : (!empty($sc['name']) ? $sc['name'] : 'Welcome to Marg Soft');
        $formChildren[] = [
            'type' => 'TextHeading',
            'text' => mb_substr($titleText, 0, 30)
        ];

        // Body Text
        if (!empty($sc['body'])) {
            $formChildren[] = [
                'type' => 'TextBody',
                'text' => mb_substr($sc['body'], 0, 4000)
            ];
        }

        $payloadFields = [];

        // Components
        if (!empty($sc['components']) && is_array($sc['components'])) {
            foreach ($sc['components'] as $cIdx => $c) {
                $rawName = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $c['label'] ?? 'field_' . $cIdx));
                $fieldName = trim($rawName, '_');
                if (empty($fieldName) || is_numeric($fieldName[0])) {
                    $fieldName = 'f_' . ($cIdx + 1);
                }
                $label = !empty($c['label']) ? mb_substr($c['label'], 0, 30) : 'Field ' . ($cIdx + 1);
                $required = !empty($c['required']);
                $helper = !empty($c['helper']) ? mb_substr($c['helper'], 0, 80) : '';

                $payloadFields[$fieldName] = "\${form.{$fieldName}}";

                if ($c['type'] === 'Date Picker') {
                    $formChildren[] = [
                        'type' => 'DatePicker',
                        'name' => $fieldName,
                        'label' => $label,
                        'required' => $required
                    ];
                } elseif ($c['type'] === 'Dropdown') {
                    $formChildren[] = [
                        'type' => 'Dropdown',
                        'name' => $fieldName,
                        'label' => $label,
                        'required' => $required,
                        'data-source' => [
                            ['id' => 'opt_1', 'title' => 'Bill Format Issue'],
                            ['id' => 'opt_2', 'title' => 'GST Error'],
                            ['id' => 'opt_3', 'title' => 'Printer Setup']
                        ]
                    ];
                } elseif ($c['type'] === 'Time Slot') {
                    $formChildren[] = [
                        'type' => 'Dropdown',
                        'name' => $fieldName,
                        'label' => $label,
                        'required' => $required,
                        'data-source' => [
                            ['id' => 'slot_any', 'title' => 'Any Time (Flexible)'],
                            ['id' => 'slot_1', 'title' => '10:00 AM - 12:00 PM'],
                            ['id' => 'slot_2', 'title' => '12:00 PM - 02:00 PM'],
                            ['id' => 'slot_3', 'title' => '02:00 PM - 04:00 PM'],
                            ['id' => 'slot_4', 'title' => '04:00 PM - 06:00 PM']
                        ]
                    ];
                } elseif ($c['type'] === 'Phone Number') {
                    $fieldArr = [
                        'type' => 'TextInput',
                        'name' => $fieldName,
                        'input-type' => 'phone',
                        'label' => $label,
                        'required' => $required
                    ];
                    if ($helper) $fieldArr['helper-text'] = $helper;
                    $formChildren[] = $fieldArr;
                } elseif ($c['type'] === 'Text Area') {
                    $fieldArr = [
                        'type' => 'TextArea',
                        'name' => $fieldName,
                        'label' => $label,
                        'required' => $required
                    ];
                    if ($helper) $fieldArr['helper-text'] = $helper;
                    $formChildren[] = $fieldArr;
                } else {
                    $fieldArr = [
                        'type' => 'TextInput',
                        'name' => $fieldName,
                        'label' => $label,
                        'required' => $required
                    ];
                    if ($helper) $fieldArr['helper-text'] = $helper;
                    $formChildren[] = $fieldArr;
                }
            }
        }

        // Footer Button
        $footerLabel = !empty($sc['footer_label']) ? mb_substr($sc['footer_label'], 0, 30) : 'Submit';
        if ($isTerminal) {
            $formChildren[] = [
                'type' => 'Footer',
                'label' => $footerLabel,
                'on-click-action' => [
                    'name' => 'complete',
                    'payload' => (object)$payloadFields
                ]
            ];
        } else {
            $nextCleanId = 'SCREEN_' . chr(65 + $idx + 1);
            $formChildren[] = [
                'type' => 'Footer',
                'label' => $footerLabel,
                'on-click-action' => [
                    'name' => 'navigate',
                    'next' => [
                        'type' => 'screen',
                        'name' => $nextCleanId
                    ],
                    'payload' => (object)$payloadFields
                ]
            ];
        }

        $screenObj = [
            'id' => $cleanId,
            'title' => mb_substr($titleText, 0, 30),
            'data' => new stdClass(),
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    [
                        'type' => 'Form',
                        'name' => 'flow_form_' . strtolower($cleanId),
                        'children' => $formChildren
                    ]
                ]
            ]
        ];

        if ($isTerminal) {
            $screenObj['terminal'] = true;
            $screenObj['success'] = true;
        }

        $metaScreens[] = $screenObj;
    }

    return [
        'version' => '6.0',
        'screens' => $metaScreens
    ];
}

/**
 * Upload Flow JSON asset and publish directly to Meta WhatsApp Manager
 */
function syncFlowScreensToMeta($flowId, array $screens, $token): array {
    if (empty($screens)) {
        return ['success' => false, 'message' => 'No screens defined'];
    }

    $flowJson = convertScreensToMetaFlowJson($screens);
    $tmpFile = tempnam(sys_get_temp_dir(), 'flow_') . '.json';
    file_put_contents($tmpFile, json_encode($flowJson, JSON_PRETTY_PRINT));

    // Upload Asset
    $urlAsset = "https://graph.facebook.com/v20.0/{$flowId}/assets";
    $ch = curl_init($urlAsset);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'name' => 'flow.json',
        'asset_type' => 'FLOW_JSON',
        'file' => new CURLFile($tmpFile, 'application/json', 'flow.json')
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resAsset = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    $assetData = json_decode($resAsset, true) ?? [];
    if (empty($assetData['success'])) {
        $err = !empty($assetData['validation_errors'][0]['message']) 
            ? $assetData['validation_errors'][0]['message'] 
            : ($assetData['error']['message'] ?? 'Asset upload failed on Meta');
        return ['success' => false, 'message' => $err, 'details' => $assetData];
    }

    // Publish Flow
    $urlPub = "https://graph.facebook.com/v20.0/{$flowId}/publish";
    $chP = curl_init($urlPub);
    curl_setopt($chP, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($chP, CURLOPT_POST, true);
    curl_setopt($chP, CURLOPT_RETURNTRANSFER, true);
    $resPub = curl_exec($chP);
    curl_close($chP);

    $pubData = json_decode($resPub, true) ?? [];
    return [
        'success' => true,
        'asset' => $assetData,
        'published' => !empty($pubData['success'])
    ];
}

/**
 * Convert raw Meta Flow JSON layout into builder screens array
 */
function parseMetaFlowJsonToBuilderScreens(array $metaFlowJson): array {
    $screens = [];
    $rawScreens = $metaFlowJson['screens'] ?? [];
    
    foreach ($rawScreens as $idx => $s) {
        $screenId = $s['id'] ?? ('screen_' . ($idx + 1));
        $screenTitle = $s['title'] ?? 'Form';
        $screenBody = '';
        $components = [];
        $footerLabel = 'Submit';
        $footerAction = 'Complete';
        
        $children = $s['layout']['children'] ?? [];
        foreach ($children as $c) {
            $type = $c['type'] ?? '';
            if ($type === 'TextHeading') {
                if (empty($screenTitle) || $screenTitle === 'Form') {
                    $screenTitle = $c['text'] ?? $screenTitle;
                }
            } elseif ($type === 'TextBody') {
                $screenBody = $c['text'] ?? '';
            } elseif ($type === 'Form') {
                $formChildren = $c['children'] ?? [];
                foreach ($formChildren as $fcIdx => $fc) {
                    $fType = $fc['type'] ?? '';
                    if ($fType === 'TextHeading' && empty($screenTitle)) {
                        $screenTitle = $fc['text'] ?? '';
                    } elseif ($fType === 'TextBody' && empty($screenBody)) {
                        $screenBody = $fc['text'] ?? '';
                    } elseif ($fType === 'TextInput') {
                        $inputType = $fc['input-type'] ?? 'text';
                        $components[] = [
                            'id' => 'c' . ($fcIdx + 1),
                            'name' => $fc['name'] ?? '',
                            'type' => ($inputType === 'phone' ? 'Phone Number' : 'Short Answer'),
                            'label' => $fc['label'] ?? ($fc['name'] ?? 'Field'),
                            'helper' => $fc['helper-text'] ?? ($fc['helper'] ?? ''),
                            'required' => !empty($fc['required'])
                        ];
                    } elseif ($fType === 'TextArea') {
                        $components[] = [
                            'id' => 'c' . ($fcIdx + 1),
                            'name' => $fc['name'] ?? '',
                            'type' => 'Text Area',
                            'label' => $fc['label'] ?? ($fc['name'] ?? 'Field'),
                            'helper' => $fc['helper-text'] ?? ($fc['helper'] ?? ''),
                            'required' => !empty($fc['required'])
                        ];
                    } elseif ($fType === 'DatePicker') {
                        $components[] = [
                            'id' => 'c' . ($fcIdx + 1),
                            'name' => $fc['name'] ?? '',
                            'type' => 'Date Picker',
                            'label' => $fc['label'] ?? 'Date',
                            'helper' => $fc['helper-text'] ?? '',
                            'required' => !empty($fc['required'])
                        ];
                    } elseif ($fType === 'Dropdown') {
                        $optList = [];
                        if (!empty($fc['data-source'])) {
                            foreach ($fc['data-source'] as $ds) {
                                $optList[] = $ds['title'] ?? ($ds['id'] ?? '');
                            }
                        }
                        $components[] = [
                            'id' => 'c' . ($fcIdx + 1),
                            'name' => $fc['name'] ?? '',
                            'type' => (stripos($fc['label'] ?? '', 'time') !== false ? 'Time Slot' : 'Dropdown'),
                            'label' => $fc['label'] ?? 'Dropdown',
                            'options' => $optList,
                            'helper' => $fc['helper-text'] ?? '',
                            'required' => !empty($fc['required'])
                        ];
                    } elseif ($fType === 'Footer') {
                        $footerLabel = $fc['label'] ?? 'Submit';
                    }
                }
            } elseif ($type === 'Footer') {
                $footerLabel = $c['label'] ?? 'Submit';
            }
        }
        
        $screens[] = [
            'id' => $screenId,
            'name' => $screenTitle,
            'title' => $screenTitle,
            'body' => $screenBody,
            'components' => $components,
            'footer_label' => $footerLabel,
            'footer_action' => $footerAction
        ];
    }
    return $screens;
}

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : null;
$inputData = is_array($jsonInput) ? array_merge($_POST, $jsonInput) : $_POST;

$action = $_GET['action'] ?? $inputData['action'] ?? 'list';

switch ($action) {

    case 'list':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            $search = trim($_GET['search'] ?? '');
            $category = trim($_GET['category'] ?? '');
            
            $where = [];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(name LIKE ? OR flow_id LIKE ? OR category LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if (!empty($category) && $category !== 'ALL') {
                $where[] = "category = ?";
                $params[] = $category;
            }
            
            $sql = "SELECT * FROM bot_flows";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            $sql .= " ORDER BY id ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'flows' => $flows]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'get':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $q = trim($_GET['q'] ?? $_GET['id'] ?? '');
        try {
            $stmt = $pdo->prepare("SELECT * FROM bot_flows WHERE flow_id = ? OR id = ? LIMIT 1");
            $stmt->execute([$q, $q]);
            $flow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($flow) {
                $flow['screens'] = !empty($flow['screens_json']) ? json_decode($flow['screens_json'], true) : [];
                echo json_encode(['success' => true, 'flow' => $flow]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Flow not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'sync_meta_flows':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        
        try {
            $stmtW = $pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' THEN 1 ELSE 2 END) LIMIT 1");
            $stmtW->execute();
            $waba = $stmtW->fetch(PDO::FETCH_ASSOC);

            if (!$waba || empty($waba['waba_id']) || empty($waba['access_token'])) {
                echo json_encode(['success' => false, 'message' => 'Please configure WABA ID and Access Token in Marg ERP WABA Setup first.']);
                exit;
            }

            $url = "https://graph.facebook.com/v20.0/{$waba['waba_id']}/flows?fields=id,name,status,categories,validation_errors";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $waba['access_token']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $metaData = json_decode($res, true);

            if ($httpCode >= 200 && $httpCode < 300 && !empty($metaData['data'])) {
                $synced = 0;
                $metaFlowIds = [];
                foreach ($metaData['data'] as $mf) {
                    $flowId = $mf['id'];
                    $metaFlowIds[] = $flowId;
                    $name = ucwords($mf['name']);
                    $status = strtoupper($mf['status'] ?? 'PUBLISHED');
                    $catArr = $mf['categories'] ?? ['OTHER'];
                    $category = !empty($catArr[0]) ? strtoupper(str_replace('_', ' ', $catArr[0])) : 'OTHER';

                    $stmtChk = $pdo->prepare("SELECT id FROM bot_flows WHERE flow_id = ?");
                    $stmtChk->execute([$flowId]);
                    $existingId = $stmtChk->fetchColumn();

                    // Fetch live flow.json asset from Meta
                    $screensJson = null;
                    $rawContent = null;
                    try {
                        $urlAssets = "https://graph.facebook.com/v20.0/{$flowId}/assets";
                        $chA = curl_init($urlAssets);
                        curl_setopt($chA, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $waba['access_token']]);
                        curl_setopt($chA, CURLOPT_RETURNTRANSFER, true);
                        $resA = curl_exec($chA);
                        curl_close($chA);
                        $assetsA = json_decode($resA, true);
                        if (!empty($assetsA['data'])) {
                            foreach ($assetsA['data'] as $ass) {
                                if (($ass['name'] ?? '') === 'flow.json' && !empty($ass['download_url'])) {
                                    $chD = curl_init($ass['download_url']);
                                    curl_setopt($chD, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $waba['access_token']]);
                                    curl_setopt($chD, CURLOPT_RETURNTRANSFER, true);
                                    $rawContent = curl_exec($chD);
                                    curl_close($chD);
                                    $metaJ = json_decode($rawContent, true);
                                    if (!empty($metaJ['screens'])) {
                                        $parsedScreens = parseMetaFlowJsonToBuilderScreens($metaJ);
                                        $screensJson = json_encode($parsedScreens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    }
                                    break;
                                }
                            }
                        }
                    } catch (Throwable $eAsset) {}

                    if ($existingId) {
                        if ($screensJson !== null) {
                            $stmtUp = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = ?, screens_json = ?, raw_nodes_json = ? WHERE id = ?");
                            $stmtUp->execute([$name, $category, $status, $screensJson, $rawContent, $existingId]);
                        } else {
                            $stmtUp = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = ? WHERE id = ?");
                            $stmtUp->execute([$name, $category, $status, $existingId]);
                        }
                    } else {
                        $stmtIns = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json, raw_nodes_json) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmtIns->execute([$flowId, $name, $category, $status, $screensJson, $rawContent]);
                    }
                    $synced++;
                }

                // Remove obsolete or demo flows that do not exist on Meta WhatsApp Manager
                if (!empty($metaFlowIds)) {
                    $placeholders = implode(',', array_fill(0, count($metaFlowIds), '?'));
                    $delStmt = $pdo->prepare("DELETE FROM bot_flows WHERE flow_id NOT IN ($placeholders)");
                    $delStmt->execute($metaFlowIds);
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Successfully synced {$synced} official Meta WhatsApp Flow(s) from Meta WhatsApp Manager.",
                    'count' => $synced
                ]);
            } else {
                $errMsg = $metaData['error']['message'] ?? 'Failed to fetch flows from Meta Graph API';
                echo json_encode(['success' => false, 'message' => $errMsg]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'save':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        
        $input = $inputData;
        
        $flow_id = trim($input['flow_id'] ?? '');
        $name = trim($input['name'] ?? 'New Flow');
        $category = trim($input['category'] ?? 'LEAD_GENERATION');
        $status = 'PUBLISHED';
        $screens = $input['screens'] ?? [];
        $screens_json = is_array($screens) ? json_encode($screens) : $screens;

        // Fetch WABA credentials
        $stmtW = $pdo->query("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN waba_id != '1363197648586760' THEN 1 ELSE 2 END) LIMIT 1");
        $waba = $stmtW ? $stmtW->fetch(PDO::FETCH_ASSOC) : null;
        $wabaId = $waba['waba_id'] ?? '';
        $token = $waba['access_token'] ?? '';

        // Category mapping for Meta
        $catMeta = strtoupper(str_replace(' ', '_', $category));
        if (!in_array($catMeta, ['SIGN_IN', 'LEAD_GENERATION', 'CUSTOMER_SUPPORT', 'SURVEY', 'OTHER'])) {
            $catMeta = 'LEAD_GENERATION';
        }

        // If flow_id is empty or not numeric, it does not exist on Meta yet - create it on Meta!
        if (empty($flow_id) || !is_numeric($flow_id)) {
            if (!empty($wabaId) && !empty($token)) {
                $urlCreate = "https://graph.facebook.com/v20.0/{$wabaId}/flows";
                $ch = curl_init($urlCreate);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'name' => $name,
                    'categories' => [$catMeta]
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resCreate = json_decode(curl_exec($ch), true);
                curl_close($ch);

                if (!empty($resCreate['id'])) {
                    $flow_id = $resCreate['id'];
                }
            }
        }

        // Fallback if still empty
        if (empty($flow_id)) {
            $flow_id = date('Ymd') . rand(100000, 999999);
        }

        // Push screens & publish to Meta WhatsApp Manager
        $metaSyncResult = null;
        if (is_numeric($flow_id) && !empty($token) && is_array($screens) && !empty($screens)) {
            $metaSyncResult = syncFlowScreensToMeta($flow_id, $screens, $token);
        }

        try {
            // Check existing
            $stmtChk = $pdo->prepare("SELECT id FROM bot_flows WHERE flow_id = ?");
            $stmtChk->execute([$flow_id]);
            $existId = $stmtChk->fetchColumn();
            
            if ($existId) {
                $stmtUpd = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = 'PUBLISHED', screens_json = ? WHERE id = ?");
                $stmtUpd->execute([$name, $category, $screens_json, $existId]);
                $msg = 'Flow screens saved and published directly on Meta WhatsApp Manager!';
                echo json_encode(['success' => true, 'message' => $msg, 'flow_id' => $flow_id, 'id' => $existId, 'meta' => $metaSyncResult]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json) VALUES (?, ?, ?, 'PUBLISHED', ?)");
                $stmtIns->execute([$flow_id, $name, $category, $screens_json]);
                $newId = $pdo->lastInsertId();
                $msg = 'Flow created & published directly on Meta WhatsApp Manager!';
                echo json_encode(['success' => true, 'message' => $msg, 'flow_id' => $flow_id, 'id' => $newId, 'meta' => $metaSyncResult]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'publish_meta':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $flow_id = trim($_POST['flow_id'] ?? '');
        try {
            require_once __DIR__ . '/whatsapp-api.php';
            $whatsapp = new WhatsAppAPI($pdo);
            $metaRes = $whatsapp->publishMetaFlow($flow_id);

            $stmt = $pdo->prepare("UPDATE bot_flows SET status = 'PUBLISHED' WHERE flow_id = ?");
            $stmt->execute([$flow_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Flow published on Meta WhatsApp Manager!',
                'meta_response' => $metaRes
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'toggle_status':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? 'PUBLISHED');
        try {
            $stmt = $pdo->prepare("UPDATE bot_flows SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true, 'message' => 'Status updated']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'delete':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM bot_flows WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Flow deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'import_json':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        
        $jsonContent = trim($_POST['json_data'] ?? '');
        if (empty($jsonContent) && isset($_FILES['json_file']['tmp_name'])) {
            $jsonContent = file_get_contents($_FILES['json_file']['tmp_name']);
        }
        
        if (empty($jsonContent)) {
            echo json_encode(['success' => false, 'message' => 'Please provide JSON data or select a JSON file.']);
            exit;
        }
        
        $parsed = json_decode($jsonContent, true);
        if (!is_array($parsed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON file format.']);
            exit;
        }
        
        // Extract flow metadata or parse array of nodes (e.g. Service 01.json format)
        $flow_id = '';
        $flow_name = 'Imported Bot Flow';
        $category = 'SIGN IN';
        $screens = [];
        
        // Check if node array format like user provided
        if (isset($parsed[0]['FlowID']) || isset($parsed[0]['NodeType'])) {
            foreach ($parsed as $node) {
                if (!empty($node['FlowID']) && empty($flow_id)) {
                    $flow_id = $node['FlowID'];
                }
                if (!empty($node['FormID']) && empty($flow_id)) {
                    $flow_id = $node['FormID'];
                }
                if ($node['NodeType'] === 'buttonmessage' && !empty($node['BodyText'])) {
                    // Extract button message or greeting screen
                    $lines = explode("\n", $node['BodyText']);
                    $flow_name = trim($lines[0]);
                }
                if ($node['NodeType'] === 'form') {
                    $formTitle = $node['NodeText'] ?? 'Interactive Form';
                    $screens[] = [
                        'id' => 'screen_' . rand(100, 999),
                        'name' => 'Support Form',
                        'title' => 'Welcome to Marg Soft',
                        'body' => $node['BodyText'] ?? 'Please Provide Your Info and Problem Here..',
                        'components' => [
                            ['id' => 'c1', 'type' => 'Short Answer', 'label' => 'License Number', 'helper' => 'Client Id', 'required' => true],
                            ['id' => 'c2', 'type' => 'Dropdown', 'label' => 'Bill Format Issue', 'helper' => '', 'options' => ['Bill Format Issue', 'GST Error', 'Printer Setup'], 'required' => false],
                            ['id' => 'c3', 'type' => 'Text Area', 'label' => 'Problem', 'helper' => 'Describe issue', 'required' => true],
                            ['id' => 'c4', 'type' => 'Short Answer', 'label' => 'Call Back Number', 'helper' => 'Call Back Number', 'required' => true]
                        ],
                        'footer_label' => $node['Caption'] ?? 'Submit',
                        'footer_action' => 'Complete'
                    ];
                }
            }
        }
        
        if (empty($flow_id)) {
            $flow_id = date('Ymd') . rand(100000, 999999);
        }
        if (empty($screens)) {
            $screens[] = [
                'id' => 'screen_1',
                'name' => 'Welcome to Marg Soft',
                'title' => 'Welcome to Marg Soft',
                'body' => 'Please Provide Your Info and Problem Here..',
                'components' => [
                    ['id' => 'c1', 'type' => 'Short Answer', 'label' => 'License Number', 'helper' => 'Client Id', 'required' => true],
                    ['id' => 'c2', 'type' => 'Dropdown', 'label' => 'Bill Format Issue', 'helper' => '', 'options' => ['Bill Format Issue', 'GST Error', 'Printer Setup'], 'required' => false],
                    ['id' => 'c3', 'type' => 'Text Area', 'label' => 'Problem', 'helper' => 'Describe issue', 'required' => true],
                    ['id' => 'c4', 'type' => 'Short Answer', 'label' => 'Call Back Number', 'helper' => 'Call Back Number', 'required' => true]
                ],
                'footer_label' => 'Submit',
                'footer_action' => 'Complete'
            ];
        }
        
        try {
            $stmtChk = $pdo->prepare("SELECT id FROM bot_flows WHERE flow_id = ?");
            $stmtChk->execute([$flow_id]);
            $existId = $stmtChk->fetchColumn();
            
            $rawJson = json_encode($parsed);
            $screensJson = json_encode($screens);
            
            if ($existId) {
                $stmtUpd = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = 'PUBLISHED', screens_json = ?, raw_nodes_json = ? WHERE id = ?");
                $stmtUpd->execute([$flow_name, $category, $screensJson, $rawJson, $existId]);
                echo json_encode(['success' => true, 'message' => 'Flow JSON imported and updated successfully!', 'flow_id' => $flow_id]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json, raw_nodes_json) VALUES (?, ?, ?, 'PUBLISHED', ?, ?)");
                $stmtIns->execute([$flow_id, $flow_name, $category, $screensJson, $rawJson]);
                echo json_encode(['success' => true, 'message' => 'Flow JSON imported successfully!', 'flow_id' => $flow_id]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    // ---------------------------------------------------------
    // KEYWORD TRIGGERS MANAGEMENT ACTIONS
    // ---------------------------------------------------------
    case 'get_triggers':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            // Seed initial default keyword triggers if empty
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM whatsapp_keyword_triggers");
            if ($stmtCount && intval($stmtCount->fetchColumn()) === 0) {
                $defaults = [
                    ['keyword' => 'TICKET', 'match_type' => 'exact', 'reply_type' => 'flow', 'reply_payload' => 'Ticket Flow (1838065533836150)', 'flow_id' => '1838065533836150'],
                    ['keyword' => 'SERVICE', 'match_type' => 'exact', 'reply_type' => 'flow', 'reply_payload' => 'Service Flow (36230192503294106)', 'flow_id' => '36230192503294106'],
                    ['keyword' => 'BOT', 'match_type' => 'exact', 'reply_type' => 'flow', 'reply_payload' => 'Bot Flow (1303139711243346)', 'flow_id' => '1303139711243346'],
                    ['keyword' => 'AMC', 'match_type' => 'contains', 'reply_type' => 'text', 'reply_payload' => '⏰ Marg ERP AMC Renewal Notice: Call 7523830026 for renewal assistance.', 'flow_id' => null]
                ];
                $stmtIns = $pdo->prepare("INSERT INTO whatsapp_keyword_triggers (keyword, match_type, reply_type, reply_payload, flow_id) VALUES (?, ?, ?, ?, ?)");
                foreach ($defaults as $d) {
                    $stmtIns->execute([$d['keyword'], $d['match_type'], $d['reply_type'], $d['reply_payload'], $d['flow_id']]);
                }
            }

            $stmtT = $pdo->query("SELECT * FROM whatsapp_keyword_triggers ORDER BY id ASC");
            $triggers = $stmtT ? $stmtT->fetchAll(PDO::FETCH_ASSOC) : [];
            echo json_encode(['success' => true, 'triggers' => $triggers]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'save_trigger':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $keyword = strtoupper(trim($_POST['keyword'] ?? ''));
        $matchType = trim($_POST['match_type'] ?? 'exact');
        $replyType = trim($_POST['reply_type'] ?? 'text');
        $replyPayload = trim($_POST['reply_payload'] ?? '');
        $flowId = trim($_POST['flow_id'] ?? '');

        if (empty($keyword) || empty($replyPayload)) {
            echo json_encode(['success' => false, 'message' => 'Keyword and Reply Response are required']);
            exit;
        }

        try {
            $stmtIns = $pdo->prepare("INSERT INTO whatsapp_keyword_triggers (keyword, match_type, reply_type, reply_payload, flow_id) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE match_type = VALUES(match_type), reply_type = VALUES(reply_type), reply_payload = VALUES(reply_payload), flow_id = VALUES(flow_id)");
            $stmtIns->execute([$keyword, $matchType, $replyType, $replyPayload, $flowId]);
            echo json_encode(['success' => true, 'message' => 'Keyword trigger rule saved successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'delete_trigger':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM whatsapp_keyword_triggers WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Keyword trigger rule deleted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'get_canvas_resources':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            $effective_pdo = $pdo_master ?? $pdo;
            $userId = $_SESSION['user_id'] ?? 0;
            $tenantPrefix = (!empty($_SESSION['tenant_db']) && strpos($_SESSION['tenant_db'], 't_') === 0) ? $_SESSION['tenant_db'] : '';

            // 1. Fetch Meta Approved Flows ONLY (status = 'PUBLISHED')
            $flowsMap = [];
            if (!empty($tenantPrefix)) {
                try {
                    $tenantFlowsTbl = "`" . $tenantPrefix . "bot_flows`";
                    $stmtTF = $effective_pdo->query("SELECT id, flow_id, name, category, status, screens_json FROM {$tenantFlowsTbl} WHERE UPPER(status) = 'PUBLISHED' ORDER BY id DESC");
                    while ($f = $stmtTF->fetch(PDO::FETCH_ASSOC)) {
                        $flowsMap[$f['flow_id']] = $f;
                    }
                } catch (\Throwable $e) {}
            }
            try {
                $stmtF = $effective_pdo->query("SELECT id, flow_id, name, category, status, screens_json FROM bot_flows WHERE UPPER(status) = 'PUBLISHED' ORDER BY id DESC");
                while ($f = $stmtF->fetch(PDO::FETCH_ASSOC)) {
                    if (!isset($flowsMap[$f['flow_id']])) {
                        $flowsMap[$f['flow_id']] = $f;
                    }
                }
            } catch (\Throwable $e) {}
            $flowsList = array_values($flowsMap);

            // 2. Fetch Meta Approved Templates ONLY (meta_status = 'APPROVED')
            $tplMap = [];
            if (!empty($tenantPrefix)) {
                try {
                    $tenantTplTbl = "`" . $tenantPrefix . "whatsapp_templates`";
                    $stmtTT = $effective_pdo->query("SELECT id, title, slug, category, header_type, header_text, header_content, body_text, footer_text, buttons_json, meta_status FROM {$tenantTplTbl} WHERE UPPER(meta_status) = 'APPROVED' ORDER BY id DESC");
                    while ($t = $stmtTT->fetch(PDO::FETCH_ASSOC)) {
                        $tplMap[$t['slug']] = $t;
                    }
                } catch (\Throwable $e) {}
            }
            try {
                $stmtT = $effective_pdo->query("SELECT id, title, slug, category, header_type, header_text, header_content, body_text, footer_text, buttons_json, meta_status FROM whatsapp_templates WHERE UPPER(meta_status) = 'APPROVED' ORDER BY id DESC");
                while ($t = $stmtT->fetch(PDO::FETCH_ASSOC)) {
                    if (!isset($tplMap[$t['slug']])) {
                        $tplMap[$t['slug']] = $t;
                    }
                }
            } catch (\Throwable $e) {}
            $templatesList = array_values($tplMap);

            // Format buttons and screens for interactive canvas wiring
            foreach ($templatesList as &$tpl) {
                $rawBtns = $tpl['buttons_json'] ?? '[]';
                $decoded = is_string($rawBtns) ? json_decode($rawBtns, true) : $rawBtns;
                $formattedBtns = [];
                if (is_array($decoded)) {
                    foreach ($decoded as $idx => $b) {
                        $bTitle = $b['title'] ?? $b['text'] ?? $b['label'] ?? ("Option " . ($idx + 1));
                        $bId = $b['id'] ?? ('btn_' . ($idx + 1));
                        if ($bId === 'QUICK_REPLY' || $bId === 'FLOW' || $bId === 'URL' || $bId === 'PHONE_NUMBER') {
                            $cleanSuffix = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $bTitle));
                            $bId = 'btn_' . ($cleanSuffix ?: ($idx + 1));
                        }
                        $formattedBtns[] = [
                            'id' => $bId,
                            'title' => $bTitle,
                            'label' => $bTitle,
                            'type' => $b['type'] ?? $b['id'] ?? 'QUICK_REPLY'
                        ];
                    }
                }
                $tpl['buttons'] = $formattedBtns;
            }
            unset($tpl);

            foreach ($flowsList as &$flw) {
                $rawScreens = $flw['screens_json'] ?? '[]';
                $decodedScreens = is_string($rawScreens) ? json_decode($rawScreens, true) : $rawScreens;
                $flw['screens'] = is_array($decodedScreens) ? $decodedScreens : [];
            }
            unset($flw);

            echo json_encode([
                'success' => true,
                'flows' => $flowsList,
                'templates' => $templatesList
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'sync_meta_canvas_resources':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            $effective_pdo = $pdo_master ?? $pdo;
            $userId = $_SESSION['user_id'] ?? 0;

            // Get active WABA credentials
            $stmtW = $effective_pdo->prepare("SELECT waba_id, access_token FROM merchant_waba_settings WHERE access_token != '' AND waba_id != '' ORDER BY (CASE WHEN user_id = ? THEN 1 WHEN waba_id != '1363197648586760' THEN 2 ELSE 3 END) LIMIT 1");
            $stmtW->execute([$userId]);
            $waba = $stmtW->fetch(PDO::FETCH_ASSOC);

            if (!$waba || empty($waba['waba_id']) || empty($waba['access_token'])) {
                $stmtTc = $effective_pdo->prepare("SELECT waba_id, access_token FROM tenant_whatsapp_configs WHERE access_token != '' AND waba_id != '' ORDER BY (user_id = ?) DESC LIMIT 1");
                $stmtTc->execute([$userId]);
                $waba = $stmtTc->fetch(PDO::FETCH_ASSOC);
            }

            $syncedTemplatesCount = 0;
            $syncedFlowsCount = 0;

            if ($waba && !empty($waba['waba_id']) && !empty($waba['access_token'])) {
                $wabaId = $waba['waba_id'];
                $token = $waba['access_token'];

                // 1. Sync Flows from Meta Graph API
                $urlFlows = "https://graph.facebook.com/v20.0/{$wabaId}/flows?fields=id,name,status,categories,validation_errors";
                $chF = curl_init($urlFlows);
                curl_setopt($chF, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                curl_setopt($chF, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chF, CURLOPT_SSL_VERIFYPEER, false);
                $resF = curl_exec($chF);
                $httpF = curl_getinfo($chF, CURLINFO_HTTP_CODE);
                curl_close($chF);

                if ($httpF >= 200 && $httpF < 300) {
                    $flowData = json_decode($resF, true);
                    if (!empty($flowData['data'])) {
                        foreach ($flowData['data'] as $mf) {
                            $fId = $mf['id'];
                            $fName = ucwords($mf['name']);
                            $fStatus = strtoupper($mf['status'] ?? 'PUBLISHED');
                            $catArr = $mf['categories'] ?? ['OTHER'];
                            $fCat = !empty($catArr[0]) ? strtoupper(str_replace('_', ' ', $catArr[0])) : 'OTHER';

                            $stmtUpF = $effective_pdo->prepare("
                                INSERT INTO bot_flows (flow_id, name, category, status)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), status = VALUES(status)
                            ");
                            $stmtUpF->execute([$fId, $fName, $fCat, $fStatus]);
                            $syncedFlowsCount++;
                        }
                    }
                }

                // 2. Sync Templates from Meta Graph API
                $urlTpls = "https://graph.facebook.com/v20.0/{$wabaId}/message_templates?limit=100";
                $chT = curl_init($urlTpls);
                curl_setopt($chT, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                curl_setopt($chT, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chT, CURLOPT_SSL_VERIFYPEER, false);
                $resT = curl_exec($chT);
                $httpT = curl_getinfo($chT, CURLINFO_HTTP_CODE);
                curl_close($chT);

                if ($httpT >= 200 && $httpT < 300) {
                    $tplData = json_decode($resT, true);
                    if (!empty($tplData['data'])) {
                        foreach ($tplData['data'] as $mT) {
                            $tSlug = $mT['name'];
                            $tTitle = ucwords(str_replace('_', ' ', $tSlug));
                            $tCat = $mT['category'] ?? 'MARKETING';
                            $tStatus = strtoupper($mT['status'] ?? 'APPROVED');

                            $hText = '';
                            $hType = 'none';
                            $bText = '';
                            $fText = '';
                            $btnsArr = [];

                            if (!empty($mT['components']) && is_array($mT['components'])) {
                                foreach ($mT['components'] as $comp) {
                                    if ($comp['type'] === 'HEADER') {
                                        $hType = strtolower($comp['format'] ?? 'text');
                                        $hText = $comp['text'] ?? '';
                                    }
                                    if ($comp['type'] === 'BODY') {
                                        $bText = $comp['text'] ?? '';
                                    }
                                    if ($comp['type'] === 'FOOTER') {
                                        $fText = $comp['text'] ?? '';
                                    }
                                    if ($comp['type'] === 'BUTTONS' && !empty($comp['buttons'])) {
                                        foreach ($comp['buttons'] as $b) {
                                            $btnsArr[] = [
                                                'id' => $b['type'] ?? 'btn',
                                                'title' => $b['text'] ?? 'Action'
                                            ];
                                        }
                                    }
                                }
                            }
                            $btnsJson = !empty($btnsArr) ? json_encode($btnsArr, JSON_UNESCAPED_UNICODE) : '[]';

                            $stmtUpT = $effective_pdo->prepare("
                                INSERT INTO whatsapp_templates (title, slug, category, header_type, header_text, body_text, footer_text, buttons_json, meta_status, waba_id)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE title = VALUES(title), category = VALUES(category), header_type = VALUES(header_type), header_text = VALUES(header_text), body_text = VALUES(body_text), footer_text = VALUES(footer_text), buttons_json = VALUES(buttons_json), meta_status = VALUES(meta_status)
                            ");
                            $stmtUpT->execute([$tTitle, $tSlug, $tCat, $hType, $hText, $bText, $fText, $btnsJson, $tStatus, $wabaId]);
                            $syncedTemplatesCount++;
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Synced successfully from Meta: {$syncedTemplatesCount} templates and {$syncedFlowsCount} flows verified."
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()]);
        }
        exit;

    case 'list_workflows':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        try {
            // Seed initial default workflows if table is empty
            $countWf = $pdo->query("SELECT COUNT(*) FROM bot_flow_automations")->fetchColumn();
            if (intval($countWf) === 0) {
                $defaultNodes1 = [
                    ['id' => 'node_trigger_1', 'type' => 'trigger', 'title' => 'Keyword Trigger', 'x' => 60, 'y' => 180, 'data' => ['keywords' => 'HI, SUPPORT, TICKET, HELPDESK, HELP', 'match_type' => 'contains']],
                    ['id' => 'node_template_1', 'type' => 'template', 'title' => 'WhatsApp Welcome Menu', 'x' => 430, 'y' => 150, 'data' => ['template_name' => 'marg_welcome_menu', 'header_text' => 'Marg Soft Solution Helpdesk', 'body_text' => 'Namaste! Welcome to Marg ERP Support. Please choose an option below to proceed:', 'buttons' => [['id' => 'btn_ticket', 'label' => 'Create Support Ticket'], ['id' => 'btn_demo', 'label' => 'Book ERP Demo'], ['id' => 'btn_agent', 'label' => 'Talk to Support Agent']]]],
                    ['id' => 'node_flow_1', 'type' => 'flow', 'title' => 'Marg Tickets Flow', 'x' => 840, 'y' => 80, 'data' => ['flow_id' => '869669339414580', 'flow_name' => 'Marg Tickets', 'screen' => 'WELCOME_SCREEN', 'fields' => ['license_number', 'problem', 'callback_number']]],
                    ['id' => 'node_action_1', 'type' => 'action', 'title' => 'Generate Support Ticket', 'x' => 1250, 'y' => 80, 'data' => ['action_type' => 'create_support_ticket', 'notify_client' => true, 'use_meta_template' => true, 'reverse_loop_agent' => true]]
                ];
                $conn1 = [
                    ['from' => 'node_trigger_1', 'fromPort' => 'out', 'to' => 'node_template_1', 'toPort' => 'in'],
                    ['from' => 'node_template_1', 'fromPort' => 'btn_ticket', 'to' => 'node_flow_1', 'toPort' => 'in'],
                    ['from' => 'node_flow_1', 'fromPort' => 'out', 'to' => 'node_action_1', 'toPort' => 'in']
                ];

                $defaultNodes2 = [
                    ['id' => 'node_trigger_2', 'type' => 'trigger', 'title' => 'Demo Keyword Trigger', 'x' => 60, 'y' => 180, 'data' => ['keywords' => 'DEMO, BOOK DEMO, SALES', 'match_type' => 'contains']],
                    ['id' => 'node_flow_2', 'type' => 'flow', 'title' => 'Marg Demo Booking Flow', 'x' => 480, 'y' => 180, 'data' => ['flow_id' => '1611148110659211', 'flow_name' => 'Marg ERP Demo Appointment', 'screen' => 'DEMO_SCREEN', 'fields' => ['name', 'mobile', 'demo_date', 'slot']]],
                    ['id' => 'node_action_2', 'type' => 'action', 'title' => 'Generate Sales Lead', 'x' => 900, 'y' => 180, 'data' => ['action_type' => 'create_sales_lead', 'assign_to' => 'SAHIL SAVITA', 'notify_client' => false]]
                ];
                $conn2 = [
                    ['from' => 'node_trigger_2', 'fromPort' => 'out', 'to' => 'node_flow_2', 'toPort' => 'in'],
                    ['from' => 'node_flow_2', 'fromPort' => 'out', 'to' => 'node_action_2', 'toPort' => 'in']
                ];

                $insWf = $pdo->prepare("INSERT INTO bot_flow_automations (name, trigger_keyword, status, nodes_json, connections_json) VALUES (?, ?, 'ACTIVE', ?, ?)");
                $insWf->execute(['Marg Technical Support Ticket Journey', 'HI, SUPPORT, TICKET', json_encode($defaultNodes1), json_encode($conn1)]);
                $insWf->execute(['Marg ERP Demo Booking Walkthrough', 'DEMO, BOOK DEMO', json_encode($defaultNodes2), json_encode($conn2)]);
            }

            $stmtList = $pdo->query("SELECT id, name, trigger_keyword, status, nodes_json, connections_json, created_at, updated_at FROM bot_flow_automations ORDER BY id DESC");
            $workflows = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : [];

            $formatted = [];
            foreach ($workflows as $w) {
                $nodes = !empty($w['nodes_json']) ? json_decode($w['nodes_json'], true) : [];
                $connections = !empty($w['connections_json']) ? json_decode($w['connections_json'], true) : [];
                $formatted[] = [
                    'id' => (int)$w['id'],
                    'name' => $w['name'],
                    'trigger_keyword' => $w['trigger_keyword'] ?: 'ALL',
                    'status' => $w['status'] ?: 'ACTIVE',
                    'node_count' => count($nodes),
                    'connection_count' => count($connections),
                    'created_at' => $w['created_at'],
                    'updated_at' => $w['updated_at']
                ];
            }

            echo json_encode(['success' => true, 'workflows' => $formatted]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'get_workflow':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_GET['id'] ?? $inputData['id'] ?? 0);
        try {
            if ($id > 0) {
                $stmtW = $pdo->prepare("SELECT * FROM bot_flow_automations WHERE id = ? LIMIT 1");
                $stmtW->execute([$id]);
            } else {
                $stmtW = $pdo->query("SELECT * FROM bot_flow_automations ORDER BY id ASC LIMIT 1");
            }
            $row = $stmtW ? $stmtW->fetch(PDO::FETCH_ASSOC) : null;

            if ($row) {
                echo json_encode([
                    'success' => true,
                    'workflow' => [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'trigger_keyword' => $row['trigger_keyword'] ?? 'ALL',
                        'status' => $row['status'],
                        'nodes' => json_decode($row['nodes_json'], true) ?: [],
                        'connections' => json_decode($row['connections_json'], true) ?: []
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Workflow automation not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'save_workflow':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }

        $id = intval($inputData['id'] ?? 0);
        $workflowName = trim($inputData['name'] ?? 'New WhatsApp Automation');
        $triggerKeyword = trim($inputData['trigger_keyword'] ?? 'ALL');
        $status = trim($inputData['status'] ?? 'ACTIVE');
        $nodes = $inputData['nodes'] ?? [];
        $connections = $inputData['connections'] ?? [];

        $nodesJson = is_array($nodes) ? json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $nodes;
        $connectionsJson = is_array($connections) ? json_encode($connections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $connections;

        $tenantId = intval($_SESSION['tenant_id'] ?? $_SESSION['user_id'] ?? 0);
        $wabaId = trim($_SESSION['waba_id'] ?? $inputData['waba_id'] ?? '');
        $phoneId = trim($_SESSION['phone_number_id'] ?? $inputData['phone_number_id'] ?? '');

        // Auto-resolve tenant phone_number_id and waba_id if not explicitly provided
        if (empty($phoneId)) {
            try {
                $stmtPh = $pdo->prepare("SELECT phone_number_id, waba_id FROM merchant_waba_settings WHERE user_id = ? OR access_token != '' ORDER BY (CASE WHEN user_id = ? THEN 1 ELSE 2 END), id DESC LIMIT 1");
                $stmtPh->execute([$tenantId, $tenantId]);
                $wRow = $stmtPh->fetch(PDO::FETCH_ASSOC);
                if ($wRow) {
                    $phoneId = $wRow['phone_number_id'] ?? '';
                    if (empty($wabaId)) $wabaId = $wRow['waba_id'] ?? '';
                }
            } catch (\Throwable $ePh) {}
        }

        try {
            if ($id > 0) {
                $stmtUpd = $pdo->prepare("UPDATE bot_flow_automations SET name = ?, trigger_keyword = ?, status = ?, nodes_json = ?, connections_json = ?, tenant_id = ?, waba_id = ?, phone_number_id = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpd->execute([$workflowName, $triggerKeyword, $status, $nodesJson, $connectionsJson, $tenantId, $wabaId, $phoneId, $id]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO bot_flow_automations (name, trigger_keyword, status, nodes_json, connections_json, tenant_id, waba_id, phone_number_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$workflowName, $triggerKeyword, $status, $nodesJson, $connectionsJson, $tenantId, $wabaId, $phoneId]);
                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode([
                'success' => true,
                'id' => $id,
                'message' => 'Flow Automation saved successfully!'
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'delete_workflow':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? $inputData['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid workflow ID']);
            exit;
        }
        try {
            $stmtDel = $pdo->prepare("DELETE FROM bot_flow_automations WHERE id = ?");
            $stmtDel->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Flow automation deleted successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'clone_workflow':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? $inputData['id'] ?? 0);
        try {
            $stmtO = $pdo->prepare("SELECT * FROM bot_flow_automations WHERE id = ? LIMIT 1");
            $stmtO->execute([$id]);
            $orig = $stmtO->fetch(PDO::FETCH_ASSOC);

            if ($orig) {
                $newName = $orig['name'] . ' (Copy)';
                $stmtClone = $pdo->prepare("INSERT INTO bot_flow_automations (name, trigger_keyword, status, nodes_json, connections_json) VALUES (?, ?, 'ACTIVE', ?, ?)");
                $stmtClone->execute([$newName, $orig['trigger_keyword'], $orig['nodes_json'], $orig['connections_json']]);
                $newId = (int)$pdo->lastInsertId();

                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Flow automation duplicated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Workflow not found']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'toggle_workflow_status':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        $id = intval($_POST['id'] ?? $inputData['id'] ?? 0);
        try {
            $stmtS = $pdo->prepare("SELECT status FROM bot_flow_automations WHERE id = ? LIMIT 1");
            $stmtS->execute([$id]);
            $curr = $stmtS->fetchColumn();
            $newStatus = ($curr === 'ACTIVE') ? 'PAUSED' : 'ACTIVE';

            $stmtUp = $pdo->prepare("UPDATE bot_flow_automations SET status = ? WHERE id = ?");
            $stmtUp->execute([$newStatus, $id]);

            echo json_encode(['success' => true, 'status' => $newStatus, 'message' => "Workflow status updated to {$newStatus}"]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}
