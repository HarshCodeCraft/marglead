            </main> <!-- /content-body -->
        </div> <!-- /main-content -->
    </div> <!-- /app-wrapper -->

    <!-- Global Modals Section -->
    
    <!-- 1. Keyboard-Activated Global Search Modal (Ctrl + K) -->
    <div id="global-search-modal" class="modal-overlay">
        <div class="modal-container" style="max-width: 600px;">
            <div class="modal-header" style="border: none; padding-bottom: 0.5rem;">
                <div class="search-input-wrapper flex align-center gap-4 w-full" style="background-color: var(--border-card); padding: 0.75rem 1.25rem; border-radius: var(--border-radius-md); border: 1px solid var(--border-color);">
                    <i data-lucide="search" class="text-muted" style="width: 20px; height: 20px;"></i>
                    <input type="text" placeholder="Type here to search leads, contacts, invoices..." class="w-full text-sm" style="border: none; background: transparent; outline: none; font-size: 1rem;" autofocus>
                    <span class="text-xs text-muted" style="background-color: var(--border-color); padding: 0.15rem 0.4rem; border-radius: 4px;">ESC</span>
                </div>
            </div>
            <div class="modal-body" style="padding-top: 0;">
                <div class="search-results-section" style="margin-top: 1rem;">
                    <h5 class="text-xs text-muted font-bold" style="text-transform: uppercase; margin-bottom: 0.75rem;">Recent Leads</h5>
                    <div class="results-list flex flex-col gap-2">
                        <a href="index.php?page=lead_details&id=1" class="result-item flex align-center justify-between pointer" style="padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); border: 1px solid transparent; transition: all var(--transition-fast);">
                            <div class="flex align-center gap-3">
                                <i data-lucide="building-2" class="text-muted" style="width: 18px; height: 18px;"></i>
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold">Apex Pharma Solutions</span>
                                    <span class="text-xs text-muted">ID: #LD-9021 • Source: Website</span>
                                </div>
                            </div>
                            <span class="badge" style="--badge-bg: var(--danger-light); --badge-color: var(--danger);">Hot</span>
                        </a>
                        <a href="index.php?page=lead_details&id=2" class="result-item flex align-center justify-between pointer" style="padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); border: 1px solid transparent; transition: all var(--transition-fast);">
                            <div class="flex align-center gap-3">
                                <i data-lucide="stethoscope" class="text-muted" style="width: 18px; height: 18px;"></i>
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold">Dr. Verma Clinic</span>
                                    <span class="text-xs text-muted">ID: #LD-7890 • Source: Cold Call</span>
                                </div>
                            </div>
                            <span class="badge" style="--badge-bg: var(--warning-light); --badge-color: var(--warning);">Warm</span>
                        </a>
                    </div>
                </div>

                <div class="search-results-section" style="margin-top: 1.5rem;">
                    <h5 class="text-xs text-muted font-bold" style="text-transform: uppercase; margin-bottom: 0.75rem;">System Navigation Shortcuts</h5>
                    <div class="results-list grid" style="grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <a href="index.php?page=lead_form" class="shortcut-item flex align-center gap-3" style="padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); background-color: var(--border-card); border: 1px solid var(--border-color);">
                            <i data-lucide="plus-circle" style="width: 16px; height: 16px; color: var(--primary);"></i>
                            <span class="text-xs font-semibold">Create New Lead</span>
                        </a>
                        <a href="index.php?page=pipeline" class="shortcut-item flex align-center gap-3" style="padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); background-color: var(--border-card); border: 1px solid var(--border-color);">
                            <i data-lucide="kanban-square" style="width: 16px; height: 16px; color: var(--accent);"></i>
                            <span class="text-xs font-semibold">Open Kanban Board</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 0.75rem 1.5rem; background-color: var(--border-card); border-bottom-left-radius: var(--border-radius-lg); border-bottom-right-radius: var(--border-radius-lg);">
                <span class="text-xs text-muted flex align-center gap-2">
                    <i data-lucide="info" style="width: 12px; height: 12px;"></i> Press <kbd style="background: var(--border-color); padding: 0.1rem 0.3rem; border-radius: 3px;">ESC</kbd> to close. Use Arrow keys to navigate results.
                </span>
                <button id="close-search-modal" class="btn btn-secondary text-xs" style="padding: 0.4rem 0.8rem; margin-left: auto;">Close</button>
            </div>
        </div>
    </div>

    <!-- Core Javascript Code -->
    <script src="assets/js/main.js"></script>

    <!-- Contextual Module JS imports -->
    <?php
    $page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
    if ($page === 'dashboard'):
    ?>
        <!-- Chart.js CDN -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <!-- Charts setup script -->
        <script src="assets/js/charts-setup.js"></script>
    <?php elseif ($page === 'pipeline'): ?>
        <script src="assets/js/pipeline.js"></script>
    <?php elseif ($page === 'followups'): ?>
        <script src="assets/js/calendar.js"></script>
    <?php elseif ($page === 'lead_details'): ?>
        <script src="assets/js/lead-details.js"></script>
    <?php endif; ?>
    
    <!-- Render Lucide Icons to make sure all newly mounted elements display icons correctly -->
    <script>
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>

    <?php if (isset($_SESSION['sms_simulation_batch'])): 
        $batch = $_SESSION['sms_simulation_batch'];
        unset($_SESSION['sms_simulation_batch']); // Clear
    ?>
    <div class="sms-toast-container" id="sms-toast">
        <div class="sms-toast-header">
            <div class="flex align-center gap-2">
                <i data-lucide="message-square" style="width: 16px; height: 16px; color: #10b981;"></i>
                <strong style="font-size: 0.85rem; color: #1e293b;">SMS Notification (Free Route)</strong>
            </div>
            <button onclick="document.getElementById('sms-toast').remove();" style="background: none; border: none; color: #64748b; cursor: pointer; display: flex; align-items: center; padding: 0;">
                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
            </button>
        </div>
        <div class="sms-toast-body">
            <div style="margin-bottom: 10px;">
                <span class="text-xs text-muted block mb-1" style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--primary);">Sent Recipients:</span>
                <ul style="margin: 0; padding-left: 1rem; font-size: 0.75rem; color: #475569; list-style-type: disc;">
                    <?php foreach ($batch['targets'] as $tgt): ?>
                        <li style="margin-bottom: 2px;">
                            <strong><?php echo $tgt['role']; ?>:</strong> <?php echo htmlspecialchars($tgt['phone']); ?> 
                            <span style="color: #10b981; font-weight: bold; margin-left: 0.25rem;">✓ Sent</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p style="margin: 0; font-size: 0.8rem; line-height: 1.4; color: #1e293b; font-family: monospace; background: #f8fafc; padding: 10px; border-radius: 6px; border-left: 4px solid #10b981; word-break: break-word; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);">
                <?php echo htmlspecialchars($batch['message']); ?>
            </p>
            <span style="font-size: 0.65rem; color: #94a3b8; display: block; margin-top: 8px;">*Delivered free via Marg Soft operator integration.</span>
        </div>
    </div>

    <style>
    .sms-toast-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 320px;
        background-color: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15), 0 10px 10px -5px rgba(0,0,0,0.04);
        z-index: 999999;
        overflow: hidden;
        animation: smsSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: 'Inter', sans-serif;
    }
    .sms-toast-header {
        background-color: #f1f5f9;
        padding: 12px 16px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .sms-toast-body {
        padding: 16px;
    }
    @keyframes smsSlideIn {
        from { transform: translateY(100px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    </style>
    <?php endif; ?>
</body>
</html>
