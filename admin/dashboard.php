<?php
/**
 * Marg CRM - WhatsApp Ticket Management Dashboard
 * 
 * Provides metrics overview, ticket statistics, status breakdown,
 * and quick access to ticket list and WhatsApp testing.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Calculate Key Statistics
$totalTickets = 0;
$todayTickets = 0;
$openTickets  = 0;
$closedTickets= 0;
$recentTickets= [];

if ($pdo) {
    try {
        $totalTickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
        $todayTickets = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $openTickets  = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'open', 'In Progress', 'in_progress')")->fetchColumn();
        $closedTickets= (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Closed', 'closed', 'Resolved', 'resolved')")->fetchColumn();

        $stmtRecent = $pdo->query("SELECT * FROM tickets ORDER BY id DESC LIMIT 5");
        $recentTickets = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
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
    <title>WhatsApp Ticket Dashboard - Marg CRM</title>
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
        .dashboard-container { max-width: 1200px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; }
        .header h1 i { color: #25d366; }
        .nav-links { display: flex; gap: 12px; }
        .btn { padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; border: none; cursor: pointer; }
        .btn-primary { background-color: var(--primary-btn); color: white; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .btn-secondary { background-color: #1e293b; color: var(--text-main); border: 1px solid var(--card-border); }
        .btn-secondary:hover { background-color: #334155; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 52px; height: 52px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-blue { background: rgba(59, 130, 246, 0.15); color: var(--accent-blue); }
        .stat-green { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); }
        .stat-amber { background: rgba(245, 158, 11, 0.15); color: var(--accent-amber); }
        .stat-red { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .stat-info h3 { font-size: 13px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        .stat-info .value { font-size: 28px; font-weight: 700; margin-top: 4px; color: #ffffff; }

        .card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { text-align: left; padding: 12px 16px; color: var(--text-muted); font-size: 12px; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid var(--card-border); }
        td { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; color: var(--text-main); }
        tr:hover { background-color: rgba(255,255,255,0.02); }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .badge-open { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-closed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-priority-high { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-priority-medium { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge-priority-low { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; }

        .config-box { background: rgba(37, 211, 102, 0.1); border: 1px solid rgba(37, 211, 102, 0.3); border-radius: 10px; padding: 16px; margin-bottom: 24px; font-size: 14px; line-height: 1.6; }
        .config-box code { background: #000; padding: 2px 6px; border-radius: 4px; color: #25d366; font-family: monospace; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <!-- Header -->
        <div class="header">
            <h1><i class="fa-brands fa-whatsapp"></i> WhatsApp Support Ticket Dashboard</h1>
            <div class="nav-links">
                <a href="ticket-list.php" class="btn btn-primary"><i class="fa-solid fa-list"></i> All Tickets</a>
                <a href="../index.php" class="btn btn-secondary"><i class="fa-solid fa-house"></i> CRM Home</a>
            </div>
        </div>

        <!-- Webhook Status Banner -->
        <div class="config-box">
            <strong><i class="fa-solid fa-circle-check" style="color: #25d366;"></i> Live System Webhook URLs Configured:</strong><br>
            Webhook Endpoint: <code><?php echo BASE_URL; ?>api/webhook.php</code><br>
            Flow Endpoint: <code><?php echo BASE_URL; ?>api/flow-endpoint.php</code>
        </div>

        <!-- Metric Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon stat-blue"><i class="fa-solid fa-ticket"></i></div>
                <div class="stat-info">
                    <h3>Total Tickets</h3>
                    <div class="value"><?php echo number_format($totalTickets); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-amber"><i class="fa-solid fa-calendar-day"></i></div>
                <div class="stat-info">
                    <h3>Today's Tickets</h3>
                    <div class="value"><?php echo number_format($todayTickets); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-red"><i class="fa-solid fa-folder-open"></i></div>
                <div class="stat-info">
                    <h3>Open Tickets</h3>
                    <div class="value"><?php echo number_format($openTickets); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-green"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-info">
                    <h3>Closed / Resolved</h3>
                    <div class="value"><?php echo number_format($closedTickets); ?></div>
                </div>
            </div>
        </div>

        <!-- Recent Tickets Table -->
        <div class="card">
            <div class="card-title">
                <span>Recent Support Tickets</span>
                <a href="ticket-list.php" style="color: var(--accent-blue); text-decoration: none; font-size: 14px;">View All &rarr;</a>
            </div>
            <?php if (empty($recentTickets)): ?>
                <p style="color: var(--text-muted); padding: 20px 0; text-align: center;">No tickets found. Submit a ticket from WhatsApp Flow to see it here!</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Customer Name</th>
                            <th>License #</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTickets as $t): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($t['ticket_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($t['customer_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($t['license_number'] ?? 'N/A'); ?></code></td>
                                <td><?php echo htmlspecialchars($t['category']); ?></td>
                                <td>
                                    <span class="badge badge-priority-<?php echo strtolower($t['priority']); ?>">
                                        <?php echo htmlspecialchars($t['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo in_array($t['status'], ['Closed', 'Resolved']) ? 'badge-closed' : 'badge-open'; ?>">
                                        <?php echo htmlspecialchars($t['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></td>
                                <td>
                                    <a href="ticket-view.php?id=<?php echo $t['id']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">View</a>
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
