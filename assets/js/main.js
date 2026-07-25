/**
 * Marg ERP CRM - Global Frontend Interactions
 */

// Overwrite native browser localhost alert() with a custom non-blocking CRM toast banner
window.alert = function(msg) {
    if (!msg) return;
    let toastContainer = document.getElementById('crm-global-toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'crm-global-toast-container';
        toastContainer.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; max-width: 420px; pointer-events: none;';
        document.body.appendChild(toastContainer);
    }
    
    const toast = document.createElement('div');
    toast.style.cssText = 'background: var(--bg-card, #1e293b); color: var(--text-main, #f8fafc); border: 1px solid var(--primary, #3b82f6); padding: 12px 18px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 10px; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; pointer-events: auto;';
    toast.innerHTML = '<i data-lucide="info" style="width: 18px; height: 18px; color: var(--primary, #3b82f6); flex-shrink: 0;"></i><span>' + String(msg).replace(/\n/g, '<br>') + '</span>';
    
    toastContainer.appendChild(toast);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    }, 10);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
};

function initMainApp() {
    // Initial Theme Setup
    const htmlEl = document.documentElement;
    const currentTheme = localStorage.getItem('theme') || 'dark';
    htmlEl.setAttribute('data-theme', currentTheme);

    // Initialize Lucide Icons if available (failsafe check)
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // Collapsible Sidebar
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            sidebar.classList.toggle('open');
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
            }, 300);
        });
    }

    // Toggle Dropdowns (Profile menu, Notifications, etc.)
    const dropdownTriggers = document.querySelectorAll('.dropdown-trigger');
    dropdownTriggers.forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const targetId = trigger.getAttribute('data-dropdown');
            const targetMenu = document.getElementById(targetId);
            
            // Close other open dropdowns first
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                if (menu !== targetMenu) {
                    menu.classList.remove('show');
                }
            });

            if (targetMenu) {
                targetMenu.classList.toggle('show');
            }
        });
    });

    // Theme Switch Toggler
    const themeToggleBtn = document.querySelector('.theme-toggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const activeTheme = htmlEl.getAttribute('data-theme');
            const newTheme = activeTheme === 'light' ? 'dark' : 'light';
            
            htmlEl.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = themeToggleBtn.querySelector('i');
            if (icon) {
                icon.setAttribute('data-lucide', newTheme === 'light' ? 'moon' : 'sun');
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            }
        });
    }

    // Global Search Modal Toggle
    const searchTrigger = document.querySelector('.global-search-trigger');
    const searchModal = document.getElementById('global-search-modal');
    const closeSearchBtn = document.getElementById('close-search-modal');

    const openSearch = () => {
        if (searchModal) {
            searchModal.classList.add('open');
            const input = searchModal.querySelector('input');
            if (input) setTimeout(() => input.focus(), 150);
        }
    };

    const closeSearch = () => {
        if (searchModal) {
            searchModal.classList.remove('open');
        }
    };

    if (searchTrigger) searchTrigger.addEventListener('click', openSearch);
    if (closeSearchBtn) closeSearchBtn.addEventListener('click', closeSearch);

    // Dynamic Live Global Search Execution
    const searchModalInput = searchModal ? searchModal.querySelector('input') : null;
    const searchResultsSection = searchModal ? searchModal.querySelector('.search-results-section .results-list') : null;

    if (searchModalInput && searchResultsSection) {
        searchModalInput.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length === 0) return;

            fetch('index.php?action=global_search&q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.results) {
                    if (data.results.length === 0) {
                        searchResultsSection.innerHTML = '<div class="p-3 text-xs text-muted text-center">No leads or records found for "' + query + '"</div>';
                    } else {
                        let html = '';
                        data.results.forEach(item => {
                            const pClass = item.priority === 'hot' ? 'danger' : (item.priority === 'warm' ? 'warning' : 'secondary');
                            html += `
                                <a href="index.php?page=lead_details&id=${item.id}" class="result-item flex align-center justify-between pointer" style="padding: 0.75rem 1rem; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.5rem; text-decoration: none;">
                                    <div class="flex align-center gap-3">
                                        <i data-lucide="user" class="text-muted" style="width: 18px; height: 18px;"></i>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-semibold" style="color: var(--text-main);">${item.name} (${item.phone})</span>
                                            <span class="text-xs text-muted">ID: #${item.id} • ${item.company}</span>
                                        </div>
                                    </div>
                                    <span class="badge" style="--badge-bg: var(--${pClass}-light); --badge-color: var(--${pClass}); text-transform: uppercase;">${item.priority}</span>
                                </a>
                            `;
                        });
                        searchResultsSection.innerHTML = html;
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }
                }
            })
            .catch(err => console.error(err));
        });
    }

    // Keyboard Shortcuts (Ctrl + K for Global Search)
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openSearch();
        }
        if (e.key === 'Escape') {
            closeSearch();
            document.querySelectorAll('.modal-overlay.open').forEach(modal => {
                modal.classList.remove('open');
            });
        }
    });

    // Handle profile switcher selection change
    const roleSelector = document.getElementById('global-role-switcher');
    if (roleSelector) {
        roleSelector.addEventListener('change', () => {
            const chosenRole = roleSelector.value;
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 'dashboard';
            window.location.href = window.location.pathname + '?action=switch_role&role=' + encodeURIComponent(chosenRole) + '&page=' + encodeURIComponent(page);
        });
    }

    // Click Outside handler to close modals & dropdowns
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dropdown-trigger') && !e.target.closest('.dropdown-menu')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
            e.target.classList.add('hidden');
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMainApp);
} else {
    initMainApp();
}

// Modal helper controls
window.openModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('open');
        modal.classList.remove('hidden');
    }
};

window.closeModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('open');
    }
};

// --------------------------------------------------------------------------
// Background Live Data Auto-Sync System (Fast & Silent)
// --------------------------------------------------------------------------
let isTypingInForm = false;

// Track user typing activity across all forms
document.addEventListener('input', function(e) {
    if (e.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
        isTypingInForm = true;
        clearTimeout(window._typingTimer);
        window._typingTimer = setTimeout(() => {
            if (document.activeElement !== e.target) {
                isTypingInForm = false;
            }
        }, 5000);
    }
}, true);

document.addEventListener('focusout', function(e) {
    if (e.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
        setTimeout(() => {
            const active = document.activeElement;
            if (!active || !['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) {
                isTypingInForm = false;
            }
        }, 300);
    }
}, true);

function isUserFillingForm() {
    // 1. Check if any modal overlay is currently open
    const openModals = document.querySelectorAll('.modal-overlay.open, .modal.open');
    if (openModals.length > 0) return true;

    // 2. Check if user is currently focused on an input, textarea or select
    const active = document.activeElement;
    if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) {
        return true;
    }

    // 3. Check typing state
    if (isTypingInForm) return true;

    return false;
}

function autoSyncPageData() {
    // Strictly skip updating if user is filling out a form or modal is open
    if (isUserFillingForm()) {
        return;
    }

    // Fetch latest page state silently from database
    fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
    })
    .then(response => {
        if (!response.ok) return null;
        return response.text();
    })
    .then(html => {
        if (!html) return;
        
        // Double check user state before applying DOM changes
        if (isUserFillingForm()) return;

        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // 1. Update Table Containers silently
        const currentTables = document.querySelectorAll('.table-responsive');
        const newTables = doc.querySelectorAll('.table-responsive');

        currentTables.forEach((table, idx) => {
            if (newTables[idx]) {
                if (table.innerHTML !== newTables[idx].innerHTML) {
                    table.innerHTML = newTables[idx].innerHTML;
                }
            }
        });

        // 2. Update KPI Metric Cards silently
        const currentKpi = document.querySelectorAll('.grid-4');
        const newKpi = doc.querySelectorAll('.grid-4');

        currentKpi.forEach((kpi, idx) => {
            if (newKpi[idx]) {
                if (kpi.innerHTML !== newKpi[idx].innerHTML) {
                    kpi.innerHTML = newKpi[idx].innerHTML;
                }
            }
        });

        // 3. Re-initialize Lucide Icons & Page Preferences
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        if (typeof loadDirColumnPreferences === 'function') {
            loadDirColumnPreferences();
        }
    })
    .catch(err => {
        // Silent catch for network drops
    });
}

// Ultra-Fast Auto-sync latest database records every 1.5 seconds (1500ms)
setInterval(autoSyncPageData, 1500);
