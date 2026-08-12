<?php
/**
 * Marg CRM - Team Inbox & WhatsApp Live Chat Manager
 * Multi-agent web workspace for live WhatsApp customer conversations
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
?>

<style>
/* Team Inbox Workspace Layout */
.inbox-workspace {
    display: grid;
    grid-template-columns: 320px minmax(0, 1fr) 300px;
    gap: 0;
    height: calc(100vh - 120px);
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05);
}

/* Left Pane: Conversations List */
.inbox-left-pane {
    border-right: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    flex-direction: column;
    background: var(--bg-body, #fafafa);
}
.inbox-left-header {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-card, #ffffff);
}
.inbox-left-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--text-main, #111827);
}
.inbox-search-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #d1d5db);
    font-size: 0.85rem;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #111827);
    box-sizing: border-box;
}
.inbox-status-tabs {
    display: flex;
    gap: 4px;
    padding: 6px 8px;
    background: var(--bg-body, #fafafa);
    border-bottom: 1px solid var(--border-color, #e5e7eb);
}
.tab-btn {
    flex: 1;
    padding: 5px 4px;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-muted, #6b7280);
    cursor: pointer;
    text-align: center;
    transition: all 0.2s ease;
}
.tab-btn:hover {
    background: rgba(0,0,0,0.05);
}
.tab-btn.active {
    background: var(--bg-card, #ffffff);
    color: var(--primary, #10b981);
    border-color: var(--border-color, #d1d5db);
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.inbox-conv-list {
    flex: 1;
    overflow-y: auto;
}
.conv-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border-color, #f3f4f6);
    cursor: pointer;
    transition: background 0.2s ease;
}
.conv-item:hover {
    background: rgba(16, 185, 129, 0.05);
}
.conv-item.active {
    background: rgba(16, 185, 129, 0.12);
    border-left: 4px solid #10b981;
}
.conv-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #10b981;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
    flex-shrink: 0;
}
.conv-details {
    flex: 1;
    min-width: 0;
}
.conv-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-main, #111827);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.conv-preview {
    font-size: 0.78rem;
    color: var(--text-muted, #6b7280);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}
.conv-meta {
    text-align: right;
    font-size: 0.7rem;
    color: var(--text-muted, #9ca3af);
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
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-card, #ffffff);
}
.chat-user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.chat-user-title {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-main, #111827);
}
.chat-user-subtitle {
    font-size: 0.75rem;
    color: #10b981;
    display: flex;
    align-items: center;
    gap: 4px;
}
.chat-body {
    flex: 1;
    min-height: 0;
    padding: 1.25rem;
    overflow-y: auto;
    background: var(--chat-bg, #efeae2);
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

/* WhatsApp Message Bubbles */
.msg-bubble-wrap {
    display: flex;
    width: 100%;
}
.msg-bubble-wrap.inbound {
    justify-content: flex-start;
}
.msg-bubble-wrap.outbound {
    justify-content: flex-end;
}
.msg-bubble-wrap.system {
    justify-content: center;
}
.msg-bubble {
    max-width: 70%;
    padding: 0.65rem 0.85rem;
    border-radius: 8px;
    font-size: 0.88rem;
    line-height: 1.4;
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    word-break: break-word;
}
.msg-bubble.inbound {
    background: #ffffff;
    color: #111827;
    border-top-left-radius: 0;
}
.msg-bubble.outbound {
    background: #dcf8c6;
    color: #111827;
    border-top-right-radius: 0;
}
.msg-bubble.system {
    background: #fef3c7;
    color: #92400e;
    border: 1px dashed #f59e0b;
    font-size: 0.78rem;
    text-align: center;
    border-radius: 20px;
    padding: 4px 14px;
}
.msg-time {
    font-size: 0.68rem;
    color: #888;
    float: right;
    margin-left: 10px;
    margin-top: 4px;
}
.flow-card-badge {
    background: #e0f2fe;
    border: 1px solid #7dd3fc;
    color: #0369a1;
    padding: 8px;
    border-radius: 6px;
    font-size: 0.8rem;
    margin-top: 6px;
}

/* Chat Input Bar */
.chat-input-bar {
    flex-shrink: 0;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-card, #ffffff);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.quick-actions-toolbar {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}
.chat-textarea {
    width: 100%;
    border-radius: 8px;
    border: 1px solid var(--border-color, #d1d5db);
    padding: 0.65rem;
    font-size: 0.9rem;
    resize: none;
    height: 48px;
    font-family: inherit;
    box-sizing: border-box;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #111827);
}

/* Right Pane: Customer CRM Profile */
.inbox-right-pane {
    border-left: 1px solid var(--border-color, #e5e7eb);
    padding: 1.25rem;
    background: var(--bg-body, #fafafa);
    overflow-y: auto;
}
.profile-card {
    text-align: center;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    margin-bottom: 1rem;
}
.profile-card-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #10b981;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 auto 0.75rem auto;
}
</style>

<div class="inbox-workspace">
    <!-- 1. LEFT PANE: CONVERSATIONS LIST -->
    <div class="inbox-left-pane">
        <div class="inbox-left-header">
            <div class="inbox-left-title">
                <span>Team Inbox</span>
                <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 0.7rem;">LIVE</span>
            </div>
            <input type="text" id="inboxSearchInput" class="inbox-search-input" placeholder="Search chats by name or phone..." onkeyup="fetchConversations()">
        </div>
        <!-- Status Filter Tabs (Open, Pending, Closed, All) -->
        <div class="inbox-status-tabs">
            <button type="button" class="tab-btn active" id="tab-open" onclick="switchFilterTab('open')">Open (<span id="cnt-open">0</span>)</button>
            <button type="button" class="tab-btn" id="tab-pending" onclick="switchFilterTab('pending')">Pending (<span id="cnt-pending">0</span>)</button>
            <button type="button" class="tab-btn" id="tab-closed" onclick="switchFilterTab('closed')">Closed (<span id="cnt-closed">0</span>)</button>
            <button type="button" class="tab-btn" id="tab-all" onclick="switchFilterTab('all')">All (<span id="cnt-all">0</span>)</button>
        </div>
        <div class="inbox-conv-list" id="conversationsContainer">
            <div style="padding: 2rem; text-align: center; color: #888; font-size: 0.85rem;">Loading conversations...</div>
        </div>
    </div>

    <!-- 2. CENTER PANE: ACTIVE CHAT WINDOW -->
    <div class="inbox-center-pane">
        <!-- Chat Header -->
        <div class="chat-header">
            <div class="chat-user-info">
                <div class="conv-avatar" id="activeAvatar">C</div>
                <div>
                    <div class="chat-user-title" id="activeName">Select a Conversation</div>
                    <div class="chat-user-subtitle" id="activePhone">
                        <i data-lucide="circle" style="width: 8px; height: 8px; fill: #10b981; color: #10b981;"></i>
                        <span>Active WhatsApp Chat</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                <div id="ticketHeaderBadge"></div>
                <div id="windowTimerBadge"></div>
                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="fetchMessages(currentActivePhone)">
                    <i data-lucide="rotate-cw" style="width: 13px; height: 13px;"></i>
                    Refresh
                </button>
                <div style="display: flex; gap: 2px; background: rgba(0,0,0,0.05); padding: 2px; border-radius: 20px;">
                    <button type="button" class="btn-pill text-xs" style="background: #10b981; color: white; padding: 2px 8px;" onclick="updateChatStatus('open')" title="Mark Chat Open">🟢 Open</button>
                    <button type="button" class="btn-pill text-xs" style="background: #f59e0b; color: white; padding: 2px 8px;" onclick="updateChatStatus('pending')" title="Mark Chat Pending">🟡 Pending</button>
                    <button type="button" class="btn-pill text-xs" style="background: #ef4444; color: white; padding: 2px 8px;" onclick="updateChatStatus('closed')" title="Mark Chat Closed">🔒 Close</button>
                </div>
            </div>
        </div>

        <!-- Scrollable Messages Window -->
        <div class="chat-body" id="chatMessagesContainer">
            <div style="margin: auto; text-align: center; color: #888; font-size: 0.9rem;">
                <i data-lucide="message-square" style="width: 42px; height: 42px; color: #cbd5e1; margin-bottom: 0.5rem;"></i>
                <p>Select a chat from the left panel to start messaging live.</p>
            </div>
        </div>

        <!-- Chat Input Bar -->
        <div class="chat-input-bar">
            <div class="quick-actions-toolbar">
                <select class="btn-pill btn-pill-outline text-xs" onchange="insertQuickReply(this)" style="max-width: 210px; cursor: pointer;">
                    <option value="">⚡ Quick Replies (Canned Responses)...</option>
                    <option value="Thank you for contacting Marg Soft Solution Support! How can we assist your business today?">👋 Welcome & Greet</option>
                    <option value="Kindly provide your Marg License Number or Customer ID to check your AMC status.">🎫 Request License No.</option>
                    <option value="Our sales executive will get in touch with you shortly on 7523830026.">📞 Sales Callback Info</option>
                    <option value="Your issue has been resolved successfully. Have a great day!">✅ Resolve & Close Ticket</option>
                </select>

                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="sendQuickButtons()" title="Send Sales/Support Reply Buttons">
                    <i data-lucide="grid" style="width: 13px; height: 13px; color: #10b981;"></i>
                    Send Buttons
                </button>
                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="sendQuickFlow()" title="Send WhatsApp Ticket Form">
                    <i data-lucide="file-text" style="width: 13px; height: 13px; color: #3b82f6;"></i>
                    Send Support Form
                </button>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <textarea id="replyMessageInput" class="chat-textarea" placeholder="Type a message to reply on WhatsApp... (Press Ctrl+Enter to send)" onkeydown="handleKeyDown(event)"></textarea>
                <button type="button" class="btn-pill btn-pill-dark" onclick="submitReplyMessage()" style="height: 48px; padding: 0 1.25rem;">
                    <i data-lucide="send" style="width: 16px; height: 16px;"></i>
                    Send
                </button>
            </div>
        </div>
    </div>

    <!-- 3. RIGHT PANE: CUSTOMER CRM CONTEXT -->
    <div class="inbox-right-pane" id="profilePane">
        <div class="profile-card">
            <div class="profile-card-avatar" id="rightAvatar">C</div>
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-main);" id="rightName">Client Details</h3>
            <div style="font-size: 0.8rem; color: #6b7280; margin-top: 2px;" id="rightCompany">Marg ERP Customer</div>
            <span class="badge" style="margin-top: 8px; background: rgba(16,185,129,0.1); color: #10b981;" id="rightStatus">Active</span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.85rem;">
            <div>
                <label class="text-xs text-muted font-semibold">24H META SERVICE WINDOW</label>
                <div id="rightWindowTimer" style="font-weight: 700; font-size: 0.82rem; margin-top: 2px;">--</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold">PHONE NUMBER</label>
                <div style="font-weight: 600; color: var(--text-main);" id="rightPhone">+91 --</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold">EMAIL ADDRESS</label>
                <div style="font-weight: 600; color: var(--text-main);" id="rightEmail">N/A</div>
            </div>
            <div>
                <label class="text-xs text-muted font-semibold">ASSOCIATED LEAD ID</label>
                <div id="rightLeadLink" style="font-weight: 600; color: #3b82f6;">N/A</div>
            </div>
            <div style="margin-top: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                <label class="text-xs text-muted font-semibold mb-1 block">SUPPORT TICKET CONTEXT</label>
                <div id="rightTicketCard"></div>
            </div>
            <div style="margin-top: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                <label class="text-xs text-muted font-semibold mb-1 block">AUDIT & STATUS HISTORY</label>
                <div id="rightAuditHistory" style="max-height: 140px; overflow-y: auto; font-size: 0.75rem;"></div>
            </div>
            <div style="margin-top: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 0.75rem;">
                <label class="text-xs text-muted font-semibold mb-2 block">QUICK CRM ACTIONS</label>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mb-2" onclick="window.location.href='index.php?page=leads'">
                    <i data-lucide="user-plus" style="width: 13px; height: 13px;"></i>
                    View CRM Leads
                </button>
                <a href="index.php?page=support" class="btn-pill btn-pill-outline w-full text-xs block text-center" style="text-decoration: none;">
                    <i data-lucide="life-buoy" style="width: 13px; height: 13px;"></i>
                    Support Operations Desk
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let currentActivePhone = '';
let currentFilterTab = 'open'; // Default 'open'
let isPolling = true;

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

function switchFilterTab(tab) {
    currentFilterTab = tab;
    ['open', 'pending', 'closed', 'all'].forEach(t => {
        const btn = document.getElementById('tab-' + t);
        if (btn) {
            if (t === tab) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });
    fetchConversations();
}

function fetchConversations(showLoading = true) {
    const search = document.getElementById('inboxSearchInput').value;
    fetch(`api/inbox-api.php?action=conversations&status=${encodeURIComponent(currentFilterTab)}&search=${encodeURIComponent(search)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderConversations(data.conversations);
            if (data.counts) {
                document.getElementById('cnt-open').innerText = data.counts.open || 0;
                document.getElementById('cnt-pending').innerText = data.counts.pending || 0;
                document.getElementById('cnt-closed').innerText = data.counts.closed || 0;
                document.getElementById('cnt-all').innerText = data.counts.all || 0;
            }
        }
    })
    .catch(err => console.error(err));
}

function renderConversations(list) {
    const container = document.getElementById('conversationsContainer');
    if (!list || list.length === 0) {
        container.innerHTML = `<div style="padding: 2rem; text-align: center; color: #888; font-size: 0.85rem;">No ${currentFilterTab} conversations found.</div>`;
        return;
    }

    let html = '';
    list.forEach(c => {
        const phone = c.recipient_or_sender;
        const isActive = (phone === currentActivePhone) ? 'active' : '';
        const initial = (c.customer_name || 'C').charAt(0).toUpperCase();

        const statusBadge = (c.chat_status === 'closed') 
            ? `<span style="color: #ef4444; font-weight: 600; font-size: 0.68rem;">🔒 Closed</span>`
            : ((c.chat_status === 'pending')
                ? `<span style="color: #d97706; font-weight: 600; font-size: 0.68rem;">🟡 Pending</span>`
                : `<span style="color: #10b981; font-weight: 600; font-size: 0.68rem;">🟢 Open</span>`);

        html += `
        <div class="conv-item ${isActive}" onclick="selectConversation('${phone}')">
            <div class="conv-avatar">${initial}</div>
            <div class="conv-details">
                <div class="conv-name">${escapeHtml(c.customer_name)}</div>
                <div class="conv-preview">${escapeHtml(c.message_body || 'Media / Event')}</div>
            </div>
            <div class="conv-meta">
                <div>${c.formatted_time}</div>
                <div style="margin-top: 2px;">${statusBadge}</div>
            </div>
        </div>
        `;
    });

    container.innerHTML = html;
}

function selectConversation(phone) {
    currentActivePhone = phone;
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
    fetch(`api/inbox-api.php?action=messages&phone=${encodeURIComponent(phone)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderMessages(data.messages);
            renderProfile(data.profile);
            if (scrollBottom) {
                scrollChatToBottom();
                setTimeout(scrollChatToBottom, 100);
                setTimeout(scrollChatToBottom, 300);
            }
        }
    });
}

function renderMessages(messages) {
    const container = document.getElementById('chatMessagesContainer');
    if (!messages || messages.length === 0) {
        container.innerHTML = '<div style="margin: auto; color: #888; font-size: 0.85rem;">No messages yet. Start conversation below.</div>';
        return;
    }

    let html = '';
    messages.forEach(m => {
        const isSystem = (m.message_type === 'system' || m.status === 'closed');
        const isOutbound = (m.direction === 'OUTBOUND');

        if (isSystem) {
            html += `
            <div class="msg-bubble-wrap system">
                <div class="msg-bubble system">
                    ${escapeHtml(m.message_body)}
                </div>
            </div>
            `;
        } else {
            const bubbleClass = isOutbound ? 'outbound' : 'inbound';
            const wrapClass = isOutbound ? 'outbound' : 'inbound';
            const senderTag = isOutbound 
                ? `<div style="font-size: 0.7rem; font-weight: 700; color: #059669; margin-bottom: 2px;">Support Team / Bot</div>`
                : `<div style="font-size: 0.7rem; font-weight: 700; color: #2563eb; margin-bottom: 2px;">👤 Customer</div>`;

            let bodyText = escapeHtml(m.message_body || '');
            if (m.message_type === 'flow_submission' || (m.message_body && m.message_body.includes('Ticket'))) {
                bodyText += `<div class="flow-card-badge">📋 <strong>Form Submission Received</strong></div>`;
            }

            html += `
            <div class="msg-bubble-wrap ${wrapClass}">
                <div class="msg-bubble ${bubbleClass}">
                    ${senderTag}
                    <div>${bodyText}</div>
                    <span class="msg-time">${m.formatted_time}</span>
                </div>
            </div>
            `;
        }
    });

    container.innerHTML = html;
}

function renderProfile(p) {
    if (!p) return;
    document.getElementById('activeName').innerText = p.name;
    document.getElementById('activePhone').innerHTML = `<i data-lucide="circle" style="width: 8px; height: 8px; fill: #10b981; color: #10b981;"></i> +${p.phone}`;
    document.getElementById('activeAvatar').innerText = p.name.charAt(0).toUpperCase();

    document.getElementById('rightName').innerText = p.name;
    document.getElementById('rightCompany').innerText = p.company;
    document.getElementById('rightPhone').innerText = '+' + p.phone;
    document.getElementById('rightEmail').innerText = p.email || 'N/A';
    document.getElementById('rightAvatar').innerText = p.name.charAt(0).toUpperCase();

    // 24h Window Timer Formatting
    const timerElem = document.getElementById('rightWindowTimer');
    const badgeElem = document.getElementById('windowTimerBadge');

    if (p.window_status === 'Active') {
        timerElem.innerHTML = `<span style="color: #10b981;">⚡ ${p.window_time_text}</span>`;
        badgeElem.innerHTML = `<span class="badge" style="background: rgba(16,185,129,0.15); color: #10b981; font-weight: bold; font-size: 0.75rem;">⏱️ ${p.window_time_text}</span>`;
    } else {
        timerElem.innerHTML = `<span style="color: #ef4444;">🔒 ${p.window_time_text}</span>`;
        badgeElem.innerHTML = `<span class="badge" style="background: rgba(239,68,68,0.15); color: #ef4444; font-weight: bold; font-size: 0.75rem;">🔒 24h Window Expired</span>`;
    }

    if (p.lead_id) {
        document.getElementById('rightLeadLink').innerHTML = `<a href="index.php?page=lead_details&id=${p.lead_id}">${p.lead_id}</a>`;
    } else {
        document.getElementById('rightLeadLink').innerText = 'N/A';
    }

    // Render Ticket Header Badge & Right Profile Ticket Card
    const ticketHeaderBadge = document.getElementById('ticketHeaderBadge');
    if (ticketHeaderBadge) {
        if (p.ticket) {
            ticketHeaderBadge.innerHTML = `
                <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill" style="background: rgba(59,130,246,0.15); color: #2563eb; border: 1px solid rgba(59,130,246,0.3); font-weight: 700; text-decoration: none;">
                    🎫 Ticket ${escapeHtml(p.ticket.id)} (${escapeHtml(p.ticket.status)}) → View Ticket
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
                <div style="background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.25); padding: 0.75rem; border-radius: 8px; margin-top: 0.5rem;">
                    <div style="font-size: 0.72rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; margin-bottom: 2px;">Active Ticket</div>
                    <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">${escapeHtml(p.ticket.id)} <span class="badge text-xs" style="background: #e0f2fe; color: #0369a1;">${escapeHtml(p.ticket.status)}</span></div>
                    <div style="font-size: 0.78rem; color: #4b5563; margin-top: 2px;">${escapeHtml(p.ticket.subject)}</div>
                    <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill text-xs w-full block text-center mt-2" style="background: #2563eb; color: white; text-decoration: none; font-weight: 600;">
                        🎫 Open Ticket in Support Ops
                    </a>
                </div>
            `;
        } else {
            rightTicketCard.innerHTML = `
                <div style="font-size: 0.78rem; color: #6b7280; font-style: italic;">No open ticket found for this client.</div>
            `;
        }
    }

    const rightStatusElem = document.getElementById('rightStatus');
    if (rightStatusElem && p.chat_status) {
        if (p.chat_status === 'closed') {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 8px; background: rgba(239,68,68,0.1); color: #ef4444;';
            rightStatusElem.innerText = '🔒 Closed';
        } else if (p.chat_status === 'pending') {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 8px; background: rgba(245,158,11,0.1); color: #d97706;';
            rightStatusElem.innerText = '🟡 Pending';
        } else {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 8px; background: rgba(16,185,129,0.1); color: #10b981;';
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
                    <div style="padding: 4px 0; border-bottom: 1px dashed var(--border-color, #e5e7eb);">
                        <div>${actBadge} by <strong>${escapeHtml(a.actor_name)}</strong></div>
                        <div style="color: #888; font-size: 0.68rem;">${a.formatted_time}</div>
                    </div>
                `;
            });
            rightAuditElem.innerHTML = auditHtml;
        } else {
            rightAuditElem.innerHTML = '<div style="color: #888; font-style: italic;">No audit events recorded yet.</div>';
        }
    }

    // Lock/Unlock Reply Bar for Closed Chats
    const replyInput = document.getElementById('replyMessageInput');
    const chatInputBar = document.querySelector('.chat-input-bar');
    const closedBanner = document.getElementById('closedChatBanner');

    if (p.chat_status === 'closed') {
        if (replyInput) replyInput.disabled = true;
        if (!closedBanner && chatInputBar) {
            const b = document.createElement('div');
            b.id = 'closedChatBanner';
            b.style.cssText = 'background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 6px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; text-align: center; margin-bottom: 6px;';
            b.innerHTML = '🔒 Conversation is Closed. Click <button type="button" onclick="updateChatStatus(\'open\')" style="background: #10b981; color: white; border: none; padding: 2px 8px; border-radius: 12px; font-size: 0.72rem; font-weight: bold; cursor: pointer;">🟢 Re-open Chat</button> to send messages.';
            chatInputBar.insertBefore(b, chatInputBar.firstChild);
        }
    } else {
        if (replyInput) replyInput.disabled = false;
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
            fetchMessages(currentActivePhone, true);
            fetchConversations(false);
        } else {
            alert('Error: ' + (data.message || 'Failed updating status'));
        }
    });
}

function insertQuickReply(selectElem) {
    const text = selectElem.value;
    if (text) {
        const input = document.getElementById('replyMessageInput');
        input.value = text;
        selectElem.value = '';
        input.focus();
    }
}

function closeActiveChat() {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }

    if (confirm('Are you sure you want to close & resolve this conversation?')) {
        const formData = new FormData();
        formData.append('action', 'close_chat');
        formData.append('phone', currentActivePhone);

        fetch('api/inbox-api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                fetchMessages(currentActivePhone, true);
                fetchConversations(false);
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function handleKeyDown(e) {
    if (e.key === 'Enter' && e.ctrlKey) {
        submitReplyMessage();
    }
}

function submitReplyMessage() {
    const input = document.getElementById('replyMessageInput');
    const msg = input.value.trim();
    if (!msg || !currentActivePhone) {
        alert('Please select a conversation and type a message.');
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
