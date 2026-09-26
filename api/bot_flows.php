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

                    if ($existingId) {
                        $stmtUp = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = ? WHERE id = ?");
                        $stmtUp->execute([$name, $category, $status, $existingId]);
                    } else {
                        $stmtIns = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status) VALUES (?, ?, ?, ?)");
                        $stmtIns->execute([$flowId, $name, $category, $status]);
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

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}
