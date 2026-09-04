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
    <script src="assets/js/main.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?>"></script>

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

    <!-- Real-Time Floating Reminder Notifications Panel -->
    <div id="reminder-widget-wrapper" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999999; display: flex; flex-direction: column; width: 380px; max-width: calc(100vw - 32px); pointer-events: none;">
        
        <!-- Header Bar when multiple reminders exist -->
        <div id="reminder-header-bar" style="display: none; pointer-events: auto; background: #ffffff; border: 1px solid #e2e8f0; border-bottom: none; border-radius: 12px 12px 0 0; padding: 10px 14px; margin-bottom: -1px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #ef4444; color: #fff; font-size: 11px; font-weight: 800;" id="reminder-total-count">0</span>
                <span style="font-size: 0.82rem; font-weight: 700; color: #0f172a; letter-spacing: 0.01em;">Pending Reminders</span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 0.72rem; color: #64748b;">Scroll to view</span>
                <button type="button" onclick="dismissAllReminderToasts()" style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; border-radius: 6px; padding: 3px 9px; font-size: 0.7rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;">Clear All</button>
            </div>
        </div>

        <!-- Scrollable Cards Container -->
        <div id="reminder-toast-container" style="display: flex; flex-direction: column; gap: 10px; max-height: min(78vh, 600px); overflow-y: auto; overflow-x: hidden; padding: 4px 2px 4px 2px; pointer-events: auto; scroll-behavior: smooth;"></div>
    </div>

    <style>
    /* Custom Sleek Scrollbar for Reminders */
    #reminder-toast-container::-webkit-scrollbar {
        width: 5px;
    }
    #reminder-toast-container::-webkit-scrollbar-track {
        background: transparent;
    }
    #reminder-toast-container::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    #reminder-toast-container::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .reminder-toast-card {
        pointer-events: auto;
        position: relative;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 16px;
        color: #1e293b;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.12), 0 8px 10px -6px rgba(0, 0, 0, 0.08);
        animation: reminderSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        border-left: 5px solid #2563eb;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .reminder-toast-card:hover {
        box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.18), 0 10px 12px -5px rgba(0, 0, 0, 0.1);
    }

    .reminder-toast-card.alert-5min {
        border-left-color: #0284c7;
        background: #ffffff;
    }

    .reminder-toast-card.alert-duenow {
        border-left-color: #dc2626;
        background: #ffffff;
    }

    @keyframes reminderSlideUp {
        from { transform: translateY(30px) scale(0.97); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
    }
    </style>

    <script>
    (function() {
        // Audio Chime Synthesizer using Web Audio API
        function playReminderChime(isDueNow) {
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                const ctx = new AudioCtx();
                
                const now = ctx.currentTime;
                const osc1 = ctx.createOscillator();
                const gain = ctx.createGain();
                
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(isDueNow ? 880 : 587.33, now); // A5 or D5
                osc1.frequency.exponentialRampToValueAtTime(isDueNow ? 1174.66 : 880, now + 0.2); // D6 or A5
                
                gain.gain.setValueAtTime(0.15, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.45);
                
                osc1.connect(gain);
                gain.connect(ctx.destination);
                
                osc1.start(now);
                osc1.stop(now + 0.45);
            } catch (e) {}
        }

        // Request browser notification permission on page load
        if ("Notification" in window && Notification.permission !== "granted" && Notification.permission !== "denied") {
            try { Notification.requestPermission(); } catch(e) {}
        }

        function showDesktopNotification(title, body) {
            if ("Notification" in window && Notification.permission === "granted") {
                try {
                    new Notification(title, {
                        body: body,
                        icon: 'assets/images/logo.png'
                    });
                } catch(e) {}
            }
        }

        function updateReminderHeader() {
            const container = document.getElementById('reminder-toast-container');
            const headerBar = document.getElementById('reminder-header-bar');
            const countSpan = document.getElementById('reminder-total-count');
            if (!container || !headerBar) return;

            const cards = container.querySelectorAll('.reminder-toast-card');
            if (cards.length > 1) {
                headerBar.style.display = 'flex';
                if (countSpan) countSpan.textContent = cards.length;
            } else {
                headerBar.style.display = 'none';
            }
        }

        async function checkPendingReminders() {
            const container = document.getElementById('reminder-toast-container');
            if (!container) return;

            try {
                const res = await fetch('api/check_reminders.php', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await res.json();

                if (data.success && Array.isArray(data.reminders) && data.reminders.length > 0) {
                    let hasNewChime = false;

                    data.reminders.forEach(item => {
                        const alertKey = `reminded_${item.alert_type}_${item.reminder_kind}_${item.id}`;
                        const snoozeUntil = sessionStorage.getItem(alertKey);
                        if (snoozeUntil && (snoozeUntil === 'dismissed' || Date.now() < parseInt(snoozeUntil, 10))) {
                            return; // Snoozed or dismissed
                        }

                        // If transitioning to due_now, remove any previous 5min_warning toast card for this item
                        const isDueNow = (item.alert_type === 'due_now');
                        if (isDueNow) {
                            const prev5minCard = document.getElementById(`toast_reminded_5min_warning_${item.reminder_kind}_${item.id}`);
                            if (prev5minCard) prev5minCard.remove();
                        }

                        // Existing card check to avoid duplicate rendering
                        const cardId = `toast_${alertKey}`;
                        if (document.getElementById(cardId)) return;

                        // Create Toast Card
                        const card = document.createElement('div');
                        card.className = `reminder-toast-card ${isDueNow ? 'alert-duenow' : 'alert-5min'}`;
                        card.id = cardId;
                        card.setAttribute('data-alert-key', alertKey);

                        let badgeTitle = isDueNow ? 'Action Required Now!' : `Upcoming in ${item.mins_left}m`;
                        let badgeBg = isDueNow ? '#fee2e2' : '#e0f2fe';
                        let badgeColor = isDueNow ? '#b91c1c' : '#0369a1';
                        let badgeBorder = isDueNow ? '#fca5a5' : '#bae6fd';

                        let cleanPhone = (item.phone || '').replace(/[^0-9+]/g, '');
                        if (cleanPhone && !cleanPhone.startsWith('+') && cleanPhone.length === 10) cleanPhone = '+91' + cleanPhone;

                        const formattedTime = new Date(item.scheduled_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                        card.innerHTML = `
                            <!-- Top Header Line -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                <div style="display: inline-flex; align-items: center; gap: 5px; background: ${badgeBg}; border: 1px solid ${badgeBorder}; color: ${badgeColor}; border-radius: 6px; padding: 3px 8px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.02em;">
                                    <i data-lucide="${isDueNow ? 'bell' : 'clock'}" style="width: 13px; height: 13px;"></i>
                                    <span>${badgeTitle}</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 0.75rem; color: #64748b; font-weight: 600;">${formattedTime}</span>
                                    <button type="button" onclick="dismissReminderToast('${card.id}', '${alertKey}')" title="Dismiss" style="background: #f1f5f9; border: 1px solid #e2e8f0; color: #64748b; cursor: pointer; width: 22px; height: 22px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; line-height: 1; transition: all 0.15s ease;">&times;</button>
                                </div>
                            </div>

                            <!-- Lead & Client Name -->
                            <div style="font-weight: 700; font-size: 0.98rem; color: #0f172a; line-height: 1.3; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                                <i data-lucide="user" style="width: 15px; height: 15px; color: #0284c7; flex-shrink: 0;"></i>
                                <span style="font-weight: 700;">${escapeHtml(item.lead_name || 'Prospect Client')}</span>
                                ${item.company ? `<span style="color: #64748b; font-weight: 500; font-size: 0.82rem;">(${escapeHtml(item.company)})</span>` : ''}
                            </div>

                            <!-- Task / Action Description & Phone -->
                            <div style="font-size: 0.8rem; color: #475569; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="background: #f1f5f9; border: 1px solid #e2e8f0; color: #334155; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 0.75rem;">
                                    ${escapeHtml(item.action_type || 'Follow-up')}
                                </span>
                                ${cleanPhone ? `
                                    <span style="color: #64748b; font-size: 0.78rem;">&bull;</span>
                                    <a href="tel:${cleanPhone}" style="color: #0284c7; text-decoration: none; font-weight: 600; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 3px;">
                                        <i data-lucide="phone" style="width: 12px; height: 12px;"></i> ${cleanPhone}
                                    </a>
                                ` : ''}
                            </div>

                            <!-- Remarks / Notes Box -->
                            ${item.remarks ? `<div style="font-size: 0.78rem; color: #334155; background: #f8fafc; padding: 8px 10px; border-radius: 6px; margin-bottom: 12px; border-left: 3px solid #cbd5e1; line-height: 1.4;">"${escapeHtml(item.remarks)}"</div>` : ''}

                            <!-- Action Buttons Toolbar -->
                            <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end; flex-wrap: wrap; margin-top: 4px;">
                                ${cleanPhone ? `
                                    <a href="tel:${cleanPhone}" class="btn" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i data-lucide="phone-call" style="width: 12px; height: 12px; color: #16a34a;"></i> Call
                                    </a>
                                    <a href="https://wa.me/${cleanPhone.replace(/[^0-9]/g, '')}" target="_blank" class="btn" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i data-lucide="message-circle" style="width: 12px; height: 12px; color: #16a34a;"></i> WhatsApp
                                    </a>
                                ` : ''}
                                ${item.lead_id ? `<a href="index.php?page=lead_details&id=${encodeURIComponent(item.lead_id)}" class="btn" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; text-decoration: none; font-weight: 600;">View Lead</a>` : ''}
                                <button type="button" onclick="snoozeReminderToast('${card.id}', '${alertKey}')" class="btn" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #475569; padding: 5px 10px; font-size: 0.75rem; border-radius: 6px; cursor: pointer; font-weight: 600;">Snooze 5m</button>
                                ${item.reminder_kind === 'followup' ? `<button type="button" onclick="completeReminderFollowup(${item.id}, '${card.id}', '${alertKey}')" class="btn" style="background: #10b981; border: 1px solid #059669; color: #ffffff; padding: 5px 12px; font-size: 0.75rem; border-radius: 6px; cursor: pointer; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">✓ Done</button>` : ''}
                            </div>
                        `;

                        container.appendChild(card);
                        hasNewChime = true;
                        showDesktopNotification(badgeTitle, `${item.lead_name || 'Followup'} - ${item.action_type || 'Task'} scheduled at ${formattedTime}`);
                    });

                    if (hasNewChime) {
                        playReminderChime(true);
                    }
                    updateReminderHeader();
                    if (window.lucide) lucide.createIcons();
                } else {
                    updateReminderHeader();
                }
            } catch (e) {}
        }

        window.dismissReminderToast = function(cardId, alertKey) {
            if (alertKey) sessionStorage.setItem(alertKey, 'dismissed');
            const card = document.getElementById(cardId);
            if (card) {
                card.style.transition = 'all 0.25s ease';
                card.style.opacity = '0';
                card.style.transform = 'translateY(15px) scale(0.95)';
                setTimeout(() => {
                    card.remove();
                    updateReminderHeader();
                }, 250);
            }
        };

        window.dismissAllReminderToasts = function() {
            const container = document.getElementById('reminder-toast-container');
            if (!container) return;
            const cards = container.querySelectorAll('.reminder-toast-card');
            cards.forEach(card => {
                const alertKey = card.getAttribute('data-alert-key');
                if (alertKey) sessionStorage.setItem(alertKey, 'dismissed');
                card.style.transition = 'all 0.25s ease';
                card.style.opacity = '0';
                card.style.transform = 'translateY(15px) scale(0.95)';
            });
            setTimeout(() => {
                container.innerHTML = '';
                updateReminderHeader();
            }, 250);
        };

        window.snoozeReminderToast = function(cardId, alertKey) {
            if (alertKey) {
                // Snooze for 5 minutes (300,000 ms)
                sessionStorage.setItem(alertKey, String(Date.now() + 300000));
            }
            dismissReminderToast(cardId);
        };

        window.completeReminderFollowup = async function(followupId, cardId, alertKey) {
            try {
                const res = await fetch('api/followups.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ action: 'complete', id: followupId })
                });
                const data = await res.json();
                if (data.success) {
                    dismissReminderToast(cardId, alertKey);
                }
            } catch (e) {
                dismissReminderToast(cardId, alertKey);
            }
        };

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Run immediately and poll every 10 seconds
        document.addEventListener('DOMContentLoaded', () => {
            checkPendingReminders();
            setInterval(checkPendingReminders, 10000);
        });
    })();
    </script>
</body>
</html>
