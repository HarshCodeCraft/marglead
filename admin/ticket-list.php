<?php
/**
 * Marg CRM - WhatsApp Ticket List View
 * 
 * Filterable datatable of support tickets with search, status filters,
 * pagination, and CSV export.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Handle CSV Export
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=whatsapp_tickets_' . date('Y-m-d_H-i') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Ticket Number', 'License Number', 'Customer Name', 'Firm Name', 'Mobile', 'Email', 'Category', 'Priority', 'Status', 'Description', 'Created At']);

    if ($pdo) {
        $stmt = $pdo->query("SELECT ticket_number, license_number, customer_name, firm_name, mobile, email, category, priority, status, description, created_at FROM tickets ORDER BY id DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit;
}

// Filters & Pagination
$search   = trim($_GET['search'] ?? '');
$status   = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(ticket_number LIKE ? OR customer_name LIKE ? OR license_number LIKE ? OR mobile LIKE ? OR firm_name LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if (!empty($status)) {
    $where[] = "status = ?";
    $params[] = $status;
}

if (!empty($priority)) {
    $where[] = "priority = ?";
    $params[] = $priority;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$tickets = [];
if ($pdo) {
    try {
        $sql = "SELECT * FROM tickets $whereClause ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets - WhatsApp Ticket System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #151b2c;
            --card-border: #232d45;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
            --accent-blue: #3b82f6;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --primary-btn: #2563eb;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-main); padding: 24px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #ffffff; }
        
        .btn { padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; }
        .btn-primary { background-color: var(--primary-btn); color: white; }
        .btn-success { background-color: #059669; color: white; }
        .btn-secondary { background-color: #1e293b; color: var(--text-main); border: 1px solid var(--card-border); }

        .filter-bar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 16px; margin-bottom: 24px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .filter-bar input, .filter-bar select { background: #0b0f19; border: 1px solid var(--card-border); color: white; padding: 8px 12px; border-radius: 6px; font-size: 14px; outline: none; }
        .filter-bar input { flex: 1; min-width: 200px; }

        .card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 20px; overflow-x: auto; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 16px; color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid var(--card-border); }
        td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; color: var(--text-main); }
        tr:hover { background-color: rgba(255,255,255,0.02); }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .badge-open { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-in_progress { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-closed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-priority-high { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-priority-medium { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge-priority-low { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1>Support Tickets List</h1>
            <div style="display: flex; gap: 10px;">
                <a href="dashboard.php" class="btn btn-secondary"><i class="fa-solid fa-gauge"></i> Dashboard</a>
                <a href="ticket-list.php?action=export_csv" class="btn btn-success"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" action="ticket-list.php" class="filter-bar">
            <input type="text" name="search" placeholder="Search by Ticket #, Customer Name, License, Mobile..." value="<?php echo htmlspecialchars($search); ?>">
            
            <select name="status">
                <option value="">All Statuses</option>
                <option value="Open" <?php echo $status==='Open'?'selected':''; ?>>Open</option>
                <option value="In Progress" <?php echo $status==='In Progress'?'selected':''; ?>>In Progress</option>
                <option value="Resolved" <?php echo $status==='Resolved'?'selected':''; ?>>Resolved</option>
                <option value="Closed" <?php echo $status==='Closed'?'selected':''; ?>>Closed</option>
            </select>

            <select name="priority">
                <option value="">All Priorities</option>
                <option value="Low" <?php echo $priority==='Low'?'selected':''; ?>>Low</option>
                <option value="Medium" <?php echo $priority==='Medium'?'selected':''; ?>>Medium</option>
                <option value="High" <?php echo $priority==='High'?'selected':''; ?>>High</option>
                <option value="Critical" <?php echo $priority==='Critical'?'selected':''; ?>>Critical</option>
            </select>

            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="ticket-list.php" class="btn btn-secondary">Reset</a>
        </form>

        <!-- Tickets Table -->
        <div class="card">
            <?php if (empty($tickets)): ?>
                <p style="color: var(--text-muted); padding: 20px; text-align: center;">No tickets matching your filter criteria.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>License #</th>
                            <th>Customer Name</th>
                            <th>Firm Name</th>
                            <th>Mobile</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($t['ticket_number']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($t['license_number'] ?? 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($t['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($t['firm_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($t['mobile']); ?></td>
                                <td><?php echo htmlspecialchars($t['category']); ?></td>
                                <td>
                                    <span class="badge badge-priority-<?php echo strtolower($t['priority']); ?>">
                                        <?php echo htmlspecialchars($t['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo in_array($t['status'], ['Closed', 'Resolved']) ? 'badge-closed' : ($t['status']==='In Progress'?'badge-in_progress':'badge-open'); ?>">
                                        <?php echo htmlspecialchars($t['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?></td>
                                <td>
                                    <a href="ticket-view.php?id=<?php echo $t['id']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>
