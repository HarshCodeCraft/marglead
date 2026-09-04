<?php
/**
 * Marg ERP CRM - Customer Training Registry & Trainer Allocation
 * Schedule product trainers, verify training hours, checklist user certification assessments,
 * and track user readiness reports with Client Directory & CRM Leads auto-filling.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';

// Permission Check
if (!hasAccess('training', $user_role)) {
    header('Location: ../index.php?page=dashboard');
    exit;
}

$canCreate = hasActionAccess('can_create');
$canEdit = hasActionAccess('can_edit');
$canDelete = hasActionAccess('can_delete');

// --------------------------------------------------------------------------
// 1. Action Handlers (Create Training, Update Hours / Certification)
// --------------------------------------------------------------------------
$flash_msg = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_training' && $canCreate) {
        $lead_id = trim($_POST['lead_id'] ?? '');
        $customer = trim($_POST['customer_name'] ?? '');
        $trainer = trim($_POST['trainer'] ?? '');
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $mode = $_POST['mode'] ?? 'Online (Google Meet)';
        $total_hours = intval($_POST['total_hours'] ?? 6);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $product = trim($_POST['product'] ?? 'Marg ERP Pro');
        $renewal_date = !empty($_POST['renewal_date']) ? $_POST['renewal_date'] : null;
        $address = trim($_POST['address'] ?? '');
        $topics = trim($_POST['topics'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if (!empty($customer) && !empty($trainer) && !empty($scheduled_at)) {
            if ($db_connected && $pdo) {
                try {
                    $trId = 'TRN-' . rand(100, 999);
                    $stmtIns = $pdo->prepare("INSERT INTO training_sessions (id, lead_id, customer, trainer, scheduled_at, mode, hours_completed, total_hours, status, phone, email, product, renewal_date, address, topics, remarks) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'scheduled', ?, ?, ?, ?, ?, ?, ?)");
                    $stmtIns->execute([$trId, $lead_id, $customer, $trainer, $scheduled_at, $mode, $total_hours, $phone, $email, $product, $renewal_date, $address, $topics, $remarks]);

                    // Insert timeline log if lead_id present
                    if (!empty($lead_id) && str_starts_with($lead_id, 'LD-')) {
                        $tlStmt = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                        $tlStmt->execute([$lead_id, $user_name, "Scheduled {$total_hours}h product training ({$product}) with trainer {$trainer} on " . date('M d, Y h:i A', strtotime($scheduled_at))]);
                    }

                    $flash_msg = "Trainer allocated & session {$trId} scheduled for \"{$customer}\"!";
                    $flash_type = "success";
                } catch (PDOException $e) {
                    $flash_msg = "Error creating training allocation: " . $e->getMessage();
                    $flash_type = "danger";
                }
            }
        } else {
            $flash_msg = "Please provide Client Name, Trainer, and Scheduled Target Date.";
            $flash_type = "danger";
        }
    }
    elseif ($_POST['action'] === 'update_certification' && $canEdit) {
        $trId = trim($_POST['training_id'] ?? '');
        $hours_comp = intval($_POST['hours_completed'] ?? 0);
        $total_hrs = intval($_POST['total_hours'] ?? 6);
        $cert_status = $_POST['cert_status'] ?? 'active';

        if ($hours_comp >= $total_hrs) {
            $cert_status = 'certified';
        }

        if (!empty($trId) && $db_connected && $pdo) {
            try {
                $stmtUpd = $pdo->prepare("UPDATE training_sessions SET hours_completed = ?, status = ? WHERE id = ?");
                $stmtUpd->execute([$hours_comp, $cert_status, $trId]);
                $flash_msg = "Training session {$trId} progress & certification updated!";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_msg = "Error updating certification: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// --------------------------------------------------------------------------
// 2. Fetch Clients & Leads for Autocomplete (Permission Scoped)
// --------------------------------------------------------------------------
$db_clients = [];
$db_clients[] = [
    'id' => 'TRIAL-NEW',
    'name' => '⚡ Trial Version Client (New Trial Prospect)',
    'phone' => '+91 98000 11122',
    'email' => 'prospect@trialclient.com',
    'product' => 'Marg ERP Trial Version',
    'renewal_date' => date('Y-m-d', strtotime('+30 days')),
    'address' => 'Trial Version License Workspace Premises',
    'source' => 'Trial Prospect'
];

if ($db_connected && $pdo) {
    try {
        $dirStmt = $pdo->query("SELECT customer_id as id, party_name as name, mobile as phone, email, software_type as product, due_on as renewal_date, address, 'Client Directory' as source FROM client_directory WHERE party_name IS NOT NULL AND party_name != '' ORDER BY id DESC LIMIT 500");
        while ($row = $dirStmt->fetch(PDO::FETCH_ASSOC)) {
            $db_clients[] = $row;
        }
    } catch (PDOException $e) { }

    try {
        $leadsStmt = $pdo->query("SELECT id, name, phone, email, enq_for as product, created_at as renewal_date, address, 'CRM Lead' as source FROM leads WHERE name IS NOT NULL AND name != '' ORDER BY created_at DESC LIMIT 500");
        while ($row = $leadsStmt->fetch(PDO::FETCH_ASSOC)) {
            $db_clients[] = $row;
        }
    } catch (PDOException $e) { }
}
$clients_json = json_encode($db_clients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

// --------------------------------------------------------------------------
// 3. Fetch Operators / Trainers List
// --------------------------------------------------------------------------
$trainers = ['Prakash Raj (Senior Trainer)', 'Sonal Mehta (Technical Lead)', 'Vikas Patel (Installation Lead)', 'Amit Sen (Product Specialist)'];
if ($db_connected && $pdo) {
    try {
        $uStmt = $pdo->query("SELECT name, role FROM users WHERE status = 'Active' ORDER BY name ASC");
        $uList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($uList)) {
            $trainers = [];
            foreach ($uList as $u) {
                $trainers[] = $u['name'] . ' (' . $u['role'] . ')';
            }
        }
    } catch (PDOException $e) { }
}

// --------------------------------------------------------------------------
// 4. Fetch Training Sessions Data & Apply Search Filters
// --------------------------------------------------------------------------
$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$trainings = [];

if ($db_connected && $pdo) {
    try {
        $sql = "SELECT * FROM training_sessions WHERE 1=1";
        $params = [];

        if (!empty($search_query)) {
            $sql .= " AND (id LIKE ? OR customer LIKE ? OR trainer LIKE ? OR phone LIKE ? OR product LIKE ?)";
            $sq = '%' . $search_query . '%';
            $params = array_fill(0, 5, $sq);
        }

        if (!empty($status_filter)) {
            $sql .= " AND status = ?";
            $params[] = $status_filter;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmtT = $pdo->prepare($sql);
        $stmtT->execute($params);
        $trainings = $stmtT->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trainings = [];
    }
}

// KPI Counts
$totalCount = count($trainings);
$scheduledCount = 0;
$inProgressCount = 0;
$certifiedCount = 0;

foreach ($trainings as $t) {
    if ($t['status'] === 'certified') $certifiedCount++;
    elseif ($t['status'] === 'active') $inProgressCount++;
    else $scheduledCount++;
}
?>

<div class="trainings-container">
    <!-- Flash Notification -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> mb-6 p-4 border-radius-md flex align-center gap-3" style="background: var(--<?php echo $flash_type; ?>-light); border: 1px solid var(--<?php echo $flash_type; ?>); color: var(--<?php echo $flash_type; ?>);">
            <i data-lucide="info" style="width: 20px; height: 20px;"></i>
            <span class="text-sm font-semibold"><?php echo htmlspecialchars($flash_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Customer Training Registry</h2>
            <p class="text-muted text-sm">Schedule product trainers, verify training hours, checklist user certification assessments, and track user readiness reports.</p>
        </div>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary text-sm flex align-center gap-2" style="padding: 0.65rem 1.25rem;" onclick="openTrainingAllocationModal()">
                <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                <span>Allocate Trainer</span>
            </button>
        <?php endif; ?>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid mb-6" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(59, 130, 246, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="graduation-cap" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Total Training Allocations</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading);"><?php echo $totalCount; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(6, 182, 212, 0.12); color: var(--info); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="calendar" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Scheduled Sessions</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--info);"><?php echo $scheduledCount; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(245, 124, 0, 0.12); color: var(--warning); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="clock" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">In-Progress Hours</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--warning);"><?php echo $inProgressCount; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card);">
            <div style="width: 48px; height: 48px; border-radius: var(--border-radius-md); background: rgba(52, 211, 153, 0.12); color: var(--success); display: flex; align-items: center; justify-content: center;">
                <i data-lucide="award" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <span class="text-xs text-muted font-bold block" style="text-transform: uppercase;">Certified Operators</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--success);"><?php echo $certifiedCount; ?></span>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card p-4 mb-6" style="border: 1px solid var(--border-color); background: var(--bg-card);">
        <form action="index.php" method="GET" class="grid" style="grid-template-columns: 2fr 1fr 1fr; gap: 1rem; align-items: end;">
            <input type="hidden" name="page" value="training">
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Search Training Registry</label>
                <div style="position: relative;">
                    <input type="text" name="search" class="form-control text-sm" placeholder="Client Name, ID, Trainer, Phone, Product..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 2.25rem;">
                    <i data-lucide="search" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted);"></i>
                </div>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Certification Status</label>
                <select name="status" class="form-control text-sm">
                    <option value="">All Statuses</option>
                    <option value="scheduled" <?php echo ($status_filter === 'scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>In-Progress</option>
                    <option value="certified" <?php echo ($status_filter === 'certified') ? 'selected' : ''; ?>>Certified Users</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary text-sm flex-1">Apply Filter</button>
                <?php if (!empty($search_query) || !empty($status_filter)): ?>
                    <a href="index.php?page=training" class="btn btn-secondary text-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Training Registry Table -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color); background: var(--bg-card);">
        <div class="table-responsive">
            <table class="w-full text-left" style="border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); background: var(--border-card);">
                        <th class="p-3 text-xs font-bold text-muted">TRAINING ID</th>
                        <th class="p-3 text-xs font-bold text-muted">CLIENT / CUSTOMER</th>
                        <th class="p-3 text-xs font-bold text-muted">ASSIGNED TRAINER</th>
                        <th class="p-3 text-xs font-bold text-muted">SCHEDULED DATE</th>
                        <th class="p-3 text-xs font-bold text-muted">TRAINING HOURS</th>
                        <th class="p-3 text-xs font-bold text-muted">CERTIFICATION STATUS</th>
                        <th class="p-3 text-xs font-bold text-muted text-right">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trainings)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-6 text-muted text-sm">No customer training sessions recorded. Click "Allocate Trainer" to schedule product training.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trainings as $tr): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td class="p-3 font-bold text-xs font-mono text-primary"><?php echo htmlspecialchars($tr['id']); ?></td>
                                <td class="p-3">
                                    <div class="flex flex-col">
                                        <span class="font-semibold text-sm" style="color: var(--text-main);"><?php echo htmlspecialchars($tr['customer']); ?></span>
                                        <div class="flex align-center gap-2 text-xs text-muted">
                                            <span class="font-mono">ID: <?php echo htmlspecialchars($tr['lead_id'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($tr['product'])): ?>
                                                <span class="badge text-xs" style="--badge-bg: var(--accent-light); --badge-color: var(--accent); font-size: 0.65rem;"><?php echo htmlspecialchars($tr['product']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3 text-sm font-semibold"><?php echo htmlspecialchars($tr['trainer']); ?></td>
                                <td class="p-3 text-sm font-mono"><?php echo !empty($tr['scheduled_at']) ? date('M d, Y h:i A', strtotime($tr['scheduled_at'])) : '-'; ?></td>
                                <td class="p-3 font-semibold text-xs font-mono"><?php echo $tr['hours_completed']; ?> / <?php echo $tr['total_hours']; ?> Hours</td>
                                <td class="p-3">
                                    <?php 
                                    if ($tr['status'] === 'certified') {
                                        echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success); font-weight: 700;">Certified Users</span>';
                                    } elseif ($tr['status'] === 'active') {
                                        echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning); font-weight: 700;">In-Progress</span>';
                                    } else {
                                        echo '<span class="badge" style="--badge-bg: var(--info-light); --badge-color: var(--info); font-weight: 700;">Scheduled</span>';
                                    }
                                    ?>
                                </td>
                                <td class="p-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" class="btn btn-secondary text-xs" onclick="openCertifyModal('<?php echo htmlspecialchars(addslashes($tr['id'])); ?>', '<?php echo htmlspecialchars(addslashes($tr['customer'])); ?>', <?php echo $tr['hours_completed']; ?>, <?php echo $tr['total_hours']; ?>, '<?php echo $tr['status']; ?>')">
                                            <i data-lucide="check-square" style="width: 14px; height: 14px; margin-right: 4px;"></i>
                                            <span>Certify / Update</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Schedule & Allocate Trainer Modal (With Client Directory / Leads Auto-Fill & Trial Version Option) -->
<div id="schedule-training-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 680px;">
        <div class="modal-header">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading);">Schedule & Allocate Trainer</h3>
                <span class="text-xs text-muted">Fetch client details from Client Directory database or CRM to allocate product trainer.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('schedule-training-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-4" action="index.php?page=training" method="POST" style="max-height: 540px; overflow-y: auto;">
            <input type="hidden" name="action" value="create_training">

            <!-- Section 1: Client Info Box -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <div class="flex align-center justify-between mb-3 border-bottom pb-2" style="border-bottom: 1px solid var(--border-color);">
                    <h4 class="text-xs font-bold uppercase m-0" style="color: var(--primary); letter-spacing: 0.05em;">Client Info</h4>
                    <span class="text-xs text-muted">Select client to auto-fill details</span>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    
                    <!-- Select Client Dropdown Picker & Search Input -->
                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-bold text-main flex justify-between align-center">
                            <span>Client Name *</span>
                            <span class="text-xs text-muted font-normal">Pick from list or search below to auto-fill</span>
                        </label>
                        <select id="training-client-select-picker" class="form-control text-xs font-semibold mb-2" onchange="onTrainingSelectPickerChange(this)" style="background-color: var(--bg-card); border-color: var(--primary);">
                            <!-- Populated on DOM load -->
                        </select>
                        <div style="position: relative;">
                            <input type="text" id="training-client-search-input" class="form-control text-xs font-semibold" placeholder="Type client name, ID, phone to filter..." autocomplete="off" oninput="filterTrainingClientDropdown()" onfocus="showTrainingClientDropdown()" style="background-color: var(--bg-card); border-color: var(--border-color); padding-right: 2rem;">
                            <i data-lucide="search" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                        </div>
                        
                        <!-- Auto-complete Search Results Pop-up -->
                        <div id="training-client-dropdown-menu" style="display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 999; max-height: 220px; overflow-y: auto; background-color: var(--bg-card); border: 2px solid var(--primary); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.6); margin-top: 4px;">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Client id</label>
                        <input type="text" name="lead_id" id="trn-client-id" class="form-control text-xs font-mono" readonly style="background-color: var(--bg-card); border-color: var(--border-color); opacity: 0.9;">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Mobile no.</label>
                        <input type="text" name="phone" id="trn-phone" class="form-control text-xs font-mono" placeholder="Mobile Number" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Email ID</label>
                        <input type="email" name="email" id="trn-email" class="form-control text-xs font-mono" placeholder="Email Address" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-bold text-main">Training for (Product)*</label>
                        <select name="product" id="trn-product" class="form-control text-xs font-semibold" required style="background-color: var(--bg-card); border-color: var(--border-color);">
                            <option value="Marg ERP Pro">Marg ERP Pro</option>
                            <option value="Marg ERP Basic">Marg ERP Basic</option>
                            <option value="Marg ERP Gold">Marg ERP Gold</option>
                            <option value="Marg Silver Edition">Marg Silver Edition</option>
                            <option value="Marg ERP Trial Version">Marg ERP Trial Version</option>
                            <option value="Marg Books">Marg Books</option>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Renewal date</label>
                        <input type="date" name="renewal_date" id="trn-renewal-date" class="form-control text-xs font-mono" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main">Address</label>
                        <textarea name="address" id="trn-address" class="form-control text-xs" rows="2" placeholder="Client premise address" style="background-color: var(--bg-card); border-color: var(--border-color);"></textarea>
                        <input type="hidden" name="customer_name" id="trn-customer-name" required>
                    </div>
                </div>
            </div>

            <!-- Section 2: Trainer & Training Parameters Box -->
            <div class="p-4" style="background-color: var(--bg-app); border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold uppercase m-0 mb-3" style="letter-spacing: 0.05em;">Trainer Allocation Parameters</h4>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Assigned Trainer / Engineer *</label>
                        <select name="trainer" class="form-control text-xs font-semibold" required style="background-color: var(--bg-card); border-color: var(--border-color);">
                            <?php foreach ($trainers as $trOpt): ?>
                                <option value="<?php echo htmlspecialchars($trOpt); ?>"><?php echo htmlspecialchars($trOpt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Scheduled Date & Time *</label>
                        <input type="datetime-local" name="scheduled_at" class="form-control text-xs font-mono" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 day 11:00')); ?>" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Training Hours Required</label>
                        <input type="number" name="total_hours" class="form-control text-xs font-mono" value="6" min="1" max="50" required style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold text-main">Training Mode</label>
                        <select name="mode" class="form-control text-xs" style="background-color: var(--bg-card); border-color: var(--border-color);">
                            <option value="Online (Google Meet)">Online (Google Meet / AnyDesk)</option>
                            <option value="On-Site">On-Site at Client Premises</option>
                            <option value="Self-Paced / Video">Self-Paced / Video Tutorial</option>
                        </select>
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main">Modules / Topics to Cover</label>
                        <input type="text" name="topics" class="form-control text-xs" placeholder="e.g. Billing, Barcode generation, GST GSTR-1, Inventory" value="Billing, Barcode Setup, GST Returns" style="background-color: var(--bg-card); border-color: var(--border-color);">
                    </div>

                    <div class="form-group m-0" style="grid-column: span 2;">
                        <label class="form-label text-xs font-semibold text-main">Instructions / Remarks</label>
                        <textarea name="remarks" class="form-control text-xs" rows="2" placeholder="Special training instructions..." style="background-color: var(--bg-card); border-color: var(--border-color);"></textarea>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('schedule-training-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs flex align-center gap-2">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Save Allocation & Dispatch Schedule</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Certify Users Assessment Checklist Modal -->
<div id="certify-users-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 500px;">
        <div class="modal-header">
            <div>
                <h3 class="m-0" style="font-family: var(--font-heading);">Certify Software Operators</h3>
                <span class="text-xs text-muted">Update training hours & certify user readiness.</span>
            </div>
            <button type="button" class="btn-icon" onclick="window.closeModal('certify-users-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>

        <form class="modal-body p-5 flex flex-col gap-4" action="index.php?page=training" method="POST">
            <input type="hidden" name="action" value="update_certification">
            <input type="hidden" name="training_id" id="certify-tr-id" value="">

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Client Business</label>
                <input type="text" id="certify-client-name" class="form-control text-xs font-bold" readonly style="background-color: var(--border-card);">
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Completed Hours</label>
                    <input type="number" name="hours_completed" id="certify-hours-comp" class="form-control text-xs font-mono" min="0" max="50" required>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Total Target Hours</label>
                    <input type="number" name="total_hours" id="certify-hours-total" class="form-control text-xs font-mono" min="1" max="50" required readonly style="background-color: var(--border-card);">
                </div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Certification Status</label>
                <select name="cert_status" id="certify-status-select" class="form-control text-xs font-semibold">
                    <option value="scheduled">Scheduled</option>
                    <option value="active">In-Progress</option>
                    <option value="certified">Certified Users (Passed Assessment)</option>
                </select>
            </div>

            <div class="p-3 border-radius-sm" style="background-color: var(--bg-app); border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold block mb-2" style="text-transform: uppercase;">Operator Assessment Checklist</h4>
                <div class="flex flex-col gap-2">
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" checked style="accent-color: var(--primary);">
                        <span>Operator understands daily billing & item configuration</span>
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" checked style="accent-color: var(--primary);">
                        <span>Operator understands barcode printing & batch expiries</span>
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" checked style="accent-color: var(--primary);">
                        <span>Operator understands GST GSTR-1 & GSTR-3B filings</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('certify-users-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs flex align-center gap-2">
                    <i data-lucide="award" style="width: 14px; height: 14px;"></i>
                    <span>Save Certificate Status</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const trainingClientsData = <?php echo $clients_json; ?>;

    function initTrainingClientPicker() {
        const picker = document.getElementById('training-client-select-picker');
        if (!picker) return;

        let optHtml = '<option value="">-- Select Client Database Record / Trial Version --</option>';
        trainingClientsData.forEach((c, idx) => {
            const label = c.name + (c.id ? ' (' + c.id + ')' : '') + (c.phone ? ' - ' + c.phone : '');
            optHtml += `<option value="${idx}">${escapeTrnText(label)}</option>`;
        });
        picker.innerHTML = optHtml;
    }

    function onTrainingSelectPickerChange(selectEl) {
        const idx = selectEl.value;
        if (idx !== '') {
            selectTrainingClientByIndex(parseInt(idx, 10));
        }
    }

    function showTrainingClientDropdown() {
        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu) {
            filterTrainingClientDropdown();
            menu.style.display = 'block';
        }
    }

    function filterTrainingClientDropdown() {
        const input = document.getElementById('training-client-search-input');
        const menu = document.getElementById('training-client-dropdown-menu');
        if (!input || !menu) return;

        const val = input.value.trim().toLowerCase();
        const trnCustName = document.getElementById('trn-customer-name');
        if (trnCustName) trnCustName.value = input.value;
        
        let html = '';
        const filtered = [];

        trainingClientsData.forEach((c, idx) => {
            if (
                !val ||
                (c.name && c.name.toString().toLowerCase().includes(val)) ||
                (c.id && c.id.toString().toLowerCase().includes(val)) ||
                (c.phone && c.phone.toString().toLowerCase().includes(val)) ||
                (c.email && c.email.toString().toLowerCase().includes(val))
            ) {
                filtered.push({ item: c, origIndex: idx });
            }
        });

        if (filtered.length === 0) {
            html = '<div style="padding: 10px; font-size: 12px; color: var(--text-muted); text-align: center;">No matching client found</div>';
        } else {
            filtered.slice(0, 60).forEach(f => {
                const c = f.item;
                const idx = f.origIndex;
                const badgeColor = c.id === 'TRIAL-NEW' ? 'var(--warning)' : (c.source === 'CRM Lead' ? 'var(--primary)' : 'var(--success)');
                html += `
                    <div style="padding: 10px 12px; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: background 0.15s;" 
                         onmouseover="this.style.background='var(--border-card)'" 
                         onmouseout="this.style.background='transparent'" 
                         onclick="selectTrainingClientByIndex(${idx})">
                        <div class="flex align-center justify-between">
                            <span class="font-bold text-xs" style="color: var(--text-main);">${escapeTrnText(c.name)}</span>
                            <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: ${badgeColor}; font-weight: 700; font-size: 10px;">${escapeTrnText(c.source || 'Client')}</span>
                        </div>
                        <div class="flex align-center gap-3 text-xs text-muted mt-1 font-mono" style="font-size: 11px;">
                            <span>ID: ${escapeTrnText(c.id || '-')}</span>
                            <span>Phone: ${escapeTrnText(c.phone || '-')}</span>
                            ${c.product ? `<span>Product: ${escapeTrnText(c.product)}</span>` : ''}
                        </div>
                    </div>
                `;
            });
        }

        menu.innerHTML = html;
        menu.style.display = 'block';
    }

    function selectTrainingClientByIndex(idx) {
        const c = trainingClientsData[idx];
        if (!c) return;

        document.getElementById('training-client-search-input').value = c.name;
        document.getElementById('trn-customer-name').value = c.name;
        document.getElementById('trn-client-id').value = c.id || '';
        document.getElementById('trn-phone').value = c.phone || '';
        document.getElementById('trn-email').value = c.email || '';

        if (c.product) {
            const prodSelect = document.getElementById('trn-product');
            let found = false;
            for (let i = 0; i < prodSelect.options.length; i++) {
                if (prodSelect.options[i].value.toLowerCase() === c.product.toLowerCase()) {
                    prodSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            if (!found) {
                const opt = new Option(c.product, c.product, true, true);
                prodSelect.add(opt);
            }
        }

        if (c.renewal_date) {
            const dateVal = c.renewal_date.split(' ')[0];
            document.getElementById('trn-renewal-date').value = dateVal;
        } else {
            document.getElementById('trn-renewal-date').value = '';
        }

        if (c.address) {
            document.getElementById('trn-address').value = c.address;
        } else {
            document.getElementById('trn-address').value = '';
        }

        const picker = document.getElementById('training-client-select-picker');
        if (picker) picker.value = idx.toString();

        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu) menu.style.display = 'none';
    }

    function escapeTrnText(str) {
        if (!str) return '';
        return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    document.addEventListener('click', function(e) {
        const input = document.getElementById('training-client-search-input');
        const menu = document.getElementById('training-client-dropdown-menu');
        if (menu && input && !input.contains(e.target) && !menu.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    function openCertifyModal(trId, clientName, compHours, totalHours, status) {
        document.getElementById('certify-tr-id').value = trId;
        document.getElementById('certify-client-name').value = clientName;
        document.getElementById('certify-hours-comp').value = compHours;
        document.getElementById('certify-hours-total').value = totalHours;
        document.getElementById('certify-status-select').value = status;
        window.openModal('certify-users-modal');
    }

    function openTrainingAllocationModal() {
        initTrainingClientPicker();
        window.openModal('schedule-training-modal');
    }

    document.addEventListener('DOMContentLoaded', function() {
        initTrainingClientPicker();
    });
</script>
