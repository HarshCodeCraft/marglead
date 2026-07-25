<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Check if we are in Edit Mode
$isEdit = (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']));
$leadId = $isEdit ? htmlspecialchars($_GET['id']) : '';

$editLead = [];
$message = '';
$message_type = '';
$lead_created = false;
$new_lead_id = '';
$new_lead_name = '';
$new_lead_phone = '';

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

$tags_list = ['Cold', 'Hold For Payment', 'Hot', 'Negotiation', 'Normal', 'cold'];

// Load existing lead details if in Edit Mode
if ($isEdit && $db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$leadId]);
        $fetched = $stmt->fetch();
        if ($fetched) {
            $editLead = $fetched;
        }
    } catch (PDOException $e) {
        $message = "Failed to fetch lead details: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Dynamically fetch latest pending follow-up date/time if in Edit Mode
$reminder_date_val = '';
$reminder_time_val = '';
if ($isEdit && $db_connected && $pdo) {
    try {
        $fStmt = $pdo->prepare("SELECT scheduled_at FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY scheduled_at ASC LIMIT 1");
        $fStmt->execute([$leadId]);
        $fData = $fStmt->fetch();
        if ($fData) {
            $parts = explode(' ', $fData['scheduled_at']);
            $reminder_date_val = $parts[0] ?? '';
            $reminder_time_val = substr($parts[1] ?? '', 0, 5);
        }
    } catch (PDOException $e) {}
}

// Check if we are saving a follow-up post-creation
if (isset($_GET['action']) && $_GET['action'] === 'save_followup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = $_POST['lead_id'] ?? '';
    $group = $_POST['group_name'] ?? '';
    $not_required = isset($_POST['not_required']) ? 1 : 0;
    $assigned_to = $_POST['assigned_to'] ?? '';
    $tags = $_POST['tags'] ?? '';
    $reminder_date = $_POST['reminder_date'] ?? '';
    $reminder_time = $_POST['reminder_time'] ?? '';
    $address = $_POST['address'] ?? '';
    $source = $_POST['source'] ?? '';
    $enq_for = $_POST['enq_for'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $remark = $_POST['remark'] ?? '';
    $follow_up_type = $_POST['follow_up_type'] ?? 'Call';
    $follow_type = $_POST['follow_type'] ?? '';
    $comment = $_POST['comment'] ?? '';

    if ($db_connected && $pdo) {
        try {
            // Save followup details if not skipped
            if (!$not_required && !empty($reminder_date)) {
                $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                
                // Check if a pending follow-up already exists for this lead
                $chkFup = $pdo->prepare("SELECT id FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $chkFup->execute([$lead_id]);
                $existFup = $chkFup->fetchColumn();

                if ($existFup) {
                    $stmt = $pdo->prepare("UPDATE followups SET action_type = ?, scheduled_at = ?, remarks = ?, assigned_to = ? WHERE id = ?");
                    $stmt->execute([$follow_up_type, $scheduled_at, $comment, $assigned_to, $existFup]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    $stmt->execute([$lead_id, $follow_up_type, $scheduled_at, $comment, $assigned_to]);
                }
            }
            
            // Update Lead profile with modal inputs
            $stmt = $pdo->prepare("UPDATE leads SET address = ?, tags = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
            $stmt->execute([$address, $tags, $source, $enq_for, $contact_person, $remark, $lead_id]);

            header("Location: index.php?page=leads");
            exit;
        } catch (PDOException $e) {
            $message = "Failed to save follow-up details: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        header("Location: index.php?page=leads");
        exit;
    }
}

// Process Lead creation/edit (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_GET['action']) || $_GET['action'] !== 'save_followup')) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $assigned_to = $_POST['assigned_to'] ?? '';
    $group_name = $_POST['group_name'] ?? '';
    $tags = $_POST['tags'] ?? '';
    $reminder_date = $_POST['reminder_date'] ?? '';
    $reminder_time = $_POST['reminder_time'] ?? '';
    $address = $_POST['address'] ?? '';
    $source = $_POST['source'] ?? '';
    $enq_for = $_POST['enq_for'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $remark = $_POST['remark'] ?? '';

    if ($db_connected && $pdo) {
        try {
            if ($isEdit) {
                // Update existing lead details
                $stmt = $pdo->prepare("UPDATE leads SET name = ?, company = ?, email = ?, phone = ?, address = ?, source = ?, tags = ?, assigned_to = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $stmt->execute([$name, $group_name, $email, $phone, $address, $source, $tags, $assigned_to, $enq_for, $contact_person, $remark, $leadId]);
                
                // Add activity timeline record
                $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead details modified by operator')");
                $log->execute([$leadId, $_SESSION['user_name'] ?? 'System User']);
                
                // Check if they updated follow-up info, insert or update if configured
                if (!empty($reminder_date)) {
                    $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                    
                    // Delete previous pending follow-ups to schedule a fresh one
                    $delFup = $pdo->prepare("DELETE FROM followups WHERE lead_id = ? AND status = 'pending'");
                    $delFup->execute([$leadId]);
                    
                    $fup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, 'Call', ?, ?, ?, 'pending')");
                    $fup->execute([$leadId, $scheduled_at, $remark, $assigned_to]);
                }
                
                // Redirect directly to directory lists
                header("Location: index.php?page=leads");
                exit;
            } else {
                // Register a new lead profile
                $newId = 'LD-' . rand(1000, 9999);
                
                $stmt = $pdo->prepare("INSERT INTO leads (id, name, company, email, phone, address, source, tags, assigned_to, enq_for, contact_person, remarks, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')");
                $stmt->execute([$newId, $name, $group_name, $email, $phone, $address, $source, $tags, $assigned_to, $enq_for, $contact_person, $remark]);
                
                // Add activity log
                $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead file registered')");
                $log->execute([$newId, $_SESSION['user_name'] ?? 'System User']);
                
                // Insert notification for the assigned representative
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'New Lead Assigned', ?, 'info')");
                $notifMsg = "Lead \"" . ($group_name ?: $name) . "\" has been assigned to you.";
                $notifStmt->execute([$assigned_to, $notifMsg]);
                
                // Insert notification for the admin
                $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, type) VALUES ('Admin', 'New Lead Registered', ?, 'success')");
                $adminNotifMsg = "New lead \"" . ($group_name ?: $name) . "\" registered and assigned to " . $assigned_to;
                $adminNotifStmt->execute([$adminNotifMsg]);
                
                // Seed a follow-up directly if configured
                if (!empty($reminder_date)) {
                    $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                    $fup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, 'Call', ?, ?, ?, 'pending')");
                    $fup->execute([$newId, $scheduled_at, $remark, $assigned_to]);
                }
                
                // Trigger Follow-up Modal configuration flow
                $lead_created = true;
                $new_lead_id = $newId;
                $new_lead_name = $name;
                $new_lead_phone = $phone;
            }
        } catch (PDOException $e) {
            $message = "Database execution failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Fallback for offline environments
        if ($isEdit) {
            header("Location: index.php?page=leads");
            exit;
        } else {
            $lead_created = true;
            $new_lead_id = 'LD-MOCK';
            $new_lead_name = $name;
            $new_lead_phone = $phone;
        }
    }
}
?>

<style>
    .glass-form-card {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-lg);
        border-radius: var(--border-radius-lg);
        padding: 2.25rem;
        transition: background-color var(--transition-base), border-color var(--transition-base);
    }
    .form-section-title {
        font-family: var(--font-heading);
        font-size: 0.925rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 0.625rem;
        margin-bottom: 1.5rem;
    }
    .tag-pill {
        cursor: pointer;
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius-full);
        border: 1px solid var(--border-color);
        background: var(--border-card);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.8rem;
        transition: all var(--transition-fast);
        user-select: none;
    }
    .tag-pill:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    .tag-pill.active {
        background-color: var(--primary);
        color: #ffffff;
        border-color: var(--primary);
        box-shadow: var(--shadow-glow);
    }
    .form-control-focus {
        background-color: var(--border-card);
        color: var(--text-main);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius-sm);
        transition: all var(--transition-fast);
    }
    .form-control-focus:focus {
        background-color: var(--bg-card);
        border-color: var(--primary);
        box-shadow: 0 0 0 3px hsla(var(--primary-h), var(--primary-s), var(--primary-l), 0.2);
        outline: none;
    }
    .additional-toggler {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--primary);
        background-color: var(--primary-light);
        padding: 0.625rem 1.125rem;
        border-radius: var(--border-radius-sm);
        border: 1px solid var(--border-color);
        cursor: pointer;
        user-select: none;
        margin-top: 0.5rem;
        transition: all var(--transition-fast);
    }
    .additional-toggler:hover {
        background-color: var(--border-card);
        border-color: var(--primary);
    }
    .additional-toggler i {
        transition: transform var(--transition-base);
    }
    .additional-toggler.open i {
        transform: rotate(90deg);
    }
</style>

<div class="lead-form-container" style="max-width: 850px; margin: 0 auto;">
    <!-- Page Header -->
    <div class="mb-6 flex align-center justify-between">
        <div class="flex align-center gap-3">
            <div style="background-color: var(--primary-light); color: var(--primary); width: 44px; height: 44px; border-radius: var(--border-radius-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="<?php echo $isEdit ? 'user-check' : 'user-plus'; ?>" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <h2 style="font-family: var(--font-heading); font-size: 1.625rem; font-weight: 700; color: var(--text-main);" class="mb-1">
                    <?php echo $isEdit ? "Modify Lead Record (ID: {$leadId})" : "Create New Lead"; ?>
                </h2>
                <p class="text-muted text-sm" style="margin: 0;">
                    <?php echo $isEdit ? "Update and verify registration details for this customer file." : "Add custom customer profiles, select executive owners, configure reminders and tags."; ?>
                </p>
            </div>
        </div>
        <a href="index.php?page=leads" class="btn btn-secondary text-xs" style="padding: 0.5rem 1rem;">
            <i data-lucide="arrow-left" style="width: 14px; height: 14px;"></i> Back to list
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.825rem;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Main Form Card -->
    <form action="index.php?page=lead_form<?php echo $isEdit ? '&action=edit&id=' . urlencode($leadId) : ''; ?>" method="POST" class="glass-form-card flex flex-col gap-6" id="create-lead-form">
        
        <!-- Section 1: Customer Details -->
        <div>
            <h3 class="form-section-title">
                <i data-lucide="user" style="width: 18px; height: 18px;"></i>
                <span>Customer Information</span>
            </h3>
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="name" class="form-control form-control-focus" placeholder="E.g. Dheerendra Vyas" required value="<?php echo htmlspecialchars($editLead['name'] ?? ''); ?>">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Contact Phone <span style="color: var(--danger);">*</span></label>
                    <input type="tel" name="phone" class="form-control form-control-focus" placeholder="E.g. 919454883552" required value="<?php echo htmlspecialchars($editLead['phone'] ?? ''); ?>">
                </div>
                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Email Address</label>
                    <input type="email" name="email" class="form-control form-control-focus" placeholder="E.g. name@domain.com" value="<?php echo htmlspecialchars($editLead['email'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Section 2: Routing & Classification -->
        <div>
            <h3 class="form-section-title">
                <i data-lucide="briefcase" style="width: 18px; height: 18px;"></i>
                <span>Assignment & Classification</span>
            </h3>
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Assign to Operator</label>
                    <select name="assigned_to" class="form-control form-control-focus">
                        <option value="">-- Choose Employee --</option>
                        <?php foreach ($assigned_operators as $op): 
                            $isSelected = (($editLead['assigned_to'] ?? '') === $op);
                        ?>
                            <option value="<?php echo htmlspecialchars($op); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($op); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Group / Company</label>
                    <select name="group_name" class="form-control form-control-focus">
                        <option value="">-- Select Group --</option>
                        <?php 
                        $group_options = ['Fresh', 'Followup', 'Demo Scheduled', 'Demo Done', 'Installation Done', 'Not Required'];
                        $cur_grp = $editLead['company'] ?? '';
                        foreach ($group_options as $grp): 
                        ?>
                            <option value="<?php echo $grp; ?>" <?php echo ($cur_grp === $grp) ? 'selected' : ''; ?>><?php echo $grp; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Tags Selection Pills -->
                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold mb-2" style="color: var(--text-main);">Lead Tags</label>
                    <div class="flex gap-2 flex-wrap" id="tags-pill-matrix-create">
                        <?php foreach ($tags_list as $tag): 
                            $leadTag = $editLead['tags'] ?? '';
                            $isActive = (!empty($leadTag) && strcasecmp($leadTag, $tag) === 0);
                        ?>
                            <span class="tag-pill <?php echo $isActive ? 'active' : ''; ?>" data-tag="<?php echo htmlspecialchars($tag); ?>">
                                <?php echo htmlspecialchars($tag); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="tags" id="lead-tags-hidden" value="<?php echo htmlspecialchars($editLead['tags'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Section 3: Scheduling & Location -->
        <div>
            <h3 class="form-section-title">
                <i data-lucide="calendar-clock" style="width: 18px; height: 18px;"></i>
                <span>Follow-up Reminder & Location</span>
            </h3>
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Reminder Date</label>
                    <input type="date" name="reminder_date" class="form-control form-control-focus" value="<?php echo $reminder_date_val; ?>">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Reminder Time</label>
                    <input type="time" name="reminder_time" class="form-control form-control-focus" value="<?php echo $reminder_time_val; ?>">
                </div>
                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Address / Pin Code</label>
                    <input type="text" name="address" class="form-control form-control-focus" placeholder="E.g. 285204 - Phase 1" value="<?php echo htmlspecialchars($editLead['address'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Section 4: Advanced Attributes (Collapsible) -->
        <div>
            <div class="additional-toggler" id="adv-toggler">
                <i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i>
                <span>Show Additional Fields</span>
            </div>
            
            <div class="grid hidden mt-4" id="adv-fields-panel" style="grid-template-columns: 1fr 1fr; gap: 1.25rem; border-top: 1px dashed var(--border-color); padding-top: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Source</label>
                    <input type="text" name="source" class="form-control form-control-focus" placeholder="E.g. HO" value="<?php echo htmlspecialchars($editLead['source'] ?? ''); ?>">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Enq_For (Product)</label>
                    <?php $cur_ef = $editLead['enq_for'] ?? ''; ?>
                    <select name="enq_for" class="form-control form-control-focus">
                        <option value="">-- Choose Product --</option>
                        <?php 
                        $ef_options = ['Marg Basic', 'Marg Silver', 'Marg Gold', 'Marg Nano', 'Marg Hr', 'Marg Cloud', 'Marg Book Gold', 'Marg Book Silver', 'Marg Enterprises', 'Marg Mart', 'Marg Diamond'];
                        foreach ($ef_options as $ef): 
                        ?>
                            <option value="<?php echo $ef; ?>" <?php echo ($cur_ef === $ef) ? 'selected' : ''; ?>><?php echo $ef; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-focus" placeholder="E.g. Dheerendra Vyas" value="<?php echo htmlspecialchars($editLead['contact_person'] ?? ''); ?>">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Remark</label>
                    <input type="text" name="remark" class="form-control form-control-focus" placeholder="E.g. Follow-up required" value="<?php echo htmlspecialchars($editLead['remarks'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Footer Actions Bar -->
        <div class="flex justify-end gap-3 pt-6" style="border-top: 1px solid var(--border-color); margin-top: 1.5rem;">
            <a href="index.php?page=leads" class="btn btn-secondary text-sm">Cancel</a>
            <button type="submit" class="btn btn-primary text-sm" style="padding: 0.75rem 1.75rem;">
                <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                <span><?php echo $isEdit ? "Save Changes" : "Save Lead"; ?></span>
            </button>
        </div>
    </form>
</div>

<!-- ==========================================
     MODAL: IMMEDIATE FOLLOW-UP (UX WIZARD)
     ========================================== -->
<div id="followup-UX-wizard-modal" class="modal-overlay <?php echo $lead_created ? 'open' : ''; ?>">
    <div class="modal-container" style="max-width: 650px; background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border-color);">
        <div class="modal-header" style="background-color: var(--border-card); border-bottom: 1px solid var(--border-color);">
            <div class="flex align-center gap-3">
                <div style="background-color: var(--primary-light); color: var(--primary); padding: 0.5rem; border-radius: 8px;">
                    <i data-lucide="calendar-clock" style="width: 20px; height: 20px;"></i>
                </div>
                <h3 class="m-0" style="font-family: var(--font-heading); font-size: 1.15rem; font-weight: 700;">
                    Configure Follow-up for <?php echo htmlspecialchars($new_lead_name); ?>
                </h3>
            </div>
            <button class="btn-icon" onclick="window.location.href='index.php?page=leads'"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>

        <form action="index.php?page=lead_form&action=save_followup" method="POST" class="modal-body flex flex-col gap-5" style="padding: 2rem;">
            <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars($new_lead_id); ?>">
            
            <div class="badge" style="--badge-bg: var(--primary-light); --badge-color: var(--primary); padding: 0.5rem 1rem; border-radius: var(--border-radius-sm); font-size: 0.85rem; width: fit-content;">
                Target: <?php echo htmlspecialchars($new_lead_phone); ?>
            </div>

            <!-- Group & Not Required Checkbox Row -->
            <div class="grid" style="grid-template-columns: 1.5fr 1fr; gap: 1rem; align-items: end;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Group</label>
                    <select name="group_name" class="form-control">
                        <option value="Fresh" selected>Fresh</option>
                        <option value="Followup">Followup</option>
                        <option value="Demo Scheduled">Demo Scheduled</option>
                        <option value="Demo Done">Demo Done</option>
                        <option value="Installation Done">Installation Done</option>
                        <option value="Not Required">Not Required</option>
                    </select>
                </div>
                <div class="form-group m-0" style="padding-bottom: 0.75rem;">
                    <label class="flex align-center gap-2 pointer text-sm font-semibold text-danger">
                        <input type="checkbox" name="not_required" id="not-required-checkbox" style="accent-color: var(--danger); scale: 1.1;">
                        <span>Not Required / Skip</span>
                    </label>
                </div>
            </div>

            <!-- Follow-up Details Assignment -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Assign to Operator</label>
                    <select name="assigned_to" class="form-control">
                        <option value="">-- Choose Employee / Unassigned --</option>
                        <?php foreach ($assigned_operators as $op): 
                            $isSelected = (isset($assigned_to) && $assigned_to !== '') ? ($op === $assigned_to) : false;
                        ?>
                            <option value="<?php echo htmlspecialchars($op); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($op); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Reminder Date / Time</label>
                    <div class="flex gap-2">
                        <input type="date" name="reminder_date" class="form-control" style="padding: 0.5rem;" value="">
                        <input type="time" name="reminder_time" class="form-control" style="padding: 0.5rem;" value="">
                    </div>
                </div>
            </div>

            <!-- Tags selector pills row -->
            <div>
                <label class="form-label text-xs font-semibold mb-2">Follow-up Tags</label>
                <div class="flex gap-2 flex-wrap" id="tags-pill-matrix-modal">
                    <?php foreach ($tags_list as $tag): ?>
                        <span class="tag-pill" data-tag="<?php echo htmlspecialchars($tag); ?>">
                            <?php echo htmlspecialchars($tag); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="tags" id="modal-tags-hidden" value="">
            </div>

            <!-- Additional fields -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Location / Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Office location / Code" value="">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Source</label>
                    <input type="text" name="source" class="form-control" placeholder="E.g. HO" value="">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" placeholder="Contact Person Name" value="">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Enq_For</label>
                    <input type="text" name="enq_for" class="form-control" placeholder="Product details">
                </div>
            </div>

            <!-- Follow-up Activity details -->
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; border-top: 1px dashed var(--border-color); padding-top: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Follow-up Activity Type</label>
                    <select name="follow_up_type" class="form-control">
                        <option value="Call">Phone Call</option>
                        <option value="WhatsApp">WhatsApp Message</option>
                        <option value="Demo">System Demo</option>
                        <option value="Visit">Site Visit</option>
                        <option value="Email">Email Thread</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Follow Type Category</label>
                    <select name="follow_type" class="form-control">
                        <option value="First Discovery">First Discovery</option>
                        <option value="Product Trial">Product Trial</option>
                        <option value="Price Negotiation">Price Negotiation</option>
                    </select>
                </div>
                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold">Follow-up Comments / Remarks</label>
                    <textarea name="comment" class="form-control" rows="2" placeholder="Describe the outcome of follow-up call..."></textarea>
                </div>
            </div>

            <!-- Footer modal action controls -->
            <div class="flex justify-end gap-3 mt-4 pt-4 border-top" style="border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.location.href='index.php?page=leads'">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" style="padding: 0.75rem 1.5rem;">
                    <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i>
                    <span>Save Follow-up</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Render Lucide Icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // Toggle Additional Fields Collapsible
        const advToggler = document.getElementById('adv-toggler');
        const advPanel = document.getElementById('adv-fields-panel');
        if (advToggler && advPanel) {
            advToggler.addEventListener('click', () => {
                advToggler.classList.toggle('open');
                advPanel.classList.toggle('hidden');
                
                const icon = advToggler.querySelector('i');
                if (icon) {
                    if (advToggler.classList.contains('open')) {
                        icon.style.transform = 'rotate(90deg)';
                    } else {
                        icon.style.transform = 'rotate(0deg)';
                    }
                }
            });
        }

        // Pills Selection matrices toggles
        const handleTagsPills = (matrixId, hiddenInputId) => {
            const pills = document.querySelectorAll(`#${matrixId} .tag-pill`);
            const hidden = document.getElementById(hiddenInputId);
            
            pills.forEach(pill => {
                pill.addEventListener('click', () => {
                    // Toggle Active state
                    pills.forEach(p => p.classList.remove('active'));
                    pill.classList.add('active');
                    
                    if (hidden) {
                        hidden.value = pill.getAttribute('data-tag');
                    }
                });
            });
        };

        handleTagsPills('tags-pill-matrix-create', 'lead-tags-hidden');
        handleTagsPills('tags-pill-matrix-modal', 'modal-tags-hidden');

        // Not Required checkbox listener
        const notReqCheckbox = document.getElementById('not-required-checkbox');
        const modalForm = document.querySelector('#followup-UX-wizard-modal form');
        if (notReqCheckbox) {
            notReqCheckbox.addEventListener('change', () => {
                const inputsToDisable = modalForm.querySelectorAll('select:not([name="group_name"]), input[type="date"], input[type="time"], input[type="text"]:not([name="address"]):not([name="source"]):not([name="contact_person"]), textarea');
                inputsToDisable.forEach(input => {
                    if (notReqCheckbox.checked) {
                        input.setAttribute('disabled', 'true');
                        input.style.opacity = '0.5';
                    } else {
                        input.removeAttribute('disabled');
                        input.style.opacity = '1';
                    }
                });
            });
        }
    });
</script>
