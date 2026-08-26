<?php
require_once __DIR__ . '/config.php';
$role = $_SESSION['user_role'];
?>
<aside class="sidebar">
    <!-- Brand Header -->
    <div class="sidebar-header">
        <a href="index.php" class="sidebar-brand">
            <img src="assets/image.png" alt="Marg Logo" style="width: 24px; height: 24px; object-fit: contain;">
            <span><?php echo APP_NAME; ?></span>
        </a>
    </div>

    <!-- Navigation Menu Items -->
    <div class="sidebar-menu">
        
        <!-- Core Group -->
        <div class="menu-group-title">Core</div>
        <ul>
            <?php if (hasAccess('dashboard', $role)): ?>
                <li class="sidebar-item <?php echo isActivePage('dashboard'); ?>">
                    <a href="index.php?page=dashboard">
                        <i data-lucide="layout-dashboard" style="width: 18px; height: 18px;"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <!-- Lead Operations Group -->
        <?php if (hasAccess('leads', $role) || hasAccess('pipeline', $role) || hasAccess('followups', $role)): ?>
            <div class="menu-group-title">Leads & Clients</div>
            <ul>
                <?php if (hasAccess('leads', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('leads') || isActivePage('lead_details') || isActivePage('lead_form') || isActivePage('lead_import') ? 'active' : ''; ?>">
                        <a href="index.php?page=leads">
                            <i data-lucide="users" style="width: 18px; height: 18px;"></i>
                            <span>Lead Directory</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('clients', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('clients') ? 'active' : ''; ?>">
                        <a href="index.php?page=clients">
                            <i data-lucide="building-2" style="width: 18px; height: 18px;"></i>
                            <span>Clients Directory</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('leads', $role) || hasAccess('clients', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('customer_kyc') ? 'active' : ''; ?>">
                        <a href="index.php?page=customer_kyc">
                            <i data-lucide="file-check" style="width: 18px; height: 18px;"></i>
                            <span>Customer KYC Details</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('pipeline', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('pipeline'); ?>">
                        <a href="index.php?page=pipeline">
                            <i data-lucide="kanban-square" style="width: 18px; height: 18px;"></i>
                            <span>Visual Pipeline</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('followups', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('followups'); ?>">
                        <a href="index.php?page=followups">
                            <i data-lucide="calendar" style="width: 18px; height: 18px;"></i>
                            <span>Follow-up Planner</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Conversions & Processes -->
        <?php if (hasAccess('demo', $role) || hasAccess('quotation', $role) || hasAccess('payments', $role) || hasAccess('bank_accounts', $role)): ?>
            <div class="menu-group-title">Sales Process</div>
            <ul>
                <?php if (hasAccess('demo', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('demo'); ?>">
                        <a href="index.php?page=demo">
                            <i data-lucide="monitor-play" style="width: 18px; height: 18px;"></i>
                            <span>Demos & Feedback</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('quotation', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('quotation') || isActivePage('quotation_create') || isActivePage('quotation_view') ? 'active' : ''; ?>">
                        <a href="index.php?page=quotation">
                            <i data-lucide="file-text" style="width: 18px; height: 18px;"></i>
                            <span>Quotations</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('payments', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('payments'); ?>">
                        <a href="index.php?page=payments">
                            <i data-lucide="credit-card" style="width: 18px; height: 18px;"></i>
                            <span>Payments & Invoices</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('bank_accounts', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('bank_accounts'); ?>">
                        <a href="index.php?page=bank_accounts">
                            <i data-lucide="qr-code" style="width: 18px; height: 18px;"></i>
                            <span>Bank & QR Details</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Engineering & Operations -->
        <?php if (hasAccess('installation', $role) || hasAccess('training', $role) || hasAccess('support', $role) || hasAccess('renewals', $role)): ?>
            <div class="menu-group-title">Operations</div>
            <ul>
                <?php if (hasAccess('installation', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('installation'); ?>">
                        <a href="index.php?page=installation">
                            <i data-lucide="wrench" style="width: 18px; height: 18px;"></i>
                            <span>Installations</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('training', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('training'); ?>">
                        <a href="index.php?page=training">
                            <i data-lucide="graduation-cap" style="width: 18px; height: 18px;"></i>
                            <span>Client Training</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('support', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('support'); ?>">
                        <a href="index.php?page=support">
                            <i data-lucide="life-buoy" style="width: 18px; height: 18px;"></i>
                            <span>Support Tickets</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('renewals', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('renewals'); ?>">
                        <a href="index.php?page=renewals">
                            <i data-lucide="rotate-cw" style="width: 18px; height: 18px;"></i>
                            <span>Renewals Management</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- WhatsApp & Automation -->
        <?php if (hasAccess('team_inbox', $role) || hasAccess('broadcast_campaigns', $role) || hasAccess('merchant_waba_settings', $role) || hasAccess('whatsapp_settings', $role) || hasAccess('bulk_broadcast', $role) || hasAccess('bot_flows', $role) || hasAccess('whatsapp_flows', $role)): ?>
            <div class="menu-group-title">WhatsApp & Messaging</div>
            <ul>
                <?php if (hasAccess('team_inbox', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('team_inbox'); ?>">
                        <a href="index.php?page=team_inbox">
                            <i data-lucide="message-square" style="width: 18px; height: 18px;"></i>
                            <span>Team Inbox & Live Chat</span>
                            <span class="badge" style="margin-left: auto; background: #10b981; color: white; font-size: 0.65rem;">LIVE</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasAccess('broadcast_campaigns', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('broadcast_campaigns'); ?>">
                        <a href="index.php?page=broadcast_campaigns">
                            <i data-lucide="send" style="width: 18px; height: 18px;"></i>
                            <span>WhatsApp Campaigns</span>
                            <span class="badge" style="margin-left: auto; background: #3b82f6; color: white; font-size: 0.65rem;">NEW</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasAccess('merchant_waba_settings', $role) || hasAccess('whatsapp_settings', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('merchant_waba_settings'); ?>">
                        <a href="index.php?page=merchant_waba_settings">
                            <i data-lucide="qr-code" style="width: 18px; height: 18px;"></i>
                            <span>Marg ERP WABA Setup</span>
                            <span class="badge" style="margin-left: auto; background: #10b981; color: white; font-size: 0.65rem;">ADD-ON</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasAccess('bulk_broadcast', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('bulk_broadcast'); ?>">
                        <a href="index.php?page=bulk_broadcast">
                            <i data-lucide="upload-cloud" style="width: 18px; height: 18px;"></i>
                            <span>Bulk Marketing Broadcast</span>
                            <span class="badge" style="margin-left: auto; background: #06b6d4; color: white; font-size: 0.65rem;">ADD-ON</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasAccess('bot_flows', $role) || hasAccess('whatsapp_flows', $role)): ?>
                    <?php 
                    $is_bot_active = isActivePage('bot_flows') || isActivePage('bot_flow_builder');
                    $current_tab = strtolower($_GET['tab'] ?? 'flows');
                    ?>
                    <li class="sidebar-item sidebar-dropdown <?php echo $is_bot_active ? 'active open' : ''; ?>">
                        <a href="index.php?page=bot_flows" class="sidebar-link-parent" onclick="toggleSidebarDropdown(event, this)">
                            <div class="dropdown-title-group">
                                <i data-lucide="bot" style="width: 18px; height: 18px;"></i>
                                <span>WhatsApp Bots & Flows</span>
                            </div>
                            <i data-lucide="chevron-down" class="menu-chevron" style="width: 14px; height: 14px;"></i>
                        </a>
                        <ul class="sidebar-submenu">
                            <li class="sidebar-subitem <?php echo ($is_bot_active && $current_tab === 'bots') ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=bots">
                                    <i data-lucide="bot" style="width: 14px; height: 14px;"></i>
                                    <span>Bots</span>
                                </a>
                            </li>
                            <li class="sidebar-subitem <?php echo ($is_bot_active && ($current_tab === 'flows' || empty($_GET['tab']))) ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=flows">
                                    <i data-lucide="git-fork" style="width: 14px; height: 14px;"></i>
                                    <span>Flows</span>
                                </a>
                            </li>
                            <li class="sidebar-subitem <?php echo ($is_bot_active && $current_tab === 'events') ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=events">
                                    <i data-lucide="file-text" style="width: 14px; height: 14px;"></i>
                                    <span>Events</span>
                                </a>
                            </li>
                            <li class="sidebar-subitem <?php echo ($is_bot_active && ($current_tab === 'triggers' || $current_tab === 'inggers')) ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=triggers">
                                    <i data-lucide="zap" style="width: 14px; height: 14px;"></i>
                                    <span>Triggers</span>
                                </a>
                            </li>
                            <li class="sidebar-subitem <?php echo ($is_bot_active && $current_tab === 're-engagement') ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=re-engagement">
                                    <i data-lucide="repeat" style="width: 14px; height: 14px;"></i>
                                    <span>Re-Engagement</span>
                                </a>
                            </li>
                            <li class="sidebar-subitem <?php echo ($is_bot_active && $current_tab === 'reports') ? 'active' : ''; ?>">
                                <a href="index.php?page=bot_flows&tab=reports">
                                    <i data-lucide="bar-chart-2" style="width: 14px; height: 14px;"></i>
                                    <span>Reports</span>
                                </a>
                            </li>
                            <?php if (hasAccess('whatsapp_settings', $role) || hasAccess('merchant_waba_settings', $role)): ?>
                                <li class="sidebar-subitem <?php echo isActivePage('whatsapp_settings'); ?>">
                                    <a href="index.php?page=whatsapp_settings">
                                        <i data-lucide="key" style="width: 14px; height: 14px; color: #25D366;"></i>
                                        <span>Cloud API Setup</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Managerial & Admin Controls -->
        <?php if (hasAccess('crm_clients', $role) || hasAccess('admin_users', $role) || hasAccess('admin_permissions', $role) || hasAccess('reports', $role) || hasAccess('admin_reviews', $role)): ?>
            <div class="menu-group-title">Management</div>
            <ul>
                <?php if (($role === 'Super Admin' || $role === 'Admin') && empty($_SESSION['impersonate_tenant_db'])): ?>
                    <?php if (hasAccess('crm_clients', $role)): ?>
                        <li class="sidebar-item <?php echo isActivePage('crm_clients'); ?>">
                            <a href="index.php?page=crm_clients">
                                <i data-lucide="building" style="width: 18px; height: 18px; color: var(--primary);"></i>
                                <span>CRM Clients</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (hasAccess('admin_users', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('admin_users'); ?>">
                        <a href="index.php?page=admin_users">
                            <i data-lucide="users-round" style="width: 18px; height: 18px;"></i>
                            <span>Manage Users</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('admin_permissions', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('admin_permissions'); ?>">
                        <a href="index.php?page=admin_permissions">
                            <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                            <span>Employee Permissions</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('reports', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('admin_reports'); ?>">
                        <a href="index.php?page=admin_reports">
                            <i data-lucide="bar-chart-3" style="width: 18px; height: 18px;"></i>
                            <span>Business Reports</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (hasAccess('admin_reviews', $role)): ?>
                    <li class="sidebar-item <?php echo isActivePage('admin_reviews'); ?>">
                        <a href="index.php?page=admin_reviews">
                            <i data-lucide="star" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                            <span>Customer Ratings</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Settings & System Group -->
        <?php if (hasAccess('settings', $role)): ?>
            <div class="menu-group-title">System</div>
            <ul>
                <li class="sidebar-item <?php echo isActivePage('settings'); ?>">
                    <a href="index.php?page=settings">
                        <i data-lucide="sliders" style="width: 18px; height: 18px;"></i>
                        <span>Control Settings</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <!-- Legal & Compliance Group -->
        <div class="menu-group-title">Legal & Compliance</div>
        <ul>
            <li class="sidebar-item <?php echo isActivePage('privacy_policy'); ?>">
                <a href="index.php?page=privacy_policy">
                    <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                    <span>Privacy Policy</span>
                </a>
            </li>
            <li class="sidebar-item <?php echo isActivePage('terms_conditions'); ?>">
                <a href="index.php?page=terms_conditions">
                    <i data-lucide="file-text" style="width: 18px; height: 18px;"></i>
                    <span>Terms & Conditions</span>
                </a>
            </li>
            <li class="sidebar-item <?php echo isActivePage('refund_policy'); ?>">
                <a href="index.php?page=refund_policy">
                    <i data-lucide="refresh-cw" style="width: 18px; height: 18px;"></i>
                    <span>Refund Policy</span>
                </a>
            </li>
        </ul>

    </div>

    <!-- User Sidebar Info Panel -->
    <div class="sidebar-footer">
        <?php 
        $sidebar_avatar = "https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&h=256&fit=crop";
        $disp_name = $logged_user_name ?? $_SESSION['user_name'] ?? 'Harsh Vardhan';
        $disp_photo = $logged_user_photo ?? $_SESSION['user_photo'] ?? null;
        $disp_role = $logged_user_role ?? $role;
        
        if (!empty($disp_photo) && file_exists(__DIR__ . '/../' . $disp_photo)) {
            $sidebar_avatar = $disp_photo;
        }
        ?>
        <img src="<?php echo htmlspecialchars($sidebar_avatar); ?>" alt="User Avatar" class="sidebar-avatar">
        <div class="user-info" style="display: flex; flex-direction: column;">
            <span class="font-semibold text-sm text-inverse"><?php echo htmlspecialchars($disp_name); ?></span>
            <span class="text-xs text-muted"><?php echo htmlspecialchars($disp_role); ?></span>
        </div>
    </div>
</aside>
