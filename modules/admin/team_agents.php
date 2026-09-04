<?php
/**
 * SaaS Super Admin - Team WhatsApp Agents Directory
 * Friendly AI Solution - Marg Lead CRM
 * 
 * Allows Super Admin and Admin to register team members (Sales, Support, Field, Tech)
 * whose incoming WhatsApp messages on 93050 45727 automatically generate Support Tickets
 * without sending messages to the client, while keeping the employee updated on status changes.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/db.php';

// Access Control: Only Super Admin and Admin
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['Super Admin', 'Admin'])) {
    echo '<div class="card p-6 text-center" style="max-width: 500px; margin: 4rem auto; border: 1px solid var(--border-color);">';
    echo '<i data-lucide="shield-alert" style="width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1.5rem auto;"></i>';
    echo '<h2 class="mb-2" style="font-family: var(--font-heading);">Access Restricted</h2>';
    echo '<p class="text-muted mb-4">Only SaaS Super Administrators can manage Team WhatsApp Agents.</p>';
    echo '<a href="index.php?page=dashboard" class="btn btn-primary">Return to Dashboard</a>';
    echo '</div>';
    return;
}

$msg = null;
$msg_type = 'success';

// Clean phone digits helper
function clean_agent_phone($raw) {
    $digits = preg_replace('/[^\d]/', '', $raw);
    if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    return $digits;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $msg = "Security token mismatch. Please refresh and try again.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_agent') {
            $agent_id = (int)($_POST['agent_id'] ?? 0);
            $emp_code = strtoupper(trim($_POST['emp_code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $raw_phone = trim($_POST['whatsapp_phone'] ?? '');
            $department = trim($_POST['department'] ?? 'Sales');
            $status = $_POST['status'] ?? 'Active';
            
            $phone = clean_agent_phone($raw_phone);

            if (empty($emp_code) || empty($name) || empty($phone)) {
                $msg = "Please fill in Employee Code, Name, and a valid WhatsApp Number.";
                $msg_type = "danger";
            } elseif (strlen($phone) < 10) {
                $msg = "WhatsApp phone number must be at least 10 digits.";
                $msg_type = "danger";
            } else {
                try {
                    // Check duplicates
                    $dupStmt = $pdo->prepare("SELECT id FROM team_agents WHERE (emp_code = ? OR whatsapp_phone = ?) AND id != ?");
                    $dupStmt->execute([$emp_code, $phone, $agent_id]);
                    if ($dupStmt->fetch()) {
                        $msg = "An employee with this Employee Code or WhatsApp Number already exists.";
                        $msg_type = "danger";
                    } else {
                        if ($agent_id > 0) {
                            $stmt = $pdo->prepare("UPDATE team_agents SET emp_code = ?, name = ?, whatsapp_phone = ?, department = ?, status = ? WHERE id = ?");
                            $stmt->execute([$emp_code, $name, $phone, $department, $status, $agent_id]);
                            $msg = "Team member {$name} ({$emp_code}) updated successfully!";
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO team_agents (emp_code, name, whatsapp_phone, department, status) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$emp_code, $name, $phone, $department, $status]);
                            $msg = "Team member {$name} ({$emp_code}) added successfully!";
                        }
                    }
                } catch (Exception $e) {
                    $msg = "Database error: " . $e->getMessage();
                    $msg_type = "danger";
                }
            }
        } elseif ($action === 'toggle_status') {
            $agent_id = (int)($_POST['agent_id'] ?? 0);
            if ($agent_id > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE team_agents SET status = IF(status = 'Active', 'Inactive', 'Active') WHERE id = ?");
                    $stmt->execute([$agent_id]);
                    $msg = "Team member status updated.";
                } catch (Exception $e) {
                    $msg = "Error: " . $e->getMessage();
                    $msg_type = "danger";
                }
            }
        } elseif ($action === 'delete_agent') {
            $agent_id = (int)($_POST['agent_id'] ?? 0);
            if ($agent_id > 0) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM team_agents WHERE id = ?");
                    $stmt->execute([$agent_id]);
                    $msg = "Team member removed successfully.";
                } catch (Exception $e) {
                    $msg = "Error deleting member: " . $e->getMessage();
                    $msg_type = "danger";
                }
            }
        }
    }
}

// Fetch all registered team agents with their ticket stats
$agents = [];
try {
    $stmt = $pdo->query("
        SELECT ta.*,
               COUNT(st.id) AS total_dropped_tickets,
               SUM(CASE WHEN st.status = 'open' THEN 1 ELSE 0 END) AS open_tickets,
               SUM(CASE WHEN st.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS resolved_tickets
        FROM team_agents ta
        LEFT JOIN support_tickets st ON st.dropped_by_emp_id = ta.id OR RIGHT(st.dropped_by_emp_phone, 10) = RIGHT(ta.whatsapp_phone, 10)
        GROUP BY ta.id
        ORDER BY ta.status ASC, ta.name ASC
    ");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $agents = [];
}

// Summary statistics
$total_agents = count($agents);
$active_agents = count(array_filter($agents, fn($a) => $a['status'] === 'Active'));
$total_dropped = array_sum(array_column($agents, 'total_dropped_tickets'));
?>

<div class="container-fluid" style="padding-bottom: 3rem;">
    <!-- Page Header -->
    <div class="d-flex justify-between items-center mb-4 flex-wrap" style="gap: 1rem;">
        <div>
            <h2 style="font-family: var(--font-heading); margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--text-main);">
                Team WhatsApp Agents Directory
            </h2>
            <p class="text-muted text-sm mb-0">
                Register authorized team phone numbers so when they forward client numbers to WhatsApp Bot (<code>+91 93050 45727</code>), tickets are generated automatically without notifying the client, and live progress updates loop back to the agent.
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-primary" onclick="openAgentModal()">
                <i data-lucide="user-plus" style="width: 16px; height: 16px; margin-right: 6px;"></i>
                Add Team Member
            </button>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msg_type; ?> mb-4 d-flex items-center" style="gap: 0.75rem; border-radius: 8px;">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-circle'; ?>" style="width: 20px; height: 20px;"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Workflow Instructions Banner -->
    <div class="card p-4 mb-4" style="background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border: 1px solid #bfdbfe; border-radius: 10px;">
        <div class="d-flex items-start" style="gap: 1rem;">
            <div style="background: #2563eb; color: #fff; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="bot" style="width: 22px; height: 22px;"></i>
            </div>
            <div style="flex: 1;">
                <h4 style="margin: 0 0 0.35rem 0; font-size: 1rem; color: #1e3a8a; font-weight: 700;">
                    How Team Lead-to-Ticket Automation Works:
                </h4>
                <div style="font-size: 0.85rem; color: #334155; line-height: 1.6;">
                    <strong>Step 1:</strong> Add your sales, support, or field team members below with their active WhatsApp numbers.<br>
                    <strong>Step 2:</strong> Whenever they get a client call/lead, they just send the client's 10-digit number (e.g. <code>9876543210</code> or <code>9876543210 Marg printer error</code>) to the bot at <strong>+91 93050 45727</strong>.<br>
                    <strong>Step 3:</strong> The system creates an internal <strong>Support Ticket</strong> instantly. <em>Client ko koi message nahi jayega.</em><br>
                    <strong>Step 4:</strong> Technical team calls the client from the CRM. Whenever tech updates the ticket (Call Back, In Progress, Closed), the submitting team member automatically receives real-time WhatsApp updates!
                </div>
            </div>
        </div>
    </div>

    <!-- Metric Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div class="card p-4 d-flex items-center" style="gap: 1rem; border-radius: 10px;">
            <div style="background: #eff6ff; color: #2563eb; width: 48px; height: 48px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="users" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <div class="text-xs text-muted font-semibold uppercase">Total Team Agents</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-main);"><?php echo $total_agents; ?></div>
            </div>
        </div>
        <div class="card p-4 d-flex items-center" style="gap: 1rem; border-radius: 10px;">
            <div style="background: #ecfdf5; color: #059669; width: 48px; height: 48px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="user-check" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <div class="text-xs text-muted font-semibold uppercase">Active Authorized</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #059669;"><?php echo $active_agents; ?></div>
            </div>
        </div>
        <div class="card p-4 d-flex items-center" style="gap: 1rem; border-radius: 10px;">
            <div style="background: #fdf4ff; color: #9333ea; width: 48px; height: 48px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="ticket" style="width: 24px; height: 24px;"></i>
            </div>
            <div>
                <div class="text-xs text-muted font-semibold uppercase">Total Leads Dropped</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #9333ea;"><?php echo $total_dropped; ?></div>
            </div>
        </div>
    </div>

    <!-- Team Members Table Card -->
    <div class="card" style="border-radius: 10px; overflow: hidden;">
        <div class="card-header d-flex justify-between items-center p-3 bg-light border-bottom">
            <div class="d-flex items-center" style="gap: 0.5rem;">
                <i data-lucide="shield-check" style="width: 18px; height: 18px; color: var(--primary);"></i>
                <h3 style="font-size: 1rem; font-weight: 700; margin: 0; color: var(--text-main);">
                    Registered Team Members
                </h3>
            </div>
            <div>
                <input type="text" id="tableFilter" placeholder="Search by name, code, phone..." class="form-control text-xs" style="width: 240px; border-radius: 6px;" onkeyup="filterAgentsTable()">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0" id="agentsTable">
                <thead>
                    <tr style="background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b;">
                        <th style="padding: 0.85rem 1rem;">Code</th>
                        <th style="padding: 0.85rem 1rem;">Member Name</th>
                        <th style="padding: 0.85rem 1rem;">WhatsApp Number</th>
                        <th style="padding: 0.85rem 1rem;">Department</th>
                        <th style="padding: 0.85rem 1rem;">Dropped Tickets</th>
                        <th style="padding: 0.85rem 1rem;">Status</th>
                        <th style="padding: 0.85rem 1rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agents)): ?>
                        <tr>
                            <td colspan="7" class="text-center p-5 text-muted">
                                <i data-lucide="user-x" style="width: 36px; height: 36px; margin: 0 auto 0.5rem auto; color: #94a3b8;"></i>
                                <div>No team members registered yet.</div>
                                <button type="button" class="btn btn-sm btn-primary mt-2" onclick="openAgentModal()">
                                    Add First Team Member
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($agents as $ag): ?>
                            <tr style="font-size: 0.88rem; vertical-align: middle;">
                                <td style="padding: 0.85rem 1rem;">
                                    <span class="badge" style="background: #e2e8f0; color: #334155; font-family: monospace; font-weight: 600;">
                                        <?php echo htmlspecialchars($ag['emp_code']); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <strong><?php echo htmlspecialchars($ag['name']); ?></strong>
                                </td>
                                <td style="padding: 0.85rem 1rem; font-family: monospace;">
                                    <a href="https://wa.me/91<?php echo htmlspecialchars($ag['whatsapp_phone']); ?>" target="_blank" style="color: #059669; text-decoration: none; font-weight: 600;">
                                        <i data-lucide="message-circle" style="width: 14px; height: 14px; display: inline-block; vertical-align: -2px;"></i>
                                        +91 <?php echo htmlspecialchars($ag['whatsapp_phone']); ?>
                                    </a>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <span class="badge" style="background: #f1f5f9; color: #475569; font-weight: 600;">
                                        <?php echo htmlspecialchars($ag['department']); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <span style="font-weight: 700; color: #1e293b;"><?php echo (int)$ag['total_dropped_tickets']; ?></span>
                                    <span class="text-xs text-muted">
                                        (<?php echo (int)$ag['open_tickets']; ?> Open / <?php echo (int)$ag['resolved_tickets']; ?> Closed)
                                    </span>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Toggle status for <?php echo htmlspecialchars($ag['name']); ?>?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="agent_id" value="<?php echo $ag['id']; ?>">
                                        <button type="submit" class="badge" style="border: none; cursor: pointer; background: <?php echo $ag['status'] === 'Active' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $ag['status'] === 'Active' ? '#15803d' : '#b91c1c'; ?>; font-weight: 600; padding: 4px 10px; border-radius: 4px;">
                                            ● <?php echo $ag['status']; ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="padding: 0.85rem 1rem; text-align: right;">
                                    <div class="d-flex justify-end items-center" style="gap: 0.35rem;">
                                        <button type="button" class="btn btn-sm btn-secondary" onclick='editAgent(<?php echo json_encode($ag); ?>)' title="Edit Member">
                                            <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($ag['name']); ?>?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_agent">
                                            <input type="hidden" name="agent_id" value="<?php echo $ag['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary text-danger" title="Delete Member">
                                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                            </button>
                                        </form>
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

<!-- Modal: Add / Edit Agent -->
<div id="agentModal" class="custom-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card p-4" style="width: 100%; max-width: 480px; background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <div class="d-flex justify-between items-center mb-3 pb-2 border-bottom">
            <h3 id="modalTitle" style="font-size: 1.15rem; font-weight: 700; margin: 0; color: var(--text-main);">
                Add Team Member
            </h3>
            <button type="button" class="btn-close" onclick="closeAgentModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: #64748b;">&times;</button>
        </div>

        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_agent">
            <input type="hidden" name="agent_id" id="modal_agent_id" value="0">

            <div class="mb-3">
                <label class="form-label text-xs font-semibold uppercase text-muted">Employee Code</label>
                <input type="text" name="emp_code" id="modal_emp_code" class="form-control" placeholder="e.g. EMP-101" required style="border-radius: 6px;">
            </div>

            <div class="mb-3">
                <label class="form-label text-xs font-semibold uppercase text-muted">Full Name</label>
                <input type="text" name="name" id="modal_name" class="form-control" placeholder="e.g. Amit Sharma" required style="border-radius: 6px;">
            </div>

            <div class="mb-3">
                <label class="form-label text-xs font-semibold uppercase text-muted">WhatsApp Mobile Number</label>
                <div class="input-group">
                    <span class="input-group-text" style="background: #f1f5f9; font-weight: 600;">+91</span>
                    <input type="text" name="whatsapp_phone" id="modal_whatsapp_phone" class="form-control font-mono" placeholder="9876543210" required maxlength="15" style="border-radius: 0 6px 6px 0;">
                </div>
                <small class="text-muted">The exact WhatsApp number the employee will message from.</small>
            </div>

            <div class="mb-3">
                <label class="form-label text-xs font-semibold uppercase text-muted">Department</label>
                <select name="department" id="modal_department" class="form-control" style="border-radius: 6px;">
                    <option value="Sales">Sales & Marketing</option>
                    <option value="Technical">Technical Support</option>
                    <option value="Field Support">Field Engineer / On-Site</option>
                    <option value="Admin">Admin / Management</option>
                    <option value="Billing">Billing & Accounts</option>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label text-xs font-semibold uppercase text-muted">Authorization Status</label>
                <select name="status" id="modal_status" class="form-control" style="border-radius: 6px;">
                    <option value="Active">Active (Authorized to Drop Leads)</option>
                    <option value="Inactive">Inactive (Suspended)</option>
                </select>
            </div>

            <div class="d-flex justify-end" style="gap: 0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeAgentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Team Member</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAgentModal() {
    document.getElementById('modalTitle').innerText = 'Add Team Member';
    document.getElementById('modal_agent_id').value = '0';
    document.getElementById('modal_emp_code').value = '';
    document.getElementById('modal_name').value = '';
    document.getElementById('modal_whatsapp_phone').value = '';
    document.getElementById('modal_department').value = 'Sales';
    document.getElementById('modal_status').value = 'Active';
    document.getElementById('agentModal').style.display = 'flex';
}

function editAgent(agent) {
    document.getElementById('modalTitle').innerText = 'Edit Team Member';
    document.getElementById('modal_agent_id').value = agent.id;
    document.getElementById('modal_emp_code').value = agent.emp_code;
    document.getElementById('modal_name').value = agent.name;
    document.getElementById('modal_whatsapp_phone').value = agent.whatsapp_phone;
    document.getElementById('modal_department').value = agent.department;
    document.getElementById('modal_status').value = agent.status;
    document.getElementById('agentModal').style.display = 'flex';
}

function closeAgentModal() {
    document.getElementById('agentModal').style.display = 'none';
}

function filterAgentsTable() {
    const input = document.getElementById('tableFilter').value.toLowerCase();
    const rows = document.querySelectorAll('#agentsTable tbody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>
