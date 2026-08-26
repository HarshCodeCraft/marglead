<?php
/**
 * Marg CRM - WhatsApp Broadcast & Campaign Management Hub
 * AiSensy-Style Interactive Template Builder, Live WhatsApp Phone Simulator,
 * Per-Customer Dynamic Personalization ({name}, {amount}, {due_date}), and Interactive Button Dispatcher.
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

/* Header & Tabs */
.campaign-header-card {
    background: var(--bg-card, #ffffff);
    padding: 1.25rem 1.5rem;
    border-radius: 12px;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.tab-nav-bar {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    padding-bottom: 2px;
    margin-bottom: 1rem;
    overflow-x: auto;
}

.nav-tab-btn {
    padding: 0.6rem 1.1rem;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 8px 8px 0 0;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.nav-tab-btn:hover {
    background: rgba(37, 99, 235, 0.05);
    color: var(--primary, #2563eb);
}

.nav-tab-btn.active {
    background: var(--bg-card, #ffffff);
    color: var(--primary, #2563eb);
    border-color: var(--border-color, #e2e8f0);
    border-bottom-color: var(--bg-card, #ffffff);
    box-shadow: 0 -2px 6px rgba(0,0,0,0.03);
}

/* Stat Cards */
.campaign-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1.1rem;
    box-shadow: 0 4px 14px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-val {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
}

/* Template Cards Grid */
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.1rem;
}

.template-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 14px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.85rem;
    position: relative;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.template-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}

.template-header-tag {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    display: inline-block;
    margin-bottom: 4px;
}

.template-body-preview {
    font-size: 0.82rem;
    line-height: 1.45;
    color: var(--text-main, #1e293b);
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    padding: 0.75rem;
    border-radius: 8px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 140px;
    overflow-y: auto;
}

.template-buttons-preview {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
}

.template-btn-pill {
    background: #ffffff;
    border: 1px solid #3b82f6;
    color: #2563eb;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 8px;
    text-align: center;
}

/* Modals & Live WhatsApp Phone Mockup */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(5px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}

.modal-overlay.active {
    display: flex;
}

.modal-box-lg {
    background: var(--bg-card, #ffffff);
    border-radius: 16px;
    width: 100%;
    max-width: 960px;
    padding: 1.5rem;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.25rem;
    max-height: 90vh;
    overflow-y: auto;
}

@media (max-width: 820px) {
    .modal-box-lg {
        grid-template-columns: 1fr;
    }
}

/* WhatsApp Phone Simulator Mockup */
.wa-phone-mockup {
    background: #0b141a;
    border: 10px solid #1f2937;
    border-radius: 36px;
    box-shadow: 0 16px 32px rgba(0,0,0,0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 480px;
    position: relative;
}

.wa-phone-header {
    background: #1f2c34;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #e9edef;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.wa-phone-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #10b981;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    color: white;
    font-size: 0.8rem;
}

.wa-phone-body {
    background: #0b141a url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-835d-d93a777afe46.png');
    background-size: cover;
    flex: 1;
    padding: 12px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}

.wa-msg-bubble {
    background: #005c4b;
    color: #e9edef;
    border-radius: 10px 10px 0 10px;
    padding: 10px 12px;
    font-size: 0.82rem;
    line-height: 1.45;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    position: relative;
    max-width: 90%;
    align-self: flex-end;
}

.wa-msg-header {
    font-weight: 700;
    color: #34d399;
    margin-bottom: 4px;
    font-size: 0.78rem;
}

.wa-msg-footer {
    font-size: 0.7rem;
    color: #8696a0;
    margin-top: 6px;
}

.wa-msg-time {
    font-size: 0.65rem;
    color: #8696a0;
    text-align: right;
    margin-top: 4px;
}

.wa-btn-container {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
    border-top: 1px solid rgba(255,255,255,0.1);
    padding-top: 6px;
}

.wa-interactive-btn {
    background: rgba(255,255,255,0.08);
    border: none;
    color: #29b6f6;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 6px;
    border-radius: 6px;
    text-align: center;
    cursor: pointer;
}

.var-pill-btn {
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    color: #1e293b;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
}

.var-pill-btn:hover {
    background: #3b82f6;
    color: white;
    border-color: #2563eb;
}
</style>

<div class="campaigns-container">

    <!-- Top Header -->
    <div class="campaign-header-card">
        <div>
            <h1 style="font-size: 1.35rem; font-weight: 800; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                📢 WhatsApp Broadcast Campaigns & AiSensy-Style Template Hub
            </h1>
            <p class="text-xs text-muted mb-0 mt-1">
                Compose rich WhatsApp templates with live phone simulator, interactive buttons, dynamic customer variables ({name}, {amount}, {due_date}), and click auto-replies.
            </p>
        </div>

        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary text-xs font-bold" onclick="switchMainTab('individual')">
                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                ⚡ Individual Quick Send
            </button>
            <button type="button" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white;" onclick="switchMainTab('bulk')">
                <i data-lucide="rocket" style="width: 14px; height: 14px;"></i>
                🚀 Launch Bulk Campaign
            </button>
            <button type="button" class="btn btn-secondary text-xs font-bold" onclick="openCreateTemplateModal()">
                <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                + Interactive Template Builder
            </button>
        </div>
    </div>

    <!-- Metrics Row -->
    <div class="campaign-stats-grid">
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">TOTAL BROADCAST MESSAGES</span>
            <span class="stat-val" id="statTotalSent">0</span>
            <span class="text-xs text-success">● Live WhatsApp Meta Dispatcher</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">ACTIVE RUNNING CAMPAIGNS</span>
            <span class="stat-val" id="statActiveCount" style="color: #10b981;">0</span>
            <span class="text-xs text-muted">Real-time batch loop active</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">SAVED TEMPLATES</span>
            <span class="stat-val" id="statTemplateCount" style="color: #2563eb;">0</span>
            <span class="text-xs text-muted">Interactive Button Templates</span>
        </div>
        <div class="stat-card">
            <span class="text-xs text-muted font-semibold">META API HEALTH</span>
            <span class="stat-val" style="color: #059669;">99.4%</span>
            <span class="text-xs text-muted">Verified Cloud Endpoint</span>
        </div>
    </div>

    <!-- Navigation Tabs Bar -->
    <div class="tab-nav-bar">
        <button type="button" class="nav-tab-btn active" id="tabHead-templates" onclick="switchMainTab('templates')">
            <i data-lucide="file-text" style="width: 15px; height: 15px;"></i>
            📑 Interactive Template Gallery
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-campaigns" onclick="switchMainTab('campaigns')">
            <i data-lucide="layers" style="width: 15px; height: 15px;"></i>
            📢 Active & Past Campaigns
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-individual" onclick="switchMainTab('individual')">
            <i data-lucide="user" style="width: 15px; height: 15px;"></i>
            ⚡ Individual Quick Broadcast
        </button>
        <button type="button" class="nav-tab-btn" id="tabHead-bulk" onclick="switchMainTab('bulk')">
            <i data-lucide="users" style="width: 15px; height: 15px;"></i>
            🚀 Launch Bulk Campaign
        </button>
    </div>

    <!-- TAB 1: TEMPLATE GALLERY -->
    <div id="tabContent-templates" class="tab-content-panel">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);">📑 Interactive WhatsApp Template Gallery</h3>
                <p class="text-xs text-muted mb-0">High-converting WhatsApp templates with dynamic personalization variables and interactive buttons.</p>
            </div>
            <div class="flex align-center gap-2">
                <button type="button" id="btnSyncMeta" class="btn btn-secondary text-xs font-bold flex align-center gap-1" onclick="syncMetaTemplates()" title="Fetch official approved templates directly from Meta WhatsApp Manager">
                    <i data-lucide="refresh-cw" style="width: 13px; height: 13px;"></i>
                    <span>🔄 Sync Meta Approved Templates</span>
                </button>
                <button type="button" class="btn btn-primary text-xs font-bold" onclick="openCreateTemplateModal()">
                    ✨ + Create Custom Template
                </button>
            </div>
        </div>

        <div class="templates-grid" id="templatesGridContainer">
            <div style="text-align: center; padding: 3rem; color: #888; grid-column: 1 / -1;">
                <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
                <div>Loading saved templates...</div>
            </div>
        </div>
    </div>

    <!-- TAB 2: CAMPAIGNS LIST -->
    <div id="tabContent-campaigns" class="tab-content-panel" style="display: none;">
        <div style="display: flex; flex-direction: column; gap: 1rem;" id="campaignsListContainer">
            <div style="text-align: center; padding: 3rem; color: #888;">
                <i data-lucide="loader" class="spin" style="width: 24px; height: 24px;"></i>
                <div>Loading campaigns...</div>
            </div>
        </div>
    </div>

    <!-- TAB 3: INDIVIDUAL QUICK BROADCAST -->
    <div id="tabContent-individual" class="tab-content-panel" style="display: none;">
        <div style="background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); max-width: 760px; margin: 0 auto; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
                    ⚡ Direct Individual WhatsApp Broadcast
                </h3>
                <p class="text-xs text-muted mb-0 mt-1">Send a 1-on-1 instant WhatsApp broadcast to any customer with dynamic variable substitution ({name}, {amount}, {due_date}).</p>
            </div>

            <form id="individualSendForm" onsubmit="handleIndividualSubmit(event)" style="display: flex; flex-direction: column; gap: 1rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Recipient Phone Number *</label>
                        <input type="text" id="indPhoneInput" name="phone" class="input-styled font-bold text-xs" required placeholder="e.g. 9532620736 or +919532620736">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Recipient Customer Name *</label>
                        <input type="text" id="indNameInput" name="name" class="input-styled text-xs" required placeholder="e.g. Rajesh Medical Store" oninput="updateIndividualPreview()">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Firm / Company Name</label>
                        <input type="text" id="indCompInput" name="company" class="input-styled text-xs" placeholder="e.g. Marg Pharma Pvt Ltd" oninput="updateIndividualPreview()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Pending Amount ({amount})</label>
                        <input type="text" id="indAmountInput" name="amount" class="input-styled font-bold text-xs" value="₹3,500" placeholder="e.g. ₹3,500" oninput="updateIndividualPreview()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Due Date ({due_date})</label>
                        <input type="text" id="indDueDateInput" name="due_date" class="input-styled text-xs" value="25 Aug 2026" placeholder="e.g. 25 Aug 2026" oninput="updateIndividualPreview()">
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Select Saved Template OR Type Custom</label>
                    <select id="indTemplateSelect" name="template_slug" class="input-styled text-xs" onchange="applyTemplateToIndividual(this.value)">
                        <option value="custom">✏️ Custom Message (Type below)</option>
                        <!-- Populated by JS -->
                    </select>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Message Text *</label>
                    <textarea id="indMessageText" name="message" class="input-styled text-xs" rows="5" required placeholder="Type broadcast message text... Use variables: {name}, {company}, {amount}, {due_date}"></textarea>
                </div>

                <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.85rem; border-radius: 8px;">
                    <div style="font-size: 0.72rem; font-weight: 700; color: #059669; text-transform: uppercase; margin-bottom: 4px;">Live Preview</div>
                    <div id="indLivePreview" style="font-size: 0.8rem; line-height: 1.4; color: #111827; white-space: pre-wrap; font-family: inherit;">Preview message will appear here...</div>
                    <div id="indLiveButtonsPreview" style="display: flex; gap: 0.5rem; margin-top: 8px;"></div>
                </div>

                <button type="submit" class="btn btn-primary text-xs font-bold" style="padding: 0.75rem 1.25rem;">
                    🚀 Send Direct Instant Broadcast Now
                </button>
            </form>
        </div>
    </div>

    <!-- TAB 4: BULK CAMPAIGN CREATOR -->
    <div id="tabContent-bulk" class="tab-content-panel" style="display: none;">
        <div style="background: var(--bg-card); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); max-width: 760px; margin: 0 auto; box-shadow: 0 4px 16px rgba(0,0,0,0.03);">
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 0.4rem;">
                    🚀 Launch New Bulk Campaign
                </h3>
                <p class="text-xs text-muted mb-0 mt-1">Broadcast mass AMC reminders, billing alerts, or promos to client segments or custom uploaded CSV lists.</p>
            </div>

            <form id="bulkCampaignForm" onsubmit="handleBulkCampaignSubmit(event)" style="display: flex; flex-direction: column; gap: 1rem;">
                <div>
                    <label class="form-label font-bold text-xs">Campaign Title / Name *</label>
                    <input type="text" name="name" class="input-styled font-bold text-xs" required placeholder="e.g. AMC Renewal Reminder - August 2026 Batch">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Target Audience Source *</label>
                        <select name="target_type" id="bulkTargetSelect" class="input-styled text-xs" onchange="toggleCsvUploadInput(this.value)">
                            <option value="clients">👥 All Existing Clients (client_directory & customers)</option>
                            <option value="leads">🎯 CRM Sales Leads (leads)</option>
                            <option value="csv">📁 Upload Custom CSV / Excel List</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">Select Template *</label>
                        <select name="template_name" id="bulkTemplateSelect" class="input-styled text-xs" onchange="toggleCustomMessageText(this.value)">
                            <option value="amc_renewal_reminder">⏰ AMC Renewal Reminder Notice</option>
                            <option value="bank_details_share">🏦 Bank Account & Payment Details</option>
                            <option value="billing_invoice_alert">📄 Billing Invoice Payment Alert</option>
                            <option value="welcome_promo_offer">🚀 Special Upgrade Promo Offer</option>
                            <option value="custom">✏️ Custom Text Message</option>
                        </select>
                    </div>
                </div>

                <!-- CSV File Picker -->
                <div id="csvUploadWrapper" style="display: none; background: rgba(59,130,246,0.05); border: 1px dashed #3b82f6; padding: 1rem; border-radius: 8px;">
                    <label class="form-label font-bold text-xs text-primary">Upload CSV File (Columns: Mobile, Name, Company, Amount, DueDate)</label>
                    <input type="file" name="csv_file" accept=".csv" class="input-styled text-xs" style="background: white;">
                </div>

                <!-- Custom Text Message Box -->
                <div id="customMsgWrapper" style="display: none;">
                    <label class="form-label font-bold text-xs">Custom Broadcast Message</label>
                    <textarea name="custom_message" class="input-styled text-xs" rows="4" placeholder="Enter custom message text..."></textarea>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; background: var(--bg-app); padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div>
                        <label class="form-label font-bold text-xs mb-0">Sending Delay Speed</label>
                        <div style="font-size: 0.7rem; color: #6b7280;">Pause interval between WhatsApp dispatches</div>
                    </div>
                    <select name="delay_seconds" class="input-styled text-xs" style="width: 140px;">
                        <option value="1">⚡ 1 Second</option>
                        <option value="2" selected>⏱️ 2 Seconds (Recommended)</option>
                        <option value="3">🐢 3 Seconds</option>
                        <option value="5">🛡️ 5 Seconds</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-success text-xs font-bold" style="background: #10b981; color: white; padding: 0.75rem 1.25rem;">
                    🚀 Create & Initialize Campaign
                </button>
            </form>
        </div>
    </div>

</div>

<!-- AISENSY-STYLE INTERACTIVE TEMPLATE BUILDER & LIVE PHONE SIMULATOR MODAL -->
<div class="modal-overlay" id="createTemplateModal">
    <div class="modal-box-lg">
        
        <!-- Left Side: Template Composer Form -->
        <div style="display: flex; flex-direction: column; gap: 0.85rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 0.6rem;">
                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-main);">✨ AiSensy-Style Template Builder</h3>
                <button type="button" class="btn-icon" onclick="closeCreateTemplateModal()">&times;</button>
            </div>

            <form id="createTemplateForm" onsubmit="handleTemplateSaveSubmit(event)" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label class="form-label font-bold text-xs">Template Title *</label>
                        <input type="text" name="title" id="builderTitle" class="input-styled font-bold text-xs" required placeholder="e.g. AMC Renewal Notice" oninput="updateLivePhoneMockup()">
                    </div>
                    <div>
                        <label class="form-label font-bold text-xs">Category</label>
                        <select name="category" id="builderCategory" class="input-styled text-xs" onchange="updateLivePhoneMockup()">
                            <option value="AMC">AMC Renewal</option>
                            <option value="Billing">Billing & Payment</option>
                            <option value="Marketing">Marketing & Promo</option>
                            <option value="Support">Support & Feedback</option>
                            <option value="General" selected>General</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Header Title Text (Optional)</label>
                    <input type="text" name="header_text" id="builderHeaderText" class="input-styled text-xs" placeholder="e.g. Marg ERP Official Notice" oninput="updateLivePhoneMockup()">
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Body Text *</label>
                    <textarea name="body_text" id="builderBodyText" class="input-styled text-xs" rows="4" required placeholder="Type template text... Insert dynamic variables below." oninput="updateLivePhoneMockup()"></textarea>
                    
                    <!-- 1-Click Variable Pills -->
                    <div style="font-size: 0.7rem; color: #6b7280; margin-top: 4px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                        <span class="font-bold">Insert Variable:</span>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{name}')">+ {name}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{company}')">+ {company}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{phone}')">+ {phone}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{amount}')">+ {amount}</button>
                        <button type="button" class="var-pill-btn" onclick="insertVarToBody('{due_date}')">+ {due_date}</button>
                    </div>
                </div>

                <div>
                    <label class="form-label font-bold text-xs">Footer Text (Optional)</label>
                    <input type="text" name="footer_text" id="builderFooterText" class="input-styled text-xs" placeholder="e.g. Marg Soft Solution Support Desk" oninput="updateLivePhoneMockup()">
                </div>

                <!-- Interactive Reply Buttons Builder -->
                <div style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.2); padding: 0.75rem; border-radius: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span class="font-bold text-xs text-primary">Interactive Reply Buttons (Max 3)</span>
                        <button type="button" class="btn btn-secondary text-xs" style="padding: 2px 6px;" onclick="addInteractiveButtonInput()">+ Add Button</button>
                    </div>
                    <div id="builderButtonsList" style="display: flex; flex-direction: column; gap: 4px;">
                        <div class="btn-builder-row" style="display: flex; gap: 4px;">
                            <input type="text" class="input-styled text-xs builder-btn-input" value="💳 Pay AMC Online" placeholder="Button Title (e.g. Pay Now)" oninput="updateLivePhoneMockup()">
                            <button type="button" class="btn-icon" onclick="removeButtonRow(this)">&times;</button>
                        </div>
                        <div class="btn-builder-row" style="display: flex; gap: 4px;">
                            <input type="text" class="input-styled text-xs builder-btn-input" value="📞 Request Callback" placeholder="Button Title (e.g. Call Support)" oninput="updateLivePhoneMockup()">
                            <button type="button" class="btn-icon" onclick="removeButtonRow(this)">&times;</button>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.25rem;">
                    <button type="button" class="btn btn-secondary text-xs" onclick="closeCreateTemplateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary text-xs font-bold">✨ Save Template to Library</button>
                </div>
            </form>
        </div>

        <!-- Right Side: Real-time Live WhatsApp Phone Simulator -->
        <div>
            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; margin-bottom: 6px; text-align: center;">📱 REAL-TIME WHATSAPP PHONE MOCKUP</div>
            
            <div class="wa-phone-mockup">
                <div class="wa-phone-header">
                    <div class="wa-phone-avatar">M</div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700;">Marg Soft Solution</div>
                        <div style="font-size: 0.65rem; color: #34d399;">Official Business Account</div>
                    </div>
                </div>

                <div class="wa-phone-body">
                    <div class="wa-msg-bubble">
                        <div class="wa-msg-header" id="mockupHeader">Marg ERP AMC Notice</div>
                        <div id="mockupBody">⏰ *Marg ERP - AMC Renewal Reminder*<br><br>Dear Rajesh Medical Store,<br>Your Marg ERP Software AMC renewal of *₹3,500* is due on *25 Aug 2026*.<br><br>To ensure uninterrupted billing & GST filings, kindly renew your AMC.<br><br>Call: *7523830026*</div>
                        <div class="wa-msg-footer" id="mockupFooter">Marg Soft Solution Support Desk</div>
                        <div class="wa-msg-time"><?php echo date('h:i A'); ?> ✓✓</div>
                        
                        <div class="wa-btn-container" id="mockupButtons">
                            <button type="button" class="wa-interactive-btn">💳 Pay AMC Online</button>
                            <button type="button" class="wa-interactive-btn">📞 Request Callback</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
let savedTemplatesList = [];

document.addEventListener('DOMContentLoaded', () => {
    fetchCampaigns();
    fetchTemplates();
    activeLoopInterval = setInterval(runActiveCampaignsLoop, 3000);

    const indMsg = document.getElementById('indMessageText');
    if (indMsg) {
        indMsg.addEventListener('input', updateIndividualPreview);
    }
});

function switchMainTab(tabName) {
    ['templates', 'campaigns', 'individual', 'bulk'].forEach(t => {
        const btn = document.getElementById('tabHead-' + t);
        const content = document.getElementById('tabContent-' + t);
        if (btn) btn.classList.remove('active');
        if (content) content.style.display = 'none';
    });

    const activeBtn = document.getElementById('tabHead-' + tabName);
    const activeContent = document.getElementById('tabContent-' + tabName);
    if (activeBtn) activeBtn.classList.add('active');
    if (activeContent) activeContent.style.display = 'block';

    if (tabName === 'templates') fetchTemplates();
    if (tabName === 'campaigns') fetchCampaigns();
}

function fetchTemplates() {
    fetch('api/campaign-api.php?action=get_templates')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            savedTemplatesList = data.templates || [];
            renderTemplates(savedTemplatesList);
            populateTemplateDropdowns(savedTemplatesList);
        }
    });
}

function renderTemplates(list) {
    const container = document.getElementById('templatesGridContainer');
    const statElem = document.getElementById('statTemplateCount');
    if (statElem) statElem.innerText = list.length;

    if (!list || list.length === 0) {
        container.innerHTML = `
            <div style="background: var(--bg-card); padding: 3rem; text-align: center; border-radius: 12px; border: 1px solid var(--border-color); grid-column: 1 / -1;">
                <i data-lucide="file-text" style="width: 32px; height: 32px; color: #9ca3af; margin-bottom: 0.5rem;"></i>
                <h3 style="margin: 0; font-weight: 700;">No Saved Templates Yet</h3>
                <p class="text-xs text-muted">Click "+ Interactive Template Builder" to create custom WhatsApp templates.</p>
            </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
    }

    let html = '';
    list.forEach(t => {
        let btns = [];
        if (t.buttons_json) {
            try { btns = JSON.parse(t.buttons_json); } catch(e) {}
        }

        let btnsHtml = '';
        if (btns && btns.length > 0) {
            btnsHtml += `<div class="template-buttons-preview">`;
            btns.forEach(b => {
                btnsHtml += `<div class="template-btn-pill">${escapeHtml(b.title)}</div>`;
            });
            btnsHtml += `</div>`;
        }

        html += `
        <div class="template-card">
            <div>
                <span class="template-header-tag">${escapeHtml(t.category || 'General')}</span>
                <h4 style="margin: 2px 0 6px 0; font-size: 0.95rem; font-weight: 700; color: var(--text-main);">${escapeHtml(t.title)}</h4>
                ${t.header_text ? `<div style="font-size: 0.7rem; font-weight: 700; color: #64748b; margin-bottom: 4px;">📌 ${escapeHtml(t.header_text)}</div>` : ''}
                <div class="template-body-preview">${escapeHtml(t.body_text)}</div>
                ${t.footer_text ? `<div style="font-size: 0.68rem; color: #94a3b8; margin-top: 4px;">— ${escapeHtml(t.footer_text)}</div>` : ''}
                ${btnsHtml}
            </div>
            <div style="display: flex; gap: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.6rem; margin-top: 0.4rem; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary text-xs" style="padding: 3px 8px;" onclick="useTemplateInIndividual('${escapeHtml(t.slug)}')">
                    ⚡ Use Single
                </button>
                <button type="button" class="btn btn-success text-xs" style="background:#10b981; color:white; padding: 3px 8px;" onclick="useTemplateInBulk('${escapeHtml(t.slug)}')">
                    🚀 Use Bulk
                </button>
                <button type="button" class="btn btn-secondary text-xs" style="padding: 3px 6px; color:#ef4444;" onclick="deleteTemplate(${t.id})" title="Delete Template">
                    🗑️
                </button>
            </div>
        </div>
        `;
    });

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function populateTemplateDropdowns(list) {
    const indSelect = document.getElementById('indTemplateSelect');
    const bulkSelect = document.getElementById('bulkTemplateSelect');

    let indHtml = `<option value="custom">✏️ Custom Message (Type below)</option>`;
    list.forEach(t => {
        indHtml += `<option value="${escapeHtml(t.slug)}">📑 ${escapeHtml(t.title)}</option>`;
    });

    if (indSelect) indSelect.innerHTML = indHtml;
}

function applyTemplateToIndividual(slug) {
    const txtArea = document.getElementById('indMessageText');
    if (!txtArea) return;

    if (slug === 'custom') {
        txtArea.value = '';
        updateIndividualPreview();
        return;
    }

    const t = savedTemplatesList.find(x => x.slug === slug);
    if (t) {
        txtArea.value = t.body_text;
        updateIndividualPreview();
    }
}

function useTemplateInIndividual(slug) {
    switchMainTab('individual');
    const indSelect = document.getElementById('indTemplateSelect');
    if (indSelect) {
        indSelect.value = slug;
        applyTemplateToIndividual(slug);
    }
}

function useTemplateInBulk(slug) {
    switchMainTab('bulk');
}

function updateIndividualPreview() {
    const txt = document.getElementById('indMessageText').value || '';
    const name = document.getElementById('indNameInput').value || 'Rajesh Medical Store';
    const comp = document.getElementById('indCompInput').value || 'Marg Pharma';
    const amount = document.getElementById('indAmountInput').value || '₹3,500';
    const dueDate = document.getElementById('indDueDateInput').value || '25 Aug 2026';
    const previewBox = document.getElementById('indLivePreview');

    if (previewBox) {
        let clean = txt.replace(/{name}/g, name)
                       .replace(/{company}/g, comp)
                       .replace(/{phone}/g, '9532620736')
                       .replace(/{amount}/g, amount)
                       .replace(/{due_date}/g, dueDate);
        previewBox.innerText = clean || 'Preview message will appear here...';
    }
}

function handleIndividualSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'send_individual');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            form.reset();
            updateIndividualPreview();
            fetchCampaigns();
            switchMainTab('campaigns');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Failed sending individual broadcast.'));
}

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
                <p class="text-xs text-muted">Click "+ Launch Bulk Campaign" or "⚡ Individual Quick Send" to broadcast messages.</p>
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
                    
                    ${isPendingApp && isAdminUser ? `
                        <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white;" onclick="approveCampaign(${c.id})">
                            ✅ Approve Campaign
                        </button>
                        <button type="button" class="btn btn-danger text-xs font-bold" onclick="rejectCampaign(${c.id})">
                            ❌ Reject
                        </button>
                    ` : ''}

                    ${!isDone && !isCancelled && !isRejected ? `
                        ${isPendingApp ? `
                            <span class="text-xs text-muted font-italic" style="padding: 4px 8px;">Awaiting Admin Review</span>
                        ` : `
                            ${isRunning ? `
                                <button type="button" class="btn btn-warning text-xs font-bold" onclick="toggleCampaignStatus(${c.id}, 'paused')" title="Pause sending">
                                    ⏸️ Pause
                                </button>
                            ` : `
                                <button type="button" class="btn btn-success text-xs font-bold" style="background:#10b981; color:white;" onclick="toggleCampaignStatus(${c.id}, 'running')">
                                    ▶️ Start / Resume
                                </button>
                            `}
                            <button type="button" class="btn btn-danger text-xs font-bold" onclick="toggleCampaignStatus(${c.id}, 'cancelled')" title="Stop Campaign">
                                🛑 Stop
                            </button>
                        `}
                    ` : ''}

                    <button type="button" class="btn btn-secondary text-xs" onclick="viewAudienceDetails(${c.id}, '${escapeHtml(c.name)}')">
                        👁️ Contacts (${c.total_contacts})
                    </button>
                </div>
            </div>

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

    const statSent = document.getElementById('statTotalSent');
    const statActive = document.getElementById('statActiveCount');
    if (statSent) statSent.innerText = totalSentSum;
    if (statActive) statActive.innerText = activeCnt;

    container.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function handleBulkCampaignSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'create_campaign');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            form.reset();
            fetchCampaigns();
            switchMainTab('campaigns');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Failed creating bulk campaign.'));
}

function toggleCsvUploadInput(val) {
    const csvWrap = document.getElementById('csvUploadWrapper');
    if (csvWrap) {
        csvWrap.style.display = (val === 'csv') ? 'block' : 'none';
    }
}

function toggleCustomMessageText(val) {
    const customWrap = document.getElementById('customMsgWrapper');
    if (customWrap) {
        customWrap.style.display = (val === 'custom') ? 'block' : 'none';
    }
}

function openCreateTemplateModal() {
    document.getElementById('createTemplateModal').classList.add('active');
    updateLivePhoneMockup();
}

function closeCreateTemplateModal() {
    document.getElementById('createTemplateModal').classList.remove('active');
}

function insertVarToBody(v) {
    const bodyArea = document.getElementById('builderBodyText');
    if (bodyArea) {
        bodyArea.value += ' ' + v;
        bodyArea.focus();
        updateLivePhoneMockup();
    }
}

function addInteractiveButtonInput() {
    const list = document.getElementById('builderButtonsList');
    if (list.children.length >= 3) {
        alert('Max 3 interactive buttons allowed per template');
        return;
    }

    const div = document.createElement('div');
    div.className = 'btn-builder-row';
    div.style.display = 'flex';
    div.style.gap = '4px';
    div.innerHTML = `
        <input type="text" class="input-styled text-xs builder-btn-input" value="📩 Contact Us" placeholder="Button Title" oninput="updateLivePhoneMockup()">
        <button type="button" class="btn-icon" onclick="removeButtonRow(this)">&times;</button>
    `;
    list.appendChild(div);
    updateLivePhoneMockup();
}

function removeButtonRow(btn) {
    btn.parentElement.remove();
    updateLivePhoneMockup();
}

function updateLivePhoneMockup() {
    const headerVal = document.getElementById('builderHeaderText')?.value || 'Marg ERP AMC Notice';
    const bodyVal   = document.getElementById('builderBodyText')?.value || '⏰ *Marg ERP - AMC Renewal Reminder*\n\nDear {name},\nYour Marg ERP Software AMC renewal of *{amount}* is due on *{due_date}*.\n\nTo ensure uninterrupted billing & GST filings, kindly renew your AMC.\n\nCall: *7523830026*';
    const footerVal = document.getElementById('builderFooterText')?.value || 'Marg Soft Solution Support Desk';

    // Replace variables for phone mockup
    let formattedBody = bodyVal
        .replace(/{name}/g, 'Rajesh Medical Store')
        .replace(/{company}/g, 'Marg Pharma')
        .replace(/{phone}/g, '9532620736')
        .replace(/{amount}/g, '₹3,500')
        .replace(/{due_date}/g, '25 Aug 2026')
        .replace(/\n/g, '<br>');

    document.getElementById('mockupHeader').innerHTML = escapeHtml(headerVal);
    document.getElementById('mockupBody').innerHTML = formattedBody;
    document.getElementById('mockupFooter').innerHTML = escapeHtml(footerVal);

    // Render interactive buttons inside phone mockup
    const btnInputs = document.querySelectorAll('.builder-btn-input');
    const mockBtnsContainer = document.getElementById('mockupButtons');
    let btnsHtml = '';

    btnInputs.forEach(inp => {
        const val = inp.value.trim();
        if (val) {
            btnsHtml += `<button type="button" class="wa-interactive-btn">${escapeHtml(val)}</button>`;
        }
    });

    mockBtnsContainer.innerHTML = btnsHtml;
}

function handleTemplateSaveSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    // Collect interactive buttons
    const btnInputs = document.querySelectorAll('.builder-btn-input');
    const buttonsArr = [];
    btnInputs.forEach((inp, idx) => {
        const val = inp.value.trim();
        if (val) {
            const btnId = 'btn_' + pregSlug(val) + '_' + idx;
            buttonsArr.push({ id: btnId, title: val });
        }
    });

    formData.append('buttons_json', JSON.stringify(buttonsArr));
    formData.append('action', 'save_template');

    fetch('api/campaign-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            form.reset();
            closeCreateTemplateModal();
            fetchTemplates();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function pregSlug(str) {
    return str.toLowerCase().replace(/[^a-z0-9]/g, '_').substring(0, 20);
}

function deleteTemplate(id) {
    if (confirm('Are you sure you want to delete this template from library?')) {
        const formData = new FormData();
        formData.append('action', 'delete_template');
        formData.append('id', id);

        fetch('api/campaign-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchTemplates();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
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
                fetchCampaigns();
            } else {
                alert('Error: ' + data.message);
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
                fetchCampaigns();
            } else {
                alert('Error: ' + data.message);
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

function viewAudienceDetails(campaignId, name) {
    const modal = document.getElementById('audienceModal');
    const title = document.getElementById('audModalTitle');
    const body = document.getElementById('audTableBody');

    title.innerText = `Contacts List: ${name}`;
    body.innerHTML = `<tr><td colspan="5" style="text-align:center;">Loading contacts...</td></tr>`;
    modal.classList.add('active');

    fetch(`api/campaign-api.php?action=get_campaign_details&id=${campaignId}`)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.audience) {
            let html = '';
            data.audience.forEach(a => {
                let stBadge = `<span class="badge text-xs" style="background:#e5e7eb;">Pending</span>`;
                if (a.status === 'sent') stBadge = `<span class="badge text-xs" style="background:rgba(16,185,129,0.15); color:#10b981;">Sent</span>`;
                if (a.status === 'failed') stBadge = `<span class="badge text-xs" style="background:rgba(239,68,68,0.15); color:#ef4444;" title="${escapeHtml(a.error_message || '')}">Failed</span>`;

                html += `
                <tr>
                    <td><strong>+${a.mobile}</strong></td>
                    <td>${escapeHtml(a.customer_name || 'N/A')}</td>
                    <td>${escapeHtml(a.company_name || 'N/A')}</td>
                    <td>${stBadge}</td>
                    <td>${a.sent_at || '-'}</td>
                </tr>
                `;
            });
            body.innerHTML = html || `<tr><td colspan="5" style="text-align:center;">No contacts found.</td></tr>`;
        }
    });
}

function closeAudienceModal() {
    document.getElementById('audienceModal').classList.remove('active');
}

function runActiveCampaignsLoop() {
    if (runningCampaignIds.size === 0) return;

    runningCampaignIds.forEach(id => {
        fetch(`api/campaign-api.php?action=process_batch&id=${id}&batch_size=5`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.campaign) {
                if (data.status === 'completed') {
                    runningCampaignIds.delete(id);
                    fetchCampaigns();
                }
            }
        })
        .catch(err => console.error(err));
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;").replace(/\n/g, '<br>');
}

function syncMetaTemplates() {
    const btn = document.getElementById('btnSyncMeta');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:13px; height:13px;"></i> Syncing from Meta...`;
    }

    fetch('api/campaign-api.php?action=sync_meta_templates')
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>🔄 Sync Meta Approved Templates</span>`;
        }

        if (data.success) {
            alert('🎉 ' + data.message);
            if (typeof fetchTemplates === 'function') fetchTemplates();
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>🔄 Sync Meta Approved Templates</span>`;
        }
        alert('❌ Network error syncing templates from Meta.');
    });
}

function openCreateTemplateModal() {
    if (typeof window.openModal === 'function') {
        window.openModal('create-template-modal');
    }
    const modal = document.getElementById('create-template-modal');
    if (modal) {
        modal.classList.add('open');
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
    updateTemplateLivePreview();
}

function closeCreateTemplateModal() {
    if (typeof window.closeModal === 'function') {
        window.closeModal('create-template-modal');
    }
    const modal = document.getElementById('create-template-modal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function toggleHeaderInputs() {
    const val = document.getElementById('tplHeaderTypeSelect').value;
    document.getElementById('tplHeaderTextInputWrap').style.display = (val === 'text') ? 'block' : 'none';
    updateTemplateLivePreview();
}

function updateTemplateLivePreview() {
    const titleInput = document.getElementById('tplTitleInput');
    if (!titleInput) return;
    const title = titleInput.value || 'My Custom Template';
    const slug = title.toLowerCase().replace(/[^a-z0-9_]/g, '_');
    const slugEl = document.getElementById('tplSlugPreview');
    if (slugEl) slugEl.textContent = slug;

    const headerTypeSelect = document.getElementById('tplHeaderTypeSelect');
    const headerType = headerTypeSelect ? headerTypeSelect.value : 'none';
    const headerTextInput = document.getElementById('tplHeaderText');
    const headerText = headerTextInput ? headerTextInput.value : '';
    const bodyTextInput = document.getElementById('tplBodyText');
    const bodyText = bodyTextInput ? bodyTextInput.value : '';
    const footerTextInput = document.getElementById('tplFooterText');
    const footerText = footerTextInput ? footerTextInput.value : '';

    const prevHeader = document.getElementById('prevTplHeader');
    const prevBody = document.getElementById('prevTplBody');
    const prevFooter = document.getElementById('prevTplFooter');

    if (prevHeader) {
        if (headerType === 'text' && headerText) {
            prevHeader.textContent = headerText;
            prevHeader.style.display = 'block';
        } else {
            prevHeader.style.display = 'none';
        }
    }

    if (prevBody) {
        prevBody.textContent = bodyText || 'Dear {{1}}, welcome to Marg ERP! Your bill amount is {{2}}.';
    }

    if (prevFooter) {
        if (footerText) {
            prevFooter.textContent = footerText;
            prevFooter.style.display = 'block';
        } else {
            prevFooter.style.display = 'none';
        }
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function handleCreateTemplateSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitTpl');
    btn.disabled = true;
    btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Submitting to Meta...`;

    const title = document.getElementById('tplTitleInput').value.trim();
    const category = document.getElementById('tplCategorySelect').value;
    const headerType = document.getElementById('tplHeaderTypeSelect').value;
    const headerText = document.getElementById('tplHeaderText').value.trim();
    const bodyText = document.getElementById('tplBodyText').value.trim();
    const footerText = document.getElementById('tplFooterText').value.trim();

    if (!title || !bodyText) {
        alert('❌ Template Title and Body Text are required!');
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="send" style="width: 14px; height: 14px;"></i> <span>Submit to Meta for Approval & Save</span>`;
        return;
    }

    const payload = new URLSearchParams();
    payload.append('title', title);
    payload.append('category', category);
    payload.append('header_type', headerType);
    payload.append('header_text', headerText);
    payload.append('body_text', bodyText);
    payload.append('footer_text', footerText);

    fetch('api/campaign-api.php?action=save_template', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="send" style="width: 14px; height: 14px;"></i> <span>Submit to Meta for Approval & Save</span>`;

        if (data.success) {
            alert('🎉 ' + data.message);
            closeCreateTemplateModal();
            document.getElementById('createTemplateForm').reset();
            if (typeof fetchTemplates === 'function') fetchTemplates();
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i data-lucide="send" style="width: 14px; height: 14px;"></i> <span>Submit to Meta for Approval & Save</span>`;
        alert('❌ Network Error while submitting template to Meta.');
    });
}
</script>

<!-- CREATE CUSTOM META WHATSAPP TEMPLATE MODAL -->
<style>
.modal-overlay#create-template-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.72);
    backdrop-filter: blur(5px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}
.modal-overlay#create-template-modal.open,
.modal-overlay#create-template-modal.active {
    display: flex !important;
}
</style>

<div id="create-template-modal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 840px; width: 92%; background: var(--bg-card); border-radius: 16px; padding: 1.75rem; box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); max-height: 90vh; overflow-y: auto;">
        <div class="modal-header flex justify-between align-center border-b pb-3 mb-4">
            <div class="flex align-center gap-2">
                <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(37, 211, 102, 0.15); display: flex; align-items: center; justify-content: center; color: #25D366;">
                    <i data-lucide="plus-circle" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h3 class="font-bold text-base m-0" style="color: var(--text-main);">Create Meta WhatsApp Template</h3>
                    <p class="text-xs text-muted m-0">Create & automatically submit template to Meta for official approval</p>
                </div>
            </div>
            <button type="button" class="btn-close-modal" onclick="closeCreateTemplateModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form id="createTemplateForm" onsubmit="handleCreateTemplateSubmit(event)">
            <div style="display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem;">
                
                <!-- Left Column: Form Controls -->
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="form-label font-bold text-xs">Template Title / Name *</label>
                            <input type="text" id="tplTitleInput" name="title" class="input-styled text-xs" required placeholder="e.g. Festival Offer Alert" oninput="updateTemplateLivePreview()">
                            <span class="text-xs text-muted" style="font-size: 0.7rem;">Auto Meta Slug: <code id="tplSlugPreview">festival_offer_alert</code></span>
                        </div>
                        <div>
                            <label class="form-label font-bold text-xs">Meta Category *</label>
                            <select id="tplCategorySelect" name="category" class="input-styled text-xs font-bold" required onchange="updateTemplateLivePreview()">
                                <option value="MARKETING">MARKETING (Offers, Sales, Promotions)</option>
                                <option value="UTILITY">UTILITY (Bills, Reminders, Order Updates)</option>
                                <option value="AUTHENTICATION">AUTHENTICATION (OTP, Security)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="form-label font-bold text-xs">Header Type</label>
                            <select id="tplHeaderTypeSelect" name="header_type" class="input-styled text-xs" onchange="toggleHeaderInputs()">
                                <option value="none">None (No Header)</option>
                                <option value="text">Text Header</option>
                            </select>
                        </div>
                        <div id="tplHeaderTextInputWrap" style="display: none;">
                            <label class="form-label font-bold text-xs">Header Text</label>
                            <input type="text" id="tplHeaderText" name="header_text" class="input-styled text-xs" placeholder="e.g. MARG ERP SPECIAL OFFER" oninput="updateTemplateLivePreview()">
                        </div>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">Template Body Text *</label>
                        <textarea id="tplBodyText" name="body_text" class="input-styled text-xs" rows="5" required placeholder="Dear {{1}}, welcome to Marg ERP! Your bill amount is {{2}}." oninput="updateTemplateLivePreview()"></textarea>
                        <span class="text-xs text-muted" style="font-size: 0.7rem;">Use <code>{{1}}</code>, <code>{{2}}</code> or <code>{name}</code>, <code>{company}</code> for dynamic variables.</span>
                    </div>

                    <div>
                        <label class="form-label font-bold text-xs">Footer Text (Optional)</label>
                        <input type="text" id="tplFooterText" name="footer_text" class="input-styled text-xs" placeholder="e.g. Reply STOP to opt out" oninput="updateTemplateLivePreview()">
                    </div>
                </div>

                <!-- Right Column: Live WhatsApp Message Preview -->
                <div style="background: #efeae2; border-radius: 12px; padding: 1.25rem; border: 1px solid var(--border-color); display: flex; flex-direction: column;">
                    <div style="font-size: 0.75rem; font-weight: 700; color: #128C7E; text-transform: uppercase; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
                        <i data-lucide="smartphone" style="width: 14px; height: 14px;"></i>
                        <span>Live WhatsApp Preview</span>
                    </div>

                    <!-- Chat Bubble -->
                    <div style="background: #ffffff; border-radius: 8px; padding: 0.85rem; box-shadow: 0 1px 3px rgba(0,0,0,0.12); position: relative; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                        <!-- Header Preview -->
                        <div id="prevTplHeader" style="font-weight: 700; font-size: 0.85rem; color: #111b21; margin-bottom: 0.4rem; display: none;"></div>
                        <!-- Body Preview -->
                        <div id="prevTplBody" style="font-size: 0.825rem; color: #111b21; line-height: 1.4; white-space: pre-wrap;">Your template preview will appear here...</div>
                        <!-- Footer Preview -->
                        <div id="prevTplFooter" style="font-size: 0.7rem; color: #667781; margin-top: 0.5rem; display: none;"></div>
                        <!-- Timestamp & Meta Checkmark -->
                        <div style="text-align: right; font-size: 0.65rem; color: #667781; margin-top: 0.3rem; display: flex; align-items: center; justify-content: flex-end; gap: 2px;">
                            <span><?php echo date('H:i'); ?></span>
                            <i data-lucide="check-check" style="width: 12px; height: 12px; color: #53bdeb;"></i>
                        </div>
                    </div>

                    <div style="margin-top: auto; padding-top: 1rem; text-align: center;">
                        <span class="badge" style="background: rgba(37, 211, 102, 0.15); color: #10b981; font-size: 0.7rem;">
                            <i data-lucide="shield-check" style="width: 12px; height: 12px; margin-right: 4px;"></i>
                            Auto Meta Graph API Submission
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-4 border-t pt-3">
                <button type="button" class="btn btn-secondary text-xs" onclick="closeCreateTemplateModal()">Cancel</button>
                <button type="submit" id="btnSubmitTpl" class="btn btn-primary text-xs font-bold flex align-center gap-2">
                    <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                    <span>Submit to Meta for Approval & Save</span>
                </button>
            </div>
        </form>
    </div>
</div>
