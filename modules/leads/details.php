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
    $subtitle = "Custom client service communication from Marg Soft Solutions.";
    $cta_text = "Visit Portal";
    $cta_url = "https://margsoft.com";
    
    if (strpos($subject, 'Demo') !== false) {
        $subtitle = "Confirming your upcoming product demonstration session.";
        $cta_text = "Join Live Session";
        $cta_url = "https://meet.google.com";
    } elseif (strpos($subject, 'Payment') !== false || strpos($subject, 'Invoice') !== false) {
        $subtitle = "Important invoice settlement notice.";
        $cta_text = "Review Invoice & Pay";
        $cta_url = "http://localhost/marglead/index.php?page=payments";
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
        $stmt = $pdo->query("SELECT name FROM users WHERE status = 'Active' ORDER BY name ASC");
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
            <button class="btn btn-primary text-sm" onclick="window.openModal('schedule-followup-modal');">
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
                            <span class="text-sm font-semibold"><?php echo htmlspecialchars(($lead['city'] ?? '') . ', ' . ($lead['state'] ?? '')); ?></span>
                        </div>
                    </div>
                </div>
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
                <div class="flex align-center gap-3">
                    <div style="width: 38px; height: 38px; border-radius: var(--border-radius-full); background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                        <?php echo strtoupper(substr($lead['assigned_to'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-sm font-semibold"><?php echo htmlspecialchars($lead['assigned_to'] ?? 'Unassigned'); ?></span>
                        <span class="text-xs text-muted">Account Representative</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: Tab Panels Workspace -->
        <div class="card p-6" style="border: 1px solid var(--border-color); background-color: var(--bg-card); min-height: 520px;">
            <div class="tab-container">
                <!-- Horizontally scrolling Tab navigation links -->
                <div class="tab-nav flex gap-2" style="border-bottom: 2px solid var(--border-color); margin-bottom: 2rem; overflow-x: auto; padding-bottom: 0.5rem;">
                    <button class="tab-link active" data-tab="tab-overview">Overview</button>
                    <button class="tab-link" data-tab="tab-timeline">Timeline</button>
                    <button class="tab-link" data-tab="tab-followups">Follow-ups</button>
                    <button class="tab-link" data-tab="tab-emails">Emails</button>
                    <button class="tab-link" data-tab="tab-quotations">Quotations</button>
                    <button class="tab-link" data-tab="tab-payments">Payments</button>
                    <button class="tab-link" data-tab="tab-installation">Installation</button>
                    <button class="tab-link" data-tab="tab-training">Training</button>
                    <button class="tab-link" data-tab="tab-support">Support</button>
                    <button class="tab-link" data-tab="tab-documents">Documents</button>
                </div>

                <!-- Tab Panels Contents -->

                <!-- TAB 1: Overview -->
                <div class="tab-pane active" id="tab-overview">
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
                        <button class="btn btn-primary text-xs" onclick="window.openModal('schedule-followup-modal')">Add Follow-up</button>
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

<!-- Modal: Schedule Follow-up -->
<div id="schedule-followup-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Schedule Follow-up reminder</h3>
            <button class="btn-icon" onclick="window.closeModal('schedule-followup-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?action=schedule_followup" method="POST">
            <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars($lead['id']); ?>">
            <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars($lead['assigned_to'] ?? ''); ?>">
            <div class="form-group m-0">
                <label class="form-label text-xs">Date & Time</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="2026-07-22T15:00">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Action Type</label>
                <select name="action_type" class="form-control">
                    <option value="Trail Installed">Trail Installed</option>
                    <option value="Data Input Follow Up">Data Input Follow Up</option>
                    <option value="Payment Followup">Payment Followup</option>
                    <option value="Rest Amt Followup">Rest Amt Followup</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Reminder notes / Instructions</label>
                <textarea name="remarks" class="form-control" rows="3" placeholder="Input specific goals..."></textarea>
            </div>
            <div class="flex gap-4 mt-2">
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_email" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Email Notification</span>
                </label>
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_sms" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Free Carrier SMS</span>
                </label>
            </div>
            
            <div class="form-group mt-2">
                <label class="form-label text-xs">SMS Notification Targets</label>
                <div class="flex flex-col gap-2" style="background: var(--border-card); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" name="sms_targets[]" value="client" checked style="width: 14px; height: 14px;">
                        <span>Client Phone:</span>
                        <input type="text" name="sms_client_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="<?php echo htmlspecialchars($lead['phone'] ?? ''); ?>">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="employee" checked style="width: 14px; height: 14px;">
                        <span>Employee Phone:</span>
                        <input type="text" name="sms_employee_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="9012345678" placeholder="Employee Phone">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="admin" checked style="width: 14px; height: 14px;">
                        <span>Admin Phone:</span>
                        <input type="text" name="sms_admin_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="7860510928">
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('schedule-followup-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Reminder</button>
            </div>
        </form>
    </div>
</div>

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
