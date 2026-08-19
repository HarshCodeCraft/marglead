<?php
/**
 * Marg ERP CRM - Executive Customer Ratings & Reviews Console
 * Allows System Admin to moderate, edit, create, or delete public customer reviews.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$user_role = $_SESSION['user_role'] ?? '';
$tenant_db = $_SESSION['tenant_db'] ?? (defined('DB_NAME') ? DB_NAME : 'u978772385_friendlyaidata');

// Access Check
if (!in_array($user_role, ['Super Admin', 'Admin'])) {
    echo "<div class='card p-6 text-center' style='max-width: 500px; margin: 4rem auto; border: 1px solid var(--danger); background: var(--bg-card);'>
        <i data-lucide='shield-alert' style='width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1rem auto;'></i>
        <h3 class='text-lg font-bold mb-2' style='color: var(--danger);'>Access Denied</h3>
        <p class='text-muted text-sm mb-4'>The Customer Ratings management console is reserved for System Administrators.</p>
        <a href='index.php?page=dashboard' class='btn btn-primary text-xs'>Return to Workspace Dashboard</a>
    </div>";
    return;
}

$flash_msg = '';
$flash_type = '';

// Handle Actions (Create / Edit / Delete Review)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_review') {
        $revId = intval($_POST['review_id'] ?? 0);
        $rName = trim($_POST['name'] ?? '');
        $rCompany = trim($_POST['company'] ?? '');
        $rCity = trim($_POST['city'] ?? '');
        $rRating = floatval($_POST['rating'] ?? 5.0);
        $rService = trim($_POST['service_name'] ?? 'Marg ERP 9+');
        $rStatus = $_POST['status'] ?? 'Approved';
        $rText = trim($_POST['review_text'] ?? '');

        if (!empty($rName) && !empty($rText)) {
            try {
                if ($revId > 0) {
                    // Update existing
                    $stmtUpd = $pdo->prepare("UPDATE customer_reviews SET name = ?, company = ?, city = ?, rating = ?, service_name = ?, status = ?, review_text = ? WHERE id = ?");
                    $stmtUpd->execute([$rName, $rCompany, $rCity, $rRating, $rService, $rStatus, $rText, $revId]);
                    $flash_msg = "Customer review updated successfully!";
                } else {
                    // Create new
                    $stmtIns = $pdo->prepare("INSERT INTO customer_reviews (name, company, city, rating, service_name, status, review_text, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'Admin Created')");
                    $stmtIns->execute([$rName, $rCompany, $rCity, $rRating, $rService, $rStatus, $rText]);
                    $flash_msg = "New customer review added successfully!";
                }
                $flash_type = "success";
                if (function_exists('logActivity')) {
                    logActivity($revId > 0 ? 'REVIEW_EDIT' : 'REVIEW_CREATE', 'Customer Reviews', "Review ID $revId ($rName)");
                }
            } catch (PDOException $e) {
                $flash_msg = "Error saving review: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    } elseif ($_POST['action'] === 'delete_review') {
        $revId = intval($_POST['review_id'] ?? 0);
        if ($revId > 0) {
            try {
                $stmtDel = $pdo->prepare("DELETE FROM customer_reviews WHERE id = ?");
                $stmtDel->execute([$revId]);
                $flash_msg = "Customer review deleted permanently.";
                $flash_type = "success";
                if (function_exists('logActivity')) {
                    logActivity('REVIEW_DELETE', 'Customer Reviews', "Deleted review ID $revId");
                }
            } catch (PDOException $e) {
                $flash_msg = "Error deleting review: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// Fetch all reviews and calculate statistics
$reviews = [];
$total_count = 0;
$avg_rating = 5.0;
$approved_count = 0;
$pending_count = 0;

try {
    $stmtR = $pdo->query("SELECT * FROM customer_reviews ORDER BY id DESC");
    $reviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    $total_count = count($reviews);

    if ($total_count > 0) {
        $sum_rating = 0;
        foreach ($reviews as $rev) {
            $sum_rating += floatval($rev['rating']);
            if ($rev['status'] === 'Approved') $approved_count++;
            if ($rev['status'] === 'Pending') $pending_count++;
        }
        $avg_rating = number_format($sum_rating / $total_count, 2);
    }
} catch (PDOException $e) {
    $reviews = [];
}
?>

<div class="reviews-admin-container flex flex-col gap-6">
    <!-- Flash Notification Banner -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> p-4 border-radius-md flex align-center gap-3" style="background: var(--<?php echo $flash_type; ?>-light); border: 1px solid var(--<?php echo $flash_type; ?>); color: var(--<?php echo $flash_type; ?>);">
            <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
            <span class="text-sm font-semibold"><?php echo htmlspecialchars($flash_msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Page Header & Action Bar -->
    <div class="flex justify-between align-center flex-wrap gap-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1.25rem;">
        <div>
            <div class="flex align-center gap-2 mb-1">
                <span class="badge text-xs" style="--badge-bg: rgba(245, 158, 11, 0.12); --badge-color: #f59e0b; font-weight: 700;">
                    ⭐ Customer Testimonials & Ratings
                </span>
            </div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--text-main);" class="m-0">
                Customer Ratings Console
            </h2>
            <p class="text-muted text-xs mt-1">Moderate, edit, approve, or delete Google Maps style reviews displayed on the public landing page.</p>
        </div>

        <div class="flex align-center gap-3">
            <button type="button" class="btn btn-primary text-xs flex align-center gap-2" onclick="openCreateReviewModal()" style="border-radius: 9999px; padding: 0.65rem 1.25rem;">
                <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                <span>Add Customer Review</span>
            </button>
            <a href="landing.php#ratings" target="_blank" class="btn btn-secondary text-xs flex align-center gap-2" style="border-radius: 9999px; padding: 0.65rem 1.25rem;">
                <i data-lucide="external-link" style="width: 15px; height: 15px;"></i>
                <span>Live Landing Carousel</span>
            </a>
        </div>
    </div>

    <!-- Executive Metric Summary Cards -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(59, 130, 246, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="message-square" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-semibold uppercase">Total Reviews</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: var(--text-main);"><?php echo $total_count; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(245, 158, 11, 0.12); color: #f59e0b; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="star" style="width: 24px; height: 24px; fill: #f59e0b;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-semibold uppercase">Average Rating</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: #f59e0b;"><?php echo $avg_rating; ?> <small style="font-size: 1rem;">/ 5.0</small></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #10b981; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="check-check" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-semibold uppercase">Approved & Active</span>
                <span class="text-2xl font-bold" style="font-family: var(--font-heading); color: #10b981;"><?php echo $approved_count; ?></span>
            </div>
        </div>

        <div class="card p-4 flex align-center gap-4" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
            <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(6, 182, 212, 0.12); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-lucide="shield-check" style="width: 24px; height: 24px;"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-xs text-muted font-semibold uppercase">Verification Status</span>
                <span class="text-sm font-bold" style="color: var(--accent-cyan);">Google Maps Verified</span>
            </div>
        </div>
    </div>

    <!-- Filter & View Layout Controls -->
    <div class="flex justify-between align-center flex-wrap gap-4" style="background: var(--bg-card); border: 1px solid var(--border-color); padding: 0.85rem 1.25rem; border-radius: var(--border-radius-md);">
        <!-- Search Input -->
        <div class="input-icon-wrapper" style="min-width: 280px; max-width: 400px;">
            <i data-lucide="search" style="width: 16px; height: 16px;"></i>
            <input type="text" id="reviewSearchInput" onkeyup="filterReviews()" class="form-control text-xs" placeholder="Search customer, company, city, or service...">
        </div>

        <!-- View Switcher & Filter Tabs -->
        <div class="flex align-center gap-3 flex-wrap">
            <div class="flex align-center gap-1" style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: 8px; padding: 3px;">
                <button type="button" class="btn text-xs active" id="btnViewCards" onclick="switchReviewView('cards')" style="padding: 0.35rem 0.85rem; border-radius: 6px;">
                    <i data-lucide="grid" style="width: 14px; height: 14px;"></i> Cards
                </button>
                <button type="button" class="btn text-xs" id="btnViewTable" onclick="switchReviewView('table')" style="padding: 0.35rem 0.85rem; border-radius: 6px;">
                    <i data-lucide="list" style="width: 14px; height: 14px;"></i> Datatable
                </button>
            </div>
        </div>
    </div>

    <!-- View 1: Modern Cards Grid View -->
    <div id="reviewsCardContainer" class="grid" style="grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.5rem;">
        <?php if (empty($reviews)): ?>
            <div class="card p-6 text-center text-muted text-sm" style="grid-column: 1 / -1; border: 1px solid var(--border-color); background: var(--bg-card);">
                No customer reviews submitted yet. Click <strong>"Add Customer Review"</strong> to create one.
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $rev): 
                $status_class = ($rev['status'] === 'Approved') ? 'success' : (($rev['status'] === 'Pending') ? 'warning' : 'danger');
                $stars_cnt = min(5, max(1, round(floatval($rev['rating']))));
            ?>
                <div class="card review-item-card p-5 flex flex-col justify-between" data-search="<?php echo htmlspecialchars(strtolower($rev['name'] . ' ' . $rev['company'] . ' ' . $rev['city'] . ' ' . $rev['service_name'] . ' ' . $rev['review_text'])); ?>" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md); transition: all 0.25s ease;">
                    <div>
                        <!-- Header: Rating Stars & Status -->
                        <div class="flex justify-between align-center mb-3">
                            <div class="flex align-center gap-1" style="color: #f59e0b;">
                                <?php for ($s = 0; $s < $stars_cnt; $s++): ?>
                                    <i data-lucide="star" style="width: 15px; height: 15px; fill: #f59e0b; color: #f59e0b;"></i>
                                <?php endfor; ?>
                                <span class="text-xs font-bold ml-1" style="color: #f59e0b;"><?php echo number_format($rev['rating'], 1); ?></span>
                            </div>
                            <span class="badge text-xs" style="--badge-bg: var(--<?php echo $status_class; ?>-light); --badge-color: var(--<?php echo $status_class; ?>); font-weight: 700;">
                                ● <?php echo htmlspecialchars($rev['status']); ?>
                            </span>
                        </div>

                        <!-- Review Text -->
                        <p class="text-xs text-main mb-4" style="line-height: 1.6; font-style: italic; background: rgba(255,255,255,0.03); padding: 0.75rem 0.9rem; border-radius: 8px; border: 1px solid var(--border-color);">
                            "<?php echo htmlspecialchars($rev['review_text']); ?>"
                        </p>
                    </div>

                    <!-- Footer: Author Info & Actions -->
                    <div class="flex justify-between align-center border-top pt-3" style="border-top: 1px solid var(--border-color);">
                        <div class="flex align-center gap-3">
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--accent-cyan)); color: #ffffff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                                <?php echo strtoupper(substr($rev['name'], 0, 1)); ?>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs font-bold" style="color: var(--text-main);"><?php echo htmlspecialchars($rev['name']); ?></span>
                                <span class="text-xs text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars(!empty($rev['company']) ? $rev['company'] : 'Retail Chemist'); ?> &bull; <?php echo htmlspecialchars(!empty($rev['city']) ? $rev['city'] : 'India'); ?></span>
                                <span class="text-xs text-primary font-semibold mt-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($rev['service_name']); ?></span>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="flex align-center gap-1">
                            <button type="button" class="btn-icon" onclick='openEditReviewModal(<?php echo json_encode($rev); ?>)' title="Edit Review">
                                <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                            </button>
                            <form action="index.php?page=admin_reviews" method="POST" style="display: inline;" onsubmit="return confirm('Delete review by <?php echo htmlspecialchars(addslashes($rev['name'])); ?>?');">
                                <input type="hidden" name="action" value="delete_review">
                                <input type="hidden" name="review_id" value="<?php echo $rev['id']; ?>">
                                <button type="submit" class="btn-icon text-danger" title="Delete Review">
                                    <i data-lucide="trash-2" style="width: 14px; height: 14px; color: var(--danger);"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- View 2: Executive Data Table (Hidden by default) -->
    <div id="reviewsTableContainer" class="card p-4 hidden" style="border: 1px solid var(--border-color); background: var(--bg-card); border-radius: var(--border-radius-md);">
        <div class="table-responsive">
            <table class="w-full text-left" style="border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); background: var(--border-card);">
                        <th class="p-3 text-xs font-bold text-muted">ID & CUSTOMER</th>
                        <th class="p-3 text-xs font-bold text-muted">RATING & SERVICE</th>
                        <th class="p-3 text-xs font-bold text-muted">REVIEW COMMENT</th>
                        <th class="p-3 text-xs font-bold text-muted">STATUS</th>
                        <th class="p-3 text-xs font-bold text-muted text-right">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviews as $rev): 
                        $status_class = ($rev['status'] === 'Approved') ? 'success' : (($rev['status'] === 'Pending') ? 'warning' : 'danger');
                    ?>
                        <tr class="review-item-table" data-search="<?php echo htmlspecialchars(strtolower($rev['name'] . ' ' . $rev['company'] . ' ' . $rev['city'] . ' ' . $rev['service_name'] . ' ' . $rev['review_text'])); ?>" style="border-bottom: 1px solid var(--border-color);">
                            <td class="p-3">
                                <div class="flex flex-col">
                                    <span class="text-xs font-bold" style="color: var(--text-main);"><?php echo htmlspecialchars($rev['name']); ?></span>
                                    <span class="text-xs text-muted"><?php echo htmlspecialchars($rev['company']); ?> &bull; <?php echo htmlspecialchars($rev['city']); ?></span>
                                </div>
                            </td>
                            <td class="p-3">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-bold" style="color: #f59e0b;">
                                        <?php echo number_format($rev['rating'], 1); ?> ★
                                    </span>
                                    <span class="badge text-xs" style="--badge-bg: var(--border-card); --badge-color: var(--text-muted);">
                                        <?php echo htmlspecialchars($rev['service_name']); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="p-3 text-xs text-muted" style="max-width: 380px; line-height: 1.5;">
                                "<?php echo htmlspecialchars($rev['review_text']); ?>"
                            </td>
                            <td class="p-3">
                                <span class="badge text-xs" style="--badge-bg: var(--<?php echo $status_class; ?>-light); --badge-color: var(--<?php echo $status_class; ?>); font-weight: 700;">
                                    <?php echo htmlspecialchars($rev['status']); ?>
                                </span>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex align-center justify-end gap-2">
                                    <button type="button" class="btn-icon" onclick='openEditReviewModal(<?php echo json_encode($rev); ?>)' title="Edit Review">
                                        <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <form action="index.php?page=admin_reviews" method="POST" style="display: inline;" onsubmit="return confirm('Delete review by <?php echo htmlspecialchars(addslashes($rev['name'])); ?>?');">
                                        <input type="hidden" name="action" value="delete_review">
                                        <input type="hidden" name="review_id" value="<?php echo $rev['id']; ?>">
                                        <button type="submit" class="btn-icon text-danger" title="Delete Review">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px; color: var(--danger);"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Create / Edit Review -->
<div id="edit-review-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 540px;">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);" id="reviewModalTitle">Edit Customer Review</h3>
            <button class="btn-icon" onclick="window.closeModal('edit-review-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?page=admin_reviews" method="POST">
            <input type="hidden" name="action" value="save_review">
            <input type="hidden" name="review_id" id="edit-rev-id" value="0">

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Customer Name *</label>
                    <input type="text" name="name" id="edit-rev-name" class="form-control" required placeholder="Full Name">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Company / Store Name</label>
                    <input type="text" name="company" id="edit-rev-company" class="form-control" placeholder="e.g. Gantavya Pharmacy">
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">City / Location</label>
                    <input type="text" name="city" id="edit-rev-city" class="form-control" placeholder="e.g. Kanpur, UP">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Rating Score (1.0 to 5.0) *</label>
                    <select name="rating" id="edit-rev-rating" class="form-control" required>
                        <option value="5.0">5.0 ★★★★★ (Excellent)</option>
                        <option value="4.9">4.9 ★★★★★ (Superb)</option>
                        <option value="4.8">4.8 ★★★★★ (Great)</option>
                        <option value="4.5">4.5 ★★★★☆ (Very Good)</option>
                        <option value="4.0">4.0 ★★★★☆ (Good)</option>
                        <option value="3.0">3.0 ★★★☆☆ (Average)</option>
                    </select>
                </div>
            </div>

            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Service Tag</label>
                    <input type="text" name="service_name" id="edit-rev-service" class="form-control" placeholder="e.g. Marg ERP 9+">
                </div>
                <div class="form-group m-0">
                    <label class="form-label text-xs font-semibold">Display Status</label>
                    <select name="status" id="edit-rev-status" class="form-control">
                        <option value="Approved">Approved (Public on Landing Page)</option>
                        <option value="Pending">Pending Review</option>
                        <option value="Hidden">Hidden</option>
                    </select>
                </div>
            </div>

            <div class="form-group m-0">
                <label class="form-label text-xs font-semibold">Customer Review Comment *</label>
                <textarea name="review_text" id="edit-rev-text" class="form-control" rows="3" required placeholder="Enter review description..."></textarea>
            </div>

            <div class="flex justify-end gap-3 mt-2">
                <button type="button" class="btn btn-secondary text-xs" onclick="window.closeModal('edit-review-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-xs flex align-center gap-2">
                    <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                    <span>Save Customer Review</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateReviewModal() {
    document.getElementById('reviewModalTitle').textContent = 'Add New Customer Review';
    document.getElementById('edit-rev-id').value = '0';
    document.getElementById('edit-rev-name').value = '';
    document.getElementById('edit-rev-company').value = '';
    document.getElementById('edit-rev-city').value = '';
    document.getElementById('edit-rev-rating').value = '5.0';
    document.getElementById('edit-rev-service').value = 'Marg ERP 9+';
    document.getElementById('edit-rev-status').value = 'Approved';
    document.getElementById('edit-rev-text').value = '';
    window.openModal('edit-review-modal');
}

function openEditReviewModal(revData) {
    document.getElementById('reviewModalTitle').textContent = 'Edit Customer Review & Rating';
    document.getElementById('edit-rev-id').value = revData.id;
    document.getElementById('edit-rev-name').value = revData.name || '';
    document.getElementById('edit-rev-company').value = revData.company || '';
    document.getElementById('edit-rev-city').value = revData.city || '';
    document.getElementById('edit-rev-rating').value = parseFloat(revData.rating).toFixed(1);
    document.getElementById('edit-rev-service').value = revData.service_name || '';
    document.getElementById('edit-rev-status').value = revData.status || 'Approved';
    document.getElementById('edit-rev-text').value = revData.review_text || '';
    window.openModal('edit-review-modal');
}

function switchReviewView(viewType) {
    const cardContainer = document.getElementById('reviewsCardContainer');
    const tableContainer = document.getElementById('reviewsTableContainer');
    const btnCards = document.getElementById('btnViewCards');
    const btnTable = document.getElementById('btnViewTable');

    if (viewType === 'table') {
        cardContainer.classList.add('hidden');
        tableContainer.classList.remove('hidden');
        btnTable.classList.add('active');
        btnCards.classList.remove('active');
    } else {
        tableContainer.classList.add('hidden');
        cardContainer.classList.remove('hidden');
        btnCards.classList.add('active');
        btnTable.classList.remove('active');
    }
    lucide.createIcons();
}

function filterReviews() {
    const query = document.getElementById('reviewSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.review-item-card');
    const rows = document.querySelectorAll('.review-item-table');

    cards.forEach(card => {
        const text = card.getAttribute('data-search') || '';
        card.style.display = text.includes(query) ? '' : 'none';
    });

    rows.forEach(row => {
        const text = row.getAttribute('data-search') || '';
        row.style.display = text.includes(query) ? '' : 'none';
    });
}
</script>
