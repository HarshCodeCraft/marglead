<?php
require_once __DIR__ . '/../includes/config.php';

// Mock Invoices
$invoices = [
    [
        'id' => 'INV-4509',
        'customer' => 'Apex Pharma Solutions',
        'date' => '2026-07-20',
        'total' => '₹4,50,000',
        'paid' => '₹0',
        'balance' => '₹4,50,000',
        'status' => 'pending'
    ],
    [
        'id' => 'INV-4482',
        'customer' => 'Dr. Verma Diagnostic Clinic',
        'date' => '2026-07-19',
        'total' => '₹1,80,000',
        'paid' => '₹1,80,000',
        'balance' => '₹0',
        'status' => 'paid'
    ],
    [
        'id' => 'INV-4391',
        'customer' => 'Metro Chemicals & Co.',
        'date' => '2026-07-15',
        'total' => '₹8,00,000',
        'paid' => '₹4,00,000',
        'balance' => '₹4,00,000',
        'status' => 'partial'
    ]
];
?>

<div class="payments-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Payments & Collections</h2>
            <p class="text-muted text-sm">Track invoicing milestones, verify bank transfers, log partial payments, and issue receipts to customers.</p>
        </div>
        <button class="btn btn-primary text-sm" onclick="window.openModal('record-payment-modal');">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Record Receipt</span>
        </button>
    </div>

    <!-- Receivables Stats Summary Row -->
    <div class="grid mb-6" style="grid-template-columns: repeat(4, 1fr); gap: 1rem;">
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--primary);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Total Receivables</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading);">₹14,30,000</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--success);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Collected revenue</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--success);">₹5,80,000</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--warning);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Pending Balance</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--warning);">₹8,50,000</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--danger);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Overdue Invoices</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--danger);">₹4,00,000</span>
        </div>
    </div>

    <!-- Invoices Table -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice ID</th>
                        <th>Client Customer</th>
                        <th>Issue Date</th>
                        <th>Total Amount</th>
                        <th>Paid</th>
                        <th>Balance Due</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="font-bold text-xs"><?php echo $inv['id']; ?></td>
                            <td class="font-semibold text-sm"><?php echo $inv['customer']; ?></td>
                            <td class="text-sm"><?php echo $inv['date']; ?></td>
                            <td class="font-bold text-sm"><?php echo $inv['total']; ?></td>
                            <td class="font-semibold text-sm text-success"><?php echo $inv['paid']; ?></td>
                            <td class="font-semibold text-sm text-warning"><?php echo $inv['balance']; ?></td>
                            <td>
                                <?php 
                                if ($inv['status'] === 'paid') {
                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Fully Paid</span>';
                                } elseif ($inv['status'] === 'partial') {
                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Partially Paid</span>';
                                } else {
                                    echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Unpaid</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <button class="btn btn-secondary text-xs" style="padding: 0.35rem 0.75rem;" onclick="alert('Downloading invoice PDF file...');"><i data-lucide="download" style="width: 12px; height: 12px; display:inline;"></i> PDF</button>
                                    <?php if ($inv['status'] !== 'paid'): ?>
                                        <button class="btn btn-primary text-xs" style="padding: 0.35rem 0.75rem;" onclick="openPaymentRecordModal('<?php echo $inv['id']; ?>', '<?php echo $inv['balance']; ?>')">Record Payment</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Record Receipt -->
<div id="record-payment-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Record Payment Receipt</h3>
            <button class="btn-icon" onclick="window.closeModal('record-payment-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" onsubmit="event.preventDefault(); alert('Payment receipt recorded successfully.'); window.closeModal('record-payment-modal');">
            <div class="form-group m-0">
                <label class="form-label text-xs">Target Invoice</label>
                <select class="form-control" id="payment-invoice-select">
                    <option value="INV-4509">INV-4509 (Apex Pharma Solutions) - ₹4,50,000 due</option>
                    <option value="INV-4391">INV-4391 (Metro Chemicals) - ₹4,00,000 due</option>
                </select>
            </div>
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs">Payment Method</label>
                    <select class="form-control">
                        <option value="bank">Bank Transfer (NEFT/RTGS)</option>
                        <option value="upi">UPI / QR Scan</option>
                        <option value="cheque">Cheque Deposit</option>
                        <option value="cash">Cash Received</option>
                    </select>
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs">Amount Received (INR)</label>
                    <input type="number" id="payment-amount-input" class="form-control" required value="450000">
                </div>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Transaction Reference / UTR Number</label>
                <input type="text" class="form-control" required placeholder="E.g. TXN9021876">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Upload Screenshot / Bank Receipt</label>
                <input type="file" class="form-control" accept="image/*, .pdf">
            </div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('record-payment-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPaymentRecordModal(invoiceId, balance) {
        const select = document.getElementById('payment-invoice-select');
        const amount = document.getElementById('payment-amount-input');
        
        // Find option matching ID and select it
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === invoiceId) {
                select.selectedIndex = i;
                break;
            }
        }
        
        // Clean balance value to insert
        const numericBalance = balance.replace(/[^0-9]/g, '');
        amount.value = numericBalance;
        
        window.openModal('record-payment-modal');
    }
</script>
