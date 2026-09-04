<?php
/**
 * SaaS Super Admin - Team & Employee WhatsApp Directory
 * Friendly AI Solution
 * 
 * Manages authorized employees who can drop client phone numbers via WhatsApp
 * to bot (+91 93050 45727) to auto-raise tickets and receive reverse status loops.
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
    echo '<p class="text-muted mb-4">Only SaaS Super Administrators can manage team employee directory.</p>';
    echo '<a href="index.php?page=dashboard" class="btn btn-primary">Return to Dashboard</a>';
    echo '</div>';
    return;
}

$msg = null;
$msg_type = 'success';

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $msg = "Security token mismatch. Please refresh and try again.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        // Helper to normalize Indian phone number (10 digits)
        $cleanPhone = function($rawPhone) {
            $digits = preg_replace('/\D/', '', $rawPhone);
            if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
                $digits = substr($digits, 2);
            } elseif (strlen($digits) > 10 && str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }
            return substr($digits, -10);
        };

        if ($action === 'save_employee') {
            $id = (int)($_POST['emp_id'] ?? 0);
            $emp_code = strtoupper(trim($_POST['emp_code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $phone = $cleanPhone($_POST['phone'] ?? '');
            $department = trim($_POST['department'] ?? 'Technical');
            $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

            if (empty($emp_code) || empty($name) || strlen($phone) !== 10) {
                $msg = "Please provide a valid Employee Code, Full Name, and 10-digit WhatsApp number.";
                $msg_type = "danger";
            } else {
                try {
                    if ($id > 0) {
                        // Check uniqueness for other rows
                        $chk = $pdo->prepare("SELECT id FROM team_employees WHERE (emp_code = ? OR phone = ?) AND id != ?");
                        $chk->execute([$emp_code, $phone, $id]);
                        if ($chk->fetch()) {
                            $msg = "Another employee with this Code or Phone number already exists.";
                            $msg_type = "danger";
                        } else {
                            $stmt = $pdo->prepare("UPDATE team_employees SET emp_code = ?, name = ?, phone = ?, department = ?, status = ? WHERE id = ?");
                            $stmt->execute([$emp_code, $name, $phone, $department, $status]);
                            $msg = "Employee '{$name}' updated successfully.";
                            $msg_type = "success";
                        }
                    } else {
                        // Check uniqueness
                        $chk = $pdo->prepare("SELECT id FROM team_employees WHERE emp_code = ? OR phone = ?");
                        $chk->execute([$emp_code, $phone]);
                        if ($chk->fetch()) {
                            $msg = "An employee with this Code or Phone number already exists.";
                            $msg_type = "danger";
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO team_employees (emp_code, name, phone, department, status) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$emp_code, $name, $phone, $department, $status]);
                            $msg = "Employee '{$name}' ({$emp_code}) added successfully. They can now drop client numbers via WhatsApp!";
                            $msg_type = "success";
                        }
                    }
                } catch (Exception $e) {
                    $msg = "Database Error: " . $e->getMessage();
                    $msg_type = "danger";
                }
            }
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['emp_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("UPDATE team_employees SET status = IF(status = 'Active', 'Inactive', 'Active') WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "Employee status updated.";
                $msg_type = "success";
            } catch (Exception $e) {
                $msg = "Error updating status: " . $e->getMessage();
                $msg_type = "danger";
            }
        } elseif ($action === 'delete_employee') {
            $id = (int)($_POST['emp_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("DELETE FROM team_employees WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "Employee removed from WhatsApp team directory.";
                $msg_type = "success";
            } catch (Exception $e) {
                $msg = "Error deleting employee: " . $e->getMessage();
                $msg_type = "danger";
            }
        }
    }
}

// Fetch search and filter parameters
$search = trim($_GET['q'] ?? '');
$dept_filter = trim($_GET['dept'] ?? '');

$where_clauses = [];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(name LIKE ? OR emp_code LIKE ? OR phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (!empty($dept_filter)) {
    $where_clauses[] = "department = ?";
    $params[] = $dept_filter;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch Employees list with ticket drop counts
$query = "
    SELECT e.*, 
           (SELECT COUNT(*) FROM support_tickets st WHERE (st.emp_phone COLLATE utf8mb4_general_ci = e.phone COLLATE utf8mb4_general_ci OR st.emp_code COLLATE utf8mb4_general_ci = e.emp_code COLLATE utf8mb4_general_ci)) AS total_drops,
           (SELECT COUNT(*) FROM support_tickets st WHERE (st.emp_phone COLLATE utf8mb4_general_ci = e.phone COLLATE utf8mb4_general_ci OR st.emp_code COLLATE utf8mb4_general_ci = e.emp_code COLLATE utf8mb4_general_ci) AND st.status IN ('Closed', 'Resolved')) AS closed_drops
    FROM team_employees e
    {$where_sql}
    ORDER BY e.created_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary Metrics
$total_emps = count($employees);
$active_emps = 0;
$total_dropped_tickets = 0;
$total_closed_tickets = 0;

foreach ($employees as $emp) {
    if ($emp['status'] === 'Active') $active_emps++;
    $total_dropped_tickets += (int)$emp['total_drops'];
    $total_closed_tickets += (int)$emp['closed_drops'];
}
?>

<div class="content-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h1 class="page-title mb-1" style="font-size: 1.5rem; font-weight: 700;">
            <i data-lucide="users" style="width: 24px; height: 24px; vertical-align: -3px; color: var(--primary);"></i>
            Team WhatsApp Directory & Auto-Ticket Setup
        </h1>
        <p class="text-muted text-sm mb-0">
            Manage authorized staff members who can drop client numbers to bot (<strong>+91 93050 45727</strong>) for automatic technical ticket generation and live reverse status loops.
        </p>
    </div>
    <div>
        <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2" onclick="openAddEmployeeModal()">
            <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i>
            <span>Register Team Member</span>
        </button>
    </div>
</div>

<?php if (!empty($msg)): ?>
    <div class="alert alert-<?php echo $msg_type; ?> d-flex align-items-center gap-2 mb-4">
        <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-triangle'; ?>" style="width: 18px; height: 18px;"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
<?php endif; ?>

<!-- Workflow Explainer Callout Card -->
<div class="card mb-4" style="background: linear-gradient(135deg, rgba(37,99,235,0.04) 0%, rgba(59,130,246,0.08) 100%); border: 1px solid rgba(59,130,246,0.2); border-radius: 12px; padding: 1.25rem 1.5rem;">
    <div class="d-flex align-items-start gap-3">
        <div style="background: #2563eb; color: #fff; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="zap" style="width: 20px; height: 20px;"></i>
        </div>
        <div class="flex-grow-1">
            <h4 style="margin: 0 0 0.35rem 0; font-size: 0.95rem; font-weight: 700; color: #1e3a8a;">
                How WhatsApp Group Clutter Is Automated:
            </h4>
            <div style="font-size: 0.84rem; color: #334155; line-height: 1.6;">
                <strong>1. Member Drops Number:</strong> Any registered team member sends client number (e.g. <code>9876543210 Party Marg open issue</code>) to WhatsApp Bot <strong>+91 93050 45727</strong>.<br>
                <strong>2. Client Never Disturbed:</strong> Ticket is generated for Tech Team. <strong>Client receives 0 messages</strong>.<br>
                <strong>3. Real-Time Reverse Loop:</strong> When Tech Team updates ticket status (<em>Call Back</em>, <em>In Progress</em>, or <em>Closed</em>), the <strong>Team Member gets instant WhatsApp updates</strong> with engineer remarks!
            </div>
        </div>
    </div>
</div>

<!-- Stats Counter Grid -->
<div class="row g-3 mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
    <div class="card p-3" style="border-radius: 10px; border-left: 4px solid var(--primary);">
        <div class="text-xs text-muted font-semibold text-uppercase">Total Team Agents</div>
        <div class="d-flex align-items-baseline gap-2 mt-1">
            <span style="font-size: 1.75rem; font-weight: 700;"><?php echo $total_emps; ?></span>
            <span class="text-xs text-muted">registered</span>
        </div>
    </div>
    <div class="card p-3" style="border-radius: 10px; border-left: 4px solid #10b981;">
        <div class="text-xs text-muted font-semibold text-uppercase">Active WhatsApp Drop Access</div>
        <div class="d-flex align-items-baseline gap-2 mt-1">
            <span style="font-size: 1.75rem; font-weight: 700; color: #10b981;"><?php echo $active_emps; ?></span>
            <span class="text-xs text-muted">agents active</span>
        </div>
    </div>
    <div class="card p-3" style="border-radius: 10px; border-left: 4px solid #f59e0b;">
        <div class="text-xs text-muted font-semibold text-uppercase">Tickets Raised via Team WhatsApp</div>
        <div class="d-flex align-items-baseline gap-2 mt-1">
            <span style="font-size: 1.75rem; font-weight: 700; color: #f59e0b;"><?php echo $total_dropped_tickets; ?></span>
            <span class="text-xs text-muted">auto-tickets</span>
        </div>
    </div>
    <div class="card p-3" style="border-radius: 10px; border-left: 4px solid #6366f1;">
        <div class="text-xs text-muted font-semibold text-uppercase">Closed / Resolved Loops</div>
        <div class="d-flex align-items-baseline gap-2 mt-1">
            <span style="font-size: 1.75rem; font-weight: 700; color: #6366f1;"><?php echo $total_closed_tickets; ?></span>
            <span class="text-xs text-muted">successful updates</span>
        </div>
    </div>
</div>

<!-- Filter & Search Toolbar -->
<div class="card mb-4" style="border-radius: 10px;">
    <div class="p-3">
        <form method="GET" action="index.php" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="page" value="team_agents">
            <div style="flex-grow: 1; min-width: 250px;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control text-sm" placeholder="Search by name, employee code (e.g. EMP101), or phone...">
            </div>
            <div>
                <select name="dept" class="form-control text-sm" style="min-width: 160px;">
                    <option value="">All Departments</option>
                    <option value="Technical" <?php echo $dept_filter === 'Technical' ? 'selected' : ''; ?>>Technical Team</option>
                    <option value="Sales" <?php echo $dept_filter === 'Sales' ? 'selected' : ''; ?>>Sales Team</option>
                    <option value="Support" <?php echo $dept_filter === 'Support' ? 'selected' : ''; ?>>Support / Helpdesk</option>
                    <option value="Field Operations" <?php echo $dept_filter === 'Field Operations' ? 'selected' : ''; ?>>Field Operations</option>
                    <option value="Accounts" <?php echo $dept_filter === 'Accounts' ? 'selected' : ''; ?>>Accounts / Billing</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary text-sm d-inline-flex align-items-center gap-1">
                <i data-lucide="search" style="width: 14px; height: 14px;"></i> Filter
            </button>
            <?php if (!empty($search) || !empty($dept_filter)): ?>
                <a href="index.php?page=team_agents" class="btn btn-outline text-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Employees Directory Table -->
<div class="card" style="border-radius: 12px; overflow: hidden;">
    <div class="table-responsive">
        <table class="table mb-0" style="width: 100%; border-collapse: collapse;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Emp Code</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Employee Name</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">WhatsApp Mobile</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Department</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Dropped Tickets</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Status</th>
                    <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="7" class="text-center p-5 text-muted">
                            <i data-lucide="user-x" style="width: 36px; height: 36px; margin: 0 auto 0.75rem auto; color: #94a3b8;"></i>
                            <div style="font-weight: 600;">No team members registered yet</div>
                            <p class="text-xs mb-3">Add employees so they can start dropping client numbers to WhatsApp bot.</p>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openAddEmployeeModal()">Add First Employee</button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $row): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.85rem 1rem;">
                                <span class="badge" style="background: #e2e8f0; color: #334155; font-family: monospace; font-weight: 700; font-size: 0.78rem;">
                                    <?php echo htmlspecialchars($row['emp_code']); ?>
                                </span>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($row['name']); ?></div>
                                <div class="text-xs text-muted">Added: <?php echo date('d M Y', strtotime($row['created_at'])); ?></div>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <a href="https://wa.me/91<?php echo htmlspecialchars($row['phone']); ?>" target="_blank" style="color: #059669; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                    <i data-lucide="message-circle" style="width: 14px; height: 14px;"></i>
                                    +91 <?php echo htmlspecialchars($row['phone']); ?>
                                </a>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <span class="badge" style="background: rgba(37,99,235,0.08); color: #2563eb; font-weight: 600; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($row['department']); ?>
                                </span>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <div style="font-size: 0.85rem; font-weight: 600;">
                                    <a href="index.php?page=support&emp_phone=<?php echo urlencode($row['phone']); ?>" style="color: var(--primary); text-decoration: none;">
                                        <?php echo (int)$row['total_drops']; ?> tickets
                                    </a>
                                </div>
                                <div class="text-xs text-muted">
                                    <?php echo (int)$row['closed_drops']; ?> resolved
                                </div>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="emp_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="badge" style="cursor: pointer; border: none; background: <?php echo $row['status'] === 'Active' ? '#dcfce7; color: #15803d;' : '#f1f5f9; color: #64748b;'; ?> font-weight: 600; font-size: 0.75rem; padding: 4px 8px; border-radius: 4px;">
                                        <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: <?php echo $row['status'] === 'Active' ? '#22c55e' : '#94a3b8'; ?>; margin-right: 4px;"></span>
                                        <?php echo $row['status']; ?>
                                    </button>
                                </form>
                            </td>
                            <td style="padding: 0.85rem 1rem; text-align: right;">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-secondary p-1" title="Edit Employee" onclick='openEditEmployeeModal(<?php echo json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                        <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remove <?php echo addslashes($row['name']); ?> from WhatsApp drop directory?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="delete_employee">
                                        <input type="hidden" name="emp_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline text-danger p-1" title="Delete">
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

<!-- Modal: Add / Edit Employee -->
<div id="employeeModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-dialog" style="background: #ffffff; width: 100%; max-width: 480px; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <form method="POST" action="index.php?page=team_agents">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="save_employee">
            <input type="hidden" name="emp_id" id="modal_emp_id" value="0">

            <div class="modal-header d-flex justify-content-between align-items-center p-3" style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                <h3 id="modalTitle" style="font-size: 1.1rem; font-weight: 700; margin: 0; color: #1e293b;">Register Team Member</h3>
                <button type="button" class="btn-close" onclick="closeEmployeeModal()" style="background: none; border: none; cursor: pointer; font-size: 1.25rem;">&times;</button>
            </div>

            <div class="modal-body p-3">
                <div class="mb-3">
                    <label class="form-label text-xs font-semibold text-uppercase text-muted">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="emp_code" id="modal_emp_code" class="form-control text-sm font-mono text-uppercase" placeholder="e.g. EMP-101 or SALES-01" required>
                    <small class="text-muted text-xs">Unique identifier code for tracking referrals.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label text-xs font-semibold text-uppercase text-muted">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="modal_name" class="form-control text-sm" placeholder="e.g. Amit Sharma" required>
                </div>

                <div class="mb-3">
                    <label class="form-label text-xs font-semibold text-uppercase text-muted">WhatsApp Mobile Number <span class="text-danger">*</span></label>
                    <div class="d-flex align-items-center">
                        <span style="background: #e2e8f0; border: 1px solid #cbd5e1; border-right: none; padding: 0.45rem 0.65rem; border-radius: 6px 0 0 6px; font-size: 0.85rem; font-weight: 600; color: #475569;">+91</span>
                        <input type="tel" name="phone" id="modal_phone" class="form-control text-sm font-mono" style="border-radius: 0 6px 6px 0;" placeholder="9876543210" pattern="[6-9][0-9]{9}" maxlength="10" required>
                    </div>
                    <small class="text-muted text-xs">Must be the WhatsApp number they use to send messages to the bot.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label text-xs font-semibold text-uppercase text-muted">Department</label>
                    <select name="department" id="modal_department" class="form-control text-sm">
                        <option value="Technical">Technical Support</option>
                        <option value="Sales">Sales & Marketing</option>
                        <option value="Support">Customer Service Helpdesk</option>
                        <option value="Field Operations">Field Operations & Installation</option>
                        <option value="Accounts">Accounts & Billing</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label text-xs font-semibold text-uppercase text-muted">Status</label>
                    <select name="status" id="modal_status" class="form-control text-sm">
                        <option value="Active">Active (Can drop numbers & receive loops)</option>
                        <option value="Inactive">Inactive (Revoke access)</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer p-3 d-flex justify-content-end gap-2" style="border-top: 1px solid #e2e8f0; background: #f8fafc;">
                <button type="button" class="btn btn-secondary text-sm" onclick="closeEmployeeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm" id="modalSubmitBtn">Save Employee</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddEmployeeModal() {
    document.getElementById('modalTitle').textContent = 'Register Team Member';
    document.getElementById('modalSubmitBtn').textContent = 'Save Employee';
    document.getElementById('modal_emp_id').value = '0';
    document.getElementById('modal_emp_code').value = '';
    document.getElementById('modal_name').value = '';
    document.getElementById('modal_phone').value = '';
    document.getElementById('modal_department').value = 'Technical';
    document.getElementById('modal_status').value = 'Active';
    document.getElementById('employeeModal').style.display = 'flex';
}

function openEditEmployeeModal(data) {
    document.getElementById('modalTitle').textContent = 'Edit Team Member';
    document.getElementById('modalSubmitBtn').textContent = 'Update Employee';
    document.getElementById('modal_emp_id').value = data.id;
    document.getElementById('modal_emp_code').value = data.emp_code;
    document.getElementById('modal_name').value = data.name;
    document.getElementById('modal_phone').value = data.phone;
    document.getElementById('modal_department').value = data.department;
    document.getElementById('modal_status').value = data.status;
    document.getElementById('employeeModal').style.display = 'flex';
}

function closeEmployeeModal() {
    document.getElementById('employeeModal').style.display = 'none';
}

// Close on outside backdrop click
window.addEventListener('click', function(e) {
    var modal = document.getElementById('employeeModal');
    if (e.target === modal) {
        closeEmployeeModal();
    }
});
</script>
