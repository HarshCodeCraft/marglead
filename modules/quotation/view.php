<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

// Resolve ID
$quoteId = isset($_GET['id']) ? trim($_GET['id']) : 'QT-9011';
$message = '';
$message_type = 'success';

// Fetch Quotation Record from Database first
$quote = null;
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT q.*, l.name as lead_name, l.company, l.phone, l.email, l.address, l.contact_person, l.assigned_to, l.gstin 
            FROM quotations q 
            LEFT JOIN leads l ON q.lead_id = l.id 
            WHERE q.id = ? LIMIT 1
        ");
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quote = null;
    }
}

// Fallback data if quotation not found in database
if (!$quote) {
    $quote = [
        'id' => $quoteId,
        'lead_id' => 'LD-9021',
        'lead_name' => 'Amit Sharma',
        'company' => 'Apex Pharma Solutions',
        'phone' => '+91 98765 43210',
        'email' => 'asharma@apexpharma.com',
        'address' => 'Okhla Industrial Area Phase 2, New Delhi - 110020',
        'contact_person' => 'Mr. Amit Sharma',
        'assigned_to' => 'Amit Sen (Sales Executive)',
        'gstin' => '07AAAAA1111A1Z1',
        'issue_date' => '2026-07-20',
        'valid_until' => '2026-08-20',
        'taxable_amount' => 375000.00,
        'gst_amount' => 67500.00,
        'grand_total' => 442500.00,
        'status' => 'pending',
        'created_by' => 'Harsh Vardhan',
        'items_json' => json_encode([
            [
                'product' => 'Marg ERP - Pro Inventory Suite License',
                'qty' => 5,
                'price' => 75000.00,
                'gst' => 18,
                'total' => 442500.00
            ]
        ])
    ];
}

// Handle Action Updates (Approve / Reject / Email Client)
if (isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($db_connected && $pdo) {
        try {
            if ($act === 'approve') {
                $stmt = $pdo->prepare("UPDATE quotations SET status = 'approved' WHERE id = ?");
                $stmt->execute([$quoteId]);
                $quote['status'] = 'approved';
                $message = "Quotation ($quoteId) approved successfully!";
            } elseif ($act === 'reject') {
                $stmt = $pdo->prepare("UPDATE quotations SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$quoteId]);
                $quote['status'] = 'rejected';
                $message = "Quotation ($quoteId) marked as rejected.";
                $message_type = 'warning';
            } elseif ($act === 'email_client') {
                $toEmail = !empty($_REQUEST['email']) ? trim($_REQUEST['email']) : ($quote['email'] ?? '');
                if (!empty($toEmail)) {
                    $sent = Mailer::sendQuotation($quoteId, $toEmail, $quote['lead_name'] ?? 'Client', $quote['company'] ?? '', $quote['grand_total'], $quote['items_json'] ?? null);
                    if ($sent) {
                        $message = "Official Quotation & Proposal ($quoteId) sent successfully to $toEmail!";
                        $message_type = 'success';
                    } else {
                        $message = "Attempted sending to ($toEmail), logged in sent_emails DB table.";
                        $message_type = 'warning';
                    }
                } else {
                    $message = "No client email address found for this proposal.";
                    $message_type = 'danger';
                }
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Decode items JSON
$items = [];
if (!empty($quote['items_json'])) {
    $items = json_decode($quote['items_json'], true);
}
if (!is_array($items) || empty($items)) {
    $items = [
        [
            'product' => 'Marg ERP - Software Suite',
            'qty' => 1,
            'price' => floatval($quote['taxable_amount']),
            'gst' => ($quote['taxable_amount'] > 0) ? round(($quote['gst_amount'] / $quote['taxable_amount']) * 100) : 18,
            'total' => floatval($quote['grand_total'])
        ]
    ];
}
?>

<div class="quotation-view-container" style="max-width: 850px; margin: 0 auto;">
    <?php if (!empty($message)): ?>
        <div class="badge mb-4 print-hidden" style="--badge-bg: var(--<?php echo $message_type; ?>-light); --badge-color: var(--<?php echo $message_type; ?>); padding: 0.75rem 1rem; width: 100%; display: flex; font-size: 0.85rem;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Page Header (Non-printable controls area) -->
    <div class="flex justify-between align-center mb-6 print-hidden flex-wrap gap-3" style="background-color: var(--border-card); padding: 1rem; border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
        <div class="flex align-center gap-2">
            <a href="index.php?page=quotation" class="btn btn-secondary text-xs">
                <i data-lucide="arrow-left" style="width: 14px; height: 14px;"></i>
                <span>Return to List</span>
            </a>
            <?php 
            $st = strtolower($quote['status'] ?? 'pending');
            if ($st === 'approved') {
                echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Approved</span>';
            } elseif ($st === 'rejected') {
                echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Rejected</span>';
            } else {
                echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Pending Signature</span>';
            }
            ?>
        </div>
        <div class="flex gap-2 align-center flex-wrap">
            <?php if ($st === 'pending'): ?>
                <a href="index.php?page=quotation_view&id=<?php echo htmlspecialchars($quote['id']); ?>&action=approve" class="btn btn-success text-xs" style="background-color: var(--success); color: #fff;">Approve Proposal</a>
                <a href="index.php?page=quotation_view&id=<?php echo htmlspecialchars($quote['id']); ?>&action=reject" class="btn btn-danger text-xs">Reject</a>
            <?php endif; ?>
            <button class="btn btn-secondary text-xs" onclick="window.print();">
                <i data-lucide="printer" style="width: 14px; height: 14px;"></i>
                <span>Print / Save PDF</span>
            </button>
            <a href="index.php?page=bank_accounts" target="_blank" class="btn btn-secondary text-xs" title="View & Share Corporate Bank Accounts & Payment QR Codes">
                <i data-lucide="qr-code" style="width: 14px; height: 14px;"></i>
                <span>Bank & QR Details</span>
            </a>
            <button type="button" class="btn btn-primary text-xs" onclick="sendProposalEmail('<?php echo htmlspecialchars($quote['id']); ?>', '<?php echo htmlspecialchars(addslashes($quote['email'] ?? '')); ?>')">
                <i data-lucide="mail" style="width: 14px; height: 14px;"></i>
                <span>Email Client</span>
            </button>
        </div>
    </div>

    <!-- Printable Quotation Page -->
    <div class="card p-8" id="printable-quotation-sheet" style="background-color: #ffffff; color: #1e293b; border: 1px solid #e2e8f0; font-family: 'Inter', sans-serif; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
        <!-- Letterhead Header -->
        <div class="flex justify-between align-start" style="border-bottom: 2px solid var(--primary); padding-bottom: 1.5rem; margin-bottom: 2rem;">
            <div>
                <!-- Brand title logo style -->
                <h1 style="font-family: var(--font-heading); font-weight: 800; font-size: 1.75rem; color: #0f172a; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="activity" style="color: #3b82f6; width: 28px; height: 28px;"></i>
                    <span>Marg ERP Limited</span>
                </h1>
                <p style="font-size: 0.825rem; color: #64748b; line-height: 1.5;">
                    2nd Floor, Corporate Tower B,<br>
                    Okhla Industrial Area Phase 1, New Delhi - 110020<br>
                    Email: sales@mangerp.com | Tel: +91 11 4500 9000<br>
                    GSTIN: 07AAAAM4509A1Z2
                </p>
            </div>
            <div style="text-align: right;">
                <h2 style="font-family: var(--font-heading); font-weight: 800; font-size: 1.5rem; color: #0f172a; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">PROPOSAL</h2>
                <div style="font-size: 0.825rem; color: #64748b; display: flex; flex-direction: column; gap: 0.25rem;">
                    <span><strong>Proposal No:</strong> <?php echo htmlspecialchars($quote['id']); ?></span>
                    <span><strong>Date:</strong> <?php echo date('M d, Y', strtotime($quote['issue_date'] ?? 'now')); ?></span>
                    <span><strong>Valid Until:</strong> <?php echo date('M d, Y', strtotime($quote['valid_until'] ?? '+30 days')); ?></span>
                </div>
            </div>
        </div>

        <!-- Bill To / Details Grid -->
        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2.5rem; font-size: 0.875rem;">
            <div>
                <h4 style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.25rem; margin-bottom: 0.75rem;">PROPOSAL PREPARED FOR</h4>
                <div style="line-height: 1.6; color: #334155;">
                    <strong style="color: #0f172a; font-size: 1rem;"><?php echo htmlspecialchars($quote['company'] ?? $quote['lead_name'] ?? 'Target Client'); ?></strong><br>
                    Attn: <?php echo htmlspecialchars($quote['contact_person'] ?? $quote['lead_name'] ?? 'Contact Person'); ?><br>
                    <?php echo nl2br(htmlspecialchars($quote['address'] ?? 'Corporate Address Unspecified')); ?><br>
                    <?php if (!empty($quote['gstin'])): ?>GSTIN: <?php echo htmlspecialchars($quote['gstin']); ?><br><?php endif; ?>
                    Phone: <?php echo htmlspecialchars($quote['phone'] ?? 'N/A'); ?><br>
                    Email: <?php echo htmlspecialchars($quote['email'] ?? 'N/A'); ?>
                </div>
            </div>
            <div>
                <h4 style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.25rem; margin-bottom: 0.75rem;">ENGAGEMENT PARAMETERS</h4>
                <div style="line-height: 1.6; color: #334155;">
                    <strong>Account Owner:</strong> <?php echo htmlspecialchars($quote['assigned_to'] ?? $quote['created_by'] ?? 'Sales Executive'); ?><br>
                    <strong>Lead Reference:</strong> <?php echo htmlspecialchars($quote['lead_id']); ?><br>
                    <strong>Deployment Mode:</strong> On-Site Client Servers / Cloud<br>
                    <strong>Status:</strong> <?php echo ucfirst(htmlspecialchars($quote['status'] ?? 'pending')); ?>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2.5rem; font-size: 0.875rem;">
            <thead>
                <tr style="border-bottom: 2px solid #cbd5e1; text-align: left;">
                    <th style="padding: 0.75rem 0.5rem; font-weight: 700; color: #0f172a;">Description of Products / Services</th>
                    <th style="padding: 0.75rem 0.5rem; font-weight: 700; color: #0f172a; width: 10%; text-align: center;">Qty</th>
                    <th style="padding: 0.75rem 0.5rem; font-weight: 700; color: #0f172a; width: 20%; text-align: right;">Unit Price (INR)</th>
                    <th style="padding: 0.75rem 0.5rem; font-weight: 700; color: #0f172a; width: 12%; text-align: center;">GST</th>
                    <th style="padding: 0.75rem 0.5rem; font-weight: 700; color: #0f172a; width: 20%; text-align: right;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 1rem 0.5rem; line-height: 1.5;">
                            <strong style="color: #0f172a;"><?php echo htmlspecialchars($item['product'] ?? 'Marg ERP Software Suite'); ?></strong>
                        </td>
                        <td style="padding: 1rem 0.5rem; text-align: center;"><?php echo intval($item['qty'] ?? 1); ?></td>
                        <td style="padding: 1rem 0.5rem; text-align: right;">₹<?php echo number_format(floatval($item['price'] ?? 0), 2); ?></td>
                        <td style="padding: 1rem 0.5rem; text-align: center;"><?php echo floatval($item['gst'] ?? 18); ?>%</td>
                        <td style="padding: 1rem 0.5rem; text-align: right; font-weight: 600;">₹<?php echo number_format(floatval($item['total'] ?? 0), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals & Summary Block -->
        <div class="grid" style="grid-template-columns: 1.2fr 1fr; gap: 2rem; margin-bottom: 3rem; font-size: 0.825rem;">
            <div>
                <h4 style="font-weight: 700; color: #0f172a; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.25rem; margin-bottom: 0.75rem;">Terms & Conditions</h4>
                <ol style="padding-left: 1.25rem; color: #64748b; line-height: 1.6; display: flex; flex-direction: column; gap: 0.25rem;">
                    <li>Payment Schedule: 50% advance, 50% post-installation confirmation.</li>
                    <li>Support details: Includes 1 year free telephonic and support tickets service.</li>
                    <li>Software renewal: Annual maintenance fees applicable from year 2.</li>
                </ol>
            </div>
            <div>
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <tbody>
                        <tr>
                            <td style="padding: 0.5rem 0; color: #64748b;">Taxable Subtotal</td>
                            <td style="padding: 0.5rem 0; text-align: right; font-weight: 600;">₹<?php echo number_format(floatval($quote['taxable_amount'] ?? 0), 2); ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 0.5rem 0; color: #64748b;">Total GST Tax</td>
                            <td style="padding: 0.5rem 0; text-align: right; font-weight: 600;">₹<?php echo number_format(floatval($quote['gst_amount'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 1rem 0 0 0; font-weight: 700; color: #0f172a; font-size: 1rem;">Gross Grand Total</td>
                            <td style="padding: 1rem 0 0 0; text-align: right; font-weight: 800; color: #10b981; font-size: 1.15rem;">₹<?php echo number_format(floatval($quote['grand_total'] ?? 0), 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Signature Lines -->
        <div class="flex justify-between" style="margin-top: 4rem; padding-top: 2rem; border-top: 1px solid #e2e8f0; font-size: 0.825rem; color: #64748b;">
            <div style="text-align: center; width: 200px;">
                <div style="height: 50px;"></div>
                <div style="border-top: 1px solid #cbd5e1; padding-top: 0.5rem; font-weight: 600; color: #0f172a;">Prepared By</div>
                <div><?php echo htmlspecialchars($quote['created_by'] ?? 'Marg ERP Limited Sales'); ?></div>
            </div>
            <div style="text-align: center; width: 200px;">
                <div style="height: 50px;"></div>
                <div style="border-top: 1px solid #cbd5e1; padding-top: 0.5rem; font-weight: 600; color: #0f172a;">Accepted By</div>
                <div>Authorized Client Signee</div>
            </div>
        </div>
    </div>
</div>

<script>
    function sendProposalEmail(quoteId, email) {
        const recipient = email || prompt('Enter recipient client email address:');
        if (recipient) {
            window.location.href = 'index.php?page=quotation_view&id=' + encodeURIComponent(quoteId) + '&action=email_client&email=' + encodeURIComponent(recipient);
        }
    }
</script>

<style>
/* CSS styles specifically for page prints */
@media print {
    body {
        background-color: #ffffff !important;
        color: #000000 !important;
    }
    .print-hidden, .header, .sidebar, #pipeline-toast-notification {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .content-body {
        padding: 0 !important;
    }
    #printable-quotation-sheet {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }
}
</style>
