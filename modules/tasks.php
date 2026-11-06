<?php
/**
 * Marg CRM - Professional Isolated Task Management Suite
 * Strict Multi-Tenant & Role-Based Data Isolation
 * Enterprise Corporate UI Standard (Zero Emojis, Lucide Vector Icons)
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../includes/config.php';
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? 'Employee';
$company_id = $_SESSION['tenant_id'] ?? 1;

// Role check: Only system admins & tenant admins have global oversight
$is_admin = isSystemAdminRole($user_role) || ($user_role === 'Tenant Admin');

// 1. Auto-Provision tasks table if not existing (Self-Healing Schema)
if ($db_connected && $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            task_type VARCHAR(50) DEFAULT 'General',
            related_to VARCHAR(50) DEFAULT 'General',
            related_id VARCHAR(100) NULL,
            related_title VARCHAR(255) NULL,
            due_date DATE NOT NULL,
            due_time TIME NULL,
            priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Medium',
            status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled') DEFAULT 'Pending',
            assigned_to VARCHAR(100) NOT NULL,
            assigned_to_id INT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_by_id INT NULL,
            completed_at DATETIME NULL,
            completed_by VARCHAR(100) NULL,
            checklist TEXT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_assigned (assigned_to),
            INDEX idx_assigned_id (assigned_to_id),
            INDEX idx_status (status),
            INDEX idx_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $eInit) {
        error_log("Task table init notice: " . $eInit->getMessage());
    }
}

// 2. AJAX Handlers: Toggle Status, Save, Delete
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (!empty($action) && $db_connected && $pdo) {
    header('Content-Type: application/json');

    // Action: Toggle Task Completion Status
    if ($action === 'toggle_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        if (!in_array($newStatus, ['Pending', 'In Progress', 'Completed', 'Cancelled'])) {
            $newStatus = 'Completed';
        }

        // Strict isolation check
        $checkSql = "SELECT id, assigned_to_id, assigned_to, created_by_id FROM tasks WHERE id = ? AND company_id = ?";
        $cStmt = $pdo->prepare($checkSql);
        $cStmt->execute([$taskId, $company_id]);
        $taskRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$taskRow) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        // Access verify: Admin or assigned operator
        if (!$is_admin && $taskRow['assigned_to_id'] != $user_id && strcasecmp(trim($taskRow['assigned_to']), trim($user_name)) !== 0 && $taskRow['created_by_id'] != $user_id) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only update tasks assigned to you.']);
            exit;
        }

        $completedAt = ($newStatus === 'Completed') ? date('Y-m-d H:i:s') : null;
        $completedBy = ($newStatus === 'Completed') ? $user_name : null;

        $uStmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = ?, completed_by = ? WHERE id = ?");
        $uStmt->execute([$newStatus, $completedAt, $completedBy, $taskId]);

        echo json_encode(['success' => true, 'new_status' => $newStatus, 'task_id' => $taskId]);
        exit;
    }

    // Action: Save Task (Create or Update)
    if ($action === 'save_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $task_type = trim($_POST['task_type'] ?? 'General');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $due_date = trim($_POST['due_date'] ?? date('Y-m-d'));
        $due_time = !empty($_POST['due_time']) ? trim($_POST['due_time']) : null;
        $status = trim($_POST['status'] ?? 'Pending');
        $assigned_to = trim($_POST['assigned_to'] ?? $user_name);
        $related_to = trim($_POST['related_to'] ?? 'General');
        $related_id = trim($_POST['related_id'] ?? '');
        $related_title = trim($_POST['related_title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $checklist_raw = trim($_POST['checklist'] ?? '');

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Task Title is required.']);
            exit;
        }

        // Lookup assigned_to_id
        $assigned_to_id = null;
        if (!empty($assigned_to)) {
            $uFind = $pdo->prepare("SELECT id, name FROM users WHERE (name = ? OR email = ?) LIMIT 1");
            $uFind->execute([$assigned_to, $assigned_to]);
            if ($uRow = $uFind->fetch(PDO::FETCH_ASSOC)) {
                $assigned_to_id = (int)$uRow['id'];
                $assigned_to = $uRow['name'];
            }
        }

        // Lookup related title if related to Lead
        if ($related_to === 'Lead' && !empty($related_id) && empty($related_title)) {
            $lFind = $pdo->prepare("SELECT name, company FROM leads WHERE id = ? LIMIT 1");
            $lFind->execute([$related_id]);
            if ($lRow = $lFind->fetch(PDO::FETCH_ASSOC)) {
                $related_title = !empty($lRow['name']) ? $lRow['name'] : $lRow['company'];
            }
        }

        if ($taskId > 0) {
            // Update existing task (Verify isolation)
            $checkSql = "SELECT id, assigned_to_id, assigned_to, created_by_id FROM tasks WHERE id = ? AND company_id = ?";
            $cStmt = $pdo->prepare($checkSql);
            $cStmt->execute([$taskId, $company_id]);
            $taskRow = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$taskRow) {
                echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
                exit;
            }

            if (!$is_admin && $taskRow['assigned_to_id'] != $user_id && strcasecmp(trim($taskRow['assigned_to']), trim($user_name)) !== 0 && $taskRow['created_by_id'] != $user_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only edit your own tasks.']);
                exit;
            }

            $updSql = "UPDATE tasks SET 
                title = ?, description = ?, task_type = ?, related_to = ?, related_id = ?, 
                related_title = ?, due_date = ?, due_time = ?, priority = ?, status = ?, 
                assigned_to = ?, assigned_to_id = ?, checklist = ?, updated_at = NOW() 
                WHERE id = ?";
            $uStmt = $pdo->prepare($updSql);
            $uStmt->execute([
                $title, $description, $task_type, $related_to, $related_id,
                $related_title, $due_date, $due_time, $priority, $status,
                $assigned_to, $assigned_to_id, $checklist_raw, $taskId
            ]);

            echo json_encode(['success' => true, 'message' => 'Task updated successfully.', 'task_id' => $taskId]);
            exit;
        } else {
            // Insert New Task
            $insSql = "INSERT INTO tasks (
                company_id, title, description, task_type, related_to, related_id, 
                related_title, due_date, due_time, priority, status, 
                assigned_to, assigned_to_id, created_by, created_by_id, checklist
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $iStmt = $pdo->prepare($insSql);
            $iStmt->execute([
                $company_id, $title, $description, $task_type, $related_to, $related_id,
                $related_title, $due_date, $due_time, $priority, $status,
                $assigned_to, $assigned_to_id, $user_name, $user_id, $checklist_raw
            ]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode(['success' => true, 'message' => 'New task registered successfully.', 'task_id' => $newId]);
            exit;
        }
    }

    // Action: Delete Task
    if ($action === 'delete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $checkSql = "SELECT id, created_by_id, assigned_to_id FROM tasks WHERE id = ? AND company_id = ?";
        $cStmt = $pdo->prepare($checkSql);
        $cStmt->execute([$taskId, $company_id]);
        $taskRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$taskRow) {
            echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
            exit;
        }

        // Only Admin or Creator can delete a task
        if (!$is_admin && $taskRow['created_by_id'] != $user_id) {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Only task creator or admin can delete tasks.']);
            exit;
        }

        $delStmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $delStmt->execute([$taskId]);

        echo json_encode(['success' => true, 'message' => 'Task deleted permanently.']);
        exit;
    }
}

// 3. Data Query & Isolation Scope
$today_str = date('Y-m-d');
$tomorrow_str = date('Y-m-d', strtotime('+1 day'));

$active_tab = $_GET['tab'] ?? 'all';
$filter_priority = $_GET['priority'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_assignee = $_GET['assignee'] ?? '';
$search_query = trim($_GET['q'] ?? '');

// Base where clause strictly enforcing multi-tenant isolation
$where = ["company_id = ?"];
$params = [$company_id];

// Employee Data Isolation Enforcement
if (!$is_admin) {
    $where[] = "(assigned_to_id = ? OR LOWER(TRIM(assigned_to)) = LOWER(TRIM(?)) OR created_by_id = ?)";
    $params[] = $user_id;
    $params[] = $user_name;
    $params[] = $user_id;
} else {
    // Admin can optionally filter by employee
    if (!empty($filter_assignee)) {
        $where[] = "(assigned_to_id = ? OR assigned_to = ?)";
        $params[] = $filter_assignee;
        $params[] = $filter_assignee;
    }
}

// Tab filtering
if ($active_tab === 'today') {
    $where[] = "due_date = ? AND status != 'Completed'";
    $params[] = $today_str;
} elseif ($active_tab === 'upcoming') {
    $where[] = "due_date > ? AND status != 'Completed'";
    $params[] = $today_str;
} elseif ($active_tab === 'overdue') {
    $where[] = "due_date < ? AND status != 'Completed'";
    $params[] = $today_str;
} elseif ($active_tab === 'completed') {
    $where[] = "status = 'Completed'";
}

// Priority filter
if (!empty($filter_priority)) {
    $where[] = "priority = ?";
    $params[] = $filter_priority;
}

// Task Type filter
if (!empty($filter_type)) {
    $where[] = "task_type = ?";
    $params[] = $filter_type;
}

// Text Search filter
if (!empty($search_query)) {
    $where[] = "(title LIKE ? OR related_title LIKE ? OR related_id LIKE ? OR description LIKE ? OR assigned_to LIKE ?)";
    $term = '%' . $search_query . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$where_sql = implode(' AND ', $where);

// Fetch Tasks Count Metrics (Strictly Isolated)
$metrics = ['all' => 0, 'today' => 0, 'upcoming' => 0, 'overdue' => 0, 'completed' => 0];
if ($db_connected && $pdo) {
    $iso_sql = "company_id = " . intval($company_id);
    if (!$is_admin) {
        $iso_sql .= " AND (assigned_to_id = " . intval($user_id) . " OR LOWER(TRIM(assigned_to)) = " . $pdo->quote(strtolower(trim($user_name))) . " OR created_by_id = " . intval($user_id) . ")";
    }

    try {
        $mRes = $pdo->query("SELECT 
            COUNT(*) as cnt_all,
            SUM(CASE WHEN due_date = '{$today_str}' AND status != 'Completed' THEN 1 ELSE 0 END) as cnt_today,
            SUM(CASE WHEN due_date > '{$today_str}' AND status != 'Completed' THEN 1 ELSE 0 END) as cnt_upcoming,
            SUM(CASE WHEN due_date < '{$today_str}' AND status != 'Completed' THEN 1 ELSE 0 END) as cnt_overdue,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as cnt_completed
            FROM tasks WHERE {$iso_sql}")->fetch(PDO::FETCH_ASSOC);

        if ($mRes) {
            $metrics['all'] = (int)($mRes['cnt_all'] ?? 0);
            $metrics['today'] = (int)($mRes['cnt_today'] ?? 0);
            $metrics['upcoming'] = (int)($mRes['cnt_upcoming'] ?? 0);
            $metrics['overdue'] = (int)($mRes['cnt_overdue'] ?? 0);
            $metrics['completed'] = (int)($mRes['cnt_completed'] ?? 0);
        }
    } catch (\Throwable $mEx) {}
}

// Fetch Active Task Records
$tasks_list = [];
if ($db_connected && $pdo) {
    try {
        $order_sql = "ORDER BY CASE WHEN status = 'Completed' THEN 2 ELSE 1 END, due_date ASC, CASE priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END";
        $stmt = $pdo->prepare("SELECT * FROM tasks WHERE {$where_sql} {$order_sql} LIMIT 250");
        $stmt->execute($params);
        $tasks_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $qEx) {
        error_log("Tasks list fetch warning: " . $qEx->getMessage());
    }
}

// Fetch active employees for assignment selector
$operators_list = [];
if ($db_connected && $pdo) {
    try {
        $opStmt = $pdo->query("SELECT id, name, role FROM users WHERE status = 'Active' ORDER BY name ASC");
        $operators_list = $opStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $eOp) {}
}

// Fetch recent customer leads for quick linking
$recent_leads = [];
if ($db_connected && $pdo) {
    try {
        $leadSql = "SELECT id, name, company, phone FROM leads ORDER BY updated_at DESC LIMIT 60";
        $lStmt = $pdo->query($leadSql);
        $recent_leads = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $eLd) {}
}
?>

<div class="task-manager-container flex flex-col gap-5 p-1">
    
    <!-- Header Block -->
    <div class="flex justify-between align-center flex-wrap gap-4 pb-2" style="border-bottom: 1px solid var(--border-color);">
        <div>
            <div class="flex align-center gap-2 mb-1">
                <i data-lucide="check-square" style="width: 22px; height: 22px; color: var(--primary);"></i>
                <h2 class="text-xl font-bold tracking-tight" style="color: var(--text-main); margin: 0;">Task & Workload Manager</h2>
                <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: var(--primary); font-size: 0.7rem; font-weight: 700; border: 1px solid rgba(37, 99, 235, 0.2);">
                    <?php echo $is_admin ? 'Company Oversight' : 'My Assigned Workload'; ?>
                </span>
            </div>
            <p class="text-xs text-muted" style="margin: 0;">
                Isolated task execution, scheduling, customer follow-up actions, and assignment matrix.
            </p>
        </div>

        <div class="flex align-center gap-2">
            <button type="button" class="btn btn-secondary text-xs flex align-center gap-1.5" onclick="exportTasksToCsv()">
                <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                <span>Export CSV</span>
            </button>
            <button type="button" class="btn btn-primary text-xs flex align-center gap-1.5" onclick="openCreateTaskModal()">
                <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                <span>Create Task</span>
            </button>
        </div>
    </div>

    <!-- Summary Metrics Cards -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
        
        <!-- Total Tasks -->
        <a href="index.php?page=tasks&tab=all" class="card p-3.5 flex flex-col justify-between transition" style="border: 1px solid var(--border-color); text-decoration: none; border-radius: var(--border-radius-md); background: var(--bg-card); <?php echo $active_tab === 'all' ? 'border-color: var(--primary); box-shadow: 0 0 0 1px var(--primary);' : ''; ?>">
            <div class="flex justify-between align-center mb-2">
                <span class="text-xs font-semibold text-muted">All Tasks</span>
                <i data-lucide="layers" style="width: 16px; height: 16px; color: var(--text-muted);"></i>
            </div>
            <div class="flex align-baseline gap-2">
                <span class="text-2xl font-bold" style="color: var(--text-main);"><?php echo $metrics['all']; ?></span>
                <span class="text-xs text-muted">total</span>
            </div>
        </a>

        <!-- Due Today -->
        <a href="index.php?page=tasks&tab=today" class="card p-3.5 flex flex-col justify-between transition" style="border: 1px solid var(--border-color); text-decoration: none; border-radius: var(--border-radius-md); background: var(--bg-card); <?php echo $active_tab === 'today' ? 'border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb;' : ''; ?>">
            <div class="flex justify-between align-center mb-2">
                <span class="text-xs font-semibold" style="color: #2563eb;">Due Today</span>
                <i data-lucide="calendar" style="width: 16px; height: 16px; color: #2563eb;"></i>
            </div>
            <div class="flex align-baseline gap-2">
                <span class="text-2xl font-bold" style="color: #2563eb;"><?php echo $metrics['today']; ?></span>
                <span class="text-xs text-muted">scheduled today</span>
            </div>
        </a>

        <!-- Upcoming -->
        <a href="index.php?page=tasks&tab=upcoming" class="card p-3.5 flex flex-col justify-between transition" style="border: 1px solid var(--border-color); text-decoration: none; border-radius: var(--border-radius-md); background: var(--bg-card); <?php echo $active_tab === 'upcoming' ? 'border-color: #059669; box-shadow: 0 0 0 1px #059669;' : ''; ?>">
            <div class="flex justify-between align-center mb-2">
                <span class="text-xs font-semibold" style="color: #059669;">Upcoming</span>
                <i data-lucide="clock" style="width: 16px; height: 16px; color: #059669;"></i>
            </div>
            <div class="flex align-baseline gap-2">
                <span class="text-2xl font-bold" style="color: #059669;"><?php echo $metrics['upcoming']; ?></span>
                <span class="text-xs text-muted">future pipeline</span>
            </div>
        </a>

        <!-- Overdue -->
        <a href="index.php?page=tasks&tab=overdue" class="card p-3.5 flex flex-col justify-between transition" style="border: 1px solid var(--border-color); text-decoration: none; border-radius: var(--border-radius-md); background: var(--bg-card); <?php echo $active_tab === 'overdue' ? 'border-color: #dc2626; box-shadow: 0 0 0 1px #dc2626;' : ''; ?>">
            <div class="flex justify-between align-center mb-2">
                <span class="text-xs font-semibold" style="color: #dc2626;">Overdue</span>
                <i data-lucide="alert-circle" style="width: 16px; height: 16px; color: #dc2626;"></i>
            </div>
            <div class="flex align-baseline gap-2">
                <span class="text-2xl font-bold" style="color: #dc2626;"><?php echo $metrics['overdue']; ?></span>
                <span class="text-xs text-muted">needs action</span>
            </div>
        </a>

        <!-- Completed -->
        <a href="index.php?page=tasks&tab=completed" class="card p-3.5 flex flex-col justify-between transition" style="border: 1px solid var(--border-color); text-decoration: none; border-radius: var(--border-radius-md); background: var(--bg-card); <?php echo $active_tab === 'completed' ? 'border-color: #10b981; box-shadow: 0 0 0 1px #10b981;' : ''; ?>">
            <div class="flex justify-between align-center mb-2">
                <span class="text-xs font-semibold text-muted">Completed</span>
                <i data-lucide="check-circle-2" style="width: 16px; height: 16px; color: #10b981;"></i>
            </div>
            <div class="flex align-baseline gap-2">
                <span class="text-2xl font-bold" style="color: #10b981;"><?php echo $metrics['completed']; ?></span>
                <span class="text-xs text-muted">closed</span>
            </div>
        </a>
    </div>

    <!-- Filter & Search Bar -->
    <div class="card p-3 flex flex-wrap justify-between align-center gap-3" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
        
        <!-- Filter Tabs -->
        <div class="flex align-center gap-1 flex-wrap">
            <?php
            $tabOptions = [
                'all'       => ['label' => 'All Tasks',  'count' => $metrics['all']],
                'today'     => ['label' => 'Today',      'count' => $metrics['today']],
                'upcoming'  => ['label' => 'Upcoming',   'count' => $metrics['upcoming']],
                'overdue'   => ['label' => 'Overdue',    'count' => $metrics['overdue']],
                'completed' => ['label' => 'Completed',  'count' => $metrics['completed']]
            ];
            foreach ($tabOptions as $k => $info):
                $isActive = ($active_tab === $k);
            ?>
                <a href="index.php?page=tasks&tab=<?php echo $k; ?>&priority=<?php echo urlencode($filter_priority); ?>&type=<?php echo urlencode($filter_type); ?>&assignee=<?php echo urlencode($filter_assignee); ?>&q=<?php echo urlencode($search_query); ?>" 
                   class="task-filter-tab <?php echo $isActive ? 'active' : ''; ?>">
                    <span><?php echo $info['label']; ?></span>
                    <span class="pill-count"><?php echo $info['count']; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter Selectors & Search Form -->
        <form method="GET" action="index.php" class="flex align-center gap-2 flex-wrap" style="margin: 0;">
            <input type="hidden" name="page" value="tasks">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">

            <!-- Priority Dropdown -->
            <select name="priority" class="form-control text-xs" style="width: auto; height: 32px; padding: 2px 8px;" onchange="this.form.submit()">
                <option value="">All Priorities</option>
                <option value="Urgent" <?php echo $filter_priority === 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
                <option value="High" <?php echo $filter_priority === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Medium" <?php echo $filter_priority === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="Low" <?php echo $filter_priority === 'Low' ? 'selected' : ''; ?>>Low</option>
            </select>

            <!-- Task Type Dropdown -->
            <select name="type" class="form-control text-xs" style="width: auto; height: 32px; padding: 2px 8px;" onchange="this.form.submit()">
                <option value="">All Task Types</option>
                <option value="General" <?php echo $filter_type === 'General' ? 'selected' : ''; ?>>General</option>
                <option value="Call Follow-up" <?php echo $filter_type === 'Call Follow-up' ? 'selected' : ''; ?>>Call Follow-up</option>
                <option value="Live Demo" <?php echo $filter_type === 'Live Demo' ? 'selected' : ''; ?>>Live Demo</option>
                <option value="Installation" <?php echo $filter_type === 'Installation' ? 'selected' : ''; ?>>Installation</option>
                <option value="Quotation Review" <?php echo $filter_type === 'Quotation Review' ? 'selected' : ''; ?>>Quotation Review</option>
                <option value="Client Support" <?php echo $filter_type === 'Client Support' ? 'selected' : ''; ?>>Client Support</option>
            </select>

            <!-- Employee Dropdown (Admins Only) -->
            <?php if ($is_admin && !empty($operators_list)): ?>
                <select name="assignee" class="form-control text-xs" style="width: auto; height: 32px; padding: 2px 8px;" onchange="this.form.submit()">
                    <option value="">All Assignees</option>
                    <?php foreach ($operators_list as $op): ?>
                        <option value="<?php echo htmlspecialchars($op['name']); ?>" <?php echo $filter_assignee === $op['name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($op['name']); ?> (<?php echo htmlspecialchars($op['role']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <!-- Search Field -->
            <div style="position: relative; width: 200px;">
                <input type="text" name="q" class="form-control text-xs" style="height: 32px; padding-left: 28px;" placeholder="Search task, lead, owner..." value="<?php echo htmlspecialchars($search_query); ?>">
                <i data-lucide="search" style="position: absolute; left: 8px; top: 8px; width: 14px; height: 14px; color: var(--text-muted);"></i>
            </div>

            <?php if (!empty($search_query) || !empty($filter_priority) || !empty($filter_type) || !empty($filter_assignee)): ?>
                <a href="index.php?page=tasks&tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-secondary text-xs" style="height: 32px; padding: 0 8px; display: inline-flex; align-items: center;" title="Reset Filters">
                    <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Task List Table -->
    <div class="card p-0" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md); overflow: hidden;">
        <div class="table-responsive">
            <table class="table" style="margin: 0; width: 100%; border-collapse: collapse;" id="tasksDataTable">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); background: var(--bg-app); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <th style="width: 44px; text-align: center; padding: 0.75rem 0.5rem;">Done</th>
                        <th style="width: 50px; text-align: center; padding: 0.75rem 0.5rem;">S.No.</th>
                        <th style="padding: 0.75rem 1rem;">Task Details & Title</th>
                        <th style="padding: 0.75rem 1rem;">Related Client / Lead</th>
                        <th style="padding: 0.75rem 1rem;">Assigned Operator</th>
                        <th style="padding: 0.75rem 1rem;">Priority</th>
                        <th style="padding: 0.75rem 1rem;">Due Date & Time</th>
                        <th style="padding: 0.75rem 1rem;">Status</th>
                        <th style="padding: 0.75rem 1rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks_list)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 3.5rem 1.5rem;">
                                <div class="flex flex-col align-center justify-center gap-2">
                                    <i data-lucide="inbox" style="width: 36px; height: 36px; color: var(--text-muted); opacity: 0.6;"></i>
                                    <span class="text-sm font-semibold" style="color: var(--text-main);">No tasks found matching current view criteria.</span>
                                    <span class="text-xs text-muted">Create a new task or adjust your active filters.</span>
                                    <button type="button" class="btn btn-primary text-xs mt-2" onclick="openCreateTaskModal()">
                                        <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                                        <span>Create First Task</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $sno = 1;
                        foreach ($tasks_list as $t): 
                            $isDone = ($t['status'] === 'Completed');
                            $isOverdue = (!$isDone && $t['due_date'] < $today_str);
                            $isToday = (!$isDone && $t['due_date'] === $today_str);
                            $isTomorrow = (!$isDone && $t['due_date'] === $tomorrow_str);

                            $formattedDueDate = date('d M Y', strtotime($t['due_date']));
                            $timeStr = !empty($t['due_time']) ? date('h:i A', strtotime($t['due_time'])) : '';

                            // Priority Badges
                            $priorityColors = [
                                'Urgent' => ['bg' => 'rgba(239, 68, 68, 0.12)',  'color' => '#dc2626', 'border' => 'rgba(239, 68, 68, 0.3)'],
                                'High'   => ['bg' => 'rgba(249, 115, 22, 0.12)', 'color' => '#ea580c', 'border' => 'rgba(249, 115, 22, 0.3)'],
                                'Medium' => ['bg' => 'rgba(59, 130, 246, 0.12)',  'color' => '#2563eb', 'border' => 'rgba(59, 130, 246, 0.3)'],
                                'Low'    => ['bg' => 'rgba(107, 114, 128, 0.12)', 'color' => '#4b5563', 'border' => 'rgba(107, 114, 128, 0.3)']
                            ];
                            $pStyle = $priorityColors[$t['priority']] ?? $priorityColors['Medium'];

                            // Checklist parsing
                            $checkItems = [];
                            if (!empty($t['checklist'])) {
                                $decoded = json_decode($t['checklist'], true);
                                if (is_array($decoded)) $checkItems = $decoded;
                            }
                        ?>
                            <tr class="task-row-item <?php echo $isDone ? 'task-row-completed' : ''; ?>" id="task-row-<?php echo $t['id']; ?>" style="border-bottom: 1px solid var(--border-color); transition: background 0.15s ease;">
                                
                                <!-- Checkbox Toggle -->
                                <td style="text-align: center; vertical-align: middle; padding: 0.65rem 0.5rem;">
                                    <input type="checkbox" 
                                           class="task-complete-checkbox" 
                                           <?php echo $isDone ? 'checked' : ''; ?> 
                                           onchange="toggleTaskStatus(<?php echo $t['id']; ?>, this)" 
                                           style="width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer;"
                                           title="<?php echo $isDone ? 'Mark Pending' : 'Mark Completed'; ?>">
                                </td>

                                <!-- S.No -->
                                <td style="text-align: center; vertical-align: middle; font-family: monospace; font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">
                                    <?php echo $sno++; ?>
                                </td>

                                <!-- Task Title & Type -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem;">
                                    <div class="flex flex-col">
                                        <div class="flex align-center gap-2">
                                            <span class="font-bold text-xs task-title-text" style="color: var(--text-main); font-size: 0.85rem; <?php echo $isDone ? 'text-decoration: line-through; opacity: 0.6;' : ''; ?>">
                                                <?php echo htmlspecialchars($t['title']); ?>
                                            </span>
                                            <span class="badge" style="background: var(--bg-app); border: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.68rem; padding: 1px 6px;">
                                                <?php echo htmlspecialchars($t['task_type']); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($t['description'])): ?>
                                            <span class="text-xs text-muted" style="font-size: 0.75rem; margin-top: 2px; max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?php echo htmlspecialchars($t['description']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($checkItems)): ?>
                                            <span class="text-xs text-muted flex align-center gap-1 mt-1" style="font-size: 0.7rem;">
                                                <i data-lucide="check" style="width: 11px; height: 11px; color: var(--primary);"></i>
                                                <span>Checklist: <?php echo count($checkItems); ?> items</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Related Lead / Customer -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem;">
                                    <?php if (!empty($t['related_id'])): ?>
                                        <div class="flex flex-col">
                                            <div class="flex align-center gap-1">
                                                <i data-lucide="link" style="width: 11px; height: 11px; color: var(--primary);"></i>
                                                <?php if (strcasecmp($t['related_to'], 'Lead') === 0): ?>
                                                    <a href="index.php?page=lead_details&id=<?php echo urlencode($t['related_id']); ?>" class="text-xs font-bold text-primary hover-underline">
                                                        <?php echo htmlspecialchars($t['related_id']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-xs font-bold text-primary"><?php echo htmlspecialchars($t['related_id']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($t['related_title'])): ?>
                                                <span class="text-xs text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($t['related_title']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-muted">General Workload</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Assigned Operator -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem;">
                                    <div class="flex align-center gap-2">
                                        <div style="width: 22px; height: 22px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center;">
                                            <?php echo strtoupper(substr($t['assigned_to'] ?: 'U', 0, 1)); ?>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-xs font-semibold" style="color: var(--text-main);"><?php echo htmlspecialchars($t['assigned_to']); ?></span>
                                            <?php if (!empty($t['created_by']) && $t['created_by'] !== $t['assigned_to']): ?>
                                                <span class="text-muted" style="font-size: 0.65rem;">by <?php echo htmlspecialchars($t['created_by']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Priority -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem;">
                                    <span class="badge" style="background: <?php echo $pStyle['bg']; ?>; color: <?php echo $pStyle['color']; ?>; border: 1px solid <?php echo $pStyle['border']; ?>; font-size: 0.72rem; font-weight: 700;">
                                        <?php echo htmlspecialchars($t['priority']); ?>
                                    </span>
                                </td>

                                <!-- Due Date & Time -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem; white-space: nowrap;">
                                    <div class="flex flex-col">
                                        <?php if ($isDone): ?>
                                            <span class="text-xs font-semibold" style="color: #10b981;"><?php echo $formattedDueDate; ?></span>
                                            <span class="text-muted" style="font-size: 0.68rem;">Done: <?php echo !empty($t['completed_at']) ? date('d M, h:i A', strtotime($t['completed_at'])) : 'Closed'; ?></span>
                                        <?php elseif ($isOverdue): ?>
                                            <span class="badge" style="background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.25); font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 3px;">
                                                <i data-lucide="alert-circle" style="width: 11px; height: 11px;"></i>
                                                <span><?php echo $formattedDueDate; ?> (Overdue)</span>
                                            </span>
                                            <?php if (!empty($timeStr)): ?>
                                                <span class="text-muted" style="font-size: 0.68rem; margin-top: 2px;"><?php echo $timeStr; ?></span>
                                            <?php endif; ?>
                                        <?php elseif ($isToday): ?>
                                            <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.25); font-size: 0.72rem; font-weight: 700;">
                                                Today <?php echo !empty($timeStr) ? '• ' . $timeStr : ''; ?>
                                            </span>
                                        <?php elseif ($isTomorrow): ?>
                                            <span class="badge" style="background: rgba(245, 124, 0, 0.12); color: #f57c00; border: 1px solid rgba(245, 124, 0, 0.25); font-size: 0.72rem; font-weight: 700;">
                                                Tomorrow <?php echo !empty($timeStr) ? '• ' . $timeStr : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs font-semibold" style="color: var(--text-main);"><?php echo $formattedDueDate; ?></span>
                                            <?php if (!empty($timeStr)): ?>
                                                <span class="text-muted" style="font-size: 0.68rem;"><?php echo $timeStr; ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Status Badge -->
                                <td style="vertical-align: middle; padding: 0.65rem 1rem;">
                                    <?php
                                    $statusBadges = [
                                        'Completed'   => ['bg' => 'rgba(16, 185, 129, 0.1)', 'color' => '#10b981', 'border' => 'rgba(16, 185, 129, 0.25)'],
                                        'In Progress' => ['bg' => 'rgba(59, 130, 246, 0.1)',  'color' => '#2563eb', 'border' => 'rgba(59, 130, 246, 0.25)'],
                                        'Cancelled'   => ['bg' => 'rgba(107, 114, 128, 0.1)','color' => '#6b7280', 'border' => 'rgba(107, 114, 128, 0.25)'],
                                        'Pending'     => ['bg' => 'rgba(245, 158, 11, 0.1)', 'color' => '#d97706', 'border' => 'rgba(245, 158, 11, 0.25)']
                                    ];
                                    $sStyle = $statusBadges[$t['status']] ?? $statusBadges['Pending'];
                                    ?>
                                    <span class="badge" style="background: <?php echo $sStyle['bg']; ?>; color: <?php echo $sStyle['color']; ?>; border: 1px solid <?php echo $sStyle['border']; ?>; font-size: 0.72rem; font-weight: 700;">
                                        <?php echo htmlspecialchars($t['status']); ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td style="vertical-align: middle; text-align: right; padding: 0.65rem 1rem;">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" class="btn btn-secondary text-xs p-1" style="height: 28px; width: 28px; display: inline-flex; align-items: center; justify-content: center;" onclick="editTaskModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" title="Edit Task">
                                            <i data-lucide="edit-2" style="width: 13px; height: 13px;"></i>
                                        </button>
                                        <?php if ($is_admin || $t['created_by_id'] == $user_id): ?>
                                            <button type="button" class="btn btn-secondary text-xs p-1" style="height: 28px; width: 28px; display: inline-flex; align-items: center; justify-content: center; color: var(--danger);" onclick="deleteTask(<?php echo $t['id']; ?>)" title="Delete Task">
                                                <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Modal: Create / Edit Task Drawer Modal -->
<div id="taskModal" class="modal-overlay hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1050; backdrop-filter: blur(4px);">
    <div class="card p-0" style="width: 580px; max-width: 95vw; max-height: 90vh; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        
        <!-- Modal Header -->
        <div class="flex justify-between align-center p-4" style="border-bottom: 1px solid var(--border-color); background: var(--bg-app);">
            <div class="flex align-center gap-2">
                <i data-lucide="check-square" style="width: 18px; height: 18px; color: var(--primary);"></i>
                <h3 class="text-base font-bold m-0" id="taskModalTitle" style="color: var(--text-main);">Create New Task</h3>
            </div>
            <button type="button" class="gear-settings-btn" onclick="closeTaskModal()" style="border: none; background: transparent; cursor: pointer; color: var(--text-muted);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <!-- Modal Form Body -->
        <form id="taskUpsertForm" onsubmit="submitTaskForm(event)" style="display: flex; flex-direction: column; overflow-y: auto; flex: 1; margin: 0;">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="task_id" id="task_form_id" value="0">

            <div class="p-5 flex flex-col gap-4">
                
                <!-- Task Title -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold" style="color: var(--text-main);">Task Title / Objective <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="title" id="task_form_title" class="form-control" placeholder="E.g. Follow-up on license quotation or live demo walkthrough" required>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <!-- Task Type -->
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Task Category / Type</label>
                        <select name="task_type" id="task_form_type" class="form-control text-xs">
                            <option value="General">General</option>
                            <option value="Call Follow-up">Call Follow-up</option>
                            <option value="Live Demo">Live Demo</option>
                            <option value="Installation">Installation</option>
                            <option value="Quotation Review">Quotation Review</option>
                            <option value="Client Support">Client Support</option>
                            <option value="Payment Collection">Payment Collection</option>
                        </select>
                    </div>

                    <!-- Priority -->
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Priority Level</label>
                        <select name="priority" id="task_form_priority" class="form-control text-xs">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <!-- Due Date & Time with Quick Buttons -->
                <div>
                    <label class="form-label text-xs font-semibold">Due Date & Time <span style="color: var(--danger);">*</span></label>
                    <div class="grid" style="grid-template-columns: 1.2fr 1fr; gap: 0.75rem;">
                        <input type="date" name="due_date" id="task_form_due_date" class="form-control text-xs" value="<?php echo $today_str; ?>" required>
                        <input type="time" name="due_time" id="task_form_due_time" class="form-control text-xs" value="11:00">
                    </div>
                    <!-- Quick Date Pills -->
                    <div class="flex gap-1.5 mt-1.5 flex-wrap">
                        <button type="button" class="btn btn-secondary text-xs py-0.5 px-2" style="font-size: 0.7rem;" onclick="setQuickDueDate('today')">Today</button>
                        <button type="button" class="btn btn-secondary text-xs py-0.5 px-2" style="font-size: 0.7rem;" onclick="setQuickDueDate('tomorrow')">Tomorrow</button>
                        <button type="button" class="btn btn-secondary text-xs py-0.5 px-2" style="font-size: 0.7rem;" onclick="setQuickDueDate('+2 days')">+2 Days</button>
                        <button type="button" class="btn btn-secondary text-xs py-0.5 px-2" style="font-size: 0.7rem;" onclick="setQuickDueDate('+7 days')">Next Week</button>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <!-- Assignee -->
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Assign to Operator</label>
                        <select name="assigned_to" id="task_form_assigned_to" class="form-control text-xs">
                            <?php if (!empty($operators_list)): ?>
                                <?php foreach ($operators_list as $op): ?>
                                    <option value="<?php echo htmlspecialchars($op['name']); ?>" <?php echo strcasecmp($op['name'], $user_name) === 0 ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($op['name']); ?> (<?php echo htmlspecialchars($op['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="<?php echo htmlspecialchars($user_name); ?>" selected><?php echo htmlspecialchars($user_name); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Status</label>
                        <select name="status" id="task_form_status" class="form-control text-xs">
                            <option value="Pending" selected>Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <!-- Related Entity Link -->
                <div class="grid" style="grid-template-columns: 1fr 1.5fr; gap: 1rem;">
                    <div class="form-group m-0">
                        <label class="form-label text-xs font-semibold">Related Entity</label>
                        <select name="related_to" id="task_form_related_to" class="form-control text-xs" onchange="handleRelatedToChange(this.value)">
                            <option value="General">General (None)</option>
                            <option value="Lead" selected>Customer Lead</option>
                            <option value="Ticket">Support Ticket</option>
                        </select>
                    </div>
                    <div class="form-group m-0" id="related_id_wrapper">
                        <label class="form-label text-xs font-semibold">Select Lead / Entity</label>
                        <select name="related_id" id="task_form_related_id" class="form-control text-xs">
                            <option value="">-- Choose Lead / Contact --</option>
                            <?php foreach ($recent_leads as $rl): ?>
                                <option value="<?php echo htmlspecialchars($rl['id']); ?>">
                                    <?php echo htmlspecialchars($rl['id'] . ' - ' . ($rl['name'] ?: $rl['company'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Description / Work Notes</label>
                    <textarea name="description" id="task_form_description" class="form-control text-xs" rows="3" placeholder="Add specific task notes, instructions, or meeting agenda..."></textarea>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="flex justify-end gap-2 p-4" style="border-top: 1px solid var(--border-color); background: var(--bg-app);">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeTaskModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs" id="taskSubmitBtn">Save Task</button>
            </div>
        </form>
    </div>
</div>

<style>
.task-manager-container {
    width: 100%;
}
.task-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-muted);
    text-decoration: none;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}
.task-filter-tab:hover {
    background: var(--bg-app);
    color: var(--text-main);
}
.task-filter-tab.active {
    background: rgba(37, 99, 235, 0.1);
    color: var(--primary);
    border-color: rgba(37, 99, 235, 0.25);
    font-weight: 700;
}
.task-filter-tab .pill-count {
    background: var(--border-color);
    color: var(--text-main);
    font-size: 0.68rem;
    padding: 1px 6px;
    border-radius: 10px;
    font-weight: 700;
}
.task-filter-tab.active .pill-count {
    background: var(--primary);
    color: #ffffff;
}
.task-row-completed {
    opacity: 0.65;
    background: rgba(0, 0, 0, 0.015);
}
[data-theme="dark"] .task-row-completed {
    background: rgba(255, 255, 255, 0.02);
}
.task-row-item:hover {
    background: var(--bg-app);
}
</style>

<script>
// Quick Date Helper
function setQuickDueDate(period) {
    const d = new Date();
    if (period === 'tomorrow') {
        d.setDate(d.getDate() + 1);
    } else if (period === '+2 days') {
        d.setDate(d.getDate() + 2);
    } else if (period === '+7 days') {
        d.setDate(d.getDate() + 7);
    }
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const el = document.getElementById('task_form_due_date');
    if (el) el.value = `${yyyy}-${mm}-${dd}`;
}

function handleRelatedToChange(val) {
    const wrapper = document.getElementById('related_id_wrapper');
    if (!wrapper) return;
    if (val === 'General') {
        wrapper.style.display = 'none';
    } else {
        wrapper.style.display = 'block';
    }
}

// Open Create Modal
function openCreateTaskModal() {
    document.getElementById('taskModalTitle').innerText = 'Create New Task';
    document.getElementById('task_form_id').value = '0';
    document.getElementById('task_form_title').value = '';
    document.getElementById('task_form_type').value = 'General';
    document.getElementById('task_form_priority').value = 'Medium';
    document.getElementById('task_form_status').value = 'Pending';
    document.getElementById('task_form_description').value = '';
    document.getElementById('task_form_related_id').value = '';
    setQuickDueDate('today');

    const modal = document.getElementById('taskModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.remove('hidden');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// Edit Existing Task Modal
function editTaskModal(task) {
    if (!task) return;
    document.getElementById('taskModalTitle').innerText = 'Edit Task Record #' + task.id;
    document.getElementById('task_form_id').value = task.id;
    document.getElementById('task_form_title').value = task.title || '';
    document.getElementById('task_form_type').value = task.task_type || 'General';
    document.getElementById('task_form_priority').value = task.priority || 'Medium';
    document.getElementById('task_form_status').value = task.status || 'Pending';
    document.getElementById('task_form_due_date').value = task.due_date || '';
    document.getElementById('task_form_due_time').value = task.due_time ? task.due_time.substring(0, 5) : '11:00';
    document.getElementById('task_form_assigned_to').value = task.assigned_to || '';
    document.getElementById('task_form_related_to').value = task.related_to || 'General';
    document.getElementById('task_form_related_id').value = task.related_id || '';
    document.getElementById('task_form_description').value = task.description || '';
    
    handleRelatedToChange(task.related_to || 'General');

    const modal = document.getElementById('taskModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.remove('hidden');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeTaskModal() {
    const modal = document.getElementById('taskModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.add('hidden');
    }
}

// Submit Form via AJAX
function submitTaskForm(e) {
    e.preventDefault();
    const btn = document.getElementById('taskSubmitBtn');
    if (btn) btn.disabled = true;

    const form = document.getElementById('taskUpsertForm');
    const formData = new FormData(form);

    fetch('index.php?page=tasks', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.disabled = false;
        if (data.success) {
            closeTaskModal();
            location.reload();
        } else {
            alert(data.message || 'Error saving task.');
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Server connection error. Please try again.');
    });
}

// Toggle Task Completion Status
function toggleTaskStatus(taskId, cb) {
    const newStatus = cb.checked ? 'Completed' : 'Pending';
    const fd = new FormData();
    fd.append('action', 'toggle_status');
    fd.append('task_id', taskId);
    fd.append('status', newStatus);

    fetch('index.php?page=tasks', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('task-row-' + taskId);
            if (row) {
                if (newStatus === 'Completed') {
                    row.classList.add('task-row-completed');
                    const titleEl = row.querySelector('.task-title-text');
                    if (titleEl) {
                        titleEl.style.textDecoration = 'line-through';
                        titleEl.style.opacity = '0.6';
                    }
                } else {
                    row.classList.remove('task-row-completed');
                    const titleEl = row.querySelector('.task-title-text');
                    if (titleEl) {
                        titleEl.style.textDecoration = 'none';
                        titleEl.style.opacity = '1';
                    }
                }
            }
        } else {
            cb.checked = !cb.checked;
            alert(data.message || 'Failed to update task.');
        }
    })
    .catch(() => {
        cb.checked = !cb.checked;
        alert('Connection error while updating task.');
    });
}

// Delete Task
function deleteTask(taskId) {
    if (!confirm('Are you sure you want to delete this task? This action cannot be undone.')) return;

    const fd = new FormData();
    fd.append('action', 'delete_task');
    fd.append('task_id', taskId);

    fetch('index.php?page=tasks', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('task-row-' + taskId);
            if (row) row.remove();
        } else {
            alert(data.message || 'Error deleting task.');
        }
    })
    .catch(() => alert('Connection error while deleting task.'));
}

// Export Tasks to CSV
function exportTasksToCsv() {
    const table = document.getElementById('tasksDataTable');
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');
    rows.forEach(r => {
        let cols = r.querySelectorAll('th, td');
        let rowData = [];
        cols.forEach((c, idx) => {
            // Skip Done checkbox and Actions columns
            if (idx === 0 || idx === cols.length - 1) return;
            let text = c.innerText.replace(/"/g, '""').trim();
            rowData.push('"' + text + '"');
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });

    const csvContent = 'data:text/csv;charset=utf-8,' + csv.join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', 'marg_crm_tasks_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

