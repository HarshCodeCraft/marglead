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

    <!-- Real-Time Dual Floating Reminder Notifications (5-Min Warning & Exact-Time Alert) -->
    <div id="reminder-toast-container" style="position: fixed; bottom: 24px; right: 24px; z-index: 9999999; display: flex; flex-direction: column; gap: 12px; max-width: 380px; width: calc(100vw - 32px); pointer-events: none;"></div>

    <style>
    .reminder-toast-card {
        pointer-events: auto;
        background: rgba(15, 23, 42, 0.96);
        border-radius: 16px;
        padding: 16px;
        color: #ffffff;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(16px);
        animation: reminderSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: 'Inter', system-ui, sans-serif;
    }

    .reminder-toast-card {
        pointer-events: auto;
        background: rgba(15, 23, 42, 0.95);
        border-radius: 16px;
        padding: 16px 18px;
        color: #ffffff;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        animation: reminderSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: 'Inter', system-ui, sans-serif;
    }

    .reminder-toast-card.alert-5min {
        border-left: 4px solid #06b6d4;
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.18) 0%, rgba(15, 23, 42, 0.97) 100%);
        box-shadow: 0 15px 35px rgba(6, 182, 212, 0.2), 0 0 0 1px rgba(6, 182, 212, 0.3);
    }

    .reminder-toast-card.alert-duenow {
        border-left: 4px solid #3b82f6;
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.22) 0%, rgba(15, 23, 42, 0.97) 100%);
        box-shadow: 0 15px 35px rgba(59, 130, 246, 0.25), 0 0 0 1px rgba(59, 130, 246, 0.35);
    }

    @keyframes reminderSlideUp {
        from { transform: translateY(50px) scale(0.95); opacity: 0; }
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
            } catch (e) {
                // Audio context blocked or unsupported
            }
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

                        let badgeTitle = isDueNow ? '🔔 REMINDER DUE NOW!' : `⏳ 5 MIN WARNING (${item.mins_left}m left)`;
                        let badgeColor = isDueNow ? '#3b82f6' : '#06b6d4';

                        const formattedTime = new Date(item.scheduled_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                        card.innerHTML = `
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 800; color: ${badgeColor}; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <i data-lucide="${isDueNow ? 'bell-ring' : 'clock'}" style="width: 14px; height: 14px;"></i>
                                    <span>${badgeTitle}</span>
                                </div>
                                <button type="button" onclick="dismissReminderToast('${card.id}', '${alertKey}')" title="Dismiss" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 2px 6px;">&times;</button>
                            </div>
                            <div style="font-weight: 700; font-size: 0.95rem; color: #ffffff; margin-bottom: 2px;">
                                ${escapeHtml(item.lead_name || 'Scheduled Task')} ${item.company ? `<span style="color: #94a3b8; font-weight: 400; font-size: 0.85rem;">(${escapeHtml(item.company)})</span>` : ''}
                            </div>
                            <div style="font-size: 0.8rem; color: #cbd5e1; margin-bottom: 8px;">
                                🎯 <strong>${escapeHtml(item.action_type || 'Follow-up')}</strong> &bull; Scheduled at <strong>${formattedTime}</strong>
                            </div>
                            ${item.remarks ? `<div style="font-size: 0.775rem; color: #94a3b8; background: rgba(0,0,0,0.3); padding: 6px 10px; border-radius: 8px; margin-bottom: 10px; border-left: 2px solid ${badgeColor}; line-height: 1.3;">"${escapeHtml(item.remarks)}"</div>` : ''}
                            <div style="display: flex; align-items: center; gap: 8px; justify-content: flex-end; flex-wrap: wrap;">
                                ${item.lead_id ? `<a href="index.php?page=lead_details&id=${encodeURIComponent(item.lead_id)}" class="btn btn-xs btn-secondary" style="padding: 4px 10px; font-size: 0.75rem;">View Lead</a>` : ''}
                                <button type="button" onclick="snoozeReminderToast('${card.id}', '${alertKey}')" class="btn btn-xs btn-outline-secondary" style="padding: 4px 10px; font-size: 0.75rem;">Snooze 5m</button>
                                ${item.reminder_kind === 'followup' ? `<button type="button" onclick="completeReminderFollowup(${item.id}, '${card.id}', '${alertKey}')" class="btn btn-xs btn-cyan" style="padding: 4px 10px; font-size: 0.75rem;">Mark Done</button>` : ''}
                            </div>
                        `;

                        container.appendChild(card);
                        playReminderChime(isDueNow);
                        showDesktopNotification(badgeTitle, `${item.lead_name || 'Followup'} - ${item.action_type || 'Task'} scheduled at ${formattedTime}`);
                        if (window.lucide) lucide.createIcons();
                    });
                }
            } catch (e) {
                // Ignore background fetch errors
            }
        }

        window.dismissReminderToast = function(cardId, alertKey) {
            if (alertKey) sessionStorage.setItem(alertKey, 'dismissed');
            const card = document.getElementById(cardId);
            if (card) {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => card.remove(), 300);
            }
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
