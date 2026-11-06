<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Fetch ID from parameter
$id = isset($_GET['id']) ? htmlspecialchars($_GET['id']) : '';

// 1. Process AJAX installation status autosave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_install_status') {
    $leadId = $_POST['lead_id'];
    $checked_items = isset($_POST['items']) ? $_POST['items'] : [];
    
    header('Content-Type: application/json');
    if ($db_connected && $pdo) {
        try {
            $status_json = json_encode($checked_items);
            $stmt = $pdo->prepare("UPDATE leads SET installation_status = ? WHERE id = ?");
            $stmt->execute([$status_json, $leadId]);
            
            // Log action to the timeline log
            $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
            $items_list = empty($checked_items) ? 'None' : implode(', ', $checked_items);
            $actionMsg = "Updated system installation checklist. Active: (" . $items_list . ")";
            $logStmt->execute([$leadId, $_SESSION['user_name'] ?? 'System User', $actionMsg]);
            
            echo json_encode(['success' => true, 'message' => 'Status saved successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database offline.']);
    }
    exit;
}

// 2. Process sending client lifecycle emails
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_lead_email') {
    $leadId = $_POST['lead_id'];
    $recipient = trim($_POST['recipient']);
    $subject = trim($_POST['subject']);
    $body = $_POST['body'];
    
    require_once __DIR__ . '/../../includes/mailer.php';
    
    // Wrap plain text body in a premium advertising theme
    $title = $subject;
    $header_title = $subject;
    $subtitle = "Custom client service communication from Friendly AI Solution.";
    $cta_text = "Visit Portal";
    $cta_url = "https://friendlyaisolution.com";
    
    if (strpos($subject, 'Demo') !== false) {
        $subtitle = "Confirming your upcoming product demonstration session.";
        $cta_text = "Join Live Session";
        $cta_url = "https://meet.google.com";
    } elseif (strpos($subject, 'Payment') !== false || strpos($subject, 'Invoice') !== false) {
        $subtitle = "Important invoice settlement notice.";
        $cta_text = "Review Invoice & Pay";
        $cta_url = Mailer::getBaseUrl() . "index.php?page=payments";
    }
    
    $htmlBody = nl2br(htmlspecialchars($body));
    $compiledClientMail = Mailer::wrapHTMLTemplate($title, $header_title, $subtitle, $htmlBody, $cta_text, $cta_url);
    
    // Trigger PHPMailer send
    Mailer::send($recipient, $subject, $compiledClientMail);
    
    // Log action to the timeline log
    if ($db_connected && $pdo) {
        try {
            $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
            $actionMsg = "Sent email: \"" . $subject . "\" to " . $recipient;
            $logStmt->execute([$leadId, $_SESSION['user_name'] ?? 'System User', $actionMsg]);
        } catch (PDOException $e) {
            error_log("Timeline logging error: " . $e->getMessage());
        }
    }
    
    $_SESSION['flash_success'] = "Email sent successfully to client: " . $recipient;
    header("Location: index.php?page=lead_details&id=" . $leadId);
    exit;
}

// 2.5 Process sending official Meta WhatsApp templates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_lead_whatsapp_template') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $leadId = trim($_POST['lead_id'] ?? '');
    $template_slug = trim($_POST['template_slug'] ?? '');
    $recipient = trim($_POST['recipient'] ?? '');
    $vars = isset($_POST['vars']) && is_array($_POST['vars']) ? $_POST['vars'] : [];
    $mediaUrl = trim($_POST['media_url'] ?? '');

    // Format recipient phone number: remove non-digits, ensure country code (default 91 for India if 10 digits)
    $cleanMobile = preg_replace('/[^0-9]/', '', $recipient);
    if (strlen($cleanMobile) === 10) {
        $cleanMobile = '91' . $cleanMobile;
    } elseif (strlen($cleanMobile) === 11 && str_starts_with($cleanMobile, '0')) {
        $cleanMobile = '91' . substr($cleanMobile, 1);
    }

    if (empty($cleanMobile) || strlen($cleanMobile) < 10) {
        echo json_encode(['success' => false, 'message' => 'Invalid recipient phone number. Please specify a valid 10-12 digit mobile number.']);
        exit;
    }

    if (empty($template_slug)) {
        echo json_encode(['success' => false, 'message' => 'Please select an approved Meta template to dispatch.']);
        exit;
    }

    if (!$db_connected || !$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection offline.']);
        exit;
    }

    try {
        // Fetch approved Meta template from DB
        $stmtTpl = $pdo->prepare("SELECT * FROM whatsapp_templates WHERE slug = ? AND UPPER(meta_status) = 'APPROVED' LIMIT 1");
        $stmtTpl->execute([$template_slug]);
        $tplRow = $stmtTpl->fetch(PDO::FETCH_ASSOC);

        if (!$tplRow) {
            echo json_encode(['success' => false, 'message' => "Template '{$template_slug}' is not found or not approved by Meta."]);
            exit;
        }

        // Fetch lead record to provide variable fallback context
        $stmtLd = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
        $stmtLd->execute([$leadId]);
        $curLead = $stmtLd->fetch(PDO::FETCH_ASSOC) ?: [];

        require_once __DIR__ . '/../../api/whatsapp-api.php';
        $whatsappObj = new WhatsAppAPI($pdo);

        // Language resolution
        $templateLangs = [
            'new_client'            => 'en_US',
            'hello_world'           => 'en_US',
            'amc_overdue'           => 'en',
            'amc_renewal_remainder' => 'en',
            'boo_demo'              => 'en',
            'hello_template'        => 'en',
            'marg_bill'             => 'en'
        ];
        $langCode = $templateLangs[$template_slug] ?? 'en_US';

        $components = [];

        // 1. Header component
        $hType = strtolower($tplRow['header_type'] ?? 'none');
        $chosenMedia = !empty($mediaUrl) ? $mediaUrl : (!empty($tplRow['header_content']) ? trim($tplRow['header_content']) : '');

        if ($hType === 'image') {
            if (empty($chosenMedia) || stripos($chosenMedia, 'scontent.whatsapp.net') !== false) {
                $chosenMedia = 'https://friendlyaisolution.com/assets/image.png';
            }
            $components[] = [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'image',
                        'image' => ['link' => $chosenMedia]
                    ]
                ]
            ];
        } elseif ($hType === 'document' && !empty($chosenMedia)) {
            $components[] = [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'document',
                        'document' => ['link' => $chosenMedia, 'filename' => basename($chosenMedia) ?: 'Document.pdf']
                    ]
                ]
            ];
        } elseif ($hType === 'video' && !empty($chosenMedia)) {
            $components[] = [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'video',
                        'video' => ['link' => $chosenMedia]
                    ]
                ]
            ];
        }

        // 2. Body component & variable mapping
        $bodyTpl = $tplRow['body_text'] ?? '';
        $bodyParams = [];

        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $bodyTpl, $tplMatches);
        $rawVarKeys = !empty($tplMatches[1]) ? $tplMatches[1] : [];

        if (!empty($rawVarKeys)) {
            foreach ($rawVarKeys as $k) {
                $val = trim($vars[$k] ?? ($vars[strtolower($k)] ?? ''));
                if ($val === '') {
                    // Fallback to standard lead defaults
                    if (is_numeric($k)) {
                        switch ($k) {
                            case '1': $val = $curLead['name'] ?: ($curLead['company'] ?: 'Client'); break;
                            case '2': $val = $curLead['enq_for'] ?: ($curLead['products'] ?: 'Marg ERP'); break;
                            case '3': $val = $_SESSION['user_name'] ?? 'Support Team'; break;
                            case '4': $val = 'https://friendlyaisolution.com'; break;
                            default:  $val = '-'; break;
                        }
                    } else {
                        switch (strtolower($k)) {
                            case 'name':
                            case 'client_name':
                            case 'party_name':
                                $val = $curLead['name'] ?: ($curLead['company'] ?: 'Valued Client');
                                break;
                            case 'company':
                            case 'company_name':
                                $val = $curLead['company'] ?: 'Marg Soft';
                                break;
                            case 'phone':
                            case 'mobile':
                                $val = $curLead['phone'] ?: '';
                                break;
                            case 'software_type':
                            case 'product':
                                $val = $curLead['enq_for'] ?: ($curLead['products'] ?: 'Marg ERP');
                                break;
                            case 'agent_name':
                            case 'assigned_to':
                                $val = $curLead['assigned_to'] ?: ($_SESSION['user_name'] ?? 'Support Team');
                                break;
                            default:
                                $val = '-';
                                break;
                        }
                    }
                }

                if (is_numeric($k)) {
                    $bodyParams[] = [
                        'type' => 'text',
                        'text' => (string)$val
                    ];
                } else {
                    $bodyParams[] = [
                        'type' => 'text',
                        'parameter_name' => $k,
                        'text' => (string)$val
                    ];
                }
            }

            if (!empty($bodyParams)) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => $bodyParams
                ];
            }
        }

        // Dispatch via WhatsAppAPI
        $res = $whatsappObj->sendTemplate($cleanMobile, $template_slug, $langCode, $components);

        // Auto-retry with alternate language (en_US <-> en) on language mismatch (error 132001)
        if (empty($res['success'])) {
            $altLang = ($langCode === 'en_US') ? 'en' : 'en_US';
            $retryRes = $whatsappObj->sendTemplate($cleanMobile, $template_slug, $altLang, $components);
            if (!empty($retryRes['success'])) {
                $res = $retryRes;
            }
        }

        if (!empty($res['success'])) {
            $wamid = $res['response']['messages'][0]['id'] ?? ($res['messages'][0]['id'] ?? '');

            // Log timeline activity
            try {
                $actor = $_SESSION['user_name'] ?? 'System User';
                $actionMsg = "Dispatched official Meta WhatsApp Template '{$tplRow['title']}' to +{$cleanMobile}";
                $stmtTl = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                $stmtTl->execute([$leadId, $actor, $actionMsg]);
            } catch (\Throwable $eTl) {}

            echo json_encode([
                'success' => true,
                'message' => "Meta WhatsApp Template '{$tplRow['title']}' delivered successfully to +{$cleanMobile}!",
                'wamid'   => $wamid
            ]);
            exit;
        } else {
            $errMsg = $res['response']['error']['message'] ?? ($res['error']['message'] ?? ($res['message'] ?? 'Meta API error: Could not dispatch template.'));
            echo json_encode([
                'success' => false,
                'message' => $errMsg
            ]);
            exit;
        }
    } catch (\Throwable $ex) {
        echo json_encode([
            'success' => false,
            'message' => 'Server exception: ' . $ex->getMessage()
        ]);
        exit;
    }
}

// Auto-initialize lead_documents table schema
if ($db_connected && $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id VARCHAR(50) NOT NULL,
            doc_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(550) NOT NULL,
            file_type VARCHAR(50) DEFAULT 'pdf',
            file_size VARCHAR(50) DEFAULT '0 KB',
            uploaded_by VARCHAR(100) DEFAULT 'System User',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (lead_id)
        )");
    } catch (PDOException $e) {}
}

// 3. Process document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_lead_document') {
    $leadId = trim($_POST['lead_id'] ?? '');
    $docName = trim($_POST['doc_name'] ?? '');
    
    if (!empty($leadId) && !empty($docName) && isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../uploads/documents/';
        if (!file_exists($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        
        $origName = basename($_FILES['document_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        
        // Strict file extension validation against webshells and executable scripts
        $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'txt'];
        $disallowedExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'exe', 'sh', 'bat', 'cmd', 'ps1', 'py', 'pl', 'cgi', 'js', 'html', 'htm', 'svg', 'vbs'];

        if (in_array($ext, $disallowedExts, true) || !in_array($ext, $allowedExts, true)) {
            $_SESSION['flash_error'] = "Security Warning: File type (." . htmlspecialchars($ext) . ") is not allowed. Only PDF, Office documents, CSV, and standard image files are permitted.";
            header("Location: index.php?page=lead_details&id=" . $leadId);
            exit;
        }

        if ($_FILES['document_file']['size'] > 15 * 1024 * 1024) {
            $_SESSION['flash_error'] = "File size exceeds the 15MB limit.";
            header("Location: index.php?page=lead_details&id=" . $leadId);
            exit;
        }

        $sanitizedName = time() . '_' . rand(100, 999) . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
        $targetPath = $uploadDir . $sanitizedName;
        $relPath = 'uploads/documents/' . $sanitizedName;
        
        $fileSizeNum = $_FILES['document_file']['size'];
        $formattedSize = ($fileSizeNum >= 1048576) 
            ? round($fileSizeNum / 1048576, 2) . ' MB' 
            : round($fileSizeNum / 1024, 1) . ' KB';

        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $targetPath)) {
            if ($db_connected && $pdo) {
                try {
                    $uStmt = $pdo->prepare("INSERT INTO lead_documents (lead_id, doc_name, file_path, file_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $uStmt->execute([$leadId, $docName, $relPath, $ext, $formattedSize, $_SESSION['user_name'] ?? 'System User']);
                    
                    // Log to timeline
                    $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                    $logStmt->execute([$leadId, $_SESSION['user_name'] ?? 'System User', "Uploaded document \"{$docName}\" ({$sanitizedName})."]);
                    
                    $_SESSION['flash_success'] = "Document \"{$docName}\" uploaded successfully!";
                } catch (PDOException $e) {
                    $_SESSION['flash_error'] = "Database error: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['flash_error'] = "Failed to upload file to server.";
        }
    } else {
        $_SESSION['flash_error'] = "Please specify a document title and select a valid file.";
    }
    
    header("Location: index.php?page=lead_details&id=" . $leadId);
    exit;
}

// 5. Process Client Directory details save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_client_directory_details') {
    $leadId = trim($_POST['lead_id'] ?? '');
    
    if ($db_connected && $pdo && !empty($leadId)) {
        try {
            $customer_id = trim($_POST['customer_id'] ?? '');
            $customer_id = preg_replace('/[^0-9]/', '', $customer_id);
            if (empty($customer_id)) {
                $_SESSION['flash_error'] = "Customer ID (License No.) is mandatory and must contain only numbers!";
                header("Location: index.php?page=lead_details&id=" . $leadId . "&active_tab=client-details");
                exit;
            }
            $party_name = trim($_POST['party_name'] ?? '');
            $company_using = trim($_POST['company_using'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $sw_type = trim($_POST['sw_type'] ?? '');
            $software_type = trim($_POST['software_type'] ?? '');
            $nature_of_business = trim($_POST['nature_of_business'] ?? '');
            $user_type = trim($_POST['user_type'] ?? '');
            $no_of_users = intval($_POST['no_of_users'] ?? 1);
            $subpartner_code = trim($_POST['subpartner_code'] ?? '');
            $subpartner_name = trim($_POST['subpartner_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $area = trim($_POST['area'] ?? '');
            $online_zip_code = trim($_POST['online_zip_code'] ?? '');
            $due_on = !empty($_POST['due_on']) ? $_POST['due_on'] : null;
            $act_on = !empty($_POST['act_on']) ? $_POST['act_on'] : null;
            $days = intval($_POST['days'] ?? 365);
            $party_status = trim($_POST['party_status'] ?? 'Active');
            $category = trim($_POST['category'] ?? '');
            $software_trade = trim($_POST['software_trade'] ?? '');
            $version = trim($_POST['version'] ?? '');
            $total_amount = floatval($_POST['total_amount'] ?? 0);
            $software_hit_date = !empty($_POST['software_hit_date']) ? $_POST['software_hit_date'] : null;
            $wallet_id = trim($_POST['wallet_id'] ?? '');
            $home_user = trim($_POST['home_user'] ?? 'No');
            $transferred_party = trim($_POST['transferred_party'] ?? 'No');

            // Check if record exists in client_directory by customer_id OR mobile
            $chk = $pdo->prepare("SELECT id FROM client_directory WHERE customer_id = ? OR mobile = ? LIMIT 1");
            $chk->execute([$customer_id, $mobile]);
            $existing_client = $chk->fetch();

            if ($existing_client) {
                $updClient = $pdo->prepare("UPDATE client_directory SET 
                    party_name = ?, company_using = ?, mobile = ?, email = ?, contact_person = ?,
                    sw_type = ?, software_type = ?, nature_of_business = ?, user_type = ?, no_of_users = ?,
                    subpartner_code = ?, subpartner_name = ?, address = ?, city = ?, area = ?, state = ?, online_zip_code = ?,
                    due_on = ?, act_on = ?, days = ?, party_status = ?, category = ?, software_trade = ?, version = ?,
                    total_amount = ?, software_hit_date = ?, wallet_id = ?, home_user = ?, transferred_party = ?, updated_at = NOW()
                    WHERE id = ?");
                $updClient->execute([
                    $party_name, $company_using, $mobile, $email, $contact_person,
                    $sw_type, $software_type, $nature_of_business, $user_type, $no_of_users,
                    $subpartner_code, $subpartner_name, $address, $city, $area, $state, $online_zip_code,
                    $due_on, $act_on, $days, $party_status, $category, $software_trade, $version,
                    $total_amount, $software_hit_date, $wallet_id, $home_user, $transferred_party,
                    $existing_client['id']
                ]);
            } else {
                $insClient = $pdo->prepare("INSERT INTO client_directory (
                    company_id, customer_id, party_name, company_using, mobile, email, contact_person,
                    sw_type, software_type, nature_of_business, user_type, no_of_users,
                    subpartner_code, subpartner_name, address, city, area, state, online_zip_code,
                    due_on, act_on, days, party_status, category, software_trade, version,
                    total_amount, software_hit_date, wallet_id, home_user, transferred_party, created_at, updated_at
                ) VALUES (
                    1, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, NOW(), NOW()
                )");
                $insClient->execute([
                    $customer_id, $party_name, $company_using, $mobile, $email, $contact_person,
                    $sw_type, $software_type, $nature_of_business, $user_type, $no_of_users,
                    $subpartner_code, $subpartner_name, $address, $city, $area, $state, $online_zip_code,
                    $due_on, $act_on, $days, $party_status, $category, $software_trade, $version,
                    $total_amount, $software_hit_date, $wallet_id, $home_user, $transferred_party
                ]);
            }

            // Log timeline
            $logStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
            $logStmt->execute([$leadId, $_SESSION['user_name'] ?? 'System User', "Updated Client Directory profile for party '{$party_name}'."]);

            $_SESSION['flash_success'] = "Client Directory profile updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Database error: " . $e->getMessage();
        }
    }

    header("Location: index.php?page=lead_details&id=" . $leadId . "&active_tab=client-details");
    exit;
}

// 4. Process document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_lead_document') {
    $docId = intval($_POST['doc_id'] ?? 0);
    $leadId = trim($_POST['lead_id'] ?? '');
    
    if ($db_connected && $pdo && $docId > 0) {
        try {
            $stmtF = $pdo->prepare("SELECT * FROM lead_documents WHERE id = ?");
            $stmtF->execute([$docId]);
            $docRec = $stmtF->fetch();
            if ($docRec) {
                $fullPath = __DIR__ . '/../../' . $docRec['file_path'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
                $delStmt = $pdo->prepare("DELETE FROM lead_documents WHERE id = ?");
                $delStmt->execute([$docId]);
                
                $_SESSION['flash_success'] = "Document \"{$docRec['doc_name']}\" deleted successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Error deleting document: " . $e->getMessage();
        }
    }
    header("Location: index.php?page=lead_details&id=" . $leadId);
    exit;
}

// 2. Fetch Lead details dynamically
$lead = null;
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$id]);
        $lead = $stmt->fetch();
    } catch (PDOException $e) {
        $lead = null;
    }
}

$assigned_operators = [
    'AJAY RATHOUR', 'HARSH SAINI', 'MARG SOFT SOLUTION', 'MOIN KHAN', 
    'NAITIK CHAURASIA', 'POORNIMA BAJPAI', 'SAHIL KUMAR', 'VANDANA YADAV'
];

if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT name FROM users WHERE status = 'Active' AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') ORDER BY name ASC");
        $db_ops = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($db_ops)) {
            $assigned_operators = $db_ops;
        }
    } catch (PDOException $e) {
        // Fallback to static list
    }
}


// Decode installation checklist items
$install_status = [];
if (!empty($lead['installation_status'])) {
    $install_status = json_decode($lead['installation_status'], true);
    if (!is_array($install_status)) {
        $install_status = [];
    }
}

// Fetch Client Directory record if lead matches customer_id or phone
$client_dir_data = null;
if ($db_connected && $pdo && $lead) {
    try {
        $cStmt = $pdo->prepare("SELECT * FROM client_directory WHERE customer_id = ? OR (mobile IS NOT NULL AND mobile != '' AND mobile = ?) LIMIT 1");
        $cStmt->execute([$lead['id'], $lead['phone']]);
        $client_dir_data = $cStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Check if lead status is Closed Won / Won / Client / Converted
$leadStatusNorm = strtolower(str_replace(['_', '-'], ' ', trim($lead['status'] ?? '')));
$isClientWon = in_array($leadStatusNorm, ['closed won', 'won', 'client', 'converted', 'closed']) || !empty($client_dir_data);

// Indian States & Cities Master List (Alphabetically Sorted with Custom Cities Support)
$indian_states_map = function_exists('getIndianStatesAndCities') ? getIndianStatesAndCities() : [];

// Merge custom cities from database into states map
if ($pdo) {
    try {
        $custCitiesStmt = $pdo->query("SELECT DISTINCT state, city FROM custom_cities WHERE city IS NOT NULL AND TRIM(city) != ''");
        while ($cRow = $custCitiesStmt->fetch(PDO::FETCH_ASSOC)) {
            $st = trim($cRow['state'] ?? '');
            $ct = trim($cRow['city'] ?? '');
            if ($st && $ct) {
                if (!isset($indian_states_map[$st])) {
                    $indian_states_map[$st] = [];
                }
                if (!in_array($ct, $indian_states_map[$st], true)) {
                    $indian_states_map[$st][] = $ct;
                }
            }
        }
    } catch (\Throwable $e) {}
}

// 100% Alphabetical sorting for states and all cities
ksort($indian_states_map, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($indian_states_map as $st => &$cList) {
    $cList = array_values(array_unique($cList));
    sort($cList, SORT_NATURAL | SORT_FLAG_CASE);
}
unset($cList);

$indian_states_list = array_keys($indian_states_map);
sort($indian_states_list, SORT_NATURAL | SORT_FLAG_CASE);

$all_indian_cities = [];
foreach ($indian_states_map as $stName => $cList) {
    foreach ($cList as $cItem) {
        $all_indian_cities[] = $cItem;
    }
}
$all_indian_cities = array_values(array_unique($all_indian_cities));
sort($all_indian_cities, SORT_NATURAL | SORT_FLAG_CASE);

// Default City Areas Map
$default_city_areas_map = [
    'Kanpur' => ['Civil Lines', 'Kalyanpur', 'Kakadeo', 'Govind Nagar', 'Kidwai Nagar', 'Swaroop Nagar', 'Barra', 'Lajpat Nagar', 'Gumti No. 5', 'Chakeri', 'Fazalganj', 'Panki', 'Sharda Nagar', 'Vikas Nagar', 'Shyam Nagar', 'Yashoda Nagar', 'Armapur', 'Cantt', 'General Ganj', 'Nayaganj', 'Birhana Road', 'Rawatpur', 'Pandu Nagar', 'Ratan Lal Nagar', 'Juhi'],
    'Lucknow' => ['Hazratganj', 'Gomti Nagar', 'Alambagh', 'Indira Nagar', 'Mahanagar', 'Charbagh', 'Aminabad', 'Jankipuram', 'Ashiyana', 'Chowk', 'Vikas Nagar', 'Rajajipuram', 'Chinhat', 'Transport Nagar'],
    'Delhi' => ['Connaught Place', 'Karol Bagh', 'Lajpat Nagar', 'Chandni Chowk', 'Rohini', 'Dwarka', 'Pitampura', 'Janakpuri', 'Saket', 'South Extension', 'Nehru Place', 'Laxmi Nagar', 'Okhla', 'Mayur Vihar', 'Paschim Vihar'],
    'Noida' => ['Sector 18', 'Sector 62', 'Sector 15', 'Sector 137', 'Sector 50', 'Sector 76', 'Sector 128', 'Sector 63', 'Sector 16', 'Sector 12'],
    'Varanasi' => ['Sigra', 'Lanka', 'Godowlia', 'Cantonment', 'Bhelupur', 'Shivpur', 'Pandeypur', 'Lahurabir', 'Rathyatra', 'Mahmoorganj'],
    'Agra' => ['Sanjay Place', 'Tajganj', 'Kamla Nagar', 'Dayalbagh', 'Shahganj', 'Civil Lines', 'Khandari', 'Sikandra', 'Raja Ki Mandi'],
    'Ghaziabad' => ['Indirapuram', 'Vaishali', 'Vasundhara', 'Raj Nagar', 'Crossings Republik', 'Kavi Nagar', 'Sahibabad'],
    'Meerut' => ['Shastri Nagar', 'Civil Lines', 'Abu Lane', 'Modipuram', 'Ganga Nagar', 'Partapur'],
    'Prayagraj' => ['Civil Lines', 'George Town', 'Katra', 'Tagore Town', 'Naini', 'Allahpur', 'Ashok Nagar', 'Mumfordganj'],
    'Allahabad' => ['Civil Lines', 'George Town', 'Katra', 'Tagore Town', 'Naini', 'Allahpur', 'Ashok Nagar', 'Mumfordganj'],
    'Bareilly' => ['Civil Lines', 'Rajendra Nagar', 'DD Puram', 'Prem Nagar', 'Subhash Nagar', 'Cantonment'],
    'Aligarh' => ['Civil Lines', 'Centre Point', 'Dodhpur', 'Marris Road', 'Ramghat Road', 'Samad Road'],
    'Gorakhpur' => ['Golghar', 'Betiahata', 'Civil Lines', 'Medical College Road', 'Taramandal', 'Shahpur'],
    'Mumbai' => ['Andheri East', 'Andheri West', 'Bandra', 'Borivali', 'Dadar', 'Goregaon', 'Juhu', 'Kandivali', 'Malad', 'Powai', 'Thane', 'Vashi', 'Kurla', 'Ghatkopar', 'Lower Parel', 'Colaba'],
    'Jaipur' => ['Malviya Nagar', 'Vaishali Nagar', 'Mansarovar', 'C-Scheme', 'Raja Park', 'Tonk Road', 'Jagatpura', 'Ajmer Road', 'Bani Park'],
    'Bengaluru' => ['Koramangala', 'Indiranagar', 'Whitefield', 'HSR Layout', 'Jayanagar', 'JP Nagar', 'Electronic City', 'BTM Layout', 'Hebbal', 'Marathahalli'],
    'Kolkata' => ['Salt Lake', 'Park Street', 'New Town', 'Ballygunge', 'Howrah', 'Behala', 'Garia', 'Dum Dum', 'Alipore'],
    'Hyderabad' => ['Hitec City', 'Gachibowli', 'Madhapur', 'Banjara Hills', 'Jubilee Hills', 'Kukatpally', 'Secunderabad', 'Ameerpet', 'Begumpet'],
    'Ahmedabad' => ['Navrangpura', 'Satellite', 'Bodakdev', 'SG Highway', 'Prahlad Nagar', 'Maninagar', 'Vastrapur', 'Bopal'],
    'Pune' => ['Kothrud', 'Hinjewadi', 'Baner', 'Wakad', 'Viman Nagar', 'Hadapsar', 'Aundh', 'Shivajinagar', 'Koregaon Park']
];

// Master S/W Types & Software Editions Mapping
$sw_types_map = [
    'Marg ERP' => [
        'Marg ERP Nano',
        'Marg ERP Basic',
        'Marg ERP Silver',
        'Marg ERP Gold',
        'Marg ERP CA Addition'
    ],
    'Marg Cloud' => [
        'Marg Basic Cloud',
        'Marg Basic Cloud Premium',
        'Marg Silver Cloud'
    ],
    'Marg Books' => [
        'Marg Books Gold',
        'Marg Books Diamond',
        'Marg Books Platinum',
        'Marg Books Platinum Plus',
        'Marg Books Enterprise'
    ],
    'Marg HRMS' => [
        'Marg HRMS Basic',
        'Marg HRMS Silver',
        'Marg HRMS Gold'
    ]
];

$all_software_editions = [];
foreach ($sw_types_map as $sw => $editions) {
    foreach ($editions as $ed) {
        $all_software_editions[] = $ed;
    }
}

// Master Nature of Business List
$nature_of_business_list = [
    'Retail',
    'Wholesale'
];

// Master Software Trade List
$software_trades_list = [
    'Automobile Parts',
    'Electrical',
    'Electronics',
    'FMCG Distributor',
    'FMCG Retailer',
    'Footwear',
    'Garment',
    'General Store',
    'Grocery / Kirana',
    'Hardware',
    'Import / Export',
    'Jewellery',
    'Mandi / Aadhat',
    'Manufacturing',
    'Mobile Shop',
    'Optical',
    'Pharma Distributor',
    'Pharma Manufacturer',
    'Pharmacy / Chemist',
    'Restaurant / Food',
    'Salon & Spa',
    'Stationery / Book Store',
    'Supermarket',
    'Warehouse / Stockist',
    'Wholesale Trader'
];

$operators = [];
if ($db_connected && $pdo) {
    try {
        $stmtOp = $pdo->query("SELECT name FROM users WHERE status = 'Active' AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant') ORDER BY name ASC");
        $operators = $stmtOp->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}
}
?>

<?php if (!$lead): ?>
    <div class="card p-6 text-center" style="max-width: 500px; margin: 4rem auto; border: 1px solid var(--border-color);">
        <i data-lucide="alert-octagon" style="width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1.5rem auto;"></i>
        <h2 class="mb-2" style="font-family: var(--font-heading);">Lead File Not Found</h2>
        <p class="text-muted mb-4">The requested business lead record does not exist or has been removed from the directory.</p>
        <a href="index.php?page=leads" class="btn btn-primary">Return to Leads List</a>
    </div>
<?php return; endif; ?>

<div class="lead-details-container">
    <!-- Breadcrumb Header Control -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <nav class="flex align-center gap-2 text-xs text-muted mb-2">
                <a href="index.php?page=leads" class="text-muted hover-primary">Leads Directory</a>
                <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
                <span class="font-semibold text-main"><?php echo $lead['id']; ?> Details</span>
            </nav>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="flex align-center gap-3">
                <span><?php echo htmlspecialchars($lead['company']); ?></span>
                <span class="badge" style="--badge-bg: <?php echo $lead['priority'] === 'hot' ? 'var(--danger-light)' : ($lead['priority'] === 'warm' ? 'var(--warning-light)' : 'var(--info-light)'); ?>; --badge-color: <?php echo $lead['priority'] === 'hot' ? 'var(--danger)' : ($lead['priority'] === 'warm' ? 'var(--warning)' : 'var(--info)'); ?>; font-size: 11px;">
                    <?php echo ucfirst($lead['priority']); ?> Priority
                </span>
                <?php echo getStatusBadge($lead['status']); ?>
            </h2>
        </div>
        
        <div class="flex gap-2">
            <a href="index.php?page=lead_form&action=edit&id=<?php echo $lead['id']; ?>" class="btn btn-secondary text-sm">
                <i data-lucide="edit-3" style="width: 16px; height: 16px;"></i>
                <span>Edit Lead</span>
            </a>
            <button type="button" class="btn text-sm" onclick="openLeadWhatsAppTab()" style="background: rgba(37, 211, 102, 0.12); color: #0d9f4f; border: 1px solid rgba(37, 211, 102, 0.35); font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                <i data-lucide="message-square" style="width: 15px; height: 15px; color: #10b981;"></i>
                <span>WhatsApp Template</span>
            </button>
            <button class="btn btn-primary text-sm" onclick="openLeadDetailsFollowupModal('<?php echo htmlspecialchars($lead['id']); ?>');">
                <i data-lucide="calendar-plus" style="width: 16px; height: 16px;"></i>
                <span>Schedule Follow-up</span>
            </button>
        </div>
    </div>

    <!-- Double Column Workspace Layout -->
    <div class="grid" style="grid-template-columns: 280px minmax(0, 1fr); gap: 1.5rem; align-items: start;">
        
        <!-- COLUMN 1: Client Overview Card -->
        <div class="flex flex-col gap-4">
            <!-- Summary parameters Card -->
            <div class="card p-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
                <h4 class="text-xs text-muted font-bold mb-4" style="text-transform: uppercase; letter-spacing: 0.05em;">Client Directory Info</h4>
                
                <div class="flex flex-col gap-3">
                    <div class="flex align-center gap-2">
                        <i data-lucide="user" class="text-muted" style="width: 16px; height: 16px;"></i>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">Primary Contact</span>
                            <span class="text-sm font-semibold"><?php echo htmlspecialchars($lead['contact_person'] ?? $lead['name']); ?></span>
                        </div>
                    </div>
                    <div class="flex align-center gap-2">
                        <i data-lucide="phone" class="text-muted" style="width: 16px; height: 16px;"></i>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">Phone Number</span>
                            <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>" class="text-sm font-semibold text-primary"><?php echo htmlspecialchars($lead['phone']); ?></a>
                        </div>
                    </div>
                    <div class="flex align-center gap-2">
                        <i data-lucide="mail" class="text-muted" style="width: 16px; height: 16px;"></i>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">Email Address</span>
                            <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>" class="text-sm font-semibold text-primary"><?php echo htmlspecialchars($lead['email']); ?></a>
                        </div>
                    </div>
                    <div class="flex align-center gap-2">
                        <i data-lucide="map-pin" class="text-muted" style="width: 16px; height: 16px;"></i>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">Location</span>
                            <span class="text-sm font-semibold"><?php echo htmlspecialchars(trim(($lead['city'] ?? '') . ', ' . ($lead['state'] ?? ''), ', ')); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($lead['gst'])): ?>
                    <div class="flex align-center gap-2">
                        <i data-lucide="receipt" class="text-muted" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">GST Number</span>
                            <span class="text-sm font-bold" style="font-family: monospace; color: var(--primary); letter-spacing: 0.5px;"><?php echo htmlspecialchars($lead['gst']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Scan to Call Mobile QR Code Card -->
            <?php
            $clean_phone = preg_replace('/[^0-9+]/', '', $lead['phone'] ?? '');
            if (!empty($clean_phone) && !str_starts_with($clean_phone, '+')) {
                if (strlen($clean_phone) === 10) {
                    $clean_phone = '+91' . $clean_phone;
                }
            }
            // Use direct clean international phone number (+91XXXXXXXXXX) to prevent Google Camera / dialer from reading "tel:" as T9 keypad numbers "853"
            $tel_payload = $clean_phone;
            $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=4&data=' . urlencode($tel_payload);
            $qr_fallback_url = 'https://chart.googleapis.com/chart?cht=qr&chs=180x180&chl=' . urlencode($tel_payload);
            ?>
            <div class="card p-4 flex flex-col align-center text-center" style="border: 1px dashed var(--primary); background-color: rgba(59, 130, 246, 0.04); position: relative; border-radius: var(--border-radius-md);">
                <div class="flex align-center justify-between w-full mb-3">
                    <h4 class="text-xs font-bold" style="text-transform: uppercase; letter-spacing: 0.05em; margin: 0; color: var(--primary);">Scan to Call</h4>
                    <span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.65rem; font-weight: 700;">Mobile Dial</span>
                </div>

                <div style="background: #ffffff; padding: 8px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); display: inline-block; cursor: pointer; transition: transform 0.2s ease;" 
                     onclick="openCallQrModal('<?php echo htmlspecialchars(addslashes($lead['name'])); ?>', '<?php echo htmlspecialchars(addslashes($lead['phone'])); ?>', '<?php echo urlencode($tel_payload); ?>')"
                     title="Scan with phone camera or click to enlarge">
                    <img src="<?php echo $qr_api_url; ?>" 
                         onerror="this.onerror=null; this.src='<?php echo $qr_fallback_url; ?>';"
                         alt="Scan to Call QR Code for <?php echo htmlspecialchars($lead['name']); ?>" 
                         style="width: 145px; height: 145px; border-radius: 6px; display: block;">
                </div>

                <div class="mt-2 flex flex-col align-center gap-1">
                    <span class="text-xs font-bold" style="color: var(--text-main); font-size: 0.85rem;"><?php echo htmlspecialchars($lead['phone']); ?></span>
                    <span class="text-xs text-muted" style="font-size: 0.725rem; line-height: 1.3;">Scan with smartphone camera to load number directly into phone dial pad</span>
                </div>

                <button type="button" 
                        onclick="openCallQrModal('<?php echo htmlspecialchars(addslashes($lead['name'])); ?>', '<?php echo htmlspecialchars(addslashes($lead['phone'])); ?>', '<?php echo urlencode($tel_payload); ?>')"
                        class="btn btn-secondary text-xs w-full mt-3 flex align-center justify-center gap-2" 
                        style="padding: 0.45rem;">
                    <i data-lucide="qr-code" style="width: 14px; height: 14px; color: var(--primary);"></i>
                    <span>Enlarge Call QR</span>
                </button>
            </div>

            <!-- Lead Criteria parameters Card -->
            <div class="card p-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
                <h4 class="text-xs text-muted font-bold mb-4" style="text-transform: uppercase; letter-spacing: 0.05em;">Lead Criteria</h4>
                
                <div class="flex flex-col gap-3">
                    <div>
                        <span class="text-xs text-muted block mb-1">Expected Budget</span>
                        <span class="text-base font-bold" style="font-family: var(--font-heading); color: var(--success);">
                            ₹<?php echo number_format($lead['budget'], 0); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block mb-1">Product Interest</span>
                        <span class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent);">
                            <?php echo htmlspecialchars($lead['products'] ?? 'Marg ERP Pro'); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-xs text-muted block mb-1">Source Channel</span>
                        <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted);">
                            <?php echo htmlspecialchars($lead['source'] ?? 'Website'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Ownership parameters Card -->
            <div class="card p-4" style="border: 1px solid var(--border-color); background-color: var(--bg-card);">
                <h4 class="text-xs text-muted font-bold mb-3" style="text-transform: uppercase; letter-spacing: 0.05em;">Assigned Team</h4>
                <div class="flex align-center gap-3 mb-3">
                    <div style="width: 38px; height: 38px; border-radius: var(--border-radius-full); background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                        <?php echo strtoupper(substr($lead['assigned_to'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-xs text-muted">Assigned To:</span>
                        <span class="text-sm font-semibold"><?php echo htmlspecialchars(!empty($lead['assigned_to']) ? $lead['assigned_to'] : 'Unassigned'); ?></span>
                    </div>
                </div>
                <?php if (!empty($lead['assigned_by'])): ?>
                    <div class="flex align-center gap-3 pt-2" style="border-top: 1px dashed var(--border-color);">
                        <div style="width: 32px; height: 32px; border-radius: var(--border-radius-full); background: var(--bg-hover); color: var(--text-muted); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                            <?php echo strtoupper(substr($lead['assigned_by'], 0, 1)); ?>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-muted">Assigned By (Auto):</span>
                            <span class="text-xs font-semibold" style="color: var(--primary);"><?php echo htmlspecialchars($lead['assigned_by']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- COLUMN 2: Tab Panels Workspace -->
        <?php
        $isClientWonTab = (isset($_GET['active_tab']) && $_GET['active_tab'] === 'client-details');
        $isWhatsappActive = (isset($_GET['tab']) && strtolower($_GET['tab']) === 'whatsapp') || (isset($_GET['active_tab']) && strtolower($_GET['active_tab']) === 'whatsapp');
        $isOverviewActive = !$isWhatsappActive && !$isClientWonTab;
        ?>
        <div class="card p-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); min-height: 520px;">
            <div class="tab-container">
                <!-- Horizontally scrolling Tab navigation links -->
                <div class="tab-nav flex gap-2" style="border-bottom: 2px solid var(--border-color); margin-bottom: 2rem; overflow-x: auto; padding-bottom: 0.5rem;">
                    <button class="tab-link <?php echo $isOverviewActive ? 'active' : ''; ?>" data-tab="tab-overview">Overview</button>
                    <button class="tab-link" data-tab="tab-timeline">Timeline</button>
                    <button class="tab-link" data-tab="tab-followups">Follow-ups</button>
                    <button class="tab-link" data-tab="tab-emails">Emails</button>
                    <button class="tab-link <?php echo $isWhatsappActive ? 'active' : ''; ?>" data-tab="tab-whatsapp" id="tab-nav-whatsapp" style="display: inline-flex; align-items: center; gap: 4px;">
                        <i data-lucide="message-square" style="width: 13px; height: 13px; color: #10b981;"></i> WhatsApp
                    </button>
                    <button class="tab-link" data-tab="tab-quotations">Quotations</button>
                    <button class="tab-link" data-tab="tab-payments">Payments</button>
                    <button class="tab-link" data-tab="tab-installation">Installation</button>
                    <button class="tab-link" data-tab="tab-training">Training</button>
                    <button class="tab-link" data-tab="tab-support">Support</button>
                    <button class="tab-link <?php echo (isset($_GET['active_tab']) && $_GET['active_tab'] === 'documents') ? 'active' : ''; ?>" data-tab="tab-documents">Documents</button>
                    <?php if ($isClientWon): ?>
                        <button class="tab-link <?php echo (isset($_GET['active_tab']) && $_GET['active_tab'] === 'client-details') ? 'active' : ''; ?>" data-tab="tab-client-details" style="background: rgba(16, 185, 129, 0.15); color: #10b981; font-weight: 700; border: 1px solid rgba(16, 185, 129, 0.3);">
                            <i data-lucide="award" style="width: 14px; height: 14px; display: inline-block; vertical-align: middle; margin-right: 4px;"></i> Client Details
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Tab Panels Contents -->

                <!-- TAB 1: Overview -->
                <div class="tab-pane <?php echo $isOverviewActive ? 'active' : ''; ?>" id="tab-overview">
                    <h3 class="text-base font-semibold mb-4">Lead Executive Summary</h3>
                    <p class="text-muted text-sm mb-6"><?php echo htmlspecialchars($lead['remarks'] ?? 'No additional summary recorded.'); ?></p>
                    
                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="overview-item">
                            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">GSTIN / Registration Details</span>
                            <span class="text-sm font-semibold"><?php echo htmlspecialchars($lead['gst'] ?? 'Not Provided'); ?></span>
                        </div>
                        <div class="overview-item">
                            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Registered Address</span>
                            <span class="text-sm font-semibold"><?php echo htmlspecialchars($lead['address'] ?? 'Not Provided'); ?></span>
                        </div>
                        <div class="overview-item">
                            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Lead Health Factor</span>
                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700;">94% Win Probability</span>
                        </div>
                        <div class="overview-item">
                            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Lead Creation Date</span>
                            <span class="text-sm font-semibold"><?php echo date('Y-m-d h:i A', strtotime($lead['created_at'])); ?></span>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: Timeline -->
                <div class="tab-pane" id="tab-timeline">
                    <h3 class="text-base font-semibold mb-4">Activity Timeline Log</h3>
                    <div class="timeline flex flex-col gap-4">
                        <?php
                        $timeline_logs = [];
                        if ($db_connected && $pdo) {
                            try {
                                $stmt = $pdo->prepare("SELECT * FROM timeline WHERE lead_id = ? ORDER BY log_time DESC");
                                $stmt->execute([$lead['id']]);
                                $timeline_logs = $stmt->fetchAll();
                            } catch (PDOException $e) {
                                $timeline_logs = [];
                            }
                        }
                        if (empty($timeline_logs)):
                        ?>
                            <div class="text-center text-muted py-6">
                                <i data-lucide="history" style="width: 32px; height: 32px; margin: 0 auto 0.75rem auto;"></i>
                                <p class="text-sm">No activity records logged for this lead yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($timeline_logs as $log): ?>
                                <div class="timeline-item flex gap-3 align-start" style="position: relative; padding-bottom: 1rem; border-left: 2px solid var(--border-color); padding-left: 1.25rem; margin-left: 0.5rem;">
                                    <div style="position: absolute; left: -6px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background-color: var(--primary);"></div>
                                    <div class="timeline-content">
                                        <span class="text-xs text-muted font-semibold block mb-1">
                                            <?php echo date('Y-m-d h:i A', strtotime($log['log_time'])); ?> • <?php echo htmlspecialchars($log['actor']); ?>
                                        </span>
                                        <p class="text-sm font-semibold" style="margin: 0; color: var(--text-main);"><?php echo htmlspecialchars($log['action_taken']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TAB 3: Follow-ups -->
                <div class="tab-pane" id="tab-followups">
                    <div class="flex justify-between align-center mb-4">
                        <h3 class="text-base font-semibold m-0">Upcoming & Past Follow-ups</h3>
                        <button class="btn btn-primary text-xs" onclick="openLeadDetailsFollowupModal('<?php echo htmlspecialchars($lead['id']); ?>')">Add Follow-up</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Scheduled Date</th>
                                    <th>Action Type</th>
                                    <th>Remarks</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $followups_list = [];
                                if ($db_connected && $pdo) {
                                    try {
                                        $stmt = $pdo->prepare("SELECT * FROM followups WHERE lead_id = ? ORDER BY scheduled_at DESC");
                                        $stmt->execute([$lead['id']]);
                                        $followups_list = $stmt->fetchAll();
                                    } catch (PDOException $e) {
                                        $followups_list = [];
                                    }
                                }
                                if (empty($followups_list)):
                                ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No follow-ups scheduled yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($followups_list as $f): ?>
                                        <tr>
                                            <td class="text-sm font-semibold"><?php echo date('Y-m-d h:i A', strtotime($f['scheduled_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($f['action_type']); ?></td>
                                            <td class="text-muted text-xs"><?php echo htmlspecialchars($f['remarks'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge" style="--badge-bg: <?php echo $f['status'] === 'completed' ? 'var(--success-light)' : 'var(--warning-light)'; ?>; --badge-color: <?php echo $f['status'] === 'completed' ? 'var(--success)' : 'var(--warning)'; ?>;">
                                                    <?php echo ucfirst($f['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 4: Emails -->
                <div class="tab-pane" id="tab-emails">
                    <div class="flex justify-between align-center mb-4">
                        <div>
                            <h3 class="text-base font-semibold m-0">Emails Archive</h3>
                            <p class="text-xs text-muted m-0">Review communications sent to client's registered email.</p>
                        </div>
                        <button class="btn btn-primary text-xs" onclick="window.openModal('send-lifecycle-email-modal')" style="font-weight: 600;">
                            <i data-lucide="send" style="width: 14px; height: 14px; margin-right: 0.25rem; display: inline; vertical-align: middle;"></i>
                            <span>Send Lifecycle Email</span>
                        </button>
                    </div>
                    
                    <div class="emails-list flex flex-col gap-3" style="max-height: 400px; overflow-y: auto; padding-right: 0.25rem;">
                        <?php
                        $client_emails = [];
                        if ($db_connected && $pdo) {
                            try {
                                $stmt = $pdo->prepare("SELECT * FROM sent_emails WHERE recipient = ? ORDER BY sent_at DESC");
                                $stmt->execute([$lead['email']]);
                                $client_emails = $stmt->fetchAll();
                            } catch (PDOException $e) {
                                $client_emails = [];
                            }
                        }
                        
                        if (empty($client_emails)):
                        ?>
                            <div class="text-center text-muted py-6">
                                <i data-lucide="mail-warning" style="width: 32px; height: 32px; margin: 0 auto 0.75rem auto;"></i>
                                <p class="text-sm">No lifecycle emails sent to this client yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($client_emails as $mailItem): ?>
                                <div class="email-item card p-4 flex flex-col gap-2" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                                    <div class="flex justify-between align-center">
                                        <span class="font-semibold text-sm text-primary"><?php echo htmlspecialchars($mailItem['subject']); ?></span>
                                        <span class="text-xs text-muted"><?php echo date('Y-m-d h:i A', strtotime($mailItem['sent_at'])); ?></span>
                                    </div>
                                    <div class="text-xs text-muted">
                                        Status: 
                                        <span class="badge" style="--badge-bg: <?php echo strpos($mailItem['status'], 'Failed') !== false ? 'var(--danger-light)' : 'var(--success-light)'; ?>; --badge-color: <?php echo strpos($mailItem['status'], 'Failed') !== false ? 'var(--danger)' : 'var(--success)'; ?>; font-size: 10px; font-weight: 600;">
                                            <?php echo htmlspecialchars($mailItem['status']); ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted mt-2" style="background-color: var(--bg-card); padding: 0.75rem; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); max-height: 150px; overflow-y: auto; font-family: monospace; white-space: pre-wrap;">
                                        <?php echo htmlspecialchars(strip_tags($mailItem['body'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                
                                <!-- TAB: WhatsApp (Meta Approved Templates Studio) -->
                <div class="tab-pane <?php echo $isWhatsappActive ? 'active' : ''; ?>" id="tab-whatsapp">
                    <?php
                    // Fetch Meta Approved Templates
                    $meta_templates = [];
                    if ($db_connected && $pdo) {
                        try {
                            $stmtWT = $pdo->query("SELECT id, title, slug, category, header_type, header_text, header_content, body_text, footer_text, buttons_json, meta_status FROM whatsapp_templates WHERE UPPER(meta_status) = 'APPROVED' ORDER BY (CASE WHEN slug LIKE '%lead%' OR slug LIKE '%demo%' OR slug LIKE '%hello%' OR slug = 'new_client' THEN 1 ELSE 2 END), id DESC");
                            $meta_templates = $stmtWT->fetchAll(PDO::FETCH_ASSOC);
                        } catch (\PDOException $e) {
                            $meta_templates = [];
                        }
                    }

                    // Fetch previous WhatsApp message logs for this lead
                    $lead_wa_logs = [];
                    if ($db_connected && $pdo && !empty($lead['phone'])) {
                        try {
                            $cleanPhone = preg_replace('/[^0-9]/', '', $lead['phone']);
                            $last10 = substr($cleanPhone, -10);
                            if (!empty($last10)) {
                                $stmtLogs = $pdo->prepare("SELECT id, direction, recipient_or_sender, message_type, message_body, wamid, status, created_at FROM message_logs WHERE recipient_or_sender LIKE ? ORDER BY id DESC LIMIT 30");
                                $stmtLogs->execute(["%$last10%"]);
                                $lead_wa_logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
                            }
                        } catch (\PDOException $e) {
                            $lead_wa_logs = [];
                        }
                    }
                    ?>
                    
                    <!-- WhatsApp Studio Top Bar -->
                    <div class="wa-studio-header">
                        <div class="flex align-center gap-3">
                            <div class="wa-studio-icon">
                                <i data-lucide="message-square" style="width: 18px; height: 18px; color: #10b981;"></i>
                            </div>
                            <div>
                                <div class="flex align-center gap-2">
                                    <h3 class="wa-studio-title">WhatsApp Template Studio</h3>
                                    <span class="wa-pill-verified">
                                        <i data-lucide="shield-check" style="width: 11px; height: 11px;"></i> Meta Cloud API Active
                                    </span>
                                </div>
                                <p class="wa-studio-sub">Dispatch official pre-approved templates directly to this client's verified mobile</p>
                            </div>
                        </div>

                        <!-- Sub-tab Switcher: Composer vs History -->
                        <div class="flex align-center gap-2">
                            <div class="wa-mode-toggle">
                                <button type="button" class="wa-mode-btn active" id="wa-btn-mode-compose" onclick="switchWAMode('compose')">
                                    <i data-lucide="send" style="width: 13px; height: 13px;"></i> Composer
                                </button>
                                <button type="button" class="wa-mode-btn" id="wa-btn-mode-history" onclick="switchWAMode('history')">
                                    <i data-lucide="history" style="width: 13px; height: 13px;"></i> Deliveries (<span id="wa-history-badge-count"><?php echo count($lead_wa_logs); ?></span>)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Toast Notification Placeholder -->
                    <div id="wa-toast-alert" class="wa-toast hidden"></div>

                    <!-- VIEW 1: 3-Column Studio Composer -->
                    <div id="wa-view-compose" class="wa-studio-grid">
                        
                        <!-- COLUMN 1: Template Explorer (310px) -->
                        <div class="wa-col-templates">
                            <div class="wa-panel-heading">
                                <span class="wa-panel-title">1. Choose Template</span>
                                <span class="wa-panel-meta" id="wa-template-count"><?php echo count($meta_templates); ?> ready</span>
                            </div>

                            <!-- Search & Category Filters -->
                            <div class="wa-search-box">
                                <i data-lucide="search" style="width: 14px; height: 14px; color: var(--text-muted);"></i>
                                <input type="text" id="wa-template-search" placeholder="Search templates..." oninput="filterWATemplates()">
                            </div>

                            <div class="wa-filter-chips">
                                <button type="button" class="wa-chip active" onclick="setWACategoryFilter('all', this)">All</button>
                                <button type="button" class="wa-chip" onclick="setWACategoryFilter('MARKETING', this)">Marketing</button>
                                <button type="button" class="wa-chip" onclick="setWACategoryFilter('UTILITY', this)">Utility</button>
                                <button type="button" class="wa-chip" onclick="setWACategoryFilter('SERVICE', this)">Service</button>
                            </div>

                            <!-- Template List Scroll -->
                            <div class="wa-templates-scroll" id="wa-template-list-container">
                                <?php if (empty($meta_templates)): ?>
                                    <div class="wa-empty-state">
                                        <i data-lucide="file-question" style="width: 28px; height: 28px; color: var(--text-muted); margin-bottom: 0.5rem;"></i>
                                        <p class="text-xs text-muted m-0">No approved templates found.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($meta_templates as $index => $t): 
                                        $cat = strtoupper($t['category'] ?? 'UTILITY');
                                        $hType = strtolower($t['header_type'] ?? 'none');
                                    ?>
                                        <div class="wa-tpl-card <?php echo $index === 0 ? 'active' : ''; ?>" 
                                             data-id="<?php echo $t['id']; ?>"
                                             data-slug="<?php echo htmlspecialchars($t['slug']); ?>"
                                             data-title="<?php echo htmlspecialchars($t['title']); ?>"
                                             data-category="<?php echo htmlspecialchars($cat); ?>"
                                             data-header-type="<?php echo htmlspecialchars($hType); ?>"
                                             data-header-content="<?php echo htmlspecialchars($t['header_content'] ?? ''); ?>"
                                             data-body="<?php echo htmlspecialchars($t['body_text'] ?? ''); ?>"
                                             data-footer="<?php echo htmlspecialchars($t['footer_text'] ?? ''); ?>"
                                             data-buttons="<?php echo htmlspecialchars($t['buttons_json'] ?? ''); ?>"
                                             onclick="selectWATemplateCard(this)">
                                            
                                            <div class="wa-tpl-card-top">
                                                <span class="wa-tag wa-tag-<?php echo strtolower($cat); ?>"><?php echo htmlspecialchars($cat); ?></span>
                                                <span class="wa-tpl-type">
                                                    <?php if ($hType !== 'none'): ?>
                                                        <i data-lucide="<?php echo $hType === 'image' ? 'image' : ($hType === 'document' ? 'file-text' : 'video'); ?>" style="width: 10px; height: 10px;"></i>
                                                        <?php echo ucfirst($hType); ?>
                                                    <?php else: ?>
                                                        Text
                                                    <?php endif; ?>
                                                </span>
                                            </div>

                                            <div class="wa-tpl-card-title"><?php echo htmlspecialchars($t['title']); ?></div>
                                            <div class="wa-tpl-card-snippet"><?php echo htmlspecialchars($t['body_text'] ?? ''); ?></div>
                                            
                                            <div class="wa-tpl-card-footer">
                                                <code><?php echo htmlspecialchars($t['slug']); ?></code>
                                                <span class="wa-verified-pill"><i data-lucide="check" style="width: 9px; height: 9px;"></i> Approved</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- COLUMN 2: Parameters Composer (~380px) -->
                        <div class="wa-col-composer">
                            <div class="wa-panel-heading">
                                <span class="wa-panel-title">2. Parameters & Variables</span>
                                <span class="wa-tpl-badge-slug" id="wa-selected-template-badge">Loading...</span>
                            </div>

                            <form id="wa-lead-dispatch-form" class="wa-form-wrapper" onsubmit="submitWALeadTemplate(event)">
                                <input type="hidden" name="action" value="send_lead_whatsapp_template">
                                <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars($lead['id']); ?>">
                                <input type="hidden" name="template_slug" id="wa-form-template-slug" value="">

                                <!-- Recipient Target -->
                                <div class="wa-form-section">
                                    <div class="flex justify-between align-center mb-1">
                                        <label class="wa-input-label m-0">Client Mobile Number</label>
                                        <span class="wa-input-hint">India (+91)</span>
                                    </div>
                                    <div class="wa-input-prefix-box">
                                        <span class="wa-prefix-tag">+91</span>
                                        <input type="text" name="recipient" id="wa-form-recipient" class="wa-control-input font-semibold" value="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $lead['phone'] ?? '')); ?>" required>
                                    </div>
                                </div>

                                <!-- Dynamic Parameters Container -->
                                <div id="wa-variables-wrapper" class="wa-form-section" style="display: none;">
                                    <div class="flex justify-between align-center mb-2">
                                        <label class="wa-input-label m-0">Template Dynamic Values</label>
                                        <span class="wa-input-hint">Auto-mapped from Lead</span>
                                    </div>
                                    <div id="wa-variables-fields" class="wa-vars-list">
                                        <!-- Injected via JavaScript -->
                                    </div>
                                </div>

                                <!-- Header Media Attachment (Conditional) -->
                                <div id="wa-media-wrapper" class="wa-form-section" style="display: none;">
                                    <label class="wa-input-label mb-1" id="wa-media-label">Header Media Attachment</label>
                                    <input type="text" name="media_url" id="wa-form-media-url" class="wa-control-input" placeholder="https://..." oninput="updateWAPreview()">
                                    <span class="wa-input-hint mt-1 block">Leave empty to use the pre-configured template default media.</span>
                                </div>

                                <!-- Action Buttons -->
                                <div class="wa-composer-actions mt-3">
                                    <button type="submit" id="wa-submit-dispatch-btn" class="wa-btn-send">
                                        <i data-lucide="send" style="width: 15px; height: 15px;"></i>
                                        <span>Dispatch via WhatsApp</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- COLUMN 3: Smartphone Device Mockup (~340px) -->
                        <div class="wa-col-device">
                            <div class="wa-panel-heading">
                                <span class="wa-panel-title">3. Live Device Preview</span>
                                <span class="wa-live-badge"><span class="wa-live-dot"></span> Real-time</span>
                            </div>

                            <!-- Authentic Smartphone WhatsApp Frame -->
                            <div class="wa-device-frame">
                                <!-- Phone Notch & Status -->
                                <div class="wa-phone-top-bar">
                                    <div class="wa-phone-chat-info">
                                        <div class="wa-phone-avatar">
                                            <?php echo strtoupper(substr($lead['name'] ?: ($lead['company'] ?: 'C'), 0, 1)); ?>
                                        </div>
                                        <div class="wa-phone-contact">
                                            <div class="wa-phone-name"><?php echo htmlspecialchars($lead['name'] ?: ($lead['company'] ?: 'Client')); ?></div>
                                            <div class="wa-phone-status">Online</div>
                                        </div>
                                    </div>
                                    <div class="wa-phone-actions">
                                        <i data-lucide="video" style="width: 14px; height: 14px; color: #ffffff;"></i>
                                        <i data-lucide="phone" style="width: 13px; height: 13px; color: #ffffff;"></i>
                                    </div>
                                </div>

                                <!-- Chat Body Wallpaper -->
                                <div class="wa-phone-chat-body">
                                    <div class="wa-phone-date-pill">TODAY</div>

                                    <!-- Incoming/Outgoing Message Bubble with authentic WhatsApp tail -->
                                    <div class="wa-chat-bubble">
                                        <!-- Header Media -->
                                        <div id="wa-preview-header" class="wa-bubble-media" style="display: none;">
                                            <img id="wa-preview-header-img" src="" alt="Header" style="width: 100%; max-height: 140px; object-fit: cover; display: none;">
                                            <div id="wa-preview-header-doc" class="wa-bubble-doc-card" style="display: none;">
                                                <div class="wa-doc-icon-box">
                                                    <i data-lucide="file-text" style="width: 18px; height: 18px; color: #ffffff;"></i>
                                                </div>
                                                <div class="wa-doc-info">
                                                    <div class="wa-doc-name">Official Document.pdf</div>
                                                    <div class="wa-doc-size">PDF Document • 240 KB</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Message Body Content -->
                                        <div id="wa-preview-body" class="wa-bubble-text">Select a template on the left to preview...</div>

                                        <!-- Message Footer Text -->
                                        <div id="wa-preview-footer" class="wa-bubble-footer" style="display: none;"></div>

                                        <!-- Bubble Meta & Blue Ticks -->
                                        <div class="wa-bubble-meta-row">
                                            <span class="wa-bubble-time"><?php echo date('h:i A'); ?></span>
                                            <span class="wa-bubble-ticks">
                                                <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb;"></i>
                                            </span>
                                        </div>

                                        <!-- Interactive Action Buttons -->
                                        <div id="wa-preview-buttons" class="wa-bubble-action-btns" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- VIEW 2: Delivery History Audit Table -->
                    <div id="wa-view-history" class="wa-history-view" style="display: none;">
                        <div class="flex justify-between align-center mb-3">
                            <div>
                                <h4 class="font-bold text-sm m-0" style="font-family: var(--font-heading);">Meta WhatsApp Delivery Audit Logs</h4>
                                <p class="text-xs text-muted m-0">Full record of template messages dispatched to +<?php echo htmlspecialchars($lead['phone']); ?></p>
                            </div>
                            <button type="button" class="btn btn-secondary text-xs" onclick="switchWAMode('compose')">
                                <i data-lucide="arrow-left" style="width: 13px; height: 13px;"></i> Return to Composer
                            </button>
                        </div>

                        <div class="table-responsive" style="border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-card);">
                            <table class="table" style="font-size: 0.75rem;">
                                <thead>
                                    <tr>
                                        <th style="width: 150px;">Sent Timestamp</th>
                                        <th style="width: 130px;">Recipient</th>
                                        <th>Message / Template Title</th>
                                        <th style="width: 120px;">Meta Status</th>
                                        <th style="width: 180px;">WAMID Reference</th>
                                    </tr>
                                </thead>
                                <tbody id="wa-history-tbody">
                                    <?php if (empty($lead_wa_logs)): ?>
                                        <tr id="wa-no-logs-row">
                                            <td colspan="5" class="text-center text-muted py-6" style="font-style: italic;">
                                                <i data-lucide="inbox" style="width: 24px; height: 24px; margin: 0 auto 0.5rem auto; display: block; opacity: 0.5;"></i>
                                                No previous WhatsApp template messages dispatched to this client yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($lead_wa_logs as $log): 
                                            $st = strtolower($log['status'] ?? 'sent');
                                            $stClass = ($st === 'delivered' || $st === 'read' || $st === 'sent') ? 'var(--success)' : 'var(--danger)';
                                            $stBg = ($st === 'delivered' || $st === 'read' || $st === 'sent') ? 'var(--success-light)' : 'var(--danger-light)';
                                            $stIcon = ($st === 'delivered' || $st === 'read' || $st === 'sent') ? 'check-check' : 'alert-circle';
                                        ?>
                                            <tr>
                                                <td class="font-semibold text-muted"><?php echo date('d-M-Y h:i A', strtotime($log['created_at'])); ?></td>
                                                <td class="font-semibold">+<?php echo htmlspecialchars($log['recipient_or_sender']); ?></td>
                                                <td style="word-break: break-word; font-weight: 500;"><?php echo htmlspecialchars($log['message_body']); ?></td>
                                                <td>
                                                    <span class="badge text-xs font-semibold" style="--badge-bg: <?php echo $stBg; ?>; --badge-color: <?php echo $stClass; ?>; font-size: 0.68rem; padding: 2px 7px; display: inline-flex; align-items: center; gap: 4px;">
                                                        <i data-lucide="<?php echo $stIcon; ?>" style="width: 11px; height: 11px;"></i>
                                                        <?php echo ucfirst($st); ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted" style="font-family: monospace; font-size: 0.68rem;"><?php echo htmlspecialchars(substr($log['wamid'] ?? 'N/A', 0, 22) . '...'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- TAB 5: Quotations -->
                <div class="tab-pane" id="tab-quotations">
                    <div class="flex justify-between align-center mb-4">
                        <h3 class="text-base font-semibold m-0">Quotations History</h3>
                        <a href="index.php?page=quotation_create&lead=<?php echo $lead['id']; ?>" class="btn btn-primary text-xs">Create Quotation</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Quote Number</th>
                                    <th>Taxable Amt</th>
                                    <th>Gross Total</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $quotes_list = [];
                                if ($db_connected && $pdo) {
                                    try {
                                        $stmt = $pdo->prepare("SELECT * FROM quotations WHERE lead_id = ? ORDER BY created_at DESC");
                                        $stmt->execute([$lead['id']]);
                                        $quotes_list = $stmt->fetchAll();
                                    } catch (PDOException $e) {
                                        $quotes_list = [];
                                    }
                                }
                                if (empty($quotes_list)):
                                ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No quotations generated yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($quotes_list as $q): ?>
                                        <tr>
                                            <td class="text-sm font-semibold"><?php echo htmlspecialchars($q['id']); ?></td>
                                            <td>₹<?php echo number_format($q['taxable_amount'], 2); ?></td>
                                            <td class="font-bold text-sm">₹<?php echo number_format($q['grand_total'], 2); ?></td>
                                            <td>
                                                <span class="badge" style="--badge-bg: <?php echo $q['status'] === 'approved' ? 'var(--success-light)' : 'var(--warning-light)'; ?>; --badge-color: <?php echo $q['status'] === 'approved' ? 'var(--success)' : 'var(--warning)'; ?>;">
                                                    <?php echo ucfirst($q['status']); ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;"><a href="index.php?page=quotation_view&id=<?php echo $q['id']; ?>" class="btn btn-secondary text-xs" style="padding: 0.25rem 0.5rem;">Preview</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 6: Payments -->
                <div class="tab-pane" id="tab-payments">
                    <h3 class="text-base font-semibold mb-4">Invoices & Milestones Log</h3>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Due Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $invoices_list = [];
                                if ($db_connected && $pdo) {
                                    try {
                                        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE lead_id = ? ORDER BY date_issued DESC");
                                        $stmt->execute([$lead['id']]);
                                        $invoices_list = $stmt->fetchAll();
                                    } catch (PDOException $e) {
                                        $invoices_list = [];
                                    }
                                }
                                if (empty($invoices_list)):
                                ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No invoices issued for this lead.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices_list as $i): ?>
                                        <tr>
                                            <td class="text-sm font-semibold"><?php echo htmlspecialchars($i['id']); ?></td>
                                            <td><?php echo htmlspecialchars($i['due_date']); ?></td>
                                            <td class="font-bold">₹<?php echo number_format($i['total_amount'], 2); ?></td>
                                            <td>
                                                <span class="badge" style="--badge-bg: <?php echo $i['status'] === 'paid' ? 'var(--success-light)' : 'var(--danger-light)'; ?>; --badge-color: <?php echo $i['status'] === 'paid' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                                    <?php echo ucfirst($i['status']); ?>
                                                </span>
                                            </td>
                                            <td><button class="btn btn-secondary text-xs" style="padding: 0.25rem 0.5rem;" onclick="alert('Proceeding to record payment receipt...');">Record Receipt</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 7: Installation -->
                <div class="tab-pane" id="tab-installation">
                    <h3 class="text-base font-semibold mb-2">System Installation Checklist</h3>
                    <p class="text-xs text-muted mb-4">Mark configuration steps as they are completed on client hardware.</p>
                    
                    <div class="install-progress-row mb-6">
                        <div class="flex justify-between align-center mb-2">
                            <span class="text-xs text-muted font-semibold" id="install-progress-percentage">0% Completed</span>
                            <span class="text-xs text-muted">Engineer: Vikas Patel</span>
                        </div>
                        <div style="background-color: var(--border-color); height: 8px; border-radius: 4px; overflow: hidden;">
                            <div id="install-progress-bar" style="background-color: var(--primary); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3">
                        <label class="install-checklist-item flex align-center gap-3 pointer" style="padding: 0.5rem 0.75rem; background-color: var(--bg-app); border-radius: var(--border-radius-sm);">
                            <input type="checkbox" class="install-checkbox" value="Software Installation" <?php echo in_array('Software Installation', $install_status) ? 'checked' : ''; ?> style="accent-color: var(--primary);">
                            <span class="text-sm">Software Installation</span>
                        </label>
                        <label class="install-checklist-item flex align-center gap-3 pointer" style="padding: 0.5rem 0.75rem; background-color: var(--bg-app); border-radius: var(--border-radius-sm);">
                            <input type="checkbox" class="install-checkbox" value="Using Trial Period" <?php echo in_array('Using Trial Period', $install_status) ? 'checked' : ''; ?> style="accent-color: var(--primary);">
                            <span class="text-sm">Using Trial Period</span>
                        </label>
                        <label class="install-checklist-item flex align-center gap-3 pointer" style="padding: 0.5rem 0.75rem; background-color: var(--bg-app); border-radius: var(--border-radius-sm);">
                            <input type="checkbox" class="install-checkbox" value="License Update in Software" <?php echo in_array('License Update in Software', $install_status) ? 'checked' : ''; ?> style="accent-color: var(--primary);">
                            <span class="text-sm">License Update in Software</span>
                        </label>
                    </div>
                </div>

                <!-- TAB 8: Training -->
                <div class="tab-pane" id="tab-training">
                    <h3 class="text-base font-semibold mb-4">Client User Training Logs</h3>
                    <div class="card p-4 mb-4" style="border: 1px solid var(--border-color); background-color: var(--bg-app);">
                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <span class="text-xs text-muted block mb-1">Training Trainer</span>
                                <span class="text-sm font-semibold">Prakash Raj</span>
                            </div>
                            <div>
                                <span class="text-xs text-muted block mb-1">Status</span>
                                <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Pending Deployment</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 9: Support -->
                <div class="tab-pane" id="tab-support">
                    <div class="flex justify-between align-center mb-4">
                        <h3 class="text-base font-semibold m-0">Support Tickets Center</h3>
                        <a href="index.php?page=support" class="btn btn-primary text-xs" style="text-decoration: none; font-weight: 600;">Manage Support Tickets</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table" style="font-size: 0.85rem;">
                            <thead>
                                <tr style="text-align: left;">
                                    <th>Ticket ID</th>
                                    <th>Subject Issue</th>
                                    <th>Priority</th>
                                    <th>Assigned Engineer</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $lead_tickets = [];
                                if ($db_connected && $pdo) {
                                    try {
                                        $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE lead_id = ? ORDER BY date_created DESC");
                                        $stmt->execute([$lead['id']]);
                                        $lead_tickets = $stmt->fetchAll();
                                    } catch (PDOException $e) {
                                        $lead_tickets = [];
                                    }
                                }
                                if (empty($lead_tickets)):
                                ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-6">No support tickets recorded for this client yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($lead_tickets as $t): ?>
                                        <tr>
                                            <td class="font-bold text-xs"><?php echo $t['id']; ?></td>
                                            <td class="font-semibold"><?php echo htmlspecialchars($t['subject']); ?></td>
                                            <td>
                                                <?php 
                                                if ($t['priority'] === 'critical') {
                                                    echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); font-size: 9px; font-weight: 700;">Critical</span>';
                                                } elseif ($t['priority'] === 'high') {
                                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-size: 9px; font-weight: 700;">High</span>';
                                                } else {
                                                    echo '<span class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 9px; font-weight: 700;">' . ucfirst($t['priority']) . '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="font-medium"><?php echo htmlspecialchars($t['assigned_to']); ?></td>
                                            <td>
                                                <?php 
                                                if ($t['status'] === 'resolved') {
                                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-size: 9px; font-weight: 600;">Resolved</span>';
                                                } else {
                                                    echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger); font-size: 9px; font-weight: 600;">' . ucfirst($t['status']) . '</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 10: Documents -->
                <div class="tab-pane" id="tab-documents">
                    <div class="flex justify-between align-center mb-4">
                        <div>
                            <h3 class="text-base font-semibold m-0">Client Attachments Archive</h3>
                            <p class="text-xs text-muted m-0">Upload and manage GSTIN, PAN, Purchase Orders, Agreements, and Legal verification documents.</p>
                        </div>
                        <button class="btn btn-primary text-xs" onclick="window.openModal('upload-document-modal');">
                            <i data-lucide="upload" style="width: 14px; height: 14px;"></i>
                            <span>Upload Document</span>
                        </button>
                    </div>

                    <?php
                    $documents_list = [];
                    if ($db_connected && $pdo) {
                        try {
                            $dStmt = $pdo->prepare("SELECT * FROM lead_documents WHERE lead_id = ? ORDER BY created_at DESC");
                            $dStmt->execute([$lead['id']]);
                            $documents_list = $dStmt->fetchAll();
                        } catch (PDOException $e) {
                            $documents_list = [];
                        }
                    }
                    ?>

                    <?php if (empty($documents_list)): ?>
                        <div class="text-center text-muted py-8 card p-6" style="border: 1px dashed var(--border-color); background-color: var(--bg-app); border-radius: var(--border-radius-md);">
                            <i data-lucide="folder-open" style="width: 38px; height: 38px; margin: 0 auto 0.75rem auto; color: var(--text-muted);"></i>
                            <p class="text-sm font-semibold mb-1" style="color: var(--text-main);">No documents attached yet</p>
                            <p class="text-xs text-muted mb-4">Upload GST certificate, PAN card, registration forms, or agreements for <?php echo htmlspecialchars($lead['name']); ?>.</p>
                            <button class="btn btn-secondary text-xs" onclick="window.openModal('upload-document-modal');">
                                <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                                <span>Upload First Document</span>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                            <?php foreach ($documents_list as $doc): ?>
                                <?php
                                $ext = strtolower($doc['file_type']);
                                $icon = 'file-text';
                                $badgeBg = 'var(--primary-light)';
                                $badgeColor = 'var(--primary)';
                                if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                                    $icon = 'file-image';
                                    $badgeBg = 'var(--accent-light)';
                                    $badgeColor = 'var(--accent)';
                                } elseif ($ext === 'pdf') {
                                    $icon = 'file-text';
                                    $badgeBg = 'var(--danger-light)';
                                    $badgeColor = 'var(--danger)';
                                } elseif (in_array($ext, ['zip', 'rar', '7z'])) {
                                    $icon = 'file-archive';
                                    $badgeBg = 'var(--warning-light)';
                                    $badgeColor = 'var(--warning)';
                                } elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) {
                                    $icon = 'file-spreadsheet';
                                    $badgeBg = 'var(--success-light)';
                                    $badgeColor = 'var(--success)';
                                }
                                ?>
                                <div class="doc-card flex align-center justify-between p-3 card" style="border: 1px solid var(--border-color); background-color: var(--bg-card); border-radius: var(--border-radius-md);">
                                    <div class="flex align-center gap-3" style="min-width: 0;">
                                        <div style="background-color: <?php echo $badgeBg; ?>; color: <?php echo $badgeColor; ?>; padding: 0.6rem; border-radius: var(--border-radius-sm); flex-shrink: 0;">
                                            <i data-lucide="<?php echo $icon; ?>" style="width: 22px; height: 22px;"></i>
                                        </div>
                                        <div class="flex flex-col text-truncate" style="min-width: 0;">
                                            <span class="text-sm font-semibold text-truncate" style="color: var(--text-main);" title="<?php echo htmlspecialchars($doc['doc_name']); ?>">
                                                <?php echo htmlspecialchars($doc['doc_name']); ?>
                                            </span>
                                            <span class="text-xs text-muted">
                                                <?php echo strtoupper($doc['file_type']); ?> • <?php echo htmlspecialchars($doc['file_size']); ?> • <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                            </span>
                                            <span class="text-xs text-muted" style="font-size: 10px;">Uploaded by: <?php echo htmlspecialchars($doc['uploaded_by']); ?></span>
                                        </div>
                                    </div>
                                    <div class="flex align-center gap-1" style="flex-shrink: 0;">
                                        <button type="button" class="btn-icon" style="color: var(--primary);" title="View / Preview Document" onclick="viewDocumentModal('<?php echo htmlspecialchars($doc['file_path']); ?>', '<?php echo htmlspecialchars(addslashes($doc['doc_name'])); ?>', '<?php echo $ext; ?>');">
                                            <i data-lucide="eye" style="width: 15px; height: 15px;"></i>
                                        </button>
                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" download class="btn-icon" title="Download Document">
                                            <i data-lucide="download" style="width: 15px; height: 15px;"></i>
                                        </a>
                                        <form action="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                            <input type="hidden" name="action" value="delete_lead_document">
                                            <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                            <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
                                            <button type="submit" class="btn-icon text-danger" title="Delete Document">
                                                <i data-lucide="trash-2" style="width: 15px; height: 15px; color: var(--danger);"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 11: Client Details (Enabled when Closed Won / Client) -->
                <?php if ($isClientWon): ?>
                    <div class="tab-pane <?php echo (isset($_GET['active_tab']) && $_GET['active_tab'] === 'client-details') ? 'active' : ''; ?>" id="tab-client-details">
                        <div class="flex justify-between align-center mb-6">
                            <div>
                                <h3 class="text-base font-semibold mb-1" style="display: flex; align-items: center; gap: 8px;">
                                    <i data-lucide="award" style="width: 20px; height: 20px; color: var(--success);"></i>
                                    <span>Client Directory Record</span>
                                </h3>
                                <p class="text-xs text-muted">Manage license, software details, activation & party directory attributes stored in `client_directory` table.</p>
                            </div>
                            <span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700; padding: 6px 12px;">
                                <i data-lucide="check-circle" style="width: 13px; height: 13px; display: inline; margin-right: 4px;"></i> Active Client Account
                            </span>
                        </div>

                        <form action="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" method="POST" class="flex flex-col gap-6">
                            <input type="hidden" name="action" value="save_client_directory_details">
                            <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">

                            <!-- Live Duplicate/Found Match Banner -->
                            <div id="lead_customer_id_matched_banner" style="display: none; margin-bottom: 1rem; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 10px 14px; font-size: 0.8rem; color: #065f46; align-items: center; justify-content: space-between;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i data-lucide="check-circle" style="width: 18px; height: 18px; color: #10b981; flex-shrink: 0;"></i>
                                    <span><strong>Existing Client Record Found!</strong> Details loaded automatically for <strong id="lead_matched_party_label"></strong>.</span>
                                </div>
                                <!-- <span class="badge" style="--badge-bg: #10b981; --badge-color: #ffffff; font-size: 0.68rem; font-weight: 700; padding: 3px 8px;">Live Database Match</span> -->
                            </div>

                            <!-- Section 1: Business & Software License Info -->
                            <div class="card p-5" style="border: 1px solid var(--border-color); background: var(--bg-hover);">
                                <h4 class="text-xs font-bold text-muted mb-4" style="text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary);">
                                    1. Software License & Party Basic Details
                                </h4>
                                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold" style="display: flex; justify-content: space-between; align-items: center;">
                                            <span>Customer ID (License No.) <span class="text-danger">*</span></span>
                                            <span id="lead_customer_id_live_status" style="font-size: 0.68rem; font-weight: 700;"></span>
                                        </label>
                                        <?php 
                                            $rawCustId = $client_dir_data['customer_id'] ?? '';
                                            // Only display if purely numeric license no (do not display lead IDs like LD-348577)
                                            $displayCustId = (preg_match('/^[0-9]+$/', $rawCustId)) ? $rawCustId : '';
                                        ?>
                                        <input type="text" id="lead_customer_id" name="customer_id" class="form-control text-sm font-mono font-bold" value="<?php echo htmlspecialchars($displayCustId); ?>" required pattern="[0-9]+" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, ''); onLeadCustomerIdInput(this.value);" onblur="onLeadCustomerIdInput(this.value);" placeholder="E.g. 7933108" title="Customer ID (License Number) must be numbers only">
                                        <span class="text-xs text-muted" style="font-size: 11px;">Type numeric license number to auto-fetch existing client details</span>
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Party Name <span class="text-danger">*</span></label>
                                        <input type="text" id="lead_client_party_name" name="party_name" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['party_name'] ?? $lead['name']); ?>" required placeholder="Full Client / Party Name">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Company / Firm Using <span class="text-danger">*</span></label>
                                        <input type="text" id="lead_client_company_using" name="company_using" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['company_using'] ?? $lead['company']); ?>" required placeholder="Firm / Organization Name">
                                    </div>
                                    <?php 
                                        $curSwType = $client_dir_data['sw_type'] ?? 'Marg ERP';
                                        if (empty($curSwType)) $curSwType = 'Marg ERP';
                                        $curSoftwareType = $client_dir_data['software_type'] ?? ($lead['products'] ?? '');
                                        $curNob = $client_dir_data['nature_of_business'] ?? '';
                                        $curTrade = $client_dir_data['software_trade'] ?? '';
                                    ?>
                                    <!-- S/W Type Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">S/W Type <span class="text-danger">*</span></label>
                                        <select id="lead_client_sw_type" name="sw_type" class="form-control text-sm font-semibold" onchange="onLeadSwTypeChange(this.value)">
                                            <option value="">-- Select S/W Type --</option>
                                            <?php foreach (array_keys($sw_types_map) as $swName): ?>
                                                <option value="<?php echo htmlspecialchars($swName); ?>" <?php echo (strcasecmp($swName, $curSwType) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($swName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Software Edition Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Software Edition <span class="text-danger">*</span></label>
                                        <select id="lead_client_software_type" name="software_type" class="form-control text-sm font-semibold">
                                            <option value="">-- Select S/W Type First --</option>
                                            <?php 
                                                $initialEditions = $sw_types_map[$curSwType] ?? $all_software_editions;
                                                foreach ($initialEditions as $ed):
                                            ?>
                                                <option value="<?php echo htmlspecialchars($ed); ?>" <?php echo (strcasecmp($ed, $curSoftwareType) === 0 || strcasecmp(preg_replace('/^(marg\s*erp|marg)\s*/i', '', $ed), $curSoftwareType) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ed); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Nature of Business Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Nature of Business</label>
                                        <select id="lead_client_nature_of_business" name="nature_of_business" class="form-control text-sm font-semibold">
                                            <option value="">-- Select Nature of Business --</option>
                                            <?php foreach ($nature_of_business_list as $nob): ?>
                                                <option value="<?php echo htmlspecialchars($nob); ?>" <?php echo (strcasecmp($nob, $curNob) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($nob); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Software Trade Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Software Trade</label>
                                        <select id="lead_client_software_trade" name="software_trade" class="form-control text-sm font-semibold">
                                            <option value="">-- Select Software Trade --</option>
                                            <?php foreach ($software_trades_list as $trade): ?>
                                                <option value="<?php echo htmlspecialchars($trade); ?>" <?php echo (strcasecmp($trade, $curTrade) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($trade); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- User License Type Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">User License Type</label>
                                        <select id="lead_client_user_type" name="user_type" class="form-control text-sm font-semibold">
                                            <?php $ut = $client_dir_data['user_type'] ?? 'Single User'; ?>
                                            <option value="Single User" <?php echo $ut === 'Single User' ? 'selected' : ''; ?>>Single User</option>
                                            <option value="Multi User" <?php echo $ut === 'Multi User' ? 'selected' : ''; ?>>Multi User</option>
                                            <option value="Enterprise LAN" <?php echo $ut === 'Enterprise LAN' ? 'selected' : ''; ?>>Enterprise LAN</option>
                                        </select>
                                    </div>

                                    <!-- No. of Users Input -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">No. of Users</label>
                                        <input type="number" id="lead_client_no_of_users" name="no_of_users" class="form-control text-sm font-mono" value="<?php echo htmlspecialchars($client_dir_data['no_of_users'] ?? 1); ?>" min="1">
                                    </div>

                                    <!-- Software Version Input -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Software Version</label>
                                        <input type="text" id="lead_client_version" name="version" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['version'] ?? '9.2.14'); ?>" placeholder="E.g. 9.2.14">
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Contact & Location Info -->
                            <div class="card p-5" style="border: 1px solid var(--border-color); background: var(--bg-hover);">
                                <h4 class="text-xs font-bold text-muted mb-4" style="text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary);">
                                    2. Contact & Geographic Location
                                </h4>
                                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Contact Person</label>
                                        <input type="text" id="lead_client_contact_person" name="contact_person" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['contact_person'] ?? ($lead['contact_person'] ?? $lead['name'])); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Mobile Number <span class="text-danger">*</span></label>
                                        <input type="text" id="lead_client_mobile" name="mobile" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['mobile'] ?? $lead['phone']); ?>" required>
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Email Address</label>
                                        <input type="email" id="lead_client_email" name="email" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['email'] ?? $lead['email']); ?>">
                                    </div>
                                    <!-- State Dropdown -->
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">State</label>
                                        <?php 
                                            $curState = $client_dir_data['state'] ?? ($lead['state'] ?? 'Uttar Pradesh');
                                            if (empty($curState)) $curState = 'Uttar Pradesh';
                                        ?>
                                        <select id="lead_client_state" name="state" class="form-control text-sm font-semibold" onchange="onLeadClientStateSelect(this.value)">
                                            <option value="">-- Select State --</option>
                                            <?php foreach ($indian_states_list as $stName): ?>
                                                <option value="<?php echo htmlspecialchars($stName); ?>" <?php echo (strcasecmp($stName, $curState) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($stName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- City Dropdown -->
                                    <div class="form-group m-0">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <label class="form-label text-xs font-semibold" style="margin: 0;">City</label>
                                            <button type="button" onclick="openQuickAddLeadCityPrompt()" class="btn-ghost" title="Add New City for selected State" style="background: none; border: none; padding: 0 2px; font-size: 0.7rem; font-weight: 700; color: var(--primary); cursor: pointer; display: flex; align-items: center; gap: 3px;">
                                                <i data-lucide="plus-circle" style="width: 12px; height: 12px;"></i> Add City
                                            </button>
                                        </div>
                                        <?php 
                                            $curCity = $client_dir_data['city'] ?? ($lead['city'] ?? 'Kanpur');
                                            if (empty($curCity)) $curCity = 'Kanpur';
                                        ?>
                                        <select id="lead_client_city" name="city" class="form-control text-sm font-semibold" onchange="onLeadClientCitySelect(this.value)">
                                            <option value="">-- Select City --</option>
                                            <?php 
                                                $stateCities = $indian_states_map[$curState] ?? $all_indian_cities;
                                                foreach ($stateCities as $cName): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($cName); ?>" <?php echo (strcasecmp($cName, $curCity) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Area / Locality Smart Searchable Combobox -->
                                    <div class="form-group m-0" style="position: relative;" id="lead_area_autocomplete_wrapper">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <label class="form-label text-xs font-semibold" style="margin: 0;">Area / Locality</label>
                                            <button type="button" onclick="openQuickAddLeadAreaPrompt()" class="btn-ghost" title="Add New Area for selected City" style="background: none; border: none; padding: 0 2px; font-size: 0.7rem; font-weight: 700; color: var(--primary); cursor: pointer; display: flex; align-items: center; gap: 3px;">
                                                <i data-lucide="plus-circle" style="width: 12px; height: 12px;"></i> Add Area
                                            </button>
                                        </div>
                                        <div style="position: relative;">
                                            <input type="text" id="lead_client_area" name="area" autocomplete="off" placeholder="Search or type area..." class="form-control text-sm" style="padding-right: 32px;" value="<?php echo htmlspecialchars($client_dir_data['area'] ?? ''); ?>" onfocus="onLeadAreaInputFocus()" oninput="onLeadAreaInputChange(this.value)" onkeydown="onLeadAreaInputKeydown(event)">
                                            <div id="lead_area_input_icon_spinner" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted); display: flex; align-items: center;">
                                                <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                            </div>
                                        </div>

                                        <!-- Smart Autocomplete Suggestions Dropdown -->
                                        <div id="lead_area_autocomplete_dropdown" style="display: none; position: absolute; top: calc(100% + 2px); left: 0; right: 0; z-index: 1050; background: var(--bg-card, #fff); border: 1px solid var(--border-color); border-radius: 10px; box-shadow: 0 12px 28px -6px rgba(0,0,0,0.4); max-height: 220px; overflow-y: auto; padding: 4px;">
                                            <!-- Dynamic Area Options injected via JS -->
                                        </div>
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Online Zip / Pincode</label>
                                        <input type="text" id="lead_client_online_zip" name="online_zip_code" class="form-control text-sm font-mono" value="<?php echo htmlspecialchars($client_dir_data['online_zip_code'] ?? ''); ?>" placeholder="Pincode">
                                    </div>
                                    <div class="form-group m-0" style="grid-column: 1 / -1;">
                                        <label class="form-label text-xs font-semibold">Complete Address</label>
                                        <textarea id="lead_client_address" name="address" class="form-control text-sm" rows="2"><?php echo htmlspecialchars($client_dir_data['address'] ?? ($lead['address'] ?? '')); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Subscription, Financials & Partner Info -->
                            <div class="card p-5" style="border: 1px solid var(--border-color); background: var(--bg-hover);">
                                <h4 class="text-xs font-bold text-muted mb-4" style="text-transform: uppercase; letter-spacing: 0.05em; color: var(--primary);">
                                    3. Subscription Validity, Partner & Financials
                                </h4>
                                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Subpartner Code</label>
                                        <input type="text" id="lead_client_subpartner_code" name="subpartner_code" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['subpartner_code'] ?? ''); ?>" placeholder="E.g. SP-4092">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Subpartner Name</label>
                                        <input type="text" id="lead_client_subpartner_name" name="subpartner_name" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['subpartner_name'] ?? ''); ?>" placeholder="Subpartner Name">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Activation Date (`act_on`)</label>
                                        <input type="date" id="lead_client_act_on" name="act_on" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['act_on'] ?? date('Y-m-d')); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Renewal Due Date (`due_on`)</label>
                                        <input type="date" id="lead_client_due_on" name="due_on" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['due_on'] ?? date('Y-m-d', strtotime('+1 year'))); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Validity Days</label>
                                        <input type="number" id="lead_client_days" name="days" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['days'] ?? 365); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Total Amount (₹)</label>
                                        <input type="number" step="0.01" id="lead_client_total_amount" name="total_amount" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['total_amount'] ?? ($lead['budget'] ?? 0)); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Party Status</label>
                                        <select id="lead_client_party_status" name="party_status" class="form-control text-sm">
                                            <?php $ps = $client_dir_data['party_status'] ?? 'Active'; ?>
                                            <option value="Active" <?php echo $ps === 'Active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="Expiring Soon" <?php echo $ps === 'Expiring Soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                                            <option value="Expired" <?php echo $ps === 'Expired' ? 'selected' : ''; ?>>Expired</option>
                                            <option value="Suspended" <?php echo $ps === 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Software Hit Date</label>
                                        <input type="date" id="lead_client_software_hit_date" name="software_hit_date" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['software_hit_date'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Wallet ID</label>
                                        <input type="text" id="lead_client_wallet_id" name="wallet_id" class="form-control text-sm" value="<?php echo htmlspecialchars($client_dir_data['wallet_id'] ?? ''); ?>" placeholder="Wallet / Account ID">
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Home User?</label>
                                        <select id="lead_client_home_user" name="home_user" class="form-control text-sm">
                                            <?php $hu = $client_dir_data['home_user'] ?? 'No'; ?>
                                            <option value="No" <?php echo $hu === 'No' ? 'selected' : ''; ?>>No</option>
                                            <option value="Yes" <?php echo $hu === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                    <div class="form-group m-0">
                                        <label class="form-label text-xs font-semibold">Transferred Party?</label>
                                        <select id="lead_client_transferred_party" name="transferred_party" class="form-control text-sm">
                                            <?php $tp = $client_dir_data['transferred_party'] ?? 'No'; ?>
                                            <option value="No" <?php echo $tp === 'No' ? 'selected' : ''; ?>>No</option>
                                            <option value="Yes" <?php echo $tp === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Action Bar -->
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="submit" class="btn btn-primary text-sm" style="background-color: var(--success); border-color: var(--success); padding: 0.75rem 1.75rem;">
                                    <i data-lucide="save" style="width: 16px; height: 16px; margin-right: 4px; display: inline-block; vertical-align: middle;"></i>
                                    <span>Save Client Directory Record</span>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<!-- Modal: Send Lifecycle Email -->
<div id="send-lifecycle-email-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Send Lifecycle Email</h3>
            <button class="btn-icon" onclick="window.closeModal('send-lifecycle-email-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=lead_details" method="POST">
            <input type="hidden" name="action" value="send_lead_email">
            <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
            
            <!-- Hidden inputs to supply token replacement values dynamically -->
            <input type="hidden" id="lead-id-val" value="<?php echo $lead['id']; ?>">
            <input type="hidden" id="client-name-val" value="<?php echo htmlspecialchars($lead['contact_person'] ?? $lead['name']); ?>">
            <input type="hidden" id="client-company-val" value="<?php echo htmlspecialchars($lead['company']); ?>">
            <input type="hidden" id="client-budget-val" value="₹<?php echo number_format($lead['budget'], 2); ?>">

            <div class="form-group m-0">
                <label class="form-label text-xs">Recipient Address</label>
                <input type="email" name="recipient" class="form-control" value="<?php echo htmlspecialchars($lead['email']); ?>" required readonly style="background-color: var(--border-card); opacity: 0.8;">
            </div>
            
            <div class="form-group m-0">
                <label class="form-label text-xs">Email Type / Template</label>
                <select id="email-template-selector" class="form-control" onchange="updateEmailTemplate()" required>
                    <option value="custom">-- Custom Empty Mail --</option>
                    <option value="demo_schedule">Demo Scheduled Confirmation</option>
                    <option value="demo_reschedule">Demo Rescheduled Notification</option>
                    <option value="payment_reminder">Payment Due / Invoicing Reminder</option>
                </select>
            </div>

            <!-- Date Picker for schedule parameters -->
            <div class="form-group m-0" id="email-date-picker-container" style="display: none;">
                <label class="form-label text-xs" id="email-date-label">Schedule / Payment Due Date</label>
                <input type="datetime-local" id="email-custom-date" class="form-control" onchange="applyTemplateFields()">
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs">Email Subject Line</label>
                <input type="text" id="email-subject-input" name="subject" class="form-control" placeholder="E.g. Invoice #INV-4509 Pending Receipt" required>
            </div>
            
            <div class="form-group m-0">
                <label class="form-label text-xs">Email Body Content</label>
                <textarea id="email-body-input" name="body" class="form-control" rows="8" placeholder="Type email message body here..." required style="font-family: var(--font-body); font-size: 13px; line-height: 1.5;"></textarea>
            </div>

            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('send-lifecycle-email-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" style="background-color: var(--success); border-color: var(--success);">
                    <i data-lucide="send" style="width: 14px; height: 14px; margin-right: 0.25rem; display: inline-block; vertical-align: middle;"></i>
                    <span>Confirm & Send Email</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Follow-up & Data Fill Modal (Modern Enterprise UI) -->
<div id="quick-followup-modal" class="modal-overlay hidden" style="display: none !important; position: fixed !important; inset: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(15, 23, 42, 0.65) !important; align-items: center !important; justify-content: center !important; z-index: 2000 !important; backdrop-filter: blur(6px) !important; -webkit-backdrop-filter: blur(6px) !important; padding: 1rem !important; box-sizing: border-box !important;">
    <div class="qf-modal-dialog">
        <!-- Modal Header -->
        <div class="qf-modal-header">
            <div class="qf-header-meta">
                <div class="qf-header-icon">
                    <i data-lucide="phone-call" style="width: 22px; height: 22px;"></i>
                </div>
                <div class="qf-header-title-box">
                    <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
                        <h3 class="qf-header-title" id="qf-modal-title">Follow-Up For Client</h3>
                        <span id="qf-hero-lead-badge" class="qf-id-badge" style="display: none;"></span>
                    </div>
                    <p class="qf-header-sub">Update lead classification, team assignment and schedule interaction</p>
                </div>
            </div>
            <button type="button" class="qf-close-btn" onclick="closeQuickFollowupModal()" title="Close (Esc)">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <!-- High-Priority Hero Contact Bar: Large Prominent Phone, 1-Click Copy & Quick Actions -->
        <div class="qf-hero-contact-bar" id="qf-hero-contact-bar">
            <div class="qf-hero-phone-group">
                <div class="qf-hero-phone-meta">
                    <span class="qf-hero-badge-tag">
                        <i data-lucide="phone" style="width: 13px; height: 13px;"></i> Mobile Number
                    </span>
                    <span class="qf-hero-phone-number" id="qf-hero-phone-val">--</span>
                </div>
                <div class="qf-hero-buttons">
                    <button type="button" class="qf-hero-btn qf-hero-btn-copy" id="qf-copy-phone-btn" title="Copy Mobile Number">
                        <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                        <span class="btn-text">Copy</span>
                    </button>
                    <a href="#" id="qf-hero-wa-link" class="qf-hero-btn qf-hero-btn-wa" title="Open Meta WhatsApp Template Studio for this Lead">
                        <i data-lucide="message-square" style="width: 14px; height: 14px;"></i>
                        <span class="btn-text">WhatsApp</span>
                    </a>
                    <a href="#" id="qf-hero-call-link" class="qf-hero-btn qf-hero-btn-call" title="Call directly">
                        <i data-lucide="phone-outgoing" style="width: 14px; height: 14px;"></i>
                        <span class="btn-text">Call</span>
                    </a>
                </div>
            </div>

            <div class="qf-hero-email-group" id="qf-hero-email-wrap" style="display: none;">
                <div class="qf-hero-email-meta">
                    <span class="qf-hero-badge-tag">
                        <i data-lucide="mail" style="width: 13px; height: 13px;"></i> Email Address
                    </span>
                    <span class="qf-hero-email-val" id="qf-hero-email-val">--</span>
                </div>
                <button type="button" class="qf-hero-btn qf-hero-btn-copy" id="qf-copy-email-btn" title="Copy Email">
                    <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                    <span class="btn-text">Copy</span>
                </button>
            </div>
        </div>
        
        <!-- Modal Form Content -->
        <form id="quick-followup-form" method="POST" class="qf-modal-form">
            <input type="hidden" name="action" value="quick_followup_save">
            <input type="hidden" name="lead_id" id="qf-lead-id" value="<?php echo htmlspecialchars($lead['id'] ?? ''); ?>">
            
            <div class="qf-modal-body">
                <!-- Left Column: Lead Data & Assignments -->
                <div>
                    <div class="qf-sec-heading">
                        <i data-lucide="user-check" style="width: 14px; height: 14px; color: var(--primary);"></i>
                        <span>Lead Status & Team Assignment</span>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label">Group / Pipeline Stage</label>
                            <select name="group_stage" id="qf-group-stage" class="qf-control" required>
                                <option value="Fresh">Fresh</option>
                                <option value="Followup">Followup</option>
                                <option value="Future Prospect">Future Prospect</option>
                                <option value="Demo Scheduled">Demo Scheduled</option>
                                <option value="Demo Done">Demo Done</option>
                                <option value="Installation Done">Installation Done</option>
                                <option value="Not Required">Not Required</option>
                            </select>
                        </div>
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label">Lead Status</label>
                            <select name="status" id="qf-status" class="qf-control" style="height: 38px; font-size: 0.84rem;">
                                <?php foreach ($PIPELINE_STAGES as $key => $stage): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($stage['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qf-form-group" style="margin-bottom: 0; position: relative;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                                <label class="qf-label m-0">Assign Member(s)</label>
                                <span id="qf-assigned-count-badge" style="font-size: 0.68rem; font-weight: 700; color: var(--primary);">0 Selected</span>
                            </div>
                            <div id="qf-assigned-dropdown-wrapper" style="position: relative;">
                                <button type="button" id="qf-assigned-dropdown-btn" class="qf-control" onclick="toggleQfAssignedDropdown(event)" style="text-align: left; display: flex; align-items: center; justify-content: space-between; cursor: pointer; padding: 0.48rem 0.65rem; height: 38px;">
                                    <span id="qf-assigned-btn-text" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; font-weight: 600; font-size: 0.82rem; color: var(--text-main);">
                                        -- Select Team --
                                    </span>
                                    <i data-lucide="chevron-down" style="width: 14px; height: 14px; color: var(--text-muted); flex-shrink: 0; transition: transform 0.2s;" id="qf-assigned-chevron"></i>
                                </button>

                                <!-- Dropdown Menu with Checkboxes -->
                                <div id="qf-assigned-dropdown-menu" style="display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; min-width: 220px; background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #cbd5e1); border-radius: 10px; box-shadow: 0 14px 28px rgba(0,0,0,0.18); z-index: 1060; padding: 6px; max-height: 200px; overflow-y: auto;">
                                    <div style="padding: 2px 4px 6px; border-bottom: 1px solid var(--border-color, #e2e8f0); margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 0.68rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Team Members</span>
                                        <button type="button" onclick="clearQfAssigned(event)" style="background: none; border: none; font-size: 0.68rem; color: var(--primary); font-weight: 700; cursor: pointer; padding: 0;">Clear All</button>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 2px;">
                                        <?php foreach ($operators as $op): 
                                            $opParts = preg_split('/\s+/', trim($op));
                                            $initials = '';
                                            if (count($opParts) >= 2) {
                                                $initials = strtoupper(substr($opParts[0], 0, 1) . substr($opParts[count($opParts)-1], 0, 1));
                                            } else {
                                                $initials = strtoupper(substr(trim($op), 0, 2));
                                            }
                                        ?>
                                            <label class="qf-dropdown-item" style="display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 6px; cursor: pointer; transition: background 0.15s; font-size: 0.78rem; color: var(--text-main);">
                                                <input type="checkbox" name="assigned_to[]" class="qf-assigned-cb" value="<?php echo htmlspecialchars($op); ?>" onchange="updateQfAssignedBtnText()" style="width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary);">
                                                <span class="qf-emp-avatar" style="width: 20px; height: 20px; font-size: 0.62rem;"><?php echo htmlspecialchars($initials); ?></span>
                                                <span style="flex: 1; font-weight: 500;"><?php echo htmlspecialchars($op); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1.6fr; gap: 0.65rem; margin-bottom: 0.75rem;">
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label" style="color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
                                <i data-lucide="lock" style="width: 12px; height: 12px;"></i>
                                Assigned By (Audit Trail)
                            </label>
                            <input type="text" id="qf-assigned-by" class="qf-control" readonly disabled placeholder="Not assigned yet" style="height: 38px; font-size: 0.84rem;">
                        </div>
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label" style="display: flex; align-items: center; gap: 0.3rem;">
                                <i data-lucide="map-pin" style="width: 12px; height: 12px; color: var(--text-muted);"></i>
                                Address
                            </label>
                            <input type="text" name="address" id="qf-address" class="qf-control" placeholder="Full office or delivery address..." style="height: 38px; font-size: 0.84rem;">
                        </div>
                    </div>
                    
                    <hr class="qf-sec-divider" style="margin: 0.75rem 0 0.65rem 0;">
                    
                    <div class="qf-sec-heading" style="margin-bottom: 0.65rem;">
                        <i data-lucide="sliders" style="width: 13px; height: 13px; color: var(--primary);"></i>
                        <span>Additional Details</span>
                    </div>
                    
                    <div class="grid" style="grid-template-columns: 1fr 1fr 1.2fr; gap: 0.65rem; margin-bottom: 0.75rem;">
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label">Source</label>
                            <select name="source" id="qf-source" class="qf-control" style="height: 36px; font-size: 0.82rem;">
                                <option value="Website">Website</option>
                                <option value="Google Ads">Google Ads</option>
                                <option value="Cold Calls">Cold Calls</option>
                                <option value="Referrals">Referrals</option>
                                <option value="Exhibitions">Exhibitions</option>
                                <option value="HO">HO</option>
                                <option value="Office">Office</option>
                                <option value="Self">Self</option>
                                <option value="Door to Door">Door to Door</option>
                                <option value="GSTN Data">GSTN Data</option>
                                <option value="Imported">Imported</option>
                            </select>
                        </div>
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label">Enquiry For</label>
                            <select name="enq_for" id="qf-enq-for" class="qf-control" style="height: 36px; font-size: 0.82rem;">
                                <option value="">-- Choose --</option>
                                <option value="Marg Basic">Marg Basic</option>
                                <option value="Marg Silver">Marg Silver</option>
                                <option value="Marg Gold">Marg Gold</option>
                                <option value="Marg Nano">Marg Nano</option>
                                <option value="Marg Hr">Marg Hr</option>
                                <option value="Marg Colud">Marg Colud</option>
                                <option value="Marg Book Gold">Marg Book Gold</option>
                                <option value="Marg Book Silver">Marg Book Silver</option>
                                <option value="Marg Enterprises">Marg Enterprises</option>
                                <option value="Marg Mart">Marg Mart</option>
                                <option value="Marg Dimond">Marg Dimond</option>
                            </select>
                        </div>
                        <div class="qf-form-group" style="margin-bottom: 0;">
                            <label class="qf-label">Contact Person</label>
                            <input type="text" name="contact_person" id="qf-contact-person" class="qf-control" placeholder="Contact person name" style="height: 36px; font-size: 0.82rem;">
                        </div>
                    </div>
                    
                    <div class="qf-form-group" style="margin-bottom: 0;">
                        <label class="qf-label">Lead Remarks</label>
                        <textarea name="remarks" id="qf-remarks" class="qf-control" style="min-height: 44px; height: 44px; font-size: 0.84rem; line-height: 1.35;" placeholder="General background notes or requirements..."></textarea>
                    </div>
                </div>
                
                <!-- Right Column: Schedule Follow-up Reminder & History -->
                <div class="qf-scheduler-panel">
                    <div>
                        <div class="flex justify-between align-center mb-1.5">
                            <div class="qf-sec-heading m-0" style="color: var(--primary); font-size: 0.8rem;">
                                <i data-lucide="bell-ring" style="width: 13px; height: 13px;"></i>
                                <span>Schedule Follow-up Reminder</span>
                            </div>
                        </div>

                        <!-- Quick Date Shortcuts -->
                        <div class="qf-quick-dates-bar mb-2" style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                            <button type="button" class="qf-date-shortcut-btn" onclick="setQFQuickDate('today')" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; border-radius: 6px;">
                                <i data-lucide="sun" style="width: 11px; height: 11px;"></i> Today 5 PM
                            </button>
                            <button type="button" class="qf-date-shortcut-btn" onclick="setQFQuickDate('tomorrow')" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; border-radius: 6px;">
                                <i data-lucide="sunrise" style="width: 11px; height: 11px;"></i> Tomorrow 11 AM
                            </button>
                            <button type="button" class="qf-date-shortcut-btn" onclick="setQFQuickDate('2days')" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; border-radius: 6px;">
                                <i data-lucide="calendar" style="width: 11px; height: 11px;"></i> +2 Days
                            </button>
                            <button type="button" class="qf-date-shortcut-btn" onclick="setQFQuickDate('nextweek')" style="padding: 0.25rem 0.55rem; font-size: 0.75rem; border-radius: 6px;">
                                <i data-lucide="calendar-days" style="width: 11px; height: 11px;"></i> Next Week
                            </button>
                        </div>

                        <!-- Date & Time & Action Type Row -->
                        <div class="grid mb-2" style="grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                            <div class="qf-form-group m-0">
                                <label class="qf-label">Date & Time</label>
                                <input type="datetime-local" name="scheduled_at" id="qf-scheduled-at" class="qf-control" style="height: 36px; padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                            </div>
                            <div class="qf-form-group m-0">
                                <label class="qf-label">Action Type</label>
                                <select name="action_type" id="qf-action-type" class="qf-control" style="height: 36px; padding: 0.4rem 0.6rem; font-size: 0.85rem;">
                                    <option value="Call">Call / Phone Call</option>
                                    <option value="Trail Installed">Trail Installed</option>
                                    <option value="Data Input Follow Up">Data Input Follow Up</option>
                                    <option value="Payment Followup">Payment Followup</option>
                                    <option value="Rest Amt Followup">Rest Amt Followup</option>
                                    <option value="Product Demo">Product Demo</option>
                                    <option value="On-Site Visit">On-Site Visit</option>
                                    <option value="Renewal / Expiry">Renewal / Expiry</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Reminder Notes / Instructions with Smart Telecaller Suggestions -->
                        <div class="qf-form-group mb-2">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                                <label class="qf-label m-0" style="font-size: 0.82rem; font-weight: 700;">Reminder Notes / Action Plan</label>
                                <span class="text-xs text-muted" style="font-size: 0.68rem;">Quick Suggestion Chips below</span>
                            </div>

                            <!-- Smart Suggestion Quick Chips Bar -->
                            <div id="qf-smart-suggestions-bar" style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 6px;">
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="CUSTOMER CALL BACK">
                                    Call Back
                                </button>
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="Call Not Picked / Busy">
                                    Not Picked / Busy
                                </button>
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="Interested - Quotation / Details Shared on WhatsApp">
                                    WhatsApp Shared
                                </button>
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="Demo Scheduled - Team to connect">
                                    Demo Scheduled
                                </button>
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="Rate Quote Discussion / Negotiation">
                                    Rate Quote
                                </button>
                                <button type="button" class="qf-chip-btn" onclick="applyQfNoteSuggestion(this.getAttribute('data-note'))" data-note="Payment Followup - Balance Due">
                                    Payment Due
                                </button>
                            </div>

                            <textarea name="fup_notes" id="qf-fup-notes" class="qf-control" style="min-height: 52px; height: 52px; padding: 0.45rem 0.65rem; font-size: 0.85rem;" placeholder="Type notes or click any quick suggestion above..."></textarea>
                        </div>
                    </div>

                    <!-- Upcoming & Past Follow-ups Section -->
                    <div style="border-top: 1px solid var(--border-color, #e2e8f0); padding-top: 0.55rem; flex: 1; display: flex; flex-direction: column;">
                        <div class="flex justify-between align-center mb-1.5">
                            <div class="qf-sec-heading m-0" style="font-size: 0.78rem;">
                                <i data-lucide="history" style="width: 12px; height: 12px;"></i>
                                <span>Upcoming & Past Activity</span>
                            </div>
                            <span class="badge text-xs" id="qf-history-count" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 10px;">0 entries</span>
                        </div>
                        <div id="qf-history-timeline" class="qf-timeline-box" style="max-height: 110px; overflow-y: auto;">
                            <p class="text-xs text-muted" style="font-style: italic;">Loading history...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal Actions Footer -->
            <div class="qf-modal-footer">
                <span class="text-xs text-muted" style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem;">
                    <i data-lucide="info" style="width: 13px; height: 13px;"></i>
                    Syncs lead stage & schedules next reminder
                </span>
                <div class="flex gap-2">
                    <button type="button" class="qf-btn-cancel" onclick="closeQuickFollowupModal()">Cancel</button>
                    <button type="submit" id="qf-submit-btn" class="qf-btn-submit">
                        <i data-lucide="check" style="width: 15px; height: 15px;"></i>
                        <span>Save & Log Follow-up</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* ==========================================================================
   Quick Follow-up Modal Modern Enterprise Styling (High Legibility & Hero Info)
   ========================================================================== */
#quick-followup-modal {
    display: none !important;
    position: fixed !important;
    inset: 0 !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(15, 23, 42, 0.65) !important;
    backdrop-filter: blur(6px) !important;
    -webkit-backdrop-filter: blur(6px) !important;
    z-index: 2000 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 1rem !important;
    box-sizing: border-box !important;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
#quick-followup-modal.open {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}
.qf-modal-dialog {
    width: 100%;
    max-width: 1040px;
    max-height: 92vh;
    background: var(--bg-card, #ffffff);
    border-radius: 16px;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(0, 0, 0, 0.03);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    color: var(--text-main, #0f172a);
    animation: qfModalZoom 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes qfModalZoom {
    0% { transform: scale(0.96) translateY(8px); opacity: 0; }
    100% { transform: scale(1) translateY(0); opacity: 1; }
}

/* Header */
.qf-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.15rem 1.75rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
}
.qf-header-meta {
    display: flex;
    align-items: center;
    gap: 0.95rem;
    min-width: 0;
}
.qf-header-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: var(--primary-light, rgba(37, 99, 235, 0.1));
    color: var(--primary, #2563eb);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.qf-header-title-box {
    min-width: 0;
}
.qf-header-title {
    font-size: 1.25rem;
    font-weight: 700;
    font-family: var(--font-heading);
    color: var(--text-main, #0f172a);
    margin: 0;
    line-height: 1.3;
}
.qf-header-title strong {
    color: var(--primary, #2563eb);
}
.qf-header-sub {
    font-size: 0.85rem;
    color: var(--text-muted, #64748b);
    margin: 3px 0 0 0;
}
.qf-id-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.65rem;
    border-radius: 6px;
    background: rgba(37, 99, 235, 0.1);
    color: var(--primary, #2563eb);
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    border: 1px solid rgba(37, 99, 235, 0.2);
}
.qf-close-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid var(--border-color, #e2e8f0);
    background: transparent;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.qf-close-btn:hover {
    background: var(--bg-app, #f8fafc);
    color: var(--text-main, #0f172a);
    border-color: var(--text-muted);
}

/* High-Contrast Hero Contact Bar */
.qf-hero-contact-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    padding: 0.95rem 1.75rem;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    flex-wrap: wrap;
}
[data-theme="dark"] .qf-hero-contact-bar {
    background: rgba(30, 41, 59, 0.7);
    border-color: rgba(51, 65, 85, 0.8);
}
.qf-hero-phone-group {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.qf-hero-phone-meta {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}
.qf-hero-badge-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, #64748b);
}
.qf-hero-phone-number {
    font-size: 1.35rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: 0.04em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    line-height: 1.2;
}
[data-theme="dark"] .qf-hero-phone-number {
    color: #f8fafc;
}
.qf-hero-buttons {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.qf-hero-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.48rem 0.95rem;
    border-radius: 8px;
    font-size: 0.86rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}
.qf-hero-btn-copy {
    background: #ffffff;
    border-color: #cbd5e1;
    color: #1e293b;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.qf-hero-btn-copy:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
[data-theme="dark"] .qf-hero-btn-copy {
    background: rgba(51, 65, 85, 0.8);
    border-color: rgba(100, 116, 139, 0.4);
    color: #f1f5f9;
}
.qf-hero-btn-wa {
    background: #10b981;
    color: #ffffff;
    box-shadow: 0 1px 2px rgba(16, 185, 129, 0.2);
}
.qf-hero-btn-wa:hover {
    background: #059669;
    color: #ffffff;
}
.qf-hero-btn-call {
    background: var(--primary, #2563eb);
    color: #ffffff;
    box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
}
.qf-hero-btn-call:hover {
    background: var(--primary-hover, #1d4ed8);
    color: #ffffff;
}
.qf-hero-email-group {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    background: #ffffff;
    padding: 0.45rem 0.95rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
[data-theme="dark"] .qf-hero-email-group {
    background: rgba(30, 41, 59, 0.9);
    border-color: rgba(51, 65, 85, 0.8);
}
.qf-hero-email-meta {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.qf-hero-email-val {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
}
[data-theme="dark"] .qf-hero-email-val {
    color: #f1f5f9;
}

/* Body Form & Grid */
.qf-modal-form {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex: 1;
    min-height: 0;
}
.qf-modal-body {
    overflow-y: auto;
    padding: 1.1rem 1.4rem;
    flex: 1;
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 1.25rem;
    align-items: start;
}
@media (max-width: 860px) {
    .qf-modal-body {
        grid-template-columns: 1fr;
        gap: 1.25rem;
    }
}

/* Section Headings */
.qf-sec-heading {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-muted, #475569);
    margin: 0 0 0.95rem 0;
}
.qf-sec-divider {
    border: none;
    border-top: 1px solid var(--border-color, #e2e8f0);
    margin: 1.35rem 0 1.1rem 0;
}

/* Form Controls */
.qf-form-group {
    margin-bottom: 0.95rem;
}
.qf-label {
    display: block;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-main, #1e293b);
    margin-bottom: 0.45rem;
}
.qf-control {
    width: 100%;
    font-size: 0.93rem;
    font-family: inherit;
    border: 1px solid var(--border-color, #cbd5e1);
    border-radius: 8px;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    padding: 0.58rem 0.85rem;
    line-height: 1.45;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
    box-sizing: border-box;
}
.qf-control:focus {
    border-color: var(--primary, #2563eb);
    box-shadow: 0 0 0 3px var(--primary-light, rgba(37, 99, 235, 0.15));
}
.qf-control[readonly], .qf-control:disabled {
    background: var(--bg-app, #f1f5f9);
    color: var(--text-muted, #64748b);
    cursor: not-allowed;
}
textarea.qf-control {
    resize: vertical;
    line-height: 1.5;
}

/* Employee Multi-Select Chips */
.qf-employee-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    max-height: 135px;
    overflow-y: auto;
    padding: 0.55rem;
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
}
.qf-emp-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.32rem 0.7rem;
    border-radius: 20px;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.84rem;
    font-weight: 500;
    color: var(--text-main, #0f172a);
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease;
}
.qf-emp-pill:hover {
    border-color: var(--primary, #2563eb);
    background: var(--primary-light, rgba(37, 99, 235, 0.05));
}
.qf-emp-pill input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
    width: 0;
    height: 0;
}
.qf-emp-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--border-color, #e2e8f0);
    color: var(--text-muted, #475569);
    font-size: 0.68rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
.qf-emp-check-icon {
    display: none;
    width: 12px;
    height: 12px;
    margin-left: 2px;
}
.qf-emp-pill.active,
.qf-emp-pill:has(input:checked) {
    border-color: var(--primary, #2563eb);
    background: var(--primary-light, rgba(37, 99, 235, 0.1));
    color: var(--primary, #2563eb);
    font-weight: 600;
}
.qf-emp-pill.active .qf-emp-avatar,
.qf-emp-pill:has(input:checked) .qf-emp-avatar {
    background: var(--primary, #2563eb);
    color: #ffffff;
}
.qf-emp-pill.active .qf-emp-check-icon,
.qf-emp-pill:has(input:checked) .qf-emp-check-icon {
    display: inline-block;
}

/* Right Column Schedule Card */
.qf-scheduler-panel {
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 0.95rem 1.15rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.qf-chip-btn {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #cbd5e1);
    border-radius: 5px;
    padding: 2px 7px;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-muted, #475569);
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    line-height: 1.4;
}
.qf-chip-btn:hover {
    border-color: var(--primary, #2563eb);
    color: var(--primary, #2563eb);
    background: var(--primary-light, rgba(37, 99, 235, 0.08));
}
.qf-chip-last {
    background: rgba(37, 99, 235, 0.08);
    border-color: var(--primary, #2563eb);
    color: var(--primary, #2563eb);
    font-weight: 700;
}
.qf-dropdown-item:hover {
    background: var(--primary-light, rgba(37, 99, 235, 0.08));
}
.qf-quick-dates-bar {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
}
.qf-date-shortcut-btn {
    border: 1px solid var(--border-color, #cbd5e1);
    background: var(--bg-card, #ffffff);
    color: var(--text-muted, #475569);
    font-size: 0.76rem;
    font-weight: 600;
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.qf-date-shortcut-btn:hover {
    border-color: var(--primary, #2563eb);
    color: var(--primary, #2563eb);
    background: var(--primary-light, rgba(37, 99, 235, 0.08));
}

/* Timeline Cards */
.qf-timeline-box {
    max-height: 110px;
    overflow-y: auto;
    padding-right: 0.35rem;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}
.qf-timeline-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    padding: 0.45rem 0.65rem;
    transition: border-color 0.15s ease;
}
.qf-timeline-card:hover {
    border-color: var(--primary, #2563eb);
}
.qf-timeline-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.25rem;
    gap: 0.5rem;
}
.qf-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
}
.qf-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.qf-status-pending { background: var(--warning-light, rgba(234, 179, 8, 0.15)); color: var(--warning, #d97706); }
.qf-status-pending .qf-status-dot { background: var(--warning, #d97706); }
.qf-status-completed { background: var(--success-light, rgba(34, 197, 94, 0.15)); color: var(--success, #16a34a); }
.qf-status-completed .qf-status-dot { background: var(--success, #16a34a); }
.qf-status-rescheduled { background: var(--info-light, rgba(6, 182, 212, 0.15)); color: var(--info, #0891b2); }
.qf-status-rescheduled .qf-status-dot { background: var(--info, #0891b2); }
.qf-status-danger { background: var(--danger-light, rgba(239, 68, 68, 0.15)); color: var(--danger, #dc2626); }
.qf-status-danger .qf-status-dot { background: var(--danger, #dc2626); }

.qf-timeline-time {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--text-muted, #64748b);
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.qf-timeline-note {
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--text-main, #0f172a);
    margin: 0.2rem 0;
    word-break: break-word;
    line-height: 1.35;
}
.qf-timeline-meta {
    font-size: 0.74rem;
    font-weight: 500;
    color: var(--text-muted, #64748b);
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

/* Footer */
.qf-modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.95rem 1.75rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
}
.qf-btn-cancel {
    border-radius: 8px;
    border: 1px solid var(--border-color, #cbd5e1);
    background: transparent;
    color: var(--text-main, #0f172a);
    padding: 0.6rem 1.35rem;
    cursor: pointer;
    font-size: 0.92rem;
    font-weight: 500;
    font-family: inherit;
    transition: all 0.15s ease;
}
.qf-btn-cancel:hover {
    background: var(--bg-app, #f1f5f9);
    border-color: var(--text-muted);
}
.qf-btn-submit {
    border-radius: 8px;
    border: none;
    background: var(--primary, #2563eb);
    color: #ffffff;
    padding: 0.6rem 1.65rem;
    cursor: pointer;
    font-size: 0.95rem;
    font-weight: 600;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}
.qf-btn-submit:hover {
    background: var(--primary-hover, #1d4ed8);
}
.qf-btn-submit:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

/* Custom Slim Scrollbars */
.qf-employee-grid::-webkit-scrollbar,
.qf-timeline-box::-webkit-scrollbar,
.qf-modal-body::-webkit-scrollbar {
    width: 5px;
}
.qf-employee-grid::-webkit-scrollbar-thumb,
.qf-timeline-box::-webkit-scrollbar-thumb,
.qf-modal-body::-webkit-scrollbar-thumb {
    background: var(--border-color, #cbd5e1);
    border-radius: 4px;
}
</style>

<!-- Modal: Upload Lead Document -->
<div id="upload-document-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 520px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="file-up" style="width: 20px; height: 20px;"></i>
                </div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700;">
                    Upload Lead Document
                </h3>
            </div>
            <button class="btn-icon" onclick="window.closeModal('upload-document-modal')">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form class="modal-body flex flex-col gap-4" action="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" method="POST" enctype="multipart/form-data" style="padding: 1.75rem;">
            <input type="hidden" name="action" value="upload_lead_document">
            <input type="hidden" name="lead_id" value="<?php echo $lead['id']; ?>">
            
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Document Name / Title <span class="text-danger">*</span></label>
                <input type="text" name="doc_name" class="form-control form-control-focus" placeholder="E.g. GST Certificate, PAN Card, Purchase Order, Agreement" required>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Select File <span class="text-danger">*</span></label>
                <input type="file" name="document_file" class="form-control form-control-focus" style="padding: 0.5rem;" required accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.zip,.rar,.xlsx">
                <span class="text-xs text-muted mt-1 block">Supported formats: PDF, DOC, DOCX, PNG, JPG, XLSX, ZIP (Max: 15MB)</span>
            </div>

            <div class="flex justify-end gap-3 pt-4" style="border-top: 1px solid var(--border-color); margin-top: 0.5rem;">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('upload-document-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" style="padding: 0.65rem 1.5rem;">
                    <i data-lucide="upload-cloud" style="width: 16px; height: 16px;"></i>
                    <span>Upload & Save</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View / Preview Lead Document -->
<div id="view-document-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 860px; width: 90%; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="eye" style="width: 20px; height: 20px;"></i>
                </div>
                <h3 id="view-doc-title" class="m-0 text-truncate" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700; max-width: 480px;">
                    Document Preview
                </h3>
            </div>
            <div class="flex align-center gap-2">
                <a id="view-doc-tab-btn" href="#" target="_blank" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.75rem;">
                    <i data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                    <span>Open New Tab</span>
                </a>
                <a id="view-doc-download-btn" href="#" download class="btn btn-primary text-xs" style="padding: 0.4rem 0.75rem;">
                    <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                    <span>Download</span>
                </a>
                <button class="btn-icon" onclick="window.closeModal('view-document-modal')">
                    <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                </button>
            </div>
        </div>

        <div class="modal-body" style="padding: 1.5rem; text-align: center; min-height: 420px; display: flex; align-items: center; justify-content: center; background-color: var(--bg-app); border-radius: 0 0 16px 16px;">
            <div id="view-doc-content" style="width: 100%;">
                <!-- Dynamic Content (iframe / img / fallback link) -->
            </div>
        </div>
    </div>
</div>

<script>
function viewDocumentModal(filePath, docName, fileType) {
    document.getElementById('view-doc-title').innerText = docName;
    document.getElementById('view-doc-download-btn').href = filePath;
    document.getElementById('view-doc-tab-btn').href = filePath;

    const contentDiv = document.getElementById('view-doc-content');
    const imageExts = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
    const typeLower = (fileType || '').toLowerCase();

    if (imageExts.includes(typeLower)) {
        contentDiv.innerHTML = `<img src="${filePath}" alt="${docName}" style="max-width: 100%; max-height: 68vh; border-radius: 8px; box-shadow: var(--shadow-md); object-fit: contain; margin: 0 auto; border: 1px solid var(--border-color);">`;
    } else if (typeLower === 'pdf') {
        contentDiv.innerHTML = `<iframe src="${filePath}" style="width: 100%; height: 68vh; border: none; border-radius: 8px; background: #fff;"></iframe>`;
    } else {
        contentDiv.innerHTML = `
            <div class="py-8 text-center" style="max-width: 480px; margin: 0 auto;">
                <i data-lucide="file-text" style="width: 48px; height: 48px; color: var(--primary); margin: 0 auto 1rem auto;"></i>
                <h4 class="mb-2" style="font-family: var(--font-heading); color: var(--text-main);">Document Ready for View / Download</h4>
                <p class="text-muted text-sm mb-4">The .${typeLower.toUpperCase()} file type can be opened in a separate tab or saved directly.</p>
                <div class="flex justify-center gap-3">
                    <a href="${filePath}" target="_blank" class="btn btn-secondary text-sm">
                        <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
                        <span>Open in Browser</span>
                    </a>
                    <a href="${filePath}" download class="btn btn-primary text-sm">
                        <i data-lucide="download" style="width: 16px; height: 16px;"></i>
                        <span>Download Document</span>
                    </a>
                </div>
            </div>`;
        if (window.lucide) lucide.createIcons();
    }

    window.openModal('view-document-modal');
}

const templates = {
    custom: {
        subject: "",
        body: ""
    },
    demo_schedule: {
        subject: "Marg ERP Product Demo Scheduled",
        body: "Dear {CLIENT_NAME},\n\nWe are pleased to inform you that your product demonstration session for Marg ERP Pro has been scheduled.\n\nDate: {DATE}\nMode: Online (Google Meet)\n\nWe look forward to demonstrating how our system can help automate billing, stock expiry tracking, and GST returns for {COMPANY_NAME}.\n\nBest regards,\nMarg CRM Team"
    },
    demo_reschedule: {
        subject: "Marg ERP Product Demo Rescheduled",
        body: "Dear {CLIENT_NAME},\n\nYour product demonstration session for Marg ERP has been rescheduled as requested.\n\nNew Date: {DATE}\nMode: Online\n\nIf you have any questions, please feel free to reach out.\n\nBest regards,\nMarg CRM Team"
    },
    payment_reminder: {
        subject: "Urgent: Payment Reminder - Invoice #INV-4509",
        body: "Dear {CLIENT_NAME},\n\nThis is a friendly reminder that the payment for Invoice #INV-4509 is due on {DATE}.\n\nTotal Due: {BUDGET}\n\nPlease process the payment at your earliest convenience to avoid service interruptions.\n\nBest regards,\nAccounts Department\nMarg ERP"
    }
};

function updateEmailTemplate() {
    const selector = document.getElementById('email-template-selector');
    const dateContainer = document.getElementById('email-date-picker-container');
    const dateLabel = document.getElementById('email-date-label');
    const dateInput = document.getElementById('email-custom-date');
    
    const selectedTemplate = selector.value;
    
    // Set appropriate date label or input type
    if (selectedTemplate === 'payment_reminder') {
        dateLabel.innerText = "Payment Due Date";
        dateInput.type = "date";
        dateContainer.style.display = 'block';
    } else if (selectedTemplate === 'demo_schedule' || selectedTemplate === 'demo_reschedule') {
        dateLabel.innerText = "Scheduled Demo Date & Time";
        dateInput.type = "datetime-local";
        dateContainer.style.display = 'block';
    } else {
        dateContainer.style.display = 'none';
    }
    
    applyTemplateFields();
}

function applyTemplateFields() {
    const selector = document.getElementById('email-template-selector');
    const subjectInput = document.getElementById('email-subject-input');
    const bodyInput = document.getElementById('email-body-input');
    const dateInput = document.getElementById('email-custom-date');
    
    const selectedTemplate = selector.value;
    if (!templates[selectedTemplate]) return;
    
    let subject = templates[selectedTemplate].subject;
    let body = templates[selectedTemplate].body;
    
    // Format date value
    let dateVal = "[Choose Date]";
    if (dateInput.value) {
        if (selectedTemplate === 'payment_reminder') {
            dateVal = new Date(dateInput.value).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
        } else {
            dateVal = new Date(dateInput.value).toLocaleString(undefined, { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
    }
    
    // Replace placeholder variables
    const clientName = document.getElementById('client-name-val').value;
    const companyName = document.getElementById('client-company-val').value;
    const budgetVal = document.getElementById('client-budget-val').value;
    
    body = body.replace(/{CLIENT_NAME}/g, clientName)
               .replace(/{COMPANY_NAME}/g, companyName)
               .replace(/{BUDGET}/g, budgetVal)
               .replace(/{DATE}/g, dateVal);
               
    subjectInput.value = subject;
    bodyInput.value = body;
}
</script>

<!-- Modal: Enlarged Scan-to-Call QR Code -->
<div id="call-qr-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 380px; text-align: center;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.1rem;" id="qr-modal-title">Scan to Call Client</h3>
            <button class="btn-icon" onclick="window.closeModal('call-qr-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <div class="modal-body flex flex-col align-center p-6 gap-4">
            <div style="background: #ffffff; padding: 16px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                <img id="qr-modal-img" src="" alt="Enlarged QR Code" style="width: 220px; height: 220px; border-radius: 8px; display: block;">
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-base font-bold" id="qr-modal-phone" style="color: var(--primary);"></span>
                <span class="text-xs text-muted">Point your smartphone camera at this QR code to load the phone number directly into your mobile dial pad.</span>
            </div>
        </div>
        <div class="modal-footer flex justify-between align-center p-4" style="background: var(--border-card);">
            <a id="qr-modal-tel-link" href="#" class="btn btn-primary text-xs flex align-center justify-center gap-2" style="width: 100%;">
                <i data-lucide="phone-call" style="width: 14px; height: 14px;"></i>
                <span>Direct Call Now</span>
            </a>
        </div>
    </div>
</div>

<!-- Modal: In-Panel Quick Add City Dialog (Lead Details) -->
<div id="lead-quick-add-city-modal" class="modal-overlay" style="z-index: 1080;">
    <div class="modal-container" style="max-width: 440px; width: 92%; border-radius: 14px; border: 1px solid var(--border-color); background: var(--bg-card); box-shadow: 0 20px 45px -10px rgba(0,0,0,0.5); overflow: hidden;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, var(--primary) 0%, #1e40af 100%); padding: 0.85rem 1.15rem; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="background: rgba(255,255,255,0.2); padding: 5px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="map-pin" style="width: 16px; height: 16px; color: #fff;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-size: 0.95rem; font-weight: 800; color: #fff;">Add New City</h4>
                    <span style="font-size: 0.68rem; color: rgba(255,255,255,0.85);">Register a custom city for regional directory</span>
                </div>
            </div>
            <button type="button" onclick="closeQuickAddLeadCityModal()" style="background: rgba(255,255,255,0.15); border: none; border-radius: 6px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #fff;">
                <i data-lucide="x" style="width: 15px; height: 15px;"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="padding: 1.1rem 1.15rem; background: var(--bg-app);">
            <div id="lead_quick_city_error_msg" style="display: none; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; padding: 7px 10px; margin-bottom: 12px; font-size: 0.75rem; color: #dc2626; align-items: center; gap: 6px;">
                <i data-lucide="alert-circle" style="width: 14px; height: 14px; flex-shrink: 0;"></i>
                <span id="lead_quick_city_error_text"></span>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">State</label>
                <select id="lead_quick_city_state_select" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 8px;">
                    <option value="">-- Select State --</option>
                    <?php foreach ($indian_states_list as $stName): ?>
                        <option value="<?php echo htmlspecialchars($stName); ?>"><?php echo htmlspecialchars($stName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 4px;">
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; display: block;">City Name *</label>
                <input type="text" id="lead_quick_city_name_input" placeholder="e.g. Mainpuri, Firozabad, Noida" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 8px;" onkeydown="if(event.key==='Enter'){event.preventDefault();submitQuickNewLeadCity();}">
            </div>
        </div>

        <!-- Footer -->
        <div style="padding: 0.75rem 1.15rem; background: var(--bg-card); border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="closeQuickAddLeadCityModal()" class="btn btn-secondary text-xs" style="border-radius: 7px; padding: 0.4rem 0.9rem;">
                Cancel
            </button>
            <button type="button" id="lead_quick_city_save_btn" onclick="submitQuickNewLeadCity()" class="btn btn-primary text-xs font-bold" style="border-radius: 7px; padding: 0.4rem 1.1rem; display: flex; align-items: center; gap: 5px;">
                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                <span>Save &amp; Add City</span>
            </button>
        </div>
    </div>
</div>

<script>
function normalizePhoneForDialing(phone) {
    if (!phone) return '';
    let digits = String(phone).replace(/[^0-9+]/g, '');
    if (!digits) return '';
    if (digits.startsWith('+')) return digits;
    if (digits.length === 10) return '+91' + digits;
    if (digits.length === 12 && digits.startsWith('91')) return '+' + digits;
    if (digits.length === 11 && digits.startsWith('0')) return '+91' + digits.substring(1);
    return '+91' + digits;
}

function openCallQrModal(name, phone, telEncoded) {
    const modal = document.getElementById('call-qr-modal');
    if (!modal) return;
    
    const formattedPhone = normalizePhoneForDialing(phone);
    const telPayload = 'tel:' + formattedPhone;
    const encodedPayload = encodeURIComponent(telPayload);

    document.getElementById('qr-modal-title').textContent = 'Call ' + (name || 'Lead');
    document.getElementById('qr-modal-phone').textContent = formattedPhone || '-';
    document.getElementById('qr-modal-tel-link').href = telPayload;
    
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=4&data=' + encodedPayload;
    const fallbackUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=260x260&chl=' + encodedPayload;
    const qrImg = document.getElementById('qr-modal-img');
    qrImg.onerror = function() {
        this.onerror = null;
        this.src = fallbackUrl;
    };
    qrImg.src = qrUrl;
    
    window.openModal('call-qr-modal');
}

// --------------------------------------------------------------------------
// Lead Details: Live Customer ID (License No.) Lookup & Auto-Fetch Controller
// --------------------------------------------------------------------------
let leadCustIdCheckTimer = null;
let lastCheckedLeadCustId = '';

function onLeadCustomerIdInput(val) {
    const rawVal = (val || '').replace(/[^0-9]/g, '').trim();
    const statusEl = document.getElementById('lead_customer_id_live_status');
    const bannerEl = document.getElementById('lead_customer_id_matched_banner');
    const partyLabel = document.getElementById('lead_matched_party_label');

    if (leadCustIdCheckTimer) clearTimeout(leadCustIdCheckTimer);

    if (rawVal.length < 3) {
        if (statusEl) statusEl.innerHTML = '';
        if (bannerEl) bannerEl.style.display = 'none';
        return;
    }

    if (statusEl) {
        statusEl.innerHTML = '<span style="color:var(--text-muted); font-size:0.68rem;">⏳ Checking...</span>';
    }

    leadCustIdCheckTimer = setTimeout(async () => {
        try {
            const res = await fetch('index.php?page=clients&action=check_customer_id&customer_id=' + encodeURIComponent(rawVal));
            const data = await res.json();

            if (data.exists && data.client) {
                const c = data.client;
                lastCheckedLeadCustId = rawVal;

                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#10b981; font-weight:700;">✓ Existing Client Loaded</span>';
                }

                if (bannerEl) {
                    if (partyLabel) partyLabel.innerText = c.party_name || c.company_using || rawVal;
                    bannerEl.style.display = 'flex';
                    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') lucide.createIcons();
                }

                // Auto-populate all fields from existing client record
                if (c.party_name && document.getElementById('lead_client_party_name')) {
                    document.getElementById('lead_client_party_name').value = c.party_name;
                }
                if (c.company_using && document.getElementById('lead_client_company_using')) {
                    document.getElementById('lead_client_company_using').value = c.company_using;
                }

                // S/W Type & Edition
                if (c.sw_type && document.getElementById('lead_client_sw_type')) {
                    document.getElementById('lead_client_sw_type').value = c.sw_type;
                }
                onLeadSwTypeChange(c.sw_type || 'Marg ERP', c.software_type || '');

                if (c.nature_of_business && document.getElementById('lead_client_nature_of_business')) {
                    document.getElementById('lead_client_nature_of_business').value = c.nature_of_business;
                }
                if (c.software_trade && document.getElementById('lead_client_software_trade')) {
                    document.getElementById('lead_client_software_trade').value = c.software_trade;
                }
                if (c.user_type && document.getElementById('lead_client_user_type')) {
                    document.getElementById('lead_client_user_type').value = c.user_type;
                }
                if (c.no_of_users && document.getElementById('lead_client_no_of_users')) {
                    document.getElementById('lead_client_no_of_users').value = c.no_of_users;
                }
                if (c.version && document.getElementById('lead_client_version')) {
                    document.getElementById('lead_client_version').value = c.version;
                }
                if (c.contact_person && document.getElementById('lead_client_contact_person')) {
                    document.getElementById('lead_client_contact_person').value = c.contact_person;
                }
                if (c.mobile && document.getElementById('lead_client_mobile')) {
                    document.getElementById('lead_client_mobile').value = c.mobile;
                }
                if (c.email && document.getElementById('lead_client_email')) {
                    document.getElementById('lead_client_email').value = c.email;
                }

                // State & City
                if (c.state && document.getElementById('lead_client_state')) {
                    document.getElementById('lead_client_state').value = c.state;
                }
                onLeadClientStateSelect(c.state || 'Uttar Pradesh', c.city || '');

                if (c.area && document.getElementById('lead_client_area')) {
                    document.getElementById('lead_client_area').value = c.area;
                }
                if (c.online_zip_code && document.getElementById('lead_client_online_zip')) {
                    document.getElementById('lead_client_online_zip').value = c.online_zip_code;
                }
                if (c.address && document.getElementById('lead_client_address')) {
                    document.getElementById('lead_client_address').value = c.address;
                }

                // Commercials
                if (c.subpartner_code && document.getElementById('lead_client_subpartner_code')) {
                    document.getElementById('lead_client_subpartner_code').value = c.subpartner_code;
                }
                if (c.subpartner_name && document.getElementById('lead_client_subpartner_name')) {
                    document.getElementById('lead_client_subpartner_name').value = c.subpartner_name;
                }
                if (c.act_on && document.getElementById('lead_client_act_on')) {
                    document.getElementById('lead_client_act_on').value = c.act_on;
                }
                if (c.due_on && document.getElementById('lead_client_due_on')) {
                    document.getElementById('lead_client_due_on').value = c.due_on;
                }
                if (c.days && document.getElementById('lead_client_days')) {
                    document.getElementById('lead_client_days').value = c.days;
                }
                if (c.total_amount !== undefined && document.getElementById('lead_client_total_amount')) {
                    document.getElementById('lead_client_total_amount').value = c.total_amount;
                }
                if (c.party_status && document.getElementById('lead_client_party_status')) {
                    document.getElementById('lead_client_party_status').value = c.party_status;
                }
                if (c.software_hit_date && document.getElementById('lead_client_software_hit_date')) {
                    document.getElementById('lead_client_software_hit_date').value = c.software_hit_date;
                }
                if (c.wallet_id && document.getElementById('lead_client_wallet_id')) {
                    document.getElementById('lead_client_wallet_id').value = c.wallet_id;
                }
                if (c.home_user && document.getElementById('lead_client_home_user')) {
                    document.getElementById('lead_client_home_user').value = c.home_user;
                }
                if (c.transferred_party && document.getElementById('lead_client_transferred_party')) {
                    document.getElementById('lead_client_transferred_party').value = c.transferred_party;
                }
            } else {
                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#6366f1; font-weight:700;">✓ New License No.</span>';
                }
                if (bannerEl) bannerEl.style.display = 'none';
            }
        } catch (e) {
            console.error('Error checking customer ID:', e);
            if (statusEl) statusEl.innerHTML = '';
        }
    }, 280);
}
window.onLeadCustomerIdInput = onLeadCustomerIdInput;

// --------------------------------------------------------------------------
// Lead Details: Master S/W Types & Software Editions Dynamic Cascading Controller
// --------------------------------------------------------------------------
const leadSwTypesMap = <?php echo json_encode($sw_types_map); ?>;

function normalizeLeadSwType(val) {
    if (!val) return '';
    const v = String(val).trim().toLowerCase();
    if (v === 'marg erp' || v === 'marg' || v.includes('erp')) return 'Marg ERP';
    if (v === 'marg cloud' || v.includes('cloud')) return 'Marg Cloud';
    if (v === 'marg books' || v.includes('book')) return 'Marg Books';
    if (v === 'marg hrms' || v.includes('hrms') || v.includes('hr')) return 'Marg HRMS';
    return val.trim();
}

function onLeadSwTypeChange(swTypeVal, selectedEdition = '') {
    const editSelect = document.getElementById('lead_client_software_type');
    if (!editSelect) return;

    const targetEdition = (selectedEdition !== undefined && selectedEdition !== null && selectedEdition !== '') ? String(selectedEdition).trim() : '';
    const normSwType = normalizeLeadSwType(swTypeVal);

    if (!normSwType || !leadSwTypesMap[normSwType]) {
        editSelect.innerHTML = '<option value="">-- Select S/W Type First --</option>';
        return;
    }

    editSelect.innerHTML = '<option value="">-- Select Software Edition (' + normSwType + ') --</option>';

    const editions = leadSwTypesMap[normSwType] || [];
    let isSelectedFound = false;
    editions.forEach(function(ed) {
        const opt = document.createElement('option');
        opt.value = ed;
        opt.textContent = ed;
        if (targetEdition && (ed.toLowerCase() === targetEdition.toLowerCase() || targetEdition.toLowerCase() === ed.toLowerCase().replace(/^(marg\s*erp|marg)\s*/i, '').trim())) {
            opt.selected = true;
            isSelectedFound = true;
        }
        editSelect.appendChild(opt);
    });

    if (targetEdition && !isSelectedFound) {
        const customOpt = document.createElement('option');
        customOpt.value = targetEdition;
        customOpt.textContent = targetEdition;
        customOpt.selected = true;
        editSelect.appendChild(customOpt);
    }
}
window.onLeadSwTypeChange = onLeadSwTypeChange;

// --------------------------------------------------------------------------
// Lead Details: State, City Cascading & Smart Area Autocomplete Controller
// --------------------------------------------------------------------------
const leadIndianStatesMap = <?php echo json_encode($indian_states_map); ?>;
const leadDefaultCityAreasMap = <?php echo json_encode($default_city_areas_map); ?>;
let leadCityAreasCache = [];
let leadLoadedCity = '';

function onLeadClientStateSelect(stateVal, targetCity = '') {
    const citySelect = document.getElementById('lead_client_city');
    if (!citySelect) return;

    citySelect.innerHTML = '<option value="">-- Select City --</option>';
    if (!stateVal) {
        citySelect.innerHTML += '<option value="">(Select a State first)</option>';
        return;
    }

    const rawCities = leadIndianStatesMap[stateVal] || [];
    const cities = rawCities.slice().sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
    cities.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c;
        opt.textContent = c;
        if (targetCity && c.toLowerCase() === targetCity.toLowerCase()) {
            opt.selected = true;
        }
        citySelect.appendChild(opt);
    });

    if (targetCity && !cities.some(c => c.toLowerCase() === targetCity.toLowerCase())) {
        const customOpt = document.createElement('option');
        customOpt.value = targetCity;
        customOpt.textContent = targetCity;
        customOpt.selected = true;
        citySelect.appendChild(customOpt);
    }

    const finalCity = citySelect.value || targetCity || '';
    if (finalCity) {
        fetchLeadCityAreas(finalCity, stateVal);
    }
}
window.onLeadClientStateSelect = onLeadClientStateSelect;

// Quick Add City In-Panel Modal Controller for Lead Details
function openQuickAddLeadCityPrompt() {
    const stateVal = (document.getElementById('lead_client_state')?.value || '').trim();
    const stateSelect = document.getElementById('lead_quick_city_state_select');
    if (stateSelect && stateVal) {
        stateSelect.value = stateVal;
    }
    const input = document.getElementById('lead_quick_city_name_input');
    if (input) input.value = '';
    const errorBox = document.getElementById('lead_quick_city_error_msg');
    if (errorBox) errorBox.style.display = 'none';

    const modal = document.getElementById('lead-quick-add-city-modal');
    if (modal) {
        modal.classList.add('open');
        modal.classList.remove('hidden');
    }
    if (window.lucide) lucide.createIcons();
    setTimeout(() => {
        if (input) input.focus();
    }, 150);
}
window.openQuickAddLeadCityPrompt = openQuickAddLeadCityPrompt;

function closeQuickAddLeadCityModal() {
    const modal = document.getElementById('lead-quick-add-city-modal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.add('hidden');
    }
}
window.closeQuickAddLeadCityModal = closeQuickAddLeadCityModal;

async function submitQuickNewLeadCity() {
    const stateSelect = document.getElementById('lead_quick_city_state_select');
    const input = document.getElementById('lead_quick_city_name_input');
    const errorBox = document.getElementById('lead_quick_city_error_msg');
    const errorText = document.getElementById('lead_quick_city_error_text');
    const saveBtn = document.getElementById('lead_quick_city_save_btn');

    const stateVal = (stateSelect ? stateSelect.value : '').trim();
    const cityName = (input ? input.value : '').trim();

    if (!stateVal) {
        if (errorBox && errorText) {
            errorText.textContent = 'Please select a State before adding a city.';
            errorBox.style.display = 'flex';
        }
        if (stateSelect) stateSelect.focus();
        return;
    }

    if (!cityName) {
        if (errorBox && errorText) {
            errorText.textContent = 'Please enter a valid City name.';
            errorBox.style.display = 'flex';
        }
        if (input) input.focus();
        return;
    }

    if (errorBox) errorBox.style.display = 'none';
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span style="font-size:0.75rem;">Saving...</span>';
    }

    try {
        const result = await quickAddNewLeadCity(cityName, stateVal);
        if (result && result.success) {
            closeQuickAddLeadCityModal();
        } else if (result && result.message) {
            if (errorBox && errorText) {
                errorText.textContent = result.message;
                errorBox.style.display = 'flex';
            }
        }
    } catch (e) {
        console.error('Error in submitQuickNewLeadCity:', e);
        if (errorBox && errorText) {
            errorText.textContent = 'Server communication error while saving city.';
            errorBox.style.display = 'flex';
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="check" style="width: 14px; height: 14px;"></i><span>Save &amp; Add City</span>';
            if (window.lucide) lucide.createIcons();
        }
    }
}
window.submitQuickNewLeadCity = submitQuickNewLeadCity;

async function quickAddNewLeadCity(cityName, stateVal) {
    if (!cityName) return { success: false, message: 'City name is required' };
    cityName = cityName.trim();
    if (!stateVal) {
        stateVal = (document.getElementById('lead_client_state')?.value || '').trim();
    }
    if (!stateVal) {
        return { success: false, message: 'Please select a State first' };
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_custom_city');
        formData.append('state', stateVal);
        formData.append('city', cityName);

        const res = await fetch('index.php?page=clients', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            const addedCity = (data.city || cityName).trim();

            const mainStateEl = document.getElementById('lead_client_state');
            if (mainStateEl && mainStateEl.value !== stateVal) {
                mainStateEl.value = stateVal;
            }

            if (!leadIndianStatesMap[stateVal]) {
                leadIndianStatesMap[stateVal] = [];
            }
            const existsInState = leadIndianStatesMap[stateVal].some(c => c.toLowerCase() === addedCity.toLowerCase());
            if (!existsInState) {
                leadIndianStatesMap[stateVal].push(addedCity);
                leadIndianStatesMap[stateVal].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true }));
            }

            onLeadClientStateSelect(stateVal, addedCity);

            if (typeof onLeadClientCitySelect === 'function') {
                onLeadClientCitySelect(addedCity);
            }

            const areaEl = document.getElementById('lead_client_area');
            if (areaEl) {
                areaEl.focus();
            }
            return { success: true, city: addedCity };
        } else {
            return { success: false, message: data.message || 'Could not add city.' };
        }
    } catch (e) {
        console.error('Error adding new city:', e);
        const citySelect = document.getElementById('lead_client_city');
        if (citySelect) {
            const opt = document.createElement('option');
            opt.value = cityName;
            opt.textContent = cityName;
            opt.selected = true;
            citySelect.appendChild(opt);
        }
        return { success: true, city: cityName };
    }
}
window.quickAddNewLeadCity = quickAddNewLeadCity;

function onLeadClientCitySelect(cityVal) {
    const stateVal = (document.getElementById('lead_client_state')?.value || '').trim();
    fetchLeadCityAreas(cityVal, stateVal);
}
window.onLeadClientCitySelect = onLeadClientCitySelect;

async function fetchLeadCityAreas(cityName, stateName = '') {
    cityName = (cityName || '').trim();
    if (!cityName) {
        leadCityAreasCache = [];
        leadLoadedCity = '';
        return [];
    }

    // 1. Instant cache from leadDefaultCityAreasMap so suggestions appear in 0ms
    if (leadLoadedCity.toLowerCase() !== cityName.toLowerCase() || leadCityAreasCache.length === 0) {
        let matchKey = Object.keys(leadDefaultCityAreasMap).find(k => k.toLowerCase() === cityName.toLowerCase());
        if (matchKey && leadDefaultCityAreasMap[matchKey]) {
            leadCityAreasCache = [...leadDefaultCityAreasMap[matchKey]];
            leadLoadedCity = cityName;
            
            const inputEl = document.getElementById('lead_client_area');
            const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
            if (inputEl && (document.activeElement === inputEl || (dropdown && dropdown.style.display === 'block'))) {
                renderLeadAreaSuggestions(inputEl.value);
            }
        }
    }

    try {
        const spinner = document.getElementById('lead_area_input_icon_spinner');
        if (spinner) spinner.innerHTML = '<span style="font-size:10px; color:var(--primary);">...</span>';

        const res = await fetch(`index.php?page=clients&action=get_city_areas&city=${encodeURIComponent(cityName)}&state=${encodeURIComponent(stateName)}`);
        const data = await res.json();

        if (spinner) spinner.innerHTML = '<i data-lucide="map-pin" style="width:14px; height:14px;"></i>';
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') lucide.createIcons();

        if (data.success && Array.isArray(data.areas) && data.areas.length > 0) {
            leadCityAreasCache = data.areas;
            leadLoadedCity = cityName;

            const inputEl = document.getElementById('lead_client_area');
            const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
            if (inputEl && (document.activeElement === inputEl || (dropdown && dropdown.style.display === 'block'))) {
                renderLeadAreaSuggestions(inputEl.value);
            }
            return data.areas;
        }
    } catch (e) {
        console.error('Error fetching lead city areas:', e);
    }
    return leadCityAreasCache;
}
window.fetchLeadCityAreas = fetchLeadCityAreas;

async function onLeadAreaInputFocus() {
    const cityVal = (document.getElementById('lead_client_city')?.value || '').trim();
    const stateVal = (document.getElementById('lead_client_state')?.value || '').trim();
    const inputVal = (document.getElementById('lead_client_area')?.value || '').trim();

    if (cityVal && (leadLoadedCity.toLowerCase() !== cityVal.toLowerCase() || leadCityAreasCache.length === 0)) {
        await fetchLeadCityAreas(cityVal, stateVal);
    }
    renderLeadAreaSuggestions(inputVal);
}
window.onLeadAreaInputFocus = onLeadAreaInputFocus;

function onLeadAreaInputChange(val) {
    renderLeadAreaSuggestions(val);
}
window.onLeadAreaInputChange = onLeadAreaInputChange;

function renderLeadAreaSuggestions(query) {
    const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
    const cityVal = (document.getElementById('lead_client_city')?.value || '').trim();
    if (!dropdown) return;

    query = (query || '').trim().toLowerCase();

    if (!cityVal) {
        dropdown.innerHTML = `
            <div style="padding: 8px 12px; font-size: 0.75rem; color: var(--text-muted); text-align: center;">
                ⚠️ Please select a <strong>City</strong> first
            </div>
        `;
        dropdown.style.display = 'block';
        return;
    }

    let filtered = leadCityAreasCache.filter(a => a.toLowerCase().includes(query));

    let html = '';
    if (filtered.length > 0) {
        html += `<div style="font-size: 0.68rem; font-weight: 700; color: var(--text-muted); padding: 4px 8px; text-transform: uppercase; letter-spacing: 0.05em;">Areas in ${escapeHtml(cityVal)} (${filtered.length})</div>`;
        filtered.forEach(area => {
            const escaped = escapeHtml(area);
            html += `
                <div class="area-item" onclick="selectLeadArea('${escaped.replace(/'/g, "\\'")}')" style="padding: 6px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: background 0.15s; margin-bottom: 2px;" onmouseover="this.style.background='var(--border-card)'" onmouseout="this.style.background='transparent'">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <i data-lucide="map-pin" style="width: 12px; height: 12px; color: var(--primary);"></i>
                        <span>${escaped}</span>
                    </div>
                    <span style="font-size: 0.65rem; color: var(--text-muted);">Select</span>
                </div>
            `;
        });
    }

    const exactMatch = leadCityAreasCache.some(a => a.toLowerCase() === query);
    if (query && !exactMatch) {
        if (filtered.length === 0) {
            html += `
                <div style="padding: 8px 10px; font-size: 0.75rem; color: var(--text-muted); text-align: center;">
                    No existing area matching "<strong>${escapeHtml(query)}</strong>" in ${escapeHtml(cityVal)}.
                </div>
            `;
        }
        const escQuery = escapeHtml(query);
        html += `
            <div style="padding: 4px; border-top: 1px solid var(--border-color); margin-top: 4px;">
                <button type="button" onclick="quickAddNewLeadArea('${escQuery.replace(/'/g, "\\'")}')" class="btn btn-primary w-full flex align-center justify-center gap-1.5" style="width: 100%; border-radius: 6px; font-size: 0.74rem; font-weight: 700; padding: 6px 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; color: #fff; cursor: pointer;">
                    <i data-lucide="plus-circle" style="width: 13px; height: 13px;"></i>
                    <span>Add "<strong>${escQuery}</strong>" to ${escapeHtml(cityVal)}</span>
                </button>
            </div>
        `;
    } else if (filtered.length === 0 && !query) {
        html += `
            <div style="padding: 10px; font-size: 0.75rem; color: var(--text-muted); text-align: center;">
                No areas saved yet for ${escapeHtml(cityVal)}.
                <div style="margin-top: 6px;">
                    <button type="button" onclick="document.getElementById('lead_client_area').focus();" class="btn btn-sm btn-secondary" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 6px;">
                        + Type to Add Area
                    </button>
                </div>
            </div>
        `;
    }

    dropdown.innerHTML = html;
    dropdown.style.display = 'block';

    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
    }
}
window.renderLeadAreaSuggestions = renderLeadAreaSuggestions;

function selectLeadArea(areaName) {
    const input = document.getElementById('lead_client_area');
    if (input) input.value = areaName;
    const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
    if (dropdown) dropdown.style.display = 'none';
}
window.selectLeadArea = selectLeadArea;

async function quickAddNewLeadArea(areaName) {
    const cityVal = (document.getElementById('lead_client_city')?.value || '').trim();
    const stateVal = (document.getElementById('lead_client_state')?.value || '').trim();
    if (!cityVal) {
        alert('Please select a City first.');
        return;
    }
    if (!areaName) {
        areaName = (document.getElementById('lead_client_area')?.value || '').trim();
    }
    if (!areaName) {
        alert('Please enter an area name to add.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'add_city_area');
        formData.append('city', cityVal);
        formData.append('state', stateVal);
        formData.append('area_name', areaName);

        const res = await fetch('index.php?page=clients', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            if (!leadCityAreasCache.includes(areaName)) {
                leadCityAreasCache.push(areaName);
                leadCityAreasCache.sort();
            }
            selectLeadArea(areaName);
        } else {
            alert(data.message || 'Could not save area.');
        }
    } catch (e) {
        console.error(e);
        if (!leadCityAreasCache.includes(areaName)) {
            leadCityAreasCache.push(areaName);
        }
        selectLeadArea(areaName);
    }
}
window.quickAddNewLeadArea = quickAddNewLeadArea;

function openQuickAddLeadAreaPrompt() {
    const input = document.getElementById('lead_client_area');
    if (input) {
        input.focus();
        renderLeadAreaSuggestions(input.value);
    }
}
window.openQuickAddLeadAreaPrompt = openQuickAddLeadAreaPrompt;

function onLeadAreaInputKeydown(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
        if (dropdown) dropdown.style.display = 'none';
    }
}
window.onLeadAreaInputKeydown = onLeadAreaInputKeydown;

// Global outside click dismisser
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('lead_area_autocomplete_wrapper');
    const dropdown = document.getElementById('lead_area_autocomplete_dropdown');
    if (wrapper && dropdown && !wrapper.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Auto initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    const curSwType = document.getElementById('lead_client_sw_type')?.value;
    const curSoftwareType = '<?php echo addslashes($curSoftwareType ?? ""); ?>';
    if (curSwType) {
        onLeadSwTypeChange(curSwType, curSoftwareType);
    }

    const curCity = document.getElementById('lead_client_city')?.value;
    const curState = document.getElementById('lead_client_state')?.value;
    if (curCity) {
        fetchLeadCityAreas(curCity, curState);
    }
});

// Quick Date Shortcut Helper
window.setQFQuickDate = function(type) {
    const input = document.getElementById('qf-scheduled-at');
    if (!input) return;
    const now = new Date();
    let target = new Date(now);
    
    if (type === 'today') {
        target.setHours(17, 0, 0, 0);
        if (target <= now) {
            target = new Date(now.getTime() + 2 * 60 * 60 * 1000);
        }
    } else if (type === 'tomorrow') {
        target.setDate(target.getDate() + 1);
        target.setHours(11, 0, 0, 0);
    } else if (type === '2days') {
        target.setDate(target.getDate() + 2);
        target.setHours(11, 0, 0, 0);
    } else if (type === 'nextweek') {
        target.setDate(target.getDate() + 7);
        target.setHours(11, 0, 0, 0);
    }
    
    const pad = n => String(n).padStart(2, '0');
    const formatted = `${target.getFullYear()}-${pad(target.getMonth() + 1)}-${pad(target.getDate())}T${pad(target.getHours())}:${pad(target.getMinutes())}`;
    input.value = formatted;
};

// Multi-Select Team Dropdown Controllers
window.toggleQfAssignedDropdown = function(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const menu = document.getElementById('qf-assigned-dropdown-menu');
    const chevron = document.getElementById('qf-assigned-chevron');
    if (!menu) return;
    const isShowing = menu.style.display === 'block';
    menu.style.display = isShowing ? 'none' : 'block';
    if (chevron) {
        chevron.style.transform = isShowing ? 'rotate(0deg)' : 'rotate(180deg)';
    }
};

window.clearQfAssigned = function(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    document.querySelectorAll('.qf-assigned-cb').forEach(cb => {
        cb.checked = false;
    });
    updateQfAssignedBtnText();
};

window.updateQfAssignedBtnText = function() {
    const checked = Array.from(document.querySelectorAll('.qf-assigned-cb:checked')).map(cb => cb.value.trim());
    const btnText = document.getElementById('qf-assigned-btn-text');
    const badge = document.getElementById('qf-assigned-count-badge');
    const count = checked.length;

    if (badge) {
        badge.textContent = `${count} Selected`;
    }

    if (!btnText) return;
    if (count === 0) {
        btnText.textContent = '-- Select Team --';
        btnText.style.color = 'var(--text-muted)';
        btnText.title = 'No member assigned';
    } else if (count === 1) {
        btnText.textContent = checked[0];
        btnText.style.color = 'var(--text-main)';
        btnText.title = checked[0];
    } else if (count === 2) {
        btnText.textContent = `${checked[0]}, ${checked[1]}`;
        btnText.style.color = 'var(--text-main)';
        btnText.title = checked.join(', ');
    } else {
        btnText.textContent = `${checked[0]} (+${count - 1})`;
        btnText.style.color = 'var(--text-main)';
        btnText.title = checked.join(', ');
    }
};

// Quick Telecaller Notes Suggestion helper
window.applyQfNoteSuggestion = function(note) {
    const textarea = document.getElementById('qf-fup-notes');
    if (!textarea || !note) return;
    const current = textarea.value.trim();
    if (!current) {
        textarea.value = note;
    } else if (!current.includes(note)) {
        textarea.value = current + ' | ' + note;
    }
    textarea.focus();
};

// Close assigned dropdown on outside click
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('qf-assigned-dropdown-wrapper');
    const menu = document.getElementById('qf-assigned-dropdown-menu');
    const chevron = document.getElementById('qf-assigned-chevron');
    if (menu && wrapper && !wrapper.contains(e.target)) {
        menu.style.display = 'none';
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
});

// Keep Employee multi-select dropdown in sync with checkbox changes
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('qf-assigned-cb')) {
        updateQfAssignedBtnText();
    }
});

// Quick Follow-up Modal handler for Lead Details
function openLeadDetailsFollowupModal(leadId) {
    if (!leadId) leadId = '<?php echo htmlspecialchars($lead['id'] ?? ''); ?>';
    const quickFollowupModal = document.getElementById('quick-followup-modal');
    
    fetch('index.php?page=leads&action=get_lead_json&id=' + encodeURIComponent(leadId))
    .then(response => response.json())
    .then(res => {
        if (res.success) {
            const lead = res.lead;
            
            if (document.getElementById('qf-lead-id')) document.getElementById('qf-lead-id').value = lead.id || '';
            
            // Hero Phone & Contact Controls
            const rawPhone = (lead.phone || '').trim();
            let digitsOnly = rawPhone.replace(/[^0-9]/g, '');
            let formattedPhone = rawPhone || 'Not Available';
            
            if (digitsOnly.length === 10) {
                formattedPhone = `+91 ${digitsOnly.slice(0, 5)} ${digitsOnly.slice(5)}`;
            } else if (digitsOnly.length === 12 && digitsOnly.startsWith('91')) {
                formattedPhone = `+91 ${digitsOnly.slice(2, 7)} ${digitsOnly.slice(7)}`;
            }

            const heroPhoneVal = document.getElementById('qf-hero-phone-val');
            if (heroPhoneVal) {
                heroPhoneVal.textContent = formattedPhone;
            }

            const copyPhoneBtn = document.getElementById('qf-copy-phone-btn');
            if (copyPhoneBtn) {
                copyPhoneBtn.onclick = function() {
                    window.copyQfText(rawPhone || digitsOnly, this);
                };
                copyPhoneBtn.style.display = rawPhone ? 'inline-flex' : 'none';
            }

            const waLink = document.getElementById('qf-hero-wa-link');
            if (waLink) {
                if (lead.id) {
                    waLink.href = 'index.php?page=lead_details&id=' + encodeURIComponent(lead.id) + '&tab=whatsapp';
                    waLink.title = 'Send Meta Approved WhatsApp Template';
                    waLink.style.display = 'inline-flex';
                    waLink.onclick = function(e) {
                        e.preventDefault();
                        if (typeof closeQuickFollowupModal === 'function') {
                            closeQuickFollowupModal();
                        }
                        if (typeof openLeadWhatsAppTab === 'function') {
                            openLeadWhatsAppTab();
                        } else {
                            window.location.href = waLink.href;
                        }
                    };
                } else {
                    waLink.style.display = 'none';
                }
            }

            const callLink = document.getElementById('qf-hero-call-link');
            if (callLink) {
                if (digitsOnly) {
                    callLink.href = `tel:+${digitsOnly.startsWith('91') ? digitsOnly : '91' + digitsOnly}`;
                    callLink.style.display = 'inline-flex';
                } else {
                    callLink.style.display = 'none';
                }
            }

            // Email
            const rawEmail = (lead.email || '').trim();
            const emailWrap = document.getElementById('qf-hero-email-wrap');
            const emailVal = document.getElementById('qf-hero-email-val');
            const copyEmailBtn = document.getElementById('qf-copy-email-btn');

            if (rawEmail && rawEmail.toLowerCase() !== 'null' && rawEmail.toLowerCase() !== 'na') {
                if (emailWrap) emailWrap.style.display = 'flex';
                if (emailVal) emailVal.textContent = rawEmail;
                if (copyEmailBtn) {
                    copyEmailBtn.onclick = function() {
                        window.copyQfText(rawEmail, this);
                    };
                }
            } else {
                if (emailWrap) emailWrap.style.display = 'none';
            }

            // Lead ID Badge
            const heroLeadBadge = document.getElementById('qf-hero-lead-badge');
            if (heroLeadBadge) {
                if (lead.id) {
                    heroLeadBadge.textContent = String(lead.id).startsWith('LD-') ? lead.id : 'LD-' + lead.id;
                    heroLeadBadge.style.display = 'inline-flex';
                } else {
                    heroLeadBadge.style.display = 'none';
                }
            }

            // Modal Title
            const leadName = (lead.name && lead.name !== 'NA' && lead.name.trim() !== '') ? lead.name : (lead.company && lead.company !== 'NA' ? lead.company : 'Client');
            if (document.getElementById('qf-modal-title')) {
                document.getElementById('qf-modal-title').innerHTML = `Follow-Up: <strong>${leadName}</strong>`;
            }
            if (document.getElementById('qf-group-stage')) document.getElementById('qf-group-stage').value = lead.group_stage || lead.company || 'Fresh';
            if (document.getElementById('qf-status')) document.getElementById('qf-status').value = lead.status || 'new';
            
            const assignedList = (lead.assigned || '').split(',').map(s => s.trim().toLowerCase());
            document.querySelectorAll('.qf-assigned-cb').forEach(cb => {
                const isChecked = assignedList.includes(cb.value.trim().toLowerCase());
                cb.checked = isChecked;
            });
            if (typeof updateQfAssignedBtnText === 'function') {
                updateQfAssignedBtnText();
            }
            
            if (document.getElementById('qf-assigned-by')) document.getElementById('qf-assigned-by').value = lead.assigned_by || 'Not assigned yet';
            if (document.getElementById('qf-tags')) document.getElementById('qf-tags').value = lead.tags || '';
            if (document.getElementById('qf-address')) document.getElementById('qf-address').value = lead.address || '';
            if (document.getElementById('qf-source')) document.getElementById('qf-source').value = lead.source || 'Website';
            if (document.getElementById('qf-enq-for')) document.getElementById('qf-enq-for').value = lead.enq_for || '';
            if (document.getElementById('qf-contact-person')) document.getElementById('qf-contact-person').value = lead.contact_person || '';
            if (document.getElementById('qf-remarks')) document.getElementById('qf-remarks').value = lead.remarks || '';
            
            let pendingFup = null;
            if (res.followup_history && res.followup_history.length > 0) {
                pendingFup = res.followup_history.find(f => f.status === 'pending') || res.followup_history[0];
            }

            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            const defaultDateTime = now.toISOString().slice(0, 16);

            // If existing pending follow-up is strictly in the future, show its future scheduled date;
            // if it was from the past (e.g. earlier days), default to current time so user schedules forward!
            if (pendingFup && pendingFup.scheduled_at && new Date(pendingFup.scheduled_at).getTime() > Date.now()) {
                const fupDateStr = pendingFup.scheduled_at.replace(' ', 'T').slice(0, 16);
                if (document.getElementById('qf-scheduled-at')) {
                    document.getElementById('qf-scheduled-at').value = fupDateStr;
                }
                if (document.getElementById('qf-action-type')) {
                    document.getElementById('qf-action-type').value = pendingFup.action_type || 'Call';
                }
            } else {
                if (document.getElementById('qf-scheduled-at')) {
                    document.getElementById('qf-scheduled-at').value = defaultDateTime;
                }
                if (document.getElementById('qf-action-type')) {
                    document.getElementById('qf-action-type').value = (pendingFup && pendingFup.action_type) ? pendingFup.action_type : 'Call';
                }
            }

            // Keep notes input fresh/empty for new calling entry (telecaller can click "Last Note" chip if they wish)
            if (document.getElementById('qf-fup-notes')) {
                document.getElementById('qf-fup-notes').value = '';
            }

            // Render Smart Suggestion Chip for Last Note if available
            const lastSavedNote = (pendingFup && pendingFup.remarks ? pendingFup.remarks : (localStorage.getItem('marg_last_fup_note') || '')).trim();
            const suggestionsBar = document.getElementById('qf-smart-suggestions-bar');
            if (suggestionsBar) {
                let lastChip = document.getElementById('qf-last-note-chip');
                if (lastSavedNote) {
                    if (!lastChip) {
                        lastChip = document.createElement('button');
                        lastChip.type = 'button';
                        lastChip.id = 'qf-last-note-chip';
                        lastChip.className = 'qf-chip-btn qf-chip-last';
                        suggestionsBar.prepend(lastChip);
                    }
                    const cleanShort = lastSavedNote.length > 25 ? lastSavedNote.slice(0, 25) + '...' : lastSavedNote;
                    lastChip.setAttribute('data-note', lastSavedNote);
                    lastChip.title = `Fill previous note: "${lastSavedNote}"`;
                    lastChip.onclick = function() { window.applyQfNoteSuggestion(this.getAttribute('data-note')); };
                    lastChip.innerHTML = `<i data-lucide="rotate-ccw" style="width:10px;height:10px;"></i> Last: ${typeof escapeHtml === 'function' ? escapeHtml(cleanShort) : cleanShort}`;
                    if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
                } else if (lastChip) {
                    lastChip.remove();
                }
            }
            
            const historyTimeline = document.getElementById('qf-history-timeline');
            const historyCount = document.getElementById('qf-history-count');

            if (res.followup_history && res.followup_history.length > 0) {
                if (historyCount) historyCount.textContent = res.followup_history.length + (res.followup_history.length === 1 ? ' entry' : ' entries');
                let html = '';
                res.followup_history.forEach(fup => {
                    let statusClass = 'pending';
                    const statusVal = (fup.status || 'pending').toLowerCase();
                    if (statusVal === 'completed') statusClass = 'completed';
                    else if (statusVal === 'rescheduled') statusClass = 'rescheduled';
                    else if (statusVal === 'missed' || statusVal === 'cancelled') statusClass = 'danger';

                    const formattedDate = fup.scheduled_at ? new Date(fup.scheduled_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : 'N/A';
                    html += `
                        <div class="qf-timeline-card">
                            <div class="qf-timeline-header">
                                <span class="qf-status-pill qf-status-${statusClass}">
                                    <span class="qf-status-dot"></span>
                                    ${fup.action_type || 'Call'} (${statusVal})
                                </span>
                                <span class="qf-timeline-time">
                                    <i data-lucide="clock" style="width: 11px; height: 11px;"></i>
                                    ${formattedDate}
                                </span>
                            </div>
                            <p class="qf-timeline-note">${fup.remarks || 'No notes added.'}</p>
                            <div class="qf-timeline-meta">
                                <i data-lucide="user" style="width: 10px; height: 10px;"></i>
                                <span>Assigned: ${fup.assigned_to || 'System'}</span>
                            </div>
                        </div>
                    `;
                });
                if (historyTimeline) historyTimeline.innerHTML = html;
            } else {
                if (historyCount) historyCount.textContent = '0 entries';
                if (historyTimeline) historyTimeline.innerHTML = '<p class="text-xs text-muted" style="font-style: italic; font-size: 0.75rem; margin: 0.5rem 0;">No previous follow-up history logged yet for this lead.</p>';
            }

            if (quickFollowupModal) {
                quickFollowupModal.classList.remove('hidden');
                quickFollowupModal.classList.add('open');
                quickFollowupModal.style.setProperty('display', 'flex', 'important');
            }
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        } else {
            alert('Error loading lead data: ' + res.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error loading lead details.');
    });
}
window.openLeadDetailsFollowupModal = openLeadDetailsFollowupModal;

function closeQuickFollowupModal() {
    const quickFollowupModal = document.getElementById('quick-followup-modal');
    if (quickFollowupModal) {
        quickFollowupModal.classList.add('hidden');
        quickFollowupModal.classList.remove('open');
        quickFollowupModal.style.setProperty('display', 'none', 'important');
    }
}
window.closeQuickFollowupModal = closeQuickFollowupModal;

// 1-Click Copy Helper with visual feedback
window.copyQfText = function(text, btn) {
    if (!text) return;
    const cleanText = text.trim();
    const originalHtml = btn.innerHTML;

    function showSuccess() {
        btn.classList.add('qf-copied');
        btn.innerHTML = '<i data-lucide="check" style="width: 14px; height: 14px; color: #16a34a;"></i> <span style="color: #16a34a; font-weight: 700;">Copied!</span>';
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
        setTimeout(() => {
            btn.classList.remove('qf-copied');
            btn.innerHTML = originalHtml;
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        }, 1800);
    }

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(cleanText).then(showSuccess).catch(() => fallbackCopy(cleanText));
    } else {
        fallbackCopy(cleanText);
    }

    function fallbackCopy(val) {
        const ta = document.createElement('textarea');
        ta.value = val;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            showSuccess();
        } catch (err) {
            console.error('Copy fallback failed', err);
        }
        document.body.removeChild(ta);
    }
};

// Close on backdrop click & Escape key in Details page
document.addEventListener('DOMContentLoaded', function() {
    const qfModal = document.getElementById('quick-followup-modal');
    if (qfModal) {
        qfModal.addEventListener('click', function(e) {
            if (e.target === qfModal) {
                closeQuickFollowupModal();
            }
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const m = document.getElementById('quick-followup-modal');
            if (m && !m.classList.contains('hidden') && m.style.display !== 'none') {
                closeQuickFollowupModal();
            }
        }
    });

    // Quick Follow-up Form Submission in Details page with button loading state
    const qfForm = document.getElementById('quick-followup-form');
    if (qfForm) {
        qfForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const noteVal = (document.getElementById('qf-fup-notes')?.value || '').trim();
            if (noteVal) {
                try { localStorage.setItem('marg_last_fup_note', noteVal); } catch(e){}
            }

            const submitBtn = document.getElementById('qf-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span style="display: inline-block; width: 13px; height: 13px; border: 2px solid #ffffff; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span> <span>Saving...</span>';
            }
            const formData = new FormData(this);
            
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server response:', text);
                    throw new Error('Invalid response: ' + text.substring(0, 150));
                }
            })
            .then(data => {
                if (data.success) {
                    closeQuickFollowupModal();
                    window.location.reload();
                } else {
                    alert('Failed to save details: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Save error: ' + err.message);
            })
            .finally(() => {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i data-lucide="check" style="width: 15px; height: 15px;"></i><span>Save & Log Follow-up</span>';
                    if (window.lucide && typeof lucide.createIcons === 'function') {
                        lucide.createIcons();
                    }
                }
            });
        });
    }
});
</script>


<style>
/* ==========================================================================
   WhatsApp Template Studio Modern Enterprise Styling
   ========================================================================== */
.wa-studio-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 1rem;
    margin-bottom: 1.25rem;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 0.75rem;
}
.wa-studio-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-studio-title {
    font-size: 1.1rem;
    font-weight: 700;
    font-family: var(--font-heading);
    color: var(--text-main);
    margin: 0;
    line-height: 1.2;
}
.wa-studio-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin: 2px 0 0 0;
}
.wa-pill-verified {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}
.wa-mode-toggle {
    display: inline-flex;
    padding: 3px;
    background: var(--bg-app);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}
.wa-mode-btn {
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.wa-mode-btn.active {
    background: var(--bg-card);
    color: var(--text-main);
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}

/* 3-Column Studio Grid */
.wa-studio-grid {
    display: grid;
    grid-template-columns: 290px minmax(320px, 1fr) 330px;
    gap: 1.25rem;
    align-items: start;
}
@media (max-width: 1280px) {
    .wa-studio-grid {
        grid-template-columns: 280px 1fr;
    }
    .wa-col-device {
        grid-column: span 2;
    }
}
@media (max-width: 820px) {
    .wa-studio-grid {
        grid-template-columns: 1fr;
    }
    .wa-col-device {
        grid-column: auto;
    }
}

/* Panel Headings */
.wa-panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.6rem;
}
.wa-panel-title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
}
.wa-panel-meta {
    font-size: 0.68rem;
    color: var(--text-muted);
}
.wa-tpl-badge-slug {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--primary);
    background: var(--primary-light);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
}
.wa-live-badge {
    font-size: 0.68rem;
    font-weight: 600;
    color: #059669;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.wa-live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #10b981;
    animation: waPulse 1.5s infinite;
}
@keyframes waPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(0.85); }
}

/* Column 1: Template Explorer */
.wa-search-box {
    position: relative;
    margin-bottom: 0.5rem;
}
.wa-search-box input {
    width: 100%;
    height: 32px;
    font-size: 0.75rem;
    padding-left: 28px;
    padding-right: 8px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-main);
    box-sizing: border-box;
    outline: none;
}
.wa-search-box input:focus {
    border-color: #10b981;
}
.wa-search-box i {
    position: absolute;
    left: 8px;
    top: 9px;
    pointer-events: none;
}
.wa-filter-chips {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 0.65rem;
    flex-wrap: wrap;
}
.wa-chip {
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    font-size: 0.68rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.wa-chip:hover {
    border-color: #10b981;
    color: #10b981;
}
.wa-chip.active {
    background: #10b981;
    color: #ffffff;
    border-color: #10b981;
}

.wa-templates-scroll {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    max-height: 440px;
    overflow-y: auto;
    padding-right: 2px;
}
.wa-templates-scroll::-webkit-scrollbar {
    width: 4px;
}
.wa-templates-scroll::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 4px;
}

.wa-tpl-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.65rem 0.75rem;
    cursor: pointer;
    transition: all 0.15s ease;
    border-left: 3px solid transparent;
}
.wa-tpl-card:hover {
    border-color: var(--border-color-hover, #94a3b8);
    background: var(--bg-app);
}
.wa-tpl-card.active {
    border-color: #10b981;
    border-left-color: #10b981;
    background: rgba(16, 185, 129, 0.04);
}
.wa-tpl-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.25rem;
}
.wa-tag {
    font-size: 0.6rem;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 3px;
    letter-spacing: 0.03em;
}
.wa-tag-marketing { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
.wa-tag-utility { background: rgba(16, 185, 129, 0.12); color: #059669; }
.wa-tag-service { background: rgba(139, 92, 246, 0.12); color: #7c3aed; }
.wa-tpl-type {
    font-size: 0.62rem;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.wa-tpl-card-title {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 2px;
    line-height: 1.25;
}
.wa-tpl-card-snippet {
    font-size: 0.7rem;
    color: var(--text-muted);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.wa-tpl-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.35rem;
    padding-top: 0.35rem;
    border-top: 1px dashed var(--border-color);
    font-size: 0.62rem;
}
.wa-tpl-card-footer code {
    font-size: 0.62rem;
    color: var(--text-muted);
}
.wa-verified-pill {
    color: #059669;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

/* Column 2: Parameters Form */
.wa-form-wrapper {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.wa-form-section {
    display: flex;
    flex-direction: column;
}
.wa-input-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-main);
}
.wa-input-hint {
    font-size: 0.68rem;
    color: var(--text-muted);
}
.wa-input-prefix-box {
    display: flex;
    align-items: center;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-card);
    overflow: hidden;
}
.wa-prefix-tag {
    background: var(--bg-app);
    padding: 0 0.55rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    height: 32px;
    display: flex;
    align-items: center;
    border-right: 1px solid var(--border-color);
}
.wa-control-input {
    width: 100%;
    height: 32px;
    font-size: 0.78rem;
    padding: 0 0.55rem;
    border: none;
    background: transparent;
    color: var(--text-main);
    outline: none;
    box-sizing: border-box;
}
.wa-form-section .wa-control-input:not(.wa-input-prefix-box *) {
    border: 1px solid var(--border-color);
    border-radius: 6px;
}
.wa-control-input:focus {
    border-color: #10b981;
}

/* Compact Variable List */
.wa-vars-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    max-height: 250px;
    overflow-y: auto;
    padding-right: 2px;
}
.wa-var-item {
    background: var(--bg-app);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.4rem 0.55rem;
}
.wa-var-item-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.25rem;
}
.wa-var-token {
    font-size: 0.68rem;
    font-weight: 700;
    color: #059669;
    font-family: monospace;
}
.wa-var-pills {
    display: flex;
    gap: 3px;
}
.wa-pill-fill {
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    font-size: 0.62rem;
    font-weight: 500;
    padding: 1px 5px;
    border-radius: 3px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.wa-pill-fill:hover {
    border-color: #10b981;
    color: #059669;
    background: rgba(16, 185, 129, 0.08);
}
.wa-var-field {
    width: 100%;
    height: 28px;
    font-size: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--bg-card);
    color: var(--text-main);
    padding: 0 0.45rem;
    outline: none;
    box-sizing: border-box;
}
.wa-var-field:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.15);
}

.wa-btn-send {
    width: 100%;
    height: 38px;
    background: linear-gradient(135deg, #10b981, #059669);
    border: none;
    border-radius: 8px;
    color: #ffffff;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    transition: all 0.15s ease;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.25);
}
.wa-btn-send:hover {
    background: linear-gradient(135deg, #059669, #047857);
}
.wa-btn-send:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

/* Column 3: Device Mockup */
.wa-device-frame {
    background: #efeae2;
    background-image: radial-gradient(#dfd7cb 1.1px, transparent 1.1px);
    background-size: 14px 14px;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
    min-height: 380px;
}
.wa-phone-top-bar {
    background: #008069;
    color: #ffffff;
    padding: 0.55rem 0.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.wa-phone-chat-info {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}
.wa-phone-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wa-phone-name {
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.1;
}
.wa-phone-status {
    font-size: 0.62rem;
    opacity: 0.85;
}
.wa-phone-actions {
    display: flex;
    gap: 0.55rem;
    opacity: 0.9;
}
.wa-phone-chat-body {
    padding: 0.85rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.wa-phone-date-pill {
    align-self: center;
    background: rgba(255, 255, 255, 0.8);
    color: #54656f;
    font-size: 0.6rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.06);
    margin-bottom: 0.65rem;
}
.wa-chat-bubble {
    align-self: flex-start;
    background: #ffffff;
    border-radius: 8px 8px 8px 2px;
    padding: 0.65rem 0.75rem;
    max-width: 95%;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12);
    position: relative;
    font-size: 0.78rem;
    line-height: 1.4;
    color: #111b21;
}
.wa-bubble-media {
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 0.4rem;
}
.wa-bubble-doc-card {
    background: #f0f2f5;
    padding: 0.45rem 0.6rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.wa-doc-icon-box {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    background: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wa-doc-name {
    font-size: 0.72rem;
    font-weight: 600;
}
.wa-doc-size {
    font-size: 0.62rem;
    color: #64748b;
}
.wa-bubble-text {
    white-space: pre-wrap;
    word-break: break-word;
}
.wa-val-pill {
    background: rgba(16, 185, 129, 0.22);
    color: #065f46;
    font-weight: 700;
    padding: 0 3px;
    border-radius: 3px;
}
.wa-bubble-footer {
    font-size: 0.68rem;
    color: #667781;
    margin-top: 0.35rem;
    font-style: italic;
}
.wa-bubble-meta-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    margin-top: 0.25rem;
}
.wa-bubble-time {
    font-size: 0.6rem;
    color: #8696a0;
}
.wa-bubble-action-btns {
    margin-top: 0.45rem;
    border-top: 1px solid #f0f2f5;
    padding-top: 0.35rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.wa-bubble-btn {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #00a884;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.3rem 0.5rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.3rem;
}

/* Toast Alert */
.wa-toast {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 9999;
    background: #ffffff;
    border: 1px solid var(--border-color);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    border-radius: 8px;
    padding: 0.75rem 1.15rem;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    animation: waToastSlide 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    max-width: 420px;
}
@keyframes waToastSlide {
    0% { transform: translateY(-12px); opacity: 0; }
    100% { transform: translateY(0); opacity: 1; }
}
.wa-toast.hidden { display: none; }
.wa-toast-success { border-left: 4px solid #10b981; }
.wa-toast-error { border-left: 4px solid #ef4444; }

/* Dark Mode Overrides */
[data-theme="dark"] .wa-device-frame {
    background: #0b141a;
    background-image: radial-gradient(#1f2c34 1.1px, transparent 1.1px);
}
[data-theme="dark"] .wa-chat-bubble {
    background: #202c33;
    color: #e9edef;
}
[data-theme="dark"] .wa-val-pill {
    background: rgba(16, 185, 129, 0.28);
    color: #34d399;
}
[data-theme="dark"] .wa-bubble-footer {
    color: #8696a0;
}
[data-theme="dark"] .wa-phone-date-pill {
    background: #182229;
    color: #8696a0;
}
[data-theme="dark"] .wa-bubble-action-btns {
    border-top-color: #2a3942;
}
[data-theme="dark"] .wa-bubble-btn {
    background: #202c33;
    border-color: #2a3942;
    color: #25d366;
}
[data-theme="dark"] .wa-toast {
    background: #1e293b;
    border-color: #334155;
    color: #f1f5f9;
}
</style>

<script>
// Open WhatsApp Tab dynamically from top action button
function openLeadWhatsAppTab() {
    const waTabBtn = document.querySelector('.tab-link[data-tab="tab-whatsapp"]');
    if (waTabBtn) {
        waTabBtn.click();
        waTabBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Automatically activate WhatsApp Tab on DOM load if requested via URL param or hash
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const reqTab = params.get('tab') || params.get('active_tab') || (window.location.hash ? window.location.hash.replace('#', '') : '');
    if (reqTab === 'whatsapp') {
        setTimeout(function() {
            openLeadWhatsAppTab();
        }, 80);
    }
});

// Switch between Composer and History sub-views
function switchWAMode(mode) {
    const composeView = document.getElementById('wa-view-compose');
    const historyView = document.getElementById('wa-view-history');
    const btnCompose = document.getElementById('wa-btn-mode-compose');
    const btnHistory = document.getElementById('wa-btn-mode-history');

    if (mode === 'history') {
        if (composeView) composeView.style.display = 'none';
        if (historyView) historyView.style.display = 'block';
        if (btnCompose) btnCompose.classList.remove('active');
        if (btnHistory) btnHistory.classList.add('active');
    } else {
        if (composeView) composeView.style.display = 'grid';
        if (historyView) historyView.style.display = 'none';
        if (btnCompose) btnCompose.classList.add('active');
        if (btnHistory) btnHistory.classList.remove('active');
    }
}

// Active WhatsApp Template State
let currentSelectedWATpl = null;

const leadContextDefaults = {
    client_name: <?php echo json_encode($lead['name'] ?: ($lead['company'] ?: 'Client')); ?>,
    company: <?php echo json_encode($lead['company'] ?: 'Marg Soft'); ?>,
    product: <?php echo json_encode($lead['enq_for'] ?: ($lead['products'] ?: 'Marg ERP')); ?>,
    phone: <?php echo json_encode($lead['phone'] ?: ''); ?>,
    agent_name: <?php echo json_encode($lead['assigned_to'] ?: ($_SESSION['user_name'] ?? 'Support Team')); ?>
};

function selectWATemplateCard(cardEl) {
    if (!cardEl) return;
    document.querySelectorAll('.wa-tpl-card').forEach(c => c.classList.remove('active'));
    cardEl.classList.add('active');

    currentSelectedWATpl = {
        id: cardEl.getAttribute('data-id'),
        slug: cardEl.getAttribute('data-slug'),
        title: cardEl.getAttribute('data-title'),
        category: cardEl.getAttribute('data-category'),
        header_type: cardEl.getAttribute('data-header-type'),
        header_content: cardEl.getAttribute('data-header-content'),
        body_text: cardEl.getAttribute('data-body'),
        footer_text: cardEl.getAttribute('data-footer'),
        buttons_json: cardEl.getAttribute('data-buttons')
    };

    renderWATemplateComposer(currentSelectedWATpl);
}

function renderWATemplateComposer(tpl) {
    if (!tpl) return;

    // Set hidden slug & title badge
    const slugInput = document.getElementById('wa-form-template-slug');
    if (slugInput) slugInput.value = tpl.slug;

    const titleBadge = document.getElementById('wa-selected-template-badge');
    if (titleBadge) titleBadge.textContent = tpl.slug;

    // Parse variables from body text {{...}}
    const varMatches = tpl.body_text.match(/\{\{([a-zA-Z0-9_]+)\}\}/g) || [];
    const varKeys = [...new Set(varMatches.map(m => m.replace(/[\{\}]/g, '')))];

    const varWrapper = document.getElementById('wa-variables-wrapper');
    const varFields = document.getElementById('wa-variables-fields');

    if (varKeys.length > 0) {
        varWrapper.style.display = 'block';
        let fieldsHtml = '';
        varKeys.forEach((key, idx) => {
            let defVal = '';
            let friendlyLabel = `Field ${key}`;
            if (key === '1' || key.toLowerCase().includes('name') || key.toLowerCase().includes('client')) {
                defVal = leadContextDefaults.client_name;
                friendlyLabel = 'Customer Name';
            } else if (key === '2' || key.toLowerCase().includes('product') || key.toLowerCase().includes('software')) {
                defVal = leadContextDefaults.product;
                friendlyLabel = 'Product / Enquiry';
            } else if (key === '3' || key.toLowerCase().includes('agent')) {
                defVal = leadContextDefaults.agent_name;
                friendlyLabel = 'Support Executive';
            } else if (key.toLowerCase().includes('company')) {
                defVal = leadContextDefaults.company;
                friendlyLabel = 'Company Name';
            } else if (key.toLowerCase().includes('phone')) {
                defVal = leadContextDefaults.phone;
                friendlyLabel = 'Mobile Number';
            } else {
                defVal = leadContextDefaults.client_name;
            }

            fieldsHtml += `
                <div class="wa-var-item">
                    <div class="wa-var-item-header">
                        <span class="wa-var-token">{{${key}}} • ${friendlyLabel}</span>
                        <div class="wa-var-pills">
                            <button type="button" class="wa-pill-fill" onclick="insertWAVar('${key}', leadContextDefaults.client_name)">Client</button>
                            <button type="button" class="wa-pill-fill" onclick="insertWAVar('${key}', leadContextDefaults.product)">Product</button>
                            <button type="button" class="wa-pill-fill" onclick="insertWAVar('${key}', leadContextDefaults.agent_name)">Agent</button>
                        </div>
                    </div>
                    <input type="text" name="vars[${key}]" id="wa-var-input-${key}" data-var-key="${key}" class="wa-var-field wa-var-input" value="${escapeHtml(defVal)}" oninput="updateWAPreview()">
                </div>
            `;
        });
        varFields.innerHTML = fieldsHtml;
    } else {
        varWrapper.style.display = 'none';
        varFields.innerHTML = '';
    }

    // Media header support
    const mediaWrapper = document.getElementById('wa-media-wrapper');
    const mediaUrlInput = document.getElementById('wa-form-media-url');
    const mediaLabel = document.getElementById('wa-media-label');

    if (tpl.header_type === 'image' || tpl.header_type === 'document' || tpl.header_type === 'video') {
        mediaWrapper.style.display = 'block';
        if (mediaLabel) mediaLabel.textContent = 'Header ' + tpl.header_type.toUpperCase() + ' Attachment';
        if (mediaUrlInput) {
            mediaUrlInput.value = tpl.header_content || '';
            mediaUrlInput.placeholder = tpl.header_type === 'image' ? 'https://.../banner.png' : 'https://.../doc.pdf';
        }
    } else {
        mediaWrapper.style.display = 'none';
        if (mediaUrlInput) mediaUrlInput.value = '';
    }

    updateWAPreview();
}

function insertWAVar(key, value) {
    const input = document.getElementById(`wa-var-input-${key}`);
    if (input) {
        input.value = value;
        updateWAPreview();
    }
}

// Convert WhatsApp Markdown (*bold*, _italic_, etc.) into clean HTML for realistic preview
function formatWhatsAppMarkdown(text) {
    if (!text) return '';
    let res = escapeHtml(text);
    // Bold: *text* -> <strong>text</strong>
    res = res.replace(/\*([^\*]+)\*/g, '<strong>$1</strong>');
    // Italic: _text_ -> <em>$1</em>
    res = res.replace(/_([^_]+)_/g, '<em>$1</em>');
    // Strikethrough: ~text~ -> <del>$1</del>
    res = res.replace(/~([^~]+)~/g, '<del>$1</del>');
    // Monospace: ```text``` -> <code>text</code>
    res = res.replace(/```([^`]+)```/g, '<code style="background: rgba(0,0,0,0.06); padding: 1px 3px; border-radius: 3px;">$1</code>');
    // Newline to <br>
    res = res.replace(/\n/g, '<br>');
    return res;
}

function updateWAPreview() {
    if (!currentSelectedWATpl) return;

    let body = currentSelectedWATpl.body_text || '';

    // Replace variables with unique placeholders first to preserve formatting
    const varInputs = document.querySelectorAll('.wa-var-input');
    const replacements = [];
    varInputs.forEach((input, index) => {
        const key = input.getAttribute('data-var-key');
        const val = input.value.trim() || `{{${key}}}`;
        const placeholder = `___VAR_TOKEN_${index}___`;
        const regex = new RegExp(`\{\{${key}\}\}`, 'g');
        body = body.replace(regex, placeholder);
        replacements.push({ token: placeholder, value: val });
    });

    // Format Markdown on the body text
    body = formatWhatsAppMarkdown(body);

    // Reinsert variables with styled highlight pills
    replacements.forEach(item => {
        body = body.split(item.token).join(`<span class="wa-val-pill">${escapeHtml(item.value)}</span>`);
    });

    const bodyPreview = document.getElementById('wa-preview-body');
    if (bodyPreview) bodyPreview.innerHTML = body;

    // Header preview
    const previewHeader = document.getElementById('wa-preview-header');
    const previewImg = document.getElementById('wa-preview-header-img');
    const previewDoc = document.getElementById('wa-preview-header-doc');
    const mediaUrlInput = document.getElementById('wa-form-media-url');
    const chosenMedia = mediaUrlInput ? mediaUrlInput.value.trim() : '';

    if (currentSelectedWATpl.header_type === 'image') {
        previewHeader.style.display = 'block';
        previewImg.style.display = 'block';
        previewDoc.style.display = 'none';
        previewImg.src = chosenMedia || 'https://friendlyaisolution.com/assets/image.png';
    } else if (currentSelectedWATpl.header_type === 'document') {
        previewHeader.style.display = 'block';
        previewImg.style.display = 'none';
        previewDoc.style.display = 'block';
    } else {
        previewHeader.style.display = 'none';
    }

    // Footer preview
    const footerPreview = document.getElementById('wa-preview-footer');
    if (footerPreview) {
        if (currentSelectedWATpl.footer_text) {
            footerPreview.style.display = 'block';
            footerPreview.textContent = currentSelectedWATpl.footer_text;
        } else {
            footerPreview.style.display = 'none';
        }
    }

    // Buttons preview
    const buttonsPreview = document.getElementById('wa-preview-buttons');
    if (buttonsPreview) {
        let btns = [];
        try {
            if (currentSelectedWATpl.buttons_json) {
                btns = JSON.parse(currentSelectedWATpl.buttons_json);
            }
        } catch (e) {}

        if (Array.isArray(btns) && btns.length > 0) {
            buttonsPreview.style.display = 'flex';
            let btnHtml = '';
            btns.forEach(b => {
                const bText = b.text || b.title || 'Action';
                btnHtml += `<div class="wa-bubble-btn"><i data-lucide="external-link" style="width: 11px; height: 11px;"></i> ${escapeHtml(bText)}</div>`;
            });
            buttonsPreview.innerHTML = btnHtml;
            if (window.lucide && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        } else {
            buttonsPreview.style.display = 'none';
            buttonsPreview.innerHTML = '';
        }
    }
}

// Live Search & Category Filtering
function filterWATemplates() {
    const query = (document.getElementById('wa-template-search')?.value || '').toLowerCase().trim();
    const activeChip = document.querySelector('.wa-chip.active');
    const cat = activeChip ? activeChip.textContent.trim().toUpperCase() : 'ALL';

    const cards = document.querySelectorAll('.wa-tpl-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const title = (card.getAttribute('data-title') || '').toLowerCase();
        const slug = (card.getAttribute('data-slug') || '').toLowerCase();
        const body = (card.getAttribute('data-body') || '').toLowerCase();
        const cardCat = (card.getAttribute('data-category') || '').toUpperCase();

        const matchQuery = !query || title.includes(query) || slug.includes(query) || body.includes(query);
        const matchCat = (cat === 'ALL') || cardCat === cat;

        if (matchQuery && matchCat) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const countBadge = document.getElementById('wa-template-count');
    if (countBadge) countBadge.textContent = visibleCount + ' ready';
}

function setWACategoryFilter(cat, btn) {
    document.querySelectorAll('.wa-chip').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterWATemplates();
}

function showWAToast(type, message, ref) {
    const toast = document.getElementById('wa-toast-alert');
    if (!toast) return;

    toast.className = `wa-toast wa-toast-${type}`;
    const icon = type === 'success' ? 'check-circle' : 'alert-circle';
    const color = type === 'success' ? '#059669' : '#dc2626';

    toast.innerHTML = `
        <i data-lucide="${icon}" style="width: 18px; height: 18px; color: ${color}; flex-shrink: 0;"></i>
        <div style="flex: 1; font-size: 0.78rem;">
            <div style="font-weight: 600;">${escapeHtml(message)}</div>
            ${ref ? `<div style="font-size: 0.65rem; color: var(--text-muted); font-family: monospace;">Ref: ${escapeHtml(ref)}</div>` : ''}
        </div>
    `;
    toast.classList.remove('hidden');

    if (window.lucide && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
    }

    setTimeout(() => {
        toast.classList.add('hidden');
    }, 5500);
}

// Submit Dispatch AJAX
function submitWALeadTemplate(e) {
    e.preventDefault();
    const form = document.getElementById('wa-lead-dispatch-form');
    const submitBtn = document.getElementById('wa-submit-dispatch-btn');

    if (!form) return;
    const formData = new FormData(form);

    if (!formData.get('template_slug')) {
        showWAToast('error', 'Please choose an approved template from the left list.');
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span style="display: inline-block; width: 13px; height: 13px; border: 2px solid #ffffff; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 6px;"></span> Dispatching via Meta...';
    }

    fetch('index.php?page=lead_details&id=<?php echo urlencode($lead['id']); ?>', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (err) {
            throw new Error('Server returned invalid response: ' + text.substring(0, 150));
        }
    })
    .then(data => {
        if (data.success) {
            showWAToast('success', data.message, data.wamid);

            // Append to Sent History Table
            const tbody = document.getElementById('wa-history-tbody');
            const noLogsRow = document.getElementById('wa-no-logs-row');
            if (noLogsRow) noLogsRow.remove();

            if (tbody) {
                const now = new Date();
                const pad = n => String(n).padStart(2, '0');
                const timeStr = `${pad(now.getDate())}-${now.toLocaleString('default', { month: 'short' })}-${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
                const tplTitle = currentSelectedWATpl ? currentSelectedWATpl.title : formData.get('template_slug');

                const newRow = `
                    <tr style="background: rgba(16, 185, 129, 0.05);">
                        <td class="font-semibold text-muted">${timeStr}</td>
                        <td class="font-semibold">+${escapeHtml(formData.get('recipient'))}</td>
                        <td style="word-break: break-word; font-weight: 500;">Template: ${escapeHtml(tplTitle)}</td>
                        <td>
                            <span class="badge text-xs font-semibold" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-size: 0.68rem; padding: 2px 7px; display: inline-flex; align-items: center; gap: 4px;">
                                <i data-lucide="check-check" style="width: 11px; height: 11px;"></i> Sent
                            </span>
                        </td>
                        <td class="text-muted" style="font-family: monospace; font-size: 0.68rem;">${escapeHtml((data.wamid || '').substring(0, 22))}...</td>
                    </tr>
                `;
                tbody.insertAdjacentHTML('afterbegin', newRow);

                // Update badge count
                const countBadge = document.getElementById('wa-history-badge-count');
                if (countBadge) {
                    countBadge.textContent = parseInt(countBadge.textContent || '0') + 1;
                }
            }
        } else {
            showWAToast('error', 'Failed: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        showWAToast('error', 'Network error: ' + err.message);
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i data-lucide="send" style="width: 15px; height: 15px;"></i><span>Dispatch via WhatsApp</span>';
            if (window.lucide && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        }
    });
}

// Auto-select first template on page load
document.addEventListener('DOMContentLoaded', function() {
    const firstCard = document.querySelector('.wa-tpl-card');
    if (firstCard) {
        selectWATemplateCard(firstCard);
    }
});
</script>