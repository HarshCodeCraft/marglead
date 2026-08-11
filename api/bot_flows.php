<?php
/**
 * WhatsApp Bot Flows & Interactive Builder API Endpoint
 * Handles list, get, save, delete, status updates, and JSON import/export
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

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

        // Seed initial flows if empty
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM bot_flows");
        if ($stmtCount->fetchColumn() == 0) {
            $defaultScreens = json_encode([
                [
                    "id" => "screen_1",
                    "name" => "Welcome to Marg Soft",
                    "title" => "Welcome to Marg Soft",
                    "body" => "Please Provide Your Info and Problem Here..",
                    "components" => [
                        ["id" => "c1", "type" => "Short Answer", "label" => "License Number", "helper" => "Client Id", "required" => true],
                        ["id" => "c2", "type" => "Dropdown", "label" => "Bill Format Issue", "helper" => "", "options" => ["Bill Format Issue", "GST Error", "Printer Setup"], "required" => false],
                        ["id" => "c3", "type" => "Text Area", "label" => "Problem", "helper" => "Describe issue", "required" => true],
                        ["id" => "c4", "type" => "Short Answer", "label" => "Call Back Number", "helper" => "Call Back Number", "required" => true]
                    ],
                    "footer_label" => "Submit",
                    "footer_action" => "Complete"
                ]
            ]);

            $stmtSeed = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json) VALUES (?, ?, ?, ?, ?)");
            $stmtSeed->execute(['2356038494923110', 'Ticket', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
            $stmtSeed->execute(['36230192503294106', 'Service', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
            $stmtSeed->execute(['1303139711243346', 'Bot', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
        }
    } catch (PDOException $e) {}
}

if ($db_connected && $pdo) {
    ensureBotFlowsTable($pdo);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

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

    case 'save':
        if (!$db_connected || !$pdo) {
            echo json_encode(['success' => false, 'message' => 'Database offline']);
            exit;
        }
        
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        
        $flow_id = trim($input['flow_id'] ?? '');
        $name = trim($input['name'] ?? 'New Flow');
        $category = trim($input['category'] ?? 'SIGN IN');
        $status = trim($input['status'] ?? 'PUBLISHED');
        $screens = $input['screens'] ?? [];
        $screens_json = is_array($screens) ? json_encode($screens) : $screens;
        
        if (empty($flow_id)) {
            $flow_id = date('Ymd') . rand(100000, 999999);
        }
        
        try {
            // Check existing
            $stmtChk = $pdo->prepare("SELECT id FROM bot_flows WHERE flow_id = ?");
            $stmtChk->execute([$flow_id]);
            $existId = $stmtChk->fetchColumn();
            
            if ($existId) {
                $stmtUpd = $pdo->prepare("UPDATE bot_flows SET name = ?, category = ?, status = ?, screens_json = ? WHERE id = ?");
                $stmtUpd->execute([$name, $category, $status, $screens_json, $existId]);
                echo json_encode(['success' => true, 'message' => 'Flow saved successfully!', 'flow_id' => $flow_id, 'id' => $existId]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json) VALUES (?, ?, ?, ?, ?)");
                $stmtIns->execute([$flow_id, $name, $category, $status, $screens_json]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success' => true, 'message' => 'Flow created successfully!', 'flow_id' => $flow_id, 'id' => $newId]);
            }
        } catch (PDOException $e) {
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

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}
