<?php
/**
 * Marg ERP CRM - Automated Notification Messages & Templates Manager
 * Allows administrators to view, edit, configure, toggle, and test all automated
 * WhatsApp and Email transactional messages (Client Welcome, Support Tickets, Leads, Renewals, Invoices).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$role = $_SESSION['user_role'] ?? '';
$is_admin = ($role === 'Super Admin' || $role === 'Admin' || isSystemAdminRole($role));

// Access control check
if (!$is_admin && !hasAccess('notification_templates', $role)) {
    echo "<div class='card p-6 text-center'><h3 class='text-danger'>Access Denied</h3><p>You do not have permission to manage notification templates.</p></div>";
    return;
}

// --------------------------------------------------------------------------
// 1. AJAX ENDPOINTS HANDLER
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $ajaxAction = $_POST['action'];

    // 1A. Save / Update Template
    if ($ajaxAction === 'save_template') {
        $id = intval($_POST['id'] ?? 0);
        $template_key = trim($_POST['template_key'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $channel = trim($_POST['channel'] ?? 'whatsapp');
        $whatsapp_body = trim($_POST['whatsapp_body'] ?? '');
        $email_subject = trim($_POST['email_subject'] ?? '');
        $email_body = trim($_POST['email_body'] ?? '');
        $available_variables = trim($_POST['available_variables'] ?? '');
        $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Template Title is required.']);
            exit;
        }

        if (empty($whatsapp_body) && ($channel === 'whatsapp' || $channel === 'both')) {
            echo json_encode(['success' => false, 'message' => 'WhatsApp message body cannot be empty.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE `system_notification_templates` SET
                        category = ?,
                        title = ?,
                        description = ?,
                        channel = ?,
                        whatsapp_body = ?,
                        email_subject = ?,
                        email_body = ?,
                        available_variables = ?,
                        is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $category, $title, $description, $channel,
                    $whatsapp_body, $email_subject, $email_body,
                    $available_variables, $is_active, $id
                ]);
                echo json_encode(['success' => true, 'message' => "Template '{$title}' updated successfully!"]);
                exit;
            } else {
                if (empty($template_key)) {
                    $template_key = 'custom_' . strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $title)) . '_' . rand(100, 999);
                } else {
                    $template_key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $template_key));
                }

                $chk = $pdo->prepare("SELECT id FROM `system_notification_templates` WHERE template_key = ? LIMIT 1");
                $chk->execute([$template_key]);
                if ($chk->fetch()) {
                    echo json_encode(['success' => false, 'message' => "Template key '{$template_key}' already exists. Please choose a unique key."]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO `system_notification_templates` (
                        template_key, category, title, description, channel,
                        whatsapp_body, email_subject, email_body, available_variables, is_active, is_system
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, 0
                    )
                ");
                $stmt->execute([
                    $template_key, $category, $title, $description, $channel,
                    $whatsapp_body, $email_subject, $email_body, $available_variables, $is_active
                ]);
                echo json_encode(['success' => true, 'message' => "New template '{$title}' created successfully!"]);
                exit;
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1B. Toggle Active Status
    if ($ajaxAction === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);

        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE `system_notification_templates` SET is_active = ? WHERE id = ?");
                $stmt->execute([$status, $id]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Template status ' . ($status ? 'Enabled' : 'Disabled') . ' successfully.',
                    'new_status' => $status
                ]);
                exit;
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid template ID']);
        exit;
    }

    // 1C. Delete Custom Template
    if ($ajaxAction === 'delete_template') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $chk = $pdo->prepare("SELECT is_system FROM `system_notification_templates` WHERE id = ?");
                $chk->execute([$id]);
                $isSystem = $chk->fetchColumn();
                if ($isSystem) {
                    echo json_encode(['success' => false, 'message' => 'System default templates cannot be deleted, but you can turn them OFF using the toggle switch.']);
                    exit;
                }

                $del = $pdo->prepare("DELETE FROM `system_notification_templates` WHERE id = ?");
                $del->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Custom template deleted successfully.']);
                exit;
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Invalid template ID']);
        exit;
    }

    // 1D. Send Live Test WhatsApp Message
    if ($ajaxAction === 'send_test_message') {
        $testMobile = trim($_POST['mobile'] ?? '');
        $messageText = trim($_POST['message'] ?? '');
        $cleanPhone = preg_replace('/[^0-9]/', '', $testMobile);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }

        if (strlen($cleanPhone) < 10) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit WhatsApp phone number.']);
            exit;
        }

        if (empty($messageText)) {
            echo json_encode(['success' => false, 'message' => 'Message content is empty.']);
            exit;
        }

        // Render sample data into sample variables if present
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://friendlyaisolution.com/';
        $sampleVars = [
            '{client_name}' => 'Gaurav Pharmaceuticals',
            '{party_name}' => 'Gaurav Pharmaceuticals Pvt Ltd',
            '{contact_person}' => 'Deepak Ji',
            '{customer_id}' => 'CL-99881',
            '{ticket_id}' => 'TK-2026-000322',
            '{client_info}' => '9897024848 (Gaurav Pharmaceuticals)',
            '{status}' => 'In Progress',
            '{software_type}' => 'Marg ERP 9+ Silver',
            '{assigned_engineer}' => 'Aman Tiwari',
            '{problem}' => 'Printer Port Configuration Error in Billing',
            '{solution}' => 'TALLY DATA EXPORT Successfully Resolved',
            '{notes}' => 'Issue resolved and verified with customer',
            '{created_at}' => date('d M Y, h:i A'),
            '{updated_at}' => date('d M Y, h:i A'),
            '{closed_at}' => date('d M Y, h:i A'),
            '{lead_id}' => 'LD-' . date('Ymd') . '-012',
            '{lead_name}' => 'Vikram Singh',
            '{lead_phone}' => '9876543210',
            '{product_name}' => 'Marg ERP Gold Edition',
            '{source}' => 'WhatsApp Drop',
            '{stage}' => 'Demo Scheduled',
            '{created_by}' => 'Harsh Saini',
            '{trainer_name}' => 'Harsh Saini',
            '{trainer_phone}' => '7860510928',
            '{training_mode}' => 'Online (Google Meet)',
            '{total_days}' => '3',
            '{total_hours}' => '6',
            '{scheduled_at}' => date('d-m-Y h:i A', strtotime('+1 day 11:00 AM')),
            '{address}' => 'Civil Lines, Kanpur',
            '{demo_id}' => 'DM-5291',
            '{crm_link}' => $baseUrl . 'index.php?page=leads',
            '{invoice_no}' => 'INV-' . date('Y') . '-0042',
            '{amount}' => '14,500.00',
            '{payment_mode}' => 'UPI / Online Bank',
            '{payment_date}' => date('d M Y'),
            '{due_date}' => date('d M Y', strtotime('+15 days')),
            '{account_name}' => 'MARG SOFT SOLUTION',
            '{bank_name}' => 'HDFC Bank',
            '{account_number}' => '50200067891234',
            '{ifsc_code}' => 'HDFC0001234',
            '{branch}' => 'Main Branch',
            '{account_type}' => 'Current Account',
            '{upi_id}' => 'margsoft@upi',
            '{agent_name}' => 'Sahil Savita',
            '{helpline}' => '7523830026 / 9170009697 / 9044345020'
        ];

        foreach ($sampleVars as $k => $v) {
            $messageText = str_ireplace($k, $v, $messageText);
        }

        try {
            require_once __DIR__ . '/../api/whatsapp-api.php';
            $whatsappObj = new WhatsAppAPI($pdo);
            $res = $whatsappObj->sendText($cleanPhone, $messageText);
            $wamid = $res['messages'][0]['id'] ?? '';

            echo json_encode([
                'success' => true,
                'message' => "Test WhatsApp message sent successfully to +{$cleanPhone}!",
                'wamid' => $wamid
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'WhatsApp API Error: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// --------------------------------------------------------------------------
// 2. FETCH ALL NOTIFICATION TEMPLATES
// --------------------------------------------------------------------------
$allTemplates = [];
try {
    $stmt = $pdo->query("SELECT * FROM `system_notification_templates` ORDER BY category ASC, id ASC");
    $allTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $allTemplates = [];
}

// KPI counters
$totalCount = count($allTemplates);
$activeWaCount = 0;
$activeEmailCount = 0;
$customCount = 0;

$categories = [];
foreach ($allTemplates as $t) {
    if ($t['is_active']) {
        if ($t['channel'] === 'whatsapp' || $t['channel'] === 'both') $activeWaCount++;
        if ($t['channel'] === 'email' || $t['channel'] === 'both') $activeEmailCount++;
    }
    if (!$t['is_system']) $customCount++;
    $cat = $t['category'] ?: 'General';
    if (!isset($categories[$cat])) {
        $categories[$cat] = 0;
    }
    $categories[$cat]++;
}

$active_cat = $_GET['cat'] ?? 'all';
?>

<div class="content-header flex justify-between align-center flex-wrap gap-4 mb-6">
    <div>
        <div class="flex align-center gap-2 mb-1">
            <div style="background: rgba(245, 158, 11, 0.15); color: #d97706; padding: 6px; border-radius: 10px; display: inline-flex;">
                <i data-lucide="bell-ring" style="width: 22px; height: 22px;"></i>
            </div>
            <h1 class="page-title text-2xl font-black m-0" style="letter-spacing: -0.02em;">Auto Notification Messages</h1>
            <span class="badge" style="background: #10b981; color: white; font-weight: 700; font-size: 0.7rem; padding: 3px 8px; border-radius: 6px;">AUTOMATED</span>
        </div>
        <p class="text-sm text-muted m-0">View, customize, and edit all automated transactional WhatsApp & Email alerts sent by Marg CRM.</p>
    </div>
    <div class="flex align-center gap-2 flex-wrap">
        <button type="button" class="btn btn-secondary text-xs flex align-center gap-1.5" onclick="location.reload();" style="border-radius: 8px; font-weight: 600;">
            <i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i>
            <span>Refresh</span>
        </button>
        <button type="button" class="btn btn-primary text-xs flex align-center gap-1.5" onclick="openTemplateModal(null);" style="border-radius: 8px; font-weight: 700; background: linear-gradient(135deg, #2563eb, #1d4ed8); box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
            <i data-lucide="plus-circle" style="width: 15px; height: 15px;"></i>
            <span>Add Custom Template</span>
        </button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;">
        <div class="flex justify-between align-center">
            <span class="text-xs text-muted font-bold uppercase tracking-wider">Total System Messages</span>
            <div style="background: rgba(37,99,235,0.1); color: #2563eb; padding: 6px; border-radius: 8px;">
                <i data-lucide="messages-square" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div class="text-2xl font-black mt-2 font-mono" style="color: var(--text-main);"><?php echo $totalCount; ?></div>
        <span class="text-xs text-muted mt-1 block">Transactional Triggers</span>
    </div>

    <div class="card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;">
        <div class="flex justify-between align-center">
            <span class="text-xs text-muted font-bold uppercase tracking-wider">Active WhatsApp Alerts</span>
            <div style="background: rgba(16,185,129,0.1); color: #10b981; padding: 6px; border-radius: 8px;">
                <i data-lucide="message-circle" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div class="text-2xl font-black mt-2 font-mono" style="color: #10b981;"><?php echo $activeWaCount; ?></div>
        <span class="text-xs text-muted mt-1 block">Live WhatsApp Notifications</span>
    </div>

    <div class="card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;">
        <div class="flex justify-between align-center">
            <span class="text-xs text-muted font-bold uppercase tracking-wider">Active Email Alerts</span>
            <div style="background: rgba(59,130,246,0.1); color: #3b82f6; padding: 6px; border-radius: 8px;">
                <i data-lucide="mail" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div class="text-2xl font-black mt-2 font-mono" style="color: #3b82f6;"><?php echo $activeEmailCount; ?></div>
        <span class="text-xs text-muted mt-1 block">Email Confirmation Messages</span>
    </div>

    <div class="card p-4" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;">
        <div class="flex justify-between align-center">
            <span class="text-xs text-muted font-bold uppercase tracking-wider">Custom Templates</span>
            <div style="background: rgba(168,85,247,0.1); color: #a855f7; padding: 6px; border-radius: 8px;">
                <i data-lucide="sparkles" style="width: 18px; height: 18px;"></i>
            </div>
        </div>
        <div class="text-2xl font-black mt-2 font-mono" style="color: #a855f7;"><?php echo $customCount; ?></div>
        <span class="text-xs text-muted mt-1 block">Admin-Added Workflows</span>
    </div>
</div>

<!-- Category Tabs & Live Search Bar -->
<div class="card p-3 mb-5" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;">
    <div class="flex justify-between align-center flex-wrap gap-3">
        <!-- Tabs -->
        <div class="flex align-center gap-1.5 flex-wrap">
            <button type="button" class="category-pill-btn <?php echo ($active_cat === 'all') ? 'active' : ''; ?>" onclick="filterByCategory('all')">
                <span>All Templates</span>
                <span class="pill-count"><?php echo $totalCount; ?></span>
            </button>
            <?php foreach ($categories as $catName => $count): ?>
                <button type="button" class="category-pill-btn <?php echo ($active_cat === $catName) ? 'active' : ''; ?>" onclick="filterByCategory('<?php echo htmlspecialchars($catName); ?>')">
                    <span><?php echo htmlspecialchars($catName); ?></span>
                    <span class="pill-count"><?php echo $count; ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Search & Filter Controls -->
        <div class="flex align-center gap-2 flex-grow-1" style="max-width: 380px;">
            <div style="position: relative; width: 100%;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 10px; width: 15px; height: 15px; color: var(--text-muted);"></i>
                <input type="text" id="template_search_input" placeholder="Search templates, variables, keywords..." class="form-control text-xs" style="padding-left: 32px; height: 36px; border-radius: 8px;" oninput="applyTemplateFilters()">
            </div>
            <select id="channel_filter_select" class="form-control text-xs font-semibold" style="width: 130px; height: 36px; border-radius: 8px;" onchange="applyTemplateFilters()">
                <option value="all">All Channels</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
                <option value="both">Both</option>
            </select>
        </div>
    </div>
</div>

<!-- Templates Cards Grid -->
<div id="templates_grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 1.25rem;">
    <?php if (empty($allTemplates)): ?>
        <div class="card p-6 text-center" style="grid-column: 1 / -1; background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: 14px;">
            <i data-lucide="inbox" style="width: 42px; height: 42px; color: var(--text-muted); margin: 0 auto 0.75rem auto;"></i>
            <h4 class="font-bold text-main m-0">No Notification Templates Found</h4>
            <p class="text-xs text-muted mt-1">Please reload or click "Add Custom Template" to configure automated messages.</p>
        </div>
    <?php else: ?>
        <?php foreach ($allTemplates as $tpl): ?>
            <?php 
                $channel = $tpl['channel'] ?? 'whatsapp';
                $isActive = (intval($tpl['is_active']) === 1);
                $varsList = array_filter(array_map('trim', explode(',', $tpl['available_variables'] ?? '')));
            ?>
            <div class="template-card card" data-id="<?php echo $tpl['id']; ?>" data-category="<?php echo htmlspecialchars($tpl['category']); ?>" data-channel="<?php echo htmlspecialchars($channel); ?>" data-search="<?php echo htmlspecialchars(strtolower($tpl['title'] . ' ' . $tpl['template_key'] . ' ' . $tpl['category'] . ' ' . $tpl['whatsapp_body'])); ?>" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; transition: all 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                <!-- Card Header -->
                <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; background: var(--bg-app);">
                    <div>
                        <div class="flex align-center gap-2 flex-wrap mb-1">
                            <span class="badge" style="background: rgba(37,99,235,0.12); color: #2563eb; font-weight: 700; font-size: 0.68rem; padding: 2px 7px; border-radius: 6px;">
                                <?php echo htmlspecialchars($tpl['category']); ?>
                            </span>
                            <span class="badge font-mono" style="background: var(--border-card); color: var(--text-muted); font-size: 0.68rem; padding: 2px 7px; border-radius: 6px;">
                                key: <?php echo htmlspecialchars($tpl['template_key']); ?>
                            </span>
                            <?php if ($channel === 'whatsapp' || $channel === 'both'): ?>
                                <span class="badge" style="background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; display: inline-flex; align-items: center; gap: 3px;">
                                    <i data-lucide="message-circle" style="width: 11px; height: 11px;"></i> WhatsApp
                                </span>
                            <?php endif; ?>
                            <?php if ($channel === 'email' || $channel === 'both'): ?>
                                <span class="badge" style="background: rgba(59,130,246,0.12); color: #3b82f6; font-weight: 700; font-size: 0.68rem; padding: 2px 7px; border-radius: 6px; display: inline-flex; align-items: center; gap: 3px;">
                                    <i data-lucide="mail" style="width: 11px; height: 11px;"></i> Email
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="font-bold text-base m-0 text-main" style="letter-spacing: -0.01em;"><?php echo htmlspecialchars($tpl['title']); ?></h3>
                    </div>

                    <!-- Live Active Toggle Switch -->
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label class="switch-toggle" title="Toggle Auto Dispatch ON/OFF">
                            <input type="checkbox" <?php echo $isActive ? 'checked' : ''; ?> onchange="toggleTemplateStatus(<?php echo $tpl['id']; ?>, this.checked)">
                            <span class="slider round"></span>
                        </label>
                    </div>
                </div>

                <!-- Card Body -->
                <div style="padding: 1.15rem 1.25rem; flex: 1; display: flex; flex-direction: column;">
                    <?php if (!empty($tpl['description'])): ?>
                        <p class="text-xs text-muted mb-3" style="line-height: 1.45;"><?php echo htmlspecialchars($tpl['description']); ?></p>
                    <?php endif; ?>

                    <!-- Message Body Snippet (WhatsApp Chat Bubble Look) -->
                    <div class="message-preview-bubble mb-3">
                        <div class="bubble-header">
                            <i data-lucide="message-square" style="width: 12px; height: 12px; color: #10b981;"></i>
                            <span>WhatsApp Message Template</span>
                        </div>
                        <div class="bubble-content font-mono text-xs">
                            <?php echo nl2br(htmlspecialchars($tpl['whatsapp_body'])); ?>
                        </div>
                    </div>

                    <!-- Email Subject (if applicable) -->
                    <?php if (($channel === 'email' || $channel === 'both') && !empty($tpl['email_subject'])): ?>
                        <div style="background: rgba(59,130,246,0.06); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px; padding: 7px 10px; margin-bottom: 12px; font-size: 0.74rem;">
                            <strong style="color: #2563eb;">Email Subject:</strong>
                            <span style="color: var(--text-main); margin-left: 4px;"><?php echo htmlspecialchars($tpl['email_subject']); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Dynamic Variables Chips -->
                    <?php if (!empty($varsList)): ?>
                        <div style="margin-top: auto; padding-top: 8px;">
                            <span class="text-xs text-muted font-bold block mb-1.5" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em;">Available Placeholders:</span>
                            <div class="flex align-center gap-1.5 flex-wrap">
                                <?php foreach ($varsList as $vTag): ?>
                                    <span class="var-tag font-mono" title="Click to copy <?php echo htmlspecialchars($vTag); ?>" onclick="copyToClipboard('<?php echo htmlspecialchars($vTag); ?>')">
                                        <?php echo htmlspecialchars($vTag); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Card Footer Controls -->
                <div style="padding: 0.75rem 1.25rem; border-top: 1px solid var(--border-color); background: var(--bg-app); display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                    <div>
                        <?php if (!$tpl['is_system']): ?>
                            <button type="button" class="btn text-xs text-danger flex align-center gap-1 p-0" onclick="deleteCustomTemplate(<?php echo $tpl['id']; ?>, '<?php echo htmlspecialchars(addslashes($tpl['title'])); ?>')" style="background: transparent; border: none; cursor: pointer; font-weight: 600;">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i> Delete
                            </button>
                        <?php else: ?>
                            <span class="text-xs text-muted" style="font-size: 0.7rem; font-weight: 600;">System Protected</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex align-center gap-2">
                        <button type="button" class="btn btn-secondary text-xs flex align-center gap-1.5" onclick='openTestMessageModal(<?php echo json_encode($tpl); ?>)' style="border-radius: 8px; font-weight: 600;">
                            <i data-lucide="send" style="width: 13px; height: 13px; color: #10b981;"></i>
                            <span>Send Test</span>
                        </button>
                        <button type="button" class="btn btn-primary text-xs flex align-center gap-1.5" onclick='openTemplateModal(<?php echo json_encode($tpl); ?>)' style="border-radius: 8px; font-weight: 700; background: var(--primary);">
                            <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i>
                            <span>Edit Message</span>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- 3. EDIT / ADD TEMPLATE MODAL WITH LIVE SMARTPHONE SIMULATOR PREVIEW       -->
<!-- ========================================================================= -->
<div id="template-edit-modal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 9999; backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1.5rem;">
    <div class="modal-card" style="background: var(--bg-card); width: 100%; max-width: 1050px; border-radius: 20px; border: 1px solid var(--border-color); box-shadow: 0 25px 60px rgba(0,0,0,0.4); display: flex; flex-direction: column; max-height: 90vh; overflow: hidden;">
        
        <!-- Modal Header -->
        <div style="padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-app);">
            <div class="flex align-center gap-2.5">
                <div style="background: rgba(37,99,235,0.12); color: var(--primary); padding: 7px; border-radius: 10px;">
                    <i data-lucide="file-edit" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h2 id="modal_template_title" class="font-black text-lg m-0 text-main">Edit Notification Template</h2>
                    <p class="text-xs text-muted m-0">Customize automated messaging copy, placeholders, and formatting.</p>
                </div>
            </div>
            <button type="button" onclick="closeTemplateModal()" class="btn-icon text-muted" style="border: none; background: transparent; cursor: pointer; padding: 6px; border-radius: 8px;">
                <i data-lucide="x" style="width: 20px; height: 20px;"></i>
            </button>
        </div>

        <!-- Modal Body (Two-Column Layout: Editor + Phone Simulator) -->
        <form id="template_edit_form" onsubmit="submitTemplateForm(event)" style="display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden;">
            <input type="hidden" id="edit_tpl_id" name="id" value="0">
            <input type="hidden" id="edit_tpl_key" name="template_key" value="">

            <div style="display: grid; grid-template-columns: 1.35fr 1fr; gap: 0; flex: 1; min-height: 0; overflow: hidden;">
                <!-- Left Pane: Editor Inputs -->
                <div style="padding: 1.5rem; overflow-y: auto; border-right: 1px solid var(--border-color);">
                    
                    <!-- Row 1: Title & Category -->
                    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group m-0">
                            <label class="form-label font-bold text-xs">Template Title *</label>
                            <input type="text" id="edit_tpl_title" name="title" required placeholder="e.g. Support Ticket Resolved" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 8px;">
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label font-bold text-xs">Category *</label>
                            <select id="edit_tpl_category" name="category" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 8px;">
                                <option value="Clients">Clients</option>
                                <option value="Support Tickets">Support Tickets</option>
                                <option value="Leads & Sales">Leads & Sales</option>
                                <option value="Billing & Renewals">Billing & Renewals</option>
                                <option value="Operations">Operations</option>
                                <option value="General">General</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Channel & Description -->
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-group m-0">
                            <label class="form-label font-bold text-xs">Dispatch Channel</label>
                            <select id="edit_tpl_channel" name="channel" class="form-control text-xs font-semibold" style="height: 38px; border-radius: 8px;" onchange="onChannelChange(this.value)">
                                <option value="whatsapp">WhatsApp Only</option>
                                <option value="email">Email Only</option>
                                <option value="both">Both (WhatsApp & Email)</option>
                            </select>
                        </div>
                        <div class="form-group m-0">
                            <label class="form-label font-bold text-xs">Short Description</label>
                            <input type="text" id="edit_tpl_description" name="description" placeholder="e.g. Dispatched when engineer resolves support ticket" class="form-control text-xs" style="height: 38px; border-radius: 8px;">
                        </div>
                    </div>

                    <!-- Placeholders Toolbar (Click to Insert) -->
                    <div class="mb-3">
                        <div class="flex justify-between align-center mb-1">
                            <label class="form-label font-bold text-xs m-0">Dynamic Placeholders (Click to Insert into Message):</label>
                            <span class="text-xs text-muted" style="font-size: 0.7rem;">Inserts at cursor</span>
                        </div>
                        <div id="modal_var_chips_container" class="flex align-center gap-1.5 flex-wrap" style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px; padding: 6px 8px; min-height: 36px;">
                            <!-- Dynamically populated chips -->
                        </div>
                        <input type="hidden" id="edit_tpl_vars" name="available_variables" value="">
                    </div>

                    <!-- WhatsApp Message Editor Section -->
                    <div id="whatsapp_section_container" class="form-group mb-3">
                        <div class="flex justify-between align-center mb-1.5">
                            <label class="form-label font-bold text-xs m-0 flex align-center gap-1.5" style="color: #10b981;">
                                <i data-lucide="message-circle" style="width: 14px; height: 14px;"></i>
                                <span>WhatsApp Message Copy *</span>
                            </label>
                            <!-- Formatting Shortcut Tools -->
                            <div class="flex align-center gap-1">
                                <button type="button" class="btn-formatting" onclick="insertFormatting('*', '*')" title="Bold (Ctrl+B)"><b>B</b></button>
                                <button type="button" class="btn-formatting" onclick="insertFormatting('_', '_')" title="Italic (Ctrl+I)"><i>I</i></button>
                                <button type="button" class="btn-formatting" onclick="insertFormatting('~', '~')" title="Strikethrough"><s>S</s></button>
                                <button type="button" class="btn-formatting" onclick="insertFormatting('```', '```')" title="Monospace"><code>&lt;&gt;</code></button>
                            </div>
                        </div>
                        <textarea id="edit_tpl_whatsapp_body" name="whatsapp_body" rows="9" class="form-control font-mono text-xs" placeholder="Type WhatsApp message template here..." style="border-radius: 8px; resize: vertical; line-height: 1.5;" oninput="updateLiveSimulator()"></textarea>
                    </div>

                    <!-- Email Section (Subject & Body) -->
                    <div id="email_section_container" style="display: none; background: rgba(59,130,246,0.03); border: 1px solid rgba(59,130,246,0.2); border-radius: 12px; padding: 12px; margin-bottom: 12px;">
                        <label class="form-label font-bold text-xs mb-2 flex align-center gap-1.5" style="color: #2563eb;">
                            <i data-lucide="mail" style="width: 14px; height: 14px;"></i>
                            <span>Email Notification Template</span>
                        </label>
                        <div class="form-group mb-2">
                            <label class="text-xs font-semibold text-muted mb-1 block">Email Subject</label>
                            <input type="text" id="edit_tpl_email_subject" name="email_subject" placeholder="e.g. Support Ticket [#{ticket_id}] Resolved" class="form-control text-xs" style="height: 36px; border-radius: 8px;">
                        </div>
                        <div class="form-group m-0">
                            <label class="text-xs font-semibold text-muted mb-1 block">Email HTML Content</label>
                            <textarea id="edit_tpl_email_body" name="email_body" rows="4" placeholder="<p>Dear {client_name}, your ticket has been resolved...</p>" class="form-control font-mono text-xs" style="border-radius: 8px; resize: vertical;"></textarea>
                        </div>
                    </div>

                    <!-- Active Toggle inside Modal -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px;">
                        <span class="text-xs font-bold text-main">Template Auto Dispatch Status:</span>
                        <label class="switch-toggle">
                            <input type="checkbox" id="edit_tpl_is_active" name="is_active" value="1" checked>
                            <span class="slider round"></span>
                        </label>
                    </div>

                </div>

                <!-- Right Pane: Real-time WhatsApp Phone Simulator -->
                <div style="background: #111b21; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;">
                    <div style="position: absolute; top: 12px; left: 16px; display: flex; align-items: center; gap: 6px;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                        <span style="font-size: 0.72rem; color: #8696a0; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Live WhatsApp Preview</span>
                    </div>

                    <!-- Smartphone Device Frame -->
                    <div class="phone-mockup-frame">
                        <div class="phone-mockup-header">
                            <div class="flex align-center gap-2">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: #00a884; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 13px;">
                                    M
                                </div>
                                <div>
                                    <div style="font-size: 13px; font-weight: 700; color: #e9edef; line-height: 1.2;">Marg Soft Solution</div>
                                    <div style="font-size: 10px; color: #8696a0;">Official Enterprise Support</div>
                                </div>
                            </div>
                            <i data-lucide="more-vertical" style="width: 16px; height: 16px; color: #8696a0;"></i>
                        </div>

                        <!-- Chat Area with WhatsApp Wallpaper Pattern -->
                        <div class="phone-mockup-chat-body">
                            <!-- Date divider -->
                            <div class="chat-date-pill">TODAY</div>

                            <!-- WhatsApp Message Bubble -->
                            <div class="wa-message-bubble-out">
                                <div id="phone_sim_content" class="bubble-text">
                                    <!-- Dynamic text populated by JS -->
                                </div>
                                <div class="bubble-time">
                                    <span><?php echo date('h:i A'); ?></span>
                                    <i data-lucide="check-check" style="width: 13px; height: 13px; color: #53bdeb; margin-left: 2px;"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Fake Input Bar -->
                        <div class="phone-mockup-footer">
                            <i data-lucide="smile" style="width: 18px; height: 18px; color: #8696a0;"></i>
                            <div style="background: #2a3942; border-radius: 8px; height: 28px; flex: 1; padding: 0 10px; font-size: 11px; color: #8696a0; display: flex; align-items: center;">Message...</div>
                            <i data-lucide="mic" style="width: 18px; height: 18px; color: #00a884;"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer Controls -->
            <div style="padding: 0.85rem 1.5rem; border-top: 1px solid var(--border-color); background: var(--bg-app); display: flex; justify-content: space-between; align-items: center;">
                <button type="button" onclick="closeTemplateModal()" class="btn btn-secondary text-xs" style="border-radius: 8px; font-weight: 600; padding: 0.5rem 1.15rem;">
                    Cancel
                </button>
                <div class="flex align-center gap-2">
                    <button type="button" class="btn btn-secondary text-xs flex align-center gap-1.5" onclick="sendTestFromEditor()" style="border-radius: 8px; font-weight: 600;">
                        <i data-lucide="send" style="width: 13px; height: 13px; color: #10b981;"></i>
                        <span>Send Test to Mobile</span>
                    </button>
                    <button type="submit" id="btn_save_template" class="btn btn-primary text-xs flex align-center gap-1.5" style="border-radius: 8px; font-weight: 700; padding: 0.5rem 1.35rem; background: #10b981; border: none; box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
                        <i data-lucide="check-circle" style="width: 15px; height: 15px;"></i>
                        <span>Save Template</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 4. QUICK SEND LIVE TEST MESSAGE MODAL                                     -->
<!-- ========================================================================= -->
<div id="quick-test-modal" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 10000; backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1.5rem;">
    <div class="modal-card" style="background: var(--bg-card); width: 100%; max-width: 520px; border-radius: 18px; border: 1px solid var(--border-color); box-shadow: 0 20px 50px rgba(0,0,0,0.4); display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-app);">
            <div class="flex align-center gap-2">
                <div style="background: rgba(16,185,129,0.12); color: #10b981; padding: 6px; border-radius: 8px;">
                    <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                </div>
                <h3 class="font-bold text-base m-0 text-main">Send Live Test WhatsApp</h3>
            </div>
            <button type="button" onclick="closeQuickTestModal()" class="btn-icon text-muted" style="border: none; background: transparent; cursor: pointer;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <div class="p-4">
            <p class="text-xs text-muted mb-3">Test dispatch will replace dynamic placeholders with sample demonstration data and send directly via active Meta Cloud API.</p>

            <div class="form-group mb-3">
                <label class="form-label font-bold text-xs">Template to Test</label>
                <input type="text" id="test_modal_tpl_name" class="form-control text-xs font-semibold" readonly style="background: var(--bg-app);">
            </div>

            <div class="form-group mb-3">
                <label class="form-label font-bold text-xs">Recipient WhatsApp Mobile Number *</label>
                <div style="display: flex; gap: 8px;">
                    <span style="display: flex; align-items: center; padding: 0 10px; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px; font-weight: 700; font-size: 0.8rem; color: var(--text-muted);">+91</span>
                    <input type="text" id="test_modal_mobile" placeholder="e.g. 9876543210" class="form-control font-mono text-xs" style="height: 38px; border-radius: 8px;" maxlength="12" value="7860510928">
                </div>
            </div>

            <input type="hidden" id="test_modal_message_payload" value="">

            <div id="test_dispatch_status_alert" style="display: none; padding: 10px 12px; border-radius: 8px; font-size: 0.75rem; margin-top: 12px;"></div>
        </div>

        <div style="padding: 0.75rem 1.25rem; border-top: 1px solid var(--border-color); background: var(--bg-app); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="closeQuickTestModal()" class="btn btn-secondary text-xs" style="border-radius: 8px;">Cancel</button>
            <button type="button" id="btn_confirm_send_test" onclick="executeSendLiveTest()" class="btn btn-primary text-xs flex align-center gap-1.5" style="border-radius: 8px; font-weight: 700; background: #10b981; border: none;">
                <i data-lucide="send" style="width: 13px; height: 13px;"></i>
                <span>Send Test WhatsApp</span>
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 5. STYLES & INTERACTIVE CLIENT-SIDE ENGINE                                -->
<!-- ========================================================================= -->
<style>
/* Category Pill Buttons */
.category-pill-btn {
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-muted);
    font-size: 0.76rem;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.category-pill-btn:hover {
    background: var(--bg-app);
    color: var(--text-main);
    border-color: var(--primary);
}
.category-pill-btn.active {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
    box-shadow: 0 2px 8px rgba(37,99,235,0.25);
}
.category-pill-btn .pill-count {
    background: rgba(255,255,255,0.2);
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 0.68rem;
}
.category-pill-btn:not(.active) .pill-count {
    background: var(--border-card);
    color: var(--text-muted);
}

/* Variable Tag Chips */
.var-tag {
    background: rgba(37,99,235,0.08);
    border: 1px solid rgba(37,99,235,0.25);
    color: #2563eb;
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    transition: all 0.15s;
    user-select: none;
}
.var-tag:hover {
    background: #2563eb;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(37,99,235,0.25);
}

/* Chat Bubble Preview inside Card */
.message-preview-bubble {
    background: var(--bg-app);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 10px 12px;
}
.message-preview-bubble .bubble-header {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    margin-bottom: 6px;
    border-bottom: 1px dashed var(--border-color);
    padding-bottom: 4px;
}
.message-preview-bubble .bubble-content {
    color: var(--text-main);
    line-height: 1.45;
    max-height: 120px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Switch Toggle Styling */
.switch-toggle {
    position: relative;
    display: inline-block;
    width: 38px;
    height: 22px;
}
.switch-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.switch-toggle .slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 22px;
}
.switch-toggle .slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
.switch-toggle input:checked + .slider {
    background-color: #10b981;
}
.switch-toggle input:checked + .slider:before {
    transform: translateX(16px);
}

/* Formatting toolbar buttons */
.btn-formatting {
    background: var(--bg-app);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    padding: 2px 7px;
    border-radius: 5px;
    font-size: 0.72rem;
    cursor: pointer;
    transition: all 0.15s;
}
.btn-formatting:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Smartphone Phone Mockup Frame */
.phone-mockup-frame {
    width: 320px;
    height: 480px;
    background: #0b141a;
    border: 8px solid #2a3942;
    border-radius: 36px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.6);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
}
.phone-mockup-header {
    background: #202c33;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.phone-mockup-chat-body {
    flex: 1;
    background: #0b141a;
    padding: 12px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.chat-date-pill {
    background: #182229;
    color: #8696a0;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    align-self: center;
    margin-bottom: 12px;
}
.wa-message-bubble-out {
    background: #005c4b;
    border-radius: 10px 0 10px 10px;
    padding: 8px 10px;
    max-width: 95%;
    align-self: flex-end;
    box-shadow: 0 1px 2px rgba(0,0,0,0.3);
    position: relative;
}
.wa-message-bubble-out .bubble-text {
    color: #e9edef;
    font-size: 11px;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
}
.wa-message-bubble-out .bubble-time {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    font-size: 9px;
    color: #8696a0;
    margin-top: 4px;
}
.phone-mockup-footer {
    background: #202c33;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

<script>
// --------------------------------------------------------------------------
// FILTERING & SEARCH
// --------------------------------------------------------------------------
function filterByCategory(cat) {
    document.querySelectorAll('.category-pill-btn').forEach(function(b) { b.classList.remove('active'); });
    var targetBtn = Array.from(document.querySelectorAll('.category-pill-btn')).find(function(b) {
        return (cat === 'all' && b.innerText.includes('All')) || b.innerText.includes(cat);
    });
    if (targetBtn) targetBtn.classList.add('active');

    window.currentCategoryFilter = cat;
    applyTemplateFilters();
}

function applyTemplateFilters() {
    var cat = window.currentCategoryFilter || 'all';
    var q = (document.getElementById('template_search_input').value || '').toLowerCase().trim();
    var channel = document.getElementById('channel_filter_select').value;

    var cards = document.querySelectorAll('.template-card');
    cards.forEach(function(card) {
        var cardCat = card.getAttribute('data-category');
        var cardChan = card.getAttribute('data-channel');
        var cardSearch = card.getAttribute('data-search') || '';

        var matchesCat = (cat === 'all' || cardCat === cat);
        var matchesChan = (channel === 'all' || cardChan === channel || cardChan === 'both');
        var matchesQ = (q === '' || cardSearch.includes(q));

        if (matchesCat && matchesChan && matchesQ) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

// --------------------------------------------------------------------------
// TOGGLE STATUS
// --------------------------------------------------------------------------
function toggleTemplateStatus(id, isChecked) {
    var formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', isChecked ? 1 : 0);

    fetch('index.php?page=notification_templates', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) {
            alert('Failed to update status: ' + data.message);
            location.reload();
        }
    })
    .catch(function(err) {
        alert('Network error while toggling status: ' + err.message);
    });
}

// --------------------------------------------------------------------------
// DELETE CUSTOM TEMPLATE
// --------------------------------------------------------------------------
function deleteCustomTemplate(id, title) {
    if (!confirm('Are you sure you want to delete template "' + title + '"? This action cannot be undone.')) {
        return;
    }

    var formData = new FormData();
    formData.append('action', 'delete_template');
    formData.append('id', id);

    fetch('index.php?page=notification_templates', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(function(err) {
        alert('Network error: ' + err.message);
    });
}

// --------------------------------------------------------------------------
// MODAL EDITOR & PREVIEW LOGIC
// --------------------------------------------------------------------------
function openTemplateModal(tpl) {
    var modal = document.getElementById('template-edit-modal');
    var isNew = (tpl === null);

    document.getElementById('modal_template_title').innerText = isNew ? 'Add Custom Notification Template' : 'Edit Notification Template';
    document.getElementById('edit_tpl_id').value = isNew ? '0' : (tpl.id || '0');
    document.getElementById('edit_tpl_key').value = isNew ? '' : (tpl.template_key || '');
    document.getElementById('edit_tpl_title').value = isNew ? '' : (tpl.title || '');
    document.getElementById('edit_tpl_category').value = isNew ? 'General' : (tpl.category || 'General');
    document.getElementById('edit_tpl_description').value = isNew ? '' : (tpl.description || '');
    document.getElementById('edit_tpl_channel').value = isNew ? 'whatsapp' : (tpl.channel || 'whatsapp');
    document.getElementById('edit_tpl_whatsapp_body').value = isNew ? '' : (tpl.whatsapp_body || '');
    document.getElementById('edit_tpl_email_subject').value = isNew ? '' : (tpl.email_subject || '');
    document.getElementById('edit_tpl_email_body').value = isNew ? '' : (tpl.email_body || '');
    document.getElementById('edit_tpl_vars').value = isNew ? '{client_name}, {helpline}' : (tpl.available_variables || '');
    document.getElementById('edit_tpl_is_active').checked = isNew ? true : (parseInt(tpl.is_active) === 1);

    onChannelChange(document.getElementById('edit_tpl_channel').value);
    populateVariableChips(document.getElementById('edit_tpl_vars').value);
    updateLiveSimulator();

    modal.style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeTemplateModal() {
    document.getElementById('template-edit-modal').style.display = 'none';
}

function onChannelChange(val) {
    var emailBox = document.getElementById('email_section_container');
    var waBox = document.getElementById('whatsapp_section_container');
    if (val === 'email') {
        emailBox.style.display = 'block';
        waBox.style.display = 'none';
    } else if (val === 'both') {
        emailBox.style.display = 'block';
        waBox.style.display = 'block';
    } else {
        emailBox.style.display = 'none';
        waBox.style.display = 'block';
    }
}

function populateVariableChips(varsStr) {
    var container = document.getElementById('modal_var_chips_container');
    container.innerHTML = '';
    var defaultChips = ['{client_name}', '{contact_person}', '{customer_id}', '{ticket_id}', '{software_type}', '{helpline}'];
    var vars = varsStr ? varsStr.split(',').map(function(s) { return s.trim(); }).filter(Boolean) : defaultChips;
    vars = Array.from(new Set(vars.concat(defaultChips)));

    vars.forEach(function(v) {
        var chip = document.createElement('span');
        chip.className = 'var-tag font-mono';
        chip.innerText = v;
        chip.title = 'Click to insert ' + v;
        chip.onclick = function() {
            insertTextAtCursor(document.getElementById('edit_tpl_whatsapp_body'), v);
            updateLiveSimulator();
        };
        container.appendChild(chip);
    });
}

function insertTextAtCursor(input, text) {
    if (!input) return;
    var startPos = input.selectionStart || 0;
    var endPos = input.selectionEnd || 0;
    input.value = input.value.substring(0, startPos) + text + input.value.substring(endPos, input.value.length);
    input.selectionStart = startPos + text.length;
    input.selectionEnd = startPos + text.length;
    input.focus();
}

function insertFormatting(prefix, suffix) {
    var ta = document.getElementById('edit_tpl_whatsapp_body');
    if (!ta) return;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value;
    var sel = text.substring(start, end);
    var repl = prefix + (sel || 'text') + suffix;
    ta.value = text.substring(0, start) + repl + text.substring(end);
    ta.selectionStart = start + prefix.length;
    ta.selectionEnd = start + prefix.length + (sel ? sel.length : 4);
    ta.focus();
    updateLiveSimulator();
}

function updateLiveSimulator() {
    var raw = document.getElementById('edit_tpl_whatsapp_body').value || '';
    if (!raw.trim()) {
        raw = 'Enter WhatsApp text to preview message formatting live...';
    }

    // Replace sample variables for live preview
    var previewText = raw
        .replace(/\{client_name\}/gi, 'Deepak Enterprises')
        .replace(/\{contact_person\}/gi, 'Deepak Ji')
        .replace(/\{party_name\}/gi, 'Deepak Enterprises')
        .replace(/\{customer_id\}/gi, 'CL-13529')
        .replace(/\{ticket_id\}/gi, 'TK-8842')
        .replace(/\{software_type\}/gi, 'Marg ERP Silver (Pharma)')
        .replace(/\{assigned_engineer\}/gi, 'Amit Kumar')
        .replace(/\{problem\}/gi, 'Barcode generation error in invoice')
        .replace(/\{solution\}/gi, 'Updated Marg ERP DLL & successfully printed test label')
        .replace(/\{created_at\}/gi, '23 Sep 2026, 11:30 AM')
        .replace(/\{updated_at\}/gi, '23 Sep 2026, 12:15 PM')
        .replace(/\{closed_at\}/gi, '23 Sep 2026, 01:00 PM')
        .replace(/\{lead_name\}/gi, 'Ravi Verma')
        .replace(/\{product_name\}/gi, 'Marg ERP Gold')
        .replace(/\{invoice_no\}/gi, 'INV-2026-042')
        .replace(/\{amount\}/gi, '14,500.00')
        .replace(/\{payment_mode\}/gi, 'UPI Bank')
        .replace(/\{payment_date\}/gi, '23 Sep 2026')
        .replace(/\{due_date\}/gi, '08 Oct 2026')
        .replace(/\{helpline\}/gi, '7523830026 / 9170009697');

    // Convert WhatsApp Markdown to HTML (bold, italic, strike, monospace, linebreaks)
    var html = previewText
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*([^\*]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_]+)_/g, '<em>$1</em>')
        .replace(/~([^~]+)~/g, '<del>$1</del>')
        .replace(/```([^`]+)```/g, '<code>$1</code>')
        .replace(/\n/g, '<br>');

    document.getElementById('phone_sim_content').innerHTML = html;
}

// Submit Template Form
function submitTemplateForm(e) {
    e.preventDefault();
    var btn = document.getElementById('btn_save_template');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" class="spin"></i> Saving...';

    var form = document.getElementById('template_edit_form');
    var formData = new FormData(form);
    formData.append('action', 'save_template');

    fetch('index.php?page=notification_templates', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="check-circle" style="width: 15px; height: 15px;"></i> Save Template';
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="check-circle" style="width: 15px; height: 15px;"></i> Save Template';
        alert('Network error: ' + err.message);
    });
}

// --------------------------------------------------------------------------
// QUICK TEST MESSAGE MODAL LOGIC
// --------------------------------------------------------------------------
function openTestMessageModal(tpl) {
    document.getElementById('test_modal_tpl_name').value = tpl.title + ' (' + tpl.template_key + ')';
    document.getElementById('test_modal_message_payload').value = tpl.whatsapp_body;
    document.getElementById('test_dispatch_status_alert').style.display = 'none';
    document.getElementById('quick-test-modal').style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeQuickTestModal() {
    document.getElementById('quick-test-modal').style.display = 'none';
}

function sendTestFromEditor() {
    var body = document.getElementById('edit_tpl_whatsapp_body').value;
    var title = document.getElementById('edit_tpl_title').value || 'Draft Template';
    openTestMessageModal({ title: title, template_key: 'editor_draft', whatsapp_body: body });
}

function executeSendLiveTest() {
    var mobile = document.getElementById('test_modal_mobile').value.trim();
    var body = document.getElementById('test_modal_message_payload').value;
    var alertBox = document.getElementById('test_dispatch_status_alert');
    var btn = document.getElementById('btn_confirm_send_test');

    if (!mobile) {
        alert('Please enter a valid 10-digit WhatsApp number.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Sending...';
    alertBox.style.display = 'block';
    alertBox.style.background = 'rgba(59,130,246,0.1)';
    alertBox.style.color = '#2563eb';
    alertBox.innerText = 'Connecting with WhatsApp Meta Cloud API and dispatching test message...';

    var formData = new FormData();
    formData.append('action', 'send_test_message');
    formData.append('mobile', mobile);
    formData.append('message', body);

    fetch('index.php?page=notification_templates', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="send" style="width: 13px; height: 13px;"></i> Send Test WhatsApp';
        if (data.success) {
            alertBox.style.background = 'rgba(16,185,129,0.12)';
            alertBox.style.color = '#10b981';
            alertBox.innerHTML = '<strong>Success!</strong> ' + data.message + (data.wamid ? '<br><small>Message ID: ' + data.wamid + '</small>' : '');
        } else {
            alertBox.style.background = 'rgba(239,68,68,0.12)';
            alertBox.style.color = '#dc2626';
            alertBox.innerHTML = '<strong>Dispatch Failed:</strong> ' + data.message;
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="send" style="width: 13px; height: 13px;"></i> Send Test WhatsApp';
        alertBox.style.background = 'rgba(239,68,68,0.12)';
        alertBox.style.color = '#dc2626';
        alertBox.innerText = 'Network error: ' + err.message;
    });
}

function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Copied placeholder: ' + text);
        });
    } else {
        alert('Placeholder: ' + text);
    }
}

// Bind Ctrl+B / Ctrl+I to editor textarea
document.addEventListener('DOMContentLoaded', function() {
    var ta = document.getElementById('edit_tpl_whatsapp_body');
    if (ta) {
        ta.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                insertFormatting('*', '*');
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') {
                e.preventDefault();
                insertFormatting('_', '_');
            }
        });
    }
});
</script>
