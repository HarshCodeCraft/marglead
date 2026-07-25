<?php
/**
 * Marg ERP CRM - Beautiful Access Restricted Screen
 */

require_once __DIR__ . '/../includes/config.php';

$requested_page = $_GET['requested'] ?? ($_SESSION['access_denied_page'] ?? 'workspace');
unset($_SESSION['access_denied_page']);

// Module title mapping
$module_titles = [
    'dashboard' => 'Dashboard Overview',
    'leads' => 'Leads Directory',
    'pipeline' => 'Pipeline Kanban',
    'followups' => 'Follow-up Schedules',
    'demo' => 'Product Demos & Feedback',
    'quotation' => 'Quotations & Proposals',
    'quotation_create' => 'Create Quotation',
    'quotation_view' => 'View Quotation',
    'payments' => 'Payments & Invoices',
    'bank_accounts' => 'Bank & QR Details',
    'installation' => 'Technical Installations',
    'training' => 'Client Training',
    'support' => 'Support Desk & Tickets',
    'renewals' => 'Software Support Renewals',
    'reports' => 'Business Reports',
    'admin_reports' => 'Business Reports',
    'settings' => 'Control Settings',
    'admin_users' => 'System Operators',
    'admin_permissions' => 'Employee Permissions'
];

$module_title = $module_titles[$requested_page] ?? ucfirst(str_replace('_', ' ', $requested_page));
?>

<style>
    .access-denied-container {
        max-width: 620px;
        margin: 3.5rem auto;
        padding: 0 1rem;
    }
    .access-restricted-card {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-lg);
        border-radius: var(--border-radius-lg);
        padding: 3.5rem 2.5rem;
        position: relative;
        overflow: hidden;
        text-align: center;
        transition: background-color var(--transition-base), border-color var(--transition-base);
    }
    .access-restricted-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--danger), var(--warning), var(--primary));
    }
    .access-lock-icon {
        width: 76px;
        height: 76px;
        border-radius: var(--border-radius-full);
        background: hsla(347, 84%, 54%, 0.12);
        border: 1px solid hsla(347, 84%, 54%, 0.25);
        color: var(--danger);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.75rem auto;
        box-shadow: 0 0 30px hsla(347, 84%, 54%, 0.18);
        animation: lockPulse 2.5s infinite ease-in-out;
    }
    @keyframes lockPulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 25px hsla(347, 84%, 54%, 0.18); }
        50% { transform: scale(1.04); box-shadow: 0 0 35px hsla(347, 84%, 54%, 0.32); }
    }
</style>

<div class="access-denied-container">
    <div class="access-restricted-card">
        <!-- Shield Lock Icon Avatar -->
        <div class="access-lock-icon">
            <i data-lucide="shield-alert" style="width: 38px; height: 38px;"></i>
        </div>

        <!-- Heading -->
        <h2 style="font-family: var(--font-heading); font-size: 1.875rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.875rem;">
            Access Restricted
        </h2>

        <!-- Description Message -->
        <p style="font-size: 0.975rem; color: var(--text-muted); margin: 0 auto; line-height: 1.6; max-width: 480px;">
            You do not currently have operational privileges to access the <strong style="color: var(--primary); font-weight: 700;"><?php echo htmlspecialchars($module_title); ?></strong> workspace module.
        </p>

        <!-- Action Button -->
        <div style="margin-top: 2rem;">
            <a href="index.php?page=dashboard" class="btn btn-primary text-sm" style="padding: 0.75rem 2rem; border-radius: var(--border-radius-sm);">
                <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                <span>Return to Dashboard</span>
            </a>
        </div>
    </div>
</div>
