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
                <?php if ($role === 'Super Admin' || $role === 'Admin'): ?>
                    <li class="sidebar-item <?php echo isActivePage('clients') ? 'active' : ''; ?>">
                        <a href="index.php?page=clients">
                            <i data-lucide="building-2" style="width: 18px; height: 18px;"></i>
                            <span>Clients Directory</span>
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
        <?php if (hasAccess('demo', $role) || hasAccess('quotation', $role) || hasAccess('payments', $role)): ?>
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

        <!-- Managerial & Admin Controls -->
        <?php if ($role === 'Super Admin' || $role === 'Admin' || $role === 'Regional Manager' || $role === 'Team Leader'): ?>
            <div class="menu-group-title">Management</div>
            <ul>
                <li class="sidebar-item <?php echo isActivePage('admin_users'); ?>">
                    <a href="index.php?page=admin_users">
                        <i data-lucide="users-round" style="width: 18px; height: 18px;"></i>
                        <span>Manage Users</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo isActivePage('admin_permissions'); ?>">
                    <a href="index.php?page=admin_permissions">
                        <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                        <span>Employee Permissions</span>
                    </a>
                </li>
                <li class="sidebar-item <?php echo isActivePage('admin_reports'); ?>">
                    <a href="index.php?page=admin_reports">
                        <i data-lucide="bar-chart-3" style="width: 18px; height: 18px;"></i>
                        <span>Business Reports</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <!-- Settings Group -->
        <?php if ($role === 'Super Admin' || $role === 'Admin'): ?>
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
