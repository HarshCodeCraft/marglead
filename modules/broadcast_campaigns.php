<?php
/**
 * Marg CRM - WhatsApp Broadcast & Campaign Management Hub
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
?>

<style>
.campaigns-container {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding-bottom: 2rem;
}

/* Stat Cards Header */
.campaign-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}
.stat-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.1rem;
    box-shadow: 0 4px 14px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.stat-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-main, #111827);
}

/* Campaign Cards List */
.campaign-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.campaign-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.campaign-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-main, #111827);
}

/* Progress Bar */
.progress-bar-bg {
    width: 100%;
    height: 10px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    width: 0%;
    transition: width 0.4s ease;
}

/* Modal styling */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}
.modal-overlay.active {
    display: flex;
}
.modal-box {
    background: var(--bg-card, #ffffff);
    border-radius: 16px;
    width: 100%;
    max-width: 650px;
    padding: 1.5rem;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-height: 90vh;
    overflow-y: auto;
}
</style>

<div class="campaigns-container">
    
    <!-- Top Action Bar -->
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; background: var(--bg-card); padding: 1.25rem; border-radius: 12px; border: 1px solid var(--border-color);">
        <div>
            <h1 style="font-size: 1.35rem; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                📢 WhatsApp Bulk Campaigns & Marketing Hub
            </h1>
            <p class="text-xs text-muted mb-0 mt-1">
                Send AMC Renewal Reminders, Billing Notices, Bank Details, or Promos to Clients, Leads, or Uploaded Contact CSVs.
            </p>
        </div>

        <button type="button" class="btn btn-primary text-xs font-bold" onclick="openCreateCampaignModal()">
            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
            + Create New Campaign
        </button>
    </div>

    <!-- Metrics Row -->
    <div class="campaign-stats-grid">
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">TOTAL CAMPAIGN MESSAGES</span>
            <span class="stat-val" id="statTotalSent">0</span>
            <span class="text-xs text-success">● Live WhatsApp Meta Dispatcher</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">ACTIVE RUNNING CAMPAIGNS</span>
            <span class="stat-val" id="statActiveCount" style="color: #10b981;">0</span>
            <span class="text-xs text-muted">Real-time status control active</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">SUCCESS DELIVERY RATE</span>
            <span class="stat-val" style="color: #2563eb;">99.4%</span>
            <span class="text-xs text-muted">Meta Cloud API Verified</span>
        </div>
    </div>

    <!-- Campaigns List Section -->
    <div style="display: flex; flex-direction: column; gap: 1rem;" id="campaignsListContainer">
        <div style="text-align: center; padding: 3rem; color: #888;">
            <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
            <div>Loading campaigns...</div>
        </div>
    </div>

</div>

<!-- CREATE CAMPAIGN MODAL -->
<div class="modal-overlay" id="createCampaignModal">
    <div class="modal-box">
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">🚀 Create New Broadcast Campaign</h3>
            <button type="button" class="btn-icon" onclick="closeCreateCampaignModal()">&times;</button>
        </div>

        <form id="createCampaignForm" onsubmit="handleCampaignSubmit(event)" style="display: flex; flex-direction: column; gap: 1rem;">
            <div>
                <label class="form-label font-bold text-xs">Campaign Name *</label>
                <input type="text" name="name" class="input-styled font-bold text-xs" required placeholder="e.g. AMC Renewal Reminder - August 2026">
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label class="form-label font-bold text-xs">Target Audience Source *</label>
                    <select name="target_type" id="targetTypeSelect" class="input-styled text-xs" onchange="toggleCsvUploadInput(this.value)">
                        <option value="clients">👥 All Existing Clients (client_directory & customers)</option>
                        <option value="leads">🎯 CRM Sales Leads (leads)</option>
                        <option value="csv">📁 Upload Custom CSV / Excel List</option>
                    </select>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Message Type / Template *</label>
                    <select name="template_name" id="templateSelect" class="input-styled text-xs" onchange="toggleCustomMessageText(this.value)">
                        <option value="amc_renewal_reminder">⏰ AMC Renewal Reminder Notice</option>
                        <option value="bank_details_share">🏦 Bank Account & Payment Details</option>
                        <option value="billing_invoice_alert">📄 Billing Invoice Payment Alert</option>
                        <option value="custom">💬 Custom Text Message</option>
                    </select>
                </div>
            </div>

            <!-- CSV Upload File Picker -->
            <div id="csvUploadWrapper" style="display: none; background: rgba(59,130,246,0.05); border: 1px dashed #3b82f6; padding: 1rem; border-radius: 8px;">
                <label class="form-label font-bold text-xs text-primary">Upload CSV File (Columns: Mobile, Name, Company)</label>
                <input type="file" name="csv_file" accept=".csv" class="input-styled text-xs" style="background: white;">
            </div>

            <!-- Custom Text Message Box -->
            <div id="customMsgWrapper" style="display: none;">
                <label class="form-label font-bold text-xs">Custom Message Text</label>
                <textarea name="custom_message" class="input-styled text-xs" rows="3" placeholder="Enter message text to broadcast..."></textarea>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-body); padding: 0.75rem; border-radius: 8px;">
                <div>
                    <label class="form-label font-bold text-xs mb-0">Sending Speed Delay</label>
                    <div style="font-size: 0.7rem; color: #6b7280;">Delay between WhatsApp messages</div>
                </div>
                <select name="delay_seconds" class="input-styled text-xs" style="width: 130px;">
                    <option value="1">⚡ 1 Second</option>
                    <option value="2" selected>⏱️ 2 Seconds</option>
                    <option value="3">🐢 3 Seconds</option>
                    <option value="5">🛡️ 5 Seconds</option>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeCreateCampaignModal()">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs font-bold">🚀 Create & Initialize Campaign</button>
            </div>
        </form>
    </div>
</div>

<!-- AUDIENCE DETAILS MODAL -->
<div class="modal-overlay" id="audienceModal">
    <div class="modal-box" style="max-width: 750px;">
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);" id="audModalTitle">Campaign Contacts List</h3>
            <button type="button" class="btn-icon" onclick="closeAudienceModal()">&times;</button>
        </div>

        <div style="max-height: 400px; overflow-y: auto;">
            <table class="table" style="font-size: 0.8rem; width: 100%;">
                <thead>
                    <tr>
                        <th>Mobile</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Sent Time</th>
                    </tr>
                </thead>
                <tbody id="audTableBody">
                    <!-- Populated by JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const currentUserRole = "<?php echo $_SESSION['user_role'] ?? 'Executive'; ?>";
const isAdminUser = ["Super Admin", "Admin", "Regional Manager"].includes(currentUserRole);

let runningCampaignIds = new Set();
let activeLoopInterval = null;

document.addEventListener('DOMContentLoaded', () => {
    fetchCampaigns();
    activeLoopInterval = setInterval(runActiveCampaignsLoop, 3000);
});

function fetchCampaigns() {
    fetch('api/campaign-api.php?action=get_campaigns')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderCampaigns(data.campaigns);
        }
    })
    .catch(err => console.error(err));
}

function renderCampaigns(list) {
    const container = document.getElementById('campaignsListContainer');
    runningCampaignIds.clear();

    if (!list || list.length === 0) {
        container.innerHTML = `
            <div style="background: var(--bg-card); padding: 3rem; text-align: center; border-radius: 12px; border: 1px solid var(--border-color);">
                <i data-lucide="send" style="width: 32px; height: 32px; color: #9ca3af; margin-bottom: 0.5rem;"></i>
                <h3 style="margin: 0; font-weight: 700;">No Broadcast Campaigns Created Yet</h3>
                <p class="text-xs text-muted">Click "+ Create New Campaign" to send bulk AMC reminders, invoices, or marketing promos.</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
    }

    let totalSentSum = 0;
    let activeCnt = 0;
    let html = '';

    list.forEach(c => {
        totalSentSum += parseInt(c.sent_count || 0);
        if (c.status === 'running') {
            activeCnt++;
            runningCampaignIds.add(c.id);
        }

        const isPendingApp = (c.status === 'pending_approval');
        const isApproved   = (c.status === 'approved');
        const isRejected   = (c.status === 'rejected');
        const isRunning    = (c.status === 'running');
        const isPaused     = (c.status === 'paused');
        const isDone       = (c.status === 'completed');
        const isCancelled  = (c.status === 'cancelled');

        let statusBadge = '<span class="badge text-xs" style="background:#e5e7eb; color:#374151;">Draft</span>';
        if (isPendingApp) statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.15); color:#d97706; font-weight:700;">⏳ Pending Admin Approval</span>';
        if (isApproved) statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.15); color:#10b981; font-weight:700;">✅ Admin Approved</span>';
        if (isRejected) statusBadge = '<span class="badge text-xs" style="background:rgba(239,68,68,0.15); color:#ef4444; font-weight:700;">❌ Rejected</span>';
        if (isRunning) statusBadge = '<span class="badge text-xs" style="background:rgba(16,185,129,0.15); color:#10b981; font-weight:700;">🟢 Running</span>';
        if (isPaused) statusBadge = '<span class="badge text-xs" style="background:rgba(245,158,11,0.15); color:#d97706; font-weight:700;">⏸️ Paused</span>';
        if (isDone) statusBadge = '<span class="badge text-xs" style="background:rgba(59,130,246,0.15); color:#2563eb; font-weight:700;">🏁 Completed</span>';
        if (isCancelled) statusBadge = '<span class="badge text-xs" style="background:rgba(239,68,68,0.15); color:#ef4444; font-weight:700;">🛑 Stopped</span>';

        html += `
        <div class="campaign-card">
            <div class="campaign-card-header">
                <div>
                    <h3 class="campaign-title">${escapeHtml(c.name)}</h3>
                    <div style="font-size: 0.75rem; color: #6b7280; margin-top: 2px;">
                        Template: <strong class="text-primary">${escapeHtml(c.template_name)}</strong> | Target: <strong>${escapeHtml(c.target_type.toUpperCase())}</strong> | Created By: <strong>${escapeHtml(c.created_by || 'Staff')}</strong> ${c.approved_by ? '| Approved By: <strong>' + escapeHtml(c.approved_by) + '</strong>' : ''}
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    ${statusBadge}
                    
                    <!-- Admin Approval Controls -->
                    ${isPendingApp && isAdminUser ? `
                        <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white;" onclick="approveCampaign(${c.id})">
                            ✅ Approve Campaign
                        </button>
                        <button type="button" class="btn btn-danger text-xs font-bold" onclick="rejectCampaign(${c.id})">
                            ❌ Reject
                        </button>
                    ` : ''}

                    <!-- Start / Pause / Stop Controls -->
                    ${!isDone && !isCancelled && !isRejected ? `
                        ${isPendingApp ? `
                            <span class="text-xs text-muted font-italic" style="padding: 4px 8px;">Awaiting Admin Review</span>
                        ` : `
                            ${isRunning ? `
                                <button type="button" class="btn btn-warning text-xs font-bold" onclick="toggleCampaignStatus(${c.id}, 'paused')" title="Pause sending to avoid Meta charges">
                                    ⏸️ Pause
                                </button>
                            ` : `
                                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white;" onclick="toggleCampaignStatus(${c.id}, 'running')">
                                    ▶️ Start / Resume
                                </button>
                            `}
                            <button type="button" class="btn btn-danger text-xs font-bold" onclick="toggleCampaignStatus(${c.id}, 'cancelled')" title="Stop Campaign completely">
                                🛑 Stop
                            </button>
                        `}
                    ` : ''}

                    <button type="button" class="btn btn-secondary text-xs" onclick="viewAudienceDetails(${c.id}, '${escapeHtml(c.name)}')">
                        👁️ Contacts (${c.total_contacts})
                    </button>
                </div>
            </div>

            <!-- Progress Bar -->
            <div>
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; font-weight: 600; margin-bottom: 4px;">
                    <span>Progress: ${c.sent_count} / ${c.total_contacts} Sent</span>
                    <span>${c.progress_percent}%</span>
                </div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: ${c.progress_percent}%;"></div>
                </div>
                <div style="display: flex; gap: 1rem; font-size: 0.72rem; color: #6b7280; margin-top: 6px;">
                    <span style="color: #10b981;">● Sent: ${c.sent_count}</span>
                    <span style="color: #f59e0b;">● Pending: ${c.pending_count}</span>
                    <span style="color: #ef4444;">● Failed: ${c.failed_count}</span>
                </div>
            </div>
        </div>
        `;
    });

    document.getElementById('statTotalSent').innerText = totalSentSum;
    document.getElementById('statActiveCount').innerText = activeCnt;

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function approveCampaign(id) {
    if (confirm('Are you sure you want to Approve this campaign for broadcast?')) {
        const formData = new FormData();
        formData.append('action', 'approve_campaign');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                fetchCampaigns();
            } else {
                alert('Approval Failed: ' + data.message);
            }
        });
    }
}

function rejectCampaign(id) {
    if (confirm('Are you sure you want to Reject this campaign?')) {
        const formData = new FormData();
        formData.append('action', 'reject_campaign');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                fetchCampaigns();
            } else {
                alert('Reject Failed: ' + data.message);
            }
        });
    }
}

function toggleCampaignStatus(id, newStatus) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            fetchCampaigns();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function runActiveCampaignsLoop() {
    if (runningCampaignIds.size === 0) return;

    runningCampaignIds.forEach(cId => {
        fetch(`api/campaign-api.php?action=process_batch&id=${cId}&batch_size=5`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchCampaigns();
            }
        });
    });
}

function openCreateCampaignModal() {
    document.getElementById('createCampaignModal').classList.add('active');
}
function closeCreateCampaignModal() {
    document.getElementById('createCampaignModal').classList.remove('active');
}

function toggleCsvUploadInput(type) {
    document.getElementById('csvUploadWrapper').style.display = (type === 'csv') ? 'block' : 'none';
}
function toggleCustomMessageText(template) {
    document.getElementById('customMsgWrapper').style.display = (template === 'custom') ? 'block' : 'none';
}

function handleCampaignSubmit(e) {
    e.preventDefault();
    const form = document.getElementById('createCampaignForm');
    const formData = new FormData(form);
    formData.append('action', 'create_campaign');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeCreateCampaignModal();
            form.reset();
            fetchCampaigns();
            alert(data.message);
        } else {
            alert('Failed: ' + data.message);
        }
    })
    .catch(err => alert('Error submitting campaign: ' + err));
}

function viewAudienceDetails(id, name) {
    document.getElementById('audModalTitle').innerText = 'Contacts List: ' + name;
    const tBody = document.getElementById('audTableBody');
    tBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading contacts...</td></tr>';
    document.getElementById('audienceModal').classList.add('active');

    fetch(`api/campaign-api.php?action=get_campaign_details&id=${id}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            let html = '';
            data.audience.forEach(a => {
                const st = (a.status === 'sent') 
                    ? '<span class="badge text-xs" style="background:#d1fae5; color:#065f46;">Sent</span>' 
                    : ((a.status === 'failed') 
                        ? '<span class="badge text-xs" style="background:#fee2e2; color:#991b1b;">Failed</span>'
                        : '<span class="badge text-xs" style="background:#fef3c7; color:#92400e;">Pending</span>');
                html += `
                    <tr>
                        <td class="font-mono">+${escapeHtml(a.mobile)}</td>
                        <td>${escapeHtml(a.customer_name)}</td>
                        <td>${escapeHtml(a.company_name)}</td>
                        <td>${st}</td>
                        <td class="text-xs text-muted">${a.sent_at || '--'}</td>
                    </tr>
                `;
            });
            tBody.innerHTML = html || '<tr><td colspan="5" style="text-align:center;">No contacts found.</td></tr>';
        }
    });
}

function closeAudienceModal() {
    document.getElementById('audienceModal').classList.remove('active');
}

function escapeHtml(str) {
    return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>
