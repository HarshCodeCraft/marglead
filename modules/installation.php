<?php
require_once __DIR__ . '/../includes/config.php';

// Mock Installation Tasks
$installations = [
    [
        'id' => 'INS-201',
        'lead_id' => 'LD-9021',
        'customer' => 'Apex Pharma Solutions',
        'city' => 'New Delhi',
        'engineer' => 'Vikas Patel',
        'date' => '2026-07-24 10:00 AM',
        'checklist' => '0/5',
        'percent' => 0,
        'status' => 'assigned'
    ],
    [
        'id' => 'INS-199',
        'lead_id' => 'LD-7890',
        'customer' => 'Dr. Satish Verma Clinic',
        'city' => 'Mumbai',
        'engineer' => 'Praveen Kumar',
        'date' => '2026-07-20 02:00 PM',
        'checklist' => '5/5',
        'percent' => 100,
        'status' => 'completed'
    ],
    [
        'id' => 'INS-194',
        'lead_id' => 'LD-6512',
        'customer' => 'Metro Chemicals & Co.',
        'city' => 'Ahmedabad',
        'engineer' => 'Anil Kumar',
        'date' => '2026-07-22 11:30 AM',
        'checklist' => '3/5',
        'percent' => 60,
        'status' => 'in_progress'
    ]
];
?>

<div class="installations-container">
    <!-- Header -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Installation Worksheets</h2>
            <p class="text-muted text-sm">Monitor software installations, schedule field engineers, track setup checklist progress, and log customer sign-off sheets.</p>
        </div>
    </div>

    <!-- Installations Table Grid -->
    <div class="card p-0 overflow-hidden" style="border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Worksheet ID</th>
                        <th>Client Customer</th>
                        <th>Site Location</th>
                        <th>Assigned Engineer</th>
                        <th>Scheduled Date</th>
                        <th>Checklist Tasks</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($installations as $ins): ?>
                        <tr>
                            <td class="font-bold text-xs"><?php echo $ins['id']; ?></td>
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-semibold text-sm"><?php echo $ins['customer']; ?></span>
                                    <a href="index.php?page=lead_details&id=<?php echo $ins['lead_id']; ?>" class="text-xs text-primary">View Folder (<?php echo $ins['lead_id']; ?>)</a>
                                </div>
                            </td>
                            <td class="text-sm font-semibold"><?php echo $ins['city']; ?></td>
                            <td class="text-sm"><?php echo $ins['engineer']; ?></td>
                            <td class="text-sm"><?php echo $ins['date']; ?></td>
                            <td>
                                <div class="flex align-center gap-2">
                                    <div style="flex: 1; background-color: var(--border-color); height: 6px; width: 80px; border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?php echo $ins['percent']; ?>%; background-color: <?php echo $ins['percent'] === 100 ? 'var(--success)' : 'var(--primary)'; ?>; height: 100%;"></div>
                                    </div>
                                    <span class="text-xs text-muted font-bold"><?php echo $ins['checklist']; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php 
                                if ($ins['status'] === 'completed') {
                                    echo '<span class="badge" style="--badge-bg: var(--success-light); --badge-color: var(--success);">Completed</span>';
                                } elseif ($ins['status'] === 'in_progress') {
                                    echo '<span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">In-Progress</span>';
                                } else {
                                    echo '<span class="badge" style="--badge-bg: var(--info-light); --badge-color: var(--info);">Assigned</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: right; vertical-align: middle;">
                                <div class="flex justify-end gap-1">
                                    <button class="btn btn-secondary text-xs" style="padding: 0.35rem 0.75rem;" onclick="openAssignModal('<?php echo $ins['id']; ?>', '<?php echo $ins['engineer']; ?>')">Reassign</button>
                                    <a href="index.php?page=lead_details&id=<?php echo $ins['lead_id']; ?>&tab=tab-installation" class="btn btn-primary text-xs" style="padding: 0.35rem 0.75rem;">Checklist</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Assign Engineer -->
<div id="assign-engineer-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Assign Field Installation Engineer</h3>
            <button class="btn-icon" onclick="window.closeModal('assign-engineer-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" onsubmit="event.preventDefault(); alert('Installation engineer updated successfully.'); window.closeModal('assign-engineer-modal');">
            <input type="hidden" id="assign-install-id">
            <div class="form-group m-0">
                <label class="form-label text-xs">Select Installation Engineer</label>
                <select class="form-control" id="engineer-select-options">
                    <option value="Vikas Patel">Vikas Patel (North Region)</option>
                    <option value="Praveen Kumar">Praveen Kumar (West Region)</option>
                    <option value="Anil Kumar">Anil Kumar (South Region)</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Scheduled Target Date & Time</label>
                <input type="datetime-local" class="form-control" required value="2026-07-24T10:00">
            </div>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('assign-engineer-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAssignModal(id, currentEngineer) {
        document.getElementById('assign-install-id').value = id;
        
        const select = document.getElementById('engineer-select-options');
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === currentEngineer) {
                select.selectedIndex = i;
                break;
            }
        }
        
        window.openModal('assign-engineer-modal');
    }
</script>
