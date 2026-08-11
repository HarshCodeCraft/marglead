<?php
/**
 * Internal CRM Module: Refund Policy
 * Rendered within index.php?page=refund_policy
 */
?>
<div class="main-content-header mb-6">
    <div>
        <div class="flex align-center gap-2 mb-1">
            <span class="badge" style="--badge-bg: rgba(16, 185, 129, 0.1); --badge-color: var(--accent);">Subscription Licensing</span>
            <span class="text-xs text-muted">Version 1.0.0</span>
        </div>
        <h1 class="text-2xl font-bold" style="font-family: var(--font-heading);">Refund & Cancellation Policy</h1>
        <p class="text-sm text-muted">Subscription terms, cancellation guidelines, and refund eligibility.</p>
    </div>
    <div class="flex gap-3 align-center">
        <a href="refund.php" target="_blank" class="btn btn-secondary text-sm flex align-center gap-2">
            <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
            <span>Public URL</span>
        </a>
    </div>
</div>

<div class="card p-6 mb-6" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);">
    <div class="flex align-center gap-3 pb-4 mb-6" style="border-bottom: 1px solid var(--border-color);">
        <div style="background: rgba(16, 185, 129, 0.1); padding: 0.75rem; border-radius: 10px; color: var(--accent);">
            <i data-lucide="refresh-cw" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <h3 class="font-bold text-base mb-1">Licensing & Billing Policy</h3>
            <p class="text-xs text-muted">Subscription renewal management and refund timelines.</p>
        </div>
    </div>

    <div class="grid gap-6" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
        
        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="calendar" style="width: 16px; height: 16px; color: var(--accent);"></i>
                <span>1. Cancellation Notice</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                Cancellations require a 15-day notice prior to billing cycle renewal. Submit requests via Support Tickets (`index.php?page=support`) or email <span class="text-main">billing@margsoft.com</span>.
            </p>
        </div>

        <div class="p-4" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
            <div class="flex align-center gap-2 font-semibold text-sm mb-2 text-main">
                <i data-lucide="rotate-ccw" style="width: 16px; height: 16px; color: var(--primary);"></i>
                <span>2. 30-Day Guarantee</span>
            </div>
            <p class="text-xs text-muted leading-relaxed">
                New annual cloud deployments enjoy a 30-day money-back guarantee if cancelled within 30 days of initial system provisioning.
            </p>
        </div>

    </div>

    <div class="mt-6 pt-4" style="border-top: 1px solid var(--border-color);">
        <p class="text-xs text-muted">
            View the complete policy at <a href="refund.php" target="_blank" class="text-primary font-semibold">Public Refund & Cancellation Policy Page</a>.
        </p>
    </div>
</div>
