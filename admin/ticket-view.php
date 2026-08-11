<?php
/**
 * Marg CRM - Detailed Ticket Management Page
 * 
 * Shows ticket details, customer contact info, license status,
 * problem description, timeline, and status update form.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ticket = null;
$customerDetails = null;

if ($pdo && $id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ticket && !empty($ticket['license_number'])) {
            $stmtC = $pdo->prepare("SELECT * FROM customers WHERE license_no = ? LIMIT 1");
            $stmtC->execute([$ticket['license_number']]);
            $customerDetails = $stmtC->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $db_error = $e->getMessage();
    }
}

if (!$ticket) {
    echo "<h2>Ticket Not Found</h2><p><a href='ticket-list.php'>Back to Ticket List</a></p>";
    exit;
}

$flashMsg = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Details - <?php echo htmlspecialchars($ticket['ticket_number']); ?></title>
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
        .container { max-width: 1100px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 10px; }
        
        .btn { padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; }
        .btn-primary { background-color: var(--primary-btn); color: white; }
        .btn-secondary { background-color: #1e293b; color: var(--text-main); border: 1px solid var(--card-border); }

        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }

        .card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-title { font-size: 16px; font-weight: 600; margin-bottom: 16px; color: #fff; border-bottom: 1px solid var(--card-border); padding-bottom: 10px; }

        .info-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; }
        .info-label { color: var(--text-muted); font-weight: 500; }
        .info-val { color: #fff; font-weight: 600; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .badge-open { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-closed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px; font-weight: 500; }
        .form-group select, .form-group textarea, .form-group input { width: 100%; background: #0b0f19; border: 1px solid var(--card-border); border-radius: 6px; padding: 10px; color: #fff; font-size: 14px; outline: none; }
        .form-group textarea { height: 100px; resize: vertical; }

        .alert-success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1><i class="fa-solid fa-ticket"></i> Ticket <?php echo htmlspecialchars($ticket['ticket_number']); ?></h1>
            <div style="display: flex; gap: 10px;">
                <a href="ticket-list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
            </div>
        </div>

        <?php if ($flashMsg): ?>
            <div class="alert-success"><?php echo htmlspecialchars($flashMsg); ?></div>
        <?php endif; ?>

        <div class="grid">
            
            <!-- Left Column: Details & Description -->
            <div>
                <div class="card">
                    <div class="card-title"><i class="fa-solid fa-file-text"></i> Ticket Information</div>
                    
                    <div class="info-row">
                        <span class="info-label">Ticket Number:</span>
                        <span class="info-val"><strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Category:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['category']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Priority:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['priority']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-val">
                            <span class="badge <?php echo in_array($ticket['status'], ['Closed', 'Resolved']) ? 'badge-closed' : 'badge-open'; ?>">
                                <?php echo htmlspecialchars($ticket['status']); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created At:</span>
                        <span class="info-val"><?php echo date('d M Y, h:i A', strtotime($ticket['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Assigned Support Engineer:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['assigned_to'] ?? 'Unassigned'); ?></span>
                    </div>

                    <div style="margin-top: 20px; border-top: 1px solid var(--card-border); padding-top: 16px;">
                        <h4 style="font-size: 14px; color: var(--text-muted); margin-bottom: 8px;">Problem Description</h4>
                        <div style="background: #0b0f19; padding: 14px; border-radius: 8px; border: 1px solid var(--card-border); line-height: 1.6; white-space: pre-wrap;">
                            <?php echo htmlspecialchars($ticket['description']); ?>
                        </div>
                    </div>

                    <?php if (!empty($ticket['internal_notes'])): ?>
                        <div style="margin-top: 20px; border-top: 1px solid var(--card-border); padding-top: 16px;">
                            <h4 style="font-size: 14px; color: #fbbf24; margin-bottom: 8px;"><i class="fa-solid fa-note-sticky"></i> Internal Notes</h4>
                            <div style="background: rgba(245, 158, 11, 0.1); padding: 14px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.3); line-height: 1.6; white-space: pre-wrap;">
                                <?php echo htmlspecialchars($ticket['internal_notes']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Customer Details & Update Form -->
            <div>
                <!-- Customer Card -->
                <div class="card">
                    <div class="card-title"><i class="fa-solid fa-user"></i> Customer Details</div>
                    
                    <div class="info-row">
                        <span class="info-label">License #:</span>
                        <span class="info-val"><code><?php echo htmlspecialchars($ticket['license_number'] ?? 'N/A'); ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Customer Name:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['customer_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Firm Name:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['firm_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Mobile Number:</span>
                        <span class="info-val"><a href="https://wa.me/<?php echo preg_replace('/\D/','',$ticket['mobile']); ?>" target="_blank" style="color: #25d366; text-decoration: none;"><i class="fa-brands fa-whatsapp"></i> <?php echo htmlspecialchars($ticket['mobile']); ?></a></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-val"><?php echo htmlspecialchars($ticket['email'] ?? 'N/A'); ?></span>
                    </div>
                    <?php if ($customerDetails): ?>
                        <div class="info-row">
                            <span class="info-label">AMC Expiry:</span>
                            <span class="info-val"><?php echo htmlspecialchars($customerDetails['amc_expiry'] ?? 'Active'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Update Ticket Form -->
                <div class="card">
                    <div class="card-title"><i class="fa-solid fa-pen-to-square"></i> Update Ticket Status</div>
                    
                    <form method="POST" action="ticket-update.php">
                        <input type="hidden" name="id" value="<?php echo $ticket['id']; ?>">
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Open" <?php echo $ticket['status']==='Open'?'selected':''; ?>>Open</option>
                                <option value="In Progress" <?php echo $ticket['status']==='In Progress'?'selected':''; ?>>In Progress</option>
                                <option value="Resolved" <?php echo $ticket['status']==='Resolved'?'selected':''; ?>>Resolved</option>
                                <option value="Closed" <?php echo $ticket['status']==='Closed'?'selected':''; ?>>Closed</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Assign Support Engineer</label>
                            <input type="text" name="assigned_to" value="<?php echo htmlspecialchars($ticket['assigned_to'] ?? ''); ?>" placeholder="e.g. Vikas Patel">
                        </div>

                        <div class="form-group">
                            <label>Internal Note / Remarks</label>
                            <textarea name="internal_notes" placeholder="Add update notes..."><?php echo htmlspecialchars($ticket['internal_notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label><input type="checkbox" name="notify_customer" value="1" checked> Send WhatsApp Notification to Customer</label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fa-solid fa-paper-plane"></i> Save Changes</button>
                    </form>
                </div>
            </div>

        </div>

    </div>
</body>
</html>
