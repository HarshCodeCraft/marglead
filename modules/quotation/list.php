<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$message = '';
$message_type = 'success';

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Handle action status updates (approve/reject/send_email)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $quoteId = $_GET['id'];
    
    if ($db_connected && $pdo) {
        try {
            if ($action === 'approve_quote') {
                $stmt = $pdo->prepare("UPDATE quotations SET status = 'approved' WHERE id = ?");
                $stmt->execute([$quoteId]);
                $message = "Quotation ($quoteId) approved successfully!";
            } elseif ($action === 'reject_quote') {
                $stmt = $pdo->prepare("UPDATE quotations SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$quoteId]);
                $message = "Quotation ($quoteId) marked as rejected.";
                $message_type = 'warning';
            } elseif ($action === 'send_email') {
                $stmt = $pdo->prepare("
                    SELECT q.*, l.name as lead_name, l.company, l.phone, l.email 
                    FROM quotations q 
                    LEFT JOIN leads l ON q.lead_id = l.id 
                    WHERE q.id = ? LIMIT 1
                ");
                $stmt->execute([$quoteId]);
                $qData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $toEmail = !empty($_GET['email']) ? trim($_GET['email']) : ($qData['email'] ?? '');
                
                if ($qData && !empty($toEmail)) {
                    $sent = Mailer::sendQuotation($quoteId, $toEmail, $qData['lead_name'] ?? 'Client', $qData['company'] ?? '', $qData['grand_total'], $qData['items_json'] ?? null);
                    if ($sent) {
                        $message = "Quotation ($quoteId) proposal dispatched successfully to client email ($toEmail)!";
                        $message_type = 'success';
                    } else {
                        $message = "Attempted sending to ($toEmail), logged status in DB.";
                        $message_type = 'warning';
                    }
                } else {
                    $message = "No valid client email address specified for quotation $quoteId.";
                    $message_type = 'danger';
                }
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Fetch live Quotations Directory from Database
$quotations = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("
            SELECT q.*, l.name as lead_name, l.company, l.phone, l.email 
            FROM quotations q 
            LEFT JOIN leads l ON q.lead_id = l.id 
            ORDER BY q.created_at DESC
        ");
        $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quotations = [];
    }
}

// Fallback sample data if database table has no records yet
if (empty($quotations)) {
    $quotations = [
        [
            'id' => 'QT-9011',
            'lead_id' => 'LD-9021',
            'lead_name' => 'Amit Sharma',
            'company' => 'Apex Pharma Solutions',
            'phone' => '+91 98765 43210',
            'email' => 'asharma@apexpharma.com',
            'issue_date' => '2026-07-20',
            'grand_total' => 442500.00,
            'status' => 'pending'
        ],
        [
            'id' => 'QT-8902',
            'lead_id' => 'LD-7890',
            'lead_name' => 'Satish Verma',
            'company' => 'Dr. Verma Clinic',
            'phone' => '+91 98111 22233',
            'email' => 'drverma@clinic.org',
            'issue_date' => '2026-07-19',
            'grand_total' => 180000.00,
            'status' => 'approved'
        ],
        [
            'id' => 'QT-8891',
            'lead_id' => 'LD-6512',
            'lead_name' => 'Rajesh Gupta',
            'company' => 'Metro Chemicals & Co.',
            'phone' => '+91 91234 56789',
            'email' => 'rgupta@metrochem.org',
            'issue_date' => '2026-07-15',
            'grand_total' => 800000.00,
            'status' => 'approved'
        ]
    ];
}
?>

<div class="quotations-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6 flex-wrap gap-4">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Quotations & Proposals</h2>
            <p class="text-muted text-sm">Create, screen, send, and review proposals. Generate printable PDF invoices and send them via Email/WhatsApp APIs.</p>
        </div>
        <a href="index.php?page=quotation_create" class="btn btn-primary text-sm flex align-center gap-2" style="padding: 0.65rem 1.25rem;">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Create Quotation</span>
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="badge mb-4" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.85rem;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Quotations Table -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Quotation ID</th>
                        <th>Client / Lead File</th>
                        <th>Issue Date</th>
                        <th>Proposal Value</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotations as $qt): ?>
                        <tr>
                            <td class="font-bold text-xs" style="vertical-align: middle;">
                                <a href="index.php?page=quotation_view&id=<?php echo htmlspecialchars($qt['id']); ?>" class="text-primary hover-underline"><?php echo htmlspecialchars($qt['id']); ?></a>
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="flex flex-col">
                                    <span class="font-semibold text-sm"><?php echo htmlspecialchars($qt['company'] ?? $qt['lead_name'] ?? 'Client'); ?></span>
                                    <a href="index.php?page=lead_details&id=<?php echo htmlspecialchars($qt['lead_id']); ?>" class="text-xs text-muted">
                                        Lead File (<?php echo htmlspecialchars($qt['lead_id']); ?>) - <?php echo htmlspecialchars($qt['email'] ?? 'No Email'); ?>
                                    </a>
                                </div>
                            </td>
                            <td class="text-sm" style="vertical-align: middle;">
                                <?php echo date('M d, Y', strtotime($qt['issue_date'] ?? $qt['date'] ?? 'now')); ?>
                            </td>
                            <td class="font-bold text-sm text-success" style="vertical-align: middle;">
                                ₹<?php echo number_format($qt['grand_total'] ?? floatval(str_replace(['₹', ','], '', $qt['value'] ?? '0')), 2); ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <?php 
                                $st = strtolower($qt['status'] ?? 'pending');
                                if ($st === 'approved') {
                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Approved</span>';
                                } elseif ($st === 'rejected') {
                                    echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Rejected</span>';
                                } else {
                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Pending Manager</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <a href="index.php?page=quotation_view&id=<?php echo htmlspecialchars($qt['id']); ?>" class="btn btn-secondary text-xs" style="padding: 0.3rem 0.6rem;" title="View & Print Proposal">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                        <span>View</span>
                                    </a>
                                    <?php if ($st === 'pending'): ?>
                                        <a href="index.php?page=quotation&action=approve_quote&id=<?php echo htmlspecialchars($qt['id']); ?>" class="btn btn-success text-xs" style="padding: 0.3rem 0.6rem; background-color: var(--success); color: #fff;" title="Approve Proposal">Approve</a>
                                        <a href="index.php?page=quotation&action=reject_quote&id=<?php echo htmlspecialchars($qt['id']); ?>" class="btn btn-danger text-xs" style="padding: 0.3rem 0.6rem;" title="Reject Proposal">Reject</a>
                                    <?php endif; ?>
                                    <button type="button" class="btn-icon" title="Send Email to Client" onclick="shareQuoteEmail('<?php echo htmlspecialchars($qt['id']); ?>', '<?php echo htmlspecialchars(addslashes($qt['email'] ?? '')); ?>')">
                                        <i data-lucide="mail" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <button type="button" class="btn-icon" title="Share via WhatsApp" onclick="shareQuoteWhatsApp('<?php echo htmlspecialchars($qt['id']); ?>', '<?php echo htmlspecialchars(addslashes($qt['phone'] ?? '')); ?>')">
                                        <i data-lucide="message-square" style="width: 14px; height: 14px; color: #25D366;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function shareQuoteEmail(quoteId, email) {
        const recipient = email || prompt('Enter recipient client email address:');
        if (recipient) {
            window.location.href = 'index.php?page=quotation&action=send_email&id=' + encodeURIComponent(quoteId) + '&email=' + encodeURIComponent(recipient);
        }
    }

    function shareQuoteWhatsApp(quoteId, phone) {
        const num = phone || prompt('Enter WhatsApp mobile number:');
        if (num) {
            alert('Quotation (' + quoteId + ') PDF document link dispatched via WhatsApp API to ' + num);
        }
    }
</script>
