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

    // ----------------------------------------------------
    // Auto-Hiding & Collapsible Sidebar System
    // ----------------------------------------------------
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const autohideToggle = document.getElementById('sidebar-autohide-btn');
    
    // Auto-Hide is enabled by default (saved in localStorage)
    let isAutoHideEnabled = localStorage.getItem('sidebar_autohide_enabled') !== 'false';
    const AUTO_HIDE_SECONDS = 5; // 5 seconds of inactivity
    let sidebarTimer = null;
    
    function isSidebarOpen() {
        if (!sidebar) return false;
        const isMobile = window.innerWidth <= 1024;
        if (isMobile) {
            return sidebar.classList.contains('open');
        } else {
            return !sidebar.classList.contains('collapsed');
        }
    }

    function collapseSidebar() {
        if (!sidebar) return;
        const isMobile = window.innerWidth <= 1024;
        if (isMobile) {
            sidebar.classList.remove('open');
        } else {
            sidebar.classList.add('collapsed');
        }
        clearTimeout(sidebarTimer);
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 300);
    }

    function expandSidebar() {
        if (!sidebar) return;
        const isMobile = window.innerWidth <= 1024;
        if (isMobile) {
            sidebar.classList.add('open');
        } else {
            sidebar.classList.remove('collapsed');
        }
        resetSidebarTimer();
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 300);
    }

    function resetSidebarTimer() {
        clearTimeout(sidebarTimer);
        if (!isAutoHideEnabled) return;
        if (!isSidebarOpen()) return;

        // Auto-close after AUTO_HIDE_SECONDS without interaction
        sidebarTimer = setTimeout(() => {
            if (isSidebarOpen()) {
                collapseSidebar();
            }
        }, AUTO_HIDE_SECONDS * 1000);
    }

    function updateAutohideUI() {
        if (!autohideToggle) return;
        if (isAutoHideEnabled) {
            autohideToggle.classList.add('active');
            autohideToggle.title = 'Auto-Hide Active (5s Inactivity) - Click to Pin';
            autohideToggle.innerHTML = '<i data-lucide="timer" style="width: 15px; height: 15px;"></i>';
        } else {
            autohideToggle.classList.remove('active');
            autohideToggle.title = 'Sidebar Pinned (Always Open) - Click for Auto-Hide';
            autohideToggle.innerHTML = '<i data-lucide="pin" style="width: 15px; height: 15px;"></i>';
        }
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    if (sidebar) {
        // Reset timer on any user interaction inside sidebar
        ['mousemove', 'mouseenter', 'click', 'scroll', 'touchstart', 'keydown'].forEach(evt => {
            sidebar.addEventListener(evt, () => {
                if (isSidebarOpen()) {
                    resetSidebarTimer();
                }
            }, { passive: true });
        });

        // When mouse leaves sidebar, trigger/restart timer
        sidebar.addEventListener('mouseleave', () => {
            if (isSidebarOpen() && isAutoHideEnabled) {
                resetSidebarTimer();
            }
        });

        // Auto-close sidebar when clicking outside on main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.addEventListener('click', (e) => {
                if (isAutoHideEnabled && isSidebarOpen() && !e.target.closest('.sidebar-toggle') && !e.target.closest('.sidebar')) {
                    collapseSidebar();
                }
            });
        }

        // Initialize autohide UI state
        updateAutohideUI();
        if (isSidebarOpen() && isAutoHideEnabled) {
            resetSidebarTimer();
        }
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (isSidebarOpen()) {
                collapseSidebar();
            } else {
                expandSidebar();
            }
        });
    }

    if (autohideToggle) {
        autohideToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            isAutoHideEnabled = !isAutoHideEnabled;
            localStorage.setItem('sidebar_autohide_enabled', isAutoHideEnabled ? 'true' : 'false');
            updateAutohideUI();
            
            if (isAutoHideEnabled) {
                resetSidebarTimer();
                if (typeof window.alert === 'function') {
                    window.alert('⚡ Sidebar Auto-Hide Enabled (Auto-closes after 5s of inactivity)');
                }
            } else {
                clearTimeout(sidebarTimer);
                if (typeof window.alert === 'function') {
                    window.alert('📌 Sidebar Pinned (Will stay open)');
                }
            }
        });
    }

    window.toggleSidebarDropdown = function(e, element) {
        if (e.target.closest('.menu-chevron')) {
            e.preventDefault();
            e.stopPropagation();
            const parentLi = element.closest('.sidebar-dropdown');
            if (parentLi) {
                parentLi.classList.toggle('open');
            }
        }
    };

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

    // 4. Prevent auto-sync if user has selected rows/checkboxes or is doing Batch Actions
    const checkedRows = document.querySelectorAll('.lead-checkbox:checked, .client-checkbox:checked, input[type="checkbox"][name*="id"]:checked');
    if (checkedRows.length > 0) return true;

    // 5. Prevent auto-sync if batch action dropdown or employee menu is open/selected
    const batchActionSelect = document.getElementById('batch-action-select');
    if (batchActionSelect && batchActionSelect.value !== '') return true;
    const batchEmpMenu = document.getElementById('batch-emp-dropdown-menu');
    if (batchEmpMenu && !batchEmpMenu.classList.contains('hidden')) return true;

    return false;
}

function autoSyncPageData() {
    refreshDataWithoutReload(false);
}

/**
 * Universal Zero-Refresh Real-Time Database Auto-Sync Engine
 * Fetches latest database updates and updates DOM components dynamically without page reloads.
 */
function refreshDataWithoutReload(force = false) {
    if (!force && isUserFillingForm()) {
        return Promise.resolve(false);
    }

    return fetch(window.location.href, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' }
    })
    .then(response => {
        if (!response.ok) return null;
        return response.text();
    })
    .then(html => {
        if (!html) return false;
        
        if (!force && isUserFillingForm()) return false;

        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        let updatedAny = false;

        // Extract and execute inline script tags (e.g., window.dashboardChartData)
        const scripts = doc.querySelectorAll('script');
        scripts.forEach(script => {
            if (script.textContent && script.textContent.includes('dashboardChartData')) {
                try {
                    eval(script.textContent);
                } catch (e) {}
            }
        });

        // Container Selectors for Universal Auto-Sync across all pages
        const containerSelectors = [
            '.dashboard-container',
            '.kpi-grid',
            '.charts-grid',
            '.kpi-card',
            '.live-metric-cards-container',
            '.table-responsive',
            'tbody',
            '.grid-4',
            '.grid-3',
            '.grid-2',
            '.history-timeline',
            '.timeline',
            '.activity-feed',
            '.chat-messages',
            '.chat-list',
            '.notif-badge',
            '#unread-count',
            '.detail-card',
            '.lead-info',
            '.card-body-scroll',
            '#kpi-cards-wrapper',
            '#followups-list-container',
            '#leads-table-container'
        ];

        containerSelectors.forEach(selector => {
            const currentEls = document.querySelectorAll(selector);
            const newEls = doc.querySelectorAll(selector);

            currentEls.forEach((el, idx) => {
                if (newEls[idx] && el.innerHTML !== newEls[idx].innerHTML) {
                    // Remember checked items before replacement in table
                    const checkedValues = new Set(
                        Array.from(el.querySelectorAll('.lead-checkbox:checked, .client-checkbox:checked, input[type="checkbox"]:checked'))
                            .map(cb => cb.value)
                    );
                    const isSelectAllChecked = el.querySelector('#select-all-leads')?.checked;

                    el.innerHTML = newEls[idx].innerHTML;
                    updatedAny = true;

                    // Restore checked items
                    if (checkedValues.size > 0) {
                        el.querySelectorAll('.lead-checkbox, .client-checkbox, input[type="checkbox"]').forEach(cb => {
                            if (checkedValues.has(cb.value)) {
                                cb.checked = true;
                            }
                        });
                    }
                    if (isSelectAllChecked) {
                        const selectAll = el.querySelector('#select-all-leads');
                        if (selectAll) selectAll.checked = true;
                    }
                }
            });
        });

        // Sync individual count badges / metric spans matching id="cnt-*"
        const cntSpans = document.querySelectorAll('[id^="cnt-"]');
        cntSpans.forEach(span => {
            const newSpan = doc.querySelector('#' + CSS.escape(span.id));
            if (newSpan && span.innerHTML !== newSpan.innerHTML) {
                span.innerHTML = newSpan.innerHTML;
                updatedAny = true;
            }
        });

        // Check elements with data-auto-sync attribute or ID
        const autoSyncEls = document.querySelectorAll('[data-auto-sync]');
        autoSyncEls.forEach(el => {
            const syncId = el.getAttribute('data-auto-sync') || el.id;
            if (syncId) {
                const newEl = doc.querySelector(`[data-auto-sync="${syncId}"], #${syncId}`);
                if (newEl && el.innerHTML !== newEl.innerHTML) {
                    el.innerHTML = newEl.innerHTML;
                    updatedAny = true;
                }
            }
        });

        if (updatedAny) {
            // Re-initialize Lucide Icons, Column Preferences & Charts
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
            if (typeof loadDirColumnPreferences === 'function') {
                loadDirColumnPreferences();
            }
            if (typeof loadColumnPreferences === 'function') {
                loadColumnPreferences();
            }
            if (typeof window.initCRMCharts === 'function') {
                window.initCRMCharts();
            }
        }

        return updatedAny;
    })
    .catch(err => {
        // Silent catch for network drops
        return false;
    });
}

window.refreshDataWithoutReload = refreshDataWithoutReload;

// Ultra-Fast Auto-sync latest database records every 1 second (1000ms)
setInterval(autoSyncPageData, 1000);

/**
 * Easy Date-Time Picker Shortcut Enhancements
 */
window.applyQuickDT = function(btn, dateAction, timeStr) {
    const container = btn.closest('.quick-dt-presets-container');
    if (!container) return;
    const input = container.previousElementSibling || container.parentElement.querySelector('input[type="datetime-local"], input[type="date"]');
    if (!input) return;

    const now = new Date();
    let targetDate = new Date();

    // Preserve existing date/time if valid
    if (input.value) {
        let parsed = new Date(input.value);
        if (!isNaN(parsed.getTime())) {
            targetDate = parsed;
        }
    }

    if (dateAction === 'today') {
        targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate(), targetDate.getHours() || 10, targetDate.getMinutes() || 0);
    } else if (dateAction === 'tomorrow') {
        targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, targetDate.getHours() || 10, targetDate.getMinutes() || 0);
    } else if (dateAction === '+2days') {
        targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 2, targetDate.getHours() || 10, targetDate.getMinutes() || 0);
    } else if (dateAction === '+1hour') {
        targetDate = new Date(now.getTime() + 60 * 60 * 1000);
    } else if (dateAction === '+2hours') {
        targetDate = new Date(now.getTime() + 2 * 60 * 60 * 1000);
    }

    if (timeStr) {
        let parts = timeStr.split(':');
        targetDate.setHours(parseInt(parts[0], 10));
        targetDate.setMinutes(parseInt(parts[1], 10));
    }

    const year = targetDate.getFullYear();
    const month = String(targetDate.getMonth() + 1).padStart(2, '0');
    const day = String(targetDate.getDate()).padStart(2, '0');
    const hours = String(targetDate.getHours()).padStart(2, '0');
    const mins = String(targetDate.getMinutes()).padStart(2, '0');

    if (input.type === 'date') {
        input.value = `${year}-${month}-${day}`;
    } else {
        input.value = `${year}-${month}-${day}T${hours}:${mins}`;
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));

    // Brief animation feedback on clicked chip
    btn.style.transform = 'scale(0.92)';
    setTimeout(() => { btn.style.transform = ''; }, 150);
};

window.initEasyDateTimePickers = function() {
    const dtInputs = document.querySelectorAll('input[type="datetime-local"], input[type="date"]');
    dtInputs.forEach(input => {
        if (input.dataset.quickDtInit === 'true' || input.dataset.noQuick === 'true' || input.classList.contains('no-quick')) return;
        input.dataset.quickDtInit = 'true';

        const isDateOnly = (input.type === 'date');
        const container = document.createElement('div');
        container.className = 'quick-dt-presets-container';

        if (isDateOnly) {
            container.innerHTML = `
                <span class="quick-dt-label">Quick:</span>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, 'today')">📅 Today</button>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, 'tomorrow')">🌅 Tomorrow</button>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, '+2days')">⏩ +2 Days</button>
            `;
        } else {
            container.innerHTML = `
                <span class="quick-dt-label">Quick:</span>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, 'today')">📅 Today</button>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, 'tomorrow')">🌅 Tomorrow</button>
                <button type="button" class="quick-dt-chip" onclick="applyQuickDT(this, '+2days')">⏩ +2 Days</button>
                <button type="button" class="quick-dt-chip quick-dt-chip-time" onclick="applyQuickDT(this, null, '10:00')">🕘 10 AM</button>
                <button type="button" class="quick-dt-chip quick-dt-chip-time" onclick="applyQuickDT(this, null, '12:00')">🕛 12 PM</button>
                <button type="button" class="quick-dt-chip quick-dt-chip-time" onclick="applyQuickDT(this, null, '15:00')">🕒 3 PM</button>
                <button type="button" class="quick-dt-chip quick-dt-chip-time" onclick="applyQuickDT(this, null, '17:00')">🕔 5 PM</button>
                <button type="button" class="quick-dt-chip quick-dt-chip-plus" onclick="applyQuickDT(this, '+1hour')">⏳ +1 Hr</button>
            `;
        }

        if (input.nextSibling) {
            input.parentNode.insertBefore(container, input.nextSibling);
        } else {
            input.parentNode.appendChild(container);
        }
    });
};

// Automatically initialize date-time helper chips
document.addEventListener('DOMContentLoaded', initEasyDateTimePickers);
setInterval(initEasyDateTimePickers, 500);
