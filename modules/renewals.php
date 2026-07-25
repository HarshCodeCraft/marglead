<?php
require_once __DIR__ . '/../includes/config.php';

// Mock Renewals Directory
$renewals = [
    [
        'id' => 'RNW-902',
        'lead_id' => 'LD-9021',
        'customer' => 'Apex Pharma Solutions',
        'product' => 'Marg ERP Pro License',
        'expiry' => '2026-08-15',
        'days' => 25,
        'value' => '₹45,000',
        'status' => 'active'
    ],
    [
        'id' => 'RNW-889',
        'lead_id' => 'LD-7890',
        'customer' => 'Dr. Verma Diagnostic Clinic',
        'product' => 'Marg ERP Basic Suite',
        'expiry' => '2026-07-10',
        'days' => -11, // Expired
        'value' => '₹18,000',
        'status' => 'expired'
    ],
    [
        'id' => 'RNW-851',
        'lead_id' => 'LD-6512',
        'customer' => 'Metro Chemicals & Co.',
        'product' => 'Marg ERP Gold Enterprise',
        'expiry' => '2026-07-28',
        'days' => 7,
        'value' => '₹80,000',
        'status' => 'grace'
    ]
];
?>

<div class="renewals-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Renewals Management</h2>
            <p class="text-muted text-sm">Monitor software support contracts expiries, manage grace periods, generate renewal quotations, and dispatch automated invoices.</p>
        </div>
    </div>

    <!-- Renewal Expiries Row -->
    <div class="grid mb-6" style="grid-template-columns: repeat(4, 1fr); gap: 1rem;">
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--success);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Active Subscriptions</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--success);">142 Clients</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--warning);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Expiries (< 30 days)</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--warning);">5 Accounts</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--danger);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Grace Periods / Overdue</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading); color: var(--danger);">2 Clients</span>
        </div>
        <div class="card p-4" style="border: 1px solid var(--border-color); border-left: 4px solid var(--primary);">
            <span class="text-xs text-muted font-bold block mb-1" style="text-transform: uppercase;">Renewal Forecast (Month)</span>
            <span class="text-xl font-bold" style="font-family: var(--font-heading);">₹1.83 Lakhs</span>
        </div>
    </div>

    <!-- Renewals Table -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Renewal ID</th>
                        <th>Client Customer</th>
                        <th>Product License</th>
                        <th>Expiry Date</th>
                        <th>Timeline Status</th>
                        <th>Renewal Fee (INR)</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($renewals as $rn): ?>
                        <tr>
                            <td class="font-bold text-xs"><?php echo $rn['id']; ?></td>
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-semibold text-sm"><?php echo $rn['customer']; ?></span>
                                    <a href="index.php?page=lead_details&id=<?php echo $rn['lead_id']; ?>" class="text-xs text-primary">View Folder (<?php echo $rn['lead_id']; ?>)</a>
                                </div>
                            </td>
                            <td class="text-sm font-semibold"><?php echo $rn['product']; ?></td>
                            <td class="text-sm"><?php echo $rn['expiry']; ?></td>
                            <td>
                                <?php if ($rn['days'] < 0): ?>
                                    <span class="text-xs text-danger font-bold">Expired <?php echo abs($rn['days']); ?> days ago</span>
                                <?php elseif ($rn['days'] <= 7): ?>
                                    <span class="text-xs text-warning font-bold">Expires in <?php echo $rn['days']; ?> days!</span>
                                <?php else: ?>
                                    <span class="text-xs text-muted"><?php echo $rn['days']; ?> days left</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-bold text-sm text-success"><?php echo $rn['value']; ?></td>
                            <td>
                                <?php 
                                if ($rn['status'] === 'active') {
                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Active</span>';
                                } elseif ($rn['status'] === 'grace') {
                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Grace Period</span>';
                                } else {
                                    echo '<span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Expired</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <button class="btn btn-secondary text-xs" style="padding: 0.35rem 0.75rem;" onclick="alert('Sending automated renewal reminder via WhatsApp...');"><i data-lucide="bell" style="width: 12px; height: 12px; display:inline;"></i> Remind</button>
                                    <a href="index.php?page=quotation_create&lead=<?php echo $rn['lead_id']; ?>" class="btn btn-primary text-xs" style="padding: 0.35rem 0.75rem;">Create Quote</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
