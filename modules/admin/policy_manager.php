<?php
/**
 * SaaS Super Admin Policy Manager - Friendly AI Solution
 * Allows Super Admin and Admin roles to view, add, edit, toggle, and delete
 * points for Privacy Policy, Terms & Conditions (with Continuation Policy), and Refund Policy.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/policy_helper.php';

// Access Control: Only Super Admin and Admin
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['Super Admin', 'Admin'])) {
    echo '<div class="card p-6 text-center" style="max-width: 500px; margin: 4rem auto; border: 1px solid var(--border-color);">';
    echo '<i data-lucide="shield-alert" style="width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1.5rem auto;"></i>';
    echo '<h2 class="mb-2" style="font-family: var(--font-heading);">Access Restricted</h2>';
    echo '<p class="text-muted mb-4">Only SaaS Super Administrators can manage public legal and policy points.</p>';
    echo '<a href="index.php?page=dashboard" class="btn btn-primary">Return to Dashboard</a>';
    echo '</div>';
    return;
}

// Handle Form Submissions (Add, Edit, Delete, Toggle, Seed Defaults)
$msg = null;
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $msg = "Security token mismatch. Please refresh and try again.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_point') {
            $data = [
                'id' => $_POST['point_id'] ?? 0,
                'page_type' => $_POST['page_type'] ?? 'terms',
                'section_number' => (int)($_POST['section_number'] ?? 1),
                'section_title' => trim($_POST['section_title'] ?? ''),
                'section_badge' => trim($_POST['section_badge'] ?? ''),
                'icon' => trim($_POST['icon'] ?? 'shield-check'),
                'content' => trim($_POST['content'] ?? ''),
                'sort_order' => (int)($_POST['sort_order'] ?? 10),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            $res = save_policy_point($data);
            if ($res['success']) {
                $msg = $res['message'];
                $msg_type = "success";
            } else {
                $msg = $res['message'];
                $msg_type = "danger";
            }
        } elseif ($action === 'delete_point') {
            $point_id = (int)($_POST['point_id'] ?? 0);
            $res = delete_policy_point($point_id);
            $msg = $res['message'];
            $msg_type = $res['success'] ? "success" : "danger";
        } elseif ($action === 'toggle_status') {
            $point_id = (int)($_POST['point_id'] ?? 0);
            $res = toggle_policy_point_status($point_id);
            $msg = $res['message'];
            $msg_type = $res['success'] ? "success" : "danger";
        } elseif ($action === 'reset_defaults') {
            seed_default_policy_points(true);
            $msg = "All policy pages have been successfully reset to standard enterprise defaults!";
            $msg_type = "success";
        }
    }
}

// Determine Active Tab
$active_tab = $_GET['tab'] ?? 'terms';
if (!in_array($active_tab, ['privacy', 'terms', 'refund'])) {
    $active_tab = 'terms';
}

// Fetch Points for Current Tab (include inactive for admin)
$points = get_policy_points($active_tab, false);

$tab_titles = [
    'terms' => 'Terms & Conditions (with Continuation Policy)',
    'privacy' => 'Privacy Policy (Meta & DPDP Compliant)',
    'refund' => 'Refund & Cancellation Policy'
];

$public_urls = [
    'terms' => 'terms.php',
    'privacy' => 'privacy.php',
    'refund' => 'refund.php'
];
?>

<div class="main-content-header mb-6">
    <div>
        <div class="flex align-center gap-2 mb-1">
            <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);">
                <i data-lucide="shield-check" style="width: 13px; height: 13px; vertical-align: -2px; margin-right: 4px;"></i>
                SaaS Super Admin Master Control
            </span>
            <span class="text-xs text-muted">Real-Time Sync with Public Portal</span>
        </div>
        <h1 class="text-2xl font-bold" style="font-family: var(--font-heading);">Legal & Compliance Policy Manager</h1>
        <p class="text-sm text-muted">Dynamically add, edit, reorganize, or disable policy clauses for public portal visitors and Meta compliance verification.</p>
    </div>
    <div class="flex gap-3 align-center flex-wrap">
        <a href="<?php echo $public_urls[$active_tab]; ?>" target="_blank" class="btn btn-secondary text-sm flex align-center gap-2" title="View Public Page">
            <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
            <span>Preview Public URL</span>
        </a>
        <button type="button" onclick="openAddPointModal()" class="btn btn-primary text-sm flex align-center gap-2">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Add New Policy Point</span>
        </button>
    </div>
</div>

<?php if ($msg): ?>
    <div class="p-4 mb-6 rounded-lg flex align-center justify-between" style="background: <?php echo $msg_type === 'success' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border: 1px solid <?php echo $msg_type === 'success' ? '#10b981' : '#ef4444'; ?>; color: <?php echo $msg_type === 'success' ? '#10b981' : '#ef4444'; ?>;">
        <div class="flex align-center gap-2 text-sm font-medium">
            <i data-lucide="<?php echo $msg_type === 'success' ? 'check-circle' : 'alert-triangle'; ?>" style="width: 18px; height: 18px;"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: inherit;">&times;</button>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="flex align-center justify-between gap-4 mb-6 pb-2" style="border-bottom: 2px solid var(--border-color); flex-wrap: wrap;">
    <div class="flex gap-2">
        <a href="index.php?page=policy_manager&tab=terms" class="btn text-sm <?php echo $active_tab === 'terms' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius: 8px 8px 0 0;">
            <i data-lucide="file-text" style="width: 16px; height: 16px; margin-right: 6px;"></i>
            <span>Terms & Conditions (<?php echo count(get_policy_points('terms', false)); ?>)</span>
        </a>
        <a href="index.php?page=policy_manager&tab=privacy" class="btn text-sm <?php echo $active_tab === 'privacy' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius: 8px 8px 0 0;">
            <i data-lucide="lock" style="width: 16px; height: 16px; margin-right: 6px;"></i>
            <span>Privacy Policy (<?php echo count(get_policy_points('privacy', false)); ?>)</span>
        </a>
        <a href="index.php?page=policy_manager&tab=refund" class="btn text-sm <?php echo $active_tab === 'refund' ? 'btn-primary' : 'btn-secondary'; ?>" style="border-radius: 8px 8px 0 0;">
            <i data-lucide="refresh-cw" style="width: 16px; height: 16px; margin-right: 6px;"></i>
            <span>Refund Policy (<?php echo count(get_policy_points('refund', false)); ?>)</span>
        </a>
    </div>
    <div>
        <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to restore all policy points to standard system defaults? Custom changes will be reset.');" style="display: inline;">
            <?php echo renderCsrfInput(); ?>
            <input type="hidden" name="action" value="reset_defaults">
            <button type="submit" class="btn btn-secondary text-xs text-danger flex align-center gap-1" title="Restore all default points">
                <i data-lucide="rotate-ccw" style="width: 14px; height: 14px;"></i>
                <span>Reset All Defaults</span>
            </button>
        </form>
    </div>
</div>

<!-- Points List Container -->
<div class="card p-6 mb-6" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);">
    <div class="flex align-center justify-between pb-4 mb-4" style="border-bottom: 1px solid var(--border-color);">
        <div>
            <h3 class="font-bold text-lg mb-1" style="font-family: var(--font-heading);"><?php echo $tab_titles[$active_tab]; ?></h3>
            <p class="text-xs text-muted">Currently active on live URL: <a href="<?php echo $public_urls[$active_tab]; ?>" target="_blank" class="text-primary font-semibold"><?php echo $public_urls[$active_tab]; ?></a></p>
        </div>
        <div class="text-xs text-muted">
            Total Clauses: <strong><?php echo count($points); ?></strong>
        </div>
    </div>

    <?php if (empty($points)): ?>
        <div class="text-center py-12 text-muted">
            <i data-lucide="file-question" style="width: 48px; height: 48px; margin: 0 auto 1rem auto; opacity: 0.5;"></i>
            <p class="mb-3">No points found for this policy yet.</p>
            <button type="button" onclick="openAddPointModal()" class="btn btn-primary btn-sm">Add First Point</button>
        </div>
    <?php else: ?>
        <div class="flex flex-col gap-4">
            <?php foreach ($points as $pt): ?>
                <div class="p-5 rounded-lg transition-all" style="background: <?php echo $pt['is_active'] ? 'var(--border-card, #f8fafc)' : 'rgba(239, 68, 68, 0.05)'; ?>; border: 1px solid <?php echo $pt['is_active'] ? 'var(--border-color)' : '#fca5a5'; ?>; border-radius: var(--border-radius-md); opacity: <?php echo $pt['is_active'] ? '1' : '0.75'; ?>;">
                    <div class="flex align-start justify-between gap-4">
                        <div class="flex align-start gap-3">
                            <div style="background: rgba(37, 99, 235, 0.1); color: #2563eb; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i data-lucide="<?php echo htmlspecialchars($pt['icon'] ?: 'shield-check'); ?>" style="width: 20px; height: 20px;"></i>
                            </div>
                            <div>
                                <div class="flex align-center gap-2 mb-1 flex-wrap">
                                    <span class="font-bold text-base text-main">
                                        <?php echo htmlspecialchars($pt['section_number']); ?>. <?php echo htmlspecialchars($pt['section_title']); ?>
                                    </span>
                                    <?php if (!empty($pt['section_badge'])): ?>
                                        <span class="badge text-xs" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; padding: 2px 8px; border-radius: 50px;">
                                            <?php echo htmlspecialchars($pt['section_badge']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-xs text-muted" title="Display order">Sort Order: #<?php echo (int)$pt['sort_order']; ?></span>
                                    <?php if (!$pt['is_active']): ?>
                                        <span class="badge text-xs" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">Hidden / Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-muted leading-relaxed mt-2" style="max-height: 120px; overflow-y: auto;">
                                    <?php echo $pt['content']; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex align-center gap-2 flex-shrink-0">
                            <!-- Toggle Active/Inactive Form -->
                            <form method="POST" style="display: inline;">
                                <?php echo renderCsrfInput(); ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="point_id" value="<?php echo $pt['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo $pt['is_active'] ? 'Hide from public' : 'Show on public'; ?>" style="padding: 6px 10px;">
                                    <i data-lucide="<?php echo $pt['is_active'] ? 'eye-off' : 'eye'; ?>" style="width: 14px; height: 14px;"></i>
                                </button>
                            </form>

                            <!-- Edit Button -->
                            <button type="button" onclick="openEditPointModal(<?php echo htmlspecialchars(json_encode($pt), ENT_QUOTES, 'UTF-8'); ?>)" class="btn btn-secondary btn-sm" title="Edit point" style="padding: 6px 10px;">
                                <i data-lucide="edit-3" style="width: 14px; height: 14px; color: #2563eb;"></i>
                            </button>

                            <!-- Delete Form -->
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this clause permanently?');" style="display: inline;">
                                <?php echo renderCsrfInput(); ?>
                                <input type="hidden" name="action" value="delete_point">
                                <input type="hidden" name="point_id" value="<?php echo $pt['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm text-danger" title="Delete clause" style="padding: 6px 10px;">
                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Add / Edit Policy Point -->
<div id="pointModal" class="lead-modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 1050; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="card p-6" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); width: 100%; max-width: 650px; max-height: 90vh; overflow-y: auto; position: relative;">
        
        <button type="button" onclick="closePointModal()" style="position: absolute; top: 1.25rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: var(--text-muted);">&times;</button>
        
        <div class="mb-4">
            <h3 id="modalTitle" class="font-bold text-lg" style="font-family: var(--font-heading);">Add New Policy Clause</h3>
            <p class="text-xs text-muted">Add comprehensive terms, continuation conditions, or privacy rules to publish live.</p>
        </div>

        <form method="POST">
            <?php echo renderCsrfInput(); ?>
            <input type="hidden" name="action" value="save_point">
            <input type="hidden" name="point_id" id="modal_point_id" value="0">

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-main">Target Policy Page *</label>
                    <select name="page_type" id="modal_page_type" class="form-control" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-input, transparent);" required>
                        <option value="terms" <?php echo $active_tab === 'terms' ? 'selected' : ''; ?>>Terms & Conditions (terms.php)</option>
                        <option value="privacy" <?php echo $active_tab === 'privacy' ? 'selected' : ''; ?>>Privacy Policy (privacy.php)</option>
                        <option value="refund" <?php echo $active_tab === 'refund' ? 'selected' : ''; ?>>Refund Policy (refund.php)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-main">Clause Number *</label>
                    <input type="number" name="section_number" id="modal_section_number" class="form-control" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px;" value="<?php echo count($points) + 1; ?>" min="1" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1 text-main">Clause Title *</label>
                <input type="text" name="section_title" id="modal_section_title" class="form-control" placeholder="e.g. Messaging Continuation & Auto-Renewal Policy" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px;" required>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-main">Badge / Pill Tag</label>
                    <input type="text" name="section_badge" id="modal_section_badge" class="form-control" placeholder="e.g. Continuation Policy" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1 text-main">Lucide Icon Name</label>
                    <input type="text" name="icon" id="modal_icon" class="form-control" placeholder="shield-check, database, layers..." value="shield-check" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1 text-main">Clause Body Content (HTML supported) *</label>
                <textarea name="content" id="modal_content" rows="6" class="form-control" placeholder="<p>Detailed policy description...</p><ul><li>Sub-point 1</li><li>Sub-point 2</li></ul>" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px; font-family: monospace; font-size: 0.85rem;" required></textarea>
                <p class="text-xs text-muted mt-1">You can use standard HTML formatting tags like <code>&lt;p&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;li&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>.</p>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-semibold mb-1 text-main">Display Sort Order</label>
                    <input type="number" name="sort_order" id="modal_sort_order" class="form-control" value="10" step="5" style="width: 100%; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>
                <div class="flex align-center" style="margin-top: 1.5rem;">
                    <label class="flex align-center gap-2 text-xs font-semibold text-main cursor-pointer">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 16px; height: 16px;">
                        <span>Visible on Public Live Page</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4" style="border-top: 1px solid var(--border-color);">
                <button type="button" onclick="closePointModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary flex align-center gap-2">
                    <i data-lucide="check" style="width: 16px; height: 16px;"></i>
                    <span id="modalSubmitText">Save Policy Clause</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddPointModal() {
    document.getElementById('modalTitle').innerText = 'Add New Policy Clause';
    document.getElementById('modalSubmitText').innerText = 'Add Policy Clause';
    document.getElementById('modal_point_id').value = '0';
    document.getElementById('modal_page_type').value = '<?php echo $active_tab; ?>';
    document.getElementById('modal_section_number').value = '<?php echo count($points) + 1; ?>';
    document.getElementById('modal_section_title').value = '';
    document.getElementById('modal_section_badge').value = '';
    document.getElementById('modal_icon').value = 'shield-check';
    document.getElementById('modal_content').value = '';
    document.getElementById('modal_sort_order').value = '<?php echo (count($points) + 1) * 10; ?>';
    document.getElementById('modal_is_active').checked = true;

    const m = document.getElementById('pointModal');
    m.style.display = 'flex';
    if (window.lucide) lucide.createIcons();
}

function openEditPointModal(pt) {
    document.getElementById('modalTitle').innerText = 'Edit Policy Clause (ID: #' + pt.id + ')';
    document.getElementById('modalSubmitText').innerText = 'Update Policy Clause';
    document.getElementById('modal_point_id').value = pt.id;
    document.getElementById('modal_page_type').value = pt.page_type;
    document.getElementById('modal_section_number').value = pt.section_number;
    document.getElementById('modal_section_title').value = pt.section_title;
    document.getElementById('modal_section_badge').value = pt.section_badge || '';
    document.getElementById('modal_icon').value = pt.icon || 'shield-check';
    document.getElementById('modal_content').value = pt.content;
    document.getElementById('modal_sort_order').value = pt.sort_order;
    document.getElementById('modal_is_active').checked = (parseInt(pt.is_active) === 1);

    const m = document.getElementById('pointModal');
    m.style.display = 'flex';
    if (window.lucide) lucide.createIcons();
}

function closePointModal() {
    document.getElementById('pointModal').style.display = 'none';
}

// Close when clicking background overlay
window.addEventListener('click', function(e) {
    const m = document.getElementById('pointModal');
    if (e.target === m) {
        closePointModal();
    }
});
</script>