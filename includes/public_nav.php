<?php
/**
 * Shared Modern Public Navigation Bar - Friendly AI Solution
 */
$current_page = $requested_page ?? ($_GET['page'] ?? 'home');
if ($current_page === '' || $current_page === 'landing' || $current_page === 'public') {
    $current_page = 'home';
}
$is_logged_in = isset($_SESSION['user_id']);
?>
<!-- Public Top Sticky Navbar -->
<nav class="public-navbar">
    <div class="public-container navbar-inner">
        <!-- Brand Logo -->
        <a href="index.php" class="brand-logo" title="Friendly AI Solution - Marg ERP & WhatsApp Automation">
            <div class="brand-logo-icon">
                <i data-lucide="layers"></i>
            </div>
            <div class="brand-logo-text">
                <span class="brand-title">FRIENDLY AI</span>
                <span class="brand-subtitle">SOLUTION</span>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <ul class="nav-menu">
            <li>
                <a href="index.php" class="nav-item <?php echo ($current_page === 'home') ? 'active' : ''; ?>">
                    <span>Home</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=features" class="nav-item <?php echo ($current_page === 'features') ? 'active' : ''; ?>">
                    <span>Features</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=whatsapp" class="nav-item <?php echo ($current_page === 'whatsapp') ? 'active' : ''; ?>">
                    <span class="nav-link-flex">
                        WhatsApp API 
                        <span class="nav-badge-pill">Meta Official</span>
                    </span>
                </a>
            </li>
            <li>
                <a href="index.php?page=pricing" class="nav-item <?php echo ($current_page === 'pricing') ? 'active' : ''; ?>">
                    <span>Pricing</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=contact" class="nav-item <?php echo ($current_page === 'contact') ? 'active' : ''; ?>">
                    <span>Contact</span>
                </a>
            </li>
        </ul>

        <!-- Right Side Action Buttons -->
        <div class="nav-actions">
            <?php if ($is_logged_in): ?>
                <a href="index.php?page=dashboard" class="btn-brand btn-brand-primary">
                    <i data-lucide="layout-dashboard" style="width: 16px; height: 16px;"></i>
                    <span>Go to Dashboard</span>
                </a>
            <?php else: ?>
                <a href="auth/login.php" class="btn-brand btn-brand-ghost">
                    <i data-lucide="log-in" style="width: 15px; height: 15px;"></i>
                    <span>Sign In</span>
                </a>
                <button type="button" onclick="openLeadModal('Nav Header CTA')" class="btn-brand btn-brand-primary">
                    <span>Book a Demo</span>
                    <i data-lucide="arrow-right" style="width: 15px; height: 15px;"></i>
                </button>
            <?php endif; ?>

            <!-- Mobile Hamburger Toggle -->
            <button class="mobile-toggle-btn" onclick="toggleMobileNav()" aria-label="Toggle Mobile Navigation">
                <i data-lucide="menu" id="mobile-nav-icon"></i>
            </button>
        </div>
    </div>

    <!-- Mobile Slide-out Menu -->
    <div class="mobile-nav-drawer" id="mobileNavDrawer">
        <ul class="mobile-nav-list">
            <li>
                <a href="index.php" class="mobile-nav-item <?php echo ($current_page === 'home') ? 'active' : ''; ?>">
                    <i data-lucide="home"></i>
                    <span>Home</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=features" class="mobile-nav-item <?php echo ($current_page === 'features') ? 'active' : ''; ?>">
                    <i data-lucide="sparkles"></i>
                    <span>Features & Solutions</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=whatsapp" class="mobile-nav-item <?php echo ($current_page === 'whatsapp') ? 'active' : ''; ?>">
                    <i data-lucide="message-square"></i>
                    <span>WhatsApp Cloud API</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=pricing" class="mobile-nav-item <?php echo ($current_page === 'pricing') ? 'active' : ''; ?>">
                    <i data-lucide="tag"></i>
                    <span>Pricing Plans</span>
                </a>
            </li>
            <li>
                <a href="index.php?page=contact" class="mobile-nav-item <?php echo ($current_page === 'contact') ? 'active' : ''; ?>">
                    <i data-lucide="mail"></i>
                    <span>Contact Us</span>
                </a>
            </li>
            <li class="mobile-drawer-cta">
                <?php if ($is_logged_in): ?>
                    <a href="index.php?page=dashboard" class="btn-brand btn-brand-primary w-full text-center" style="display:flex; justify-content:center;">
                        Go to Dashboard
                    </a>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; width: 100%;">
                        <a href="auth/login.php" class="btn-brand btn-brand-ghost w-full" style="display:flex; justify-content:center;">Sign In</a>
                        <button type="button" onclick="toggleMobileNav(); openLeadModal('Mobile Drawer CTA');" class="btn-brand btn-brand-primary w-full" style="display:flex; justify-content:center;">
                            Book a Live Demo
                        </button>
                    </div>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</nav>
