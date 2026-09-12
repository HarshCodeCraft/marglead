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
    padding: 1.25rem 1.5rem;
    overflow-y: auto;
    background-color: #f0f2f5;
    background-image: 
        radial-gradient(circle at 20px 20px, rgba(15, 23, 42, 0.035) 2%, transparent 0%);
    background-size: 40px 40px;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    scrollbar-width: thin;
}

[data-theme="dark"] .chat-body {
    background-color: #0b141a;
    background-image: 
        radial-gradient(circle at 20px 20px, rgba(255, 255, 255, 0.03) 2%, transparent 0%);
}

/* WhatsApp Message Bubbles - Royal & Crisp Proportions */
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
    margin: 0.4rem 0;
}

.msg-bubble {
    max-width: min(72%, 560px);
    padding: 0.65rem 0.95rem;
    font-size: 0.84rem;
    line-height: 1.5;
    position: relative;
    word-break: break-word;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}

.msg-bubble.inbound {
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #e2e8f0;
    border-radius: 14px 14px 14px 4px;
}

[data-theme="dark"] .msg-bubble.inbound {
    background: #1e293b;
    color: #f1f5f9;
    border-color: #334155;
}

/* WhatsApp Royal Soft Emerald Outbound Bubble Color */
.msg-bubble.outbound {
    background: #e7f8e8;
    color: #0f172a;
    border: 1px solid #bbf7d0;
    border-radius: 14px 14px 4px 14px;
    box-shadow: 0 1px 3px rgba(16, 185, 129, 0.08);
}

[data-theme="dark"] .msg-bubble.outbound {
    background: #064e3b;
    color: #ecfdf5;
    border-color: #047857;
}

.msg-bubble.system {
    background: rgba(245, 158, 11, 0.08);
    color: #b45309;
    border: 1px solid rgba(245, 158, 11, 0.25);
    font-size: 0.74rem;
    font-weight: 600;
    text-align: center;
    border-radius: 20px;
    padding: 4px 14px;
    box-shadow: none;
}

.msg-sender-tag {
    font-size: 0.7rem;
    font-weight: 700;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.msg-bubble.inbound .msg-sender-tag {
    color: #2563eb;
}

.msg-bubble.outbound .msg-sender-tag {
    color: #047857;
}

[data-theme="dark"] .msg-bubble.outbound .msg-sender-tag {
    color: #34d399;
}

.msg-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 4px;
    margin-top: 4px;
}

.msg-time {
    font-size: 0.66rem;
    color: #64748b;
    font-weight: 500;
}

[data-theme="dark"] .msg-time {
    color: #94a3b8;
}

.read-ticks {
    font-size: 0.74rem;
    color: #0ea5e9;
    font-weight: bold;
}

.msg-text-content {
    font-size: 0.84rem;
    line-height: 1.55;
    color: inherit;
}

.flow-card-badge {
    background: rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(0, 0, 0, 0.08);
    color: #111b21;
    padding: 6px 10px;
    border-radius: 8px;
    font-size: 0.78rem;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}

[data-theme="dark"] .flow-card-badge {
    background: rgba(255, 255, 255, 0.06);
    border-color: rgba(255, 255, 255, 0.12);
    color: #e9edef;
}

/* Chat Input Composer */
.chat-input-bar {
    flex-shrink: 0;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-card, #ffffff);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    z-index: 10;
}

.quick-actions-toolbar {
    display: flex;
    gap: 0.45rem;
    align-items: center;
    flex-wrap: wrap;
}

.quick-reply-select {
    padding: 0.38rem 0.65rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.75rem;
    background: var(--bg-app, #f8fafc);
    color: var(--text-main, #0f172a);
    cursor: pointer;
    max-width: 230px;
    outline: none;
    font-weight: 500;
}

.quick-reply-select:focus {
    border-color: var(--primary, #2563eb);
}

.composer-row {
    display: flex;
    gap: 0.6rem;
    align-items: flex-end;
}

.chat-textarea {
    flex: 1;
    border-radius: 12px;
    border: 1px solid var(--border-color, #cbd5e1);
    padding: 0.6rem 0.9rem;
    font-size: 0.85rem;
    resize: none;
    height: 44px;
    min-height: 44px;
    max-height: 110px;
    font-family: inherit;
    box-sizing: border-box;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    transition: all 0.15s ease;
    line-height: 1.45;
}

.chat-textarea:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.15);
}

.btn-send-msg {
    height: 44px;
    padding: 0 1.25rem;
    border-radius: 12px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
}

.btn-send-msg:hover {
    background: linear-gradient(135deg, #047857, #065f46);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(5, 150, 105, 0.35);
}

/* Media Card & Attachment Styles */
.chat-media-card {
    margin-top: 4px;
    border-radius: 10px;
    overflow: hidden;
}

.chat-media-img-wrap {
    position: relative;
    display: block;
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    text-align: center;
}

.chat-media-img {
    max-width: 100%;
    max-height: 260px;
    width: auto;
    border-radius: 8px;
    display: block;
    margin: 0 auto;
    object-fit: contain;
    padding: 4px;
    transition: transform 0.2s ease, filter 0.2s ease;
}

.chat-media-zoom-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(15, 23, 42, 0.65);
    color: #ffffff;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 4px 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.2s ease;
    backdrop-filter: blur(2px);
}

.chat-media-img-wrap:hover .chat-media-zoom-overlay {
    opacity: 1;
}

.chat-media-img-wrap:hover .chat-media-img {
    transform: scale(1.02);
}

.chat-media-caption {
    font-size: 0.82rem;
    margin-top: 8px;
    line-height: 1.5;
    color: inherit;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
    padding-top: 6px;
}

.doc-card {
    background: rgba(37, 99, 235, 0.08);
    border: 1px solid rgba(37, 99, 235, 0.22);
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 8px;
    max-width: 310px;
}

.doc-badge-icon {
    width: 36px;
    height: 36px;
    background: #ef4444;
    color: white;
    font-weight: 800;
    font-size: 0.72rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.25);
}

.doc-details {
    flex: 1;
    overflow: hidden;
}

.doc-title {
    font-weight: 700;
    font-size: 0.78rem;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.doc-sub {
    font-size: 0.68rem;
    color: var(--text-muted);
    margin-top: 1px;
}

.doc-dl-btn {
    background: #2563eb;
    color: white !important;
    padding: 4px 9px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    transition: background 0.15s ease;
}

.doc-dl-btn:hover {
    background: #1d4ed8;
}

.doc-view-btn {
    background: #0284c7;
    color: white !important;
    padding: 4px 9px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    border: none;
    cursor: pointer;
    transition: background 0.15s ease;
}

.doc-view-btn:hover {
    background: #0369a1;
}

.chat-media-placeholder {
    padding: 8px 12px;
    background: rgba(0,0,0,0.04);
    border-radius: 6px;
    font-size: 0.78rem;
    border-left: 3px solid var(--primary);
}

/* PDF Viewer Modal */
.pdf-viewer-modal {
    display: none;
    position: fixed;
    z-index: 99999;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}

.pdf-viewer-card {
    background: var(--bg-card, #ffffff);
    width: 90vw;
    height: 90vh;
    max-width: 1050px;
    max-height: 850px;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0,0,0,0.35);
}

.pdf-viewer-header {
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-app, #f8fafc);
}

.pdf-viewer-body {
    flex: 1;
    background: #525659;
}

.pdf-viewer-close {
    font-size: 24px;
    font-weight: bold;
    color: var(--text-muted);
    cursor: pointer;
    margin-left: 8px;
    line-height: 1;
}

/* Lightbox Modal */
.media-lightbox-modal {
    display: none;
    position: fixed;
    z-index: 99999;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}

.media-lightbox-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.media-lightbox-content img {
    max-width: 85vw;
    max-height: 78vh;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    object-fit: contain;
}

.media-lightbox-close {
    position: absolute;
    top: -36px;
    right: -10px;
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.media-lightbox-caption {
    color: white;
    margin-top: 10px;
    font-size: 0.85rem;
    text-align: center;
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

/* Bank Details Modal Styling */
.bank-modal-card {
    background: var(--bg-card, #ffffff);
    border-radius: 14px;
    width: 95%;
    max-width: 580px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
    animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid var(--border-color, #e2e8f0);
}

.bank-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-app, #f8fafc);
}

.bank-modal-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.bank-modal-body {
    padding: 1.25rem;
    overflow-y: auto;
    flex: 1;
}

.bank-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 0.85rem 1.25rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-app, #f8fafc);
}

.bank-accounts-grid {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.bank-account-card {
    border: 2px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    padding: 0.75rem 0.9rem;
    cursor: pointer;
    transition: all 0.15s ease;
    background: var(--bg-card, #ffffff);
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.bank-account-card:hover {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.02);
}

.bank-account-card.selected {
    border-color: #10b981;
    background: rgba(16, 185, 129, 0.06);
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
}

.bank-card-radio {
    accent-color: #10b981;
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.bank-card-qr-thumb {
    width: 52px;
    height: 52px;
    border-radius: 6px;
    border: 1px solid var(--border-color, #cbd5e1);
    background: #ffffff;
    object-fit: contain;
    padding: 2px;
    flex-shrink: 0;
}

.bank-card-qr-placeholder {
    width: 52px;
    height: 52px;
    border-radius: 6px;
    background: rgba(0,0,0,0.04);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 0.65rem;
    flex-shrink: 0;
    text-align: center;
    border: 1px dashed var(--border-color, #cbd5e1);
}

.bank-note-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #cbd5e1);
    font-size: 0.82rem;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    box-sizing: border-box;
}

.bank-note-input:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.15);
}

.bank-message-preview {
    background: #eef2f6;
    border-left: 3px solid #10b981;
    padding: 0.65rem 0.85rem;
    border-radius: 6px;
    font-family: monospace;
    font-size: 0.74rem;
    color: #1e293b;
    white-space: pre-wrap;
    line-height: 1.4;
    max-height: 140px;
    overflow-y: auto;
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
                    <option value="">Quick Canned Reply...</option>
                    <option value="Thank you for contacting Marg Soft Solution Support! How can we assist your business today?">Welcome &amp; Greet</option>
                    <option value="__BANK_DETAILS__">Share Bank Details &amp; QR</option>
                    <option value="Kindly provide your Marg License Number or Customer ID to check your AMC status.">Request License No.</option>
                    <option value="Our sales executive will get in touch with you shortly on 7523830026.">Sales Callback Info</option>
                    <option value="Thank you for your payment confirmation! We have verified your transaction and updated your account records.">Payment Verified &amp; Confirmed</option>
                    <option value="Here is your Marg ERP AMC renewal quotation &amp; payment link. Please let us know once transferred.">Send AMC Renewal Offer</option>
                    <option value="Your issue has been resolved successfully. Have a great day!">Resolve &amp; Close Ticket</option>
                </select>

                <button type="button" id="btnShareBank" class="btn-pill btn-pill-outline text-xs" style="color: #059669; border-color: rgba(5,150,105,0.35); background: rgba(16,185,129,0.05); font-weight: 600;" onclick="openBankDetailsModal()" title="Share Company Bank Accounts & Payment QR Code with Customer">
                    <i data-lucide="landmark" style="width: 12px; height: 12px; color: #10b981;"></i>
                    Share Bank & QR
                </button>
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
                <input type="file" id="chatFileInput" accept="image/*,.pdf,.doc,.docx" style="display:none;" onchange="handleFileSelected(this)">
                <button type="button" class="btn-pill btn-pill-outline text-xs" style="height: 42px; width: 42px; border-radius: 10px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="triggerFilePicker()" title="Attach & Send Image or PDF Document">
                    <i data-lucide="paperclip" style="width: 16px; height: 16px; color: #2563eb;"></i>
                </button>
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
            <span class="badge" style="margin-top: 6px; background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 3px;" id="rightStatus">
                <i data-lucide="check-circle-2" style="width: 11px; height: 11px;"></i> Active
            </span>
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
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mb-1" onclick="openBankDetailsModal()" style="padding: 3px 8px; color: #059669; border-color: rgba(5,150,105,0.3);">
                    <i data-lucide="landmark" style="width: 12px; height: 12px;"></i>
                    Share Bank & QR
                </button>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mb-1" onclick="window.location.href='index.php?page=leads'" style="padding: 3px 8px;">
                    <i data-lucide="user-plus" style="width: 12px; height: 12px;"></i>
                    View CRM Leads
                </button>
                <a href="index.php?page=support" class="btn-pill btn-pill-outline w-full text-xs block text-center" style="text-decoration: none; padding: 3px 8px;">
                    <i data-lucide="life-buoy" style="width: 12px; height: 12px;"></i>
                    Support Desk
                </a>
                <button type="button" class="btn-pill btn-pill-outline w-full text-xs mt-1 block text-center" onclick="runMediaCleanup()" style="padding: 3px 8px; color: #ef4444; border-color: rgba(239,68,68,0.3);" title="Auto-delete MP4 video & MP3 audio files older than 48 hours">
                    <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
                    Clean 48h Audio/Video
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bank Details & Payment QR Sharing Modal -->
<div id="bankDetailsModal" class="media-lightbox-modal" onclick="closeBankModal(event)">
    <div class="bank-modal-card" onclick="event.stopPropagation()">
        <div class="bank-modal-header">
            <div class="flex align-center gap-2">
                <div class="bank-modal-icon">
                    <i data-lucide="landmark" style="width: 20px; height: 20px; color: #10b981;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700; color: var(--text-main);">Share Bank & Payment QR Details</h3>
                    <p style="margin: 0; font-size: 0.74rem; color: var(--text-muted);">Send official corporate bank accounts & payment QR code directly to client on WhatsApp.</p>
                </div>
            </div>
            <span class="media-lightbox-close" style="position: static; font-size: 1.5rem; color: var(--text-muted); cursor: pointer;" onclick="closeBankModal()">&times;</span>
        </div>

        <div class="bank-modal-body">
            <div id="bankAccountsLoading" style="text-align: center; padding: 2rem 0; color: var(--text-muted); font-size: 0.85rem;">
                <i data-lucide="loader-2" class="refresh-spin" style="width: 24px; height: 24px; margin: 0 auto 0.5rem auto; color: #10b981;"></i>
                <div>Loading registered bank accounts...</div>
            </div>

            <div id="bankAccountsContainer" style="display: none;">
                <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.4rem; display: block;">Select Payment Account</label>
                <div id="bankAccountsList" class="bank-accounts-grid"></div>

                <div style="margin-top: 1rem;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.3rem; display: block;">Optional Note / Invoice Purpose</label>
                    <input type="text" id="bankCustomNote" class="bank-note-input" placeholder="e.g. Marg ERP License AMC Renewal (Rs 3,540) or Custom Requirement" oninput="updateBankPreview()">
                </div>

                <div style="margin-top: 1rem;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.3rem; display: block;">WhatsApp Message Preview</label>
                    <div id="bankMessagePreview" class="bank-message-preview"></div>
                </div>
            </div>
        </div>

        <div class="bank-modal-footer">
            <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="closeBankModal()" style="padding: 6px 14px; font-weight: 600;">Cancel</button>
            <button type="button" id="btnSubmitBankShare" class="btn-pill text-xs" style="background: #10b981; color: white; border: none; padding: 7px 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;" onclick="submitSendBankDetails()">
                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                <span id="btnSubmitBankText">Send to Client</span>
            </button>
        </div>
    </div>
</div>

<!-- Media Lightbox Modal -->
<div id="mediaLightboxModal" class="media-lightbox-modal" onclick="closeMediaModal(event)">
    <div class="media-lightbox-content" onclick="event.stopPropagation()">
        <span class="media-lightbox-close" onclick="closeMediaModal()">&times;</span>
        <img id="lightboxImg" src="" alt="Full Attachment Preview">
        <div id="lightboxCaption" class="media-lightbox-caption"></div>
        <a id="lightboxDownloadBtn" href="" target="_blank" download class="btn-pill" style="background: #2563eb; color: white; margin-top: 12px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; padding: 6px 14px; font-weight: 600;">
            <i data-lucide="download" style="width: 14px; height: 14px;"></i> Download Full Image
        </a>
    </div>
</div>

<!-- PDF Viewer Modal -->
<div id="pdfViewerModal" class="pdf-viewer-modal" onclick="closePdfModal(event)">
    <div class="pdf-viewer-card" onclick="event.stopPropagation()">
        <div class="pdf-viewer-header">
            <div class="flex align-center gap-2">
                <span class="badge" style="background: #ef4444; color: white; font-weight: 800; padding: 2px 6px; font-size: 0.68rem;">PDF</span>
                <span id="pdfModalTitle" style="font-weight: 700; font-size: 0.92rem; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 380px;">PDF Document</span>
            </div>
            <div class="flex align-center gap-2">
                <a id="pdfModalNewTabBtn" href="" target="_blank" class="btn-pill btn-pill-outline text-xs" style="text-decoration: none; padding: 4px 10px; font-weight: 600;">
                    <i data-lucide="external-link" style="width: 13px; height: 13px; display: inline-block; vertical-align: middle;"></i> New Tab
                </a>
                <a id="pdfModalDownloadBtn" href="" target="_blank" download class="btn-pill btn-pill-primary text-xs" style="text-decoration: none; padding: 4px 12px; font-weight: 700; background: #2563eb; color: white;">
                    <i data-lucide="download" style="width: 13px; height: 13px; display: inline-block; vertical-align: middle;"></i> Download PDF
                </a>
                <span class="pdf-viewer-close" onclick="closePdfModal()">&times;</span>
            </div>
        </div>
        <div class="pdf-viewer-body">
            <iframe id="pdfFrame" src="" width="100%" height="100%" style="border: none;"></iframe>
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
    if (clean.toLowerCase() === 'image') return 'Photo Attachment';
    if (clean.toLowerCase() === 'document' || clean.toLowerCase() === 'pdf') return 'PDF Document';
    if (clean.toLowerCase() === 'video') return 'Video Clip';
    if (clean.toLowerCase() === 'audio' || clean.toLowerCase() === 'voice') return 'Voice Note';
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

function getAvatarGradient(name) {
    const gradients = [
        'linear-gradient(135deg, #059669, #047857)', // Emerald
        'linear-gradient(135deg, #2563eb, #1d4ed8)', // Blue
        'linear-gradient(135deg, #7c3aed, #5b21b6)', // Purple
        'linear-gradient(135deg, #d97706, #b45309)', // Amber
        'linear-gradient(135deg, #db2777, #9d174d)', // Pink
        'linear-gradient(135deg, #0891b2, #0e7490)', // Cyan
        'linear-gradient(135deg, #4f46e5, #3730a3)', // Indigo
        'linear-gradient(135deg, #0d9488, #115e59)', // Teal
    ];
    let hash = 0;
    const str = name || 'Customer';
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    const index = Math.abs(hash) % gradients.length;
    return gradients[index];
}

function formatWhatsAppText(raw) {
    if (!raw) return '';
    let text = escapeHtml(raw);

    // 1. Bold: *text* -> <strong>text</strong>
    text = text.replace(/\*([^\*\n\r]+)\*/g, '<strong style="font-weight: 700; color: inherit;">$1</strong>');

    // 2. Italic: _text_ -> <em>text</em>
    text = text.replace(/_([^_\n\r]+)_/g, '<em style="font-style: italic;">$1</em>');

    // 3. Strikethrough: ~text~ -> <del>text</del>
    text = text.replace(/~([^~\n\r]+)~/g, '<del style="opacity: 0.7;">$1</del>');

    // 4. Monospace code: `text` -> <code>text</code>
    text = text.replace(/`([^`\n\r]+)`/g, '<code style="background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 0.88em;">$1</code>');

    // 5. Linebreaks
    text = text.replace(/\n/g, '<br>');

    // 6. Bullet points styling (• or - at start of line)
    text = text.replace(/(<br>|^)•\s+/g, '$1<span style="color: #10b981; font-weight: bold; margin-right: 4px;">•</span> ');

    return text;
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
        const nameStr = c.customer_name || 'Client';
        const initial = nameStr.charAt(0).toUpperCase();
        const avatarBg = getAvatarGradient(nameStr);

        let statusPill = '';
        if (c.chat_status === 'closed') {
            statusPill = `<span class="status-pill-mini" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="lock" style="width: 10px; height: 10px;"></i> Closed</span>`;
        } else if (c.chat_status === 'pending') {
            statusPill = `<span class="status-pill-mini" style="background: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="clock" style="width: 10px; height: 10px;"></i> Pending</span>`;
        } else {
            statusPill = `<span class="status-pill-mini" style="background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="check-circle-2" style="width: 10px; height: 10px;"></i> Open</span>`;
        }

        const previewText = cleanPreviewText(c.message_body);

        html += `
        <div class="conv-item ${isActive}" onclick="selectConversation('${phone}')">
            <div class="conv-avatar-wrap">
                <div class="conv-avatar" style="background: ${avatarBg}; box-shadow: 0 2px 5px rgba(0,0,0,0.15);">${initial}</div>
            </div>
            <div class="conv-details">
                <div class="conv-name-row">
                    <div class="conv-name">${escapeHtml(nameStr)}</div>
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
    if (window.lucide) {
        lucide.createIcons();
    }
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
                ? `<div class="msg-sender-tag"><i data-lucide="bot" style="width: 12px; height: 12px;"></i> Support Team / Bot</div>`
                : `<div class="msg-sender-tag"><i data-lucide="user" style="width: 12px; height: 12px;"></i> Customer</div>`;

            let bodyText = '';
            const msgType = m.media_type || m.message_type || 'text';
            const mediaUrl = m.media_url || '';
            const caption = m.media_caption || '';
            const filename = m.media_filename || '';

            if (msgType === 'image' || (mediaUrl && (mediaUrl.endsWith('.jpg') || mediaUrl.endsWith('.jpeg') || mediaUrl.endsWith('.png') || mediaUrl.endsWith('.webp')))) {
                const imgCaption = caption || (m.message_body && !m.message_body.startsWith('📷') ? m.message_body : '');
                if (mediaUrl) {
                    bodyText = `
                    <div class="chat-media-card image-card">
                        <div class="chat-media-img-wrap" onclick="openMediaModal('${mediaUrl}', '${escapeHtml(imgCaption)}')">
                            <img src="${mediaUrl}" alt="Attachment" class="chat-media-img">
                            <div class="chat-media-zoom-overlay"><i data-lucide="zoom-in" style="width: 14px; height: 14px;"></i> Click to Zoom</div>
                        </div>
                        ${imgCaption ? `<div class="chat-media-caption">${formatWhatsAppText(imgCaption)}</div>` : ''}
                    </div>`;
                } else {
                    bodyText = `<div class="chat-media-placeholder"><i data-lucide="image" style="width: 14px; height: 14px; display: inline-block; vertical-align: -2px; margin-right: 4px; color: #2563eb;"></i> <strong>Photo Attachment</strong><div style="font-size: 0.76rem; opacity: 0.85; margin-top:2px;">${formatWhatsAppText(m.message_body || 'Image')}</div></div>`;
                }
            } else if (msgType === 'document' || msgType === 'pdf' || (mediaUrl && mediaUrl.endsWith('.pdf'))) {
                const displayName = filename || (m.message_body && !m.message_body.startsWith('📄') ? m.message_body : 'WhatsApp Document.pdf');
                if (mediaUrl) {
                    bodyText = `
                    <div class="chat-media-card doc-card">
                        <div class="doc-badge-icon" onclick="openPdfModal('${mediaUrl}', '${escapeHtml(displayName)}')" style="cursor: pointer;" title="Click to View PDF">PDF</div>
                        <div class="doc-details" onclick="openPdfModal('${mediaUrl}', '${escapeHtml(displayName)}')" style="cursor: pointer;" title="Click to View PDF">
                            <div class="doc-title">${escapeHtml(displayName)}</div>
                            ${caption ? `<div class="doc-sub">${formatWhatsAppText(caption)}</div>` : ''}
                        </div>
                        <div class="flex gap-1" style="flex-shrink: 0;">
                            <button type="button" class="doc-view-btn" onclick="openPdfModal('${mediaUrl}', '${escapeHtml(displayName)}')" title="View PDF Document">
                                <i data-lucide="eye" style="width: 12px; height: 12px;"></i> View
                            </button>
                            <a href="${mediaUrl}" target="_blank" download class="doc-dl-btn" title="Download PDF file">
                                <i data-lucide="download" style="width: 12px; height: 12px;"></i> Download
                            </a>
                        </div>
                    </div>`;
                } else {
                    bodyText = `<div class="chat-media-placeholder"><i data-lucide="file-text" style="width: 13px; height: 13px; display: inline-block; vertical-align: -2px; margin-right: 4px; color: #ef4444;"></i> <strong>PDF Document</strong><div style="font-size: 0.72rem; opacity: 0.8; margin-top:2px;">${escapeHtml(displayName)}</div></div>`;
                }
            } else if (msgType === 'audio' || msgType === 'voice') {
                if (mediaUrl) {
                    bodyText = `
                    <div class="chat-media-card audio-card">
                        <div style="font-size: 0.72rem; font-weight: 600; margin-bottom: 3px; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                            <i data-lucide="mic" style="width: 12px; height: 12px; color: #64748b;"></i> Voice Note
                        </div>
                        <audio controls src="${mediaUrl}" style="max-width: 240px; height: 32px;"></audio>
                    </div>`;
                } else {
                    bodyText = `<div class="chat-media-placeholder"><i data-lucide="mic" style="width: 13px; height: 13px; display: inline-block; vertical-align: -2px; margin-right: 4px; color: #64748b;"></i> <strong>Voice Note</strong></div>`;
                }
            } else if (msgType === 'video') {
                if (mediaUrl) {
                    bodyText = `
                    <div class="chat-media-card video-card">
                        <video controls src="${mediaUrl}" style="max-width: 260px; border-radius: 8px; max-height: 200px;"></video>
                        ${caption ? `<div class="chat-media-caption">${formatWhatsAppText(caption)}</div>` : ''}
                    </div>`;
                } else {
                    bodyText = `<div class="chat-media-placeholder"><i data-lucide="video" style="width: 13px; height: 13px; display: inline-block; vertical-align: -2px; margin-right: 4px; color: #64748b;"></i> <strong>Video Clip</strong></div>`;
                }
            } else {
                bodyText = `<div class="msg-text-content">${formatWhatsAppText(m.message_body || '')}</div>`;
                if (m.message_type === 'flow_submission' || (m.message_body && m.message_body.includes('Ticket'))) {
                    bodyText += `<div class="flow-card-badge"><i data-lucide="file-check" style="width: 13px; height: 13px; color: #10b981;"></i> <strong>Support Ticket Form Submitted</strong></div>`;
                }
            }

            const readReceipt = isOutbound ? `<span class="read-ticks" title="Delivered & Read" style="color: #0284c7; display: inline-flex; align-items: center; margin-left: 3px;"><i data-lucide="check-check" style="width: 13px; height: 13px;"></i></span>` : '';

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

    // Clean Single Action Button in Header (Close Chat vs Re-open Chat)
    const actionBtnElem = document.getElementById('chatStatusActionBtn');
    if (actionBtnElem) {
        if (currentChatStatus === 'closed') {
            actionBtnElem.innerHTML = `
                <button type="button" class="btn-pill text-xs" style="background: #10b981; color: #ffffff; border: none; padding: 4px 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" onclick="updateChatStatus('open')" title="Re-open conversation to enable messaging">
                    <i data-lucide="unlock" style="width: 12px; height: 12px;"></i> Re-open Chat
                </button>
            `;
        } else {
            actionBtnElem.innerHTML = `
                <button type="button" class="btn-pill btn-pill-outline text-xs" style="color: #ef4444; border-color: rgba(239,68,68,0.4); padding: 4px 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" onclick="updateChatStatus('closed')" title="Close & resolve conversation">
                    <i data-lucide="lock" style="width: 12px; height: 12px;"></i> Close Chat
                </button>
            `;
        }
    }

    // 24h Window Badge
    const timerElem = document.getElementById('rightWindowTimer');
    const badgeElem = document.getElementById('windowTimerBadge');

    if (timerElem && badgeElem) {
        if (p.window_status === 'Active') {
            timerElem.innerHTML = `<span style="color: #10b981; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="zap" style="width: 12px; height: 12px; fill: #10b981;"></i> 24h Window Active</span>`;
            badgeElem.innerHTML = `<span class="badge" style="background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; padding: 3px 8px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="zap" style="width: 11px; height: 11px; fill: #10b981;"></i> 24h Active</span>`;
        } else {
            timerElem.innerHTML = `<span style="color: #ef4444; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="lock" style="width: 12px; height: 12px;"></i> 24h Window Expired</span>`;
            badgeElem.innerHTML = `<span class="badge" style="background: rgba(239,68,68,0.12); color: #ef4444; font-weight: 700; font-size: 0.68rem; padding: 3px 8px; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="lock" style="width: 11px; height: 11px;"></i> 24h Expired</span>`;
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
                <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill" style="background: rgba(59,130,246,0.12); color: #2563eb; border: 1px solid rgba(59,130,246,0.25); font-weight: 700; text-decoration: none; font-size: 0.68rem; padding: 2px 7px; display: inline-flex; align-items: center; gap: 4px;">
                    <i data-lucide="ticket" style="width: 12px; height: 12px;"></i> Ticket ${escapeHtml(p.ticket.id)} (${escapeHtml(p.ticket.status)})
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
                    <a href="index.php?page=support&open_ticket=${encodeURIComponent(p.ticket.id)}" class="btn-pill text-xs w-full block text-center mt-1" style="background: #2563eb; color: white; text-decoration: none; font-weight: 600; padding: 2px 6px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                        <i data-lucide="external-link" style="width: 12px; height: 12px;"></i> Open Ticket
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
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(239,68,68,0.12); color: #ef4444; font-weight: 700; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 3px;';
            rightStatusElem.innerHTML = '<i data-lucide="lock" style="width: 11px; height: 11px;"></i> Closed';
        } else if (p.chat_status === 'pending') {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(245,158,11,0.12); color: #d97706; font-weight: 700; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 3px;';
            rightStatusElem.innerHTML = '<i data-lucide="clock" style="width: 11px; height: 11px;"></i> Pending';
        } else {
            rightStatusElem.className = 'badge';
            rightStatusElem.style.cssText = 'margin-top: 6px; background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 3px;';
            rightStatusElem.innerHTML = '<i data-lucide="check-circle-2" style="width: 11px; height: 11px;"></i> Open';
        }
    }

    // Render Audit History List
    const rightAuditElem = document.getElementById('rightAuditHistory');
    if (rightAuditElem) {
        if (p.audit_logs && p.audit_logs.length > 0) {
            let auditHtml = '';
            p.audit_logs.forEach(a => {
                const actBadge = (a.action === 'closed') 
                    ? `<span style="color: #ef4444; font-weight: 700; display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="lock" style="width: 11px; height: 11px;"></i> Closed</span>`
                    : ((a.action === 'reopened')
                        ? `<span style="color: #10b981; font-weight: 700; display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="unlock" style="width: 11px; height: 11px;"></i> Reopened</span>`
                        : `<span style="color: #d97706; font-weight: 700; display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="clock" style="width: 11px; height: 11px;"></i> Pending</span>`);
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
            b.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 6px;"><i data-lucide="lock" style="width: 15px; height: 15px;"></i> Conversation is Closed. Re-open chat to send replies.</span> <button type="button" onclick="updateChatStatus(\'open\')" class="btn-pill btn-pill-dark text-xs" style="background: #10b981; color: white; border: none; padding: 6px 14px; font-weight: bold; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"><i data-lucide="unlock" style="width: 12px; height: 12px;"></i> Re-open Chat to Reply</button>';
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
        alert('Conversation is closed. Please re-open the conversation before selecting a canned reply.');
        selectElem.value = '';
        return;
    }
    const val = selectElem.value;
    if (val === '__BANK_DETAILS__') {
        selectElem.value = '';
        openBankDetailsModal();
        return;
    }
    if (val) {
        const input = document.getElementById('replyMessageInput');
        if (input) {
            input.value = val;
            selectElem.value = '';
            input.focus();
        }
    }
}

// -------------------------------------------------------------
// Bank Accounts & Payment QR Sharing Logic
// -------------------------------------------------------------
let registeredBankAccounts = [];
let selectedBankAccountId = 0;

function openBankDetailsModal() {
    if (!currentActivePhone) {
        alert('Please select a customer conversation from the left pane first.');
        return;
    }
    if (currentChatStatus === 'closed') {
        alert('Conversation is closed. Please re-open the conversation before sharing bank details.');
        return;
    }

    const modal = document.getElementById('bankDetailsModal');
    const loading = document.getElementById('bankAccountsLoading');
    const container = document.getElementById('bankAccountsContainer');
    const submitBtn = document.getElementById('btnSubmitBankShare');
    const submitText = document.getElementById('btnSubmitBankText');

    if (modal) {
        modal.style.display = 'flex';
        if (loading) {
            loading.style.display = 'block';
            loading.innerHTML = `
                <i data-lucide="loader-2" class="refresh-spin" style="width: 24px; height: 24px; margin: 0 auto 0.5rem auto; color: #10b981;"></i>
                <div>Loading registered bank accounts...</div>
            `;
        }
        if (container) container.style.display = 'none';
        if (submitText) submitText.innerText = `Send to Client (+${currentActivePhone})`;
        if (submitBtn) submitBtn.disabled = false;
        if (window.lucide) lucide.createIcons();
    }

    // Fetch active accounts from API
    fetch('api/inbox-api.php?action=get_bank_accounts')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.accounts && data.accounts.length > 0) {
                registeredBankAccounts = data.accounts;
                renderBankAccountsList(registeredBankAccounts);
                if (loading) loading.style.display = 'none';
                if (container) container.style.display = 'block';
            } else {
                if (loading) {
                    loading.innerHTML = `
                        <div style="color: #ef4444; font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            <i data-lucide="alert-circle" style="width: 16px; height: 16px;"></i> No Active Bank Accounts Found
                        </div>
                        <p style="font-size: 0.78rem;">Please add your company bank accounts & QR codes in <a href="index.php?page=bank_accounts" target="_blank" style="color: #2563eb; text-decoration: underline;">Bank Accounts Setup</a>.</p>
                    `;
                    if (window.lucide) lucide.createIcons();
                }
            }
        })
        .catch(err => {
            if (loading) {
                loading.innerHTML = `<div style="color: #ef4444;">Failed loading bank accounts: ${err.message}</div>`;
            }
        });
}

function closeBankModal(e) {
    const modal = document.getElementById('bankDetailsModal');
    if (modal) modal.style.display = 'none';
}

function renderBankAccountsList(accounts) {
    const listElem = document.getElementById('bankAccountsList');
    if (!listElem) return;

    let html = '';
    // Select primary account by default or first account
    let primaryAcc = accounts.find(a => parseInt(a.is_primary) === 1) || accounts[0];
    selectedBankAccountId = primaryAcc ? primaryAcc.id : 0;

    accounts.forEach(acc => {
        const isSelected = (acc.id == selectedBankAccountId);
        const qrImg = acc.has_qr && acc.qr_full_url 
            ? `<img src="${acc.qr_full_url}" class="bank-card-qr-thumb" alt="QR Code">`
            : `<div class="bank-card-qr-placeholder"><span>No QR</span></div>`;
        
        const primaryBadge = parseInt(acc.is_primary) === 1
            ? `<span class="badge" style="background: rgba(16,185,129,0.12); color: #10b981; font-weight: 700; font-size: 0.62rem; padding: 1px 5px; display: inline-flex; align-items: center; gap: 3px;"><i data-lucide="star" style="width: 10px; height: 10px; fill: #10b981;"></i> PRIMARY</span>`
            : '';

        html += `
        <div class="bank-account-card ${isSelected ? 'selected' : ''}" id="bankAccCard_${acc.id}" onclick="selectBankAccount(${acc.id})">
            <input type="radio" name="bank_account_choice" value="${acc.id}" class="bank-card-radio" ${isSelected ? 'checked' : ''} onchange="selectBankAccount(${acc.id})">
            ${qrImg}
            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-bottom: 2px;">
                    <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">${escapeHtml(acc.bank_name)}</div>
                    ${primaryBadge}
                </div>
                <div style="font-size: 0.76rem; color: var(--text-muted); font-weight: 500;">
                    A/C: <strong style="color: var(--text-main);">${escapeHtml(acc.account_number)}</strong> | IFSC: <strong style="color: var(--text-main);">${escapeHtml(acc.ifsc_code)}</strong>
                </div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 1px;">
                    Holder: <strong>${escapeHtml(acc.account_name)}</strong> ${acc.upi_id ? `| UPI: <span style="color:#2563eb;">${escapeHtml(acc.upi_id)}</span>` : ''}
                </div>
            </div>
        </div>
        `;
    });

    listElem.innerHTML = html;
    updateBankPreview();
    if (window.lucide) lucide.createIcons();
}

function selectBankAccount(accId) {
    selectedBankAccountId = accId;
    document.querySelectorAll('.bank-account-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById(`bankAccCard_${accId}`);
    if (card) {
        card.classList.add('selected');
        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }
    updateBankPreview();
}

function updateBankPreview() {
    const previewElem = document.getElementById('bankMessagePreview');
    if (!previewElem || !registeredBankAccounts) return;

    const acc = registeredBankAccounts.find(a => a.id == selectedBankAccountId);
    if (!acc) return;

    const noteInput = document.getElementById('bankCustomNote');
    const customNote = noteInput ? noteInput.value.trim() : '';

    let preview = `*Marg Soft Solution - Official Bank & Payment Details*\n\n` +
                  `• *Account Name:* ${acc.account_name}\n` +
                  `• *Bank Name:* ${acc.bank_name}\n` +
                  `• *Account No:* ${acc.account_number}\n` +
                  `• *IFSC Code:* ${acc.ifsc_code}\n` +
                  (acc.branch ? `• *Branch:* ${acc.branch}\n` : '') +
                  `• *Account Type:* ${acc.account_type || 'Current Account'}\n` +
                  (acc.upi_id ? `• *UPI ID:* ${acc.upi_id}\n` : '') +
                  `\n` +
                  (customNote ? `*Note:* ${customNote}\n\n` : '') +
                  (acc.has_qr ? `[Payment QR Code Image Attached]\n` : '') +
                  `*Please scan the QR code above or transfer via UPI / IMPS / NEFT.*\n` +
                  `Kindly share payment confirmation screenshot once done. Thank you!`;

    previewElem.innerText = preview;
}

function submitSendBankDetails() {
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        return;
    }
    if (currentChatStatus === 'closed') {
        alert('Conversation is closed. Please re-open the conversation before sending messages.');
        return;
    }
    if (!selectedBankAccountId) {
        alert('Please select a bank account to share.');
        return;
    }

    const noteInput = document.getElementById('bankCustomNote');
    const customNote = noteInput ? noteInput.value.trim() : '';
    const btn = document.getElementById('btnSubmitBankShare');
    const btnText = document.getElementById('btnSubmitBankText');

    if (btn) {
        btn.disabled = true;
        if (btnText) btnText.innerHTML = `Sending Bank & QR...`;
    }

    const formData = new FormData();
    formData.append('action', 'send_bank_details');
    formData.append('phone', currentActivePhone);
    formData.append('account_id', selectedBankAccountId);
    formData.append('custom_note', customNote);

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            if (btnText) btnText.innerText = `Send to Client (+${currentActivePhone})`;
        }
        if (data.success) {
            closeBankModal();
            if (noteInput) noteInput.value = '';
            lastMessagesHash = '';
            fetchMessages(currentActivePhone, true);
        } else {
            alert('Failed sending bank details: ' + (data.message || 'Error occurred'));
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            if (btnText) btnText.innerText = `Send to Client (+${currentActivePhone})`;
        }
        alert('Network error: ' + err.message);
    });
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
        alert('Conversation is closed. Please re-open the conversation before sending messages.');
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
        alert('Conversation is closed. Please re-open the conversation before sending buttons.');
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
        alert('Conversation is closed. Please re-open the conversation before sending support form.');
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

function openMediaModal(url, caption = '') {
    const modal = document.getElementById('mediaLightboxModal');
    const img = document.getElementById('lightboxImg');
    const cap = document.getElementById('lightboxCaption');
    const btn = document.getElementById('lightboxDownloadBtn');
    if (modal && img) {
        img.src = url;
        if (cap) cap.innerText = caption;
        if (btn) btn.href = url;
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeMediaModal(e) {
    const modal = document.getElementById('mediaLightboxModal');
    if (modal) modal.style.display = 'none';
}

function triggerFilePicker() {
    if (currentChatStatus === 'closed') {
        alert('Conversation is closed. Please re-open the conversation before sending files.');
        return;
    }
    const picker = document.getElementById('chatFileInput');
    if (picker) picker.click();
}

function handleFileSelected(input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    if (!currentActivePhone) {
        alert('Please select a conversation first.');
        input.value = '';
        return;
    }

    const caption = prompt(`Send "${file.name}" via WhatsApp?\nAdd optional caption (or press OK to send):`, '') ?? null;
    if (caption === null) {
        input.value = '';
        return; // Cancelled
    }

    const formData = new FormData();
    formData.append('action', 'send_media');
    formData.append('phone', currentActivePhone);
    formData.append('file', file);
    if (caption) {
        formData.append('caption', caption);
    }

    const btnSend = document.getElementById('btnSendMsg');
    if (btnSend) {
        btnSend.disabled = true;
        btnSend.innerHTML = `<i data-lucide="loader-2" class="refresh-spin" style="width:14px; height:14px;"></i> Sending...`;
        if (window.lucide) lucide.createIcons();
    }

    fetch('api/inbox-api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        input.value = '';
        if (btnSend) {
            btnSend.disabled = false;
            btnSend.innerHTML = `<i data-lucide="send" style="width: 14px; height: 14px;"></i> <span>Send</span>`;
            if (window.lucide) lucide.createIcons();
        }
        if (data.success) {
            lastMessagesHash = '';
            fetchMessages(currentActivePhone, true);
        } else {
            alert('Error sending file: ' + (data.message || 'Failed'));
        }
    })
    .catch(err => {
        input.value = '';
        if (btnSend) {
            btnSend.disabled = false;
            btnSend.innerHTML = `<i data-lucide="send" style="width: 14px; height: 14px;"></i> <span>Send</span>`;
            if (window.lucide) lucide.createIcons();
        }
        alert('Upload failed: ' + err.message);
    });
}

function openPdfModal(url, title = 'PDF Document') {
    const modal = document.getElementById('pdfViewerModal');
    const titleElem = document.getElementById('pdfModalTitle');
    const frame = document.getElementById('pdfFrame');
    const newTabBtn = document.getElementById('pdfModalNewTabBtn');
    const dlBtn = document.getElementById('pdfModalDownloadBtn');

    if (modal && frame) {
        if (titleElem) titleElem.innerText = title;
        frame.src = url;
        if (newTabBtn) newTabBtn.href = url;
        if (dlBtn) dlBtn.href = url;

        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closePdfModal(e) {
    const modal = document.getElementById('pdfViewerModal');
    const frame = document.getElementById('pdfFrame');
    if (modal) {
        modal.style.display = 'none';
        if (frame) frame.src = '';
    }
}

function runMediaCleanup() {
    if (!confirm('Auto-delete all MP4 (video) and MP3/OGG (voice note) media files older than 48 hours?\n\nNote: Images and PDF documents will NOT be deleted.')) {
        return;
    }
    fetch('api/inbox-api.php?action=cleanup_media')
    .then(res => res.json())
    .then(data => {
        alert(data.message || 'Media cleanup completed.');
        if (currentActivePhone) {
            fetchMessages(currentActivePhone, false);
        }
    })
    .catch(err => alert('Cleanup failed: ' + err.message));
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;").replace(/\n/g, '<br>');
}
</script>
