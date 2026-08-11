<?php
/**
 * Internal CRM Module: Privacy Policy
 * Rendered within index.php?page=privacy_policy
 */
?>
<div class="main-content-header mb-6">
    <div>
        <div class="flex align-center gap-2 mb-1">
            <span class="badge" style="--badge-bg: rgba(59, 130, 246, 0.1); --badge-color: var(--primary);">System Legal Compliance</span>
            <span class="text-xs text-muted">Version 1.0.0</span>
        </div>
        <h1 class="text-2xl font-bold" style="font-family: var(--font-heading);">Privacy Policy</h1>
        <p class="text-sm text-muted">Data protection, lead privacy standards, and WhatsApp API communication rules.</p>
    </div>
    <div class="flex gap-3 align-center">
        <a href="privacy.php" target="_blank" class="btn btn-secondary text-sm flex align-center gap-2">
            <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
            <span>Public URL</span>
        </a>
    </div>
</div>

<div class="card p-6 mb-6" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);">
    <div class="flex align-center gap-3 pb-4 mb-6" style="border-bottom: 1px solid var(--border-color);">
        <div style="background: rgba(59, 130, 246, 0.1); padding: 0.75rem; border-radius: 10px; color: var(--primary);">
            <i data-lucide="shield-check" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <h3 class="font-bold text-base mb-1">Data Governance Notice</h3>
            <p class="text-xs text-muted">Effective Date: August 5, 2026 • Marg Soft Solutions Enterprise Policy</p>
        </div>
    </div>

    <div class="grid gap-6" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
        
        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="database" style="width: 16px; height: 16px; color: var(--primary);"></i>
                <span>1. Data Collection Scope</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                Marg ERP CRM stores lead details, customer directory records, quotation histories, WhatsApp message logs, and user credentials. All database operations strictly use PDO prepared statements to safeguard against SQL injection.
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="message-square" style="width: 16px; height: 16px; color: var(--accent);"></i>
                <span>2. WhatsApp API & Communications</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                Automated messages dispatched via WhatsApp Cloud API or SMS gateways are strictly operational. Phone numbers and chat logs are processed securely without unauthorized third-party monetization or selling.
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="lock" style="width: 16px; height: 16px; color: #f59e0b;"></i>
                <span>3. Password & Session Security</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                User passwords are encrypted with standard cryptographic hashes. User roles and action permissions are dynamically synced on every request to prevent unauthorized privilege escalation.
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="download" style="width: 16px; height: 16px; color: #8b5cf6;"></i>
                <span>4. Tenant Control & Data Export</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                Authorized administrators have full rights to export lead directories and billing data via CSV/XLSX at any time, maintaining total control over proprietary company information.
            </p>
        </div>

    </div>

    <div class="mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
        <p class="text-xs text-muted">
            For complete compliance details, view the standalone <a href="privacy.php" target="_blank" class="text-primary font-semibold">Public Privacy Policy Page</a> or contact <span class="text-main">privacy@margsoft.com</span>.
        </p>
    </div>
</div>
