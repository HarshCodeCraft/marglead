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
    background: var(--bg-card, #ffffff);
}
.chat-header {
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
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <div id="windowTimerBadge"></div>
                <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="fetchMessages(currentActivePhone)">
                    <i data-lucide="rotate-cw" style="width: 13px; height: 13px;"></i>
                    Refresh
                </button>
                <button type="button" class="btn-pill text-xs" style="background: #ef4444; color: white;" onclick="closeActiveChat()">
                    <i data-lucide="check-circle" style="width: 13px; height: 13px;"></i>
                    Close Chat
                </button>
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
            <div style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <label class="text-xs text-muted font-semibold mb-2 block">QUICK CRM ACTIONS</label>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mb-2" onclick="alert('Creating Lead for this contact...')">
                    <i data-lucide="user-plus" style="width: 13px; height: 13px;"></i>
                    Add as CRM Lead
                </button>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs" onclick="alert('Opening Ticket Creation...')">
                    <i data-lucide="life-buoy" style="width: 13px; height: 13px;"></i>
                    Create Support Ticket
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentActivePhone = '';
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

function fetchConversations(showLoading = true) {
    const search = document.getElementById('inboxSearchInput').value;
    fetch(`api/inbox-api.php?action=conversations&search=${encodeURIComponent(search)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderConversations(data.conversations);
        }
    })
    .catch(err => console.error(err));
}

function renderConversations(list) {
    const container = document.getElementById('conversationsContainer');
    if (!list || list.length === 0) {
        container.innerHTML = '<div style="padding: 2rem; text-align: center; color: #888; font-size: 0.85rem;">No active conversations found.</div>';
        return;
    }

    let html = '';
    list.forEach(c => {
        const phone = c.recipient_or_sender;
        const isActive = (phone === currentActivePhone) ? 'active' : '';
        const initial = (c.customer_name || 'C').charAt(0).toUpperCase();

        const windowBadge = (c.window_status === 'Active') 
            ? `<span style="color: #10b981; font-weight: 600; font-size: 0.7rem;">⚡ 24h Free</span>`
            : `<span style="color: #ef4444; font-size: 0.7rem;">🔒 Closed/Template</span>`;

        html += `
        <div class="conv-item ${isActive}" onclick="selectConversation('${phone}')">
            <div class="conv-avatar">${initial}</div>
            <div class="conv-details">
                <div class="conv-name">${escapeHtml(c.customer_name)}</div>
                <div class="conv-preview">${escapeHtml(c.message_body || 'Media / Event')}</div>
            </div>
            <div class="conv-meta">
                <div>${c.formatted_time}</div>
                <div style="margin-top: 2px;">${windowBadge}</div>
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

function fetchMessages(phone, scrollBottom = true) {
    fetch(`api/inbox-api.php?action=messages&phone=${encodeURIComponent(phone)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            renderMessages(data.messages);
            renderProfile(data.profile);
            if (scrollBottom) {
                setTimeout(() => {
                    const body = document.getElementById('chatMessagesContainer');
                    body.scrollTop = body.scrollHeight;
                }, 50);
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

            let bodyText = escapeHtml(m.message_body || '');
            if (m.message_type === 'flow_submission' || m.message_body.includes('Ticket')) {
                bodyText += `<div class="flow-card-badge">📋 <strong>Form Submission Received</strong></div>`;
            }

            html += `
            <div class="msg-bubble-wrap ${wrapClass}">
                <div class="msg-bubble ${bubbleClass}">
                    ${bodyText}
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

    if (window.lucide) {
        lucide.createIcons();
    }
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
