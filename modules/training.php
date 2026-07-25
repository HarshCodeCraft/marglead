<?php
require_once __DIR__ . '/../includes/config.php';

// Mock Training sessions
$trainings = [
    [
        'id' => 'TRN-501',
        'lead_id' => 'LD-9021',
        'customer' => 'Apex Pharma Solutions',
        'trainer' => 'Prakash Raj',
        'date' => '2026-07-25 11:00 AM',
        'hours' => '0 / 6 Hours',
        'status' => 'scheduled'
    ],
    [
        'id' => 'TRN-492',
        'lead_id' => 'LD-7890',
        'customer' => 'Dr. Verma Diagnostic Clinic',
        'trainer' => 'Sonal Mehta',
        'date' => '2026-07-21 04:00 PM',
        'hours' => '6 / 6 Hours',
        'status' => 'certified'
    ],
    [
        'id' => 'TRN-487',
        'lead_id' => 'LD-6512',
        'customer' => 'Metro Chemicals & Co.',
        'trainer' => 'Prakash Raj',
        'date' => '2026-07-22 09:30 AM',
        'hours' => '3 / 6 Hours',
        'status' => 'active'
    ]
];
?>

<div class="trainings-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Customer Training Registry</h2>
            <p class="text-muted text-sm">Schedule product trainers, verify training hours, checklist user certification assessments, and track user readiness reports.</p>
        </div>
        <button class="btn btn-primary text-sm" onclick="window.openModal('schedule-training-modal');">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Allocate Trainer</span>
        </button>
    </div>

    <!-- Training List -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Training ID</th>
                        <th>Client Customer</th>
                        <th>Assigned Trainer</th>
                        <th>Scheduled Date</th>
                        <th>Training Hours</th>
                        <th>Certification Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trainings as $tr): ?>
                        <tr>
                            <td class="font-bold text-xs"><?php echo $tr['id']; ?></td>
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-semibold text-sm"><?php echo $tr['customer']; ?></span>
                                    <a href="index.php?page=lead_details&id=<?php echo $tr['lead_id']; ?>" class="text-xs text-primary">View Folder (<?php echo $tr['lead_id']; ?>)</a>
                                </div>
                            </td>
                            <td class="text-sm font-semibold"><?php echo $tr['trainer']; ?></td>
                            <td class="text-sm"><?php echo $tr['date']; ?></td>
                            <td class="font-semibold text-xs"><?php echo $tr['hours']; ?></td>
                            <td>
                                <?php 
                                if ($tr['status'] === 'certified') {
                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Certified Users</span>';
                                } elseif ($tr['status'] === 'active') {
                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In-Progress</span>';
                                } else {
                                    echo '<span class="badge" style="--badge-bg: var(--info-light); --badge-color: var(--info);">Scheduled</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <button class="btn btn-secondary text-xs" style="padding: 0.35rem 0.75rem;" onclick="openCertifyModal('<?php echo $tr['customer']; ?>')">Certify</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Certify users checklist -->
<div id="certify-users-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Certify Software Operators</h3>
            <button class="btn-icon" onclick="window.closeModal('certify-users-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" onsubmit="event.preventDefault(); alert('Users certification completed and updated in Lead timeline.'); window.closeModal('certify-users-modal');">
            <div class="form-group m-0">
                <label class="form-label text-xs">Client Business</label>
                <input type="text" id="certify-client-name" class="form-control" readonly>
            </div>
            
            <h4 class="text-xs text-muted font-bold block mt-2" style="text-transform: uppercase;">Assessment Checklist</h4>
            <div class="flex flex-col gap-2">
                <label class="flex align-center gap-3 pointer text-sm">
                    <input type="checkbox" checked style="accent-color: var(--primary);">
                    <span>Operator understands billing & item configuration</span>
                </label>
                <label class="flex align-center gap-3 pointer text-sm">
                    <input type="checkbox" checked style="accent-color: var(--primary);">
                    <span>Operator understands barcode generation & batch expiries</span>
                </label>
                <label class="flex align-center gap-3 pointer text-sm">
                    <input type="checkbox" style="accent-color: var(--primary);">
                    <span>Operator understands GST GSTR-1 & GSTR-3B filings reporting</span>
                </label>
            </div>
            
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('certify-users-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Approve Certificate</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Allocate Trainer -->
<div id="schedule-training-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Schedule Trainer Session</h3>
            <button class="btn-icon" onclick="window.closeModal('schedule-training-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" onsubmit="event.preventDefault(); alert('Trainer allocated and session notifications sent.'); window.closeModal('schedule-training-modal');">
            <div class="form-group m-0">
                <label class="form-label text-xs">Customer Lead File</label>
                <select class="form-control">
                    <option value="LD-9021">Apex Pharma Solutions</option>
                    <option value="LD-7890">Dr. Verma Clinic</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Trainer</label>
                <select class="form-control">
                    <option value="Prakash">Prakash Raj (Senior Trainer)</option>
                    <option value="Sonal">Sonal Mehta (Technical Lead)</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Scheduled Target Date</label>
                <input type="datetime-local" class="form-control" required value="2026-07-25T11:00">
            </div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('schedule-training-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Allocation</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCertifyModal(clientName) {
        document.getElementById('certify-client-name').value = clientName;
        window.openModal('certify-users-modal');
    }
</script>
