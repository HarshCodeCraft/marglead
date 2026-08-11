<?php
/**
 * Internal CRM Module: Terms & Conditions
 * Rendered within index.php?page=terms_conditions
 */
?>
<div class="main-content-header mb-6">
    <div>
        <div class="flex align-center gap-2 mb-1">
            <span class="badge" style="--badge-bg: rgba(124, 58, 237, 0.1); --badge-color: #a78bfa;">Terms of Service</span>
            <span class="text-xs text-muted">Version 1.0.0</span>
        </div>
        <h1 class="text-2xl font-bold" style="font-family: var(--font-heading);">Terms & Conditions</h1>
        <p class="text-sm text-muted">Operational terms, role responsibilities, and system access policies.</p>
    </div>
    <div class="flex gap-3 align-center">
        <a href="terms.php" target="_blank" class="btn btn-secondary text-sm flex align-center gap-2">
            <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
            <span>Public URL</span>
        </a>
    </div>
</div>

<div class="card p-6 mb-6" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);">
    <div class="flex align-center gap-3 pb-4 mb-6" style="border-bottom: 1px solid var(--border-color);">
        <div style="background: rgba(124, 58, 237, 0.1); padding: 0.75rem; border-radius: 10px; color: #a78bfa;">
            <i data-lucide="file-text" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <h3 class="font-bold text-base mb-1">Operational Terms of Service</h3>
            <p class="text-xs text-muted">Governing Marg ERP CRM system usage across organization roles.</p>
        </div>
    </div>

    <div class="flex flex-col gap-4">
        
        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <h4 class="font-semibold text-sm mb-2 text-main flex align-center gap-2">
                <i data-lucide="user-check" style="width: 16px; height: 16px; color: var(--primary);"></i>
                1. Account Registration & Role Integrity
            </h4>
            <p class="text-xs text-muted leading-relaxed">
                Operators registering accounts enter an initial `Pending Approval` state until verified by an Administrator. System roles dictate data boundaries (Sales Executives see assigned leads, Regional Managers see team metrics, Support Engineers manage tickets).
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <h4 class="font-semibold text-sm mb-2 text-main flex align-center gap-2">
                <i data-lucide="shield-alert" style="width: 16px; height: 16px; color: #ef4444;"></i>
                2. Anti-Spam & Messaging Guidelines
            </h4>
            <p class="text-xs text-muted leading-relaxed">
                Using WhatsApp Bot flows or SMS reminders for illegal bulk spam is strictly prohibited. Accounts engaging in unauthorized messaging face automated feature suspension.
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-card); border-radius: var(--border-radius-md);">
            <h4 class="font-semibold text-sm mb-2 text-main flex align-center gap-2">
                <i data-lucide="shield" style="width: 16px; height: 16px; color: var(--accent);"></i>
                3. System Uptime & Maintenance
            </h4>
            <p class="text-xs text-muted leading-relaxed">
                Marg ERP targets continuous 99.9% uptime. System updates or maintenance windows are announced via header notifications. Upstream provider outages (e.g. carrier networks or Meta Cloud API) are beyond direct SLA scope.
            </p>
        </div>

    </div>

    <div class="mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
        <p class="text-xs text-muted">
            Read full detailed clauses on the standalone <a href="terms.php" target="_blank" class="text-primary font-semibold">Public Terms & Conditions Page</a>.
        </p>
    </div>
</div>
