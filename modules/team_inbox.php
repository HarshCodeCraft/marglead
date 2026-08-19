<?php
/**
 * Marg CRM - Team Inbox & WhatsApp Live Chat Manager
 * Multi-agent web workspace for live WhatsApp customer conversations
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
?>

<style>
/* Modern Team Inbox Workspace Layout - Compact & Crisp proportions */
.inbox-workspace {
    display: grid;
    grid-template-columns: 290px minmax(0, 1fr) 0px;
    gap: 0;
    height: calc(100vh - 105px);
    min-height: 580px;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    transition: grid-template-columns 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.inbox-workspace.show-right-pane {
    grid-template-columns: 290px minmax(0, 1fr) 280px;
}

/* Left Pane: Conversations Navigation */
.inbox-left-pane {
    border-right: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    flex-direction: column;
    background: var(--bg-app, #f8fafc);
    overflow: hidden;
}

.inbox-left-header {
    padding: 0.75rem 0.85rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
}

.inbox-left-title {
    font-family: var(--font-heading, sans-serif);
    font-size: 0.98rem;
    font-weight: 700;
    margin-bottom: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--text-main, #0f172a);
}

.inbox-live-badge {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.pulse-dot {
    width: 6px;
    height: 6px;
    background: #10b981;
    border-radius: 50%;
    animation: livePulse 1.8s infinite;
}

@keyframes livePulse {
    0% { transform: scale(0.95); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.5; }
    100% { transform: scale(0.95); opacity: 1; }
}

.inbox-search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.inbox-search-box i {
    position: absolute;
    left: 0.75rem;
    color: var(--text-muted, #64748b);
    pointer-events: none;
    width: 14px;
    height: 14px;
}

.inbox-search-input {
    width: 100%;
    padding: 0.45rem 0.75rem 0.45rem 2.2rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.8rem;
    background: var(--bg-app, #f8fafc);
    color: var(--text-main, #0f172a);
    box-sizing: border-box;
    transition: all 0.2s ease;
}

.inbox-search-input:focus {
    outline: none;
    background: var(--bg-card, #ffffff);
    border-color: var(--primary, #2563eb);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.12);
}

.inbox-status-tabs {
    display: flex;
    gap: 2px;
    padding: 5px 6px;
    background: var(--bg-app, #f8fafc);
    border-bottom: 1px solid var(--border-color, #e2e8f0);
}

.tab-btn {
    flex: 1;
    padding: 4px 2px;
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    text-align: center;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 3px;
}

.tab-btn:hover {
    background: rgba(0, 0, 0, 0.04);
}

.tab-btn.active {
    background: var(--bg-card, #ffffff);
    color: var(--primary, #2563eb);
    border-color: var(--border-color, #cbd5e1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.tab-count {
    background: rgba(0, 0, 0, 0.06);
    padding: 1px 5px;
    border-radius: 8px;
    font-size: 0.65rem;
}

.tab-btn.active .tab-count {
    background: rgba(37, 99, 235, 0.1);
    color: var(--primary, #2563eb);
}

.inbox-conv-list {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
}

.conv-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.55rem 0.85rem;
    height: 62px;
    min-height: 62px;
    max-height: 62px;
    border-bottom: 1px solid var(--border-color, #f1f5f9);
    cursor: pointer;
    transition: background 0.15s ease;
    position: relative;
    box-sizing: border-box;
    overflow: hidden;
}

.conv-item:hover {
    background: rgba(37, 99, 235, 0.04);
}

.conv-item.active {
    background: rgba(37, 99, 235, 0.08);
}

.conv-item.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--primary, #2563eb);
}

.conv-avatar-wrap {
    flex-shrink: 0;
}

.conv-avatar {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.92rem;
    font-family: var(--font-heading, sans-serif);
}

.conv-details {
    flex: 1;
    min-width: 0;
}

.conv-name-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2px;
}

.conv-name {
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--text-main, #0f172a);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conv-time {
    font-size: 0.67rem;
    color: var(--text-muted, #94a3b8);
    flex-shrink: 0;
    margin-left: 4px;
}

.conv-preview-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
}

.conv-preview {
    font-size: 0.76rem;
    color: var(--text-muted, #64748b);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    max-width: 140px;
    line-height: 1.2;
}

.status-pill-mini {
    font-size: 0.62rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Center Pane: Active Chat Window */
.inbox-center-pane {
    display: flex;
    flex-direction: column;
    height: 100%;
    max-height: 100%;
    min-height: 0;
    overflow: hidden;
    background: var(--bg-card, #ffffff);
}

.chat-header {
    flex-shrink: 0;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-card, #ffffff);
    z-index: 10;
}

.chat-user-info {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.chat-header-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
}

.chat-user-title {
    font-family: var(--font-heading, sans-serif);
    font-weight: 700;
    font-size: 0.92rem;
    color: var(--text-main, #0f172a);
    line-height: 1.2;
}

.chat-user-subtitle {
    font-size: 0.72rem;
    color: var(--text-muted, #64748b);
    display: flex;
    align-items: center;
    gap: 4px;
}

.chat-header-actions {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    flex-wrap: wrap;
}

/* Chat Wallpaper & Body Canvas */
.chat-body {
    flex: 1;
    min-height: 0;
    padding: 1rem 1.25rem;
    overflow-y: auto;
    background-color: #efeae2;
    background-image: 
        radial-gradient(circle at 20px 20px, rgba(0, 0, 0, 0.03) 2%, transparent 0%);
    background-size: 60px 60px;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    scrollbar-width: thin;
}

[data-theme="dark"] .chat-body {
    background-color: #0b141a;
    background-image: 
        radial-gradient(circle at 20px 20px, rgba(255, 255, 255, 0.03) 2%, transparent 0%);
}

/* WhatsApp Message Bubbles - Strictly Constrained Width & Soft Colors */
.msg-bubble-wrap {
    display: flex;
    width: 100%;
    animation: msgFadeIn 0.2s ease-out forwards;
}

@keyframes msgFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.msg-bubble-wrap.inbound {
    justify-content: flex-start;
}

.msg-bubble-wrap.outbound {
    justify-content: flex-end;
}

.msg-bubble-wrap.system {
    justify-content: center;
    margin: 0.35rem 0;
}

.msg-bubble {
    max-width: min(72%, 540px);
    padding: 0.55rem 0.85rem;
    font-size: 0.84rem;
    line-height: 1.4;
    position: relative;
    word-break: break-word;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.msg-bubble.inbound {
    background: #ffffff;
    color: #111b21;
    border: 1px solid #e9edef;
    border-radius: 12px 12px 12px 2px;
}

[data-theme="dark"] .msg-bubble.inbound {
    background: #202c33;
    color: #e9edef;
    border-color: #2a3942;
}

/* WhatsApp Web Soft Emerald Outbound Bubble Color */
.msg-bubble.outbound {
    background: #d9fdd3;
    color: #111b21;
    border: 1px solid #c2eed1;
    border-radius: 12px 12px 2px 12px;
}

[data-theme="dark"] .msg-bubble.outbound {
    background: #005c4b;
    color: #e9edef;
    border-color: #026d59;
}

.msg-bubble.system {
    background: rgba(245, 158, 11, 0.12);
    color: #92400e;
    border: 1px dashed rgba(245, 158, 11, 0.3);
    font-size: 0.74rem;
    font-weight: 500;
    text-align: center;
    border-radius: 16px;
    padding: 4px 12px;
    box-shadow: none;
}

.msg-sender-tag {
    font-size: 0.68rem;
    font-weight: 700;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.msg-bubble.inbound .msg-sender-tag {
    color: #2563eb;
}

.msg-bubble.outbound .msg-sender-tag {
    color: #0d652d;
}

[data-theme="dark"] .msg-bubble.outbound .msg-sender-tag {
    color: #53bdeb;
}

.msg-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    margin-top: 3px;
}

.msg-time {
    font-size: 0.66rem;
    color: #667781;
}

[data-theme="dark"] .msg-time {
    color: #8696a0;
}

.read-ticks {
    font-size: 0.72rem;
    color: #53bdeb;
    font-weight: bold;
}

.flow-card-badge {
    background: rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(0, 0, 0, 0.08);
    color: #111b21;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 0.78rem;
    margin-top: 6px;
}

[data-theme="dark"] .flow-card-badge {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(255, 255, 255, 0.12);
    color: #e9edef;
}

/* Chat Input Composer */
.chat-input-bar {
    flex-shrink: 0;
    padding: 0.65rem 0.9rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    z-index: 10;
}

.quick-actions-toolbar {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    flex-wrap: wrap;
}

.quick-reply-select {
    padding: 0.35rem 0.6rem;
    border-radius: 6px;
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.74rem;
    background: var(--bg-app, #f8fafc);
    color: var(--text-main, #0f172a);
    cursor: pointer;
    max-width: 220px;
    outline: none;
}

.composer-row {
    display: flex;
    gap: 0.6rem;
    align-items: flex-end;
}

.chat-textarea {
    flex: 1;
    border-radius: 10px;
    border: 1px solid var(--border-color, #cbd5e1);
    padding: 0.55rem 0.85rem;
    font-size: 0.85rem;
    resize: none;
    height: 42px;
    min-height: 42px;
    max-height: 100px;
    font-family: inherit;
    box-sizing: border-box;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    transition: border-color 0.15s ease;
    line-height: 1.4;
}

.chat-textarea:focus {
    outline: none;
    border-color: var(--primary, #2563eb);
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.12);
}

.btn-send-msg {
    height: 42px;
    padding: 0 1.1rem;
    border-radius: 10px;
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s ease;
    flex-shrink: 0;
}

.btn-send-msg:hover {
    background: #1d4ed8;
}

/* Right Pane: Customer CRM Context (Hidden by default, shown when toggled) */
.inbox-right-pane {
    border-left: none;
    padding: 0;
    background: var(--bg-app, #f8fafc);
    overflow: hidden;
    display: none;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.inbox-workspace.show-right-pane .inbox-right-pane {
    display: block;
    padding: 1rem;
    opacity: 1;
    overflow-y: auto;
    border-left: 1px solid var(--border-color, #e2e8f0);
}

.profile-card {
    text-align: center;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    margin-bottom: 0.75rem;
}

.profile-card-avatar {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0 auto 0.6rem auto;
    font-family: var(--font-heading, sans-serif);
}

.refresh-spin {
    animation: spin 0.6s linear;
}

@keyframes spin {
    100% { transform: rotate(360deg); }
}
</style>

<div class="inbox-workspace" id="mainInboxWorkspace">
    <!-- 1. LEFT PANE: CONVERSATIONS LIST -->
    <div class="inbox-left-pane">
        <div class="inbox-left-header">
            <div class="inbox-left-title">
                <span>Team Inbox</span>
                <span class="inbox-live-badge">
                    <span class="pulse-dot"></span> LIVE
                </span>
            </div>
            <div class="inbox-search-box">
                <i data-lucide="search"></i>
                <input type="text" id="inboxSearchInput" class="inbox-search-input" placeholder="Search customer or phone..." onkeyup="fetchConversations()">
            </div>
        </div>
        <!-- Status Filter Tabs -->
        <div class="inbox-status-tabs">
            <button type="button" class="tab-btn active" id="tab-open" onclick="switchFilterTab('open')">
                Open <span class="tab-count" id="cnt-open">0</span>
            </button>
            <button type="button" class="tab-btn" id="tab-pending" onclick="switchFilterTab('pending')">
                Pending <span class="tab-count" id="cnt-pending">0</span>
            </button>
            <button type="button" class="tab-btn" id="tab-closed" onclick="switchFilterTab('closed')">
                Closed <span class="tab-count" id="cnt-closed">0</span>
            </button>
            <button type="button" class="tab-btn" id="tab-all" onclick="switchFilterTab('all')">
                All <span class="tab-count" id="cnt-all">0</span>
            </button>
        </div>
        <div class="inbox-conv-list" id="conversationsContainer">
            <div style="padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.82rem;">
                <i data-lucide="loader-2" style="width: 20px; height: 20px; animation: spin 1s linear infinite; margin-bottom: 0.4rem; color: var(--primary);"></i>
                <p>Loading conversations...</p>
            </div>
        </div>
    </div>

    <!-- 2. CENTER PANE: ACTIVE CHAT WINDOW -->
    <div class="inbox-center-pane">
        <!-- Chat Header -->
        <div class="chat-header">
            <div class="chat-user-info">
                <div class="chat-header-avatar" id="activeAvatar">C</div>
                <div>
                    <div class="chat-user-title" id="activeName">Select a Conversation</div>
                    <div class="chat-user-subtitle" id="activePhone">
                        <i data-lucide="circle" style="width: 7px; height: 7px; fill: #10b981; color: #10b981;"></i>
                        <span>WhatsApp Live Desk</span>
                    </div>
                </div>
            </div>
            <div class="chat-header-actions">
                <div id="ticketHeaderBadge"></div>
                <div id="windowTimerBadge"></div>

                <!-- Clean Single Dynamic Action Button (Close / Re-open) -->
                <div id="chatStatusActionBtn"></div>

                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="manualRefreshChat(this)" title="Refresh Chat History">
                    <i data-lucide="rotate-cw" id="refreshIcon" style="width: 12px; height: 12px;"></i>
                </button>
                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="toggleRightPane()" title="Toggle Customer Profile Panel">
                    <i data-lucide="panel-right" style="width: 13px; height: 13px;"></i>
                </button>
            </div>
        </div>

        <!-- Scrollable Messages Canvas -->
        <div class="chat-body" id="chatMessagesContainer">
            <div style="margin: auto; text-align: center; color: var(--text-muted); font-size: 0.85rem; max-width: 280px;">
                <div style="width: 50px; height: 50px; border-radius: 50%; background: var(--bg-card); display: flex; align-items: center; justify-content: center; margin: 0 auto 0.75rem auto; box-shadow: var(--shadow-sm);">
                    <i data-lucide="message-square" style="width: 24px; height: 24px; color: var(--primary);"></i>
                </div>
                <h4 style="font-weight: 700; color: var(--text-main); margin-bottom: 0.2rem; font-size: 0.9rem;">No Chat Selected</h4>
                <p style="font-size: 0.78rem; line-height: 1.4;">Choose a customer thread from the left list to view WhatsApp messages & reply live.</p>
            </div>
        </div>

        <!-- Chat Input Composer -->
        <div class="chat-input-bar" id="chatInputBar">
            <div class="quick-actions-toolbar" id="quickActionsToolbar">
                <select id="quickReplySelect" class="quick-reply-select" onchange="insertQuickReply(this)">
                    <option value="">⚡ Quick Canned Reply...</option>
                    <option value="Thank you for contacting Marg Soft Solution Support! How can we assist your business today?">👋 Welcome & Greet</option>
                    <option value="Kindly provide your Marg License Number or Customer ID to check your AMC status.">🎫 Request License No.</option>
                    <option value="Our sales executive will get in touch with you shortly on 7523830026.">📞 Sales Callback Info</option>
                    <option value="Your issue has been resolved successfully. Have a great day!">✅ Resolve & Close Ticket</option>
                </select>

                <button type="button" id="btnQuickButtons" class="btn-pill btn-pill-outline text-xs" onclick="sendQuickButtons()" title="Send Interactive Options">
                    <i data-lucide="grid" style="width: 12px; height: 12px; color: #10b981;"></i>
                    Send Quick Buttons
                </button>
                <button type="button" id="btnQuickFlow" class="btn-pill btn-pill-outline text-xs" onclick="sendQuickFlow()" title="Send WhatsApp Ticket Form">
                    <i data-lucide="file-text" style="width: 12px; height: 12px; color: #3b82f6;"></i>
                    Send Support Form
                </button>
            </div>
            <div class="composer-row" id="composerRow">
                <textarea id="replyMessageInput" class="chat-textarea" placeholder="Type a message to reply on WhatsApp... (Ctrl+Enter to send)" onkeydown="handleKeyDown(event)"></textarea>
                <button type="button" id="btnSendMsg" class="btn-send-msg" onclick="submitReplyMessage()">
                    <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                    <span>Send</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3. RIGHT PANE: CUSTOMER CRM CONTEXT -->
    <div class="inbox-right-pane" id="profilePane">
        <div class="profile-card">
            <div class="profile-card-avatar" id="rightAvatar">C</div>
            <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700; color: var(--text-main);" id="rightName">Client Details</h3>
            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;" id="rightCompany">Marg ERP Customer</div>
            <span class="badge" style="margin-top: 6px; background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem;" id="rightStatus">Active</span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.65rem; font-size: 0.8rem;">
            <div>
                <label class="text-xs text-muted font-semibold" style="letter-spacing: 0.02em; font-size: 0.68rem;">24H META SERVICE WINDOW</label>
                <div id="rightWindowTimer" style="font-weight: 700; font-size: 0.8rem; margin-top: 2px;">--</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold" style="letter-spacing: 0.02em; font-size: 0.68rem;">PHONE NUMBER</label>
                <div style="font-weight: 600; color: var(--text-main); margin-top: 1px;" id="rightPhone">+91 --</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold" style="letter-spacing: 0.02em; font-size: 0.68rem;">EMAIL ADDRESS</label>
                <div style="font-weight: 600; color: var(--text-main); margin-top: 1px;" id="rightEmail">N/A</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold" style="letter-spacing: 0.02em; font-size: 0.68rem;">ASSOCIATED LEAD ID</label>
                <div id="rightLeadLink" style="font-weight: 600; color: #3b82f6; margin-top: 1px;">N/A</div>
            </div>
            <div style="margin-top: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.65rem;">
                <label class="text-xs text-muted font-semibold mb-1 block" style="letter-spacing: 0.02em; font-size: 0.68rem;">SUPPORT TICKET CONTEXT</label>
                <div id="rightTicketCard"></div>
            </div>
            <div style="margin-top: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.65rem;">
                <label class="text-xs text-muted font-semibold mb-1 block" style="letter-spacing: 0.02em; font-size: 0.68rem;">AUDIT & STATUS HISTORY</label>
                <div id="rightAuditHistory" style="max-height: 110px; overflow-y: auto; font-size: 0.72rem;"></div>
            </div>
            <div style="margin-top: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.65rem;">
                <label class="text-xs text-muted font-semibold mb-1 block" style="letter-spacing: 0.02em; font-size: 0.68rem;">QUICK CRM ACTIONS</label>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mb-1" onclick="window.location.href='index.php?page=leads'" style="padding: 3px 8px;">
                    <i data-lucide="user-plus" style="width: 12px; height: 12px;"></i>
                    View CRM Leads
                </button>
                <a href="index.php?page=support" class="btn-pill btn-pill-outline w-full text-xs block text-center" style="text-decoration: none; padding: 3px 8px;">
                    <i data-lucide="life-buoy" style="width: 12px; height: 12px;"></i>
                    Support Desk
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let currentActivePhone = '';
let currentFilterTab = 'open';
let currentChatStatus = 'open';
let isPolling = true;

let lastConversationsHash = '';
let lastMessagesHash = '';
let lastProfileHash = '';

document.addEventListener('DOMContentLoaded', () => {
    fetchConversations();
    setInterval(() => {
        if (isPolling) {
            fetchConversations(false);
            if (currentActivePhone) {
                fetchMessages(currentActivePhone, false);
            }
        }
    }, 4000);
});

function toggleRightPane() {
    const ws = document.getElementById('mainInboxWorkspace');
    if (ws) {
        ws.classList.toggle('show-right-pane');
    }
}

function manualRefreshChat(btn) {
    const icon = document.getElementById('refreshIcon');
    if (icon) icon.classList.add('refresh-spin');
    lastConversationsHash = '';
    lastMessagesHash = '';
    lastProfileHash = '';
    if (currentActivePhone) {
        fetchMessages(currentActivePhone, true);
    }
    fetchConversations(false);
    setTimeout(() => {
        if (icon) icon.classList.remove('refresh-spin');
    }, 600);
}

function switchFilterTab(tab) {
    currentFilterTab = tab;
    lastConversationsHash = '';
    ['open', 'pending', 'closed', 'all'].forEach(t => {
        const btn = document.getElementById('tab-' + t);
        if (btn) {
            if (t === tab) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });
    fetchConversations();
}

let lastCountsHash = '';

function cleanPreviewText(text) {
    if (!text) return 'Media / Event';
    let clean = text.replace(/[\r\n]+/g, ' ').replace(/<br\s*\/?>/gi, ' ').trim();
    clean = clean.replace(/\s+/g, ' ');
    if (clean.length > 55) {
        clean = clean.substring(0, 55) + '...';
    }
    return clean;
}

function fetchConversations(showLoading = true) {
    const searchInput = document.getElementById('inboxSearchInput');
    const search = searchInput ? searchInput.value : '';
    fetch(`api/inbox-api.php?action=conversations&status=${encodeURIComponent(currentFilterTab)}&search=${encodeURIComponent(search)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderConversations(data.conversations);
            if (data.counts) {
                const cntOpen = document.getElementById('cnt-open');
                const cntPending = document.getElementById('cnt-pending');
                const cntClosed = document.getElementById('cnt-closed');
                const cntAll = document.getElementById('cnt-all');
                if (cntOpen) cntOpen.innerText = (data.counts.open !== undefined) ? data.counts.open : 0;
                if (cntPending) cntPending.innerText = (data.counts.pending !== undefined) ? data.counts.pending : 0;
                if (cntClosed) cntClosed.innerText = (data.counts.closed !== undefined) ? data.counts.closed : 0;
                if (cntAll) cntAll.innerText = (data.counts.all !== undefined) ? data.counts.all : 0;
            }
        }
    })
    .catch(err => console.error(err));
}

function renderConversations(list) {
    const container = document.getElementById('conversationsContainer');
    if (!container) return;

    if (!list || list.length === 0) {
        lastConversationsHash = 'empty';
        container.innerHTML = `<div style="padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">No ${currentFilterTab} conversations found.</div>`;
        return;
    }

    const newHash = JSON.stringify(list) + '_' + currentActivePhone + '_' + currentFilterTab;
    if (newHash === lastConversationsHash) {
        return; // DATA UNCHANGED -> SKIP RE-RENDER TO PREVENT FLICKER
    }
    lastConversationsHash = newHash;

    let html = '';
    list.forEach(c => {
        const phone = c.recipient_or_sender;
        const isActive = (phone === currentActivePhone) ? 'active' : '';
        const initial = (c.customer_name || 'C').charAt(0).toUpperCase();

        let statusPill = '';
        if (c.chat_status === 'closed') {
            statusPill = `<span class="status-pill-mini" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">🔒 Closed</span>`;
        } else if (c.chat_status === 'pending') {
            statusPill = `<span class="status-pill-mini" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">🟡 Pending</span>`;
        } else {
            statusPill = `<span class="status-pill-mini" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">🟢 Open</span>`;
        }

        const previewText = cleanPreviewText(c.message_body);

        html += `
        <div class="conv-item ${isActive}" onclick="selectConversation('${phone}')">
            <div class="conv-avatar-wrap">
                <div class="conv-avatar">${initial}</div>
            </div>
            <div class="conv-details">
                <div class="conv-name-row">
                    <div class="conv-name">${escapeHtml(c.customer_name)}</div>
                    <div class="conv-time">${c.formatted_time}</div>
                </div>
                <div class="conv-preview-row">
                    <div class="conv-preview" title="${escapeHtml(previewText)}">${escapeHtml(previewText)}</div>
                    ${statusPill}
                </div>
            </div>
        </div>
        `;
    });

    container.innerHTML = html;
}

function selectConversation(phone) {
    if (currentActivePhone === phone) return;
    currentActivePhone = phone;
    lastConversationsHash = '';
    lastMessagesHash = '';
    lastProfileHash = '';
    fetchConversations(false);
    fetchMessages(phone, true);
}

function scrollChatToBottom() {
    const body = document.getElementById('chatMessagesContainer');
    if (body) {
        body.scrollTop = body.scrollHeight;
    }
}

function fetchMessages(phone, scrollBottom = true) {
    if (!phone) return;
    fetch(`api/inbox-api.php?action=messages&phone=${encodeURIComponent(phone)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderMessages(data.messages, scrollBottom);
            renderProfile(data.profile);
        }
    });
}

function renderMessages(messages, scrollBottom = false) {
    const container = document.getElementById('chatMessagesContainer');
    if (!container) return;

    if (!messages || messages.length === 0) {
        lastMessagesHash = 'empty_' + currentActivePhone;
        container.innerHTML = '<div style="margin: auto; color: var(--text-muted); font-size: 0.82rem;">No messages yet in this thread. Start messaging below.</div>';
        return;
    }

    const newHash = JSON.stringify(messages) + '_' + currentActivePhone;
    if (newHash === lastMessagesHash) {
        return; // DATA UNCHANGED -> SKIP RE-RENDER TO PREVENT FLICKER & RE-ANIMATION
    }
    const isFirstLoad = (lastMessagesHash === '');
    lastMessagesHash = newHash;

    let html = '';
    messages.forEach(m => {
        const isSystem = (m.message_type === 'system' || m.status === 'closed');
        const isOutbound = (m.direction === 'OUTBOUND');

        if (isSystem) {
            html += `
            <div class="msg-bubble-wrap system">
                <div class="msg-bubble system">
                    <i data-lucide="info" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i>
                    ${escapeHtml(m.message_body)}
                </div>
            </div>
            `;
        } else {
            const bubbleClass = isOutbound ? 'outbound' : 'inbound';
            const wrapClass = isOutbound ? 'outbound' : 'inbound';
            const senderTag = isOutbound 
                ? `<div class="msg-sender-tag"><i data-lucide="bot" style="width: 11px; height: 11px;"></i> Support Team / Bot</div>`
                : `<div class="msg-sender-tag"><i data-lucide="user" style="width: 11px; height: 11px;"></i> Customer</div>`;

            let bodyText = escapeHtml(m.message_body || '');
            if (m.message_type === 'flow_submission' || (m.message_body && m.message_body.includes('Ticket'))) {
                bodyText += `<div class="flow-card-badge">📋 <strong>Support Form Received</strong></div>`;
            }

            const readReceipt = isOutbound ? `<span class="read-ticks" title="Delivered & Read">✓✓</span>` : '';

            html += `
            <div class="msg-bubble-wrap ${wrapClass}">
                <div class="msg-bubble ${bubbleClass}">
                    ${senderTag}
                    <div>${bodyText}</div>
                    <div class="msg-footer">
                        <span class="msg-time">${m.formatted_time}</span>
                        ${readReceipt}
                    </div>
                </div>
            </div>
            `;
        }
    });

    container.innerHTML = html;
    if (window.lucide) {
        lucide.createIcons();
    }

    if (scrollBottom || isFirstLoad) {
        scrollChatToBottom();
        setTimeout(scrollChatToBottom, 100);
        setTimeout(scrollChatToBottom, 300);
    }
}

function renderProfile(p) {
    if (!p) return;

    const newHash = JSON.stringify(p);
    if (newHash === lastProfileHash) {
        return; // PROFILE UNCHANGED -> SKIP RE-RENDER
    }
    lastProfileHash = newHash;

    currentChatStatus = p.chat_status || 'open';

    const activeName = document.getElementById('activeName');
    const activePhone = document.getElementById('activePhone');
    const activeAvatar = document.getElementById('activeAvatar');
    if (activeName) activeName.innerText = p.name;
    if (activePhone) activePhone.innerHTML = `<i data-lucide="circle" style="width: 7px; height: 7px; fill: #10b981; color: #10b981;"></i> +${p.phone}`;
    if (activeAvatar) activeAvatar.innerText = p.name.charAt(0).toUpperCase();

    const rightName = document.getElementById('rightName');
    const rightCompany = document.getElementById('rightCompany');
    const rightPhone = document.getElementById('rightPhone');
    const rightEmail = document.getElementById('rightEmail');
    const rightAvatar = document.getElementById('rightAvatar');
    if (rightName) rightName.innerText = p.name;
    if (rightCompany) rightCompany.innerText = p.company;
    if (rightPhone) rightPhone.innerText = '+' + p.phone;
    if (rightEmail) rightEmail.innerText = p.email || 'N/A';
    if (rightAvatar) rightAvatar.innerText = p.name.charAt(0).toUpperCase();

    // Clean Single Action Button in Header (Close Chat vs Re-open Chat - Matched with Dashboard buttons)
    const actionBtnElem = document.getElementById('chatStatusActionBtn');
    if (actionBtnElem) {
        if (currentChatStatus === 'closed') {
            actionBtnElem.innerHTML = `
                <button type="button" class="btn-pill btn-pill-dark text-xs" style="background: #10b981; color: #ffffff; border: none; padding: 4px 12px; font-weight: 700; cursor: pointer;" onclick="updateChatStatus('open')" title="Re-open conversation to enable messaging">
                    🟢 Re-open Chat
                </button>
            `;
        } else {
            actionBtnElem.innerHTML = `
                <button type="button" class="btn-pill btn-pill-outline text-xs" style="color: #ef4444; border-color: rgba(239,68,68,0.4); padding: 4px 12px; font-weight: 700; cursor: pointer;" onclick="updateChatStatus('closed')" title="Close & resolve conversation">
                    🔒 Close Chat
                </button>
            `;
        }
    }

    // 24h Window Badge (No Countdown Time text shown)
    const timerElem = document.getElementById('rightWindowTimer');
    const badgeElem = document.getElementById('windowTimerBadge');

    if (timerElem && badgeElem) {
        if (p.window_status === 'Active') {
            timerElem.innerHTML = `<span style="color: #10b981; font-weight: 700;">⚡ 24h Window Active</span>`;
            badgeElem.innerHTML = `<span class="badge" style="background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; padding: 3px 8px;">⚡ 24h Window Active</span>`;
        } else {
            timerElem.innerHTML = `<span style="color: #ef4444; font-weight: 700;">🔒 24h Window Expired</span>`;
            badgeElem.innerHTML = `<span class="badge" style="background: rgba(239,68,68,0.12); color: #ef4444; font-weight: 700; font-size: 0.68rem; padding: 3px 8px;">🔒 24h Expired</span>`;
        }
    }

    const rightLeadLink = document.getElementById('rightLeadLink');
    if (rightLeadLink) {
        if (p.lead_id) {
            rightLeadLink.innerHTML = `<a href="index.php?page=lead_details&id=${p.lead_id}" style="color: #2563eb; text-decoration: underline;">${p.lead_id}</a>`;
        } else {
            rightLeadLink.innerText = 'N/A';
        }
    }

    // Render Ticket Header Badge & Right Profile Ticket Card
    const ticketHeaderBadge = document.getElementById('ticketHeaderBadge');
    if (ticketHeaderBadge) {
        if (p.ticket) {
            ticketHeaderBadge.innerHTML = `
                <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill" style="background: rgba(59,130,246,0.12); color: #2563eb; border: 1px solid rgba(59,130,246,0.25); font-weight: 700; text-decoration: none; font-size: 0.68rem; padding: 2px 7px;">
                    🎫 Ticket ${escapeHtml(p.ticket.id)} (${escapeHtml(p.ticket.status)})
                </a>
            `;
        } else {
            ticketHeaderBadge.innerHTML = '';
        }
    }

    const rightTicketCard = document.getElementById('rightTicketCard');
    if (rightTicketCard) {
        if (p.ticket) {
            rightTicketCard.innerHTML = `
                <div style="background: rgba(59,130,246,0.06); border: 1px solid rgba(59,130,246,0.2); padding: 0.6rem; border-radius: 8px; margin-top: 0.3rem;">
                    <div style="font-size: 0.66rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; margin-bottom: 2px;">Active Ticket</div>
                    <div style="font-weight: 700; font-size: 0.82rem; color: var(--text-main);">${escapeHtml(p.ticket.id)} <span class="badge text-xs" style="background: #e0f2fe; color: #0369a1; padding: 1px 4px; font-size: 0.65rem;">${escapeHtml(p.ticket.status)}</span></div>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">${escapeHtml(p.ticket.subject)}</div>
                    <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill text-xs w-full block text-center mt-1" style="background: #2563eb; color: white; text-decoration: none; font-weight: 600; padding: 2px 6px;">
                        🎫 Open Ticket
                    </a>
                </div>
            `;
        } else {
            rightTicketCard.innerHTML = `
                <div style="font-size: 0.74rem; color: var(--text-muted); font-style: italic;">No open ticket found for this client.</div>
            `;
        }
    }

    const rightStatusElem = document.getElementById('rightStatus');
    if (rightStatusElem && p.chat_status) {
        if (p.chat_status === 'closed') {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(239,68,68,0.12); color: #ef4444; font-weight: 700; font-size: 0.68rem;';
            rightStatusElem.innerText = '🔒 Closed';
        } else if (p.chat_status === 'pending') {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(245,158,11,0.12); color: #d97706; font-weight: 700; font-size: 0.68rem;';
            rightStatusElem.innerText = '🟡 Pending';
        } else {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem;';
            rightStatusElem.innerText = '🟢 Open';
        }
    }

    // Render Audit History List
    const rightAuditElem = document.getElementById('rightAuditHistory');
    if (rightAuditElem) {
        if (p.audit_logs && p.audit_logs.length > 0) {
            let auditHtml = '';
            p.audit_logs.forEach(a => {
                const actBadge = (a.action === 'closed') 
                    ? `<span style="color: #ef4444; font-weight: 700;">🔒 Closed</span>`
                    : ((a.action === 'reopened')
                        ? `<span style="color: #10b981; font-weight: 700;">🟢 Reopened</span>`
                        : `<span style="color: #d97706; font-weight: 700;">🟡 Pending</span>`);
                auditHtml += `
                    <div style="padding: 3px 0; border-bottom: 1px dashed var(--border-color, #e2e8f0);">
                        <div>${actBadge} by <strong>${escapeHtml(a.actor_name)}</strong></div>
                        <div style="color: var(--text-muted); font-size: 0.65rem; margin-top: 1px;">${a.formatted_time}</div>
                    </div>
                `;
            });
            rightAuditElem.innerHTML = auditHtml;
        } else {
            rightAuditElem.innerHTML = '<div style="color: var(--text-muted); font-style: italic;">No audit events recorded yet.</div>';
        }
    }

    // Hide/Show Composer Controls for Closed vs Open Chats
    const quickActionsToolbar = document.getElementById('quickActionsToolbar');
    const composerRow = document.getElementById('composerRow');
    const chatInputBar = document.getElementById('chatInputBar');
    const closedBanner = document.getElementById('closedChatBanner');

    if (p.chat_status === 'closed') {
        if (quickActionsToolbar) quickActionsToolbar.style.display = 'none';
        if (composerRow) composerRow.style.display = 'none';

        if (!closedBanner && chatInputBar) {
            const b = document.createElement('div');
            b.id = 'closedChatBanner';
            b.style.cssText = 'background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25); color: #ef4444; padding: 12px 16px; border-radius: 10px; font-size: 0.84rem; font-weight: 600; text-align: center; display: flex; align-items: center; justify-content: center; gap: 12px; margin: 4px 0; width: 100%; box-sizing: border-box;';
            b.innerHTML = '<span>🔒 Chat is Closed. Employees cannot send messages until re-opened.</span> <button type="button" onclick="updateChatStatus(\'open\')" class="btn-pill btn-pill-dark text-xs" style="background: #10b981; color: white; border: none; padding: 6px 14px; font-weight: bold; cursor: pointer;">🟢 Re-open Chat to Reply</button>';
            chatInputBar.appendChild(b);
        }
    } else {
        if (quickActionsToolbar) quickActionsToolbar.style.display = 'flex';
        if (composerRow) composerRow.style.display = 'flex';
        if (closedBanner) closedBanner.remove();
    }

    if (window.lucide) {
        lucide.createIcons();
    }
}

function updateChatStatus(status) {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_chat_status');
    formData.append('phone', currentActivePhone);
    formData.append('status', status);

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            lastMessagesHash = '';
            lastProfileHash = '';
            fetchMessages(currentActivePhone, true);
            fetchConversations(false);
        } else {
            alert('Error: ' + (data.message || 'Failed updating status'));
        }
    });
}

function insertQuickReply(selectElem) {
    if (currentChatStatus === 'closed') {
        alert('🔒 Chat is Closed. Click "🟢 Re-open Chat" before typing a reply.');
        selectElem.value = '';
        return;
    }
    const text = selectElem.value;
    if (text) {
        const input = document.getElementById('replyMessageInput');
        if (input) {
            input.value = text;
            selectElem.value = '';
            input.focus();
        }
    }
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && e.ctrlKey) {
        submitReplyMessage();
    }
}

function submitReplyMessage() {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }

    if (currentChatStatus === 'closed') {
        alert('🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending messages.');
        return;
    }

    const input = document.getElementById('replyMessageInput');
    const msg = input.value.trim();
    if (!msg) {
        alert('Please type a message to reply.');
        return;
    }

    input.value = '';

    const formData = new FormData();
    formData.append('action', 'send_reply');
    formData.append('phone', currentActivePhone);
    formData.append('message', msg);

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            lastMessagesHash = '';
            fetchMessages(currentActivePhone, true);
        } else {
            alert('Failed sending message: ' + (data.message || 'Error'));
        }
    });
}

function sendQuickButtons() {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }

    if (currentChatStatus === 'closed') {
        alert('🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending buttons.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_buttons');
    formData.append('phone', currentActivePhone);

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            lastMessagesHash = '';
            fetchMessages(currentActivePhone, true);
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function sendQuickFlow() {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }

    if (currentChatStatus === 'closed') {
        alert('🔒 Conversation is Closed. You must click "🟢 Re-open Chat" before sending support form.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_flow');
    formData.append('phone', currentActivePhone);

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            lastMessagesHash = '';
            fetchMessages(currentActivePhone, true);
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;").replace(/\n/g, '<br>');
}
</script>
