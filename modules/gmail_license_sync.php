<?php
/**
 * Marg ERP CRM - Gmail License Automation Module
 * Allows tenants & admins to configure Gmail App Password & filter rules,
 * automatically read incoming Marg license emails, download attachments,
 * verify against Client Directory and sync with 1-click approval or auto-update.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin' || $user_role === 'Tenant Admin');

// Permission Security Check
if (!$is_admin && !hasAccess('gmail_license_sync', $user_role)) {
    echo "<div class='p-6'><div class='alert alert-danger'>Access Denied: You do not have permission to access Gmail License Automation. Contact your Super Admin.</div></div>";
    return;
}

$current_user_id = (int)($_SESSION['tenant_id'] ?? $_SESSION['user_id'] ?? 1);
if ($current_user_id <= 0) $current_user_id = 1;
?>

<div class="gmail-sync-workspace" style="padding: 1.5rem; max-width: 1400px; margin: 0 auto;">

    <!-- Top Header Banner -->
    <div class="glass-card mb-4" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98)); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 1.5rem; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);">
        <div class="flex flex-wrap justify-between align-center gap-4">
            <div class="flex align-center gap-3">
                <div style="width: 50px; height: 50px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.35);">
                    <i data-lucide="mail-check" style="width: 26px; height: 26px; color: #fff;"></i>
                </div>
                <div>
                    <h2 class="m-0 text-xl font-bold" style="color: #fff; display: flex; align-items: center; gap: 8px;">
                        Gmail License Automation
                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 20px;">SAFE REGEX ENGINE</span>
                        <span class="badge" style="background: rgba(6, 182, 212, 0.2); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 20px;">IMAP SSL 993</span>
                    </h2>
                    <p class="m-0 text-xs text-muted" style="color: #94a3b8; margin-top: 4px;">
                        Automatically read incoming Marg ERP license emails, extract Customer ID, verify Client Directory, download license files & auto-sync.
                    </p>
                </div>
            </div>

            <div class="flex align-center gap-3">
                <button type="button" id="btn-sync-now" onclick="triggerEmailSync()" class="btn flex align-center gap-2" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.65rem 1.25rem; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); transition: all 0.2s;">
                    <i data-lucide="refresh-cw" id="sync-spin-icon" style="width: 16px; height: 16px;"></i>
                    <span id="sync-btn-text">Fetch & Sync Emails</span>
                </button>
                <button type="button" onclick="switchTab('tab-settings')" class="btn btn-secondary flex align-center gap-2" style="border-radius: 10px; padding: 0.65rem 1.1rem; font-size: 0.85rem;">
                    <i data-lucide="sliders" style="width: 16px; height: 16px;"></i>
                    <span>Rules & Settings</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex gap-2 mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
        <button type="button" class="tab-btn active" id="btn-tab-inbox" onclick="switchTab('tab-inbox')" style="padding: 0.6rem 1.25rem; border: none; background: none; font-weight: 600; font-size: 0.875rem; color: var(--text-main); cursor: pointer; border-radius: 8px; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i data-lucide="inbox" style="width: 16px; height: 16px;"></i>
            Incoming License Queue
            <span class="badge" id="tab-pending-badge" style="background: #ef4444; color: #fff; font-size: 0.65rem; padding: 2px 7px; border-radius: 10px; display: none;">0</span>
        </button>
        <button type="button" class="tab-btn" id="btn-tab-settings" onclick="switchTab('tab-settings')" style="padding: 0.6rem 1.25rem; border: none; background: none; font-weight: 600; font-size: 0.875rem; color: var(--text-muted); cursor: pointer; border-radius: 8px; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i data-lucide="settings-2" style="width: 16px; height: 16px;"></i>
            Gmail Connection & Rules
        </button>
        <button type="button" class="tab-btn" id="btn-tab-guide" onclick="switchTab('tab-guide')" style="padding: 0.6rem 1.25rem; border: none; background: none; font-weight: 600; font-size: 0.875rem; color: var(--text-muted); cursor: pointer; border-radius: 8px; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
            <i data-lucide="help-circle" style="width: 16px; height: 16px;"></i>
            2-Minute Setup Guide
        </button>
    </div>

    <!-- TAB 1: INCOMING LICENSE QUEUE -->
    <div id="tab-inbox" class="tab-content">
        <!-- Filter bar -->
        <div class="flex justify-between align-center mb-3">
            <div class="flex gap-2">
                <button type="button" onclick="loadLicenses('all')" class="btn btn-secondary filter-btn active" data-filter="all" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 6px;">All</button>
                <button type="button" onclick="loadLicenses('pending')" class="btn btn-secondary filter-btn" data-filter="pending" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 6px;">Pending Review</button>
                <button type="button" onclick="loadLicenses('auto-synced')" class="btn btn-secondary filter-btn" data-filter="auto-synced" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 6px;">Auto-Synced</button>
                <button type="button" onclick="loadLicenses('approved')" class="btn btn-secondary filter-btn" data-filter="approved" style="padding: 4px 12px; font-size: 0.75rem; border-radius: 6px;">Approved</button>
            </div>
            <div class="text-xs text-muted" id="sync-last-time">Last Synced: Checking...</div>
        </div>

        <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
            <div class="table-responsive" style="max-height: 650px; overflow-y: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.825rem;">
                    <thead>
                        <tr style="background: var(--bg-app); border-bottom: 1px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.75rem 1rem;">Date & Sender</th>
                            <th style="padding: 0.75rem 1rem;">Customer ID (Licence)</th>
                            <th style="padding: 0.75rem 1rem;">Party / Firm Name</th>
                            <th style="padding: 0.75rem 1rem;">Software & Users</th>
                            <th style="padding: 0.75rem 1rem;">Matched CRM Client</th>
                            <th style="padding: 0.75rem 1rem;">Attached File</th>
                            <th style="padding: 0.75rem 1rem;">Status</th>
                            <th style="padding: 0.75rem 1rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="license-table-body">
                        <tr>
                            <td colspan="8" class="text-center py-6 text-muted">
                                <i data-lucide="loader-2" class="spin mb-2" style="width: 24px; height: 24px; display: inline-block;"></i>
                                <p class="m-0">Loading incoming license records...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: GMAIL CONNECTION & RULES -->
    <div id="tab-settings" class="tab-content" style="display: none;">
        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">

            <!-- Connection Form -->
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.5rem;">
                <div class="flex align-center gap-2 mb-4 pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <i data-lucide="mail" style="width: 20px; height: 20px; color: #f59e0b;"></i>
                    <h3 class="m-0 text-base font-bold">1. Gmail Connection Details</h3>
                </div>

                <form id="gmail-settings-form" onsubmit="saveGmailSettings(event)">
                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Gmail Address</label>
                        <div class="input-icon-group" style="position: relative;">
                            <input type="email" id="set_gmail_address" name="gmail_address" required placeholder="e.g. youragency@gmail.com" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                        </div>
                        <span class="text-xs text-muted" style="display: block; margin-top: 4px;">The Gmail account where you receive Marg ERP license emails.</span>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">
                            16-Digit Google App Password
                            <a href="javascript:void(0);" onclick="switchTab('tab-guide')" class="text-xs" style="color: var(--accent-cyan); text-decoration: underline; margin-left: 8px;">How to generate?</a>
                        </label>
                        <div class="input-icon-group" style="position: relative;">
                            <input type="password" id="set_app_password" name="app_password" placeholder="xxxx xxxx xxxx xxxx" class="form-control font-mono" style="width: 100%; border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                        </div>
                        <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Never enter your regular email password. Use a dedicated Google App Password.</span>
                    </div>

                    <div class="flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary flex align-center gap-2" style="border-radius: 8px; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 600;">
                            <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                            <span>Save Settings</span>
                        </button>
                        <button type="button" onclick="testGmailConnection()" class="btn btn-secondary flex align-center gap-2" style="border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.85rem;">
                            <i data-lucide="activity" style="width: 16px; height: 16px;"></i>
                            <span id="test-btn-text">Test Connection</span>
                        </button>
                    </div>

                    <div id="conn-status-alert" class="mt-3" style="display: none;"></div>
                </form>
            </div>

            <!-- Custom Rule Engine -->
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.5rem;">
                <div class="flex align-center gap-2 mb-4 pb-3" style="border-bottom: 1px solid var(--border-color);">
                    <i data-lucide="filter" style="width: 20px; height: 20px; color: #06b6d4;"></i>
                    <h3 class="m-0 text-base font-bold">2. Smart Filtering & Automation Rules</h3>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Allowed Sender Emails / Domains</label>
                    <input type="text" id="set_allowed_senders" placeholder="@margcompusoft.com, licenses@margerp.com" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                    <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Comma separated. Emails from any other sender are completely ignored for 100% privacy.</span>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Subject Keywords (Filter)</label>
                    <input type="text" id="set_subject_keywords" placeholder="License, Registration, Passkey, Customer ID" class="form-control" style="width: 100%; border-radius: 8px; padding: 0.6rem 0.85rem; font-size: 0.85rem; background: var(--bg-body); border: 1px solid var(--border-color); color: var(--text-main);">
                    <span class="text-xs text-muted" style="display: block; margin-top: 4px;">Only process emails containing these keywords in subject or body.</span>
                </div>

                <div class="form-group mb-3 p-3 border-radius-sm" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 8px;">
                    <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Sync & Update Mode</label>
                    <div class="flex flex-col gap-2">
                        <label class="flex align-center gap-2 cursor-pointer text-xs">
                            <input type="radio" name="set_sync_mode" value="manual" id="mode_manual" checked style="accent-color: var(--accent-primary);">
                            <span><strong>Manual Review Mode (Recommended):</strong> Receive notification & approve with 1 click before client directory updates.</span>
                        </label>
                        <label class="flex align-center gap-2 cursor-pointer text-xs">
                            <input type="radio" name="set_sync_mode" value="auto" id="mode_auto" style="accent-color: var(--accent-primary);">
                            <span><strong>Auto-Update Mode:</strong> Automatically update Client Directory as soon as matching Customer ID is detected.</span>
                        </label>
                    </div>
                </div>

                <div class="form-group mb-3 p-3 border-radius-sm" style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 8px;">
                    <div class="flex justify-between align-center">
                        <div>
                            <span class="text-xs font-bold" style="display: block;">Download & Save Attached License File</span>
                            <span class="text-xs text-muted">Automatically save attached Marg .lic / .txt / .pdf into Client's secure storage.</span>
                        </div>
                        <input type="checkbox" id="set_save_attachments" checked style="width: 18px; height: 18px; accent-color: var(--accent-primary); cursor: pointer;">
                    </div>
                </div>

                <button type="button" onclick="saveFilterRules()" class="btn btn-primary" style="border-radius: 8px; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 600;">
                    Update Rules
                </button>
            </div>

        </div>
    </div>

    <!-- TAB 3: 2-MINUTE SETUP GUIDE -->
    <div id="tab-guide" class="tab-content" style="display: none;">
        <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; padding: 2rem; max-width: 900px; margin: 0 auto;">
            <div class="flex align-center gap-3 mb-4">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="key" style="width: 22px; height: 22px; color: #f59e0b;"></i>
                </div>
                <div>
                    <h3 class="m-0 text-lg font-bold">How to Generate 16-Digit Google App Password</h3>
                    <p class="m-0 text-xs text-muted">Follow these 3 simple steps to connect your Gmail safely in under 2 minutes.</p>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="p-3 border-radius-sm flex gap-3" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 28px; height: 28px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem; flex-shrink: 0;">1</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Enable 2-Step Verification</h4>
                        <p class="text-xs text-muted m-0 mt-1">Open your Google Account: <a href="https://myaccount.google.com/security" target="_blank" style="color: var(--accent-cyan); text-decoration: underline;">https://myaccount.google.com/security</a> and make sure <strong>2-Step Verification</strong> is turned ON.</p>
                    </div>
                </div>

                <div class="p-3 border-radius-sm flex gap-3" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 28px; height: 28px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem; flex-shrink: 0;">2</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Generate App Password</h4>
                        <p class="text-xs text-muted m-0 mt-1">In the Google Account search bar at top, type <strong>"App Passwords"</strong> (or go to <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color: var(--accent-cyan); text-decoration: underline;">myaccount.google.com/apppasswords</a>). Under <em>App Name</em>, type <strong>"Marg CRM"</strong> and click <strong>Create</strong>.</p>
                    </div>
                </div>

                <div class="p-3 border-radius-sm flex gap-3" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 10px;">
                    <span style="width: 28px; height: 28px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem; flex-shrink: 0;">3</span>
                    <div>
                        <h4 class="m-0 text-sm font-semibold">Paste into Marg CRM</h4>
                        <p class="text-xs text-muted m-0 mt-1">Google will show a 16-character code (e.g. <code>abcd efgh ijkl mnop</code>). Copy it and paste it into the <strong>16-Digit Google App Password</strong> field in Tab 2, then click <strong>Save Settings</strong> & <strong>Test Connection</strong>.</p>
                    </div>
                </div>
            </div>

            <div class="mt-4 p-3 border-radius-sm" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px;">
                <span class="text-xs" style="color: #10b981; display: flex; align-items: center; gap: 6px;">
                    <i data-lucide="shield-check" style="width: 16px; height: 16px;"></i>
                    <strong>Why is this 100% safe?</strong> Google App Passwords allow strictly email access over encrypted TLS/SSL without exposing your Google Account password, and you can revoke it anytime from Google Security in 1 click.
                </span>
            </div>
        </div>
    </div>

</div>

<!-- Modal: Review & Approve License -->
<div id="approve-license-modal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
    <div class="modal-container" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; width: 90%; max-width: 650px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <div class="modal-header flex justify-between align-center p-4" style="border-bottom: 1px solid var(--border-color); background: var(--bg-app);">
            <div class="flex align-center gap-2">
                <i data-lucide="check-circle-2" style="width: 20px; height: 20px; color: #10b981;"></i>
                <h3 class="m-0 text-base font-bold">Review & Approve Marg License</h3>
            </div>
            <button class="btn-icon" onclick="closeApproveModal()" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x" style="width: 18px; height: 18px;"></i></button>
        </div>

        <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
            <input type="hidden" id="modal_license_id">

            <!-- Extracted summary card -->
            <div class="p-3 mb-3 border-radius-sm" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px;">
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.8rem;">
                    <div><span class="text-muted">Customer ID:</span> <strong id="modal_disp_customer_id" class="font-mono text-main"></strong></div>
                    <div><span class="text-muted">Party Name:</span> <strong id="modal_disp_party_name" class="text-main"></strong></div>
                    <div><span class="text-muted">Phone / Mobile:</span> <span id="modal_disp_phone" class="text-main"></span></div>
                    <div><span class="text-muted">Software:</span> <span id="modal_disp_software" class="text-main"></span></div>
                    <div><span class="text-muted">Contact:</span> <span id="modal_disp_contact" class="text-main"></span></div>
                    <div><span class="text-muted">Email:</span> <span id="modal_disp_email" class="text-main"></span></div>
                </div>
            </div>

            <!-- Target Client Match -->
            <div class="form-group mb-3">
                <label class="form-label text-xs font-semibold" style="margin-bottom: 6px; display: block;">Match with Client in Directory</label>
                <div id="modal_match_info" class="p-2 border-radius-sm mb-2 text-xs" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 8px;"></div>
                <input type="hidden" id="modal_target_client_id">
            </div>

            <div id="modal_file_info" class="p-2 mb-3 text-xs" style="background: var(--bg-body); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
                <span class="flex align-center gap-2">
                    <i data-lucide="paperclip" style="width: 14px; height: 14px; color: var(--accent-cyan);"></i>
                    <span id="modal_file_name">No file</span>
                </span>
                <a id="modal_file_link" href="#" target="_blank" class="btn btn-secondary text-xs" style="padding: 2px 8px; text-decoration: none;">Download File</a>
            </div>
        </div>

        <div class="modal-footer flex justify-between align-center p-3" style="border-top: 1px solid var(--border-color); background: var(--bg-app);">
            <button type="button" onclick="closeApproveModal()" class="btn btn-secondary text-xs" style="padding: 6px 14px; border-radius: 8px;">Cancel</button>
            <button type="button" onclick="confirmApproveLicense()" class="btn btn-primary text-xs flex align-center gap-2" style="background: #10b981; border-color: #10b981; padding: 6px 16px; border-radius: 8px; font-weight: 600;">
                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                <span>Confirm & Update Client Directory</span>
            </button>
        </div>
    </div>
</div>

<script>
let currentLicensesData = [];

document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    loadLicenses('all');
    if (typeof lucide !== 'undefined') lucide.createIcons();
});

function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => {
        el.classList.remove('active');
        el.style.color = 'var(--text-muted)';
    });

    const activeTab = document.getElementById(tabId);
    if (activeTab) activeTab.style.display = 'block';

    const activeBtn = document.getElementById('btn-' + tabId);
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.color = 'var(--text-main)';
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function loadSettings() {
    try {
        const res = await fetch('api/gmail_license_engine.php?action=get_settings');
        const data = await res.json();
        if (data.success && data.settings) {
            const s = data.settings;
            document.getElementById('set_gmail_address').value = s.gmail_address || '';
            if (s.app_password_masked) {
                document.getElementById('set_app_password').value = s.app_password_masked;
            }
            document.getElementById('set_allowed_senders').value = s.allowed_senders || '';
            document.getElementById('set_subject_keywords').value = s.subject_keywords || '';
            if (s.sync_mode === 'auto') {
                document.getElementById('mode_auto').checked = true;
            } else {
                document.getElementById('mode_manual').checked = true;
            }
            document.getElementById('set_save_attachments').checked = (parseInt(s.save_attachments) === 1);
            if (s.last_sync_at) {
                document.getElementById('sync-last-time').innerText = 'Last Synced: ' + s.last_sync_at;
            }
        }
    } catch (e) {
        console.error('loadSettings error:', e);
    }
}

async function saveGmailSettings(e) {
    if (e) e.preventDefault();
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('gmail_address', document.getElementById('set_gmail_address').value.trim());
    formData.append('app_password', document.getElementById('set_app_password').value.trim());
    formData.append('allowed_senders', document.getElementById('set_allowed_senders').value.trim());
    formData.append('subject_keywords', document.getElementById('set_subject_keywords').value.trim());
    formData.append('sync_mode', document.querySelector('input[name="set_sync_mode"]:checked').value);
    formData.append('save_attachments', document.getElementById('set_save_attachments').checked ? '1' : '0');

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, data.success ? 'success' : 'danger');
    } catch (err) {
        showAlert('Error saving settings: ' + err.message, 'danger');
    }
}

function saveFilterRules() {
    saveGmailSettings(null);
}

async function testGmailConnection() {
    const btn = document.getElementById('test-btn-text');
    btn.innerText = 'Testing...';

    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('gmail_address', document.getElementById('set_gmail_address').value.trim());
    formData.append('app_password', document.getElementById('set_app_password').value.trim());

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, data.success ? 'success' : 'danger');
    } catch (err) {
        showAlert('Connection error: ' + err.message, 'danger');
    } finally {
        btn.innerText = 'Test Connection';
    }
}

async function triggerEmailSync() {
    const spinIcon = document.getElementById('sync-spin-icon');
    const syncBtn = document.getElementById('btn-sync-now');
    const syncBtnText = document.getElementById('sync-btn-text');
    if (spinIcon) spinIcon.classList.add('spin');
    syncBtn.disabled = true;
    if (syncBtnText) syncBtnText.innerText = 'Scanning Gmail...';

    try {
        const formData = new FormData();
        formData.append('action', 'fetch_emails');
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (je) {
            throw new Error('Server returned invalid response: ' + text.substring(0, 150));
        }

        if (data.success) {
            showAlert(data.message, 'success');
            await loadLicenses('all');
            document.getElementById('sync-last-time').innerText = 'Last Synced: Just now';
        } else {
            showAlert(data.message, 'danger');
        }
    } catch (e) {
        showAlert('Sync failed: ' + e.message, 'danger');
    } finally {
        if (spinIcon) spinIcon.classList.remove('spin');
        syncBtn.disabled = false;
        if (syncBtnText) syncBtnText.innerText = 'Fetch & Sync Emails';
    }
}

async function loadLicenses(status = 'all') {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    const activeBtn = document.querySelector(`.filter-btn[data-filter="${status}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    const tbody = document.getElementById('license-table-body');
    try {
        const res = await fetch(`api/gmail_license_engine.php?action=list_licenses&status=${encodeURIComponent(status)}`);
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (je) {
            console.error('Invalid JSON response:', text);
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Server error while loading records. Raw: ${escapeHtml(text.substring(0, 100))}</td></tr>`;
            return;
        }

        if (!data.success || !data.licenses || data.licenses.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-6 text-muted">
                        <i data-lucide="inbox" style="width: 32px; height: 32px; display: inline-block; opacity: 0.4; margin-bottom: 8px;"></i>
                        <p class="m-0 text-sm">No incoming Marg ERP license emails found in queue.</p>
                        <p class="m-0 text-xs mt-1">Click "Fetch & Sync Emails" to check your Gmail inbox.</p>
                    </td>
                </tr>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
            return;
        }

        currentLicensesData = data.licenses;
        let pendingCount = 0;
        let html = '';

        data.licenses.forEach(lic => {
            if (lic.sync_status === 'Pending') pendingCount++;

            let statusBadge = '';
            if (lic.sync_status === 'Auto-Synced') {
                statusBadge = '<span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">Auto-Synced ✓</span>';
            } else if (lic.sync_status === 'Approved') {
                statusBadge = '<span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">Approved ✓</span>';
            } else if (lic.sync_status === 'Ignored') {
                statusBadge = '<span class="badge" style="background: rgba(100, 116, 139, 0.15); color: #64748b; font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">Ignored</span>';
            } else {
                statusBadge = '<span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;">Pending Review</span>';
            }

            let fileHtml = '<span class="text-muted text-xs">No file</span>';
            if (lic.attachment_path) {
                fileHtml = `<a href="${lic.attachment_path}" target="_blank" class="flex align-center gap-1 text-xs" style="color: var(--accent-cyan); text-decoration: none;" download>
                    <i data-lucide="paperclip" style="width: 12px; height: 12px;"></i>
                    <span>${lic.attachment_filename || 'Download'}</span>
                </a>`;
            }

            let matchedHtml = '';
            if (lic.matched_client_id) {
                matchedHtml = `<span class="text-xs" style="color: #10b981;">✓ Matched (${escapeHtml(lic.client_actual_name || '#' + lic.matched_client_id)})</span>`;
            } else {
                matchedHtml = `<span class="text-xs text-muted">New Client (Not in CRM)</span>`;
            }

            let actionsHtml = '';
            if (lic.sync_status === 'Pending') {
                actionsHtml = `
                    <button type="button" onclick="openApproveModal(${lic.id})" class="btn btn-primary text-xs" style="padding: 4px 10px; border-radius: 6px; background: #10b981; border: none; font-weight: 600;">
                        Approve & Sync
                    </button>
                    <button type="button" onclick="ignoreLicense(${lic.id})" class="btn btn-secondary text-xs" style="padding: 4px 8px; border-radius: 6px; margin-left: 4px;" title="Ignore">
                        ✕
                    </button>
                `;
            } else {
                actionsHtml = `<span class="text-xs text-muted">Synced</span>`;
            }

            html += `
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 0.75rem 1rem;">
                        <div class="text-xs font-semibold text-main">${escapeHtml(lic.email_date || '')}</div>
                        <div class="text-xs text-muted" style="font-size: 11px;">${escapeHtml(lic.from_email || '')}</div>
                    </td>
                    <td style="padding: 0.75rem 1rem;">
                        <span class="font-mono text-xs font-bold text-main" style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;">${escapeHtml(lic.customer_id || '-')}</span>
                    </td>
                    <td style="padding: 0.75rem 1rem;">
                        <div class="text-xs font-semibold text-main">${escapeHtml(lic.party_name || '-')}</div>
                        <div class="text-xs text-muted" style="font-size: 11px;">${escapeHtml(lic.city || '')} ${escapeHtml(lic.state || '')}</div>
                    </td>
                    <td style="padding: 0.75rem 1rem;">
                        <div class="text-xs text-main">${escapeHtml(lic.software_type || '-')}</div>
                        <div class="text-xs text-muted" style="font-size: 11px;">Users: ${escapeHtml(lic.users_count || '1')}</div>
                    </td>
                    <td style="padding: 0.75rem 1rem;">${matchedHtml}</td>
                    <td style="padding: 0.75rem 1rem;">${fileHtml}</td>
                    <td style="padding: 0.75rem 1rem;">${statusBadge}</td>
                    <td style="padding: 0.75rem 1rem; text-align: right;">${actionsHtml}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;

        // Update badge
        const badge = document.getElementById('tab-pending-badge');
        if (badge) {
            badge.innerText = pendingCount;
            badge.style.display = pendingCount > 0 ? 'inline-block' : 'none';
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Error loading records: ${e.message}</td></tr>`;
    }
}

function openApproveModal(id) {
    const lic = currentLicensesData.find(item => parseInt(item.id) === parseInt(id));
    if (!lic) return;

    document.getElementById('modal_license_id').value = lic.id;
    document.getElementById('modal_disp_customer_id').innerText = lic.customer_id || '-';
    document.getElementById('modal_disp_party_name').innerText = lic.party_name || '-';
    document.getElementById('modal_disp_phone').innerText = (lic.phone || lic.reg_mobile || '-');
    document.getElementById('modal_disp_software').innerText = lic.software_type || '-';
    document.getElementById('modal_disp_contact').innerText = lic.contact_person || '-';
    document.getElementById('modal_disp_email').innerText = lic.reg_email || '-';

    document.getElementById('modal_target_client_id').value = lic.matched_client_id || '';
    const matchBox = document.getElementById('modal_match_info');
    if (lic.matched_client_id) {
        matchBox.innerHTML = `<strong>Matched Client Found:</strong> ${escapeHtml(lic.client_actual_name || '')} (ID #${lic.matched_client_id})<br><span class="text-muted">Will update license details and attach file to this existing client profile.</span>`;
    } else {
        matchBox.innerHTML = `<strong>New Client Record:</strong> No existing client matched Customer ID "${lic.customer_id}".<br><span class="text-muted">A brand new client entry will be created in your Client Directory automatically.</span>`;
    }

    const fileBox = document.getElementById('modal_file_info');
    if (lic.attachment_path) {
        fileBox.style.display = 'flex';
        document.getElementById('modal_file_name').innerText = lic.attachment_filename || 'License File Attached';
        document.getElementById('modal_file_link').href = lic.attachment_path;
    } else {
        fileBox.style.display = 'none';
    }

    const modal = document.getElementById('approve-license-modal');
    modal.style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeApproveModal() {
    document.getElementById('approve-license-modal').style.display = 'none';
}

async function confirmApproveLicense() {
    const licId = document.getElementById('modal_license_id').value;
    const targetClientId = document.getElementById('modal_target_client_id').value;

    const formData = new FormData();
    formData.append('action', 'approve_license');
    formData.append('license_id', licId);
    formData.append('target_client_id', targetClientId);

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            closeApproveModal();
            showAlert(data.message, 'success');
            loadLicenses('all');
        } else {
            alert('Approval error: ' + data.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function ignoreLicense(id) {
    if (!confirm('Are you sure you want to ignore this license email?')) return;
    const formData = new FormData();
    formData.append('action', 'ignore_license');
    formData.append('license_id', id);

    try {
        const res = await fetch('api/gmail_license_engine.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        showAlert(data.message, 'info');
        loadLicenses('all');
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function showAlert(msg, type = 'success') {
    const container = document.getElementById('conn-status-alert');
    if (!container) return;
    container.style.display = 'block';
    container.className = `alert alert-${type} mt-3 text-xs`;
    container.innerHTML = msg;
    setTimeout(() => {
        container.style.display = 'none';
    }, 6000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<style>
.tab-btn.active {
    background: rgba(255, 255, 255, 0.08) !important;
    color: var(--text-main) !important;
}
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
