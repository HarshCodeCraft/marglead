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
    $assigned_to_raw = $_POST['assigned_to'] ?? '';
    $assigned_to = is_array($assigned_to_raw) ? implode(', ', array_filter(array_map('trim', $assigned_to_raw))) : trim($assigned_to_raw);
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
            
            // Update Lead profile with modal inputs & assigned_by
            $existingAssignedTo = $pdo->query("SELECT assigned_to, assigned_by FROM leads WHERE id = " . $pdo->quote($lead_id))->fetch(PDO::FETCH_ASSOC);
            $newAssignedBy = $existingAssignedTo['assigned_by'] ?? '';
            if (empty($newAssignedBy) || ($existingAssignedTo && $existingAssignedTo['assigned_to'] !== $assigned_to)) {
                $newAssignedBy = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
            }
            $stmt = $pdo->prepare("UPDATE leads SET address = ?, tags = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ?, assigned_to = ?, assigned_by = ? WHERE id = ?");
            $stmt->execute([$address, $tags, $source, $enq_for, $contact_person, $remark, $assigned_to, $newAssignedBy, $lead_id]);

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
    $assigned_to_raw = $_POST['assigned_to'] ?? '';
    $assigned_to = is_array($assigned_to_raw) ? implode(', ', array_filter(array_map('trim', $assigned_to_raw))) : trim($assigned_to_raw);
    $group_name = $_POST['group_name'] ?? '';
    $tags = $_POST['tags'] ?? '';
    $reminder_date = $_POST['reminder_date'] ?? '';
    $reminder_time = $_POST['reminder_time'] ?? '';
    $address = $_POST['address'] ?? '';
    $source = $_POST['source'] ?? '';
    $enq_for = $_POST['enq_for'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $remark = $_POST['remark'] ?? '';

    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (empty($phone) || (strlen($clean_phone) !== 10 && strlen($clean_phone) !== 12)) {
        $message = "<strong>Invalid Contact Phone!</strong> Phone number must be exactly 10 or 12 digits (numeric only).";
        $message_type = "danger";
    } elseif ($db_connected && $pdo) {
        try {
            if ($isEdit) {
                // Update existing lead details
                $existingAssignedTo = $pdo->query("SELECT assigned_to, assigned_by FROM leads WHERE id = " . $pdo->quote($leadId))->fetch(PDO::FETCH_ASSOC);
                $newAssignedBy = $existingAssignedTo['assigned_by'] ?? '';
                if (empty($newAssignedBy) || ($existingAssignedTo && $existingAssignedTo['assigned_to'] !== $assigned_to)) {
                    $newAssignedBy = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                }
                $stmt = $pdo->prepare("UPDATE leads SET name = ?, company = ?, email = ?, phone = ?, address = ?, source = ?, tags = ?, assigned_to = ?, assigned_by = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $stmt->execute([$name, $group_name, $email, $phone, $address, $source, $tags, $assigned_to, $newAssignedBy, $enq_for, $contact_person, $remark, $leadId]);
                
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
                // Check if phone number already exists before inserting new lead
                $confirm_duplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] === '1';
                $show_duplicate_modal = null;

                if (!$confirm_duplicate && !empty($phone)) {
                    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                    $chkDupStmt = $pdo->prepare("SELECT id, name, company, phone, status, assigned_to FROM leads WHERE (REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ? OR phone = ?) ORDER BY id DESC LIMIT 1");
                    $chkDupStmt->execute(['%' . $clean_phone . '%', $phone]);
                    $dupFound = $chkDupStmt->fetch(PDO::FETCH_ASSOC);

                    if ($dupFound) {
                        $message = "<strong>Duplicate Contact Phone Detected!</strong> A lead (<strong>" . htmlspecialchars($dupFound['name']) . "</strong> - ID: <strong>" . htmlspecialchars($dupFound['id']) . "</strong>) already exists with phone <strong>" . htmlspecialchars($dupFound['phone']) . "</strong>.";
                        $message_type = "warning";
                        $show_duplicate_modal = $dupFound;
                    }
                }

                if (empty($show_duplicate_modal)) {
                    // Register a new lead profile
                    $newId = 'LD-' . rand(1000, 9999);
                    $assigned_by = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
                    
                    $stmt = $pdo->prepare("INSERT INTO leads (id, name, company, email, phone, address, source, tags, assigned_to, assigned_by, enq_for, contact_person, remarks, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')");
                    $stmt->execute([$newId, $name, $group_name, $email, $phone, $address, $source, $tags, $assigned_to, $assigned_by, $enq_for, $contact_person, $remark]);
                    
                    // Add activity log
                    $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead file registered')");
                    $log->execute([$newId, $_SESSION['user_name'] ?? 'System User']);
                    
                    // Insert notification for the assigned representative
                    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, role, title, message, link, type) VALUES ((SELECT id FROM users WHERE name = ? LIMIT 1), NULL, 'New Lead Assigned', ?, 'index.php?page=leads', 'info')");
                    $notifMsg = "Lead \"" . ($group_name ?: $name) . "\" has been assigned to you.";
                    $notifStmt->execute([$assigned_to, $notifMsg]);
                    
                    // Insert notification for the admin
                    $adminNotifStmt = $pdo->prepare("INSERT INTO notifications (role, title, message, link, type) VALUES ('Admin', 'New Lead Registered', ?, 'index.php?page=leads', 'success')");
                    $adminNotifMsg = "New lead \"" . ($group_name ?: $name) . "\" registered and assigned to " . $assigned_to;
                    $adminNotifStmt->execute([$adminNotifMsg]);
                    
                    // Seed a follow-up directly if configured
                    if (!empty($reminder_date)) {
                        $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                        $fup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, status) VALUES (?, 'Call', ?, ?, ?, 'pending')");
                        $fup->execute([$newId, $scheduled_at, $remark, $assigned_to]);
                    }
                    
                    // Redirect directly to leads directory after lead creation
                    header("Location: index.php?page=leads");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $message = "Database execution failure: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        // Redirect to directory
        header("Location: index.php?page=leads");
        exit;
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
        <input type="hidden" name="confirm_duplicate" id="confirm-duplicate-input" value="0">
        
        <!-- Section 1: Customer Details -->
        <div>
            <h3 class="form-section-title">
                <i data-lucide="user" style="width: 18px; height: 18px;"></i>
                <span>Customer Information</span>
            </h3>
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Firm Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="name" class="form-control form-control-focus" placeholder="E.g. NEW ASHIRWAD MEDICAL STORE" required value="<?php echo htmlspecialchars($editLead['name'] ?? ''); ?>">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-focus" placeholder="E.g. Dheerendra Vyas" value="<?php echo htmlspecialchars($editLead['contact_person'] ?? ''); ?>">
                </div>
                <div class="form-group m-0" style="position: relative;">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Contact Phone <span style="color: var(--danger);">*</span></label>
                    <input type="tel" name="phone" id="lead-phone-input" class="form-control form-control-focus" placeholder="E.g. 9876543210 or 919876543210" required maxlength="12" pattern="[0-9]{10}|[0-9]{12}" title="Contact Phone must be exactly 10 or 12 digits" value="<?php echo htmlspecialchars($editLead['phone'] ?? ''); ?>">
                    <div id="phone-dup-inline-alert" class="hidden mt-2 text-xs" style="display: none; flex-direction: column; gap: 0.5rem; color: var(--danger); background: var(--danger-light); padding: 0.65rem 0.85rem; border-radius: 6px; border: 1px solid var(--danger); transition: all 0.2s ease;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                            <span style="display: flex; align-items: center; gap: 0.35rem; font-weight: 600; flex: 1; min-width: 200px;">
                                <i data-lucide="alert-circle" style="width: 15px; height: 15px; flex-shrink: 0;"></i>
                                <span id="dup-inline-summary-text">Contact number exists: <strong id="dup-inline-lead-info">BALA JI MEDICAL STORE (LD-3182)</strong></span>
                            </span>
                            <button type="button" id="btn-phone-view-details" style="background: none; border: none; padding: 0; color: inherit; text-decoration: underline; font-weight: 700; cursor: pointer; font-size: 0.75rem; flex-shrink: 0;">
                                View Details
                            </button>
                        </div>
                        <div id="dup-inline-actions" style="display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; border-top: 1px dashed rgba(220, 38, 38, 0.3); padding-top: 0.4rem; margin-top: 0.25rem;">
                            <span style="font-weight: 600; color: var(--text-main); font-size: 0.75rem; margin-right: auto;">Continue with this number?</span>
                            <button type="button" id="btn-inline-dup-no" class="btn text-xs" style="padding: 0.25rem 0.75rem; font-size: 0.75rem; height: auto; border: 1px solid var(--danger); color: var(--danger); background: transparent; font-weight: 700; border-radius: 4px; cursor: pointer;">
                                No
                            </button>
                            <button type="button" id="btn-inline-dup-yes" class="btn text-xs" style="padding: 0.25rem 0.75rem; font-size: 0.75rem; height: auto; background-color: var(--danger); border: 1px solid var(--danger); color: #fff; font-weight: 700; border-radius: 4px; cursor: pointer;">
                                Yes
                            </button>
                        </div>
                    </div>
                </div>
                <div class="form-group m-0">
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
                <div class="form-group m-0" style="grid-column: span 2;">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Assign to Employee(s) (Select Multiple)</label>
                    <div class="employee-select-grid mb-1" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 0.5rem; max-height: 140px; overflow-y: auto; padding: 0.6rem; background: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm);">
                        <?php 
                        $assigned_array = array_map('trim', explode(',', $editLead['assigned_to'] ?? ''));
                        foreach ($assigned_operators as $op): 
                            $isChecked = in_array($op, $assigned_array);
                        ?>
                            <label class="flex align-center gap-2 text-xs font-semibold pointer" style="padding: 0.35rem 0.6rem; border-radius: 4px; background: var(--bg-card); border: 1px solid var(--border-color); user-select: none;">
                                <input type="checkbox" name="assigned_to[]" value="<?php echo htmlspecialchars($op); ?>" <?php echo $isChecked ? 'checked' : ''; ?> style="accent-color: var(--primary); width: 14px; height: 14px;">
                                <span><?php echo htmlspecialchars($op); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="text-xs text-muted" style="font-size: 0.7rem;">Check one or more employees to work on this lead as a team.</span>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-muted);">Assigned By (Read-Only)</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($editLead['assigned_by'] ?? ''); ?>" placeholder="Auto-set on assignment" readonly disabled style="background-color: var(--bg-hover); opacity: 0.85; cursor: not-allowed; font-weight: 600;">
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
                    <div class="flex align-center justify-between mb-1">
                        <label class="form-label text-xs font-semibold m-0" style="color: var(--text-main);">Address / Pin Code</label>
                        <span id="pincode-fetch-status" class="text-xs text-muted" style="font-size: 0.75rem; display: none;">
                            <i data-lucide="map-pin" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; color: var(--primary);"></i>
                            <span id="pincode-status-text">Fetching area...</span>
                        </span>
                    </div>
                    <div style="position: relative;">
                        <input type="text" name="address" id="lead-address-input" class="form-control form-control-focus" placeholder="E.g. 285204 or 285204 - Kalpi, Jalaun" value="<?php echo htmlspecialchars($editLead['address'] ?? ''); ?>">
                    </div>
                    
                    <!-- Pincode Locality / Area Results Box -->
                    <div id="pincode-area-container" class="mt-2" style="display: none; background: rgba(59, 130, 246, 0.06); border: 1px solid rgba(59, 130, 246, 0.25); padding: 0.65rem 0.85rem; border-radius: 8px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; border-bottom: 1px dashed rgba(59, 130, 246, 0.25); padding-bottom: 0.35rem; margin-bottom: 0.35rem;">
                            <span style="font-size: 0.75rem; font-weight: 700; color: var(--primary); display: inline-flex; align-items: center; gap: 4px;">
                                <i data-lucide="map-pin" style="width: 13px; height: 13px;"></i>
                                <span id="pincode-num-title">PIN Code Details:</span>
                            </span>
                            <span id="pincode-district-state-text" style="font-size: 0.75rem; font-weight: 600; color: var(--text-main);"></span>
                        </div>
                        <div style="font-size: 0.725rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                            Select Area / Locality to auto-fill address:
                        </div>
                        <div id="pincode-locality-chips" class="flex flex-wrap gap-1"></div>
                    </div>
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
                    <?php 
                    $cur_src = $editLead['source'] ?? 'Website';
                    $src_options = ['Website', 'Google Ads', 'Cold Calls', 'Referrals', 'Exhibitions', 'HO', 'Office', 'Imported'];
                    if (!empty($cur_src) && !in_array($cur_src, $src_options)) {
                        $src_options[] = $cur_src;
                    }
                    ?>
                    <select name="source" class="form-control form-control-focus">
                        <?php foreach ($src_options as $so): ?>
                            <option value="<?php echo htmlspecialchars($so); ?>" <?php echo ($cur_src === $so) ? 'selected' : ''; ?>><?php echo htmlspecialchars($so); ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <!-- <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-focus" placeholder="E.g. Dheerendra Vyas" value="<?php echo htmlspecialchars($editLead['contact_person'] ?? ''); ?>">
                </div> -->
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
                    <select name="source" class="form-control">
                        <option value="Website">Website</option>
                        <option value="Google Ads">Google Ads</option>
                        <option value="Cold Calls">Cold Calls</option>
                        <option value="Referrals">Referrals</option>
                        <option value="Exhibitions">Exhibitions</option>
                        <option value="HO">HO</option>
                        <option value="Office">Office</option>
                        <option value="Imported">Imported</option>
                    </select>
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

<!-- Duplicate Phone Warning Mini Modal -->
<div id="duplicate-phone-modal" class="modal-overlay hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px);">
    <div class="card p-5" style="width: 100%; max-width: 460px; border-radius: var(--border-radius-md); border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); box-shadow: var(--shadow-lg); animation: scaleUp 0.25s ease-out;">
        <div class="flex align-center gap-3 mb-3 pb-3" style="border-bottom: 1px solid var(--border-color);">
            <div style="background-color: var(--warning-light); color: var(--warning); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="alert-triangle" style="width: 22px; height: 22px;"></i>
            </div>
            <div>
                <h4 class="font-bold text-sm m-0" style="color: var(--warning); font-family: var(--font-heading);">Duplicate Phone Number Detected!</h4>
                <p class="text-xs text-muted m-0">A lead with this contact number already exists</p>
            </div>
        </div>

        <div class="mb-3" style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 0.75rem;">
            <table class="text-xs" style="width: 100%; border-collapse: collapse; line-height: 1.6;">
                <tr>
                    <td class="font-semibold text-muted" style="width: 38%;">Lead ID:</td>
                    <td class="font-bold" id="dup-lead-id" style="color: var(--primary);">-</td>
                </tr>
                <tr>
                    <td class="font-semibold text-muted">Customer Name:</td>
                    <td class="font-bold text-main" id="dup-lead-name">-</td>
                </tr>
                <tr>
                    <td class="font-semibold text-muted">Company / Group:</td>
                    <td id="dup-lead-company">-</td>
                </tr>
                <tr>
                    <td class="font-semibold text-muted">Contact Phone:</td>
                    <td class="font-bold" id="dup-lead-phone">-</td>
                </tr>
                <tr>
                    <td class="font-semibold text-muted">Assigned Exec:</td>
                    <td id="dup-lead-assigned">-</td>
                </tr>
                <tr>
                    <td class="font-semibold text-muted">Current Status:</td>
                    <td id="dup-lead-status">-</td>
                </tr>
            </table>
        </div>

        <p class="text-xs text-muted mb-4" style="line-height: 1.4; margin: 0 0 1rem 0;">
            Do you want to <strong>continue</strong> filling this form anyway, or <strong>clear inputs</strong> to re-enter details?
        </p>

        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-secondary text-xs" id="btn-dup-clear" style="padding: 0.5rem 1rem;">
                <i data-lucide="refresh-cw" style="width: 13px; height: 13px;"></i> No, Clear Inputs
            </button>
            <button type="button" class="btn btn-primary text-xs" id="btn-dup-continue" style="padding: 0.5rem 1.25rem;">
                <i data-lucide="check" style="width: 13px; height: 13px;"></i> Yes, Continue
            </button>
        </div>
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
                const inputsToDisable = modalForm.querySelectorAll('select:not([name="group_name"]):not([name="source"]), input[type="date"], input[type="time"], input[type="text"]:not([name="address"]):not([name="source"]):not([name="contact_person"]), textarea');
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

        // Contact Phone Duplication Checker
        const phoneInput = document.getElementById('lead-phone-input');
        const dupModal = document.getElementById('duplicate-phone-modal');
        const createForm = document.getElementById('create-lead-form');
        const confirmInput = document.getElementById('confirm-duplicate-input');
        const inlineAlert = document.getElementById('phone-dup-inline-alert');
        const viewDetailsBtn = document.getElementById('btn-phone-view-details');
        const isEditMode = <?php echo json_encode($isEdit); ?>;
        const currentLeadId = <?php echo json_encode($leadId); ?>;

        let duplicateLeadData = null;
        let userConfirmedDuplicate = false;

        const showModalWithData = (l) => {
            if (!l) return;
            duplicateLeadData = l;
            if (document.getElementById('dup-lead-id')) document.getElementById('dup-lead-id').textContent = l.id || '-';
            if (document.getElementById('dup-lead-name')) document.getElementById('dup-lead-name').textContent = l.name || '-';
            if (document.getElementById('dup-lead-company')) document.getElementById('dup-lead-company').textContent = l.company || '-';
            if (document.getElementById('dup-lead-phone')) document.getElementById('dup-lead-phone').textContent = l.phone || '-';
            if (document.getElementById('dup-lead-assigned')) document.getElementById('dup-lead-assigned').textContent = l.assigned_to || 'Unassigned';
            if (document.getElementById('dup-lead-status')) document.getElementById('dup-lead-status').textContent = (l.status || 'New').toUpperCase();

            const targetModal = document.getElementById('duplicate-phone-modal');
            if (targetModal) {
                targetModal.classList.remove('hidden');
                targetModal.style.setProperty('display', 'flex', 'important');
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        };

        const hideModal = () => {
            const targetModal = document.getElementById('duplicate-phone-modal');
            if (targetModal) {
                targetModal.classList.add('hidden');
                targetModal.style.setProperty('display', 'none', 'important');
            }
        };

        const escapeHtml = (str) => {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const showInlineAlert = (l, isConfirmed = false) => {
            duplicateLeadData = l;
            const inlineAlertBox = document.getElementById('phone-dup-inline-alert');
            const summaryText = document.getElementById('dup-inline-summary-text');
            const phoneInputEl = document.getElementById('lead-phone-input');

            if (summaryText && l) {
                const displayName = l.name || l.company || 'Lead';
                summaryText.innerHTML = `Contact number exists: <strong style="font-weight: 700;">${escapeHtml(displayName)} (${escapeHtml(l.id || '')})</strong>`;
            }

            if (inlineAlertBox) {
                inlineAlertBox.style.setProperty('display', 'flex', 'important');
                inlineAlertBox.classList.remove('hidden');

                const actionsDiv = document.getElementById('dup-inline-actions');
                const viewDetailsBtn = document.getElementById('btn-phone-view-details');

                if (isConfirmed || userConfirmedDuplicate) {
                    inlineAlertBox.style.borderColor = 'var(--success, #10b981)';
                    inlineAlertBox.style.background = 'rgba(16, 185, 129, 0.12)';
                    inlineAlertBox.style.color = '#047857';
                    if (viewDetailsBtn) viewDetailsBtn.style.color = '#047857';

                    if (actionsDiv) {
                        actionsDiv.style.borderTopColor = 'rgba(16, 185, 129, 0.3)';
                        actionsDiv.innerHTML = `
                            <span style="font-weight: 600; color: #047857; font-size: 0.75rem; margin-right: auto; display: flex; align-items: center; gap: 0.35rem;">
                                <i data-lucide="check-circle" style="width: 14px; height: 14px;"></i> Duplicate confirmed (Proceeding)
                            </span>
                            <button type="button" id="btn-inline-dup-change" class="btn text-xs" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; height: auto; background: transparent; border: 1px solid #047857; color: #047857; border-radius: 4px; cursor: pointer; font-weight: 600;">
                                Change
                            </button>
                        `;
                    }
                } else {
                    inlineAlertBox.style.borderColor = 'var(--danger)';
                    inlineAlertBox.style.background = 'var(--danger-light)';
                    inlineAlertBox.style.color = 'var(--danger)';
                    if (viewDetailsBtn) viewDetailsBtn.style.color = 'var(--danger)';

                    if (actionsDiv) {
                        actionsDiv.style.borderTopColor = 'rgba(220, 38, 38, 0.3)';
                        actionsDiv.innerHTML = `
                            <span style="font-weight: 600; color: var(--text-main); font-size: 0.75rem; margin-right: auto;">Continue with this number?</span>
                            <button type="button" id="btn-inline-dup-no" class="btn text-xs" style="padding: 0.25rem 0.75rem; font-size: 0.75rem; height: auto; border: 1px solid var(--danger); color: var(--danger); background: transparent; font-weight: 700; border-radius: 4px; cursor: pointer;">
                                No
                            </button>
                            <button type="button" id="btn-inline-dup-yes" class="btn text-xs" style="padding: 0.25rem 0.75rem; font-size: 0.75rem; height: auto; background-color: var(--danger); border: 1px solid var(--danger); color: #fff; font-weight: 700; border-radius: 4px; cursor: pointer;">
                                Yes
                            </button>
                        `;
                    }
                }
            }

            if (phoneInputEl) {
                phoneInputEl.style.borderColor = (isConfirmed || userConfirmedDuplicate) ? 'var(--success, #10b981)' : 'var(--danger)';
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        };

        const hideInlineAlert = () => {
            duplicateLeadData = null;
            const inlineAlertBox = document.getElementById('phone-dup-inline-alert');
            const phoneInputEl = document.getElementById('lead-phone-input');
            if (inlineAlertBox) {
                inlineAlertBox.style.setProperty('display', 'none', 'important');
                inlineAlertBox.classList.add('hidden');
            }
            if (phoneInputEl) {
                phoneInputEl.style.borderColor = '';
            }
        };

        if (phoneInput) {
            let lastCheckedPhone = '';

            const checkPhoneDup = (callback) => {
                const phoneVal = phoneInput.value.trim();
                const cleanPhone = phoneVal.replace(/[^0-9]/g, '');

                if (cleanPhone.length >= 7) {
                    if (cleanPhone === lastCheckedPhone && duplicateLeadData) {
                        showInlineAlert(duplicateLeadData, userConfirmedDuplicate);
                        if (typeof callback === 'function') callback(true);
                        return;
                    }

                    lastCheckedPhone = cleanPhone;
                    userConfirmedDuplicate = false;
                    if (confirmInput) confirmInput.value = '0';

                    let url = 'index.php?action=check_phone&phone=' + encodeURIComponent(phoneVal);
                    if (isEditMode && currentLeadId) {
                        url += '&exclude_id=' + encodeURIComponent(currentLeadId);
                    }

                    fetch(url)
                        .then(res => res.json())
                        .then(data => {
                            if (data.exists && data.lead) {
                                showInlineAlert(data.lead, false);
                                if (typeof callback === 'function') callback(true);
                            } else {
                                hideInlineAlert();
                                if (typeof callback === 'function') callback(false);
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            if (typeof callback === 'function') callback(false);
                        });
                } else {
                    hideInlineAlert();
                    if (typeof callback === 'function') callback(false);
                }
            };

            const enforcePhoneDigits = function() {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 12);
                const len = this.value.length;
                if (len > 0 && len !== 10 && len !== 12) {
                    this.setCustomValidity('Contact Phone must be exactly 10 or 12 digits.');
                } else {
                    this.setCustomValidity('');
                }
            };

            phoneInput.addEventListener('input', function(e) {
                enforcePhoneDigits.call(this);
                checkPhoneDup(e);
            });
            phoneInput.addEventListener('blur', checkPhoneDup);
            phoneInput.addEventListener('change', checkPhoneDup);

            // Global Click Delegation for inline alert buttons and View Details
            document.addEventListener('click', function(e) {
                // View Details button
                const viewBtn = e.target.closest('#btn-phone-view-details');
                if (viewBtn) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (duplicateLeadData) {
                        showModalWithData(duplicateLeadData);
                    } else {
                        const phoneVal = phoneInput.value.trim();
                        if (phoneVal) {
                            fetch('index.php?action=check_phone&phone=' + encodeURIComponent(phoneVal))
                                .then(res => res.json())
                                .then(data => {
                                    if (data.exists && data.lead) {
                                        showModalWithData(data.lead);
                                    }
                                });
                        }
                    }
                    return;
                }

                // Inline Yes button
                const yesBtn = e.target.closest('#btn-inline-dup-yes');
                if (yesBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    userConfirmedDuplicate = true;
                    if (confirmInput) confirmInput.value = '1';
                    if (duplicateLeadData) {
                        showInlineAlert(duplicateLeadData, true);
                    }
                    return;
                }

                // Inline No button
                const noBtn = e.target.closest('#btn-inline-dup-no');
                if (noBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    userConfirmedDuplicate = false;
                    if (confirmInput) confirmInput.value = '0';
                    hideInlineAlert();
                    lastCheckedPhone = '';
                    if (phoneInput) {
                        phoneInput.value = '';
                        phoneInput.focus();
                    }
                    return;
                }

                // Inline Change button
                const changeBtn = e.target.closest('#btn-inline-dup-change');
                if (changeBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    userConfirmedDuplicate = false;
                    if (confirmInput) confirmInput.value = '0';
                    if (duplicateLeadData) {
                        showInlineAlert(duplicateLeadData, false);
                    }
                    return;
                }
            });

            // Intercept Form Submit Event to prevent bypassing
            if (createForm) {
                createForm.addEventListener('submit', function(e) {
                    const phoneVal = phoneInput.value.trim();
                    const cleanPhone = phoneVal.replace(/[^0-9]/g, '');

                    if (cleanPhone.length !== 10 && cleanPhone.length !== 12) {
                        e.preventDefault();
                        e.stopPropagation();
                        phoneInput.setCustomValidity('Contact Phone must be exactly 10 or 12 digits.');
                        phoneInput.reportValidity();
                        return false;
                    } else {
                        phoneInput.setCustomValidity('');
                    }

                    if (isEditMode) return true;
                    if (userConfirmedDuplicate) return true;

                    if (cleanPhone.length >= 7) {
                        if (duplicateLeadData && !userConfirmedDuplicate) {
                            e.preventDefault();
                            e.stopPropagation();
                            showModalWithData(duplicateLeadData);
                            return false;
                        }
                    }
                });
            }

            // Modal Button Action: Yes, Continue
            document.getElementById('btn-dup-continue')?.addEventListener('click', (e) => {
                e.preventDefault();
                userConfirmedDuplicate = true;
                if (confirmInput) confirmInput.value = '1';
                if (duplicateLeadData) {
                    showInlineAlert(duplicateLeadData, true);
                }
                hideModal();
            });

            // Modal Button Action: No, Clear Inputs
            document.getElementById('btn-dup-clear')?.addEventListener('click', (e) => {
                e.preventDefault();
                userConfirmedDuplicate = false;
                hideInlineAlert();
                lastCheckedPhone = '';
                if (confirmInput) confirmInput.value = '0';
                hideModal();
                if (phoneInput) {
                    phoneInput.value = '';
                    phoneInput.focus();
                }
            });
        }

        <?php if (!empty($show_duplicate_modal)): ?>
        // Trigger inline alert and modal automatically if server-side PHP caught duplicate submit
        showInlineAlert(<?php echo json_encode($show_duplicate_modal); ?>, false);
        showModalWithData(<?php echo json_encode($show_duplicate_modal); ?>);
        <?php endif; ?>

        // PIN Code Area Fetching Logic
        const addressInput = document.getElementById('lead-address-input');
        const pincodeContainer = document.getElementById('pincode-area-container');
        const pincodeStatus = document.getElementById('pincode-fetch-status');
        const pincodeStatusText = document.getElementById('pincode-status-text');
        const pincodeNumTitle = document.getElementById('pincode-num-title');
        const pincodeDistrictStateText = document.getElementById('pincode-district-state-text');
        const pincodeLocalityChips = document.getElementById('pincode-locality-chips');

        let lastFetchedPincode = '';

        const fetchAreaByPincode = async (pincode) => {
            if (pincode === lastFetchedPincode) return;
            lastFetchedPincode = pincode;

            if (pincodeStatus) pincodeStatus.style.display = 'inline-block';
            if (pincodeStatusText) pincodeStatusText.innerText = `Searching PIN ${pincode}...`;

            try {
                let postOffices = [];
                let district = '';
                let state = '';

                // Try India Post API
                try {
                    let response = await fetch(`https://api.postalpincode.in/pincode/${pincode}`);
                    let data = await response.json();

                    if (Array.isArray(data) && data[0] && data[0].Status === 'Success' && Array.isArray(data[0].PostOffice)) {
                        postOffices = data[0].PostOffice;
                        district = postOffices[0].District || '';
                        state = postOffices[0].State || '';
                    }
                } catch(e1) {}

                // Fallback to Zippopotam API if India Post returns empty or fails
                if (postOffices.length === 0) {
                    try {
                        let res2 = await fetch(`https://api.zippopotam.us/in/${pincode}`);
                        if (res2.ok) {
                            let data2 = await res2.json();
                            if (data2 && data2.places) {
                                postOffices = data2.places.map(p => ({
                                    Name: p['place name'],
                                    District: p['state abbreviation'] || '',
                                    State: p['state'] || ''
                                }));
                                district = data2.places[0]['state'] || '';
                                state = data2.country || 'India';
                            }
                        }
                    } catch(e2) {}
                }

                if (postOffices.length > 0) {
                    if (pincodeStatusText) pincodeStatusText.innerText = `Area Found for ${pincode}`;
                    if (pincodeNumTitle) pincodeNumTitle.innerText = `PIN ${pincode} (${postOffices.length} Area${postOffices.length > 1 ? 's' : ''}):`;
                    if (pincodeDistrictStateText) pincodeDistrictStateText.innerText = `${district}${district && state ? ', ' : ''}${state}`;

                    if (pincodeLocalityChips) {
                        pincodeLocalityChips.innerHTML = '';
                        const uniqueAreas = [...new Set(postOffices.map(po => po.Name))];
                        
                        uniqueAreas.forEach(areaName => {
                            const chip = document.createElement('button');
                            chip.type = 'button';
                            chip.className = 'btn text-xs';
                            chip.style.cssText = 'padding: 0.25rem 0.6rem; font-size: 0.725rem; background: var(--bg-card); border: 1px solid var(--primary); color: var(--primary); font-weight: 600; border-radius: 4px; cursor: pointer; transition: all 0.15s ease;';
                            chip.innerHTML = `<i data-lucide="map-pin" style="width: 11px; height: 11px; display: inline-block; vertical-align: middle;"></i> ${areaName}`;
                            
                            chip.addEventListener('mouseenter', () => {
                                chip.style.background = 'var(--primary)';
                                chip.style.color = '#ffffff';
                            });
                            chip.addEventListener('mouseleave', () => {
                                chip.style.background = 'var(--bg-card)';
                                chip.style.color = 'var(--primary)';
                            });

                            chip.addEventListener('click', (e) => {
                                e.preventDefault();
                                if (addressInput) {
                                    addressInput.value = `${pincode} - ${areaName}, ${district} (${state})`;
                                    addressInput.focus();
                                }
                            });

                            pincodeLocalityChips.appendChild(chip);
                        });
                    }

                    if (pincodeContainer) pincodeContainer.style.display = 'block';

                    // Auto-fill area name into address if current input is just the 6-digit PIN code
                    if (addressInput) {
                        const val = addressInput.value.trim();
                        if (val === pincode) {
                            const primaryArea = postOffices[0].Name;
                            addressInput.value = `${pincode} - ${primaryArea}, ${district} (${state})`;
                        }
                    }

                    if (typeof lucide !== 'undefined') lucide.createIcons();
                } else {
                    if (pincodeStatusText) pincodeStatusText.innerText = `No area found for PIN ${pincode}`;
                    if (pincodeContainer) pincodeContainer.style.display = 'none';
                }
            } catch (err) {
                if (pincodeStatusText) pincodeStatusText.innerText = `PIN Code search error`;
                if (pincodeContainer) pincodeContainer.style.display = 'none';
            }
        };

        if (addressInput) {
            const handlePincodeCheck = () => {
                const text = addressInput.value;
                const match = text.match(/\b(\d{6})\b/);
                if (match && match[1]) {
                    fetchAreaByPincode(match[1]);
                } else {
                    if (pincodeContainer) pincodeContainer.style.display = 'none';
                    if (pincodeStatus) pincodeStatus.style.display = 'none';
                    lastFetchedPincode = '';
                }
            };

            addressInput.addEventListener('input', handlePincodeCheck);
            addressInput.addEventListener('change', handlePincodeCheck);

            if (addressInput.value) {
                handlePincodeCheck();
            }
        }
    });
</script>
